<?php
// Exchange Configuration
define('DUCO_RECIPIENT', 'katfaucet');  // Replace with your DUCO username
define('DUCO_PASSWORD', 'Stayout1');   // Replace with your DUCO password
define('ELAP_USERNAME', 'gamecat999');   // Replace with your ELAP username
define('ELAP_PASSWORD', 'Jbllc100');   // Replace with your ELAP password
define('MAX_DUCO_AMOUNT', 250);
define('MIN_DUCO_AMOUNT', 0.001);
define('TRANSACTION_FEE', 0.02); // 2%
define('EXCHANGE_TIMEOUT', 1800); // 30 minutes
define('COOLDOWN_HOURS', 12);

// Cookie file for ELAP session
define('COOKIE_FILE', __DIR__ . '/elap_cookies.txt');
