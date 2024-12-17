<?php
require_once 'config.php';

// Function to get ELAP price
function get_elap_price() {
    error_log("Fetching ELAP price");
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://wallet.stormsurge.xyz/getprice",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log("CURL Error in get_elap_price: " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    error_log("ELAP price response (HTTP $http_code): $response");
    
    if ($http_code === 200 && $response) {
        $price = floatval($response);
        error_log("ELAP price: $price");
        return $price;
    }
    
    return null;
}

// Function to get DUCO price
function get_duco_price() {
    error_log("Fetching DUCO price");
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://server.duinocoin.com/api.json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log("CURL Error in get_duco_price: " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    error_log("DUCO API Response (HTTP $http_code): $response");
    
    $data = json_decode($response, true);
    if (isset($data['Duco price'])) {
        $price = floatval($data['Duco price']);
        error_log("DUCO price: $price");
        return $price;
    }
    
    error_log("Failed to get DUCO price");
    return null;
}

// Function to calculate exchange rate
function calculate_exchange_rate($duco_price, $elap_price) {
    error_log("Calculating exchange rate - DUCO: $duco_price, ELAP: $elap_price");
    
    if ($duco_price && $elap_price && $elap_price > 0) {
        $rate = ($duco_price / $elap_price);
        error_log("Calculated exchange rate: $rate");
        return $rate;
    }
    
    error_log("Using fallback exchange rate");
    return 2.0; // Default fallback rate
}

// Function to check ELAP balance
function check_elap_balance($username) {
    error_log("Checking ELAP balance for user: $username");
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://wallet.stormsurge.xyz/getbalance/" . urlencode($username) . "/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log("CURL Error in check_elap_balance: " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    error_log("ELAP balance check for $username - Response: $response, HTTP Code: $http_code");
    
    if ($http_code === 200 && $response) {
        $balance = floatval($response);
        error_log("ELAP balance for $username: $balance");
        return $balance;
    }
    
    error_log("Failed to get ELAP balance for $username");
    return null;
}

// Function to send DUCO with relaxed SSL
// Function to send DUCO with URL parameters
function send_duco($recipient, $amount, $memo) {
    error_log("Attempting to send DUCO - Recipient: $recipient, Amount: $amount, Memo: $memo");
    
    // Validate credentials exist
    if (empty(DUCO_RECIPIENT) || empty(DUCO_PASSWORD)) {
        error_log("DUCO credentials missing");
        return ['success' => false, 'message' => 'Exchange configuration error'];
    }

    // Format the URL with parameters
    $url = "https://server.duinocoin.com/transaction/?username=" . urlencode(DUCO_RECIPIENT) . 
           "&password=" . urlencode(DUCO_PASSWORD) . 
           "&recipient=" . urlencode($recipient) . 
           "&amount=" . urlencode(number_format($amount, 8, '.', '')) . 
           "&memo=" . urlencode($memo);

    error_log("DUCO transaction URL (excluding password): " . 
             str_replace(urlencode(DUCO_PASSWORD), '[HIDDEN]', $url));

    // Create context with SSL verification disabled
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    // Make the request
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        error_log("DUCO transaction failed - no response from server");
        return ['success' => false, 'message' => 'Connection error'];
    }

    $result = json_decode($response, true);
    error_log("DUCO API Response: " . $response);

    if (isset($result['success']) && $result['success'] === true) {
        error_log("DUCO transfer successful");
        return ['success' => true, 'message' => 'Transfer completed successfully'];
    } else {
        $error_message = isset($result['message']) ? $result['message'] : 'Unknown error';
        error_log("DUCO transfer failed: $error_message");
        return ['success' => false, 'message' => $error_message];
    }
}



// Function to log exchanges
function log_exchange($from_coin, $to_coin, $from_user, $to_user, $amount, $status) {
    $log_entry = date('Y-m-d H:i:s') . " | " . 
                 "$from_user sent $amount $from_coin to $to_user for $to_coin | " .
                 "Status: $status\n";
    
    error_log("Exchange log: $log_entry");
    
    $log_file = __DIR__ . '/exchange_log.txt';
    if (!file_put_contents($log_file, $log_entry, FILE_APPEND)) {
        error_log("Failed to write to exchange log file");
    }
}
// Function to validate exchange amount
function validate_exchange_amount($amount, $from_coin, $exchange_rate) {
    error_log("Validating exchange amount - Amount: $amount, From: $from_coin, Rate: $exchange_rate");
    
    if ($amount <= 0) {
        error_log("Invalid amount: Amount must be greater than 0");
        return ['valid' => false, 'message' => "Amount must be greater than 0"];
    }
    
    if ($from_coin === 'DUCO') {
        if ($amount < MIN_DUCO_AMOUNT) {
            error_log("Amount below minimum DUCO amount");
            return ['valid' => false, 'message' => "Minimum amount is " . MIN_DUCO_AMOUNT . " DUCO"];
        }
        if ($amount > MAX_DUCO_AMOUNT) {
            error_log("Amount exceeds maximum DUCO amount");
            return ['valid' => false, 'message' => "Maximum amount is " . MAX_DUCO_AMOUNT . " DUCO"];
        }
    } else {
        $duco_equivalent = $amount / $exchange_rate;
        error_log("DUCO equivalent for ELAP amount: $duco_equivalent");
        if ($duco_equivalent > MAX_DUCO_AMOUNT) {
            error_log("ELAP amount exceeds maximum DUCO equivalent");
            return ['valid' => false, 'message' => "Amount exceeds maximum equivalent of " . MAX_DUCO_AMOUNT . " DUCO"];
        }
    }
    
    error_log("Exchange amount validation passed");
    return ['valid' => true];
}
// Function to check if user is on cooldown
function check_cooldown($username) {
    $cooldown_file = 'cooldowns.txt';
    
    if (file_exists($cooldown_file)) {
        $cooldowns = file_get_contents($cooldown_file);
        $cooldowns = json_decode($cooldowns, true) ?: [];
        
        if (isset($cooldowns[$username])) {
            $last_exchange = $cooldowns[$username];
            $cooldown_seconds = COOLDOWN_HOURS * 3600;
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
                    "on_cooldown" => true, 
                    "message" => "You can exchange again in {$time_message}"
                ];
            }
        }
    }
    
    return ["on_cooldown" => false];
}

// Function to update cooldown
function update_cooldown($username) {
    $cooldown_file = 'cooldowns.txt';
    
    // Read existing cooldowns
    $cooldowns = [];
    if (file_exists($cooldown_file)) {
        $cooldowns = json_decode(file_get_contents($cooldown_file), true) ?: [];
    }
    
    // Update the cooldown for this user
    $cooldowns[$username] = time();
    
    // Clean up old cooldowns (older than 24 hours)
    foreach ($cooldowns as $user => $time) {
        if (time() - $time > 86400) { // 24 hours
            unset($cooldowns[$user]);
        }
    }
    
    // Save back to file
    return file_put_contents($cooldown_file, json_encode($cooldowns));
}
