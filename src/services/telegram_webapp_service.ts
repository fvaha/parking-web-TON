export interface TelegramUser {
  id: number;
  first_name: string;
  last_name?: string;
  username?: string;
  language_code?: string;
  is_premium?: boolean;
  photo_url?: string;
}

export class TelegramWebAppService {
  private static instance: TelegramWebAppService;
  private telegram_webapp: any = null;

  private constructor() {
    if (typeof window !== 'undefined' && (window as any).Telegram?.WebApp) {
      this.telegram_webapp = (window as any).Telegram.WebApp;
      this.telegram_webapp.ready();
      this.telegram_webapp.expand();
    }
  }

  static getInstance(): TelegramWebAppService {
    if (!TelegramWebAppService.instance) {
      TelegramWebAppService.instance = new TelegramWebAppService();
    }
    return TelegramWebAppService.instance;
  }

  isTelegramWebApp(): boolean {
    return this.telegram_webapp !== null;
  }

  getUser(): TelegramUser | null {
    if (!this.telegram_webapp) return null;
    
    const user = this.telegram_webapp.initDataUnsafe?.user;
    if (!user) return null;

    return {
      id: user.id,
      first_name: user.first_name,
      last_name: user.last_name,
      username: user.username,
      language_code: user.language_code,
      is_premium: user.is_premium,
      photo_url: user.photo_url
    };
  }

  getInitData(): string | null {
    if (!this.telegram_webapp) return null;
    return this.telegram_webapp.initData || null;
  }

  showAlert(message: string, callback?: () => void) {
    if (this.telegram_webapp) {
      this.telegram_webapp.showAlert(message, callback);
    } else {
      alert(message);
      if (callback) callback();
    }
  }

  showConfirm(message: string, callback?: (confirmed: boolean) => void) {
    if (this.telegram_webapp) {
      this.telegram_webapp.showConfirm(message, callback);
    } else {
      const confirmed = confirm(message);
      if (callback) callback(confirmed);
    }
  }

  close() {
    if (this.telegram_webapp) {
      this.telegram_webapp.close();
    }
  }

  setHeaderColor(color: string) {
    if (!this.telegram_webapp) return;
    
    // Check if method exists and version supports it
    // These methods are available from version 6.1+
    const version = this.telegram_webapp.version || '6.0';
    const version_parts = version.split('.');
    const major = parseInt(version_parts[0], 10);
    const minor = parseInt(version_parts[1] || '0', 10);
    
    // Only call if version is 6.1 or higher
    if (major > 6 || (major === 6 && minor >= 1)) {
      if (typeof this.telegram_webapp.setHeaderColor === 'function') {
        try {
          this.telegram_webapp.setHeaderColor(color);
        } catch (error) {
          // Silently ignore if method fails
          console.debug('setHeaderColor failed:', error);
        }
      }
    }
  }

  setBackgroundColor(color: string) {
    if (!this.telegram_webapp) return;
    
    // Check if method exists and version supports it
    // These methods are available from version 6.1+
    const version = this.telegram_webapp.version || '6.0';
    const version_parts = version.split('.');
    const major = parseInt(version_parts[0], 10);
    const minor = parseInt(version_parts[1] || '0', 10);
    
    // Only call if version is 6.1 or higher
    if (major > 6 || (major === 6 && minor >= 1)) {
      if (typeof this.telegram_webapp.setBackgroundColor === 'function') {
        try {
          this.telegram_webapp.setBackgroundColor(color);
        } catch (error) {
          // Silently ignore if method fails
          console.debug('setBackgroundColor failed:', error);
        }
      }
    }
  }

  enableClosingConfirmation() {
    if (this.telegram_webapp) {
      this.telegram_webapp.enableClosingConfirmation();
    }
  }

  disableClosingConfirmation() {
    if (this.telegram_webapp) {
      this.telegram_webapp.disableClosingConfirmation();
    }
  }
}

// Extend Window interface for TypeScript
declare global {
  interface Window {
    Telegram?: {
      WebApp?: {
        openLink?: (url: string, options?: { try_instant_view?: boolean }) => void;
        [key: string]: any;
      };
    };
  }
}

