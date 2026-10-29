<?php
require_once __DIR__ . '/config.php';

/**
 * Shared mysqli connection (one per request).
 */
function db(): mysqli
{
    static $connection = null;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        $connection->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $exception) {
        http_response_code(500);
        exit('Database connection failed. Check that MySQL is running on port ' . DB_PORT . '.');
    }

    return $connection;
}
