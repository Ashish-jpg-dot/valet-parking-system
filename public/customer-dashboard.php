<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/bookings.php';

$user = require_role('customer');
$customerId = $user['profile_id'];
$customer = $user['customer'];

$tabs = ['overview', 'book', 'active', 'history', 'vehicles', 'profile'];
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, $tabs, true)) {
    $tab = 'overview';
}

if (is_post()) {
    verify_csrf();
    $action = post_string('action');

    if ($action === 'create_booking') {
        $pickupAt = post_string('pickup_at');
        [$bookingId, $error] = create_booking(
            $customerId,
            post_int('vehicle_id'),
            post_string('pickup_address'),
            post_float('pickup_lat'),
            post_float('pickup_lng'),
            $pickupAt,
            post_string('fuel_service') === '1'
        );
        if ($error) {
            flash('error', $error);
            redirect('customer-dashboard.php?tab=book');
        }
        flash('success', 'Booking ' . booking_ref($bookingId) . ' created. We are finding a valet.');
        redirect('customer-dashboard.php?tab=active');
    }

    if ($action === 'add_vehicle') {
        $error = add_vehicle(
            $customerId,
            post_string('vehicle_type'),
            post_string('vehicle_number'),
            post_string('vehicle_color'),
            post_string('fuel_level'),
            post_int('odometer')
        );
        flash($error ? 'error' : 'success', $error ?: 'Vehicle added to your fleet.');
        redirect('customer-dashboard.php?tab=vehicles');
    }

    if ($action === 'update_profile') {
        $error = update_customer_profile(
            $customerId,
            $user['login_id'],
            post_string('full_name'),
            post_string('phone'),
            post_string('email'),
            post_string('address')
        );
        flash($error ? 'error' : 'success', $error ?: 'Profile updated.');
        redirect('customer-dashboard.php?tab=profile');
    }

    if ($action === 'pay') {
        $error = pay_booking(post_int('booking_id'), $customerId);
        flash($error ? 'error' : 'success', $error ?: 'Payment recorded. You can now request the return.');
        redirect('customer-dashboard.php?tab=active');
    }

    if ($action === 'request_return') {
        $error = request_return(
            post_int('booking_id'),
            $customerId,
            post_string('return_address'),
            post_string('return_at'),
            post_float('return_lat'),
            post_float('return_lng')
        );
        flash($error ? 'error' : 'success', $error ?: 'Return requested. A valet will be assigned shortly.');
        redirect('customer-dashboard.php?tab=active');
    }

    if ($action === 'cancel') {
        $error = cancel_booking(post_int('booking_id'), $customerId);
        flash($error ? 'error' : 'success', $error ?: 'Booking cancelled.');
        redirect('customer-dashboard.php?tab=history');
    }

    if ($action === 'complaint') {
        $error = report_complaint($customerId, post_int('booking_id'), post_string('message'));
        flash($error ? 'error' : 'success', $error ?: 'Your complaint has been submitted to the admin team.');
        redirect('customer-dashboard.php?tab=history');
    }

    if ($action === 'rate') {
        $error = rate_booking($customerId, post_int('booking_id'), post_int('stars'), post_string('comment'));
        flash($error ? 'error' : 'success', $error ?: 'Thanks — your feedback has been saved.');
        redirect('customer-dashboard.php?tab=history');
    }

    redirect('customer-dashboard.php');
}

$flashes = take_flashes();
$vehicles = customer_vehicles($customerId);
$active = active_booking($customerId);
$bookings = customer_bookings($customerId);
$completed = array_values(array_filter($bookings, static fn ($b) => $b['booking_status'] === 'COMPLETED'));
$recent = array_slice($completed, 0, 3);
$activeCount = $active ? 1 : 0;
$activeInspections = $active ? booking_inspections((int) $active['booking_id']) : [];
$returnAddress = $active ? ($active['return_address'] ?: $active['pickup_address']) : '';
$returnLat = $active ? ($active['return_latitude'] ?? $active['pickup_latitude']) : null;
$returnLng = $active ? ($active['return_longitude'] ?? $active['pickup_longitude']) : null;

$returnMin = new DateTime();
if ($active) {
    $pickupAt = DateTime::createFromFormat('Y-m-d H:i:s', $active['pickup_date'] . ' ' . $active['pickup_time']);
    if ($pickupAt && $pickupAt > $returnMin) {
        $returnMin = $pickupAt;
    }
}
$returnMin = $returnMin->format('Y-m-d\TH:i');

$inspectionCache = [];
$inspectionsFor = static function (int $bookingId) use (&$inspectionCache): array {
    return $inspectionCache[$bookingId] ??= booking_inspections($bookingId);
};

$steps = [
    ['SEARCHING', 'Requested'],
    ['ASSIGNED', 'Valet assigned'],
    ['ARRIVED', 'Valet arrived'],
    ['COLLECTED', 'Vehicle collected'],
    ['PARKED', 'Vehicle parked'],
    ['PAID', 'Payment complete'],
    ['RETURN_REQUESTED', 'Return requested'],
    ['RETURNING', 'Return in progress'],
    ['RETURN_ARRIVED', 'Return arrived'],
    ['COMPLETED', 'Completed'],
];
$activeStep = $active ? array_search($active['booking_status'], array_column($steps, 0), true) : false;

