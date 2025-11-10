<?php
function set_cors_headers() {
    // Allow specific origins for development and production
    // STRICT: Only allow exact matches, no wildcards or subdomains
    $allowed_origins = [
        'http://localhost:5173',
        'http://localhost:3000',
        'http://localhost:5187',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:5187',
        'https://parkiraj.info',
        'https://www.parkiraj.info'
        // Note: Only HTTPS allowed for production domain (http:// removed for security)
        // No wildcards - only exact matches for security
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Check if request is from Telegram Web App
    $is_telegram_webapp = (
        strpos($user_agent, 'Telegram') !== false ||
        strpos($referer, 'telegram.org') !== false ||
        strpos($referer, 't.me') !== false ||
        empty($origin) // Telegram Web Apps sometimes send null origin
    );
    
    // Check if the origin is in our allowed list (exact match only)
    if (in_array($origin, $allowed_origins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } elseif ($is_telegram_webapp && strpos($referer, 'parkiraj.info') !== false) {
        // Allow Telegram Web App requests from our domain
        header('Access-Control-Allow-Origin: https://parkiraj.info');
    } else {
        // For development, allow localhost if no origin specified
        if (empty($origin) && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1') {
            header('Access-Control-Allow-Origin: http://localhost:5173');
        } else {
            // Reject unknown origins for security
            // Don't set CORS header - browser will block the request
            // This prevents malicious subdomains from accessing the API
        }
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
}

function handle_preflight() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}
?>
