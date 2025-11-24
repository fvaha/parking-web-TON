<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\ParkingService;
use TelegramBot\Services\LanguageService;

class SpacesCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $user = $message->getFrom();
        $text = $message->getText();
        $lang = LanguageService::getLanguage($user);
        
        $parking_service = new ParkingService();
        
        // Parse command parameters
        $parts = explode(' ', $text, 3);
        $action = isset($parts[1]) ? strtolower(trim($parts[1])) : '';
        $param = isset($parts[2]) ? trim($parts[2]) : '';
        
        // If no parameters, show list of streets
        if (empty($action)) {
            $this->showStreetsList($bot, $chat_id, $parking_service, $lang);
            return;
        }
        
        // Handle different actions
        switch ($action) {
            case 'streets':
            case 'street':
                if (empty($param)) {
                    $this->showStreetsList($bot, $chat_id, $parking_service, $lang);
                } else {
                    $this->showSpacesByStreet($bot, $chat_id, $parking_service, $param, $lang);
                }
                break;
                
            case 'all':
                $this->showAllSpaces($bot, $chat_id, $parking_service, $lang);
                break;
                
            case 'vacant':
            default:
                $this->showAvailableSpaces($bot, $chat_id, $parking_service, $lang);
                break;
        }
    }
    
    private function showStreetsList($bot, $chat_id, $parking_service, $lang) {
        $streets = $parking_service->getUniqueStreets();
        
        if (empty($streets)) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('spaces_none', $lang)
            ]);
            return;
        }
        
        $text = "🛣️ *" . LanguageService::t('streets_list', $lang) . "*\n\n";
        foreach ($streets as $street_name => $count) {
            $text .= "📍 {$street_name} ({$count} " . LanguageService::t('spaces_count', $lang) . ")\n";
        }
        
        $text .= "\n" . LanguageService::t('streets_instruction', $lang);
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
    
    private function showSpacesByStreet($bot, $chat_id, $parking_service, $street_name, $lang) {
        $spaces = $parking_service->getSpacesByStreet($street_name);
        
        if (empty($spaces)) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('spaces_none_street', $lang, ['street' => $street_name])
            ]);
            return;
        }
        
        $text = LanguageService::t('spaces_street', $lang, ['street' => $street_name]);
        $count = 0;
        
        foreach ($spaces as $space) {
            $count++;
            $space_name = !empty($space['sensor_name']) ? $space['sensor_name'] : "Space #{$space['id']}";
            $text .= "📍 *{$space_name}*\n";
            $text .= "   ID: `{$space['id']}`\n";
            
            if (isset($space['zone']) && !empty($space['zone']['name'])) {
                $text .= "   🏢 Zone: {$space['zone']['name']}";
                if ($space['zone']['is_premium']) {
                    $text .= " (Premium)";
                }
                $text .= "\n";
            }
            $text .= "\n";
            
            if ($count >= 20) {
                $text .= "... " . LanguageService::t('and_more', $lang);
                break;
            }
        }
        
        $text .= "\n*" . LanguageService::t('total_spaces', $lang) . ": " . count($spaces) . "*";
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
    
    private function showAllSpaces($bot, $chat_id, $parking_service, $lang) {
        $spaces = $parking_service->getParkingSpaces();
        
        if (empty($spaces)) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('spaces_none_all', $lang)
            ]);
            return;
        }
        
        $text = LanguageService::t('spaces_all', $lang);
        $count = 0;
        
        foreach ($spaces as $space) {
            $count++;
            $space_name = !empty($space['sensor_name']) ? $space['sensor_name'] : "Space #{$space['id']}";
            $status_icon = $space['status'] === 'vacant' ? '🟢' : ($space['status'] === 'reserved' ? '🟡' : '🔴');
            $text .= "{$status_icon} *{$space_name}*\n";
            $text .= "   ID: `{$space['id']}` | Status: {$space['status']}\n";
            
            if (!empty($space['street_name'])) {
                $text .= "   🛣️ {$space['street_name']}\n";
            }
            
            if (isset($space['zone']) && !empty($space['zone']['name'])) {
                $text .= "   🏢 Zone: {$space['zone']['name']}\n";
            }
            $text .= "\n";
            
            if ($count >= 20) {
                $text .= "... " . LanguageService::t('and_more', $lang);
                break;
            }
        }
        
        $text .= "\n*" . LanguageService::t('total_spaces', $lang) . ": " . count($spaces) . "*";
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
    
    private function showAvailableSpaces($bot, $chat_id, $parking_service, $lang) {
        $available_spaces = $parking_service->getAvailableSpaces();
        
        if (empty($available_spaces)) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('spaces_none', $lang)
            ]);
            return;
        }
        
        $text = LanguageService::t('spaces_available', $lang);
        $count = 0;
        
        foreach ($available_spaces as $space) {
            $count++;
            $space_name = !empty($space['sensor_name']) ? $space['sensor_name'] : "Space #{$space['id']}";
            $text .= "📍 *{$space_name}*\n";
            $text .= "   ID: `{$space['id']}`\n";
            
            if (!empty($space['street_name'])) {
                $text .= "   🛣️ {$space['street_name']}\n";
            }
            
            if (isset($space['zone']) && !empty($space['zone']['name'])) {
                $text .= "   🏢 Zone: {$space['zone']['name']}";
                if ($space['zone']['is_premium']) {
                    $text .= " (Premium - {$space['zone']['hourly_rate']} TON/hr)";
                }
                $text .= "\n";
            }
            $text .= "\n";
            
            if ($count >= 20) {
                $text .= "... " . LanguageService::t('and_more', $lang);
                break;
            }
        }
        
        $text .= "\n*" . LanguageService::t('total_available', $lang) . ": " . count($available_spaces) . "*";
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
}

