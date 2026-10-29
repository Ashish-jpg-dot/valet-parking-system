<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';

require_guest();

$currentYear = date('Y');
$flashes = take_flashes();
$email = '';
$formError = '';

if (is_post()) {
    verify_csrf();
    $email = post_string('email');
    $password = post_string('password');
    if ($email === '' || $password === '') {
        $formError = 'Enter your email address and password.';
    } else {
        $formError = login_user($email, $password);
        if ($formError === null) {
            $user = current_user(true);
            flash('success', 'Welcome back, ' . $user['name'] . '.');
            redirect(dashboard_url($user['role']));
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcf9f8">
    <meta name="description" content="Sign in to your ValetFlow account.">
    <title>Log In — ValetFlow</title>
    <link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/layout.css">
<link rel="stylesheet" href="assets/css/components.css">
<link rel="stylesheet" href="assets/css/pages/login.css">
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a class="brand" href="index.php" aria-label="ValetFlow home">
                <span class="brand-mark" aria-hidden="true">V</span>
                <span class="brand-name">ValetFlow</span>
            </a>
            <a class="header-home" href="index.php">
                <span>Back to Home</span>
                <span class="account-icon" aria-hidden="true">
                    <svg class="icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"></circle><path d="M5.5 20v-1.5a6.5 6.5 0 0 1 13 0V20z"></path></svg>
                </span>
            </a>
        </div>
    </header>

    <main>
        <section class="login-card" aria-labelledby="login-title">
            <a class="back-link" href="index.php">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6M9 12h12"></path></svg>
                Back to ValetFlow Home
            </a>

            <?php if ($flashes): ?>
                <div class="flash-stack">
                    <?php foreach ($flashes as $flash): ?>
                        <p class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="account-label" aria-label="ValetFlow Account Access">
                <span class="brand-mark" aria-hidden="true">V</span>
                <span class="account-label-text">ValetFlow Account Access</span>
            </div>

            <h1 id="login-title">Welcome back</h1>
            <p class="intro">Log in to manage your valet parking experience.</p>

            <form id="login-form" method="post" action="login.php" novalidate>
                <?= csrf_field() ?>
                <div class="form-fields">
                    <div class="field">
                        <label for="email-input">Email Address <span class="required" aria-hidden="true">*</span></label>
                        <input
                            id="email-input"
                            name="email"
                            type="email"
                            inputmode="email"
                            autocomplete="email"
                            placeholder="name@example.com"
                            value="<?= e($email) ?>"
                            required
                            aria-describedby="email-error"
                        >
                        <p class="field-error" id="email-error"></p>
                    </div>

                    <div class="field field-password">
                        <label for="password-input">Password <span class="required" aria-hidden="true">*</span></label>
                        <div class="input-wrap">
                            <input
                                id="password-input"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                required
                                aria-describedby="password-error"
                            >
                            <button class="toggle-password" id="toggle-password" type="button" aria-label="Show password" aria-pressed="false">
                                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                        <p class="field-error" id="password-error"></p>
                    </div>
                </div>

                <p class="form-message error-message" id="form-message" role="alert" aria-live="polite" <?= $formError === '' ? 'hidden' : '' ?>><?= e($formError) ?></p>

                <button class="submit-button" type="submit">
                    Log In <span aria-hidden="true">→</span>
                </button>
            </form>

            <div class="signup-area">
                <p class="signup-prompt">Don't have an account? Sign up</p>
                <div class="registration-actions">
                    <a class="registration-button" href="register.php">Register as Customer</a>
                    <a class="registration-button" href="valet-register.php">Register as Valet Partner</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="footer-inner">
            <p>&copy; <?= htmlspecialchars($currentYear, ENT_QUOTES, 'UTF-8') ?> ValetFlow Mobility Systems Inc. All rights reserved.</p>
            <span class="portal-label">System Auth Portal</span>
        </div>
    </footer>

    
<script src="assets/js/core.js" defer></script>
<script src="assets/js/login.js" defer></script>
</body>
</html>
