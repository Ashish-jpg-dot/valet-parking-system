<?php
/**
 * Idempotent demo seeder. Existing non-demo accounts and data are left alone.
 * Run: php database/seed.php
 */
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

$db = db();

function seed_login(mysqli $db, string $username, string $password, ?int $status, int $isAdmin): int
{
    $stmt = $db->prepare('SELECT login_id FROM tbl_login WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    if ($row) {
        $loginId = (int) $row['login_id'];
        $stmt = $db->prepare('UPDATE tbl_login SET passwords = ?, status = ?, is_admin = ? WHERE login_id = ?');
        $stmt->bind_param('siii', $hash, $status, $isAdmin, $loginId);
        $stmt->execute();
        $stmt->close();
        return $loginId;
    }

    $stmt = $db->prepare('INSERT INTO tbl_login (username, passwords, status, is_admin) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssii', $username, $hash, $status, $isAdmin);
    $stmt->execute();
    $loginId = (int) $stmt->insert_id;
    $stmt->close();
    return $loginId;
}

function seed_customer(mysqli $db, int $loginId, string $first, string $last, string $phone, string $address): int
{
    $stmt = $db->prepare('SELECT customer_id FROM tbl_customer WHERE login_id = ? LIMIT 1');
    $stmt->bind_param('i', $loginId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $customerId = (int) $row['customer_id'];
        $stmt = $db->prepare('UPDATE tbl_customer SET first_name = ?, last_name = ?, phone = ?, address = ? WHERE customer_id = ?');
        $stmt->bind_param('ssssi', $first, $last, $phone, $address, $customerId);
        $stmt->execute();
        $stmt->close();
        return $customerId;
    }

    $stmt = $db->prepare('INSERT INTO tbl_customer (login_id, first_name, last_name, phone, address) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issss', $loginId, $first, $last, $phone, $address);
    $stmt->execute();
    $customerId = (int) $stmt->insert_id;
    $stmt->close();
    return $customerId;
}

function seed_valet(mysqli $db, int $loginId, string $first, string $last, string $phone, string $license, ?string $upi, int $live): int
{
    $stmt = $db->prepare('SELECT valet_id FROM tbl_valet WHERE login_id = ? LIMIT 1');
    $stmt->bind_param('i', $loginId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $valetId = (int) $row['valet_id'];
        $stmt = $db->prepare('UPDATE tbl_valet SET first_name = ?, last_name = ?, phone = ?, license_number = ?, upi_id = ?, is_live = ? WHERE valet_id = ?');
        $stmt->bind_param('sssssii', $first, $last, $phone, $license, $upi, $live, $valetId);
        $stmt->execute();
        $stmt->close();
        return $valetId;
    }

    $stmt = $db->prepare('INSERT INTO tbl_valet (login_id, first_name, last_name, phone, license_number, upi_id, is_live) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssssi', $loginId, $first, $last, $phone, $license, $upi, $live);
    $stmt->execute();
    $valetId = (int) $stmt->insert_id;
    $stmt->close();
    return $valetId;
}

function seed_valet_license(mysqli $db, int $valetId, string $filename): void
{
    if (!is_file(UPLOAD_DIR . '/' . $filename)) {
        return;
    }
    $stmt = $db->prepare('UPDATE tbl_valet SET license_photo = ? WHERE valet_id = ?');
    $stmt->bind_param('si', $filename, $valetId);
    $stmt->execute();
    $stmt->close();
}

function seed_vehicle(mysqli $db, int $customerId, string $number, string $type, string $color, string $fuel, int $odometer): int
{
    $stmt = $db->prepare('SELECT vehicle_id FROM tbl_vehicle WHERE customer_id = ? AND vehicle_number = ? LIMIT 1');
    $stmt->bind_param('is', $customerId, $number);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return (int) $row['vehicle_id'];
    }

    $stmt = $db->prepare('INSERT INTO tbl_vehicle (customer_id, vehicle_number, vehicle_type, vehicle_color, fuel_level, odometer) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('issssi', $customerId, $number, $type, $color, $fuel, $odometer);
    $stmt->execute();
    $vehicleId = (int) $stmt->insert_id;
    $stmt->close();
    return $vehicleId;
}

