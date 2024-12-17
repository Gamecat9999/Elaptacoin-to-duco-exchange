<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

ini_set('session.gc_maxlifetime', 1800);
session_set_cookie_params(1800);
session_start();

require_once 'functions.php';
if (isset($_POST['initial_submit'])) {
    $cooldown_file = 'cooldowns.txt';
    $from_username = $_POST['from_username'];
    $to_username = $_POST['to_username'];
    
    // Read cooldowns
    $cooldowns = [];
    if (file_exists($cooldown_file)) {
        $cooldowns = json_decode(file_get_contents($cooldown_file), true) ?: [];
    }
    
    // Check if either username is on cooldown
    $cooldown_check = function($username) use ($cooldowns) {
        if (isset($cooldowns[$username])) {
            $last_exchange = $cooldowns[$username];
            $cooldown_seconds = 12 * 3600; // 12 hours in seconds
            $time_passed = time() - $last_exchange;
            
            if ($time_passed < $cooldown_seconds) {
                $hours_remaining = floor(($cooldown_seconds - $time_passed) / 3600);
                $minutes_remaining = floor(($cooldown_seconds - $time_passed - ($hours_remaining * 3600)) / 60);
                
                if ($hours_remaining > 0) {
                    $time_message = "{$hours_remaining} hours";
                    if ($minutes_remaining > 0) {
                        $time_message .= " and {$minutes_remaining} minutes";
                    }
                } else {
                    $time_message = "{$minutes_remaining} minutes";
                }
                
                return [
                    'on_cooldown' => true,
                    'time_message' => $time_message,
                    'username' => $username
                ];
            }
        }
        return ['on_cooldown' => false];
    };
    
    // Check both usernames
    $from_cooldown = $cooldown_check($from_username);
    $to_cooldown = $cooldown_check($to_username);
    
    if ($from_cooldown['on_cooldown']) {
        $_SESSION['error'] = "The sending account ({$from_cooldown['username']}) must wait {$from_cooldown['time_message']} before making another exchange.";
        header("Location: exchange.php");
        exit;
    }
    
    if ($to_cooldown['on_cooldown']) {
        $_SESSION['error'] = "The receiving account ({$to_cooldown['username']}) must wait {$to_cooldown['time_message']} before making another exchange.";
        header("Location: exchange.php");
        exit;
    }
}

// Get current rates - Move this to the top
$duco_price = get_duco_price();
$elap_price = get_elap_price();
$exchange_rate = calculate_exchange_rate($duco_price, $elap_price);
define('DUCO_TO_ELAP_RATE', $exchange_rate);

$message = '';
$waiting_for_verification = false;
$unique_memo = '';

