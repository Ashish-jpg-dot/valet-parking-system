<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function available_parking_jobs(int $valetId): array
{
    $stmt = db()->prepare(
        "SELECT b.*, c.first_name, c.last_name, c.phone AS customer_phone
         FROM tbl_booking b
         JOIN tbl_customer c ON c.customer_id = b.customer_id
         WHERE b.booking_status = 'SEARCHING' AND b.valet_id IS NULL
           AND NOT EXISTS (
               SELECT 1 FROM tbl_job_decline d
               WHERE d.valet_id = ? AND d.booking_id = b.booking_id AND d.job_type = 'PARKING'
           )
         ORDER BY b.created_at ASC
         LIMIT 30"
    );
    $stmt->bind_param('i', $valetId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function available_return_jobs(int $valetId): array
{
    $stmt = db()->prepare(
        "SELECT rj.*, b.vehicle_number, b.vehicle_type, b.vehicle_color, b.pickup_address,
                b.parking_address, b.return_date, b.return_time, b.booking_status,
                c.first_name, c.last_name, c.phone AS customer_phone
         FROM tbl_return_job rj
         JOIN tbl_booking b ON b.booking_id = rj.booking_id
         JOIN tbl_customer c ON c.customer_id = b.customer_id
         WHERE rj.status = 'REQUESTED' AND rj.valet_id IS NULL
           AND NOT EXISTS (
               SELECT 1 FROM tbl_job_decline d
               WHERE d.valet_id = ? AND d.booking_id = rj.booking_id AND d.job_type = 'RETURN'
           )
         ORDER BY rj.requested_at ASC
         LIMIT 30"
    );
    $stmt->bind_param('i', $valetId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function valet_has_active_job(int $valetId): bool
{
    // Must run inside a transaction so the FOR UPDATE lock is held.
    $stmt = db()->prepare(
        "SELECT b.booking_id
         FROM tbl_booking b
         LEFT JOIN tbl_return_job rj ON rj.booking_id = b.booking_id
         WHERE (b.valet_id = ? OR rj.valet_id = ?)
           AND b.booking_status IN ('ASSIGNED', 'ARRIVED', 'COLLECTED', 'RETURNING', 'RETURN_ARRIVED')
         LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('ii', $valetId, $valetId);
    $stmt->execute();
    $busy = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $busy;
}

function otp_register_failure(int $bookingId, string $type, int $attempts): void
{
    if ($attempts + 1 >= AUTH_MAX_ATTEMPTS) {
        $until = (new DateTime('+' . AUTH_LOCK_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
        $stmt = db()->prepare('UPDATE tbl_otp SET attempts = 0, locked_until = ? WHERE booking_id = ? AND otp_type = ?');
        $stmt->bind_param('sis', $until, $bookingId, $type);
    } else {
        $stmt = db()->prepare('UPDATE tbl_otp SET attempts = attempts + 1 WHERE booking_id = ? AND otp_type = ?');
        $stmt->bind_param('is', $bookingId, $type);
    }
    $stmt->execute();
    $stmt->close();
}

function decline_job(int $valetId, int $bookingId, string $jobType): ?string
{
    if (!in_array($jobType, ['PARKING', 'RETURN'], true)) {
        return 'Unknown job type.';
    }

    if ($jobType === 'PARKING') {
        $stmt = db()->prepare(
            "SELECT booking_id FROM tbl_booking
             WHERE booking_id = ? AND booking_status = 'SEARCHING' AND valet_id IS NULL LIMIT 1"
        );
        $stmt->bind_param('i', $bookingId);
    } else {
        $stmt = db()->prepare(
            "SELECT rj.return_job_id FROM tbl_return_job rj
             JOIN tbl_booking b ON b.booking_id = rj.booking_id
             WHERE rj.booking_id = ? AND rj.status = 'REQUESTED' AND rj.valet_id IS NULL
               AND b.booking_status = 'RETURN_REQUESTED' LIMIT 1"
        );
        $stmt->bind_param('i', $bookingId);
    }
    $stmt->execute();
    $available = $stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$available) {
        return 'That job is no longer available.';
    }

    $stmt = db()->prepare('INSERT IGNORE INTO tbl_job_decline (valet_id, booking_id, job_type) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $valetId, $bookingId, $jobType);
    $stmt->execute();
    $stmt->close();
    return null;
}

function valet_active_booking(int $valetId): ?array
{
    $stmt = db()->prepare(
        "SELECT b.*, c.first_name, c.last_name, c.phone AS customer_phone,
                v.first_name AS parking_valet_first, v.last_name AS parking_valet_last,
                pj.status AS parking_status, pj.assigned_at, pj.completed_at AS parking_completed_at,
                rj.return_job_id, rj.status AS return_status, rj.return_address,
                rj.return_latitude, rj.return_longitude,
                rj.valet_id AS return_valet_id, rj.assigned_at AS return_assigned_at,
                pay.payment_id, pay.amount AS paid_amount, pay.status AS payment_status, pay.paid_at
         FROM tbl_booking b
         JOIN tbl_customer c ON c.customer_id = b.customer_id
         LEFT JOIN tbl_valet v ON v.valet_id = b.valet_id
         LEFT JOIN tbl_parking_job pj ON pj.booking_id = b.booking_id
         LEFT JOIN tbl_return_job rj ON rj.booking_id = b.booking_id
         LEFT JOIN tbl_payment pay ON pay.booking_id = b.booking_id
         WHERE b.booking_status IN ('ASSIGNED', 'ARRIVED', 'COLLECTED', 'RETURNING', 'RETURN_ARRIVED')
           AND (b.valet_id = ? OR rj.valet_id = ?)
         ORDER BY b.updated_at DESC
         LIMIT 1"
    );
    $stmt->bind_param('ii', $valetId, $valetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function accept_parking_job(int $valetId, int $bookingId): ?string
{
    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT is_live FROM tbl_valet WHERE valet_id = ? FOR UPDATE');
        $stmt->bind_param('i', $valetId);
        $stmt->execute();
        $valet = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$valet) {
            $db->rollback();
            return 'Valet profile not found.';
        }
        if ((int) $valet['is_live'] !== 1) {
            $db->rollback();
            return 'Go online before accepting a job.';
        }

        if (valet_has_active_job($valetId)) {
            $db->rollback();
            return 'Finish your current job before accepting another.';
        }

        $stmt = $db->prepare('SELECT booking_id, booking_status, valet_id FROM tbl_booking WHERE booking_id = ? FOR UPDATE');
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$booking || $booking['booking_status'] !== 'SEARCHING' || $booking['valet_id'] !== null) {
            $db->rollback();
            return 'That job is no longer available.';
        }

        $stmt = $db->prepare("UPDATE tbl_booking SET valet_id = ?, booking_status = 'ASSIGNED' WHERE booking_id = ?");
        $stmt->bind_param('ii', $valetId, $bookingId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO tbl_parking_job (booking_id, valet_id, status) VALUES (?, ?, \'ASSIGNED\')');
        $stmt->bind_param('ii', $bookingId, $valetId);
        $stmt->execute();
        $stmt->close();

        $code = random_otp();
        $type = 'PARKING';
        $stmt = $db->prepare('INSERT IGNORE INTO tbl_otp (booking_id, otp_type, code) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $bookingId, $type, $code);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('UPDATE tbl_valet SET last_assigned_at = NOW() WHERE valet_id = ?');
        $stmt->bind_param('i', $valetId);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (Throwable $exception) {
        $db->rollback();
        return 'The job could not be accepted. Please refresh and try again.';
    }
}

function valet_mark_arrived(int $valetId, int $bookingId): ?string
{
    $stmt = db()->prepare(
        "UPDATE tbl_booking SET booking_status = 'ARRIVED'
         WHERE booking_id = ? AND valet_id = ? AND booking_status = 'ASSIGNED'"
    );
    $stmt->bind_param('ii', $bookingId, $valetId);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    return $changed ? null : 'This job is not waiting for arrival.';
}

function store_inspection_photos(int $inspectionId, array $files): ?string
{
    if (!$files || !isset($files['name'])) {
        return null;
    }

    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $errors = is_array($files['error'] ?? null) ? $files['error'] : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
    $sizes = is_array($files['size'] ?? null) ? $files['size'] : [$files['size'] ?? 0];
    $tmpNames = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [$files['tmp_name'] ?? ''];

    $selected = [];
    foreach ($names as $index => $name) {
        $error = $errors[$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return 'Each photo must be ' . (INSPECTION_PHOTO_MAX_BYTES / 1048576) . ' MB or smaller.';
        }
        if ($error !== UPLOAD_ERR_OK) {
            return 'One of the photos could not be uploaded. Try again.';
        }
        if (($sizes[$index] ?? 0) > INSPECTION_PHOTO_MAX_BYTES) {
            return 'Each photo must be ' . (INSPECTION_PHOTO_MAX_BYTES / 1048576) . ' MB or smaller.';
        }

        $tmpName = $tmpNames[$index] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return 'One of the photos could not be uploaded. Try again.';
        }

        $extension = detected_upload_extension($tmpName);
        if (!in_array($extension, ['jpg', 'png'], true)) {
            return 'Inspection photos must be JPG or PNG images.';
        }
        $selected[] = ['tmp' => $tmpName, 'ext' => $extension];
    }

    if (count($selected) > INSPECTION_PHOTO_LIMIT) {
        return 'Upload up to ' . INSPECTION_PHOTO_LIMIT . ' photos per inspection.';
    }
    if (!$selected) {
        return null;
    }

    if (!is_dir(INSPECTION_UPLOAD_DIR)) {
        mkdir(INSPECTION_UPLOAD_DIR, 0775, true);
    }

    $saved = [];
    try {
        foreach ($selected as $photo) {
            $filename = bin2hex(random_bytes(16)) . '.' . $photo['ext'];
            $target = INSPECTION_UPLOAD_DIR . '/' . $filename;
            if (!move_uploaded_file($photo['tmp'], $target)) {
                throw new RuntimeException('Could not store inspection photo.');
            }
            $saved[] = $filename;

            $stmt = db()->prepare('INSERT INTO tbl_inspection_photo (inspection_id, filename) VALUES (?, ?)');
            $stmt->bind_param('is', $inspectionId, $filename);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $exception) {
        foreach ($saved as $filename) {
            @unlink(INSPECTION_UPLOAD_DIR . '/' . $filename);
        }
        throw $exception;
    }

    return null;
}

function valet_collect_vehicle(int $valetId, int $bookingId, string $otp, string $fuel, int $odometer, string $condition, string $notes, array $photoFiles = []): ?string
{
    if (!preg_match('/^\d{4}$/', $otp)) {
        return 'Enter the 4-digit pickup PIN from the customer.';
    }

    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT booking_status FROM tbl_booking WHERE booking_id = ? AND valet_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $bookingId, $valetId);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking || $booking['booking_status'] !== 'ARRIVED') {
            $db->rollback();
            return 'Mark yourself arrived before collecting the vehicle.';
        }

        $stmt = $db->prepare("SELECT code, verified_at, attempts, locked_until FROM tbl_otp WHERE booking_id = ? AND otp_type = 'PARKING' LIMIT 1 FOR UPDATE");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $otpRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$otpRow || $otpRow['verified_at'] !== null) {
            $db->rollback();
            return 'That pickup PIN is not valid.';
        }
        if (($lock = lock_message($otpRow['locked_until'])) !== null) {
            $db->rollback();
            return $lock;
        }
        if (!hash_equals((string) $otpRow['code'], $otp)) {
            otp_register_failure($bookingId, 'PARKING', (int) $otpRow['attempts']);
            $db->commit();
            return 'That pickup PIN is not valid.';
        }

        $stage = 'PICKUP';
        $stmt = $db->prepare(
            'INSERT INTO tbl_vehicle_inspection (booking_id, stage, fuel_level, odometer, vehicle_condition, notes, valet_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ississi', $bookingId, $stage, $fuel, $odometer, $condition, $notes, $valetId);
        $stmt->execute();
        $inspectionId = $stmt->insert_id;
        $stmt->close();

        $photoError = store_inspection_photos($inspectionId, $photoFiles);
        if ($photoError) {
            $db->rollback();
            return $photoError;
        }

        $stmt = $db->prepare("UPDATE tbl_otp SET verified_at = NOW() WHERE booking_id = ? AND otp_type = 'PARKING'");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("UPDATE tbl_booking SET booking_status = 'COLLECTED' WHERE booking_id = ?");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (mysqli_sql_exception $exception) {
        $db->rollback();
        return $exception->getCode() === 1062
            ? 'The pickup inspection was already recorded.'
            : 'The pickup could not be recorded.';
    } catch (Throwable $exception) {
        $db->rollback();
        return 'The pickup could not be recorded.';
    }
}

function valet_park_vehicle(int $valetId, int $bookingId, string $slot, string $address, string $condition, string $notes): ?string
{
    if ($slot === '' || $address === '') {
        return 'Parking slot and parking address are required.';
    }
    if (!in_array($condition, ['Good', 'Fair', 'Damaged'], true)) {
        return 'Choose a valid parking condition.';
    }

    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT booking_status FROM tbl_booking WHERE booking_id = ? AND valet_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $bookingId, $valetId);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking || $booking['booking_status'] !== 'COLLECTED') {
            $db->rollback();
            return 'Collect the vehicle before parking it.';
        }

        $stmt = $db->prepare("UPDATE tbl_booking SET booking_status = 'PARKED', parking_slot = ?, parking_address = ? WHERE booking_id = ?");
        $stmt->bind_param('ssi', $slot, $address, $bookingId);
        $stmt->execute();
        $stmt->close();

        $stage = 'PARKED';
        $stmt = $db->prepare(
            'INSERT INTO tbl_vehicle_inspection (booking_id, stage, vehicle_condition, notes, valet_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssi', $bookingId, $stage, $condition, $notes, $valetId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("UPDATE tbl_parking_job SET status = 'COMPLETED', completed_at = NOW() WHERE booking_id = ? AND valet_id = ?");
        $stmt->bind_param('ii', $bookingId, $valetId);
        $stmt->execute();
        $stmt->close();

        $amount = VALET_PARKING_EARNING;
        $jobType = 'PARKING';
        $stmt = $db->prepare('INSERT IGNORE INTO tbl_earning (valet_id, booking_id, job_type, amount) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('iisd', $valetId, $bookingId, $jobType, $amount);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (Throwable $exception) {
        $db->rollback();
        return 'Parking could not be recorded.';
    }
}

function accept_return_job(int $valetId, int $bookingId): ?string
{
    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT is_live FROM tbl_valet WHERE valet_id = ? FOR UPDATE');
        $stmt->bind_param('i', $valetId);
        $stmt->execute();
        $valet = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$valet || (int) $valet['is_live'] !== 1) {
            $db->rollback();
            return 'Go online before accepting a return.';
        }

        if (valet_has_active_job($valetId)) {
            $db->rollback();
            return 'Finish your current job before accepting a return.';
        }

        $stmt = $db->prepare(
            "SELECT rj.return_job_id, rj.status, b.booking_status
             FROM tbl_return_job rj
             JOIN tbl_booking b ON b.booking_id = rj.booking_id
             WHERE rj.booking_id = ? FOR UPDATE"
        );
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$job || $job['status'] !== 'REQUESTED' || $job['booking_status'] !== 'RETURN_REQUESTED') {
            $db->rollback();
            return 'That return request is no longer available.';
        }

        $stmt = $db->prepare("UPDATE tbl_return_job SET valet_id = ?, status = 'ASSIGNED', assigned_at = NOW() WHERE return_job_id = ?");
        $stmt->bind_param('ii', $valetId, $job['return_job_id']);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("UPDATE tbl_booking SET booking_status = 'RETURNING' WHERE booking_id = ?");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (Throwable $exception) {
        $db->rollback();
        return 'The return could not be accepted.';
    }
}

function valet_return_arrived(int $valetId, int $bookingId, string $otp, string $fuel, int $odometer, string $condition, string $notes, array $photoFiles = []): ?string
{
    if (!preg_match('/^\d{4}$/', $otp)) {
        return 'Enter the 4-digit return PIN from the customer.';
    }

    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            "SELECT b.booking_status, rj.status AS return_status
             FROM tbl_booking b
             JOIN tbl_return_job rj ON rj.booking_id = b.booking_id
             WHERE b.booking_id = ? AND rj.valet_id = ? FOR UPDATE"
        );
        $stmt->bind_param('ii', $bookingId, $valetId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || $row['booking_status'] !== 'RETURNING' || $row['return_status'] !== 'ASSIGNED') {
            $db->rollback();
            return 'This return is not in transit.';
        }

        $stmt = $db->prepare("SELECT code, verified_at, attempts, locked_until FROM tbl_otp WHERE booking_id = ? AND otp_type = 'RETURN' LIMIT 1 FOR UPDATE");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $otpRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$otpRow || $otpRow['verified_at'] !== null) {
            $db->rollback();
            return 'That return PIN is not valid.';
        }
        if (($lock = lock_message($otpRow['locked_until'])) !== null) {
            $db->rollback();
            return $lock;
        }
        if (!hash_equals((string) $otpRow['code'], $otp)) {
            otp_register_failure($bookingId, 'RETURN', (int) $otpRow['attempts']);
            $db->commit();
            return 'That return PIN is not valid.';
        }

        $stage = 'RETURN';
        $stmt = $db->prepare(
            'INSERT INTO tbl_vehicle_inspection (booking_id, stage, fuel_level, odometer, vehicle_condition, notes, valet_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ississi', $bookingId, $stage, $fuel, $odometer, $condition, $notes, $valetId);
        $stmt->execute();
        $inspectionId = $stmt->insert_id;
        $stmt->close();

        $photoError = store_inspection_photos($inspectionId, $photoFiles);
        if ($photoError) {
            $db->rollback();
            return $photoError;
        }

        $stmt = $db->prepare("UPDATE tbl_otp SET verified_at = NOW() WHERE booking_id = ? AND otp_type = 'RETURN'");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("UPDATE tbl_booking SET booking_status = 'RETURN_ARRIVED' WHERE booking_id = ?");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (mysqli_sql_exception $exception) {
        $db->rollback();
        return $exception->getCode() === 1062
            ? 'The return inspection was already recorded.'
            : 'The return could not be recorded.';
    } catch (Throwable $exception) {
        $db->rollback();
        return 'The return could not be recorded.';
    }
}

function valet_complete_return(int $valetId, int $bookingId): ?string
{
    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            "SELECT b.booking_status, rj.return_job_id, rj.status AS return_status
             FROM tbl_booking b
             JOIN tbl_return_job rj ON rj.booking_id = b.booking_id
             WHERE b.booking_id = ? AND rj.valet_id = ? FOR UPDATE"
        );
        $stmt->bind_param('ii', $bookingId, $valetId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || $row['booking_status'] !== 'RETURN_ARRIVED' || $row['return_status'] !== 'ASSIGNED') {
            $db->rollback();
            return 'The return handover is not ready to complete.';
        }

        $stmt = $db->prepare("UPDATE tbl_booking SET booking_status = 'COMPLETED' WHERE booking_id = ?");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("UPDATE tbl_return_job SET status = 'COMPLETED', completed_at = NOW() WHERE return_job_id = ?");
        $stmt->bind_param('i', $row['return_job_id']);
        $stmt->execute();
        $stmt->close();

        $amount = VALET_RETURN_EARNING;
        $jobType = 'RETURN';
        $stmt = $db->prepare('INSERT IGNORE INTO tbl_earning (valet_id, booking_id, job_type, amount) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('iisd', $valetId, $bookingId, $jobType, $amount);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (Throwable $exception) {
        $db->rollback();
        return 'The job could not be completed.';
    }
}