function seed_booking(mysqli $db, array $data): array
{
    $stmt = $db->prepare('SELECT booking_id FROM tbl_booking WHERE customer_id = ? AND pickup_address = ? LIMIT 1');
    $stmt->bind_param('is', $data['customer_id'], $data['pickup_address']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $stmt = $db->prepare(
            'UPDATE tbl_booking SET pickup_latitude = COALESCE(pickup_latitude, ?), pickup_longitude = COALESCE(pickup_longitude, ?)
             WHERE booking_id = ?'
        );
        $stmt->bind_param('ddi', $data['pickup_latitude'], $data['pickup_longitude'], $row['booking_id']);
        $stmt->execute();
        $stmt->close();
        return [(int) $row['booking_id'], false];
    }

    $stmt = $db->prepare(
        'INSERT INTO tbl_booking
            (customer_id, vehicle_id, valet_id, vehicle_number, vehicle_type, vehicle_color,
             pickup_address, pickup_latitude, pickup_longitude, pickup_date, pickup_time, return_date, return_time,
             fuel_service, price, booking_status, parking_slot, parking_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iiissssddssssidsss',
        $data['customer_id'],
        $data['vehicle_id'],
        $data['valet_id'],
        $data['vehicle_number'],
        $data['vehicle_type'],
        $data['vehicle_color'],
        $data['pickup_address'],
        $data['pickup_latitude'],
        $data['pickup_longitude'],
        $data['pickup_date'],
        $data['pickup_time'],
        $data['return_date'],
        $data['return_time'],
        $data['fuel_service'],
        $data['price'],
        $data['booking_status'],
        $data['parking_slot'],
        $data['parking_address']
    );
    $stmt->execute();
    $bookingId = (int) $stmt->insert_id;
    $stmt->close();
    return [$bookingId, true];
}

function seed_otp(mysqli $db, int $bookingId, string $type, string $code, bool $verified): void
{
    $verifiedAt = $verified ? date('Y-m-d H:i:s') : null;
    $stmt = $db->prepare('INSERT IGNORE INTO tbl_otp (booking_id, otp_type, code, verified_at) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $bookingId, $type, $code, $verifiedAt);
    $stmt->execute();
    $stmt->close();
}

function seed_inspection(mysqli $db, int $bookingId, string $stage, string $fuel, int $odometer, string $condition, string $notes, int $valetId): void
{
    $stmt = $db->prepare(
        'INSERT IGNORE INTO tbl_vehicle_inspection (booking_id, stage, fuel_level, odometer, vehicle_condition, notes, valet_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ississi', $bookingId, $stage, $fuel, $odometer, $condition, $notes, $valetId);
    $stmt->execute();
    $stmt->close();
}

function seed_earning(mysqli $db, int $valetId, int $bookingId, string $type, float $amount): void
{
    $stmt = $db->prepare('INSERT IGNORE INTO tbl_earning (valet_id, booking_id, job_type, amount) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iisd', $valetId, $bookingId, $type, $amount);
    $stmt->execute();
    $stmt->close();
}

try {
    $db->begin_transaction();

    $adminLogin = seed_login($db, 'admin@valetflow.local', 'Admin@123', 1, 1);
    $vikramLogin = seed_login($db, 'vikram@valetflow.local', 'Customer@123', 1, 0);
    $priyaLogin = seed_login($db, 'priya@valetflow.local', 'Customer@123', 1, 0);
    $ananyaLogin = seed_login($db, 'ananya@valetflow.local', 'Customer@123', 1, 0);
    $marcusLogin = seed_login($db, 'marcus@valetflow.local', 'Valet@123', 1, 0);
    $rahulLogin = seed_login($db, 'rahul@valetflow.local', 'Valet@123', null, 0);

    $vikramId = seed_customer($db, $vikramLogin, 'Vikram', 'Malhotra', '+91 98200 11223', 'Bandra West, Mumbai, Maharashtra');
    $priyaId = seed_customer($db, $priyaLogin, 'Priya', 'Nair', '+91 98330 44556', 'Powai, Mumbai, Maharashtra');
    $ananyaId = seed_customer($db, $ananyaLogin, 'Ananya', 'Rao', '+91 98670 77889', 'Lower Parel, Mumbai, Maharashtra');

    $marcusId = seed_valet($db, $marcusLogin, 'Marcus', 'Vance', '+91 98765 43210', 'DL-1420110012345', 'marcus.vance@okaxis', 1);
    $rahulId = seed_valet($db, $rahulLogin, 'Rahul', 'Deshmukh', '+91 98201 44819', 'DL-1420110099999', null, 0);
    seed_valet_license($db, $marcusId, 'demo-marcus-license.pdf');
    seed_valet_license($db, $rahulId, 'demo-rahul-license.pdf');

    $mercedesId = seed_vehicle($db, $vikramId, 'MH-02-CD-4512', 'Mercedes-Benz C-Class', 'Obsidian Black', 'Full', 18400);
    $bmwId = seed_vehicle($db, $vikramId, 'MH-01-BK-8900', 'BMW X5 xDrive40i', 'Alpine White', '3/4', 22100);
    $ioniqId = seed_vehicle($db, $priyaId, 'MH-01-PN-2211', 'Hyundai Ioniq 5', 'Atlas White', 'Full', 7300);
    $audiId = seed_vehicle($db, $ananyaId, 'MH-03-AR-7788', 'Audi A6', 'Ibis White', 'Half', 15000);

    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $tenDaysAgo = date('Y-m-d', strtotime('-10 days'));

    // Priya is waiting for a valet: this is what Marcus can accept.
    seed_booking($db, [
        'customer_id' => $priyaId,
        'vehicle_id' => $ioniqId,
        'valet_id' => null,
        'vehicle_number' => 'MH-01-PN-2211',
        'vehicle_type' => 'Hyundai Ioniq 5',
        'vehicle_color' => 'Atlas White',
        'pickup_address' => 'Chhatrapati Shivaji Maharaj International Airport, Terminal 2, Curbside Gate 4',
        'pickup_latitude' => 19.0974,
        'pickup_longitude' => 72.8740,
        'pickup_date' => $tomorrow,
        'pickup_time' => '10:00:00',
        'return_date' => null,
        'return_time' => null,
        'fuel_service' => 0,
        'price' => 500.00,
        'booking_status' => 'SEARCHING',
        'parking_slot' => null,
        'parking_address' => null,
    ]);

    // Vikram's car is parked and waiting for payment.
    [$parkedId, $parkedNew] = seed_booking($db, [
        'customer_id' => $vikramId,
        'vehicle_id' => $mercedesId,
        'valet_id' => $marcusId,
        'vehicle_number' => 'MH-02-CD-4512',
        'vehicle_type' => 'Mercedes-Benz C-Class',
        'vehicle_color' => 'Obsidian Black',
        'pickup_address' => 'Grand Hyatt Mumbai, Santacruz East, Valet Porch',
        'pickup_latitude' => 19.0870,
        'pickup_longitude' => 72.8490,
        'pickup_date' => $today,
        'pickup_time' => '09:15:00',
        'return_date' => null,
        'return_time' => null,
        'fuel_service' => 0,
        'price' => 500.00,
        'booking_status' => 'PARKED',
        'parking_slot' => 'Bay 4A',
        'parking_address' => 'ValetFlow Parking, Andheri East, Mumbai',
    ]);
    if ($parkedNew) {
        $stmt = $db->prepare("INSERT INTO tbl_parking_job (booking_id, valet_id, status, assigned_at, completed_at, earning) VALUES (?, ?, 'COMPLETED', ?, ?, 200.00)");
        $assignedAt = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $completedAt = date('Y-m-d H:i:s', strtotime('-30 minutes'));
        $stmt->bind_param('iiss', $parkedId, $marcusId, $assignedAt, $completedAt);
        $stmt->execute();
        $stmt->close();
        seed_otp($db, $parkedId, 'PARKING', '1234', true);
        seed_inspection($db, $parkedId, 'PICKUP', 'Full', 18400, 'Good', 'Small scuff on rear bumper noted at pickup.', $marcusId);
        seed_earning($db, $marcusId, $parkedId, 'PARKING', 200.00);
    }

    // Ananya has paid and is waiting for a valet to accept the return.
    [$returnId, $returnNew] = seed_booking($db, [
        'customer_id' => $ananyaId,
        'vehicle_id' => $audiId,
        'valet_id' => $marcusId,
        'vehicle_number' => 'MH-03-AR-7788',
        'vehicle_type' => 'Audi A6',
        'vehicle_color' => 'Ibis White',
        'pickup_address' => 'The St. Regis Mumbai, Lower Parel, Main Portico',
        'pickup_latitude' => 18.9940,
        'pickup_longitude' => 72.8230,
        'pickup_date' => $yesterday,
        'pickup_time' => '14:00:00',
        'return_date' => $today,
        'return_time' => '20:00:00',
        'fuel_service' => 0,
        'price' => 500.00,
        'booking_status' => 'RETURN_REQUESTED',
        'parking_slot' => 'Bay 2C',
        'parking_address' => 'ValetFlow Parking, Lower Parel, Mumbai',
    ]);
    if ($returnNew) {
        $stmt = $db->prepare("INSERT INTO tbl_parking_job (booking_id, valet_id, status, assigned_at, completed_at, earning) VALUES (?, ?, 'COMPLETED', ?, ?, 200.00)");
        $assignedAt = date('Y-m-d H:i:s', strtotime('-1 day +2 hours'));
        $completedAt = date('Y-m-d H:i:s', strtotime('-22 hours'));
        $stmt->bind_param('iiss', $returnId, $marcusId, $assignedAt, $completedAt);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("INSERT INTO tbl_return_job (booking_id, return_address, return_latitude, return_longitude, status, requested_at, earning) VALUES (?, ?, 18.9940, 72.8230, 'REQUESTED', NOW(), 200.00)");
        $address = 'The St. Regis Mumbai, Lower Parel, Main Portico';
        $stmt->bind_param('is', $returnId, $address);
        $stmt->execute();
        $stmt->close();

        seed_otp($db, $returnId, 'PARKING', '4321', true);
        seed_otp($db, $returnId, 'RETURN', '5678', false);
        seed_inspection($db, $returnId, 'PICKUP', 'Half', 15000, 'Good', 'No visible damage.', $marcusId);
        seed_earning($db, $marcusId, $returnId, 'PARKING', 200.00);

        $stmt = $db->prepare("INSERT INTO tbl_payment (booking_id, amount, status, method, paid_at) VALUES (?, 500.00, 'PAID', 'UPI', ?)");
        $paidAt = date('Y-m-d H:i:s', strtotime('-2 hours'));
        $stmt->bind_param('is', $returnId, $paidAt);
        $stmt->execute();
        $stmt->close();
    }

    // Vikram's completed history with fuel service and both handovers.
    [$completedId, $completedNew] = seed_booking($db, [
        'customer_id' => $vikramId,
        'vehicle_id' => $bmwId,
        'valet_id' => $marcusId,
        'vehicle_number' => 'MH-01-BK-8900',
        'vehicle_type' => 'BMW X5 xDrive40i',
        'vehicle_color' => 'Alpine White',
        'pickup_address' => 'Chhatrapati Shivaji Maharaj International Airport, Terminal 1, Departure',
        'pickup_latitude' => 19.0960,
        'pickup_longitude' => 72.8690,
        'pickup_date' => $tenDaysAgo,
        'pickup_time' => '08:00:00',
        'return_date' => date('Y-m-d', strtotime('-8 days')),
        'return_time' => '19:15:00',
        'fuel_service' => 1,
        'price' => 500.00,
        'booking_status' => 'COMPLETED',
        'parking_slot' => 'Bay 1C',
        'parking_address' => 'ValetFlow Parking, Andheri East, Mumbai',
    ]);
    if ($completedNew) {
        $stmt = $db->prepare("INSERT INTO tbl_parking_job (booking_id, valet_id, status, assigned_at, completed_at, earning) VALUES (?, ?, 'COMPLETED', ?, ?, 200.00)");
        $assignedAt = date('Y-m-d H:i:s', strtotime('-10 days +1 hour'));
        $completedAt = date('Y-m-d H:i:s', strtotime('-10 days +3 hours'));
        $stmt->bind_param('iiss', $completedId, $marcusId, $assignedAt, $completedAt);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("INSERT INTO tbl_return_job (booking_id, valet_id, return_address, return_latitude, return_longitude, status, requested_at, assigned_at, completed_at, earning) VALUES (?, ?, ?, 19.0960, 72.8690, 'COMPLETED', ?, ?, ?, 200.00)");
        $address = 'Chhatrapati Shivaji Maharaj International Airport, Terminal 1, Departure';
        $requestedAt = date('Y-m-d H:i:s', strtotime('-8 days +1 hour'));
        $returnAssigned = date('Y-m-d H:i:s', strtotime('-8 days +2 hours'));
        $returnCompleted = date('Y-m-d H:i:s', strtotime('-8 days +5 hours'));
        $stmt->bind_param('iissss', $completedId, $marcusId, $address, $requestedAt, $returnAssigned, $returnCompleted);
        $stmt->execute();
        $stmt->close();

        seed_otp($db, $completedId, 'PARKING', '1234', true);
        seed_otp($db, $completedId, 'RETURN', '5678', true);
        seed_inspection($db, $completedId, 'PICKUP', '3/4', 22100, 'Good', 'Minor dust; no damage.', $marcusId);
        seed_inspection($db, $completedId, 'RETURN', 'Full', 22135, 'Good', 'Returned clean, fuel refilled.', $marcusId);
        seed_earning($db, $marcusId, $completedId, 'PARKING', 200.00);
        seed_earning($db, $marcusId, $completedId, 'RETURN', 200.00);

        $stmt = $db->prepare("INSERT INTO tbl_payment (booking_id, amount, status, method, paid_at) VALUES (?, 2000.00, 'PAID', 'UPI', ?)");
        $paidAt = date('Y-m-d H:i:s', strtotime('-9 days'));
        $stmt->bind_param('is', $completedId, $paidAt);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO tbl_rating (booking_id, valet_id, stars, comment) VALUES (?, ?, 5, ?)');
        $comment = 'Careful handover and the car came back spotless.';
        $stmt->bind_param('iis', $completedId, $marcusId, $comment);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO tbl_complaint (booking_id, customer_id, message) VALUES (?, ?, ?)');
        $message = 'The return handover took longer than the promised window.';
        $stmt->bind_param('iis', $completedId, $vikramId, $message);
        $stmt->execute();
        $stmt->close();
    }

    // A cancelled booking so the admin cancellation queue has data.
    seed_booking($db, [
        'customer_id' => $priyaId,
        'vehicle_id' => $ioniqId,
        'valet_id' => null,
        'vehicle_number' => 'MH-01-PN-2211',
        'vehicle_type' => 'Hyundai Ioniq 5',
        'vehicle_color' => 'Atlas White',
        'pickup_address' => 'Phoenix Palladium, Lower Parel, Curbside',
        'pickup_latitude' => 18.9950,
        'pickup_longitude' => 72.8260,
        'pickup_date' => date('Y-m-d', strtotime('-3 days')),
        'pickup_time' => '11:00:00',
        'return_date' => date('Y-m-d', strtotime('-2 days')),
        'return_time' => '16:00:00',
        'fuel_service' => 0,
        'price' => 500.00,
        'booking_status' => 'CANCELLED',
        'parking_slot' => null,
        'parking_address' => null,
    ]);

    // One pending payout request from the demo valet.
    $stmt = $db->prepare('SELECT withdrawal_id FROM tbl_withdrawal WHERE valet_id = ? LIMIT 1');
    $stmt->bind_param('i', $marcusId);
    $stmt->execute();
    $withdrawal = $stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$withdrawal) {
        $stmt = $db->prepare("INSERT INTO tbl_withdrawal (valet_id, amount, status) VALUES (?, 200.00, 'PENDING')");
        $stmt->bind_param('i', $marcusId);
        $stmt->execute();
        $stmt->close();
    }

    // Align older demo rows with the return-on-request workflow.
    $db->query(
        "UPDATE tbl_booking SET return_date = NULL, return_time = NULL
         WHERE booking_status IN ('SEARCHING', 'PARKED', 'ASSIGNED', 'ARRIVED', 'COLLECTED', 'PAID')
           AND return_date IS NOT NULL"
    );
    $db->query(
        'UPDATE tbl_return_job rj
         JOIN tbl_booking b ON b.booking_id = rj.booking_id
         SET rj.return_latitude = COALESCE(rj.return_latitude, b.pickup_latitude),
             rj.return_longitude = COALESCE(rj.return_longitude, b.pickup_longitude)'
    );

    $db->commit();
    echo "Seed complete.\n\n";
    echo "Admin:    admin@valetflow.local / Admin@123\n";
    echo "Customer: vikram@valetflow.local / Customer@123\n";
    echo "Customer: priya@valetflow.local / Customer@123\n";
    echo "Customer: ananya@valetflow.local / Customer@123\n";
    echo "Valet:    marcus@valetflow.local / Valet@123 (approved, online)\n";
    echo "Valet:    rahul@valetflow.local / Valet@123 (pending approval)\n";
    echo "\nExisting customer accounts were not modified.\n";
} catch (Throwable $exception) {
    $db->rollback();
    fwrite(STDERR, 'Seed failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
