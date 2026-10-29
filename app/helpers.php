<?php
require_once __DIR__ . '/config.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function old(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function post_string(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function post_int(string $key, int $default = 0): int
{
    return isset($_POST[$key]) && is_numeric($_POST[$key]) ? (int) $_POST[$key] : $default;
}

function post_float(string $key): ?float
{
    $value = post_string($key);
    return $value !== '' && is_numeric($value) ? (float) $value : null;
}

function valid_coordinates(?float $latitude, ?float $longitude): bool
{
    return $latitude !== null && $longitude !== null
        && is_finite($latitude) && is_finite($longitude)
        && $latitude >= -90 && $latitude <= 90
        && $longitude >= -180 && $longitude <= 180;
}

function inspection_photo_url(int $photoId): string
{
    return 'inspection-photo.php?id=' . $photoId;
}

function inspection_stage_label(string $stage): string
{
    return match ($stage) {
        'PICKUP' => 'Pickup inspection',
        'PARKED' => 'Parking condition',
        'RETURN' => 'Return inspection',
        default => ucfirst(strtolower($stage)),
    };
}

function inspection_summary(array $inspections): string
{
    if (!$inspections) {
        return '<p class="muted-copy">No inspection records yet.</p>';
    }

    $html = '<div class="inspection-list">';
    foreach ($inspections as $inspection) {
        $stage = inspection_stage_label((string) $inspection['stage']);
        $html .= '<article class="inspection-item"><div class="inspection-head"><strong>' . e($stage) . '</strong><span>' . e(format_ts($inspection['created_at'])) . '</span></div>';

        $facts = [];
        if (!empty($inspection['vehicle_condition'])) {
            $facts[] = 'Condition: ' . $inspection['vehicle_condition'];
        }
        if ($inspection['fuel_level'] !== null && $inspection['fuel_level'] !== '') {
            $facts[] = 'Fuel: ' . $inspection['fuel_level'];
        }
        if ($inspection['odometer'] !== null) {
            $facts[] = 'Odometer: ' . number_format((int) $inspection['odometer']) . ' km';
        }
        if ($facts) {
            $html .= '<p class="inspection-meta">' . e(implode(' · ', $facts)) . '</p>';
        }
        if (!empty($inspection['notes'])) {
            $html .= '<p class="inspection-notes">' . e($inspection['notes']) . '</p>';
        }

        $photos = $inspection['photos'] ?? [];
        if ($photos) {
            $html .= '<div class="inspection-photos">';
            foreach ($photos as $index => $photo) {
                $html .= '<a class="inspection-photo" href="' . e(inspection_photo_url((int) $photo['photo_id'])) . '" target="_blank" rel="noopener">'
                    . '<img src="' . e(inspection_photo_url((int) $photo['photo_id'])) . '" alt="' . e($stage . ' photo ' . ($index + 1)) . '" loading="lazy">'
                    . '</a>';
            }
            $html .= '</div>';
        }
        $html .= '</article>';
    }

    return $html . '</div>';
}

function booking_ref($bookingId): string
{
    return '#VF-' . str_pad((string) $bookingId, 4, '0', STR_PAD_LEFT);
}

function status_label(string $status): string
{
    $labels = [
        'SEARCHING' => 'Searching for valet',
        'ASSIGNED' => 'Valet assigned',
        'ARRIVED' => 'Valet arrived',
        'COLLECTED' => 'Vehicle collected',
        'PARKED' => 'Vehicle parked',
        'PAID' => 'Paid',
        'RETURN_REQUESTED' => 'Return requested',
        'RETURNING' => 'Returning vehicle',
        'RETURN_ARRIVED' => 'Return arrived',
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled',
    ];
    return $labels[$status] ?? ucwords(strtolower(str_replace('_', ' ', $status)));
}

function status_tone(string $status): string
{
    if (in_array($status, ['COMPLETED', 'PAID'], true)) {
        return 'success';
    }
    if (in_array($status, ['CANCELLED'], true)) {
        return 'danger';
    }
    if (in_array($status, ['SEARCHING', 'RETURN_REQUESTED'], true)) {
        return 'warning';
    }
    return 'neutral';
}

function format_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d M Y', $timestamp) : $date;
}

function format_time(?string $time): string
{
    if (!$time) {
        return '—';
    }
    $timestamp = strtotime($time);
    return $timestamp ? date('g:i A', $timestamp) : $time;
}

function format_datetime(?string $date, ?string $time): string
{
    if (!$date) {
        return '—';
    }
    return format_date($date) . ' · ' . format_time($time);
}

function format_ts(?string $timestamp): string
{
    if (!$timestamp) {
        return '—';
    }
    $value = strtotime($timestamp);
    return $value ? date('d M Y, g:i A', $value) : $timestamp;
}

function parking_slot_label(?string $slot): string
{
    $slot = trim((string) $slot);
    if ($slot === '') {
        return '';
    }
    if (preg_match('/^vault\s+(.+)$/i', $slot, $matches)) {
        return 'Bay ' . $matches[1];
    }
    return preg_match('/\b(bay|slot)\b/i', $slot) ? $slot : 'Bay ' . $slot;
}

function dashboard_url(string $role): string
{
    return match ($role) {
        'admin' => 'admin-dashboard.php',
        'valet' => 'valet-dashboard.php',
        default => 'customer-dashboard.php',
    };
}

function full_name(string $first, ?string $last = ''): string
{
    return trim($first . ' ' . ($last ?? ''));
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $letters !== '' ? $letters : 'VF';
}

function random_otp(): string
{
    return (string) random_int(1000, 9999);
}

/**
 * Message for a temporary lock, or null when the lock has expired.
 */
function lock_message(?string $lockedUntil): ?string
{
    if ($lockedUntil === null || strtotime($lockedUntil) <= time()) {
        return null;
    }
    $minutes = max(1, (int) ceil((strtotime($lockedUntil) - time()) / 60));
    return 'Too many failed attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
}

/**
 * Detect an allowed upload type from file signatures.
 * Returns the storage extension, or null when the file is not PDF/JPG/PNG.
 */
function detected_upload_extension(string $path): ?string
{
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return null;
    }
    $signature = (string) fread($handle, 8);
    fclose($handle);

    if (str_starts_with($signature, '%PDF-')) {
        return 'pdf';
    }
    if (str_starts_with($signature, "\xFF\xD8\xFF")) {
        return 'jpg';
    }
    if (str_starts_with($signature, "\x89PNG\r\n\x1A\n")) {
        return 'png';
    }
    return null;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}
