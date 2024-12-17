<?php
// Exchange Configuration
define('DUCO_RECIPIENT', 'DUCO_USERNAMEHERE');  // Replace with your DUCO username
define('DUCO_PASSWORD', 'DUCOPASSWORDHERE');   // Replace with your DUCO password
define('ELAP_USERNAME', 'ELAPTACOINUSERNAMEHERE');   // Replace with your ELAP username
define('ELAP_PASSWORD', 'ELAPPASSWORDHERE');   // Replace with your ELAP password
define('MAX_DUCO_AMOUNT', 250);
define('MIN_DUCO_AMOUNT', 0.001);
define('TRANSACTION_FEE', 0.02); // 2%
define('EXCHANGE_TIMEOUT', 1800); // 30 minutes
define('COOLDOWN_HOURS', 12);

// Cookie file for ELAP session
define('COOKIE_FILE', __DIR__ . '/elap_cookies.txt');