// Handle AJAX verification checks
if (isset($_GET['check_verification']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['exchange_data'])) {
        echo json_encode(['status' => 'error', 'message' => 'Session expired. Please start over.']);
        exit;
    }

    $exchange_data = $_SESSION['exchange_data'];
    
    if ($exchange_data['from_coin'] === 'ELAP') {
        $sender_current_balance = check_elap_balance($exchange_data['from_username']);
        $faucet_current_balance = check_elap_balance(ELAP_USERNAME);
        
        error_log("Checking balances - Sender: $sender_current_balance, Faucet: $faucet_current_balance");
        
        if ($sender_current_balance === null || $faucet_current_balance === null) {
            echo json_encode(['status' => 'error', 'message' => 'Error checking ELAP balances. Will retry...']);
            exit;
        }

        $initial_sender_balance = $exchange_data['initial_sender_balance'];
        $initial_faucet_balance = $exchange_data['initial_faucet_balance'];
        
        $expected_amount = $exchange_data['amount'] * (1 + TRANSACTION_FEE);
        $sender_balance_change = $initial_sender_balance - $sender_current_balance;
        $faucet_balance_change = $faucet_current_balance - $initial_faucet_balance;
        
        error_log("Balance changes - Sender: $sender_balance_change, Faucet: $faucet_balance_change, Expected: $expected_amount");
        
        $tolerance = 0.0001;
        
        if (abs($sender_balance_change - $expected_amount) <= $tolerance && 
            abs($faucet_balance_change - $expected_amount) <= $tolerance) {
            
            $duco_amount = ($exchange_data['amount'] / DUCO_TO_ELAP_RATE);
            
            if ($duco_amount <= MAX_DUCO_AMOUNT) {
                $result = send_duco(
                    $exchange_data['to_username'],
                    $duco_amount,
                    'ELAP Exchange'
                );
                
                error_log("DUCO send result: " . print_r($result, true));
                if (isset($result['success']) && $result['success']) {
                    // Update cooldown
                    $cooldown_file = 'cooldowns.txt';
                    $cooldowns = [];
                    if (file_exists($cooldown_file)) {
                        $cooldowns = json_decode(file_get_contents($cooldown_file), true) ?: [];
                    }
                    
                    // Update both users' cooldowns
                    $cooldowns[$exchange_data['from_username']] = time();
                    $cooldowns[$exchange_data['to_username']] = time();
                    
                    // Clean up old cooldowns (older than 24 hours)
                    foreach ($cooldowns as $user => $time) {
                        if (time() - $time > 86400) { // 24 hours
                            unset($cooldowns[$user]);
                        }
                    }
                    
                    // Save cooldowns
                    file_put_contents($cooldown_file, json_encode($cooldowns));
                    
                    // Continue with your existing success handling...
                
                


                    unset($_SESSION['exchange_data']);
                    echo json_encode([
                        'status' => 'success',
                        'message' => "Exchange successful! Received " . 
                                   number_format($expected_amount, 4) . 
                                   " ELAP and sent " . 
                                   number_format($duco_amount, 6) . " DUCO"
                    ]);
                    exit;
                } else {
                    $error_message = isset($result['message']) ? $result['message'] : 'Unknown error';
                    echo json_encode([
                        'status' => 'error',
                        'message' => "ELAP received but DUCO transfer failed. Error: " . $error_message
                    ]);
                    exit;
                }
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => "Error: Transaction exceeds maximum DUCO limit of " . MAX_DUCO_AMOUNT
                ]);
                exit;
            }
            
        } else {
            if ($sender_balance_change < $expected_amount) {
                $amount_needed = $expected_amount - $sender_balance_change;
                echo json_encode([
                    'status' => 'waiting',
                    'message' => "Waiting for payment. Please send " . 
                               number_format($amount_needed, 4) . " more ELAP to " . 
                               ELAP_USERNAME . 
                               "<br>Detected: " . number_format($sender_balance_change, 4) . 
                               " ELAP of " . number_format($expected_amount, 4) . " ELAP required"
                ]);
                exit;
            } else if ($faucet_balance_change < $expected_amount) {
                echo json_encode([
                    'status' => 'waiting',
                    'message' => "Transaction detected but not yet confirmed. Please wait..."
                ]);
                exit;
            }
        }
    }
    if ($result['success']) {
        // Update cooldown
        $cooldown_file = 'cooldowns.txt';
        $cooldowns = [];
        if (file_exists($cooldown_file)) {
            $cooldowns = json_decode(file_get_contents($cooldown_file), true) ?: [];
        }
        
        // Update user's cooldown
        $cooldowns[$_SESSION['exchange_data']['from_username']] = time();
        
        // Clean up old cooldowns (older than 24 hours)
        foreach ($cooldowns as $user => $time) {
            if (time() - $time > 86400) { // 24 hours
                unset($cooldowns[$user]);
            }
        }
        
        // Save cooldowns
        file_put_contents($cooldown_file, json_encode($cooldowns));
    }
    
    echo json_encode([
        'status' => 'waiting',
        'message' => "Checking transaction status..."
    ]);
    exit;
}

// Check for session timeout
if (isset($_SESSION['exchange_data'])) {
    if (time() - $_SESSION['exchange_data']['timestamp'] > EXCHANGE_TIMEOUT) {
        unset($_SESSION['exchange_data']);
        $message = "Session expired. Please start over.";
    }
}

