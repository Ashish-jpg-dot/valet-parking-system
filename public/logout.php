<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

logout_user();
flash('success', 'You have been signed out.');
redirect('login.php');
