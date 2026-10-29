<?php
/**
 * Idempotent schema migration for the valet workflow.
 * Run: php database/migrate.php
 */
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';

$db = db();

function column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $dbName = DB_NAME;
    $stmt->bind_param('sss', $dbName, $table, $column);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count > 0;
}

if (!column_exists($db, 'tbl_return_job', 'return_latitude')) {
    $db->query('ALTER TABLE tbl_return_job ADD COLUMN return_latitude DECIMAL(10,8) NULL AFTER return_address');
    echo "Added tbl_return_job.return_latitude\n";
}
if (!column_exists($db, 'tbl_return_job', 'return_longitude')) {
    $db->query('ALTER TABLE tbl_return_job ADD COLUMN return_longitude DECIMAL(11,8) NULL AFTER return_latitude');
    echo "Added tbl_return_job.return_longitude\n";
}

if (!column_exists($db, 'tbl_login', 'failed_attempts')) {
    $db->query('ALTER TABLE tbl_login ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0');
    echo "Added tbl_login.failed_attempts\n";
}
if (!column_exists($db, 'tbl_login', 'locked_until')) {
    $db->query('ALTER TABLE tbl_login ADD COLUMN locked_until DATETIME NULL');
    echo "Added tbl_login.locked_until\n";
}
if (!column_exists($db, 'tbl_otp', 'attempts')) {
    $db->query('ALTER TABLE tbl_otp ADD COLUMN attempts INT NOT NULL DEFAULT 0');
    echo "Added tbl_otp.attempts\n";
}
if (!column_exists($db, 'tbl_otp', 'locked_until')) {
    $db->query('ALTER TABLE tbl_otp ADD COLUMN locked_until DATETIME NULL');
    echo "Added tbl_otp.locked_until\n";
}

$db->query(
    'CREATE TABLE IF NOT EXISTS tbl_inspection_photo (
        photo_id INT NOT NULL AUTO_INCREMENT,
        inspection_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (photo_id),
        KEY idx_photo_inspection (inspection_id),
        CONSTRAINT fk_photo_inspection FOREIGN KEY (inspection_id)
            REFERENCES tbl_vehicle_inspection (inspection_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
echo "Ensured tbl_inspection_photo\n";

$db->query(
    'CREATE TABLE IF NOT EXISTS tbl_job_decline (
        decline_id INT NOT NULL AUTO_INCREMENT,
        valet_id INT NOT NULL,
        booking_id INT NOT NULL,
        job_type VARCHAR(10) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (decline_id),
        UNIQUE KEY uq_decline_valet_booking_type (valet_id, booking_id, job_type),
        KEY idx_decline_booking (booking_id),
        CONSTRAINT fk_decline_valet FOREIGN KEY (valet_id)
            REFERENCES tbl_valet (valet_id) ON DELETE CASCADE,
        CONSTRAINT fk_decline_booking FOREIGN KEY (booking_id)
            REFERENCES tbl_booking (booking_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
echo "Ensured tbl_job_decline\n";

if (!is_dir(INSPECTION_UPLOAD_DIR)) {
    mkdir(INSPECTION_UPLOAD_DIR, 0775, true);
    echo "Created storage/inspections\n";
}

echo "Migration complete.\n";
