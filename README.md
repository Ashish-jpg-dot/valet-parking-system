# ValetFlow — Valet Parking Management System

A PHP + MySQL web application for on-demand valet parking. Customers book a
valet, follow the car through pickup, parking, payment and return; valets accept
jobs and record both handovers; admins manage accounts, jobs, payments and
complaints.

## Stack

- PHP 8.1+ with `mysqli` and `mbstring`
- MySQL 8
- Plain HTML, CSS and JavaScript (no framework, no build step)
- Leaflet with OpenStreetMap tiles for the map pickers

## Run locally

1. Make sure the MySQL database `db_valet_parking_system` exists with the
   project tables (import the database dump submitted with the project).
2. Check the connection settings in `app/config.php`
   (defaults: `localhost`, port `3307`, user `root`, password `root`).
3. Run the additive migration (safe to re-run):

   ```
   php database/migrate.php
   ```

4. Load the demo data (also safe to re-run):

   ```
   php database/seed.php
   ```

5. Start the built-in PHP server from the project root:

   ```
   php -S localhost:8000 -t public
   ```

6. Open <http://localhost:8000>.

The app needs `upload_max_filesize` of at least `2M` in `php.ini`; the current
upload limit is 2 MB per file.

## Demo accounts

| Role     | Email                      | Password     |
|----------|----------------------------|--------------|
| Admin    | `admin@valetflow.local`    | `Admin@123`  |
| Customer | `vikram@valetflow.local`   | `Customer@123` |
| Customer | `priya@valetflow.local`    | `Customer@123` |
| Customer | `ananya@valetflow.local`   | `Customer@123` |
| Valet    | `marcus@valetflow.local`   | `Valet@123`  |
| Valet    | `rahul@valetflow.local`    | `Valet@123` (pending approval) |

## Fixes applied after the QA audit

| # | Finding | Fix |
|---|---------|-----|
| 1 | Stored XSS in Leaflet popups | Popup labels are inserted as text, never as HTML |
| 2 | Two active bookings from a double submit | Booking creation runs in a transaction that locks the customer row and re-checks active bookings |
| 3 | Withdrawals could pay out more than the balance | Withdrawal runs in a transaction that locks the account and recomputes the available balance |
| 4 | A valet could hold a parking and a return job at once | The busy check looks at both parking and return assignments |
| 5 | Past pickup times and returns before pickup were accepted | The server rejects a pickup that is not in the future and a return that is not after pickup; the form inputs carry the same minimums |
| 6 | No brute-force protection on login or handover PINs | 5 failures lock the account or the PIN for 15 minutes |
| 7 | Admin actions on fake IDs flashed success | Admin actions check affected rows and report an error when nothing changed |
| 8 | UI promised 5 MB / 10 MB uploads but PHP allowed 2 MB | App limits lowered to 2 MB and all hints and error messages updated |
| 9 | Seven Leaflet maps initialised eagerly in admin dialogs | Each dialog map is created the first time the dialog opens |
| 10 | Missing security headers and unconditional cookie flags | Added CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`; the session cookie is `Secure` on HTTPS |
| 11 | PHP errors displayed to users | `APP_DEBUG` is off by default in `app/config.php` |
| 12 | Inline `onsubmit` handler on the cancel-booking form | Replaced with a `data-confirm` attribute handled by shared JavaScript |
| 13 | Active dashboard tabs had no visible `h1` | The active customer and valet panels each expose exactly one `h1` |

The lockout columns (`failed_attempts`, `locked_until` on `tbl_login`;
`attempts`, `locked_until` on `tbl_otp`) are added by `database/migrate.php`.

## Notes and known limits

- The demo uses public OpenStreetMap tiles and the public Nominatim search API.
  A production deployment should use a commercial or self-hosted map/tile
  provider and follow its usage policy.
- Upload limits: 2 MB per file, up to 3 inspection photos per handover.
- The session cookie is marked `Secure` only when the app is served over HTTPS.
- Logout is still a GET link and admin tables are not paginated; both were
  deliberately left out of this round of fixes.
