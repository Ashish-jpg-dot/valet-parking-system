<?php
/**
 * Application configuration. Everything environment-specific lives here.
 */

const DB_HOST = 'localhost';
const DB_PORT = 3307;
const DB_USER = 'root';
const DB_PASS = 'root';
const DB_NAME = 'db_valet_parking_system';

const APP_NAME = 'ValetFlow';
const BASE_FARE = 500.00;
const FUEL_FARE = 1500.00;
const VALET_PARKING_EARNING = 200.00;
const VALET_RETURN_EARNING = 200.00;

const UPLOAD_DIR = __DIR__ . '/../storage/licenses';
const UPLOAD_MAX_BYTES = 2 * 1024 * 1024;

const INSPECTION_UPLOAD_DIR = __DIR__ . '/../storage/inspections';
const INSPECTION_PHOTO_MAX_BYTES = 2 * 1024 * 1024;
const INSPECTION_PHOTO_LIMIT = 3;

// Sign-in and handover-PIN lockout: 5 failures lock the account/PIN for 15 minutes.
const AUTH_MAX_ATTEMPTS = 5;
const AUTH_LOCK_MINUTES = 15;

// Set to true only while debugging locally. Keep false for the demo/submission.
const APP_DEBUG = false;
ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

// Fallback map centre when the browser declines geolocation.
const MAP_DEFAULT_LAT = 19.0760;
const MAP_DEFAULT_LNG = 72.8777;
const MAP_DEFAULT_ZOOM = 12;

const ACTIVE_BOOKING_STATUSES = [
    'SEARCHING', 'ASSIGNED', 'ARRIVED', 'COLLECTED', 'PARKED',
    'PAID', 'RETURN_REQUESTED', 'RETURNING', 'RETURN_ARRIVED',
];

date_default_timezone_set('Asia/Kolkata');

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header(
    "Content-Security-Policy: default-src 'self'; "
    . "img-src 'self' data: https://*.tile.openstreetmap.org; "
    . "style-src 'self' 'unsafe-inline'; script-src 'self'; "
    . "connect-src 'self' https://nominatim.openstreetmap.org; "
    . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $https,
    ]);
    session_start();
}
