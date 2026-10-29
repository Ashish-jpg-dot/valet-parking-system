<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function customer_vehicles(int $customerId): array
{
    $stmt = db()->prepare('SELECT * FROM tbl_vehicle WHERE customer_id = ? ORDER BY created_at DESC');
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function add_vehicle(int $customerId, string $type, string $number, string $color, string $fuel, int $odometer): ?string
{
    $number = strtoupper(preg_replace('/\s+/', '', $number));
    if ($type === '' || $number === '') {
        return 'Vehicle name and registration number are required.';
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO tbl_vehicle (customer_id, vehicle_number, vehicle_type, vehicle_color, fuel_level, odometer)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issssi', $customerId, $number, $type, $color, $fuel, $odometer);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) {
            return 'That registration number is already in your fleet.';
        }
        return 'Could not save the vehicle. Please try again.';
    }

    return null;
}

function create_booking(
    int $customerId,
    int $vehicleId,
    string $pickupAddress,
    ?float $pickupLatitude,
    ?float $pickupLongitude,
    string $pickupAt,
    bool $fuelService
): array {
    if (active_booking($customerId)) {
        return [null, 'You already have an active booking. Complete it or cancel it before booking again.'];
    }

    $vehicle = null;
    foreach (customer_vehicles($customerId) as $row) {
        if ((int) $row['vehicle_id'] === $vehicleId) {
            $vehicle = $row;
            break;
        }
    }
    if (!$vehicle) {
        return [null, 'Choose one of your registered vehicles.'];
    }
    if ($pickupAddress === '') {
        return [null, 'Pickup address is required.'];
    }
    if (!valid_coordinates($pickupLatitude, $pickupLongitude)) {
        return [null, 'Choose the pickup point on the map.'];
    }

    $pickup = DateTime::createFromFormat('Y-m-d\TH:i', $pickupAt);
    if (!$pickup) {
        return [null, 'Choose a valid pickup date/time.'];
    }
    if ($pickup <= new DateTime()) {
        return [null, 'Choose a pickup date/time in the future.'];
    }

    $price = BASE_FARE;
    $fuel = $fuelService ? 1 : 0;
    $pickupDate = $pickup->format('Y-m-d');
    $pickupTime = $pickup->format('H:i:s');

    $db = db();
    $db->begin_transaction();
    try {
        // Lock the customer row so two tabs cannot both pass the active-booking check.
        $stmt = $db->prepare('SELECT customer_id FROM tbl_customer WHERE customer_id = ? FOR UPDATE');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $stmt->close();

        $placeholders = implode(',', array_fill(0, count(ACTIVE_BOOKING_STATUSES), '?'));
        $types = 'i' . str_repeat('s', count(ACTIVE_BOOKING_STATUSES));
        $params = array_merge([$customerId], ACTIVE_BOOKING_STATUSES);
        $stmt = $db->prepare(
            "SELECT booking_id FROM tbl_booking
             WHERE customer_id = ? AND booking_status IN ($placeholders)
             LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($existing) {
            $db->rollback();
            return [null, 'You already have an active booking. Complete it or cancel it before booking again.'];
        }

        $stmt = $db->prepare(
            'INSERT INTO tbl_booking
                (customer_id, vehicle_id, vehicle_number, vehicle_type, vehicle_color,
                 pickup_address, pickup_latitude, pickup_longitude, pickup_date, pickup_time,
                 fuel_service, price, booking_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'SEARCHING\')'
        );
        $stmt->bind_param(
            'iissssddssid',
            $customerId,
            $vehicleId,
            $vehicle['vehicle_number'],
            $vehicle['vehicle_type'],
            $vehicle['vehicle_color'],
            $pickupAddress,
            $pickupLatitude,
            $pickupLongitude,
            $pickupDate,
            $pickupTime,
            $fuel,
            $price
        );
        $stmt->execute();
        $bookingId = $stmt->insert_id;
        $stmt->close();

        $db->commit();
        return [$bookingId, null];
    } catch (Throwable $exception) {
        $db->rollback();
        return [null, 'Could not create the booking. Please try again.'];
    }
}

function booking_select_sql(): string
{
    return 'SELECT b.*,
                   c.first_name AS customer_first, c.last_name AS customer_last, c.phone AS customer_phone,
                   v.first_name AS valet_first, v.last_name AS valet_last, v.phone AS valet_phone,
                   pj.status AS parking_status, pj.assigned_at, pj.completed_at AS parking_completed_at,
                   rj.return_job_id, rj.status AS return_status, rj.return_address,
                   rj.return_latitude, rj.return_longitude,
                   rj.valet_id AS return_valet_id,
                   rv.first_name AS return_valet_first, rv.last_name AS return_valet_last,
                   rv.phone AS return_valet_phone,
                   pay.payment_id, pay.amount AS paid_amount, pay.paid_at, pay.method,
                   po.code AS pickup_otp, po.verified_at AS pickup_otp_verified_at,
                   ro.code AS return_otp, ro.verified_at AS return_otp_verified_at,
                   rt.stars AS rating_stars, rt.comment AS rating_comment,
                   (SELECT COUNT(*) FROM tbl_complaint cmp WHERE cmp.booking_id = b.booking_id) AS complaint_count
            FROM tbl_booking b
            JOIN tbl_customer c ON c.customer_id = b.customer_id
            LEFT JOIN tbl_valet v ON v.valet_id = b.valet_id
            LEFT JOIN tbl_parking_job pj ON pj.booking_id = b.booking_id
            LEFT JOIN tbl_return_job rj ON rj.booking_id = b.booking_id
            LEFT JOIN tbl_valet rv ON rv.valet_id = rj.valet_id
            LEFT JOIN tbl_payment pay ON pay.booking_id = b.booking_id
            LEFT JOIN tbl_otp po ON po.booking_id = b.booking_id AND po.otp_type = \'PARKING\'
            LEFT JOIN tbl_otp ro ON ro.booking_id = b.booking_id AND ro.otp_type = \'RETURN\'
            LEFT JOIN tbl_rating rt ON rt.booking_id = b.booking_id
            ';
}

function get_booking(int $bookingId, ?int $customerId = null): ?array
{
    $sql = booking_select_sql() . ' WHERE b.booking_id = ?';
    if ($customerId !== null) {
        $sql .= ' AND b.customer_id = ?';
    }
    $sql .= ' LIMIT 1';

    $stmt = db()->prepare($sql);
    if ($customerId !== null) {
        $stmt->bind_param('ii', $bookingId, $customerId);
    } else {
        $stmt->bind_param('i', $bookingId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function customer_bookings(int $customerId, ?int $limit = null): array
{
    $sql = booking_select_sql() . ' WHERE b.customer_id = ? ORDER BY b.created_at DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    $stmt = db()->prepare($sql);
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function active_booking(int $customerId): ?array
{
    $placeholders = implode(',', array_fill(0, count(ACTIVE_BOOKING_STATUSES), '?'));
    $types = 'i' . str_repeat('s', count(ACTIVE_BOOKING_STATUSES));
    $params = array_merge([$customerId], ACTIVE_BOOKING_STATUSES);

    $stmt = db()->prepare(
        booking_select_sql() . " WHERE b.customer_id = ? AND b.booking_status IN ($placeholders)
         ORDER BY b.created_at DESC LIMIT 1"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function booking_total(array $booking): float
{
    return (float) $booking['price'] + ((int) $booking['fuel_service'] === 1 ? FUEL_FARE : 0);
}

function cancel_booking(int $bookingId, int $customerId): ?string
{
    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT booking_status FROM tbl_booking WHERE booking_id = ? AND customer_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $bookingId, $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $db->rollback();
            return 'Booking not found.';
        }
        if (!in_array($row['booking_status'], ['SEARCHING', 'ASSIGNED', 'ARRIVED'], true)) {
            $db->rollback();
            return 'This booking can no longer be cancelled online. Contact support.';
        }

        $stmt = $db->prepare("UPDATE tbl_booking SET booking_status = 'CANCELLED' WHERE booking_id = ?");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("UPDATE tbl_parking_job SET status = 'CANCELLED' WHERE booking_id = ? AND status = 'ASSIGNED'");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (Throwable $exception) {
        $db->rollback();
        return 'Could not cancel the booking. Please try again.';
    }
}

function pay_booking(int $bookingId, int $customerId): ?string
{
    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT * FROM tbl_booking WHERE booking_id = ? AND customer_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $bookingId, $customerId);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$booking) {
            $db->rollback();
            return 'Booking not found.';
        }
        if ($booking['booking_status'] !== 'PARKED') {
            $db->rollback();
            return 'Payment is available once the valet has parked your vehicle.';
        }

        $amount = booking_total($booking);
        $method = 'UPI';
        $stmt = $db->prepare(
            "INSERT INTO tbl_payment (booking_id, amount, status, method) VALUES (?, ?, 'PAID', ?)"
        );
        $stmt->bind_param('ids', $bookingId, $amount, $method);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("UPDATE tbl_booking SET booking_status = 'PAID' WHERE booking_id = ?");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (mysqli_sql_exception $exception) {
        $db->rollback();
        return $exception->getCode() === 1062
            ? 'This booking has already been paid.'
            : 'Payment could not be recorded. Please try again.';
    } catch (Throwable $exception) {
        $db->rollback();
        return 'Payment could not be recorded. Please try again.';
    }
}

function request_return(
    int $bookingId,
    int $customerId,
    string $address,
    string $returnAt,
    ?float $returnLatitude,
    ?float $returnLongitude
): ?string {
    if ($address === '') {
        return 'Return address is required.';
    }
    if (!valid_coordinates($returnLatitude, $returnLongitude)) {
        return 'Choose the return point on the map.';
    }

    $return = DateTime::createFromFormat('Y-m-d\TH:i', $returnAt);
    if (!$return) {
        return 'Choose a valid return date/time.';
    }
    if ($return <= new DateTime()) {
        return 'Choose a future return date/time.';
    }

    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT * FROM tbl_booking WHERE booking_id = ? AND customer_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $bookingId, $customerId);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$booking) {
            $db->rollback();
            return 'Booking not found.';
        }
        if ($booking['booking_status'] !== 'PAID') {
            $db->rollback();
            return 'Pay the parking invoice before requesting the return.';
        }

        $pickup = DateTime::createFromFormat('Y-m-d H:i:s', $booking['pickup_date'] . ' ' . $booking['pickup_time']);
        if ($pickup && $return <= $pickup) {
            $db->rollback();
            return 'Choose a return date/time after the pickup date/time.';
        }

        $returnDate = $return->format('Y-m-d');
        $returnTime = $return->format('H:i:s');

        $stmt = $db->prepare(
            "INSERT INTO tbl_return_job
                (booking_id, return_address, return_latitude, return_longitude, status)
             VALUES (?, ?, ?, ?, 'REQUESTED')"
        );
        $stmt->bind_param('isdd', $bookingId, $address, $returnLatitude, $returnLongitude);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare(
            "UPDATE tbl_booking
             SET return_date = ?, return_time = ?, booking_status = 'RETURN_REQUESTED'
             WHERE booking_id = ?"
        );
        $stmt->bind_param('ssi', $returnDate, $returnTime, $bookingId);
        $stmt->execute();
        $stmt->close();

        $code = random_otp();
        $type = 'RETURN';
        $stmt = $db->prepare('INSERT IGNORE INTO tbl_otp (booking_id, otp_type, code) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $bookingId, $type, $code);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (mysqli_sql_exception $exception) {
        $db->rollback();
        return $exception->getCode() === 1062
            ? 'A return has already been requested for this booking.'
            : 'The return request could not be saved.';
    } catch (Throwable $exception) {
        $db->rollback();
        return 'The return request could not be saved.';
    }
}

function report_complaint(int $customerId, int $bookingId, string $message): ?string
{
    if ($message === '') {
        return 'Describe the issue before submitting.';
    }

    $check = db()->prepare('SELECT booking_id FROM tbl_booking WHERE booking_id = ? AND customer_id = ? LIMIT 1');
    $check->bind_param('ii', $bookingId, $customerId);
    $check->execute();
    $owned = $check->get_result()->fetch_row();
    $check->close();
    if (!$owned) {
        return 'Choose one of your bookings.';
    }

    $stmt = db()->prepare('INSERT INTO tbl_complaint (booking_id, customer_id, message) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $bookingId, $customerId, $message);
    $stmt->execute();
    $stmt->close();
    return null;
}

function update_customer_profile(int $customerId, int $loginId, string $fullName, string $phone, string $email, string $address): ?string
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    if (!$parts || $parts[0] === '') {
        return 'Full name is required.';
    }
    $first = array_shift($parts);
    $last = implode(' ', $parts);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid email address.';
    }

    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('UPDATE tbl_customer SET first_name = ?, last_name = ?, phone = ?, address = ? WHERE customer_id = ?');
        $stmt->bind_param('ssssi', $first, $last, $phone, $address, $customerId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('UPDATE tbl_login SET username = ? WHERE login_id = ?');
        $stmt->bind_param('si', $email, $loginId);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (mysqli_sql_exception $exception) {
        $db->rollback();
        return $exception->getCode() === 1062
            ? 'That email address is already in use.'
            : 'Profile could not be updated.';
    }
}

function booking_inspections(int $bookingId): array
{
    $stmt = db()->prepare(
        "SELECT * FROM tbl_vehicle_inspection
         WHERE booking_id = ?
         ORDER BY FIELD(stage, 'PICKUP', 'PARKED', 'RETURN'), created_at ASC"
    );
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $photos = inspection_photos($bookingId);
    foreach ($rows as &$row) {
        $row['photos'] = $photos[(int) $row['inspection_id']] ?? [];
    }
    unset($row);

    return $rows;
}

function inspection_photos(int $bookingId): array
{
    $stmt = db()->prepare(
        'SELECT p.* FROM tbl_inspection_photo p
         JOIN tbl_vehicle_inspection i ON i.inspection_id = p.inspection_id
         WHERE i.booking_id = ?
         ORDER BY p.photo_id ASC'
    );
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int) $row['inspection_id']][] = $row;
    }
    return $grouped;
}