$currentYear = date('Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcf9f8">
    <meta name="description" content="Manage your ValetFlow bookings, vehicles and valet returns.">
    <title>Customer Portal — ValetFlow</title>
    <link rel="stylesheet" href="assets/css/tokens.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/css/pages/customer.css">
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to dashboard content</a>
    <header class="site-header">
        <div class="page-width header-main">
            <div class="header-brand-group">
                <a class="brand" href="customer-dashboard.php" aria-label="Customer dashboard home">
                    <span class="brand-mark" aria-hidden="true">V</span>
                    <span class="brand-name">ValetFlow</span>
                </a>

                <nav class="desktop-tabs" role="tablist" aria-label="Customer dashboard sections">
                    <?php foreach ([
                        'overview' => 'Home',
                        'book' => 'Book a Valet',
                        'active' => 'Active Booking',
                        'history' => 'Booking History',
                        'vehicles' => 'My Vehicles',
                        'profile' => 'Profile',
                    ] as $key => $label): ?>
                        <button class="tab-button" id="desktop-tab-<?= e($key) ?>" type="button" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>" aria-controls="panel-<?= e($key) ?>" data-tab="<?= e($key) ?>" tabindex="<?= $tab === $key ? '0' : '-1' ?>">
                            <?= e($label) ?>
                            <?php if ($key === 'active'): ?><span class="nav-count"><?= $activeCount ?> Active</span><?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="header-actions">
                <div class="portal-status" aria-label="<?= $active ? 'Active booking in progress' : 'No active bookings' ?>">
                    <span class="<?= $active ? 'green-dot' : 'neutral-dot' ?>" aria-hidden="true"></span>
                    <span><?= $active ? e(status_label($active['booking_status'])) : 'No Active Bookings' ?></span>
                </div>
                <a class="logout-link" href="logout.php" aria-label="Log out"><span class="material-symbols-outlined" aria-hidden="true">logout</span><span class="logout-link-label">Log out</span></a>
            </div>
        </div>
        <nav class="mobile-tabs" role="tablist" aria-label="Customer dashboard sections">
            <?php foreach ([
                'overview' => 'Home',
                'book' => 'Book',
                'active' => 'Active',
                'history' => 'History',
                'vehicles' => 'Vehicles',
                'profile' => 'Profile',
            ] as $key => $label): ?>
                <button class="tab-button" id="mobile-tab-<?= e($key) ?>" type="button" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>" aria-controls="panel-<?= e($key) ?>" data-tab="<?= e($key) ?>" tabindex="<?= $tab === $key ? '0' : '-1' ?>"><?= e($label) ?></button>
            <?php endforeach; ?>
        </nav>
    </header>

    <main class="page-width" id="main-content">
        <?php if ($flashes): ?>
            <div class="flash-stack">
                <?php foreach ($flashes as $flash): ?>
                    <p class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="tab-panel panel-stack" id="panel-overview" role="tabpanel" aria-labelledby="desktop-tab-overview" data-panel="overview" <?= $tab === 'overview' ? '' : 'hidden' ?>>
            <div class="card welcome-card">
                <div class="welcome-copy">
                    <span class="account-badge"><span class="green-dot" aria-hidden="true"></span>Customer account · <?= e($user['username']) ?></span>
                    <?php if ($active): ?>
                        <h1>Your <?= e($active['vehicle_type']) ?> is in valet custody</h1>
                        <p><?= e(booking_ref($active['booking_id']) . ' · ' . status_label($active['booking_status']) . ' · ' . $active['pickup_address']) ?></p>
                    <?php else: ?>
                        <h1>Welcome back, <?= e($customer['first_name']) ?></h1>
                        <p><?= $vehicles ? 'Book a valet for your next trip. Pick your car, pickup point and time — it takes a minute.' : 'Add your vehicle first, then booking a valet takes about a minute.' ?></p>
                        <p class="welcome-meta"><?= count($vehicles) ?> saved vehicle<?= count($vehicles) === 1 ? '' : 's' ?> · <?= count($completed) ?> completed trip<?= count($completed) === 1 ? '' : 's' ?></p>
                    <?php endif; ?>
                </div>
                <div class="button-row">
                    <?php if ($active): ?>
                        <button class="button" type="button" data-open-tab="active"><span class="material-symbols-outlined" aria-hidden="true">radar</span>Track Your Valet</button>
                        <button class="button button-secondary" type="button" data-open-tab="history"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>View History</button>
                    <?php elseif ($vehicles): ?>
                        <button class="button" type="button" data-open-tab="book"><span class="material-symbols-outlined" aria-hidden="true">add_circle</span>Book a Valet</button>
                        <button class="button button-secondary" type="button" data-open-tab="history"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>View History</button>
                    <?php else: ?>
                        <button class="button" type="button" data-open-tab="vehicles"><span class="material-symbols-outlined" aria-hidden="true">directions_car</span>Add Your Vehicle</button>
                        <button class="button button-secondary" type="button" data-open-tab="history"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>View History</button>
                    <?php endif; ?>
                </div>
            </div>

            <section class="card">
                <div class="card-heading">
                    <h2>Your Vehicles</h2>
                    <button class="text-action" type="button" data-open-tab="vehicles">Manage fleet <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button>
                </div>
                <?php if (!$vehicles): ?>
                    <p class="muted-copy">No vehicles saved yet. Add one so your next booking takes a minute.</p>
                    <div class="button-row" style="margin-top:12px"><button class="button button-secondary" type="button" data-open-tab="vehicles"><span class="material-symbols-outlined" aria-hidden="true">add</span>Add Vehicle</button></div>
                <?php else: ?>
                    <div class="fleet-grid">
                        <?php foreach (array_slice($vehicles, 0, 2) as $vehicle): ?>
                            <article class="vehicle-card">
                                <div class="vehicle-heading">
                                    <div><h3><?= e($vehicle['vehicle_type']) ?></h3><p><?= e(($vehicle['vehicle_color'] ?: 'Colour not set') . ' · ' . ($vehicle['fuel_level'] ?: 'Fuel not set')) ?></p></div>
                                    <span class="status-pill">Saved</span>
                                </div>
                                <div class="vehicle-foot"><span>Plate: <strong><?= e($vehicle['vehicle_number']) ?></strong></span><span><?= $vehicle['odometer'] ? e(number_format((int) $vehicle['odometer'])) . ' km' : 'Odometer not set' ?></span></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <article class="card">
                <div class="card-heading">
                    <h2>Recent Completed Journeys</h2>
                    <button class="text-action" type="button" data-open-tab="history">View All History</button>
                </div>
                <div class="recent-list">
                    <?php if (!$recent): ?>
                        <p class="muted-copy">No completed journeys yet. Your first booking will appear here.</p>
                    <?php else: ?>
                        <?php foreach ($recent as $booking): ?>
                            <div class="recent-item">
                                <div><strong><?= e(booking_ref($booking['booking_id']) . ' · ' . $booking['vehicle_type']) ?></strong><small><?= e(format_date($booking['pickup_date']) . ' · ' . (parking_slot_label($booking['parking_slot']) ?: 'Parking location')) ?></small></div>
                                <span class="recent-total"><?= e(money(booking_total($booking))) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>
        </section>

        <section class="tab-panel panel-stack" id="panel-book" role="tabpanel" aria-labelledby="desktop-tab-book" data-panel="book" <?= $tab === 'book' ? '' : 'hidden' ?>>
            <?php if ($active): ?>
                <div class="banner">
                    <div class="banner-copy">
                        <span class="banner-icon" aria-hidden="true"><span class="material-symbols-outlined">info</span></span>
                        <div><strong>You already have an active booking</strong><p>Complete or cancel <?= e(booking_ref($active['booking_id'])) ?> before creating another reservation.</p></div>
                    </div>
                    <button class="button" type="button" data-open-tab="active">View Active Booking</button>
                </div>
            <?php elseif (!$vehicles): ?>
                <div class="banner">
                    <div class="banner-copy">
                        <span class="banner-icon" aria-hidden="true"><span class="material-symbols-outlined">directions_car</span></span>
                        <div><strong>Add a vehicle first</strong><p>Register the car you want collected. It will be available in the booking form.</p></div>
                    </div>
                    <button class="button" type="button" data-open-tab="vehicles">Add Vehicle</button>
                </div>
            <?php endif; ?>

            <div class="booking-layout">
                <section class="card form-card" aria-labelledby="booking-title">
                    <h1 class="card-title" id="booking-title">Book a Valet</h1>
                    <p class="muted-copy">Set your pickup point, vehicle and pickup time. You will choose the return time and destination when you request the return.</p>
                    <form class="form-stack" id="booking-form" method="post" action="customer-dashboard.php" data-base-fare="<?= e((string) BASE_FARE) ?>" data-fuel-fare="<?= e((string) FUEL_FARE) ?>" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_booking">
                        <div class="field">
                            <label for="pickup-location">Pickup Location <span class="required" aria-hidden="true">*</span></label>
                            <div class="location-control">
                                <span class="material-symbols-outlined" aria-hidden="true">search</span>
                                <input class="control" id="pickup-location" name="pickup_address" type="text" placeholder="Street address, landmark, airport, hotel or event venue" required aria-describedby="pickup-error">
                            </div>
                            <p class="control-error" id="pickup-error"></p>
                        </div>

                        <div class="map-picker" data-map-picker data-lat="pickup-lat" data-lng="pickup-lng" data-address="pickup-location" data-default-lat="<?= e((string) MAP_DEFAULT_LAT) ?>" data-default-lng="<?= e((string) MAP_DEFAULT_LNG) ?>" data-zoom="<?= (int) MAP_DEFAULT_ZOOM ?>">
                            <div class="map-toolbar">
                                <button class="button button-secondary" type="button" data-map-search><span class="material-symbols-outlined" aria-hidden="true">travel_explore</span>Search address</button>
                                <button class="button button-secondary" type="button" data-map-locate><span class="material-symbols-outlined" aria-hidden="true">my_location</span>Use my location</button>
                                <p class="map-status" data-map-status>Search the address, then click the map or drag the pin to set the exact pickup point.</p>
                            </div>
                            <div class="map-canvas" data-map-canvas></div>
                        </div>
                        <input type="hidden" id="pickup-lat" name="pickup_lat" value="">
                        <input type="hidden" id="pickup-lng" name="pickup_lng" value="">

                        <div class="field">
                            <label for="select-vehicle">Vehicle Selection <span class="required" aria-hidden="true">*</span></label>
                            <select class="control" id="select-vehicle" name="vehicle_id" required>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= (int) $vehicle['vehicle_id'] ?>">
                                        <?= e($vehicle['vehicle_type'] . ' (' . $vehicle['vehicle_number'] . ') · ' . $vehicle['vehicle_color']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="pickup-time">Pickup Date &amp; Time <span class="required" aria-hidden="true">*</span></label>
                            <input class="control" id="pickup-time" name="pickup_at" type="datetime-local" required aria-describedby="pickup-time-error">
                            <p class="control-error" id="pickup-time-error"></p>
                        </div>

                        <div class="field">
                            <label>Contact Number</label>
                            <div class="contact-readout">
                                <span class="material-symbols-outlined" aria-hidden="true">call</span>
                                <strong><?= e($customer['phone']) ?></strong>
                                <button class="text-action" type="button" data-open-tab="profile">Change in Profile</button>
                            </div>
                            <p class="field-hint">Your valet uses this number to coordinate the handover.</p>
                        </div>

                        <fieldset class="fuel-box">
                            <legend class="field-label">Optional Fuel Service · Pay Later</legend>
                            <div class="fuel-choices">
                                <label class="choice"><input type="radio" name="fuel_service" value="0" checked><span>No — No fuel needed</span></label>
                                <label class="choice"><input type="radio" name="fuel_service" value="1"><span>Yes — Refill during the stay (<?= e(money(FUEL_FARE)) ?> added to the final invoice)</span></label>
                            </div>
                        </fieldset>

                        <button class="button" type="submit" style="min-height:50px" <?= (!$vehicles || $active) ? 'disabled' : '' ?>><span class="material-symbols-outlined" aria-hidden="true">key</span>Confirm Reservation</button>
                    </form>
                </section>

                <aside class="panel-stack" aria-label="Fare details">
                    <section class="card fare-card">
                        <div class="card-heading"><h2>Fare Breakdown</h2><span class="eyebrow">Flat fare</span></div>
                        <div class="fare-list">
                            <div class="fare-row"><span>Fixed Valet Service Fee</span><strong><?= e(money(BASE_FARE)) ?></strong></div>
                            <div class="fare-row"><span>Parking Bay</span><strong style="color:var(--green)">Included</strong></div>
                            <div class="fare-row"><span>Pickup &amp; Return Inspections</span><strong style="color:var(--green)">Included</strong></div>
                            <div class="fare-row"><span>Fuel Refill Service <small id="fuel-badge">None</small></span><strong id="fuel-amount">₹0.00</strong></div>
                        </div>
                        <div class="fare-total"><div><strong>Total Payable</strong><small>Paid after the vehicle is parked</small></div><strong id="total-fare"><?= e(money(BASE_FARE)) ?></strong></div>
                    </section>
                    <section class="card guarantee-card">
                        <h3><span class="material-symbols-outlined" aria-hidden="true">verified</span>Recorded Handovers</h3>
                        <p>Every pickup, parking and return stage records the vehicle condition, odometer, fuel level and up to three photos.</p>
                    </section>
                </aside>
            </div>
        </section>

        <section class="tab-panel panel-stack" id="panel-active" role="tabpanel" aria-labelledby="desktop-tab-active" data-panel="active" <?= $tab === 'active' ? '' : 'hidden' ?>>
            <?php if (!$active): ?>
                <div class="card active-empty">
                    <span class="garage-icon" aria-hidden="true"><span class="material-symbols-outlined">garage_home</span></span>
                    <span class="eyebrow">Status Idle</span>
                    <h1>There is no active booking</h1>
                    <p>You have no vehicles in custody or in transit. Your completed sessions are in Booking History.</p>
                    <div class="button-row" style="justify-content:center">
                        <button class="button" type="button" data-open-tab="book"><span class="material-symbols-outlined" aria-hidden="true">add_circle</span>Book a Valet</button>
                        <button class="button button-secondary" type="button" data-open-tab="history"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>View Booking History</button>
                    </div>
                </div>
            <?php else: ?>
                <div class="demo-tracking">
                    <div class="panel-stack">
                        <section class="card">
                            <div class="card-heading">
                                <div>
                                    <span class="eyebrow"><?= e(booking_ref($active['booking_id'])) ?></span>
                                    <h1 class="card-title"><?= e($active['vehicle_type']) ?> · <?= e($active['vehicle_number']) ?></h1>
                                </div>
                                <span class="status-pill tone-<?= e(status_tone($active['booking_status'])) ?>"><?= e(status_label($active['booking_status'])) ?></span>
                            </div>
                            <div class="custody-details" style="margin-top:13px">
                                <div class="detail-tile"><small>Pickup</small><strong><?= e(format_datetime($active['pickup_date'], $active['pickup_time'])) ?></strong></div>
                                <div class="detail-tile"><small>Return</small><strong><?= $active['return_date'] ? e(format_datetime($active['return_date'], $active['return_time'])) : 'At your request' ?></strong></div>
                                <div class="detail-tile"><small>Parking bay</small><strong><?= e($active['parking_slot'] ?: 'Not parked yet') ?></strong></div>
                                <div class="detail-tile"><small>Total</small><strong><?= e(money(booking_total($active))) ?></strong></div>
                            </div>
                            <p class="muted-copy" style="margin-top:13px"><?= e($active['pickup_address']) ?></p>
                        </section>

                        <section class="card">
                            <div class="card-heading"><div><span class="eyebrow">Handover points</span><h2>Pickup &amp; Return Map</h2></div></div>
                            <div class="map-viewer" data-map-view data-pickup-lat="<?= e((string) $active['pickup_latitude']) ?>" data-pickup-lng="<?= e((string) $active['pickup_longitude']) ?>" data-pickup-label="Pickup: <?= e($active['pickup_address']) ?>" data-return-lat="<?= e((string) $active['return_latitude']) ?>" data-return-lng="<?= e((string) $active['return_longitude']) ?>" data-return-label="Return: <?= e($active['return_address'] ?: 'Not requested yet') ?>">
                                <div class="map-canvas" data-map-canvas></div>
                                <p class="map-empty" data-map-empty hidden>Location pins were not captured for this booking.</p>
                            </div>
                            <div class="map-legend"><span><i class="map-dot" aria-hidden="true"></i>Pickup</span><span><i class="map-dot return" aria-hidden="true"></i>Return</span></div>
                        </section>

                        <section class="card">
                            <div class="card-heading"><div><span class="eyebrow">Accountability record</span><h2>Vehicle Inspections</h2></div></div>
                            <?= inspection_summary($activeInspections) ?>
                        </section>

                        <?php if (in_array($active['booking_status'], ['ASSIGNED', 'ARRIVED', 'COLLECTED'], true) && !empty($active['pickup_otp'])): ?>
                            <section class="card">
                                <div class="card-heading"><div><span class="eyebrow">Pickup handover</span><h2>Share this PIN with your valet</h2></div></div>
                                <div class="otp-box">
                                    <div><strong><?= e($active['pickup_otp']) ?></strong><p class="muted-copy">Do not share this PIN until the valet is in front of you.</p></div>
                                    <span class="material-symbols-outlined" aria-hidden="true">key</span>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if (in_array($active['booking_status'], ['RETURNING', 'RETURN_ARRIVED'], true) && !empty($active['return_otp'])): ?>
                            <section class="card">
                                <div class="card-heading"><div><span class="eyebrow">Return handover</span><h2>Share this return PIN with your valet</h2></div></div>
                                <div class="otp-box">
                                    <div><strong><?= e($active['return_otp']) ?></strong><p class="muted-copy">Confirm the car is back before sharing this PIN.</p></div>
                                    <span class="material-symbols-outlined" aria-hidden="true">key</span>
                                </div>
                            </section>
                        <?php endif; ?>

                        <section class="card">
                            <div class="card-heading"><div><span class="eyebrow">Custody progression</span><h2>Booking Timeline</h2></div><span class="status-pill tone-<?= e(status_tone($active['booking_status'])) ?>"><?= e(status_label($active['booking_status'])) ?></span></div>
                            <div class="timeline" aria-label="Booking timeline">
                                <?php foreach ($steps as $index => [$status, $label]): ?>
                                    <?php
                                    $state = '';
                                    if ($activeStep !== false) {
                                        if ($index < $activeStep) {
                                            $state = ' is-done';
                                        } elseif ($index === $activeStep) {
                                            $state = ' is-current';
                                        }
                                    }
                                    ?>
                                    <div class="timeline-step<?= $state ?>"><strong><?= sprintf('%02d', $index + 1) ?> · <?= e($label) ?></strong><small><?= $index < $activeStep ? 'Done' : ($index === $activeStep ? 'Current' : 'Pending') ?></small></div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <section class="card">
                            <div class="card-heading"><h2>Payment &amp; Return</h2></div>
                            <?php if ($active['payment_id']): ?>
                                <p class="muted-copy">Paid <?= e(money($active['paid_amount'])) ?> on <?= e(format_ts($active['paid_at'])) ?> via <?= e($active['method']) ?>.</p>
                            <?php elseif ($active['booking_status'] === 'PARKED'): ?>
                                <p class="muted-copy">Your vehicle is parked. Pay <?= e(money(booking_total($active))) ?> to unlock the return request.</p>
                                <form method="post" action="customer-dashboard.php" class="button-row">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="pay">
                                    <input type="hidden" name="booking_id" value="<?= (int) $active['booking_id'] ?>">
                                    <button class="button" type="submit"><span class="material-symbols-outlined" aria-hidden="true">payments</span>Pay <?= e(money(booking_total($active))) ?></button>
                                </form>
                            <?php else: ?>
                                <p class="muted-copy">Payment is enabled once the valet has parked your vehicle.</p>
                            <?php endif; ?>

                            <?php if ($active['booking_status'] === 'PAID'): ?>
                                <form method="post" action="customer-dashboard.php" class="form-stack" style="margin-top:16px">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_return">
                                    <input type="hidden" name="booking_id" value="<?= (int) $active['booking_id'] ?>">
                                    <div class="field">
                                        <label for="return-time">Return Date &amp; Time <span class="required" aria-hidden="true">*</span></label>
                                        <input class="control" id="return-time" name="return_at" type="datetime-local" min="<?= e($returnMin) ?>" required>
                                        <p class="field-hint">Choose when you want the vehicle back.</p>
                                    </div>
                                    <div class="field">
                                        <label for="return-address">Return Destination <span class="required" aria-hidden="true">*</span></label>
                                        <input class="control" id="return-address" name="return_address" type="text" value="<?= e($returnAddress) ?>" required>
                                    </div>
                                    <div class="map-picker" data-map-picker data-lat="return-lat" data-lng="return-lng" data-address="return-address" data-default-lat="<?= e((string) MAP_DEFAULT_LAT) ?>" data-default-lng="<?= e((string) MAP_DEFAULT_LNG) ?>" data-zoom="<?= (int) MAP_DEFAULT_ZOOM ?>">
                                        <div class="map-toolbar">
                                            <button class="button button-secondary" type="button" data-map-search><span class="material-symbols-outlined" aria-hidden="true">travel_explore</span>Search address</button>
                                            <button class="button button-secondary" type="button" data-map-locate><span class="material-symbols-outlined" aria-hidden="true">my_location</span>Use my location</button>
                                            <p class="map-status" data-map-status>Adjust the return pin or search for a different destination.</p>
                                        </div>
                                        <div class="map-canvas" data-map-canvas></div>
                                    </div>
                                    <input type="hidden" id="return-lat" name="return_lat" value="<?= e((string) $returnLat) ?>">
                                    <input type="hidden" id="return-lng" name="return_lng" value="<?= e((string) $returnLng) ?>">
                                    <div class="button-row"><button class="button" type="submit"><span class="material-symbols-outlined" aria-hidden="true">assignment_return</span>Request Return</button></div>
                                </form>
                            <?php elseif (in_array($active['booking_status'], ['RETURN_REQUESTED', 'RETURNING', 'RETURN_ARRIVED'], true)): ?>
                                <p class="muted-copy" style="margin-top:16px">
                                    Return status: <strong><?= e(status_label($active['return_status'] ?? $active['booking_status'])) ?></strong>
                                    <?= $active['return_date'] ? ' · ' . e(format_datetime($active['return_date'], $active['return_time'])) : '' ?>
                                    <?= $active['return_address'] ? ' · ' . e($active['return_address']) : '' ?>
                                </p>
                            <?php endif; ?>

                            <?php if (in_array($active['booking_status'], ['SEARCHING', 'ASSIGNED', 'ARRIVED'], true)): ?>
                                <form method="post" action="customer-dashboard.php" class="button-row" style="margin-top:16px" data-confirm="Cancel this booking?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="booking_id" value="<?= (int) $active['booking_id'] ?>">
                                    <button class="button button-secondary button-danger" type="submit">Cancel Booking</button>
                                </form>
                            <?php endif; ?>
                        </section>
                    </div>

                    <aside class="panel-stack">
                        <section class="card">
                            <span class="eyebrow">Custody unit</span>
                            <h2 class="card-title" style="margin-top:5px"><?= e($active['vehicle_type']) ?></h2>
                            <p class="muted-copy"><?= e(($active['vehicle_color'] ?: 'Colour not set') . ' · ' . $active['vehicle_number']) ?></p>
                            <div class="custody-details" style="margin-top:15px">
                                <div class="detail-tile"><small>Parking bay</small><strong><?= e($active['parking_slot'] ?: 'Awaiting parking') ?></strong></div>
                                <div class="detail-tile"><small>Fuel service</small><strong><?= (int) $active['fuel_service'] === 1 ? 'Requested' : 'Not requested' ?></strong></div>
                            </div>
                        </section>

                        <section class="card">
                            <div class="card-heading"><h2>Assigned Valet</h2></div>
                            <?php if ($active['valet_first']): ?>
                                <p class="muted-copy"><strong><?= e(full_name($active['valet_first'], $active['valet_last'])) ?></strong><br><?= e($active['valet_phone']) ?></p>
                            <?php elseif ($active['return_valet_first']): ?>
                                <p class="muted-copy"><strong><?= e(full_name($active['return_valet_first'], $active['return_valet_last'])) ?></strong><br><?= e($active['return_valet_phone']) ?></p>
                            <?php else: ?>
                                <p class="muted-copy">We are still matching a valet. This page updates as soon as one accepts.</p>
                            <?php endif; ?>
                        </section>

                        <section class="card fare-card">
                            <div class="card-heading"><div><span class="eyebrow">Settlement</span><h2>Fare Summary</h2></div></div>
                            <div class="fare-list">
                                <div class="fare-row"><span>Valet service fee</span><strong><?= e(money($active['price'])) ?></strong></div>
                                <div class="fare-row"><span>Fuel refill <small>Pay later</small></span><strong><?= (int) $active['fuel_service'] === 1 ? e(money(FUEL_FARE)) : 'Not requested' ?></strong></div>
                            </div>
                            <div class="fare-total"><div><strong>Total</strong></div><strong><?= e(money(booking_total($active))) ?></strong></div>
                        </section>
                    </aside>
                </div>
            <?php endif; ?>
        </section>

        <section class="tab-panel panel-stack" id="panel-history" role="tabpanel" aria-labelledby="desktop-tab-history" data-panel="history" <?= $tab === 'history' ? '' : 'hidden' ?>>
            <section class="card history-card">
                <div class="section-header">
                    <div><h1>Booking History &amp; Audit Logs</h1><p class="muted-copy">Every valet booking, handover and invoice in one place.</p></div>
                    <span class="status-pill"><?= count($bookings) ?> booking<?= count($bookings) === 1 ? '' : 's' ?></span>
                </div>
                <div class="table-wrap">
                    <table class="history-table">
                        <caption class="sr-only">Your valet booking records</caption>
                        <thead>
                            <tr><th>Booking</th><th>Vehicle</th><th>Pickup &amp; Return</th><th>Date</th><th>Valet Fee</th><th>Fuel</th><th>Total</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$bookings): ?>
                                <tr><td colspan="8" class="table-empty">No bookings yet. Your first reservation will appear here.</td></tr>
                            <?php else: ?>
                                <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td class="numeric"><strong><?= e(booking_ref($booking['booking_id'])) ?></strong><small><?= e(format_ts($booking['created_at'])) ?></small></td>
                                        <td><strong><?= e($booking['vehicle_type']) ?></strong><small><?= e($booking['vehicle_number']) ?></small></td>
                                        <td><strong><?= e($booking['pickup_address']) ?></strong><small><?= e(parking_slot_label($booking['parking_slot']) ?: 'No bay yet') ?></small></td>
                                        <td class="numeric"><?= e(format_date($booking['pickup_date'])) ?></td>
                                        <td class="numeric"><?= e(money($booking['price'])) ?></td>
                                        <td class="numeric"><?= (int) $booking['fuel_service'] === 1 ? e(money(FUEL_FARE)) : '—' ?></td>
                                        <td class="numeric"><strong><?= e(money(booking_total($booking))) ?></strong></td>
                                        <td>
                                            <span class="status-pill tone-<?= e(status_tone($booking['booking_status'])) ?>"><?= e(status_label($booking['booking_status'])) ?></span>
                                            <?php if (in_array($booking['booking_status'], ACTIVE_BOOKING_STATUSES, true)): ?>
                                                <div class="table-actions"><button type="button" data-open-tab="active">Track booking</button></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr class="history-detail-row">
                                        <td colspan="8">
                                            <details class="history-details">
                                                <summary>Inspection records<?= $booking['rating_stars'] ? ' · Rated ' . (int) $booking['rating_stars'] . '/5' : '' ?></summary>
                                                <?= inspection_summary($inspectionsFor((int) $booking['booking_id'])) ?>
                                                <?php if (!empty($booking['rating_comment'])): ?>
                                                    <p class="muted-copy" style="margin-top:10px"><strong>Your feedback:</strong> <?= e($booking['rating_comment']) ?></p>
                                                <?php endif; ?>
                                            </details>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if ($completed): ?>
                <section class="card">
                    <div class="card-heading"><h2>Rate a Completed Journey</h2><span class="eyebrow">Optional feedback</span></div>
                    <p class="muted-copy">Rate the valet who handled your return. You can update your feedback later.</p>
                    <form method="post" action="customer-dashboard.php" class="form-stack" style="margin-top:14px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="rate">
                        <div class="date-grid">
                            <div class="field">
                                <label for="rating-booking">Booking</label>
                                <select class="control" id="rating-booking" name="booking_id" required>
                                    <?php foreach ($completed as $booking): ?>
                                        <option value="<?= (int) $booking['booking_id'] ?>"><?= e(booking_ref($booking['booking_id']) . ' · ' . $booking['vehicle_type'] . ($booking['rating_stars'] ? ' · Rated ' . (int) $booking['rating_stars'] . '/5' : '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="rating-stars">Rating</label>
                                <select class="control" id="rating-stars" name="stars" required>
                                    <?php foreach ([5, 4, 3, 2, 1] as $stars): ?>
                                        <option value="<?= $stars ?>"><?= $stars ?> star<?= $stars === 1 ? '' : 's' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="field">
                            <label for="rating-comment">Feedback</label>
                            <textarea class="control" id="rating-comment" name="comment" rows="3" maxlength="1000" placeholder="What went well or what could be better?"></textarea>
                        </div>
                        <div class="button-row"><button class="button" type="submit">Save Feedback</button></div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($bookings): ?>
                <section class="card">
                    <div class="card-heading"><h2>Report an Issue</h2><span class="eyebrow">Admin reviewed</span></div>
                    <p class="muted-copy">Tell the admin team about a handover, payment or parking problem. Complaints are tied to a real booking.</p>
                    <form method="post" action="customer-dashboard.php" class="form-stack" style="margin-top:14px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="complaint">
                        <div class="date-grid">
                            <div class="field">
                                <label for="complaint-booking">Booking</label>
                                <select class="control" id="complaint-booking" name="booking_id" required>
                                    <?php foreach ($bookings as $booking): ?>
                                        <option value="<?= (int) $booking['booking_id'] ?>"><?= e(booking_ref($booking['booking_id']) . ' · ' . $booking['vehicle_type']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="complaint-message">What happened?</label>
                                <input class="control" id="complaint-message" name="message" type="text" placeholder="Describe the issue" required>
                            </div>
                        </div>
                        <div class="button-row"><button class="button button-secondary" type="submit">Submit Complaint</button></div>
                    </form>
                </section>
            <?php endif; ?>
        </section>

        <section class="tab-panel panel-stack" id="panel-vehicles" role="tabpanel" aria-labelledby="desktop-tab-vehicles" data-panel="vehicles" <?= $tab === 'vehicles' ? '' : 'hidden' ?>>
            <section class="card">
                <div class="section-header">
                    <div><h1>Authorized Fleet</h1><p class="muted-copy">Vehicles saved for faster curbside intake.</p></div>
                    <button class="button" type="button" id="focus-add-vehicle"><span class="material-symbols-outlined" aria-hidden="true">add</span>Add Vehicle</button>
                </div>
                <div class="fleet-grid" id="fleet-grid">
                    <?php if (!$vehicles): ?>
                        <p class="muted-copy">No vehicles yet. Add your first car below.</p>
                    <?php else: ?>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <article class="vehicle-card">
                                <div class="vehicle-heading">
                                    <div><h3><?= e($vehicle['vehicle_type']) ?></h3><p><?= e(($vehicle['vehicle_color'] ?: 'Colour not set') . ' · ' . ($vehicle['fuel_level'] ?: 'Fuel not set')) ?></p></div>
                                    <span class="status-pill">Saved</span>
                                </div>
                                <div class="vehicle-foot"><span>Plate: <strong><?= e($vehicle['vehicle_number']) ?></strong></span><span><?= $vehicle['odometer'] ? e(number_format((int) $vehicle['odometer'])) . ' km' : 'Odometer not set' ?></span></div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card add-vehicle-card" id="form-add-fleet">
                <div class="card-heading"><h2><span class="material-symbols-outlined" aria-hidden="true">add_circle</span> Register Another Vehicle</h2></div>
                <p class="muted-copy" style="margin-bottom:14px">Saved vehicles appear in the booking form immediately.</p>
                <form id="fleet-form" method="post" action="customer-dashboard.php" class="inline-add-grid" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_vehicle">
                    <div class="field"><label for="fleet-make">Make &amp; Model <span class="required" aria-hidden="true">*</span></label><input class="control" id="fleet-make" name="vehicle_type" type="text" placeholder="e.g. Audi A6 Matrix" required></div>
                    <div class="field"><label for="fleet-color">Color <span class="required" aria-hidden="true">*</span></label><input class="control" id="fleet-color" name="vehicle_color" type="text" placeholder="e.g. Ibis White" required></div>
                    <div class="field"><label for="fleet-reg">Registration <span class="required" aria-hidden="true">*</span></label><input class="control" id="fleet-reg" name="vehicle_number" type="text" placeholder="e.g. MH-02-ZZ-7788" required></div>
                    <div class="field"><label for="fleet-fuel">Fuel Level</label><input class="control" id="fleet-fuel" name="fuel_level" type="text" placeholder="e.g. Full"></div>
                    <div class="field"><label for="fleet-odometer">Odometer (km)</label><input class="control" id="fleet-odometer" name="odometer" type="number" min="0" step="1" placeholder="e.g. 24000"></div>
                    <div class="button-row" style="grid-column:1/-1"><button class="button" type="submit">Add Vehicle</button></div>
                </form>
            </section>
        </section>

        <section class="tab-panel panel-stack" id="panel-profile" role="tabpanel" aria-labelledby="desktop-tab-profile" data-panel="profile" <?= $tab === 'profile' ? '' : 'hidden' ?>>
            <div class="profile-layout">
                <section class="card profile-summary">
                    <div class="avatar" aria-hidden="true"><?= e(initials($user['name'])) ?></div>
                    <div><h1><?= e($user['name']) ?></h1><p><?= e($user['username']) ?></p></div>
                    <div class="account-badge"><span class="material-symbols-outlined" aria-hidden="true">verified_user</span>Active Customer</div>
                    <div class="profile-meta">
                        <div class="profile-meta-row"><span>Customer ID</span><strong>VF-CUST-<?= str_pad((string) $customerId, 4, '0', STR_PAD_LEFT) ?></strong></div>
                        <div class="profile-meta-row"><span>Registered Vehicles</span><strong><?= count($vehicles) ?></strong></div>
                        <div class="profile-meta-row"><span>Completed Journeys</span><strong><?= count($completed) ?></strong></div>
                    </div>
                </section>

                <section class="card">
                    <div class="section-header">
                        <div><h2>Account &amp; Security Settings</h2><p class="muted-copy">These details are used for curbside dispatch and digital pass delivery.</p></div>
                    </div>
                    <form id="profile-form" method="post" action="customer-dashboard.php" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="profile-fields">
                            <div class="field"><label for="profile-name">Full Name</label><input class="control" id="profile-name" name="full_name" type="text" value="<?= e($user['name']) ?>" required></div>
                            <div class="field"><label for="profile-phone">Primary Phone</label><input class="control" id="profile-phone" name="phone" type="tel" value="<?= e($customer['phone']) ?>" required></div>
                            <div class="field"><label for="profile-email">Email Address</label><input class="control" id="profile-email" name="email" type="email" value="<?= e($user['username']) ?>" required></div>
                            <div class="field"><label for="profile-address">Address</label><input class="control" id="profile-address" name="address" type="text" value="<?= e($customer['address'] ?? '') ?>" placeholder="Residential address, city, PIN code"></div>
                        </div>
                        <div class="profile-save-row"><button class="button" type="submit">Save Profile</button></div>
                    </form>
                </section>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="page-width footer-inner">
            <div class="inline-icon"><span class="footer-brand"><span class="brand-mark footer-mark" aria-hidden="true">V</span>ValetFlow</span><span>·</span><span>&copy; <?= e($currentYear) ?> ValetFlow Mobility Infrastructure Ltd. All rights reserved.</span></div>
            <span class="eyebrow">Customer Portal</span>
        </div>
    </footer>

    <div class="notice" id="global-notice" role="status" aria-live="polite" hidden></div>

    <script src="assets/js/core.js" defer></script>
    <script src="assets/vendor/leaflet/leaflet.js" defer></script>
    <script src="assets/js/maps.js" defer></script>
    <script src="assets/js/customer.js" defer></script>
</body>
</html>