// Handle initial form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['initial_submit'])) {
    $amount = floatval($_POST['amount']);
    $from_coin = $_POST['from_coin'];
    
    // Validate amount
    $validation = validate_exchange_amount($amount, $from_coin, DUCO_TO_ELAP_RATE);
    if (!$validation['valid']) {
        $message = $validation['message'];
    } else {
        if ($from_coin === 'ELAP') {
            // Get initial balances
            $initial_sender_balance = check_elap_balance($_POST['from_username']);
            $initial_faucet_balance = check_elap_balance(ELAP_USERNAME);
            
            error_log("Initial balances - Sender: $initial_sender_balance, Faucet: $initial_faucet_balance");
            
            if ($initial_sender_balance === null || $initial_faucet_balance === null) {
                $message = "Error checking ELAP balances. Please try again later.";
            } else {
                $_SESSION['exchange_data'] = [
                    'from_coin' => $from_coin,
                    'to_coin' => $_POST['to_coin'],
                    'from_username' => $_POST['from_username'],
                    'to_username' => $_POST['to_username'],
                    'amount' => $amount,
                    'initial_sender_balance' => $initial_sender_balance,
                    'initial_faucet_balance' => $initial_faucet_balance,
                    'timestamp' => time()
                ];
                $waiting_for_verification = true;
            }
        } else {
            // DUCO to ELAP exchange
            $_SESSION['exchange_data'] = [
                'from_coin' => $from_coin,
                'to_coin' => $_POST['to_coin'],
                'from_username' => $_POST['from_username'],
                'to_username' => $_POST['to_username'],
                'amount' => $amount,
                'memo' => "Exchange-" . uniqid(),
                'timestamp' => time()
            ];
            $unique_memo = $_SESSION['exchange_data']['memo'];
            $waiting_for_verification = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DUCO-ELAP Exchange</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
            margin-left: 10px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .verification-message {
            margin: 15px 0;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            font-size: 14px;
            line-height: 1.5;
        }

        .message-error {
            background-color: #ffe6e6;
            color: #d63031;
            border: 1px solid #ff7675;
        }

        .message-success {
            background-color: #e6ffe6;
            color: #27ae60;
            border: 1px solid #2ecc71;
        }

        .verification-status {
            margin-top: 15px;
            font-weight: bold;
        }

        .btn-primary {
            position: relative;
            min-width: 150px;
        }

        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
<?php if (isset($_SESSION['error'])): ?>
    <div class="error-message">
        <?php echo htmlspecialchars($_SESSION['error']); ?>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>
    <div class="container">
        <h1>DUCO-ELAP Exchange</h1>
        
        <div class="exchange-rate">
            <div class="exchange-rate-title">Current Exchange Rate</div>
            <div class="exchange-rate-value">1 DUCO = <?php echo number_format(DUCO_TO_ELAP_RATE, 4); ?> ELAP</div>
        </div>

        <div class="exchange-limits">
            <p>Exchange Limits:</p>
            <ul>
                <li>Minimum DUCO: <?php echo MIN_DUCO_AMOUNT; ?> DUCO</li>
                <li>Maximum DUCO: <?php echo MAX_DUCO_AMOUNT; ?> DUCO</li>
                <li>Transaction Fee: <?php echo TRANSACTION_FEE * 100; ?>%</li>
            </ul>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, 'successful') !== false ? 'message-success' : 'message-error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($waiting_for_verification): ?>
            <div class="verification-instructions">
                <?php if (isset($_SESSION['exchange_data'])): ?>
                    <?php if ($_SESSION['exchange_data']['from_coin'] === 'DUCO'): ?>
                        <h3>Please send DUCO to complete the exchange:</h3>
                        <p>1. Send <?php echo number_format($_SESSION['exchange_data']['amount'] * (1 + TRANSACTION_FEE), 6); ?> 
                           DUCO (including <?php echo TRANSACTION_FEE * 100; ?>% fee) to <?php echo DUCO_RECIPIENT; ?></p>
                        <p>2. Include this memo: <strong><?php echo $unique_memo; ?></strong></p>
                    <?php else: ?>
                        <h3>Please send ELAP to complete the exchange:</h3>
                        <p>1. Send <?php echo number_format($_SESSION['exchange_data']['amount'] * (1 + TRANSACTION_FEE), 4); ?> 
                           ELAP (including <?php echo TRANSACTION_FEE * 100; ?>% fee) to <strong><?php echo ELAP_USERNAME; ?></strong></p>
                    <?php endif; ?>
                    <div class="verification-message">Waiting to start verification...</div>
                    <form method="POST" id="verifyForm">
                        <button type="submit" name="verify_transaction" class="btn btn-primary">
                            <span class="button-text">Start Verification</span>
                            <span class="loading" style="display: none;"></span>
                        </button>
                    </form>
                    <p><a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-secondary">Cancel Exchange</a></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form method="POST" id="exchangeForm">
                <div class="form-group">
                    <label for="from_coin">From Currency</label>
                    <select name="from_coin" id="from_coin" required>
                        <option value="DUCO">DUCO</option>
                        <option value="ELAP">ELAP</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="to_coin">To Currency</label>
                    <select name="to_coin" id="to_coin" required>
                        <option value="ELAP">ELAP</option>
                        <option value="DUCO">DUCO</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="from_username">From Username</label>
                    <input type="text" name="from_username" id="from_username" required>
                </div>

                <div class="form-group">
                    <label for="to_username">To Username</label>
                    <input type="text" name="to_username" id="to_username" required>
                </div>

                <div class="form-group">
                    <label for="amount">Amount</label>
                    <input type="number" name="amount" id="amount" step="0.000001" required>
                    <div class="amount-helper"></div>
                </div>

                <button type="submit" name="initial_submit" class="btn btn-primary">Exchange</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('exchangeForm');
            const fromCoin = document.getElementById('from_coin');
            const toCoin = document.getElementById('to_coin');
            const amount = document.getElementById('amount');
            const helper = document.querySelector('.amount-helper');
            const rate = <?php echo DUCO_TO_ELAP_RATE; ?>;

            let verificationInterval = null;

            function checkVerification() {
                fetch(window.location.href + '?check_verification=true', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'verify_transaction=1'
                })
                .then(response => response.json())
                .then(data => {
                    const messageDiv = document.querySelector('.verification-message');
                    if (messageDiv) {
                        messageDiv.innerHTML = data.message;
                        messageDiv.className = 'verification-message ' + 
                            (data.status === 'success' ? 'message-success' : 
                             data.status === 'error' ? 'message-error' : '');
                    }
                    
                    if (data.status === 'success') {
                        clearInterval(verificationInterval);
                        setTimeout(() => {
                            window.location.href = window.location.pathname;
                        }, 3000);
                    } else if (data.status === 'error') {
                        clearInterval(verificationInterval);
                        const btn = document.querySelector('#verifyForm button');
                        if (btn) {
                            btn.disabled = false;
                            btn.querySelector('.button-text').style.display = 'inline-block';
                            btn.querySelector('.loading').style.display = 'none';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    const messageDiv = document.querySelector('.verification-message');
                    if (messageDiv) {
                        messageDiv.innerHTML = "Error checking status. Will retry...";
                        messageDiv.className = 'verification-message message-error';
                    }
                });
            }

            if (form) {
                fromCoin.addEventListener('change', function() {
                    toCoin.value = this.value === 'DUCO' ? 'ELAP' : 'DUCO';
                    updateHelper();
                });

                toCoin.addEventListener('change', function() {
                    fromCoin.value = this.value === 'DUCO' ? 'ELAP' : 'DUCO';
                    updateHelper();
                });

                amount.addEventListener('input', updateHelper);

                function updateHelper() {
                    if (!amount.value) {
                        helper.textContent = '';
                        return;
                    }

                    const value = parseFloat(amount.value);
                    if (fromCoin.value === 'DUCO') {
                        const elapAmount = value * rate;
                        helper.textContent = `You will receive approximately ${elapAmount.toFixed(4)} ELAP`;
                    } else {
                        const ducoAmount = value / rate;
                        helper.textContent = `You will receive approximately ${ducoAmount.toFixed(6)} DUCO`;
                    }
                }

                form.addEventListener('submit', function(e) {
                    const value = parseFloat(amount.value);
                    if (fromCoin.value === 'DUCO') {
                        if (value < <?php echo MIN_DUCO_AMOUNT; ?> || value > <?php echo MAX_DUCO_AMOUNT; ?>) {
                            e.preventDefault();
                            alert(`Amount must be between ${<?php echo MIN_DUCO_AMOUNT; ?>} and ${<?php echo MAX_DUCO_AMOUNT; ?>} DUCO`);
                        }
                    } else {
                        const ducoEquivalent = value / rate;
                        if (ducoEquivalent > <?php echo MAX_DUCO_AMOUNT; ?>) {
                            e.preventDefault();
                            alert(`Amount exceeds maximum equivalent of ${<?php echo MAX_DUCO_AMOUNT; ?>} DUCO`);
                        }
                    }
                });
            }

            const verifyForm = document.getElementById('verifyForm');
            if (verifyForm) {
                verifyForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const btn = this.querySelector('button');
                    const btnText = btn.querySelector('.button-text');
                    const loading = btn.querySelector('.loading');
                    
                    btnText.style.display = 'none';
                    loading.style.display = 'inline-block';
                    btn.disabled = true;

                    // Clear any existing interval
                    if (verificationInterval) {
                        clearInterval(verificationInterval);
                    }

                    // Start immediate check
                    checkVerification();
                    
                    // Start periodic checks
                    verificationInterval = setInterval(checkVerification, 1000);
                });
            }
        });
    </script>
</body>


</html>
