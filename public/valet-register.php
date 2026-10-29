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
$values = ['fullName' => '', 'email' => '', 'phone' => '', 'dlNumber' => '', 'country' => '+91'];

if (is_post()) {
    verify_csrf();
    $values['fullName'] = post_string('full_name');
    $values['email'] = post_string('email');
    $values['phone'] = post_string('phone');
    $values['dlNumber'] = post_string('dl_number');
    $values['country'] = post_string('country_code', '+91');
    $password = post_string('password');
    $confirm = post_string('confirm_password');

    if ($values['fullName'] === '') {
        $errors['fullName'] = 'Please enter your full name.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please provide a valid email format.';
    }
    $digits = preg_replace('/\D+/', '', $values['phone']);
    if (strlen($digits) < 10) {
        $errors['phone'] = 'Please enter a valid phone number (at least 10 digits).';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($confirm !== $password) {
        $errors['confirmPassword'] = 'Passwords do not match.';
    }
    if ($values['dlNumber'] === '') {
        $errors['dlNumber'] = 'Driving licence number is required.';
    }

    $file = $_FILES['dl_document'] ?? null;
    $extension = null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors['dlDocument'] = 'Please upload a copy of your driving licence (PDF, JPG, PNG).';
    } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        $errors['dlDocument'] = 'The file must be ' . (UPLOAD_MAX_BYTES / 1048576) . ' MB or smaller.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors['dlDocument'] = 'The upload failed. Please try again.';
    } elseif ($file['size'] > UPLOAD_MAX_BYTES) {
        $errors['dlDocument'] = 'The file must be ' . (UPLOAD_MAX_BYTES / 1048576) . ' MB or smaller.';
    } else {
        $extension = detected_upload_extension($file['tmp_name']);
        if ($extension === null) {
            $errors['dlDocument'] = 'Please select a PDF, JPG, or PNG file.';
        }
    }

    if (!$errors) {
        $check = db()->prepare('SELECT login_id FROM tbl_login WHERE username = ? LIMIT 1');
        $check->bind_param('s', $values['email']);
        $check->execute();
        if ($check->get_result()->fetch_row()) {
            $errors['email'] = 'That email address already has an account.';
        }
        $check->close();

        $check = db()->prepare('SELECT valet_id FROM tbl_valet WHERE license_number = ? LIMIT 1');
        $check->bind_param('s', $values['dlNumber']);
        $check->execute();
        if ($check->get_result()->fetch_row()) {
            $errors['dlNumber'] = 'That driving licence number is already registered.';
        }
        $check->close();
    }

    if (!$errors) {
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0775, true);
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = UPLOAD_DIR . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $formError = 'The licence document could not be stored. Please try again.';
        } else {
            $parts = preg_split('/\s+/', trim($values['fullName'])) ?: [$values['fullName']];
            $first = array_shift($parts);
            $last = implode(' ', $parts);
            $phone = trim($values['country'] . ' ' . $values['phone']);
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db = db();

            try {
                $db->begin_transaction();
                $stmt = $db->prepare('INSERT INTO tbl_login (username, passwords, status, is_admin) VALUES (?, ?, NULL, 0)');
                $stmt->bind_param('ss', $values['email'], $hash);
                $stmt->execute();
                $loginId = (int) $stmt->insert_id;
                $stmt->close();

                $stmt = $db->prepare('INSERT INTO tbl_valet (login_id, first_name, last_name, phone, license_number, license_photo) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isssss', $loginId, $first, $last, $phone, $values['dlNumber'], $storedName);
                $stmt->execute();
                $stmt->close();

                $db->commit();
                flash('success', 'Application submitted. An administrator will review your licence before your account is activated.');
                redirect('login.php');
            } catch (Throwable $exception) {
                $db->rollback();
                @unlink($target);
                $formError = 'Registration failed. Please check your details and try again.';
            }
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
    <meta name="description" content="Apply to join the ValetFlow valet partner network.">
    <title>Register as a Valet Partner — ValetFlow</title>
    <link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/layout.css">
<link rel="stylesheet" href="assets/css/components.css">
<link rel="stylesheet" href="assets/css/pages/valet-register.css">
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a class="brand" href="index.php" aria-label="ValetFlow home">
                <span class="brand-mark" aria-hidden="true">V</span>
                <span class="brand-name">ValetFlow</span>
            </a>
            <a class="header-login" href="login.php">
                <span>Already have an account? Log in</span>
                <span class="account-icon" aria-hidden="true">
                    <svg class="icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"></circle><path d="M5.5 20v-1.5a6.5 6.5 0 0 1 13 0V20z"></path></svg>
                </span>
            </a>
        </div>
    </header>

    <main>
        <section class="application-card" aria-labelledby="application-title">
            <a class="back-link" href="index.php">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6M9 12h12"></path></svg>
                Back to ValetFlow Home
            </a>

            <div class="application-label">
                <span class="brand-mark" aria-hidden="true">V</span>
                <span class="application-badge"><span class="status-dot" aria-hidden="true"></span>Valet Partner Application</span>
            </div>

            <h1 id="application-title">Register as a Valet Partner</h1>
            <p class="intro">Join our verified valet driver network. Earn <?= e(money(VALET_PARKING_EARNING)) ?> per parking job and <?= e(money(VALET_RETURN_EARNING)) ?> per return job, with flexible shifts.</p>

            <form class="application-form" id="valet-reg-form" method="post" action="valet-register.php" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="fullName">Full Name <span class="required" aria-hidden="true">*</span></label>
                    <input class="field-control" id="fullName" name="full_name" type="text" autocomplete="name" placeholder="e.g. Marcus Thorne" value="<?= e($values['fullName']) ?>" required aria-describedby="error-fullName">
                    <p class="field-error <?= isset($errors['fullName']) ? 'is-visible' : '' ?>" id="error-fullName"><?= e($errors['fullName'] ?? '') ?></p>
                </div>

                <div class="field">
                    <label for="email">Email Address <span class="required" aria-hidden="true">*</span></label>
                    <input class="field-control" id="email" name="email" type="email" inputmode="email" autocomplete="email" placeholder="name@example.com" value="<?= e($values['email']) ?>" required aria-describedby="error-email">
                    <p class="field-error <?= isset($errors['email']) ? 'is-visible' : '' ?>" id="error-email"><?= e($errors['email'] ?? '') ?></p>
                </div>

                <div class="field">
                    <label for="phone">Phone Number <span class="required" aria-hidden="true">*</span></label>
                    <div class="phone-control">
                        <div class="country-wrap">
                            <label class="sr-only" for="countryCode">Country Code</label>
                            <select class="country-code" aria-label="Country Code" id="countryCode" name="country_code">
                                <option value="+91" <?= $values['country'] === '+91' ? 'selected' : '' ?>>+91 (IN)</option>
                                <option value="+1" <?= $values['country'] === '+1' ? 'selected' : '' ?>>+1 (US)</option>
                                <option value="+44" <?= $values['country'] === '+44' ? 'selected' : '' ?>>+44 (UK)</option>
                                <option value="+971" <?= $values['country'] === '+971' ? 'selected' : '' ?>>+971 (UAE)</option>
                            </select>
                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="m7 10 5 5 5-5"></path></svg>
                        </div>
                        <input class="field-control" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel-national" placeholder="98765 43210" value="<?= e($values['phone']) ?>" required aria-describedby="error-phone">
                    </div>
                    <p class="field-error <?= isset($errors['phone']) ? 'is-visible' : '' ?>" id="error-phone"><?= e($errors['phone'] ?? '') ?></p>
                </div>

                <div class="password-grid">
                    <div class="field">
                        <label for="password">Password <span class="required" aria-hidden="true">*</span></label>
                        <div class="password-wrap">
                            <input class="field-control" id="password" name="password" type="password" autocomplete="new-password" minlength="8" placeholder="Min 8 characters" required aria-describedby="error-password">
                            <button class="toggle-password" id="toggle-pwd-btn" type="button" aria-label="Show password" aria-pressed="false">
                                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                        <p class="field-error <?= isset($errors['password']) ? 'is-visible' : '' ?>" id="error-password"><?= e($errors['password'] ?? '') ?></p>
                    </div>
                    <div class="field">
                        <label for="confirmPassword">Confirm Password <span class="required" aria-hidden="true">*</span></label>
                        <div class="password-wrap">
                            <input class="field-control" id="confirmPassword" name="confirm_password" type="password" autocomplete="new-password" placeholder="Re-enter password" required aria-describedby="error-confirmPassword">
                            <button class="toggle-password" id="toggle-conf-btn" type="button" aria-label="Show confirm password" aria-pressed="false">
                                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                        <p class="field-error <?= isset($errors['confirmPassword']) ? 'is-visible' : '' ?>" id="error-confirmPassword"><?= e($errors['confirmPassword'] ?? '') ?></p>
                    </div>
                </div>

                <div class="field">
                    <label for="dlNumber">Driving Licence Number <span class="required" aria-hidden="true">*</span></label>
                    <input class="field-control" id="dlNumber" name="dl_number" type="text" autocomplete="off" placeholder="e.g. DL-1420110012345" value="<?= e($values['dlNumber']) ?>" required aria-describedby="error-dlNumber">
                    <p class="field-error <?= isset($errors['dlNumber']) ? 'is-visible' : '' ?>" id="error-dlNumber"><?= e($errors['dlNumber'] ?? '') ?></p>
                </div>

                <div class="field">
                    <label class="upload-label" for="dlDocument">Driving Licence Document Upload <span class="required" aria-hidden="true">*</span></label>
                    <div class="dropzone <?= isset($errors['dlDocument']) ? 'has-error' : '' ?>" id="dropzone">
                        <input class="file-input" id="dlDocument" name="dl_document" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" aria-describedby="dropzone-help error-dlDocument">
                        <div class="dropzone-empty" id="dropzone-empty">
                            <span class="upload-icon" aria-hidden="true">
                                <svg class="icon" viewBox="0 0 24 24"><path d="M12 16V4m-5 5 5-5 5 5M4 16v4h16v-4"></path></svg>
                            </span>
                            <p class="dropzone-instruction"><span>Choose File</span> or drag and drop</p>
                            <p class="dropzone-help" id="dropzone-help">Accepted: PDF, JPG, PNG (Max 2 MB)</p>
                        </div>
                        <div class="selected-file" id="dropzone-filled" aria-live="polite">
                            <div class="file-details">
                                <span class="file-check" aria-hidden="true">
                                    <svg class="icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 2.5 2.5L16 9"></path></svg>
                                </span>
                                <div class="file-copy">
                                    <p class="file-name" id="file-name-text"></p>
                                    <p class="file-size" id="file-size-text"></p>
                                </div>
                            </div>
                            <button class="remove-file" id="remove-file-btn" type="button" aria-label="Remove selected file">
                                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></svg>
                            </button>
                        </div>
                    </div>
                    <p class="field-error upload-error <?= isset($errors['dlDocument']) ? 'is-visible' : '' ?>" id="error-dlDocument"><?= e($errors['dlDocument'] ?? '') ?></p>
                </div>

                <p class="form-message error-message" id="form-message" role="alert" aria-live="polite" <?= $formError === '' ? 'hidden' : '' ?>><?= e($formError) ?></p>

                <button class="submit-button" type="submit">
                    Submit Registration
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"></path></svg>
                </button>
            </form>

            <a class="login-link" href="login.php">Already have an account? Log in</a>
        </section>
    </main>

    <footer class="site-footer">
        <div class="footer-inner">
            <p>&copy; <?= htmlspecialchars($currentYear, ENT_QUOTES, 'UTF-8') ?> ValetFlow Mobility Systems Inc. All rights reserved.</p>
            <span class="portal-label">System Auth Portal</span>
        </div>
    </footer>

    
<script src="assets/js/core.js" defer></script>
<script src="assets/js/valet-register.js" defer></script>
</body>
</html>