function rate_booking(int $customerId, int $bookingId, int $stars, string $comment): ?string
{
    if ($stars < 1 || $stars > 5) {
        return 'Choose a rating between 1 and 5 stars.';
    }
    $comment = trim($comment);
    if (mb_strlen($comment) > 1000) {
        return 'Keep your feedback under 1000 characters.';
    }

    $stmt = db()->prepare(
        "SELECT b.booking_id, b.valet_id, rj.valet_id AS return_valet_id
         FROM tbl_booking b
         LEFT JOIN tbl_return_job rj ON rj.booking_id = b.booking_id
         WHERE b.booking_id = ? AND b.customer_id = ? AND b.booking_status = 'COMPLETED'
         LIMIT 1"
    );
    $stmt->bind_param('ii', $bookingId, $customerId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        return 'Choose one of your completed bookings.';
    }

    $valetId = $booking['return_valet_id'] !== null ? (int) $booking['return_valet_id'] : (int) $booking['valet_id'];

    $stmt = db()->prepare(
        'INSERT INTO tbl_rating (booking_id, valet_id, stars, comment)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE valet_id = VALUES(valet_id), stars = VALUES(stars), comment = VALUES(comment)'
    );
    $stmt->bind_param('iiis', $bookingId, $valetId, $stars, $comment);
    $stmt->execute();
    $stmt->close();

    return null;
}
