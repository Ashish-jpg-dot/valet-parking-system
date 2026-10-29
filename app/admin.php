<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function admin_kpis(): array
{
    $db = db();
    $one = static function (string $sql) use ($db) {
        $result = $db->query($sql);
        $row = $result->fetch_row();
        return $row ? $row[0] : 0;
    };

    return [
        'customers' => (int) $one('SELECT COUNT(*) FROM tbl_customer'),
        'valets' => (int) $one('SELECT COUNT(*) FROM tbl_valet'),
        'pending_valets' => (int) $one('SELECT COUNT(*) FROM tbl_valet v JOIN tbl_login l ON l.login_id = v.login_id WHERE l.status IS NULL'),
        'active_bookings' => (int) $one("SELECT COUNT(*) FROM tbl_booking WHERE booking_status NOT IN ('COMPLETED','CANCELLED')"),
        'completed_bookings' => (int) $one("SELECT COUNT(*) FROM tbl_booking WHERE booking_status = 'COMPLETED'"),
        'open_complaints' => (int) $one("SELECT COUNT(*) FROM tbl_complaint WHERE status = 'OPEN'"),
        'revenue' => (float) $one("SELECT COALESCE(SUM(amount),0) FROM tbl_payment WHERE status = 'PAID'"),
        'revenue_today' => (float) $one("SELECT COALESCE(SUM(amount),0) FROM tbl_payment WHERE status = 'PAID' AND DATE(paid_at) = CURDATE()"),
        'fuel_bookings' => (int) $one('SELECT COUNT(*) FROM tbl_booking WHERE fuel_service = 1'),
    ];
}

