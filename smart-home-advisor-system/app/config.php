<?php
declare(strict_types=1);

define('APP_NAME', 'Smart Home Advisor');

// 1. Dynamically determine the protocol (http or https)
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";

// 2. Dynamically determine the host (e.g., localhost or a1b2-34-56.ngrok-free.app)
$host = $_SERVER['HTTP_HOST'];

// 3. Combine them into your APP_URL definition dynamically
define('APP_URL', $protocol . '://' . $host . '/smart-home-advisor-system/public');

// Database settings stay the same (ngrok tunnels HTTP traffic, your database stays local)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'smart_home_advisor');
define('DB_USER', 'root');
define('DB_PASS', '');
define('SESSION_TIMEOUT', 1800);

date_default_timezone_set('Asia/Kuala_Lumpur');