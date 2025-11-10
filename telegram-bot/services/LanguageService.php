<?php
namespace TelegramBot\Services;

use TelegramBot\Services\DatabaseService;

class LanguageService {
    private static $translations = null;
    
    public static function getLanguage($user) {
        try {
            // First check if user has saved language in database
            $db_service = new DatabaseService();
            $db = $db_service->getDatabase();
            $telegram_user = $db->getTelegramUserByTelegramId($user->getId());
            
            if ($telegram_user && isset($telegram_user['language']) && !empty($telegram_user['language'])) {
                $saved_lang = $telegram_user['language'];
                // Validate that saved language is supported
                if (in_array($saved_lang, ['en', 'sr', 'de', 'fr', 'ar'])) {
                    return $saved_lang;
                }
            }
        } catch (\Throwable $e) {
            // If database query fails, continue to fallback
            error_log("LanguageService::getLanguage - Database error: " . $e->getMessage());
        }
        
        // If not in database or database error, try to use Telegram language_code
        // Note: language_code is OPTIONAL in Telegram Bot API and may not always be present
        // It may also reflect system language, not necessarily the user's preferred language
        try {
            $lang_code = null;
            if (method_exists($user, 'getLanguageCode')) {
                $lang_code = $user->getLanguageCode();
            }
            
            if (empty($lang_code)) {
                // No language code from Telegram (field is optional), use default
                return 'en';
            }
            
            // Map Telegram codes to our languages
            $lang_map = [
                'sr' => 'sr',
                'sr-RS' => 'sr',
                'sr-Latn' => 'sr',
                'sr-Latn-RS' => 'sr',
                'de' => 'de',
                'de-DE' => 'de',
                'de-AT' => 'de',
                'de-CH' => 'de',
                'fr' => 'fr',
                'fr-FR' => 'fr',
                'fr-CA' => 'fr',
                'fr-BE' => 'fr',
                'ar' => 'ar',
                'ar-SA' => 'ar',
                'ar-AE' => 'ar',
                'ar-EG' => 'ar',
                'ar-IQ' => 'ar',
                'ar-JO' => 'ar',
                'ar-LB' => 'ar',
                'ar-MA' => 'ar',
                'ar-SY' => 'ar',
                'en' => 'en',
                'en-US' => 'en',
                'en-GB' => 'en',
                'en-CA' => 'en',
            ];
            
            // Extract base language code (e.g., 'sr' from 'sr-RS')
            $base_lang = explode('-', $lang_code)[0];
            
            return $lang_map[$lang_code] ?? $lang_map[$base_lang] ?? 'en';
        } catch (\Throwable $e) {
            // If anything fails, return default language
            error_log("LanguageService::getLanguage - Error: " . $e->getMessage());
            return 'en';
        }
    }
    
    public static function translate($key, $lang = 'en', $replacements = []) {
        if (self::$translations === null) {
            self::$translations = require __DIR__ . '/../translations.php';
        }
        
        $lang = in_array($lang, ['en', 'sr', 'de', 'fr', 'ar']) ? $lang : 'en';
        
        $text = self::$translations[$lang][$key] ?? self::$translations['en'][$key] ?? $key;
        
        // Replace placeholders
        if (!empty($replacements)) {
            foreach ($replacements as $placeholder => $value) {
                $text = str_replace('{' . $placeholder . '}', $value, $text);
            }
        }
        
        return $text;
    }
    
    public static function t($key, $lang = 'en', $replacements = []) {
        return self::translate($key, $lang, $replacements);
    }
    
    public static function updateUserLanguage($telegram_user_id, $language) {
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        
        if (!in_array($language, ['en', 'sr', 'de', 'fr', 'ar'])) {
            return ['success' => false, 'error' => 'Invalid language code'];
        }
        
        $stmt = $db->query("
            UPDATE telegram_users 
            SET language = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE telegram_user_id = ?
        ");
        
        // Use prepared statement
        $prepared = $db->prepare("
            UPDATE telegram_users 
            SET language = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE telegram_user_id = ?
        ");
        $prepared->bindValue(1, $language);
        $prepared->bindValue(2, $telegram_user_id);
        
        if ($prepared->execute()) {
            return ['success' => true, 'message' => 'Language updated'];
        }
        
        return ['success' => false, 'error' => 'Failed to update language'];
    }
}

