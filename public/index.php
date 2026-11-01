<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/stats.php';

$currentUser = current_user();
if ($currentUser) {
    redirect(dashboard_url($currentUser['role']));
}
$stats = landing_stats();
$currentYear = date('Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcf9f8">
    <meta name="description" content="ValetFlow connects you with verified valets who collect, park and return your vehicle. Book a valet from <?= e(money(BASE_FARE)) ?> per booking.">
    <title>ValetFlow — Parking Made Simple</title>
    <link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/layout.css">
<link rel="stylesheet" href="assets/css/components.css">
<link rel="stylesheet" href="assets/css/pages/landing.css">
</head>
<body>
    <svg class="icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <symbol id="icon-arrow" viewBox="0 0 24 24">
            <path d="M5 12h14M13 5l7 7-7 7"></path>
        </symbol>
        <symbol id="icon-key" viewBox="0 0 24 24">
            <circle cx="8" cy="15" r="4"></circle>
            <path d="m11 12 8-8 2 2-2 2 2 2-3 3-2-2-2 2"></path>
        </symbol>
        <symbol id="icon-check-shield" viewBox="0 0 24 24">
            <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"></path>
            <path d="m9 12 2 2 4-4"></path>
        </symbol>
        <symbol id="icon-shield" viewBox="0 0 24 24">
            <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"></path>
        </symbol>
        <symbol id="icon-car" viewBox="0 0 24 24">
            <path d="m5 11 1.5-5h11l1.5 5M3 11h18v8H3zM6 19v2m12-2v2M7 15h.01M17 15h.01M5 11l-2 3m16-3 2 3"></path>
        </symbol>
        <symbol id="icon-garage" viewBox="0 0 24 24">
            <path d="M3 10 12 3l9 7v11H3zM7 21v-8h10v8M7 16h10m-10 3h10"></path>
        </symbol>
        <symbol id="icon-lock" viewBox="0 0 24 24">
            <rect x="4" y="10" width="16" height="11" rx="2"></rect>
            <path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"></path>
        </symbol>
        <symbol id="icon-badge" viewBox="0 0 24 24">
            <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"></path>
            <path d="m9 12 2 2 4-4"></path>
        </symbol>
        <symbol id="icon-menu" viewBox="0 0 24 24">
            <path d="M4 7h16M4 12h16M4 17h16"></path>
        </symbol>
        <symbol id="icon-close" viewBox="0 0 24 24">
            <path d="m6 6 12 12M18 6 6 18"></path>
        </symbol>
    </svg>

    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="#top" aria-label="ValetFlow home">
                <span class="brand-mark" aria-hidden="true">V</span>
                <span class="brand-name">ValetFlow</span>
            </a>

            <nav class="primary-nav" id="primary-navigation" aria-label="Main navigation">
                <a href="#how-it-works" aria-current="page">How It Works</a>
                <a href="#pricing">Pricing</a>
                <a href="#fleet-safety">Fleet Safety</a>
            </nav>

            <div class="account-actions">
                <a class="login-button" href="login.php">Log In</a>
                <a class="signup-button" href="register.php">Sign up</a>
                <button class="menu-toggle" type="button" aria-label="Open navigation" aria-controls="primary-navigation" aria-expanded="false">
                    <svg class="icon" aria-hidden="true"><use href="#icon-menu"></use></svg>
                </button>
            </div>
        </div>
    </header>

    <main id="top">
        <section class="hero" aria-labelledby="hero-title">
            <div class="container hero-grid">
                <div class="hero-copy">
                    <span class="eyebrow eyebrow-status"><span class="status-dot" aria-hidden="true"></span>Live network · <?= (int) $stats['online_valets'] ?> valet<?= $stats['online_valets'] === 1 ? '' : 's' ?> online</span>
                    <h1 id="hero-title">On-demand valet parking, seamless and secure.</h1>
                    <p class="hero-lead">ValetFlow — Parking Made Simple</p>
                    <p class="hero-description">ValetFlow connects you with a verified valet who collects your car, parks it and returns it when you ask. Book from <?= e(money(BASE_FARE)) ?> per booking, anywhere the service is available.</p>
                    <div class="hero-actions">
                        <a class="primary-button" href="register.php">
                            Book a Valet
                            <svg class="icon" aria-hidden="true"><use href="#icon-arrow"></use></svg>
                        </a>
                    </div>
                    <ul class="hero-metrics" aria-label="ValetFlow highlights">
                        <li><svg class="icon" aria-hidden="true"><use href="#icon-check-shield"></use></svg><?= (int) $stats['verified_valets'] ?> Verified Valet<?= $stats['verified_valets'] === 1 ? '' : 's' ?></li>
                        <li class="metric-separator" aria-hidden="true"></li>
                        <li><svg class="icon" aria-hidden="true"><use href="#icon-shield"></use></svg><?= (int) $stats['completed_bookings'] ?> Completed Booking<?= $stats['completed_bookings'] === 1 ? '' : 's' ?></li>
                        <li class="metric-separator" aria-hidden="true"></li>
                        <li><svg class="icon" aria-hidden="true"><use href="#icon-badge"></use></svg><?= (int) $stats['active_bookings'] ?> Active Now</li>
                    </ul>
                </div>

                <div class="pass-card" aria-label="ValetFlow Pass preview">
                    <div class="pass-card-top">
                        <span class="pass-key" aria-hidden="true"><svg class="icon"><use href="#icon-key"></use></svg></span>
                        <span class="pass-badge"><svg class="icon" aria-hidden="true"><use href="#icon-check-shield"></use></svg>Digital Fob</span>
                    </div>
                    <div class="pass-center">
                        <span class="parking-mark" aria-hidden="true">P<span class="parking-key"><svg class="icon"><use href="#icon-key"></use></svg></span></span>
                        <strong class="pass-title">ValetFlow Pass</strong>
                        <p class="pass-subtitle">Smart Handover &amp; Recorded Inspection</p>
                    </div>
                    <div class="pass-card-bottom">
                        <span class="bay-status"><span class="status-dot" aria-hidden="true"></span><?= (int) $stats['online_valets'] ?> valet<?= $stats['online_valets'] === 1 ? '' : 's' ?> online</span>
                        <span class="pass-price"><?= e(money(BASE_FARE)) ?> / Booking</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="trust-strip" id="fleet-safety" aria-label="Trust and security features">
            <div class="container trust-grid">
                <article class="trust-card">
                    <span class="trust-icon" aria-hidden="true"><svg class="icon"><use href="#icon-badge"></use></svg></span>
                    <div>
                        <h3>Verified Valets</h3>
                        <p>Each valet account is reviewed and approved by the admin team before receiving jobs.</p>
                    </div>
                </article>
                <article class="trust-card">
                    <span class="trust-icon" aria-hidden="true"><svg class="icon"><use href="#icon-garage"></use></svg></span>
                    <div>
                        <h3>Recorded Handovers</h3>
                        <p>Condition, odometer, fuel level and photos are captured at pickup and return.</p>
                    </div>
                </article>
                <article class="trust-card">
                    <span class="trust-icon" aria-hidden="true"><svg class="icon"><use href="#icon-lock"></use></svg></span>
                    <div>
                        <h3>Encrypted Handover PIN</h3>
                        <p>Separate pickup and return PINs are verified before the vehicle changes hands.</p>
                    </div>
                </article>
            </div>
        </section>

        <section class="section" id="how-it-works" aria-labelledby="steps-title">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow">Workflow Architecture</span>
                    <h2 id="steps-title">How ValetFlow Works</h2>
                    <p>A smooth three-step sequence engineered for precision and peace of mind.</p>
                </div>
                <div class="steps-grid">
                    <article class="step-card">
                        <div>
                            <div class="step-card-top">
                                <span class="step-number">1</span>
                                <span class="step-tag">Curbside Pickup</span>
                            </div>
                            <h3>1. Request &amp; Schedule</h3>
                            <p>Choose your vehicle, pickup point and pickup time. The return time and destination are requested later, when you are ready.</p>
                        </div>
                        <ul class="step-features">
                            <li>Pickup Handover PIN</li>
                        </ul>
                    </article>
                    <article class="step-card">
                        <div>
                            <div class="step-card-top">
                                <span class="step-number">2</span>
                                <span class="step-tag">Inspection &amp; Parking</span>
                            </div>
                            <h3>2. Secure Park &amp; Storage</h3>
                            <p>A verified valet inspects the vehicle, records the handover and parks it at the authorized parking location.</p>
                        </div>
                        <ul class="step-features">
                            <li>Condition, Fuel &amp; Odometer Recorded</li>
                            <li>Up to Three Inspection Photos</li>
                        </ul>
                    </article>
                    <article class="step-card">
                        <div>
                            <div class="step-card-top">
                                <span class="step-number">3</span>
                                <span class="step-tag">Return on Request</span>
                            </div>
                            <h3>3. On-Demand Return</h3>
                            <p>Pay the parking invoice, request the return with your preferred time and destination, then verify the return PIN.</p>
                        </div>
                        <ul class="step-features">
                            <li>Separate Return PIN</li>
                            <li>Recorded Return Inspection</li>
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        <section class="section pricing-section" id="pricing" aria-labelledby="pricing-title">
            <div class="container">
                <div class="pricing-heading">
                    <span class="eyebrow">Fair Economics</span>
                    <h2 id="pricing-title">Simple, Transparent Pricing</h2>
                    <p>No hidden surge calculations or complicated tiers. Fixed rates for customers and predictable earnings for valets.</p>
                </div>
                <div class="pricing-grid">
                    <article class="price-card">
                        <p class="price-label">Customer booking</p>
                        <p class="price"><?= e(money(BASE_FARE)) ?> <small>/ booking</small></p>
                        <p class="price-detail">Includes pickup, parking and a recorded inspection at every handover. Fuel, when requested, is billed later.</p>
                    </article>
                    <article class="price-card">
                        <p class="price-label">Valet earnings</p>
                        <p class="price"><?= e(money(VALET_PARKING_EARNING)) ?> <small>/ parking job</small></p>
                        <p class="price-detail">Parking and return jobs each pay <?= e(money(VALET_PARKING_EARNING)) ?> and <?= e(money(VALET_RETURN_EARNING)) ?> respectively.</p>
                    </article>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <div>
                    <a class="brand footer-brand" href="#top" aria-label="ValetFlow home">
                        <span class="brand-mark" aria-hidden="true"><svg class="icon"><use href="#icon-car"></use></svg></span>
                        <span class="brand-name">ValetFlow</span>
                    </a>
                    <h2 class="footer-about-title">About ValetFlow</h2>
                    <p class="footer-description">On-demand valet parking that connects customers with verified valets across cities, towns and neighbourhoods.</p>
                    <ul class="footer-about-list">
                        <li><svg class="icon" aria-hidden="true"><use href="#icon-check-shield"></use></svg>Verified, admin-approved valets</li>
                        <li><svg class="icon" aria-hidden="true"><use href="#icon-garage"></use></svg>Recorded inspections at every handover</li>
                    </ul>
                    <span class="dispatch-status"><span class="status-dot" aria-hidden="true"></span><?= (int) $stats['online_valets'] ?> valet<?= $stats['online_valets'] === 1 ? '' : 's' ?> online</span>
                </div>
                <nav class="footer-links" aria-label="Platform links">
                    <h2>Platform</h2>
                    <a href="#how-it-works">How It Works</a>
                    <a href="#fleet-safety">Fleet Safety</a>
                    <a href="#pricing">Pricing Plans</a>
                </nav>
                <section class="valet-card" aria-labelledby="valet-title">
                    <span class="partner-badge"><svg class="icon" aria-hidden="true"><use href="#icon-badge"></use></svg>Admin Verified Partner</span>
                    <h2 id="valet-title">Become a Valet</h2>
                    <p>Join our verified driver network and earn <?= e(money(VALET_PARKING_EARNING)) ?> per parking job and <?= e(money(VALET_RETURN_EARNING)) ?> per return job, with flexible availability.</p>
                    <a class="light-button" href="valet-register.php">
                        Apply as Valet
                        <svg class="icon" aria-hidden="true"><use href="#icon-arrow"></use></svg>
                    </a>
                </section>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?= htmlspecialchars($currentYear, ENT_QUOTES, 'UTF-8') ?> ValetFlow Mobility Systems Inc. All rights reserved.</p>
                <nav class="footer-legal" aria-label="Policies">
                    <a href="#fleet-safety">Privacy Architecture</a>
                    <a href="#pricing">Terms of Transit</a>
                    <a href="#fleet-safety">Security Protocol</a>
                </nav>
            </div>
        </div>
    </footer>

    <div class="notice" role="status" aria-live="polite" hidden></div>

    
<script src="assets/js/core.js" defer></script>
<script src="assets/js/landing.js" defer></script>
</body>
</html>
