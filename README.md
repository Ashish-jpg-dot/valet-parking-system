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

## Notes and known limits

- The demo uses public OpenStreetMap tiles and the public Nominatim search API.
  A production deployment should use a commercial or self-hosted map/tile
  provider and follow its usage policy.
- Upload limits: 2 MB per file, up to 3 inspection photos per handover.
- The session cookie is marked `Secure` only when the app is served over HTTPS.
- Logout is still a GET link and admin tables are not paginated; both were
  deliberately left out of this round of fixes.
