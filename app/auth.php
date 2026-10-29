<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Resolve the signed-in account, including role and profile ids.
 * Returns null when there is no valid, active session.
 */
function current_user(bool $refresh = false): ?array
{
    static $cached = false;
    if (!$refresh && $cached !== false) {
        return $cached;
    }
    if ($refresh) {
        $cached = false;
    }

    $loginId = $_SESSION['login_id'] ?? null;
    if (!$loginId) {
        return $cached = null;
    }

    $stmt = db()->prepare(
        'SELECT l.login_id, l.username, l.is_admin, l.status,
                c.customer_id, c.first_name AS customer_first, c.last_name AS customer_last,
                c.phone AS customer_phone, c.address AS customer_address,
                v.valet_id, v.first_name AS valet_first, v.last_name AS valet_last,
                v.phone AS valet_phone, v.license_number, v.license_photo, v.is_live, v.upi_id
         FROM tbl_login l
         LEFT JOIN tbl_customer c ON c.login_id = l.login_id
         LEFT JOIN tbl_valet v ON v.login_id = l.login_id
         WHERE l.login_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $loginId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || (int) $row['status'] !== 1) {
        unset($_SESSION['login_id']);
        return $cached = null;
    }

    if ((int) $row['is_admin'] === 1) {
        $role = 'admin';
        $name = 'Administrator';
        $profileId = null;
    } elseif ($row['customer_id'] !== null) {
        $role = 'customer';
        $name = full_name($row['customer_first'], $row['customer_last']);
        $profileId = (int) $row['customer_id'];
    } elseif ($row['valet_id'] !== null) {
        $role = 'valet';
        $name = full_name($row['valet_first'], $row['valet_last']);
        $profileId = (int) $row['valet_id'];
    } else {
        unset($_SESSION['login_id']);
        return $cached = null;
    }

    return $cached = [
        'login_id' => (int) $row['login_id'],
        'username' => $row['username'],
        'role' => $role,
        'name' => $name,
        'profile_id' => $profileId,
        'customer' => $role === 'customer' ? [
            'customer_id' => (int) $row['customer_id'],
            'first_name' => $row['customer_first'],
            'last_name' => $row['customer_last'],
            'phone' => $row['customer_phone'],
            'address' => $row['customer_address'],
        ] : null,
        'valet' => $role === 'valet' ? [
            'valet_id' => (int) $row['valet_id'],
            'first_name' => $row['valet_first'],
            'last_name' => $row['valet_last'],
            'phone' => $row['valet_phone'],
            'license_number' => $row['license_number'],
            'license_photo' => $row['license_photo'],
            'is_live' => (int) $row['is_live'],
            'upi_id' => $row['upi_id'],
        ] : null,
    ];
}

/**
 * Try to sign in. Returns an error message, or null on success.
 */
function login_user(string $username, string $password): ?string
{
    $stmt = db()->prepare(
        'SELECT login_id, passwords, status, is_admin, failed_attempts, locked_until
         FROM tbl_login WHERE username = ? LIMIT 1'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && ($lock = lock_message($row['locked_until'])) !== null) {
        return $lock;
    }

    if (!$row || !password_verify($password, $row['passwords'])) {
        if ($row) {
            $attempts = (int) $row['failed_attempts'] + 1;
            if ($attempts >= AUTH_MAX_ATTEMPTS) {
                $until = (new DateTime('+' . AUTH_LOCK_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
                $stmt = db()->prepare('UPDATE tbl_login SET failed_attempts = 0, locked_until = ? WHERE login_id = ?');
                $stmt->bind_param('si', $until, $row['login_id']);
            } else {
                $stmt = db()->prepare('UPDATE tbl_login SET failed_attempts = ? WHERE login_id = ?');
                $stmt->bind_param('ii', $attempts, $row['login_id']);
            }
            $stmt->execute();
            $stmt->close();
        }
        return 'Invalid email or password.';
    }

    $stmt = db()->prepare('UPDATE tbl_login SET failed_attempts = 0, locked_until = NULL WHERE login_id = ?');
    $stmt->bind_param('i', $row['login_id']);
    $stmt->execute();
    $stmt->close();

    if ($row['status'] === null) {
        return 'Your account is pending approval. Please try again once an administrator has reviewed it.';
    }
    if ((int) $row['status'] !== 1) {
        return 'This account is not active. Contact support if you believe this is a mistake.';
    }

    $loginId = (int) $row['login_id'];
    $role = null;

    if ((int) $row['is_admin'] === 1) {
        $role = 'admin';
    } else {
        $check = db()->prepare('SELECT customer_id FROM tbl_customer WHERE login_id = ? LIMIT 1');
        $check->bind_param('i', $loginId);
        $check->execute();
        if ($check->get_result()->fetch_row()) {
            $role = 'customer';
        }
        $check->close();

        if ($role === null) {
            $check = db()->prepare('SELECT valet_id FROM tbl_valet WHERE login_id = ? LIMIT 1');
            $check->bind_param('i', $loginId);
            $check->execute();
            if ($check->get_result()->fetch_row()) {
                $role = 'valet';
            }
            $check->close();
        }
    }

    if ($role === null) {
        return 'This account has no active profile. Contact support.';
    }

    session_regenerate_id(true);
    $_SESSION['login_id'] = $loginId;
    return null;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Require a signed-in account with one of the given roles.
 */
function require_role(string ...$roles): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please sign in to continue.');
        redirect('login.php');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        flash('error', 'You do not have access to that page.');
        redirect(dashboard_url($user['role']));
    }
    return $user;
}

function require_guest(): void
{
    $user = current_user();
    if ($user) {
        redirect(dashboard_url($user['role']));
    }
}
