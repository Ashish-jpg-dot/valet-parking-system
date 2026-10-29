<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/bookings.php';
require_once __DIR__ . '/../app/admin.php';

$user = require_role('admin');

$tabs = [
    'overview' => 'Overview',
    'valet-registrations' => 'Valet Registrations',
    'customers' => 'Customers',
    'bookings' => 'Bookings',
    'job-status' => 'Valet Job Status',
    'cancellations' => 'Cancellations',
    'payments' => 'Payments & Fuel Charges',
    'complaints' => 'Complaints',
    'accounts' => 'Manage Accounts',
];
$tab = $_GET['tab'] ?? 'overview';
if (!array_key_exists($tab, $tabs)) {
    $tab = 'overview';
}

if (is_post()) {
    verify_csrf();
    $action = post_string('action');
    $error = null;

    if ($action === 'valet_status') {
        $status = post_int('status');
        if (!in_array($status, [0, 1], true)) {
            $error = 'Unknown account status.';
        } else {
            $error = admin_set_valet_status(post_int('valet_id'), $status);
            if ($error === null) {
                flash('success', $status === 1 ? 'Valet partner approved and activated.' : 'Valet application rejected.');
            }
        }
    } elseif ($action === 'resolve_complaint') {
        $error = admin_resolve_complaint(post_int('complaint_id'));
        if ($error === null) {
            flash('success', 'Complaint marked as resolved.');
        }
    } elseif ($action === 'withdrawal_status') {
        $status = post_string('status');
        if (!in_array($status, ['PAID', 'REJECTED'], true)) {
            $error = 'Unknown withdrawal status.';
        } else {
            $error = admin_set_withdrawal_status(post_int('withdrawal_id'), $status);
            if ($error === null) {
                flash('success', $status === 'PAID' ? 'Withdrawal marked as paid.' : 'Withdrawal rejected.');
            }
        }
    } elseif ($action === 'account_status') {
        $loginId = post_int('login_id');
        $status = post_int('status');
        $target = admin_account($loginId);
        if (!$target) {
            $error = 'Account not found.';
        } elseif ($loginId === $user['login_id']) {
            $error = 'You cannot change the status of your own account.';
        } elseif ((int) $target['is_admin'] === 1) {
            $error = 'Administrator accounts cannot be changed here.';
        } elseif (!in_array($status, [0, 1], true)) {
            $error = 'Unknown account status.';
        } else {
            $error = admin_set_account_status($loginId, $status);
            if ($error === null) {
                flash('success', $status === 1 ? 'Account activated.' : 'Account suspended.');
            }
        }
    }

    if ($error) {
        flash('error', $error);
    }
    redirect('admin-dashboard.php?tab=' . $tab);
}

$flashes = take_flashes();
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($statusFilter, ['', 'SEARCHING', 'ASSIGNED', 'ARRIVED', 'COLLECTED', 'PARKED', 'PAID', 'RETURN_REQUESTED', 'RETURNING', 'RETURN_ARRIVED', 'COMPLETED', 'CANCELLED'], true)) {
    $statusFilter = '';
}

$kpis = admin_kpis();
$pendingValets = admin_pending_valets();
$valets = admin_valets();
$customers = admin_customers();
$allBookings = admin_bookings();
$bookings = admin_bookings($search, $statusFilter);
$parkingJobs = admin_parking_jobs();
$returnJobs = admin_return_jobs();
$cancellations = admin_cancellations();
$payments = admin_payments();
$complaints = admin_complaints();
$accounts = admin_accounts();
$withdrawals = admin_withdrawals();

$recentBookings = array_slice($allBookings, 0, 6);
$activeBookings = array_values(array_filter(
    $allBookings,
    static fn ($booking) => !in_array($booking['booking_status'], ['COMPLETED', 'CANCELLED'], true)
));
$openComplaints = array_values(array_filter($complaints, static fn ($complaint) => $complaint['status'] === 'OPEN'));
$pendingWithdrawals = array_values(array_filter($withdrawals, static fn ($withdrawal) => $withdrawal['status'] === 'PENDING'));

$navItems = [
    'overview' => ['Overview', 'dashboard', 0],
    'valet-registrations' => ['Valet Registrations', 'verified_user', $kpis['pending_valets']],
    'customers' => ['Customers', 'group', 0],
    'bookings' => ['Bookings', 'calendar_today', 0],
    'job-status' => ['Valet Job Status', 'alt_route', 0],
    'cancellations' => ['Cancellations', 'cancel', 0],
    'payments' => ['Payments & Fuel', 'payments', count($pendingWithdrawals)],
    'complaints' => ['Complaints', 'report', count($openComplaints)],
    'accounts' => ['Manage Accounts', 'manage_accounts', 0],
];

$pipelineStages = ['Requested', 'Accepted', 'Collected', 'Parked', 'Returning', 'Completed'];
$pipelineMap = [
    'SEARCHING' => 0,
    'ASSIGNED' => 1,
    'ARRIVED' => 1,
    'COLLECTED' => 2,
    'PARKED' => 3,
    'PAID' => 3,
    'RETURN_REQUESTED' => 3,
    'RETURNING' => 4,
    'RETURN_ARRIVED' => 4,
    'COMPLETED' => 5,
];

$accountState = static function (array $account): array {
    if ($account['status'] === null) {
        return ['Pending review', 'warning'];
    }
    return (int) $account['status'] === 1 ? ['Active', 'success'] : ['Suspended', 'danger'];
};