function admin_valets(): array
{
    $result = db()->query(
        "SELECT v.*, l.username, l.status AS login_status,
                (SELECT COUNT(*) FROM tbl_earning e WHERE e.valet_id = v.valet_id) AS jobs_done,
                (SELECT COALESCE(SUM(e.amount),0) FROM tbl_earning e WHERE e.valet_id = v.valet_id) AS earned
         FROM tbl_valet v
         JOIN tbl_login l ON l.login_id = v.login_id
         ORDER BY (l.status IS NULL) DESC, v.created_at DESC"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function admin_pending_valets(): array
{
    $result = db()->query(
        "SELECT v.*, l.username, l.status AS login_status
         FROM tbl_valet v
         JOIN tbl_login l ON l.login_id = v.login_id
         WHERE l.status IS NULL
         ORDER BY v.created_at ASC"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function admin_set_valet_status(int $valetId, int $status): ?string
{
    $stmt = db()->prepare(
        'UPDATE tbl_login l JOIN tbl_valet v ON v.login_id = l.login_id
         SET l.status = ? WHERE v.valet_id = ?'
    );
    $stmt->bind_param('ii', $status, $valetId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $changed > 0 ? null : 'That valet account could not be updated.';
}

function admin_customers(): array
{
    $result = db()->query(
        "SELECT c.*, l.username, l.status AS login_status,
                (SELECT COUNT(*) FROM tbl_vehicle v WHERE v.customer_id = c.customer_id) AS vehicles,
                (SELECT COUNT(*) FROM tbl_booking b WHERE b.customer_id = c.customer_id) AS bookings,
                (SELECT COALESCE(SUM(p.amount),0) FROM tbl_payment p
                    JOIN tbl_booking b ON b.booking_id = p.booking_id
                    WHERE b.customer_id = c.customer_id AND p.status = 'PAID') AS spent
         FROM tbl_customer c
         JOIN tbl_login l ON l.login_id = c.login_id
         ORDER BY c.created_at DESC"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function admin_bookings(string $search = '', string $status = ''): array
{
    $sql = "SELECT b.*, c.first_name, c.last_name, c.phone AS customer_phone,
                   v.first_name AS valet_first, v.last_name AS valet_last,
                   pay.amount AS paid_amount, pay.status AS payment_status,
                   rj.status AS return_status, rj.return_address,
                   rj.return_latitude, rj.return_longitude,
                   rt.stars AS rating_stars, rt.comment AS rating_comment
            FROM tbl_booking b
            JOIN tbl_customer c ON c.customer_id = b.customer_id
            LEFT JOIN tbl_valet v ON v.valet_id = b.valet_id
            LEFT JOIN tbl_payment pay ON pay.booking_id = b.booking_id
            LEFT JOIN tbl_return_job rj ON rj.booking_id = b.booking_id
            LEFT JOIN tbl_rating rt ON rt.booking_id = b.booking_id
            WHERE 1=1";
    $types = '';
    $params = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (b.vehicle_number LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR b.pickup_address LIKE ?)';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    if ($status !== '') {
        $sql .= ' AND b.booking_status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $sql .= ' ORDER BY b.created_at DESC LIMIT 200';

    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function admin_parking_jobs(): array
{
    $result = db()->query(
        "SELECT pj.*, b.booking_status, b.vehicle_number, b.vehicle_type, b.pickup_address,
                c.first_name, c.last_name, v.first_name AS valet_first, v.last_name AS valet_last
         FROM tbl_parking_job pj
         JOIN tbl_booking b ON b.booking_id = pj.booking_id
         JOIN tbl_customer c ON c.customer_id = b.customer_id
         LEFT JOIN tbl_valet v ON v.valet_id = pj.valet_id
         ORDER BY pj.assigned_at DESC
         LIMIT 100"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function admin_return_jobs(): array
{
    $result = db()->query(
        "SELECT rj.*, b.booking_status, b.vehicle_number, b.vehicle_type,
                c.first_name, c.last_name, v.first_name AS valet_first, v.last_name AS valet_last
         FROM tbl_return_job rj
         JOIN tbl_booking b ON b.booking_id = rj.booking_id
         JOIN tbl_customer c ON c.customer_id = b.customer_id
         LEFT JOIN tbl_valet v ON v.valet_id = rj.valet_id
         ORDER BY rj.requested_at DESC
         LIMIT 100"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function admin_cancellations(): array
{
    $result = db()->query(
        "SELECT b.*, c.first_name, c.last_name, c.phone AS customer_phone
         FROM tbl_booking b
         JOIN tbl_customer c ON c.customer_id = b.customer_id
         WHERE b.booking_status = 'CANCELLED'
         ORDER BY b.updated_at DESC"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function admin_payments(): array
{
    $result = db()->query(
        "SELECT p.*, b.vehicle_number, b.vehicle_type, b.fuel_service, b.price,
                c.first_name, c.last_name
         FROM tbl_payment p
         JOIN tbl_booking b ON b.booking_id = p.booking_id
         JOIN tbl_customer c ON c.customer_id = b.customer_id
         ORDER BY p.paid_at DESC
         LIMIT 200"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function admin_complaints(): array
{
    $result = db()->query(
        "SELECT cmp.*, b.vehicle_number, b.booking_status,
                c.first_name, c.last_name, c.phone AS customer_phone
         FROM tbl_complaint cmp
         JOIN tbl_booking b ON b.booking_id = cmp.booking_id
         JOIN tbl_customer c ON c.customer_id = cmp.customer_id
         ORDER BY (cmp.status = 'OPEN') DESC, cmp.created_at DESC"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function admin_resolve_complaint(int $complaintId): ?string
{
    $stmt = db()->prepare("UPDATE tbl_complaint SET status = 'RESOLVED' WHERE complaint_id = ? AND status = 'OPEN'");
    $stmt->bind_param('i', $complaintId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $changed > 0 ? null : 'That complaint is no longer open.';
}

function admin_accounts(): array
{
    $result = db()->query(
        "SELECT l.login_id, l.username, l.status, l.is_admin, l.created_at,
                c.first_name AS customer_first, c.last_name AS customer_last,
                v.first_name AS valet_first, v.last_name AS valet_last
         FROM tbl_login l
         LEFT JOIN tbl_customer c ON c.login_id = l.login_id
         LEFT JOIN tbl_valet v ON v.login_id = l.login_id
         ORDER BY l.created_at DESC"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function admin_set_account_status(int $loginId, ?int $status): ?string
{
    $stmt = db()->prepare('UPDATE tbl_login SET status = ? WHERE login_id = ?');
    $stmt->bind_param('ii', $status, $loginId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $changed > 0 ? null : 'That account could not be updated.';
}

function admin_account(int $loginId): ?array
{
    $stmt = db()->prepare('SELECT login_id, username, status, is_admin FROM tbl_login WHERE login_id = ? LIMIT 1');
    $stmt->bind_param('i', $loginId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function admin_withdrawals(): array
{
    $result = db()->query(
        "SELECT w.*, v.first_name, v.last_name, v.upi_id, l.username
         FROM tbl_withdrawal w
         JOIN tbl_valet v ON v.valet_id = w.valet_id
         JOIN tbl_login l ON l.login_id = v.login_id
         ORDER BY (w.status = 'PENDING') DESC, w.created_at DESC
         LIMIT 100"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

function admin_set_withdrawal_status(int $withdrawalId, string $status): ?string
{
    if (!in_array($status, ['PAID', 'REJECTED'], true)) {
        return 'Unknown withdrawal status.';
    }
    $stmt = db()->prepare("UPDATE tbl_withdrawal SET status = ? WHERE withdrawal_id = ? AND status = 'PENDING'");
    $stmt->bind_param('si', $status, $withdrawalId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $changed > 0 ? null : 'That withdrawal is no longer pending.';
}
