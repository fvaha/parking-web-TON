<?php
namespace TelegramBot\Services;

class WeatherService {
    private $api_key;
    private $api_url;
    
    public function __construct() {
        $this->api_key = WEATHER_API_KEY;
        $this->api_url = 'https://api.weatherapi.com/v1';
    }
    
    public function getWeatherData($lat = 43.1376, $lng = 20.5156) {
        $url = API_BASE_URL . '/api/weather.php?lat=' . $lat . '&lng=' . $lng;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 && $response) {
            $data = json_decode($response, true);
            if ($data && $data['success']) {
                return $data['data'];
            }
        }
        
        return null;
    }
    
    public function formatWeatherMessage($weather_data) {
        if (!$weather_data) {
            return "Weather data not available.";
        }
        
        $aqi_labels = [
            1 => 'Good',
            2 => 'Moderate',
            3 => 'Unhealthy for Sensitive',
            4 => 'Unhealthy',
            5 => 'Very Unhealthy',
            6 => 'Hazardous'
        ];
        
        $aqi = $weather_data['air_quality'] ?? 0;
        $aqi_label = $aqi_labels[min(6, max(1, $aqi))] ?? 'Unknown';
        
        $message = "🌤️ Weather Information\n\n";
        $message .= "📍 Location: " . ($weather_data['location']['name'] ?? 'Unknown') . "\n";
        $message .= "🌡️ Temperature: " . $weather_data['temperature'] . "°C\n";
        $message .= "💧 Humidity: " . $weather_data['humidity'] . "%\n";
        $message .= "☁️ Condition: " . $weather_data['weather_condition'] . "\n";
        $message .= "🌬️ Air Quality: " . $aqi_label . " (AQI: " . $aqi . ")\n";
        
        return $message;
    }
}