$pageTitle = $tabs[$tab];
$currentYear = date('Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcf9f8">
    <meta name="description" content="ValetFlow administration console for valets, bookings, payments and support.">
    <title><?= e($pageTitle) ?> — ValetFlow Admin</title>
    <link rel="stylesheet" href="assets/css/tokens.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/css/pages/admin.css">
</head>
<body class="admin-body">
    <a class="skip-link" href="#main-content">Skip to admin content</a>

    <div class="admin-shell">
        <aside class="admin-sidebar">
            <a class="admin-brand" href="admin-dashboard.php" aria-label="ValetFlow admin home">
                <span class="admin-brand-mark" aria-hidden="true">V</span>
                <span class="admin-brand-copy"><strong>ValetFlow</strong><small>Admin Portal</small></span>
            </a>

            <span class="admin-console-label">Operations console</span>

            <nav class="admin-nav" aria-label="Admin sections">
                <?php foreach ($navItems as $key => [$label, $icon, $badge]): ?>
                    <a class="admin-nav-link <?= $tab === $key ? 'is-active' : '' ?>" href="admin-dashboard.php?tab=<?= e($key) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>>
                        <span class="material-symbols-outlined" aria-hidden="true"><?= e($icon) ?></span>
                        <span><?= e($label) ?></span>
                        <?php if ($badge > 0): ?><span class="admin-nav-badge"><?= (int) $badge ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="admin-sidebar-footer">
                <div class="admin-system"><span class="status-dot" aria-hidden="true"></span>System healthy</div>
                <div class="admin-user">
                    <span class="admin-user-avatar" aria-hidden="true"><?= e(initials($user['name'])) ?></span>
                    <span class="admin-user-copy"><strong><?= e($user['name']) ?></strong><small><?= e($user['username']) ?></small></span>
                </div>
                <a class="admin-logout" href="logout.php"><span class="material-symbols-outlined" aria-hidden="true">logout</span>Logout</a>
            </div>
        </aside>

        <div class="admin-main">
            <header class="admin-topbar">
                <div>
                    <span class="admin-eyebrow">ValetFlow operations · live</span>
                    <h1 class="admin-title"><?= e($pageTitle) ?></h1>
                </div>
                <div class="admin-top-meta">
                    <span class="admin-date"><?= e(date('D, d M Y')) ?></span>
                    <a class="admin-logout admin-logout-mobile" href="logout.php"><span class="material-symbols-outlined" aria-hidden="true">logout</span>Logout</a>
                </div>
            </header>

            <main class="admin-content" id="main-content">
                <?php if ($flashes): ?>
                    <div class="flash-stack">
                        <?php foreach ($flashes as $flash): ?>
                            <p class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'overview'): ?>
                    <section class="admin-stack" aria-labelledby="overview-kpis">
                        <div class="admin-section-head">
                            <div>
                                <span class="admin-eyebrow">Operational overview</span>
                                <h2 id="overview-kpis">Everything moving right now</h2>
                            </div>
                            <a class="button button-secondary" href="admin-dashboard.php?tab=bookings">Open bookings</a>
                        </div>

                        <div class="admin-kpi-grid">
                            <article class="admin-kpi"><div class="admin-kpi-top"><span>Total customers</span><span class="material-symbols-outlined" aria-hidden="true">group</span></div><strong><?= (int) $kpis['customers'] ?></strong><small>Registered customer accounts</small></article>
                            <article class="admin-kpi"><div class="admin-kpi-top"><span>Valet partners</span><span class="material-symbols-outlined" aria-hidden="true">badge</span></div><strong><?= (int) $kpis['valets'] ?></strong><small><?= (int) $kpis['pending_valets'] ?> awaiting approval</small></article>
                            <article class="admin-kpi"><div class="admin-kpi-top"><span>Active bookings</span><span class="material-symbols-outlined" aria-hidden="true">alt_route</span></div><strong><?= (int) $kpis['active_bookings'] ?></strong><small><?= (int) $kpis['completed_bookings'] ?> completed all-time</small></article>
                            <article class="admin-kpi admin-kpi-dark"><div class="admin-kpi-top"><span>Gross recorded</span><span class="material-symbols-outlined" aria-hidden="true">payments</span></div><strong><?= e(money($kpis['revenue'])) ?></strong><small><?= e(money($kpis['revenue_today'])) ?> settled today</small></article>
                            <article class="admin-kpi"><div class="admin-kpi-top"><span>Pending approvals</span><span class="material-symbols-outlined" aria-hidden="true">verified_user</span></div><strong><?= (int) $kpis['pending_valets'] ?></strong><small>Commercial licence checks</small></article>
                            <article class="admin-kpi"><div class="admin-kpi-top"><span>Open complaints</span><span class="material-symbols-outlined" aria-hidden="true">report</span></div><strong><?= (int) $kpis['open_complaints'] ?></strong><small>Customer incidents to review</small></article>
                        </div>

                        <div class="admin-grid-2">
                            <section class="admin-card">
                                <div class="admin-card-head">
                                    <div><h2>Recent bookings</h2><p>Newest reservations across every status.</p></div>
                                    <a class="text-link" href="admin-dashboard.php?tab=bookings">View all</a>
                                </div>
                                <div class="admin-table-wrap">
                                    <table class="admin-table">
                                        <thead><tr><th>Booking</th><th>Customer</th><th>Vehicle</th><th>Total</th><th>Status</th></tr></thead>
                                        <tbody>
                                            <?php if (!$recentBookings): ?>
                                                <tr><td colspan="5" class="admin-empty">No bookings yet.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($recentBookings as $booking): ?>
                                                    <tr>
                                                        <td><strong><?= e(booking_ref($booking['booking_id'])) ?></strong><small><?= e(format_date($booking['created_at'])) ?></small></td>
                                                        <td><?= e(full_name($booking['first_name'], $booking['last_name'])) ?></td>
                                                        <td><?= e($booking['vehicle_type']) ?><small><?= e($booking['vehicle_number']) ?></small></td>
                                                        <td class="admin-num"><?= e(money(booking_total($booking))) ?></td>
                                                        <td><span class="status-pill tone-<?= e(status_tone($booking['booking_status'])) ?>"><?= e(status_label($booking['booking_status'])) ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section class="admin-card">
                                <div class="admin-card-head"><div><h2>Action queue</h2><p>Items waiting on an admin decision.</p></div></div>
                                <div class="action-list">
                                    <a class="action-item" href="admin-dashboard.php?tab=valet-registrations">
                                        <span class="action-icon"><span class="material-symbols-outlined" aria-hidden="true">verified_user</span></span>
                                        <span class="action-copy"><strong><?= (int) $kpis['pending_valets'] ?> valet applications</strong><small>Verify licence documents</small></span>
                                        <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                                    </a>
                                    <a class="action-item" href="admin-dashboard.php?tab=complaints">
                                        <span class="action-icon"><span class="material-symbols-outlined" aria-hidden="true">report</span></span>
                                        <span class="action-copy"><strong><?= count($openComplaints) ?> open complaints</strong><small>Review and resolve incidents</small></span>
                                        <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                                    </a>
                                    <a class="action-item" href="admin-dashboard.php?tab=payments">
                                        <span class="action-icon"><span class="material-symbols-outlined" aria-hidden="true">account_balance</span></span>
                                        <span class="action-copy"><strong><?= count($pendingWithdrawals) ?> withdrawal requests</strong><small>Payouts waiting for settlement</small></span>
                                        <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                                    </a>
                                    <a class="action-item" href="admin-dashboard.php?tab=job-status">
                                        <span class="action-icon"><span class="material-symbols-outlined" aria-hidden="true">alt_route</span></span>
                                        <span class="action-copy"><strong><?= count($activeBookings) ?> live job pipelines</strong><small>Track custody stage by stage</small></span>
                                        <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                                    </a>
                                </div>
                            </section>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($tab === 'valet-registrations'): ?>
                    <section class="admin-stack" aria-labelledby="registrations-title">
                        <div class="admin-section-head">
                            <div><span class="admin-eyebrow">Partner onboarding</span><h2 id="registrations-title">Applications awaiting review</h2></div>
                            <span class="status-pill tone-warning"><?= count($pendingValets) ?> pending</span>
                        </div>

                        <?php if (!$pendingValets): ?>
                            <div class="admin-card empty-state"><span class="material-symbols-outlined" aria-hidden="true">task_alt</span><h2>No pending applications</h2><p>Every valet partner has been reviewed.</p></div>
                        <?php else: ?>
                            <div class="approval-grid">
                                <?php foreach ($pendingValets as $valet): ?>
                                    <article class="admin-card approval-card">
                                        <div class="approval-head">
                                            <span class="admin-avatar" aria-hidden="true"><?= e(initials(full_name($valet['first_name'], $valet['last_name']))) ?></span>
                                            <div><h2><?= e(full_name($valet['first_name'], $valet['last_name'])) ?></h2><p>Applied <?= e(format_date($valet['created_at'])) ?> · <?= e($valet['username']) ?></p></div>
                                        </div>
                                        <div class="approval-details">
                                            <div><span>Phone</span><strong><?= e($valet['phone']) ?></strong></div>
                                            <div><span>Licence number</span><strong><?= e($valet['license_number']) ?></strong></div>
                                            <div><span>Valet ID</span><strong>VF-VAL-<?= str_pad((string) $valet['valet_id'], 3, '0', STR_PAD_LEFT) ?></strong></div>
                                        </div>
                                        <div class="approval-doc">
                                            <?php if ($valet['license_photo']): ?>
                                                <a class="button button-secondary" href="license.php?valet=<?= (int) $valet['valet_id'] ?>" target="_blank" rel="noopener"><span class="material-symbols-outlined" aria-hidden="true">visibility</span>View licence</a>
                                            <?php else: ?>
                                                <span class="muted-copy">No licence document uploaded.</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="approval-actions">
                                            <form method="post" action="admin-dashboard.php?tab=valet-registrations" data-confirm="Reject this application?">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="valet_status">
                                                <input type="hidden" name="valet_id" value="<?= (int) $valet['valet_id'] ?>">
                                                <input type="hidden" name="status" value="0">
                                                <button class="button button-danger" type="submit">Reject</button>
                                            </form>
                                            <form method="post" action="admin-dashboard.php?tab=valet-registrations">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="valet_status">
                                                <input type="hidden" name="valet_id" value="<?= (int) $valet['valet_id'] ?>">
                                                <input type="hidden" name="status" value="1">
                                                <button class="button" type="submit"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span>Approve</button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <section class="admin-card">
                            <div class="admin-card-head"><div><h2>All valet partners</h2><p>Approval state, job volume and lifetime earnings.</p></div></div>
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Partner</th><th>Contact</th><th>Licence</th><th>Jobs</th><th>Earned</th><th>Availability</th><th>Status</th><th>Action</th></tr></thead>
                                    <tbody>
                                        <?php if (!$valets): ?>
                                            <tr><td colspan="8" class="admin-empty">No valet partners yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($valets as $valet): ?>
                                                <?php
                                                $state = $valet['login_status'] === null ? ['Pending', 'warning'] : ((int) $valet['login_status'] === 1 ? ['Approved', 'success'] : ['Rejected', 'danger']);
                                                ?>
                                                <tr>
                                                    <td><strong><?= e(full_name($valet['first_name'], $valet['last_name'])) ?></strong><small>VF-VAL-<?= str_pad((string) $valet['valet_id'], 3, '0', STR_PAD_LEFT) ?></small></td>
                                                    <td><?= e($valet['phone']) ?><small><?= e($valet['username']) ?></small></td>
                                                    <td><?= e($valet['license_number']) ?></td>
                                                    <td class="admin-num"><?= (int) $valet['jobs_done'] ?></td>
                                                    <td class="admin-num"><?= e(money($valet['earned'])) ?></td>
                                                    <td><span class="status-pill tone-<?= (int) $valet['is_live'] === 1 ? 'success' : 'neutral' ?>"><?= (int) $valet['is_live'] === 1 ? 'Online' : 'Offline' ?></span></td>
                                                    <td><span class="status-pill tone-<?= e($state[1]) ?>"><?= e($state[0]) ?></span></td>
                                                    <td>
                                                        <?php if ($valet['login_status'] !== null): ?>
                                                            <form class="admin-inline-form" method="post" action="admin-dashboard.php?tab=valet-registrations" data-confirm="Change this valet account status?">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="valet_status">
                                                                <input type="hidden" name="valet_id" value="<?= (int) $valet['valet_id'] ?>">
                                                                <input type="hidden" name="status" value="<?= (int) $valet['login_status'] === 1 ? '0' : '1' ?>">
                                                                <button class="text-link" type="submit"><?= (int) $valet['login_status'] === 1 ? 'Suspend' : 'Reactivate' ?></button>
                                                            </form>
                                                        <?php else: ?>
                                                            <a class="text-link" href="#registrations-title">Review above</a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </section>
                <?php endif; ?>

                <?php if ($tab === 'customers'): ?>
                    <section class="admin-stack" aria-labelledby="customers-title">
                        <div class="admin-section-head">
                            <div><span class="admin-eyebrow">Customer accounts</span><h2 id="customers-title">Registered customers</h2></div>
                            <span class="status-pill"><?= count($customers) ?> accounts</span>
                        </div>
                        <section class="admin-card">
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Customer</th><th>Email</th><th>Phone</th><th>Fleet</th><th>Bookings</th><th>Spent</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <?php if (!$customers): ?>
                                            <tr><td colspan="7" class="admin-empty">No customers registered.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($customers as $customer): ?>
                                                <tr>
                                                    <td><strong><?= e(full_name($customer['first_name'], $customer['last_name'])) ?></strong><small>VF-CUST-<?= str_pad((string) $customer['customer_id'], 4, '0', STR_PAD_LEFT) ?></small></td>
                                                    <td><?= e($customer['username']) ?></td>
                                                    <td><?= e($customer['phone']) ?></td>
                                                    <td class="admin-num"><?= (int) $customer['vehicles'] ?></td>
                                                    <td class="admin-num"><?= (int) $customer['bookings'] ?></td>
                                                    <td class="admin-num"><?= e(money($customer['spent'])) ?></td>
                                                    <td><span class="status-pill tone-<?= (int) $customer['login_status'] === 1 ? 'success' : 'danger' ?>"><?= (int) $customer['login_status'] === 1 ? 'Active' : 'Suspended' ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </section>
                <?php endif; ?>

                <?php if ($tab === 'bookings'): ?>
                    <section class="admin-stack" aria-labelledby="bookings-title">
                        <div class="admin-section-head">
                            <div><span class="admin-eyebrow">Dispatch ledger</span><h2 id="bookings-title">All reservations</h2></div>
                            <span class="status-pill"><?= count($bookings) ?> shown</span>
                        </div>

                        <form class="filter-bar" method="get" action="admin-dashboard.php">
                            <input type="hidden" name="tab" value="bookings">
                            <div class="filter-field">
                                <label class="sr-only" for="booking-search">Search bookings</label>
                                <span class="material-symbols-outlined" aria-hidden="true">search</span>
                                <input id="booking-search" name="q" type="search" placeholder="Vehicle, customer or pickup point" value="<?= e($search) ?>">
                            </div>
                            <div class="filter-field">
                                <label class="sr-only" for="booking-status">Status</label>
                                <select id="booking-status" name="status">
                                    <option value="">All statuses</option>
                                    <?php foreach (['SEARCHING', 'ASSIGNED', 'ARRIVED', 'COLLECTED', 'PARKED', 'PAID', 'RETURN_REQUESTED', 'RETURNING', 'RETURN_ARRIVED', 'COMPLETED', 'CANCELLED'] as $status): ?>
                                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button class="button" type="submit">Filter</button>
                            <?php if ($search !== '' || $statusFilter !== ''): ?><a class="text-link" href="admin-dashboard.php?tab=bookings">Clear</a><?php endif; ?>
                        </form>

                        <section class="admin-card">
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Booking</th><th>Customer</th><th>Vehicle</th><th>Pickup</th><th>Schedule</th><th>Valet</th><th>Total</th><th>Status</th><th>Records</th></tr></thead>
                                    <tbody>
                                        <?php if (!$bookings): ?>
                                            <tr><td colspan="9" class="admin-empty">No bookings match those filters.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($bookings as $booking): ?>
                                                <tr>
                                                    <td><strong><?= e(booking_ref($booking['booking_id'])) ?></strong><small><?= e(format_date($booking['created_at'])) ?></small></td>
                                                    <td><?= e(full_name($booking['first_name'], $booking['last_name'])) ?><small><?= e($booking['customer_phone']) ?></small></td>
                                                    <td><?= e($booking['vehicle_type']) ?><small><?= e($booking['vehicle_number']) ?></small></td>
                                                    <td><?= e($booking['pickup_address']) ?><small><?= e(parking_slot_label($booking['parking_slot']) ?: 'No bay assigned') ?></small></td>
                                                    <td><?= e(format_datetime($booking['pickup_date'], $booking['pickup_time'])) ?><small>Return <?= $booking['return_date'] ? e(format_datetime($booking['return_date'], $booking['return_time'])) : 'not requested' ?></small></td>
                                                    <td><?= $booking['valet_first'] ? e(full_name($booking['valet_first'], $booking['valet_last'])) : 'Unassigned' ?></td>
                                                    <td class="admin-num"><?= e(money(booking_total($booking))) ?></td>
                                                    <td><span class="status-pill tone-<?= e(status_tone($booking['booking_status'])) ?>"><?= e(status_label($booking['booking_status'])) ?></span></td>
                                                    <td><button class="text-link" type="button" data-dialog-open="booking-dialog-<?= (int) $booking['booking_id'] ?>">View</button></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php foreach ($bookings as $booking): ?>
                                <?php $inspections = booking_inspections((int) $booking['booking_id']); ?>
                                <dialog class="admin-dialog" id="booking-dialog-<?= (int) $booking['booking_id'] ?>" aria-labelledby="booking-dialog-title-<?= (int) $booking['booking_id'] ?>">
                                    <div class="admin-dialog-head">
                                        <div>
                                            <span class="admin-eyebrow"><?= e(booking_ref($booking['booking_id'])) ?> · <?= e(status_label($booking['booking_status'])) ?></span>
                                            <h2 id="booking-dialog-title-<?= (int) $booking['booking_id'] ?>"><?= e($booking['vehicle_type'] . ' · ' . $booking['vehicle_number']) ?></h2>
                                        </div>
                                        <button class="admin-dialog-close" type="button" data-dialog-close aria-label="Close dialog"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
                                    </div>
                                    <div class="admin-dialog-body">
                                        <div class="admin-dialog-grid">
                                            <section>
                                                <h3>Locations</h3>
                                                <div class="map-viewer" data-map-view data-pickup-lat="<?= e((string) $booking['pickup_latitude']) ?>" data-pickup-lng="<?= e((string) $booking['pickup_longitude']) ?>" data-pickup-label="Pickup: <?= e($booking['pickup_address']) ?>" data-return-lat="<?= e((string) $booking['return_latitude']) ?>" data-return-lng="<?= e((string) $booking['return_longitude']) ?>" data-return-label="Return: <?= e($booking['return_address'] ?: 'Not requested yet') ?>">
                                                    <div class="map-canvas" data-map-canvas></div>
                                                    <p class="map-empty" data-map-empty hidden>Location pins were not captured for this booking.</p>
                                                </div>
                                                <div class="admin-dialog-facts">
                                                    <div><span>Pickup</span><strong><?= e($booking['pickup_address']) ?></strong><small><?= e(format_datetime($booking['pickup_date'], $booking['pickup_time'])) ?></small></div>
                                                    <div><span>Return</span><strong><?= e($booking['return_address'] ?: 'Not requested yet') ?></strong><small><?= $booking['return_date'] ? e(format_datetime($booking['return_date'], $booking['return_time'])) : 'At the customer’s request' ?></small></div>
                                                    <div><span>Parking</span><strong><?= e($booking['parking_slot'] ?: 'Not parked') ?></strong><small><?= e($booking['parking_address'] ?: 'No parking address recorded') ?></small></div>
                                                </div>
                                            </section>
                                            <section>
                                                <h3>Handover inspections</h3>
                                                <?= inspection_summary($inspections) ?>
                                            </section>
                                        </div>
                                        <div class="admin-dialog-grid">
                                            <section>
                                                <h3>Payment</h3>
                                                <div class="admin-dialog-facts">
                                                    <div><span>Service fee</span><strong><?= e(money($booking['price'])) ?></strong></div>
                                                    <div><span>Fuel</span><strong><?= (int) $booking['fuel_service'] === 1 ? e(money(FUEL_FARE) . ' · pay later') : 'Not requested' ?></strong></div>
                                                    <div><span>Payment</span><strong><?= $booking['paid_amount'] !== null ? e('Paid ' . money($booking['paid_amount'])) : 'Not paid' ?></strong></div>
                                                </div>
                                            </section>
                                            <section>
                                                <h3>Customer feedback</h3>
                                                <?php if ($booking['rating_stars']): ?>
                                                    <p><strong><?= (int) $booking['rating_stars'] ?>/5 stars</strong></p>
                                                    <p class="muted-copy"><?= e($booking['rating_comment'] ?: 'No written feedback.') ?></p>
                                                <?php else: ?>
                                                    <p class="muted-copy">No rating submitted.</p>
                                                <?php endif; ?>
                                                <p class="muted-copy">Complaints on this booking: <?= (int) ($booking['complaint_count'] ?? 0) ?></p>
                                            </section>
                                        </div>
                                    </div>
                                </dialog>
                            <?php endforeach; ?>
                        </section>
                    </section>
                <?php endif; ?>

                <?php if ($tab === 'job-status'): ?>
                    <section class="admin-stack" aria-labelledby="jobs-title">
                        <div class="admin-section-head">
                            <div><span class="admin-eyebrow">Live custody state machine</span><h2 id="jobs-title">Valet job progression</h2></div>
                            <span class="status-pill tone-success"><?= count($activeBookings) ?> live</span>
                        </div>

                        <?php if (!$activeBookings): ?>
                            <div class="admin-card empty-state"><span class="material-symbols-outlined" aria-hidden="true">garage_home</span><h2>No active jobs</h2><p>New jobs appear here the moment a customer books.</p></div>
                        <?php else: ?>
                            <div class="pipeline-grid">
                                <?php foreach ($activeBookings as $booking): ?>
                                    <?php $step = $pipelineMap[$booking['booking_status']] ?? 0; ?>
                                    <article class="admin-card pipeline-card">
                                        <div class="pipeline-head">
                                            <div><h2><?= e(booking_ref($booking['booking_id'])) ?> · <?= e($booking['vehicle_type']) ?></h2><p><?= e($booking['vehicle_number'] . ' · ' . full_name($booking['first_name'], $booking['last_name'])) ?></p></div>
                                            <span class="status-pill tone-<?= e(status_tone($booking['booking_status'])) ?>"><?= e(status_label($booking['booking_status'])) ?></span>
                                        </div>
                                        <div class="pipeline-steps">
                                            <?php foreach ($pipelineStages as $index => $label): ?>
                                                <div class="pipeline-step <?= $index < $step ? 'is-done' : ($index === $step ? 'is-current' : '') ?>">
                                                    <span class="pipeline-dot" aria-hidden="true"></span>
                                                    <span><?= e($label) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="pipeline-meta">
                                            <span>Valet: <strong><?= $booking['valet_first'] ? e(full_name($booking['valet_first'], $booking['valet_last'])) : 'Unassigned' ?></strong></span>
                                            <span>Slot: <strong><?= e(parking_slot_label($booking['parking_slot']) ?: 'Not parked') ?></strong></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="admin-grid-2 admin-grid-jobs">
                            <section class="admin-card">
                                <div class="admin-card-head"><div><h2>Parking jobs</h2><p>Pickup and parking handovers.</p></div></div>
                                <div class="admin-table-wrap">
                                    <table class="admin-table">
                                        <thead><tr><th>Job</th><th>Vehicle</th><th>Valet</th><th>Status</th><th>Assigned</th></tr></thead>
                                        <tbody>
                                            <?php if (!$parkingJobs): ?>
                                                <tr><td colspan="5" class="admin-empty">No parking jobs yet.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($parkingJobs as $job): ?>
                                                    <tr>
                                                        <td><strong><?= e(booking_ref($job['booking_id'])) ?></strong><small><?= e($job['pickup_address']) ?></small></td>
                                                        <td><?= e($job['vehicle_number']) ?></td>
                                                        <td><?= $job['valet_first'] ? e(full_name($job['valet_first'], $job['valet_last'])) : 'Unassigned' ?></td>
                                                        <td><span class="status-pill tone-<?= $job['status'] === 'COMPLETED' ? 'success' : ($job['status'] === 'CANCELLED' ? 'danger' : 'warning') ?>"><?= e(ucfirst(strtolower($job['status']))) ?></span></td>
                                                        <td><?= e(format_ts($job['assigned_at'])) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section class="admin-card">
                                <div class="admin-card-head"><div><h2>Return jobs</h2><p>Parking-to-customer handovers.</p></div></div>
                                <div class="admin-table-wrap">
                                    <table class="admin-table">
                                        <thead><tr><th>Booking</th><th>Vehicle</th><th>Valet</th><th>Status</th><th>Requested</th></tr></thead>
                                        <tbody>
                                            <?php if (!$returnJobs): ?>
                                                <tr><td colspan="5" class="admin-empty">No return jobs yet.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($returnJobs as $job): ?>
                                                    <tr>
                                                        <td><strong><?= e(booking_ref($job['booking_id'])) ?></strong><small><?= e($job['return_address']) ?></small></td>
                                                        <td><?= e($job['vehicle_number']) ?></td>
                                                        <td><?= $job['valet_first'] ? e(full_name($job['valet_first'], $job['valet_last'])) : 'Unassigned' ?></td>
                                                        <td><span class="status-pill tone-<?= $job['status'] === 'COMPLETED' ? 'success' : 'warning' ?>"><?= e(ucfirst(strtolower($job['status']))) ?></span></td>
                                                        <td><?= e(format_ts($job['requested_at'])) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($tab === 'cancellations'): ?>
                    <section class="admin-stack" aria-labelledby="cancellations-title">
                        <div class="admin-section-head">
                            <div><span class="admin-eyebrow">Cancellation queue</span><h2 id="cancellations-title">Cancelled bookings</h2></div>
                            <span class="status-pill tone-danger"><?= count($cancellations) ?> cancelled</span>
                        </div>
                        <section class="admin-card">
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Booking</th><th>Customer</th><th>Vehicle</th><th>Pickup</th><th>Intended schedule</th><th>Cancelled</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <?php if (!$cancellations): ?>
                                            <tr><td colspan="7" class="admin-empty">No cancellations recorded.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($cancellations as $booking): ?>
                                                <tr>
                                                    <td><strong><?= e(booking_ref($booking['booking_id'])) ?></strong></td>
                                                    <td><?= e(full_name($booking['first_name'], $booking['last_name'])) ?><small><?= e($booking['customer_phone']) ?></small></td>
                                                    <td><?= e($booking['vehicle_type']) ?><small><?= e($booking['vehicle_number']) ?></small></td>
                                                    <td><?= e($booking['pickup_address']) ?></td>
                                                    <td><?= e(format_datetime($booking['pickup_date'], $booking['pickup_time'])) ?></td>
                                                    <td><?= e(format_ts($booking['updated_at'])) ?></td>
                                                    <td><span class="status-pill tone-danger">Cancelled</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </section>
                <?php endif; ?>

                <?php if ($tab === 'payments'): ?>
                    <section class="admin-stack" aria-labelledby="payments-title">
                        <div class="admin-section-head">
                            <div><span class="admin-eyebrow">Financial ledger</span><h2 id="payments-title">Payments, fuel &amp; payouts</h2></div>
                            <span class="status-pill tone-success"><?= e(money($kpis['revenue'])) ?> gross</span>
                        </div>

                        <div class="admin-kpi-grid admin-kpi-grid-3">
                            <article class="admin-kpi"><div class="admin-kpi-top"><span>Gross settled</span><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span></div><strong><?= e(money($kpis['revenue'])) ?></strong><small>All paid invoices</small></article>
                            <article class="admin-kpi"><div class="admin-kpi-top"><span>Settled today</span><span class="material-symbols-outlined" aria-hidden="true">today</span></div><strong><?= e(money($kpis['revenue_today'])) ?></strong><small>Recorded on <?= e(date('d M')) ?></small></article>
                            <article class="admin-kpi"><div class="admin-kpi-top"><span>Pending payouts</span><span class="material-symbols-outlined" aria-hidden="true">account_balance</span></div><strong><?= count($pendingWithdrawals) ?></strong><small><?= e(money(array_sum(array_column($pendingWithdrawals, 'amount')))) ?> requested</small></article>
                        </div>

                        <section class="admin-card">
                            <div class="admin-card-head"><div><h2>Payment ledger</h2><p>Every captured customer payment.</p></div></div>
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Booking</th><th>Customer</th><th>Vehicle</th><th>Valet fee</th><th>Fuel</th><th>Charged</th><th>Method</th><th>Paid at</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <?php if (!$payments): ?>
                                            <tr><td colspan="9" class="admin-empty">No payments recorded yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($payments as $payment): ?>
                                                <tr>
                                                    <td><strong><?= e(booking_ref($payment['booking_id'])) ?></strong></td>
                                                    <td><?= e(full_name($payment['first_name'], $payment['last_name'])) ?></td>
                                                    <td><?= e($payment['vehicle_type']) ?><small><?= e($payment['vehicle_number']) ?></small></td>
                                                    <td class="admin-num"><?= e(money($payment['price'])) ?></td>
                                                    <td class="admin-num"><?= (int) $payment['fuel_service'] === 1 ? e(money(FUEL_FARE)) : 'Not requested' ?></td>
                                                    <td class="admin-num"><strong><?= e(money($payment['amount'])) ?></strong></td>
                                                    <td><?= e($payment['method']) ?></td>
                                                    <td><?= e(format_ts($payment['paid_at'])) ?></td>
                                                    <td><span class="status-pill tone-<?= $payment['status'] === 'PAID' ? 'success' : 'warning' ?>"><?= e(ucfirst(strtolower($payment['status']))) ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="admin-card">
                            <div class="admin-card-head"><div><h2>Valet withdrawal requests</h2><p>Approve payouts only after transferring the amount to the UPI handle.</p></div></div>
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Valet</th><th>UPI handle</th><th>Requested</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
                                    <tbody>
                                        <?php if (!$withdrawals): ?>
                                            <tr><td colspan="6" class="admin-empty">No withdrawal requests.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($withdrawals as $withdrawal): ?>
                                                <tr>
                                                    <td><strong><?= e(full_name($withdrawal['first_name'], $withdrawal['last_name'])) ?></strong><small><?= e($withdrawal['username']) ?></small></td>
                                                    <td><?= e($withdrawal['upi_id'] ?: 'Not set') ?></td>
                                                    <td><?= e(format_ts($withdrawal['created_at'])) ?></td>
                                                    <td class="admin-num"><?= e(money($withdrawal['amount'])) ?></td>
                                                    <td><span class="status-pill tone-<?= $withdrawal['status'] === 'PAID' ? 'success' : ($withdrawal['status'] === 'REJECTED' ? 'danger' : 'warning') ?>"><?= e(ucfirst(strtolower($withdrawal['status']))) ?></span></td>
                                                    <td>
                                                        <?php if ($withdrawal['status'] === 'PENDING'): ?>
                                                            <div class="admin-actions">
                                                                <form class="admin-inline-form" method="post" action="admin-dashboard.php?tab=payments" data-confirm="Mark this withdrawal as paid?">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="withdrawal_status">
                                                                    <input type="hidden" name="withdrawal_id" value="<?= (int) $withdrawal['withdrawal_id'] ?>">
                                                                    <input type="hidden" name="status" value="PAID">
                                                                    <button class="text-link" type="submit">Mark paid</button>
                                                                </form>
                                                                <form class="admin-inline-form" method="post" action="admin-dashboard.php?tab=payments" data-confirm="Reject this withdrawal request?">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="withdrawal_status">
                                                                    <input type="hidden" name="withdrawal_id" value="<?= (int) $withdrawal['withdrawal_id'] ?>">
                                                                    <input type="hidden" name="status" value="REJECTED">
                                                                    <button class="text-link danger" type="submit">Reject</button>
                                                                </form>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="muted-copy">Closed</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </section>
                <?php endif; ?>

                <?php if ($tab === 'complaints'): ?>
                    <section class="admin-stack" aria-labelledby="complaints-title">
                        <div class="admin-section-head">
                            <div><span class="admin-eyebrow">Incident audits</span><h2 id="complaints-title">Customer complaints</h2></div>
                            <span class="status-pill tone-<?= count($openComplaints) ? 'warning' : 'success' ?>"><?= count($openComplaints) ?> open</span>
                        </div>

                        <?php if (!$complaints): ?>
                            <div class="admin-card empty-state"><span class="material-symbols-outlined" aria-hidden="true">verified</span><h2>No complaints</h2><p>Every customer incident has been closed.</p></div>
                        <?php else: ?>
                            <div class="admin-grid-2">
                                <?php foreach ($complaints as $complaint): ?>
                                    <article class="admin-card complaint-card">
                                        <div class="complaint-head">
                                            <div><h2><?= e(full_name($complaint['first_name'], $complaint['last_name'])) ?></h2><p><?= e(booking_ref($complaint['booking_id'])) ?> · <?= e($complaint['vehicle_number']) ?> · <?= e(format_ts($complaint['created_at'])) ?></p></div>
                                            <span class="status-pill tone-<?= $complaint['status'] === 'OPEN' ? 'warning' : 'success' ?>"><?= e(ucfirst(strtolower($complaint['status']))) ?></span>
                                        </div>
                                        <p class="complaint-message">“<?= e($complaint['message']) ?>”</p>
                                        <div class="complaint-meta"><span><?= e($complaint['customer_phone']) ?></span><span>Booking status: <?= e(status_label($complaint['booking_status'])) ?></span></div>
                                        <?php if ($complaint['status'] === 'OPEN'): ?>
                                            <form method="post" action="admin-dashboard.php?tab=complaints">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="resolve_complaint">
                                                <input type="hidden" name="complaint_id" value="<?= (int) $complaint['complaint_id'] ?>">
                                                <button class="button" type="submit"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span>Mark resolved</button>
                                            </form>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($tab === 'accounts'): ?>
                    <section class="admin-stack" aria-labelledby="accounts-title">
                        <div class="admin-section-head">
                            <div><span class="admin-eyebrow">Access governance</span><h2 id="accounts-title">All login accounts</h2></div>
                            <span class="status-pill"><?= count($accounts) ?> accounts</span>
                        </div>
                        <section class="admin-card">
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Account</th><th>Email</th><th>Role</th><th>Registered</th><th>Status</th><th>Action</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($accounts as $account): ?>
                                            <?php
                                            [$stateLabel, $stateTone] = $accountState($account);
                                            if ((int) $account['is_admin'] === 1) {
                                                $role = 'Administrator';
                                            } elseif ($account['customer_first'] !== null) {
                                                $role = 'Customer';
                                            } elseif ($account['valet_first'] !== null) {
                                                $role = 'Valet partner';
                                            } else {
                                                $role = 'No profile';
                                            }
                                            $name = $account['customer_first'] !== null
                                                ? full_name($account['customer_first'], $account['customer_last'])
                                                : ($account['valet_first'] !== null ? full_name($account['valet_first'], $account['valet_last']) : 'System account');
                                            ?>
                                            <tr>
                                                <td><strong><?= e($name) ?></strong><small>LOGIN-<?= str_pad((string) $account['login_id'], 3, '0', STR_PAD_LEFT) ?></small></td>
                                                <td><?= e($account['username']) ?></td>
                                                <td><span class="admin-role"><?= e($role) ?></span></td>
                                                <td><?= e(format_date($account['created_at'])) ?></td>
                                                <td><span class="status-pill tone-<?= e($stateTone) ?>"><?= e($stateLabel) ?></span></td>
                                                <td>
                                                    <?php if ($account['login_id'] === $user['login_id']): ?>
                                                        <span class="muted-copy">This is you</span>
                                                    <?php elseif ((int) $account['is_admin'] === 1): ?>
                                                        <span class="muted-copy">Protected</span>
                                                    <?php else: ?>
                                                        <form class="admin-inline-form" method="post" action="admin-dashboard.php?tab=accounts" data-confirm="Change this account status?">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="account_status">
                                                            <input type="hidden" name="login_id" value="<?= (int) $account['login_id'] ?>">
                                                            <input type="hidden" name="status" value="<?= (int) $account['status'] === 1 ? '0' : '1' ?>">
                                                            <button class="text-link <?= (int) $account['status'] === 1 ? 'danger' : '' ?>" type="submit"><?= (int) $account['status'] === 1 ? 'Suspend' : ($account['status'] === null ? 'Activate' : 'Reactivate') ?></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </section>
                <?php endif; ?>
            </main>

            <footer class="admin-footer">
                <span>&copy; <?= e($currentYear) ?> ValetFlow Mobility Infrastructure Ltd.</span>
                <span>Admin console · internal use only</span>
            </footer>
        </div>
    </div>

    <script src="assets/js/core.js" defer></script>
    <script src="assets/vendor/leaflet/leaflet.js" defer></script>
    <script src="assets/js/maps.js" defer></script>
    <script src="assets/js/admin.js" defer></script>
</body>
</html>
