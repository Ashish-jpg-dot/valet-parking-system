<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/bookings.php';
require_once __DIR__ . '/../app/valets.php';

$user = require_role('valet');
$valet = $user['valet'];
$valetId = $user['profile_id'];

$tabs = ['available', 'active', 'earnings', 'profile'];
$tab = $_GET['tab'] ?? 'available';
if (!in_array($tab, $tabs, true)) {
    $tab = 'available';
}

if (is_post()) {
    verify_csrf();
    $action = post_string('action');

    if ($action === 'toggle_online') {
        set_valet_online($valetId, (int) $valet['is_live'] !== 1);
        flash('success', (int) $valet['is_live'] === 1 ? 'You are now offline.' : 'You are now online and receiving jobs.');
        redirect('valet-dashboard.php?tab=' . $tab);
    }

    if ($action === 'accept_job') {
        $error = accept_parking_job($valetId, post_int('booking_id'));
        flash($error ? 'error' : 'success', $error ?: 'Job accepted. Head to the pickup point.');
        redirect('valet-dashboard.php?tab=' . ($error ? 'available' : 'active'));
    }

    if ($action === 'decline_job') {
        $error = decline_job($valetId, post_int('booking_id'), post_string('job_type'));
        flash($error ? 'error' : 'success', $error ?: 'Job declined. It stays available to other valets.');
        redirect('valet-dashboard.php?tab=available');
    }

    if ($action === 'arrived') {
        $error = valet_mark_arrived($valetId, post_int('booking_id'));
        flash($error ? 'error' : 'success', $error ?: 'Marked as arrived. Verify the customer PIN to collect the vehicle.');
        redirect('valet-dashboard.php?tab=active');
    }

    if ($action === 'collected') {
        $error = valet_collect_vehicle(
            $valetId,
            post_int('booking_id'),
            post_string('otp'),
            post_string('fuel_level'),
            post_int('odometer'),
            post_string('vehicle_condition'),
            post_string('notes'),
            $_FILES['photos'] ?? []
        );
        flash($error ? 'error' : 'success', $error ?: 'Pickup recorded. Take the vehicle to the parking bay.');
        redirect('valet-dashboard.php?tab=active');
    }

    if ($action === 'parked') {
        $error = valet_park_vehicle(
            $valetId,
            post_int('booking_id'),
            post_string('parking_slot'),
            post_string('parking_address'),
            post_string('vehicle_condition'),
            post_string('notes')
        );
        flash($error ? 'error' : 'success', $error ?: 'Vehicle parked. Your ' . money(VALET_PARKING_EARNING) . ' parking earning has been added.');
        redirect('valet-dashboard.php?tab=active');
    }

    if ($action === 'accept_return') {
        $error = accept_return_job($valetId, post_int('booking_id'));
        flash($error ? 'error' : 'success', $error ?: 'Return accepted. Collect the car from its parking bay.');
        redirect('valet-dashboard.php?tab=' . ($error ? 'available' : 'active'));
    }

    if ($action === 'return_arrived') {
        $error = valet_return_arrived(
            $valetId,
            post_int('booking_id'),
            post_string('otp'),
            post_string('fuel_level'),
            post_int('odometer'),
            post_string('vehicle_condition'),
            post_string('notes'),
            $_FILES['photos'] ?? []
        );
        flash($error ? 'error' : 'success', $error ?: 'Return inspection recorded. Hand the vehicle back to the customer.');
        redirect('valet-dashboard.php?tab=active');
    }

    if ($action === 'complete_return') {
        $error = valet_complete_return($valetId, post_int('booking_id'));
        flash($error ? 'error' : 'success', $error ?: 'Job completed. Your ' . money(VALET_RETURN_EARNING) . ' return earning has been added.');
        redirect('valet-dashboard.php?tab=' . ($error ? 'active' : 'earnings'));
    }

    if ($action === 'withdraw') {
        $amount = (float) post_string('amount');
        $error = request_withdrawal($valetId, $amount, $valet['upi_id'] ?? '');
        flash($error ? 'error' : 'success', $error ?: 'Withdrawal requested. The admin team will process it.');
        redirect('valet-dashboard.php?tab=earnings');
    }

    if ($action === 'update_profile') {
        $error = update_valet_profile(
            $valetId,
            $user['login_id'],
            post_string('full_name'),
            post_string('phone'),
            post_string('email'),
            post_string('upi_id')
        );
        flash($error ? 'error' : 'success', $error ?: 'Profile updated.');
        redirect('valet-dashboard.php?tab=profile');
    }

    redirect('valet-dashboard.php');
}

$flashes = take_flashes();
$jobs = available_parking_jobs($valetId);
$returns = available_return_jobs($valetId);
$active = valet_active_booking($valetId);
$earnings = valet_earnings($valetId);
$history = valet_earning_history($valetId);
$withdrawals = valet_withdrawals($valetId);
$isLive = (int) $valet['is_live'] === 1;
$openCount = count($jobs) + count($returns);

