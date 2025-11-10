import enTranslations from '../locales/en.json';
import srTranslations from '../locales/sr.json';
import deTranslations from '../locales/de.json';
import frTranslations from '../locales/fr.json';
import arTranslations from '../locales/ar.json';

export type Language = 'en' | 'sr' | 'de' | 'fr' | 'ar';

export interface Translations {
  [key: string]: {
    [lang in Language]?: string;
  };
}

// Combine all translations into a single object
const translations: Translations = {};

// Helper function to merge translations
const mergeTranslations = (lang: Language, langTranslations: Record<string, string>) => {
  Object.keys(langTranslations).forEach((key) => {
    if (!translations[key]) {
      translations[key] = {};
    }
    translations[key][lang] = langTranslations[key];
  });
};

// Merge all language translations
mergeTranslations('en', enTranslations as Record<string, string>);
mergeTranslations('sr', srTranslations as Record<string, string>);
mergeTranslations('de', deTranslations as Record<string, string>);
mergeTranslations('fr', frTranslations as Record<string, string>);
mergeTranslations('ar', arTranslations as Record<string, string>);

export class LanguageService {
  private static instance: LanguageService;
  private current_language: Language = 'en';

  private constructor() {
    const saved_lang = localStorage.getItem('parking_language') as Language;
    if (saved_lang && ['en', 'sr', 'de', 'fr', 'ar'].includes(saved_lang)) {
      this.current_language = saved_lang;
    }
  }

  static getInstance(): LanguageService {
    if (!LanguageService.instance) {
      LanguageService.instance = new LanguageService();
    }
    return LanguageService.instance;
  }

  get_current_language(): Language {
    return this.current_language;
  }

  set_language(language: Language): void {
    this.current_language = language;
    localStorage.setItem('parking_language', language);
    window.location.reload(); // Reload to apply changes
  }

  get_available_languages(): { code: Language; name: string; flag: string }[] {
    return [
      { code: 'en', name: 'US English', flag: '🇺🇸' },
      { code: 'sr', name: 'RS Srpski', flag: '🇷🇸' },
      { code: 'de', name: 'DE Deutsch', flag: '🇩🇪' },
      { code: 'fr', name: 'FR Français', flag: '🇫🇷' },
      { code: 'ar', name: 'AR العربية', flag: '🇸🇦' }
    ];
  }

  translate(key: string): string {
    const translation = translations[key];
    if (!translation) {
      console.warn(`Translation missing for key: ${key}`);
      return key;
    }
    return translation[this.current_language] || translation['en'] || key;
  }

  t(key: string): string {
    return this.translate(key);
  }
}