function valet_earnings(int $valetId): array
{
    $stmt = db()->prepare(
        "SELECT
            COALESCE(SUM(amount), 0) AS total,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) AS today,
            COUNT(*) AS jobs
         FROM tbl_earning WHERE valet_id = ?"
    );
    $stmt->bind_param('i', $valetId);
    $stmt->execute();
    $totals = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS reserved
         FROM tbl_withdrawal WHERE valet_id = ? AND status IN ('PENDING', 'PAID')"
    );
    $stmt->bind_param('i', $valetId);
    $stmt->execute();
    $reserved = (float) $stmt->get_result()->fetch_assoc()['reserved'];
    $stmt->close();

    return [
        'total' => (float) $totals['total'],
        'today' => (float) $totals['today'],
        'jobs' => (int) $totals['jobs'],
        'available' => max(0, (float) $totals['total'] - $reserved),
    ];
}

function valet_earning_history(int $valetId, int $limit = 50): array
{
    $stmt = db()->prepare(
        "SELECT e.*, b.vehicle_number, b.vehicle_type, b.pickup_address, b.booking_status
         FROM tbl_earning e
         JOIN tbl_booking b ON b.booking_id = e.booking_id
         WHERE e.valet_id = ?
         ORDER BY e.created_at DESC
         LIMIT ?"
    );
    $stmt->bind_param('ii', $valetId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function valet_withdrawals(int $valetId): array
{
    $stmt = db()->prepare('SELECT * FROM tbl_withdrawal WHERE valet_id = ? ORDER BY created_at DESC LIMIT 30');
    $stmt->bind_param('i', $valetId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function request_withdrawal(int $valetId, float $amount, string $upiId): ?string
{
    if ($upiId === '') {
        return 'Add a UPI ID in your profile before withdrawing.';
    }
    if ($amount <= 0) {
        return 'Enter an amount greater than zero.';
    }

    $db = db();
    $db->begin_transaction();
    try {
        // Lock the account row so two requests cannot spend the same balance.
        $stmt = $db->prepare('SELECT valet_id FROM tbl_valet WHERE valet_id = ? FOR UPDATE');
        $stmt->bind_param('i', $valetId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$exists) {
            $db->rollback();
            return 'Valet profile not found.';
        }

        $stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM tbl_earning WHERE valet_id = ? FOR UPDATE');
        $stmt->bind_param('i', $valetId);
        $stmt->execute();
        $total = (float) $stmt->get_result()->fetch_row()[0];
        $stmt->close();

        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM tbl_withdrawal WHERE valet_id = ? AND status IN ('PENDING', 'PAID') FOR UPDATE");
        $stmt->bind_param('i', $valetId);
        $stmt->execute();
        $reserved = (float) $stmt->get_result()->fetch_row()[0];
        $stmt->close();

        $available = max(0, $total - $reserved);
        if ($amount > $available) {
            $db->rollback();
            return 'The amount is higher than your available balance of ' . money($available) . '.';
        }

        $stmt = db()->prepare("INSERT INTO tbl_withdrawal (valet_id, amount, status) VALUES (?, ?, 'PENDING')");
        $stmt->bind_param('id', $valetId, $amount);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return null;
    } catch (Throwable $exception) {
        $db->rollback();
        return 'The withdrawal request could not be saved. Please try again.';
    }
}

function update_valet_profile(int $valetId, int $loginId, string $fullName, string $phone, string $email, string $upiId): ?string
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
    if ($upiId !== '' && !filter_var($upiId, FILTER_VALIDATE_EMAIL) && !preg_match('/^[a-zA-Z0-9._-]{2,}@[a-zA-Z]{2,}$/', $upiId)) {
        return 'Enter a valid UPI ID, for example name@bank.';
    }

    $db = db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('UPDATE tbl_valet SET first_name = ?, last_name = ?, phone = ?, upi_id = ? WHERE valet_id = ?');
        $stmt->bind_param('ssssi', $first, $last, $phone, $upiId, $valetId);
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

function set_valet_online(int $valetId, bool $online): void
{
    $value = $online ? 1 : 0;
    $stmt = db()->prepare('UPDATE tbl_valet SET is_live = ? WHERE valet_id = ?');
    $stmt->bind_param('ii', $value, $valetId);
    $stmt->execute();
    $stmt->close();
}
