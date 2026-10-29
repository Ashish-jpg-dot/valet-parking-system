<?php
require_once __DIR__ . '/db.php';

/**
 * Live public counters for the landing page.
 */
function landing_stats(): array
{
    $row = db()->query(
        "SELECT
            (SELECT COUNT(*) FROM tbl_valet v JOIN tbl_login l ON l.login_id = v.login_id WHERE l.status = 1) AS verified_valets,
            (SELECT COUNT(*) FROM tbl_valet WHERE is_live = 1) AS online_valets,
            (SELECT COUNT(*) FROM tbl_booking WHERE booking_status NOT IN ('COMPLETED', 'CANCELLED')) AS active_bookings,
            (SELECT COUNT(*) FROM tbl_booking WHERE booking_status = 'COMPLETED') AS completed_bookings,
            (SELECT COUNT(*) FROM tbl_return_job WHERE status = 'COMPLETED') AS completed_returns"
    )->fetch_assoc();

    return array_map('intval', $row ?: []);
}