$workflow = [
    'ASSIGNED' => 'Accepted',
    'ARRIVED' => 'Arrived at pickup',
    'COLLECTED' => 'Vehicle collected',
    'PARKED' => 'Vehicle parked',
    'PAID' => 'Customer paid',
    'RETURNING' => 'Returning vehicle',
    'RETURN_ARRIVED' => 'Return arrived',
    'COMPLETED' => 'Completed',
];
$workflowKeys = array_keys($workflow);
$activeStep = $active ? array_search($active['booking_status'], $workflowKeys, true) : false;

$currentYear = date('Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcf9f8">
    <meta name="description" content="ValetFlow driver dashboard for accepting and completing valet jobs.">
    <title>Valet Dashboard — ValetFlow</title>
    <link rel="stylesheet" href="assets/css/tokens.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/css/pages/valet.css">
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to dashboard content</a>
    <header class="site-header">
        <div class="header-main">
            <a class="brand" href="valet-dashboard.php" aria-label="Valet dashboard home">
                <span class="brand-mark" aria-hidden="true">V</span>
                <span class="brand-name">ValetFlow</span>
            </a>

            <nav class="desktop-nav" role="tablist" aria-label="Driver dashboard sections">
                <?php foreach (['available' => 'Available Jobs', 'active' => 'Active Job & Status', 'earnings' => 'Earnings & History', 'profile' => 'Valet Profile'] as $key => $name): ?>
                    <button class="tab-button" id="desktop-tab-<?= e($key) ?>" type="button" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>" aria-controls="panel-<?= e($key) ?>" tabindex="<?= $tab === $key ? '0' : '-1' ?>" data-tab="<?= e($key) ?>">
                        <?= e($name) ?>
                        <?php if ($key === 'available'): ?><span class="count-pill" data-job-count><?= $openCount ?></span><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </nav>

            <div class="header-actions">
                <span class="verification-badge">
                    <span class="status-dot" aria-hidden="true"></span>
                    Approved valet · VF-VAL-<?= str_pad((string) $valetId, 3, '0', STR_PAD_LEFT) ?>
                </span>
                <div class="header-status" data-online="<?= $isLive ? 'true' : 'false' ?>">
                    <span class="status-dot" aria-hidden="true"></span>
                    <span id="header-status-text"><?= $isLive ? 'ONLINE' : 'OFFLINE' ?></span>
                    <form method="post" action="valet-dashboard.php" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_online">
                        <button class="switch" type="submit" role="switch" aria-checked="<?= $isLive ? 'true' : 'false' ?>" aria-label="<?= $isLive ? 'Go offline' : 'Go online' ?>"><span class="switch-thumb" aria-hidden="true"></span></button>
                    </form>
                </div>
                <div class="account-identity">
                    <span class="account-avatar" aria-hidden="true"><?= e(initials($user['name'])) ?></span>
                    <span class="account-copy"><strong><?= e($user['name']) ?></strong><span>Valet partner</span></span>
                </div>
                <a class="logout-link" href="logout.php" aria-label="Log out"><span class="material-symbols-outlined" aria-hidden="true">logout</span><span class="logout-link-label">Log out</span></a>
            </div>
        </div>
        <nav class="mobile-nav" role="tablist" aria-label="Driver dashboard sections">
            <div class="mobile-nav-inner">
                <?php foreach (['available' => 'Available', 'active' => 'Active', 'earnings' => 'Earnings', 'profile' => 'Profile'] as $key => $name): ?>
                    <button class="tab-button" id="mobile-tab-<?= e($key) ?>" type="button" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>" aria-controls="panel-<?= e($key) ?>" tabindex="<?= $tab === $key ? '0' : '-1' ?>" data-tab="<?= e($key) ?>"><?= e($name) ?></button>
                <?php endforeach; ?>
            </div>
        </nav>
    </header>

    <main class="page-shell dashboard-stack" id="main-content">
        <section class="top-status-row" aria-label="Valet status">
            <div class="partner-status-copy">
                <span class="partner-pill"><span class="status-dot" aria-hidden="true"></span> VALET PARTNER</span>
                <span class="status-meta"><strong>ID: VF-VAL-<?= str_pad((string) $valetId, 3, '0', STR_PAD_LEFT) ?></strong> · approved · <?= e($isLive ? 'receiving dispatches' : 'not receiving dispatches') ?></span>
            </div>
            <div class="partner-status-right">
                <span class="demo-note"><?= $isLive ? 'You can accept parking and return jobs.' : 'Go online to see open jobs.' ?></span>
                <div class="local-state" data-online="<?= $isLive ? 'true' : 'false' ?>">
                    <span class="status-dot" aria-hidden="true"></span>
                    <span class="local-state-text"><strong><?= $isLive ? 'Online' : 'Offline' ?></strong><span><?= $isLive ? 'Receiving dispatches' : 'Dispatch paused' ?></span></span>
                    <form method="post" action="valet-dashboard.php" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_online">
                        <button class="switch" type="submit" role="switch" aria-checked="<?= $isLive ? 'true' : 'false' ?>" aria-label="<?= $isLive ? 'Go offline' : 'Go online' ?>"><span class="switch-thumb" aria-hidden="true"></span></button>
                    </form>
                </div>
            </div>
        </section>

        <?php if ($flashes): ?>
            <div class="flash-stack">
                <?php foreach ($flashes as $flash): ?>
                    <p class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="metric-grid" aria-label="Shift metrics">
            <article class="metric-card">
                <div class="metric-label"><span>Today's Earnings</span><span class="material-symbols-outlined" aria-hidden="true">payments</span></div>
                <div class="metric-value"><?= e(money($earnings['today'])) ?></div>
            </article>
            <article class="metric-card">
                <div class="metric-label"><span>Completed Jobs</span><span class="material-symbols-outlined" aria-hidden="true">task_alt</span></div>
                <div class="metric-value"><?= (int) $earnings['jobs'] ?></div>
            </article>
            <article class="metric-card">
                <div class="metric-label"><span>Lifetime Earnings</span><span class="material-symbols-outlined" aria-hidden="true">account_balance</span></div>
                <div class="metric-value"><?= e(money($earnings['total'])) ?></div>
            </article>
            <article class="metric-card">
                <div class="metric-label"><span>Open Requests</span><span class="material-symbols-outlined" aria-hidden="true">radar</span></div>
                <div class="metric-value"><?= $openCount ?></div>
            </article>
        </section>

        <section class="tab-panel panel-stack" id="panel-available" role="tabpanel" aria-labelledby="desktop-tab-available" data-panel="available" <?= $tab === 'available' ? '' : 'hidden' ?>>
            <div class="section-heading">
                <div>
                    <h1>Live Curbside Requests</h1>
                    <p class="muted-copy">Accept a parking or return job. Only one active job at a time.</p>
                </div>
                <span class="sync-pill"><span class="material-symbols-outlined" aria-hidden="true">radar</span><?= $openCount ?> open request<?= $openCount === 1 ? '' : 's' ?></span>
            </div>

            <?php if (!$isLive): ?>
                <div class="offline-state">
                    <span class="offline-icon"><span class="material-symbols-outlined" aria-hidden="true">cloud_off</span></span>
                    <span class="state-pill"><span class="status-dot offline" aria-hidden="true"></span> Dispatch paused</span>
                    <h2>You are Currently Offline</h2>
                    <p>Go online to see open parking and return requests.</p>
                    <form method="post" action="valet-dashboard.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_online">
                        <button class="button" type="submit"><span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>Go Online</button>
                    </form>
                </div>
            <?php else: ?>
                <?php if (!$jobs && !$returns): ?>
                    <div class="offline-state">
                        <span class="offline-icon"><span class="material-symbols-outlined" aria-hidden="true">inbox</span></span>
                        <span class="state-pill"><span class="status-dot" aria-hidden="true"></span> You are online</span>
                        <h2>No Open Requests</h2>
                        <p>New parking and return requests will appear here automatically.</p>
                    </div>
                <?php endif; ?>

                <?php if ($jobs): ?>
                    <div class="job-grid">
                        <?php foreach ($jobs as $job): ?>
                            <article class="card job-card">
                                <div>
                                    <div class="job-card-header">
                                        <div>
                                            <div class="job-title-row">
                                                <h2><?= e(booking_ref($job['booking_id'])) ?></h2>
                                                <span class="job-tag">Parking job</span>
                                            </div>
                                            <div class="vehicle-line">
                                                <span class="material-symbols-outlined" aria-hidden="true">directions_car</span>
                                                <strong><?= e($job['vehicle_type']) ?></strong>
                                                <span>· <?= e($job['vehicle_number']) ?></span>
                                            </div>
                                            <p class="demo-note"><?= e(full_name($job['first_name'], $job['last_name'])) ?> · <?= e($job['customer_phone']) ?></p>
                                        </div>
                                        <div class="job-price"><strong><?= e(money(VALET_PARKING_EARNING)) ?></strong><span>Parking earning</span></div>
                                    </div>

                                    <div class="route-card">
                                        <div class="route-stop">
                                            <span class="route-marker" aria-hidden="true"></span>
                                            <div><span class="route-label">Pickup point</span><div class="route-place"><?= e($job['pickup_address']) ?></div><div class="route-time highlight">Pickup: <?= e(format_datetime($job['pickup_date'], $job['pickup_time'])) ?></div></div>
                                        </div>
                                        <span class="route-connector" aria-hidden="true"></span>
                                        <div class="route-stop">
                                            <span class="route-marker destination" aria-hidden="true"></span>
                                            <div><span class="route-label">Destination</span><div class="route-place">Assigned parking bay</div><div class="route-time">The return is requested separately by the customer</div></div>
                                        </div>
                                    </div>

                                    <div class="addon-list">
                                        <span class="addon-pill"><span class="material-symbols-outlined" aria-hidden="true">local_gas_station</span><?= (int) $job['fuel_service'] === 1 ? 'Fuel refill requested' : 'No fuel service' ?></span>
                                        <span class="addon-pill"><span class="material-symbols-outlined" aria-hidden="true">lock</span>Parking bay</span>
                                    </div>
                                </div>
                                <div class="inline-actions">
                                    <form method="post" action="valet-dashboard.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="accept_job">
                                        <input type="hidden" name="booking_id" value="<?= (int) $job['booking_id'] ?>">
                                        <button class="button button-full" type="submit">Accept Job <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button>
                                    </form>
                                    <form method="post" action="valet-dashboard.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="decline_job">
                                        <input type="hidden" name="job_type" value="PARKING">
                                        <input type="hidden" name="booking_id" value="<?= (int) $job['booking_id'] ?>">
                                        <button class="button button-secondary button-danger" type="submit">Decline</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($returns): ?>
                    <div class="section-heading" style="margin-top:8px">
                        <div><h2>Return Requests</h2><p class="muted-copy">Paid bookings waiting for a valet to bring the car back.</p></div>
                    </div>
                    <div class="job-grid">
                        <?php foreach ($returns as $return): ?>
                            <article class="card job-card">
                                <div>
                                    <div class="job-card-header">
                                        <div>
                                            <div class="job-title-row">
                                                <h2><?= e(booking_ref($return['booking_id'])) ?></h2>
                                                <span class="job-tag">Return job</span>
                                            </div>
                                            <div class="vehicle-line">
                                                <span class="material-symbols-outlined" aria-hidden="true">directions_car</span>
                                                <strong><?= e($return['vehicle_type']) ?></strong>
                                                <span>· <?= e($return['vehicle_number']) ?></span>
                                            </div>
                                            <p class="demo-note"><?= e(full_name($return['first_name'], $return['last_name'])) ?> · <?= e($return['customer_phone']) ?></p>
                                        </div>
                                        <div class="job-price"><strong><?= e(money(VALET_RETURN_EARNING)) ?></strong><span>Return earning</span></div>
                                    </div>
                                    <div class="route-card">
                                        <div class="route-stop">
                                            <span class="route-marker destination" aria-hidden="true"></span>
                                            <div><span class="route-label">Return to customer</span><div class="route-place"><?= e($return['return_address']) ?></div><div class="route-time highlight">Scheduled: <?= e(format_datetime($return['return_date'], $return['return_time'])) ?></div></div>
                                        </div>
                                        <span class="route-connector" aria-hidden="true"></span>
                                        <div class="route-stop">
                                            <span class="route-marker" aria-hidden="true"></span>
                                            <div><span class="route-label">Collect from</span><div class="route-place"><?= e($return['parking_address'] ?: 'Assigned parking bay') ?></div><div class="route-time">Requested: <?= e(format_ts($return['requested_at'])) ?></div></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="inline-actions">
                                    <form method="post" action="valet-dashboard.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="accept_return">
                                        <input type="hidden" name="booking_id" value="<?= (int) $return['booking_id'] ?>">
                                        <button class="button button-full" type="submit">Accept Return <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button>
                                    </form>
                                    <form method="post" action="valet-dashboard.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="decline_job">
                                        <input type="hidden" name="job_type" value="RETURN">
                                        <input type="hidden" name="booking_id" value="<?= (int) $return['booking_id'] ?>">
                                        <button class="button button-secondary button-danger" type="submit">Decline</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="tab-panel panel-stack" id="panel-active" role="tabpanel" aria-labelledby="desktop-tab-active" data-panel="active" <?= $tab === 'active' ? '' : 'hidden' ?>>
            <?php if (!$active): ?>
                <div class="offline-state">
                    <span class="offline-icon"><span class="material-symbols-outlined" aria-hidden="true">car_rental</span></span>
                    <span class="state-pill">No job in progress</span>
                    <h1>No Job Currently in Progress</h1>
                    <p>Accept a parking or return request to start a job.</p>
                    <button class="button" type="button" data-open-tab="available"><span class="material-symbols-outlined" aria-hidden="true">explore</span>View Available Jobs</button>
                </div>
            <?php else: ?>
                <div class="active-job-banner">
                    <div>
                        <span class="preview-badge"><span class="status-dot" aria-hidden="true"></span> <?= e(status_label($active['booking_status'])) ?></span>
                        <h1><?= e(booking_ref($active['booking_id'])) ?> · <?= e($active['vehicle_type']) ?></h1>
                        <p><?= e($active['vehicle_number'] . ' · ' . ($active['vehicle_color'] ?: 'Colour not set') . ' · ' . full_name($active['first_name'], $active['last_name']) . ' · ' . $active['customer_phone']) ?></p>
                    </div>
                    <div class="protection-chip">
                        <span class="material-symbols-outlined" aria-hidden="true">security</span>
                        <div><strong>Custody Protection</strong><span>Recorded inspections at both handovers</span></div>
                    </div>
                </div>

                <section class="card step-card" aria-labelledby="workflow-title">
                    <div class="step-header">
                        <div><span class="step-overline">Sequential handover protocol</span><h2 id="workflow-title">Vehicle Custody Progression</h2></div>
                        <div class="step-count"><strong>Step <?= $activeStep === false ? 1 : $activeStep + 1 ?> of <?= count($workflow) ?></strong><span><?= e(status_label($active['booking_status'])) ?></span></div>
                    </div>
                    <div class="step-grid" aria-label="Handover workflow">
                        <?php foreach ($workflow as $status => $label): ?>
                            <?php $index = array_search($status, $workflowKeys, true); ?>
                            <div class="step-node" <?= $index === $activeStep ? 'aria-current="step"' : '' ?>>
                                <span class="step-node-number"><?= $index + 1 ?></span>
                                <strong><?= e($label) ?></strong>
                                <span><?= $index < $activeStep ? 'Done' : ($index === $activeStep ? 'Current' : 'Pending') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="active-detail-grid">
                    <section class="card map-card" aria-labelledby="route-title">
                        <div class="map-heading"><div><span class="route-label">Job route</span><h2 id="route-title"><?= e($active['vehicle_number']) ?></h2></div><span class="sample-tag"><?= e(status_label($active['booking_status'])) ?></span></div>
                        <div class="map-viewer" data-map-view data-pickup-lat="<?= e((string) $active['pickup_latitude']) ?>" data-pickup-lng="<?= e((string) $active['pickup_longitude']) ?>" data-pickup-label="Pickup: <?= e($active['pickup_address']) ?>" data-return-lat="<?= e((string) $active['return_latitude']) ?>" data-return-lng="<?= e((string) $active['return_longitude']) ?>" data-return-label="Return: <?= e($active['return_address'] ?: 'Not requested yet') ?>">
                            <div class="map-canvas" data-map-canvas></div>
                            <p class="map-empty" data-map-empty hidden>Location pins were not captured for this booking.</p>
                        </div>
                        <div class="map-legend"><span><i class="map-dot" aria-hidden="true"></i>Pickup</span><span><i class="map-dot return" aria-hidden="true"></i>Return</span></div>
                        <div class="map-stops">
                            <div class="map-stop"><span>Stop A · pickup</span><strong><?= e($active['pickup_address']) ?></strong><small><?= e(format_datetime($active['pickup_date'], $active['pickup_time'])) ?></small></div>
                            <div class="map-stop"><span>Stop B · parking bay</span><strong><?= e($active['parking_slot'] ?: 'Assign a bay when you park') ?></strong><small><?= e($active['parking_address'] ?: 'Parking address recorded when parked') ?></small></div>
                            <div class="map-stop"><span>Stop C · return</span><strong><?= e($active['return_address'] ?: 'Not requested yet') ?></strong><small><?= $active['return_date'] ? e(format_datetime($active['return_date'], $active['return_time'])) : 'At the customer’s request' ?></small></div>
                        </div>
                    </section>

                    <div class="protocol-stack">
                        <section class="protocol-card">
                            <div><span class="protocol-gate">Job actions</span><h2><?= e(status_label($active['booking_status'])) ?></h2></div>

                            <?php if ($active['booking_status'] === 'ASSIGNED'): ?>
                                <p>Head to the pickup point, then mark yourself arrived. The customer sees the pickup PIN in their portal.</p>
                                <form method="post" action="valet-dashboard.php" class="form-actions">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="arrived">
                                    <input type="hidden" name="booking_id" value="<?= (int) $active['booking_id'] ?>">
                                    <button class="button" type="submit">I Have Arrived</button>
                                </form>
                            <?php elseif ($active['booking_status'] === 'ARRIVED'): ?>
                                <p>Ask the customer for the 4-digit pickup PIN, then record the inspection.</p>
                                <form method="post" action="valet-dashboard.php" class="edit-form" enctype="multipart/form-data">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="collected">
                                    <input type="hidden" name="booking_id" value="<?= (int) $active['booking_id'] ?>">
                                    <div class="form-field"><label class="field-label" for="pickup-otp">Pickup PIN</label><input id="pickup-otp" name="otp" class="input" type="text" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required></div>
                                    <div class="form-field"><label class="field-label" for="pickup-fuel">Fuel Level</label><input id="pickup-fuel" name="fuel_level" class="input" type="text" placeholder="e.g. Full" required></div>
                                    <div class="form-field"><label class="field-label" for="pickup-odometer">Odometer (km)</label><input id="pickup-odometer" name="odometer" class="input" type="number" min="0" required></div>
                                    <div class="form-field"><label class="field-label" for="pickup-condition">Condition</label><select id="pickup-condition" name="vehicle_condition" class="input" required><option>Good</option><option>Fair</option><option>Damaged</option></select></div>
                                    <div class="form-field"><label class="field-label" for="pickup-notes">Inspection Notes</label><textarea id="pickup-notes" name="notes" class="input" rows="2" placeholder="Scratches, dents, accessories"></textarea></div>
                                    <div class="form-field"><label class="field-label" for="pickup-photos">Inspection Photos</label><input id="pickup-photos" name="photos[]" class="input" type="file" accept="image/jpeg,image/png" multiple><p class="field-hint">Up to <?= INSPECTION_PHOTO_LIMIT ?> JPG or PNG photos, 2 MB each.</p></div>
                                    <div class="form-actions"><button class="button" type="submit">Verify PIN &amp; Collect</button></div>
                                </form>
                            <?php elseif ($active['booking_status'] === 'COLLECTED'): ?>
                                <p>Record the parking bay and vehicle condition. This releases your <?= e(money(VALET_PARKING_EARNING)) ?> parking earning.</p>
                                <form method="post" action="valet-dashboard.php" class="edit-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="parked">
                                    <input type="hidden" name="booking_id" value="<?= (int) $active['booking_id'] ?>">
                                    <div class="form-field"><label class="field-label" for="parking-slot">Parking Bay</label><input id="parking-slot" name="parking_slot" class="input" type="text" placeholder="e.g. Bay 4A" required></div>
                                    <div class="form-field"><label class="field-label" for="parking-address">Parking Address</label><input id="parking-address" name="parking_address" class="input" type="text" placeholder="Authorized parking location" required></div>
                                    <div class="form-field"><label class="field-label" for="parking-condition">Vehicle Condition</label><select id="parking-condition" name="vehicle_condition" class="input" required><option>Good</option><option>Fair</option><option>Damaged</option></select></div>
                                    <div class="form-field"><label class="field-label" for="parking-notes">Parking Notes</label><textarea id="parking-notes" name="notes" class="input" rows="2" placeholder="Parking position, access notes or new damage"></textarea></div>
                                    <div class="form-actions"><button class="button" type="submit">Mark Parked</button></div>
                                </form>
                            <?php elseif ($active['booking_status'] === 'PARKED'): ?>
                                <p>The vehicle is parked. Waiting for the customer to pay <?= e(money(booking_total($active))) ?>. You can accept another job while you wait.</p>
                            <?php elseif ($active['booking_status'] === 'PAID'): ?>
                                <p>The customer has paid. Waiting for them to request the return. You can accept another job while you wait.</p>
                            <?php elseif ($active['booking_status'] === 'RETURNING'): ?>
                                <p>Collect the car from its parking bay and drive to the return address. Ask the customer for the return PIN when you arrive.</p>
                                <form method="post" action="valet-dashboard.php" class="edit-form" enctype="multipart/form-data">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="return_arrived">
                                    <input type="hidden" name="booking_id" value="<?= (int) $active['booking_id'] ?>">
                                    <div class="form-field"><label class="field-label" for="return-otp">Return PIN</label><input id="return-otp" name="otp" class="input" type="text" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required></div>
                                    <div class="form-field"><label class="field-label" for="return-fuel">Fuel Level</label><input id="return-fuel" name="fuel_level" class="input" type="text" placeholder="e.g. Full" required></div>
                                    <div class="form-field"><label class="field-label" for="return-odometer">Odometer (km)</label><input id="return-odometer" name="odometer" class="input" type="number" min="0" required></div>
                                    <div class="form-field"><label class="field-label" for="return-condition">Condition</label><select id="return-condition" name="vehicle_condition" class="input" required><option>Good</option><option>Fair</option><option>Damaged</option></select></div>
                                    <div class="form-field"><label class="field-label" for="return-notes">Inspection Notes</label><textarea id="return-notes" name="notes" class="input" rows="2" placeholder="Return condition notes"></textarea></div>
                                    <div class="form-field"><label class="field-label" for="return-photos">Inspection Photos</label><input id="return-photos" name="photos[]" class="input" type="file" accept="image/jpeg,image/png" multiple><p class="field-hint">Up to <?= INSPECTION_PHOTO_LIMIT ?> JPG or PNG photos, 2 MB each.</p></div>
                                    <div class="form-actions"><button class="button" type="submit">Verify PIN &amp; Record Return</button></div>
                                </form>
                            <?php elseif ($active['booking_status'] === 'RETURN_ARRIVED'): ?>
                                <p>The return inspection is recorded. Hand the vehicle back to the customer and complete the job.</p>
                                <form method="post" action="valet-dashboard.php" class="form-actions">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="complete_return">
                                    <input type="hidden" name="booking_id" value="<?= (int) $active['booking_id'] ?>">
                                    <button class="button" type="submit">Complete Job</button>
                                </form>
                            <?php endif; ?>
                        </section>

                        <section class="protocol-card">
                            <div><span class="protocol-gate">Customer &amp; payment</span><h2>Booking Details</h2></div>
                            <div class="summary-row"><span>Customer</span><strong><?= e(full_name($active['first_name'], $active['last_name'])) ?></strong></div>
                            <div class="summary-row"><span>Phone</span><strong><?= e($active['customer_phone']) ?></strong></div>
                            <div class="summary-row"><span>Valet service fee</span><strong><?= e(money($active['price'])) ?></strong></div>
                            <div class="summary-row"><span>Fuel service</span><strong><?= (int) $active['fuel_service'] === 1 ? e(money(FUEL_FARE)) : 'Not requested' ?></strong></div>
                            <div class="summary-row"><span>Payment</span><strong><?= $active['payment_id'] ? e('Paid ' . money($active['paid_amount'])) : 'Not paid' ?></strong></div>
                        </section>
                    </div>
                </div>

                <section class="card">
                    <div class="card-heading"><div><span class="route-label">Accountability record</span><h2>Inspection Records</h2></div></div>
                    <?= inspection_summary(booking_inspections((int) $active['booking_id'])) ?>
                </section>
            <?php endif; ?>
        </section>

        <section class="tab-panel panel-stack" id="panel-earnings" role="tabpanel" aria-labelledby="desktop-tab-earnings" data-panel="earnings" <?= $tab === 'earnings' ? '' : 'hidden' ?>>
            <div class="section-heading">
                <div>
                    <h1>Earnings &amp; Payouts</h1>
                    <p class="muted-copy">Completed jobs, available balance and payout requests.</p>
                </div>
            </div>

            <div class="earnings-grid">
                <article class="lifetime-card">
                    <div class="metric-label"><span>Lifetime Valet Earnings</span><span class="material-symbols-outlined" aria-hidden="true">account_balance</span></div>
                    <div class="metric-value"><?= e(money($earnings['total'])) ?></div>
                    <p><?= e(money(VALET_PARKING_EARNING)) ?> for each parking job and <?= e(money(VALET_RETURN_EARNING)) ?> for each return job.</p>
                    <div class="summary-row" style="margin-top:20px"><span>Available to withdraw</span><strong><?= e(money($earnings['available'])) ?></strong></div>
                </article>
                <article class="card earnings-card">
                    <div class="metric-label"><span>Today's Take-Home</span><span class="sample-tag"><?= (int) $earnings['jobs'] ?> jobs total</span></div>
                    <div><div class="metric-value"><?= e(money($earnings['today'])) ?></div><p>Recorded in your earnings ledger today.</p></div>
                </article>
            </div>

            <section class="card payout-card">
                <div><span class="step-overline">Payout</span><h2>Request a Withdrawal</h2></div>
                <?php if (empty($valet['upi_id'])): ?>
                    <p class="muted-copy">Add a UPI ID in your profile before requesting a withdrawal.</p>
                <?php else: ?>
                    <p class="muted-copy">Payout destination: <strong><?= e($valet['upi_id']) ?></strong></p>
                    <form method="post" action="valet-dashboard.php" class="form-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="withdraw">
                        <div class="form-field"><label class="field-label" for="withdraw-amount">Amount (max <?= e(money($earnings['available'])) ?>)</label><input id="withdraw-amount" name="amount" class="input" type="number" min="1" max="<?= e((string) $earnings['available']) ?>" step="0.01" required></div>
                        <button class="button" type="submit">Request Withdrawal</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="card history-card">
                <div class="history-header">
                    <div><h2>Earnings Ledger</h2><p class="muted-copy">Every parking and return job you completed.</p></div>
                </div>
                <div class="table-wrap">
                    <table>
                        <caption class="demo-note" style="text-align:left;padding:0 12px 10px">Your completed job earnings.</caption>
                        <thead><tr><th scope="col">Booking</th><th scope="col">Vehicle</th><th scope="col">Job</th><th scope="col">Recorded</th><th scope="col">Amount</th></tr></thead>
                        <tbody>
                            <?php if (!$history): ?>
                                <tr><td colspan="5" class="table-empty">No earnings yet. Complete your first job to start the ledger.</td></tr>
                            <?php else: ?>
                                <?php foreach ($history as $record): ?>
                                    <tr>
                                        <td class="numeric"><strong><?= e(booking_ref($record['booking_id'])) ?></strong></td>
                                        <td><strong><?= e($record['vehicle_type']) ?></strong><small><?= e($record['vehicle_number']) ?></small></td>
                                        <td><span class="job-tag"><?= e(ucfirst(strtolower($record['job_type']))) ?></span></td>
                                        <td><?= e(format_ts($record['created_at'])) ?></td>
                                        <td class="numeric"><strong><?= e(money($record['amount'])) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card history-card">
                <div class="history-header"><div><h2>Withdrawal History</h2><p class="muted-copy">Payout requests and their status.</p></div></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Requested</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php if (!$withdrawals): ?>
                                <tr><td colspan="3" class="table-empty">No withdrawals requested yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($withdrawals as $withdrawal): ?>
                                    <tr>
                                        <td><?= e(format_ts($withdrawal['created_at'])) ?></td>
                                        <td class="numeric"><?= e(money($withdrawal['amount'])) ?></td>
                                        <td><span class="status-pill tone-<?= $withdrawal['status'] === 'PAID' ? 'success' : ($withdrawal['status'] === 'REJECTED' ? 'danger' : 'warning') ?>"><?= e(ucfirst(strtolower($withdrawal['status']))) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <section class="tab-panel panel-stack" id="panel-profile" role="tabpanel" aria-labelledby="desktop-tab-profile" data-panel="profile" <?= $tab === 'profile' ? '' : 'hidden' ?>>
            <section class="profile-layout">
                <article class="card profile-main">
                    <div class="profile-heading">
                        <div class="profile-person">
                            <span class="profile-avatar" aria-hidden="true"><?= e(initials($user['name'])) ?></span>
                            <div><div class="job-title-row"><h1><?= e($user['name']) ?></h1><span class="job-tag">Approved valet</span></div><p>Valet ID VF-VAL-<?= str_pad((string) $valetId, 3, '0', STR_PAD_LEFT) ?> · <?= e($user['username']) ?></p></div>
                        </div>
                    </div>
                    <div class="profile-facts">
                        <div class="profile-fact"><span>Full Name</span><strong><?= e($user['name']) ?></strong><small>Editable below</small></div>
                        <div class="profile-fact"><span>Registered Mobile Phone</span><strong><?= e($valet['phone']) ?></strong><small>Used for dispatch coordination</small></div>
                        <div class="profile-fact"><span>Email Address</span><strong><?= e($user['username']) ?></strong><small>Sign-in address</small></div>
                        <div class="profile-fact"><span>Availability</span><strong><?= $isLive ? 'Online' : 'Offline' ?></strong><small>Toggle from the header</small></div>
                    </div>
                    <div class="license-facts">
                        <div class="profile-fact"><span>Commercial Licence Number</span><strong><?= e($valet['license_number']) ?></strong><small>Approved by the admin team</small></div>
                        <div class="profile-fact"><span>UPI Payout Handle</span><strong><?= e($valet['upi_id'] ?: 'Not added') ?></strong><small>Used for withdrawal payouts</small></div>
                    </div>
                </article>

                <article class="card payout-card">
                    <div><span class="step-overline">Account</span><h2>Edit Profile</h2></div>
                    <form method="post" action="valet-dashboard.php" class="edit-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-field"><label class="field-label" for="valet-name">Full Name</label><input id="valet-name" name="full_name" class="input" type="text" value="<?= e($user['name']) ?>" required></div>
                        <div class="form-field"><label class="field-label" for="valet-phone">Phone</label><input id="valet-phone" name="phone" class="input" type="tel" value="<?= e($valet['phone']) ?>" required></div>
                        <div class="form-field"><label class="field-label" for="valet-email">Email Address</label><input id="valet-email" name="email" class="input" type="email" value="<?= e($user['username']) ?>" required></div>
                        <div class="form-field"><label class="field-label" for="valet-upi">UPI ID</label><input id="valet-upi" name="upi_id" class="input" type="text" value="<?= e($valet['upi_id'] ?? '') ?>" placeholder="name@bank"></div>
                        <div class="form-actions"><button class="button" type="submit">Save Profile</button><a class="button button-secondary" href="logout.php">Log Out</a></div>
                    </form>
                </article>
            </section>
        </section>

        <div class="toast" role="status" aria-live="polite" data-toast hidden></div>
    </main>

    <footer class="site-footer">
        <strong>ValetFlow Mobility Systems</strong>
        <span>© <?= e($currentYear) ?> ValetFlow · Driver Portal</span>
    </footer>

    <script src="assets/js/core.js" defer></script>
    <script src="assets/vendor/leaflet/leaflet.js" defer></script>
    <script src="assets/js/maps.js" defer></script>
    <script src="assets/js/valet.js" defer></script>
</body>
</html>
