<?php
/**
 * Security Helper Functions
 * Provides API key authentication, rate limiting, and input validation
 */

// API Keys - should be in .env file
function getApiKey() {
    return getenv('API_KEY') ?: '';
}

function getWebAppApiKey() {
    return getenv('WEB_APP_API_KEY') ?: '';
}

/**
 * Check API key authentication
 * @param string $required_key_type - 'web_app', 'bot', or 'any'
 * @param bool $allow_server_requests - Allow requests from same server (for internal calls)
 */
function checkApiKey($required_key_type = 'web_app', $allow_server_requests = false) {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Always allow requests with TelegramBot User-Agent (bot calls API from external server)
    if (strpos($user_agent, 'TelegramBot') !== false) {
        error_log("checkApiKey: Allowing TelegramBot request with User-Agent: {$user_agent}");
        return true;
    }
    
    // Allow requests from same server (for internal calls from Telegram bot)
    if ($allow_server_requests) {
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
        $server_addr = $_SERVER['SERVER_ADDR'] ?? '';
        
        $is_server_request = (
            $remote_addr === '127.0.0.1' ||
            $remote_addr === '::1' ||
            $remote_addr === $server_addr ||
            strpos($user_agent, 'curl') !== false ||
            strpos($user_agent, 'PHP') !== false ||
            empty($user_agent) // Some server requests don't send user agent
        );
        
        if ($is_server_request) {
            error_log("checkApiKey: Allowing server request from {$remote_addr}");
            return true;
        }
    }
    
    // Allow requests from same origin (web app from same domain)
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    $server_host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Check if request is from same domain
    if (!empty($origin)) {
        $origin_host = parse_url($origin, PHP_URL_HOST);
        if ($origin_host === $server_host || 
            $origin_host === 'parkiraj.info' || 
            $origin_host === 'www.parkiraj.info' ||
            strpos($origin, 'parkiraj.info') !== false) {
            // Same origin request - allow without API key for web app
            return true;
        }
    }
    
    // Get API key early for checks
    $api_key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    
    // Also check if request is from same server (no origin header but same IP)
    if (empty($origin) && !empty($server_host) && empty($api_key)) {
        // If no origin but request is to same domain and no API key provided
        // Likely same-origin web app request - allow it
        if (strpos($request_uri, '/api/') !== false) {
            // Same-origin request without API key - allow for web app
            return true;
        }
    }
    
    if (empty($api_key)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'API key required. Provide X-API-Key header or api_key parameter.'
        ]);
        exit();
    }
    
    $expected_key = '';
    if ($required_key_type === 'web_app') {
        $expected_key = getWebAppApiKey();
    } elseif ($required_key_type === 'bot') {
        $expected_key = getenv('BOT_API_KEY') ?: getWebAppApiKey(); // Fallback to web app key
    } else {
        // 'any' - accept either key
        $web_key = getWebAppApiKey();
        $bot_key = getenv('BOT_API_KEY') ?: $web_key;
        if ($api_key === $web_key || $api_key === $bot_key) {
            return true;
        }
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid API key'
        ]);
        exit();
    }
    
    if ($api_key !== $expected_key || empty($expected_key)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid API key'
        ]);
        exit();
    }
    
    return true;
}

/**
 * Simple rate limiting using file-based storage
 * For production, use Redis or Memcached
 */
function checkRateLimit($identifier, $max_requests = 100, $window_seconds = 60) {
    $rate_limit_dir = __DIR__ . '/../tmp/rate_limit/';
    
    if (!is_dir($rate_limit_dir)) {
        mkdir($rate_limit_dir, 0755, true);
    }
    
    $file = $rate_limit_dir . md5($identifier) . '.json';
    $now = time();
    
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        
        // Clean old entries
        $data['requests'] = array_filter($data['requests'], function($timestamp) use ($now, $window_seconds) {
            return ($now - $timestamp) < $window_seconds;
        });
        
        if (count($data['requests']) >= $max_requests) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => 'Rate limit exceeded. Please try again later.',
                'retry_after' => $window_seconds
            ]);
            exit();
        }
    } else {
        $data = ['requests' => []];
    }
    
    $data['requests'][] = $now;
    file_put_contents($file, json_encode($data));
    
    return true;
}

/**
 * Get client identifier for rate limiting
 */
function getClientIdentifier() {
    // Use IP address as identifier
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Handle multiple IPs (from proxy)
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    
    return $ip;
}

/**
 * Validate license plate format
 */
function validateLicensePlate($license_plate) {
    // Remove spaces and convert to uppercase
    $license_plate = strtoupper(str_replace(' ', '', trim($license_plate)));
    
    // Serbian license plate format: 2-3 letters, 3-4 digits, 2 letters (optional)
    // Examples: AB123CD, ABC1234, AB123
    if (!preg_match('/^[A-Z]{2,3}[0-9]{3,4}[A-Z]{0,2}$/', $license_plate)) {
        return false;
    }
    
    // Length check: 5-9 characters
    if (strlen($license_plate) < 5 || strlen($license_plate) > 9) {
        return false;
    }
    
    return $license_plate;
}

/**
 * Validate transaction hash format
 */
function validateTxHash($tx_hash) {
    $tx_hash = trim($tx_hash);
    
    // TON transaction hash can be:
    // - Hex string (64 chars)
    // - Base64 BOC (variable length, usually 100+ chars)
    // - Base64url (variable length)
    
    if (empty($tx_hash)) {
        return false;
    }
    
    // Check if it's a hex string (64 chars)
    if (preg_match('/^[0-9a-fA-F]{64}$/', $tx_hash)) {
        return $tx_hash;
    }
    
    // Check if it's base64 or base64url (BOC format)
    if (preg_match('/^[A-Za-z0-9+\/_-]+=*$/', $tx_hash) && strlen($tx_hash) > 20) {
        return $tx_hash;
    }
    
    return false;
}

/**
 * Sanitize string input
 */
function sanitizeString($input, $max_length = 255) {
    $input = trim($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    
    if (strlen($input) > $max_length) {
        $input = substr($input, 0, $max_length);
    }
    
    return $input;
}

/**
 * Validate and sanitize integer
 */
function validateInteger($input, $min = null, $max = null) {
    $value = filter_var($input, FILTER_VALIDATE_INT);
    
    if ($value === false) {
        return false;
    }
    
    if ($min !== null && $value < $min) {
        return false;
    }
    
    if ($max !== null && $value > $max) {
        return false;
    }
    
    return $value;
}

/**
 * Check if transaction is recent (within specified seconds)
 */
function isTransactionRecent($tx_timestamp, $max_age_seconds = 3600) {
    $now = time();
    $age = $now - $tx_timestamp;
    
    return $age <= $max_age_seconds;
}

?>

