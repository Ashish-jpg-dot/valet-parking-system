<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';

require_guest();

$currentYear = date('Y');
$errors = [];
$formError = '';
$values = ['fullname' => '', 'email' => '', 'phone' => '', 'country' => '+91'];

if (is_post()) {
    verify_csrf();
    $values['fullname'] = post_string('fullname');
    $values['email'] = post_string('email');
    $values['phone'] = post_string('phone');
    $values['country'] = post_string('country_code', '+91');
    $password = post_string('password');
    $confirm = post_string('confirm_password');

    if ($values['fullname'] === '') {
        $errors['fullname'] = 'Full name is required.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    $digits = preg_replace('/\D+/', '', $values['phone']);
    if (strlen($digits) < 10) {
        $errors['phone'] = 'Enter a phone number with at least 10 digits.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($confirm !== $password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (!$errors) {
        $check = db()->prepare('SELECT login_id FROM tbl_login WHERE username = ? LIMIT 1');
        $check->bind_param('s', $values['email']);
        $check->execute();
        if ($check->get_result()->fetch_row()) {
            $errors['email'] = 'That email address already has an account.';
        }
        $check->close();
    }

    if (!$errors) {
        $parts = preg_split('/\s+/', trim($values['fullname'])) ?: [$values['fullname']];
        $first = array_shift($parts);
        $last = implode(' ', $parts);
        $phone = trim($values['country'] . ' ' . $values['phone']);
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db = db();

        try {
            $db->begin_transaction();
            $stmt = $db->prepare('INSERT INTO tbl_login (username, passwords, status, is_admin) VALUES (?, ?, 1, 0)');
            $stmt->bind_param('ss', $values['email'], $hash);
            $stmt->execute();
            $loginId = (int) $stmt->insert_id;
            $stmt->close();

            $stmt = $db->prepare('INSERT INTO tbl_customer (login_id, first_name, last_name, phone) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('isss', $loginId, $first, $last, $phone);
            $stmt->execute();
            $stmt->close();

            $db->commit();
            flash('success', 'Account created. Sign in to book your first valet.');
            redirect('login.php');
        } catch (Throwable $exception) {
            $db->rollback();
            $formError = 'Account creation failed. Please try again.';
        }
    } elseif (!$formError) {
        $formError = 'Check the highlighted fields and try again.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcf9f8">
    <meta name="description" content="Create a ValetFlow customer account.">
    <title>Create Account — ValetFlow</title>
    <link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/layout.css">
<link rel="stylesheet" href="assets/css/components.css">
<link rel="stylesheet" href="assets/css/pages/register.css">
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a class="brand" href="index.php" aria-label="ValetFlow home">
                <span class="brand-mark" aria-hidden="true">V</span>
                <span class="brand-name">ValetFlow</span>
            </a>
            <nav class="header-actions" aria-label="Account navigation">
                <a class="header-link" href="login.php">
                    <span class="login-prompt">Already have an account? Log in</span>
                    <span class="login-short">Log in</span>
                </a>
                <a class="header-link" href="index.php">
                    <span>Back to Home</span>
                    <span class="account-icon" aria-hidden="true">
                        <svg class="icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"></circle><path d="M5.5 20v-1.5a6.5 6.5 0 0 1 13 0V20z"></path></svg>
                    </span>
                </a>
            </nav>
        </div>
    </header>

    <main>
        <div class="register-layout">
            <section class="register-card" aria-labelledby="register-title">
                <a class="back-link" href="index.php">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6M9 12h12"></path></svg>
                    Back to ValetFlow Home
                </a>

                <div class="account-label" aria-label="Customer Account">
                    <span class="brand-mark" aria-hidden="true">V</span>
                    <span class="account-label-text">Customer Account</span>
                </div>

                <h1 id="register-title">Create your account</h1>
                <p class="intro">Register to book a valet and get your vehicle parked and returned at your chosen time.</p>

                <form id="register-form" method="post" action="register.php" novalidate>
                    <?= csrf_field() ?>
                    <div class="form-fields">
                        <div class="field">
                            <label for="reg-fullname">Full Name <span class="required" aria-hidden="true">*</span></label>
                            <input class="field-control" id="reg-fullname" name="fullname" type="text" autocomplete="name" placeholder="e.g. Marcus Thorne" value="<?= e($values['fullname']) ?>" required aria-describedby="fullname-error">
                            <p class="field-error <?= isset($errors['fullname']) ? 'is-visible' : '' ?>" id="fullname-error"><?= e($errors['fullname'] ?? '') ?></p>
                        </div>

                        <div class="field">
                            <label for="reg-email">Email Address <span class="required" aria-hidden="true">*</span></label>
                            <input class="field-control" id="reg-email" name="email" type="email" inputmode="email" autocomplete="email" placeholder="name@example.com" value="<?= e($values['email']) ?>" required aria-describedby="email-error">
                            <p class="field-error <?= isset($errors['email']) ? 'is-visible' : '' ?>" id="email-error"><?= e($errors['email'] ?? '') ?></p>
                        </div>

                        <div class="field">
                            <label for="reg-phone">Phone Number <span class="required" aria-hidden="true">*</span></label>
                            <div class="phone-control">
                                <div class="country-wrap">
                                    <label class="sr-only" for="country-code">Country Code</label>
                                    <select class="country-code" id="country-code" name="country_code" aria-label="Country Code">
                                        <option value="+1" <?= $values['country'] === '+1' ? 'selected' : '' ?>>+1 (US)</option>
                                        <option value="+91" <?= $values['country'] === '+91' ? 'selected' : '' ?>>+91 (IN)</option>
                                        <option value="+44" <?= $values['country'] === '+44' ? 'selected' : '' ?>>+44 (UK)</option>
                                        <option value="+971" <?= $values['country'] === '+971' ? 'selected' : '' ?>>+971 (AE)</option>
                                    </select>
                                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="m7 10 5 5 5-5"></path></svg>
                                </div>
                                <input class="field-control" id="reg-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel-national" placeholder="98765 43210" value="<?= e($values['phone']) ?>" required aria-label="Phone number" aria-describedby="phone-error">
                            </div>
                            <p class="field-error <?= isset($errors['phone']) ? 'is-visible' : '' ?>" id="phone-error"><?= e($errors['phone'] ?? '') ?></p>
                        </div>

                        <div class="password-grid">
                            <div class="field">
                                <label for="reg-password">Password <span class="required" aria-hidden="true">*</span></label>
                                <div class="password-wrap">
                                    <input class="field-control" id="reg-password" name="password" type="password" autocomplete="new-password" minlength="8" placeholder="Min 8 characters" required aria-describedby="password-error">
                                    <button class="toggle-password" type="button" data-password-toggle="reg-password" aria-label="Show password" aria-pressed="false">
                                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    </button>
                                </div>
                                <p class="field-error <?= isset($errors['password']) ? 'is-visible' : '' ?>" id="password-error"><?= e($errors['password'] ?? '') ?></p>
                            </div>
                            <div class="field">
                                <label for="reg-confirm-password">Confirm Password <span class="required" aria-hidden="true">*</span></label>
                                <div class="password-wrap">
                                    <input class="field-control" id="reg-confirm-password" name="confirm_password" type="password" autocomplete="new-password" placeholder="Re-enter password" required aria-describedby="confirm-password-error">
                                    <button class="toggle-password" type="button" data-password-toggle="reg-confirm-password" aria-label="Show confirm password" aria-pressed="false">
                                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    </button>
                                </div>
                                <p class="field-error <?= isset($errors['confirm_password']) ? 'is-visible' : '' ?>" id="confirm-password-error"><?= e($errors['confirm_password'] ?? '') ?></p>
                            </div>
                        </div>
                    </div>

                    <p class="terms-copy">By creating an account, you agree to ValetFlow's <span class="terms-label">Terms of Service</span> and <span class="terms-label">Privacy Policy</span>.</p>
                    <p class="form-message error-message" id="form-message" role="alert" aria-live="polite" <?= $formError === '' ? 'hidden' : '' ?>><?= e($formError) ?></p>
                    <button class="create-button" type="submit">
                        Create Account
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"></path></svg>
                    </button>
                </form>

                <div class="existing-account">
                    Already have an account? <a href="login.php">Log in</a>
                </div>
            </section>
        </div>
    </main>

    <footer class="site-footer">
        <div class="footer-inner">
            <p>&copy; <?= htmlspecialchars($currentYear, ENT_QUOTES, 'UTF-8') ?> ValetFlow Mobility Systems Inc. All rights reserved.</p>
            <span class="portal-label">System Auth Portal</span>
        </div>
    </footer>

    
<script src="assets/js/core.js" defer></script>
<script src="assets/js/register.js" defer></script>
</body>
</html>
