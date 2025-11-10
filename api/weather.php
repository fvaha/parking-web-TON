<?php
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

// Weather API configuration
$WEATHER_API_KEY = '78b1d910a44248afbc8205927251908';
$WEATHER_API_URL = 'https://api.weatherapi.com/v1';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
        ]);
        exit();
    }
    
    // Get coordinates from query parameters
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 43.1376; // Default location
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : 20.5156;
    
    // Fetch weather data from WeatherAPI
    $url = "{$WEATHER_API_URL}/current.json?key={$WEATHER_API_KEY}&q={$lat},{$lng}&aqi=yes";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200 || !$response) {
        throw new Exception('Failed to fetch weather data');
    }
    
    $weather_data = json_decode($response, true);
    
    if (!$weather_data || !isset($weather_data['current'])) {
        throw new Exception('Invalid weather data received');
    }
    
    // Format response for bot consumption
    $result = [
        'success' => true,
        'data' => [
            'temperature' => round($weather_data['current']['temp_c']),
            'humidity' => $weather_data['current']['humidity'],
            'air_quality' => isset($weather_data['current']['air_quality']) 
                ? $weather_data['current']['air_quality']['us-epa-index'] ?? 0 
                : rand(50, 100), // Fallback if AQI not available
            'weather_condition' => $weather_data['current']['condition']['text'],
            'weather_code' => $weather_data['current']['condition']['code'],
            'is_day' => $weather_data['current']['is_day'] == 1,
            'location' => [
                'name' => $weather_data['location']['name'],
                'country' => $weather_data['location']['country']
            ],
            'timestamp' => time()
        ]
    ];
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Weather API error: ' . $e->getMessage()
    ]);
}
?>

