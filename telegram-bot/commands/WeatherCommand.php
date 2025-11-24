<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\WeatherService;

class WeatherCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $weather_service = new WeatherService();
        
        $weather_data = $weather_service->getWeatherData();
        
        if (!$weather_data) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => "❌ Unable to fetch weather data. Please try again later."
            ]);
            return;
        }
        
        $message_text = $weather_service->formatWeatherMessage($weather_data);
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $message_text
        ]);
    }
}

