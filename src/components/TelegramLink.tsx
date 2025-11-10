import React, { useState, useEffect } from 'react';
import { MessageCircle, X, ChevronUp } from 'lucide-react';
import { build_api_url } from '../config/api_config';
import { LanguageService } from '../services/language_service';

interface TelegramLinkProps {
  license_plate: string;
  on_collapsed_change?: (collapsed: boolean) => void;
}

const telegramLinkCache = new Map<string, { linked: boolean; username: string | null }>();

export const TelegramLink: React.FC<TelegramLinkProps> = ({ license_plate, on_collapsed_change }) => {
  const [is_linked, set_is_linked] = useState(false);
  const [telegram_username, set_telegram_username] = useState<string | null>(null);
  const [loading, set_loading] = useState(false);
  const [is_collapsed, set_is_collapsed] = useState(false);
  const language_service = LanguageService.getInstance();

  const handle_collapse = (collapsed: boolean) => {
    set_is_collapsed(collapsed);
    if (on_collapsed_change) {
      on_collapsed_change(collapsed);
    }
  };

  useEffect(() => {
    const normalized_plate = (license_plate || '').trim().toUpperCase();

    if (!normalized_plate) {
      set_is_linked(false);
      set_telegram_username(null);
      return;
    }

    const cached = telegramLinkCache.get(normalized_plate);
    if (cached) {
      set_is_linked(cached.linked);
      set_telegram_username(cached.username);
      return;
    }

    check_telegram_link(normalized_plate);
  }, [license_plate]);

  const check_telegram_link = async (normalized_plate: string) => {
    if (!normalized_plate) {
      set_is_linked(false);
      set_telegram_username(null);
      return;
    }
    
    try {
      const response = await fetch(build_api_url(`/api/telegram-users.php?license_plate=${encodeURIComponent(normalized_plate)}`));
      
      if (!response.ok) {
        // If 404 or other error, user is not linked
        set_is_linked(false);
        set_telegram_username(null);
        telegramLinkCache.set(normalized_plate, { linked: false, username: null });
        return;
      }
      
      const data = await response.json();
      
      if (data.success && data.data) {
        set_is_linked(true);
        const username = data.data.username || 'Linked';
        set_telegram_username(username);
        telegramLinkCache.set(normalized_plate, { linked: true, username });
      } else {
        set_is_linked(false);
        set_telegram_username(null);
        telegramLinkCache.set(normalized_plate, { linked: false, username: null });
      }
    } catch (error) {
      console.error('Error checking Telegram link:', error);
      set_is_linked(false);
      set_telegram_username(null);
    }
  };

  const handle_link_telegram = () => {
    const lang = language_service.get_current_language();
    const bot_username = '@Parkiraj_info_bot';
    const bot_link = `https://t.me/Parkiraj_info_bot`;
    
    const instructions: { [key: string]: string } = {
      'sr': `Kako povezati Telegram nalog:\n\n1. Otvori Telegram aplikaciju\n2. Pronađi bota: ${bot_username}\n   Ili klikni na link: ${bot_link}\n3. Pošalji komandu:\n   /link ${license_plate}\n\nIli ako želiš da koristiš korisničko ime:\n   /link <tvoje_korisnicko_ime> ${license_plate}\n\nPrimer:\n   /link ${license_plate}\n   ili\n   /link john_doe ${license_plate}`,
      'en': `How to link your Telegram account:\n\n1. Open Telegram app\n2. Find the bot: ${bot_username}\n   Or click the link: ${bot_link}\n3. Send command:\n   /link ${license_plate}\n\nOr if you want to use username:\n   /link <your_username> ${license_plate}\n\nExample:\n   /link ${license_plate}\n   or\n   /link john_doe ${license_plate}`,
      'de': `So verknüpfen Sie Ihr Telegram-Konto:\n\n1. Öffnen Sie die Telegram-App\n2. Suchen Sie den Bot: ${bot_username}\n   Oder klicken Sie auf den Link: ${bot_link}\n3. Senden Sie den Befehl:\n   /link ${license_plate}\n\nOder wenn Sie einen Benutzernamen verwenden möchten:\n   /link <Ihr_Benutzername> ${license_plate}\n\nBeispiel:\n   /link ${license_plate}\n   oder\n   /link john_doe ${license_plate}`,
      'fr': `Comment lier votre compte Telegram:\n\n1. Ouvrez l'application Telegram\n2. Trouvez le bot: ${bot_username}\n   Ou cliquez sur le lien: ${bot_link}\n3. Envoyez la commande:\n   /link ${license_plate}\n\nOu si vous voulez utiliser un nom d'utilisateur:\n   /link <votre_nom_utilisateur> ${license_plate}\n\nExemple:\n   /link ${license_plate}\n   ou\n   /link john_doe ${license_plate}`,
      'ar': `كيفية ربط حساب Telegram الخاص بك:\n\n1. افتح تطبيق Telegram\n2. ابحث عن البوت: ${bot_username}\n   أو انقر على الرابط: ${bot_link}\n3. أرسل الأمر:\n   /link ${license_plate}\n\nأو إذا كنت تريد استخدام اسم مستخدم:\n   /link <اسم_المستخدم> ${license_plate}\n\nمثال:\n   /link ${license_plate}\n   أو\n   /link john_doe ${license_plate}`
    };
    
    alert(instructions[lang] || instructions['en']);
  };

  // If collapsed, don't render anything (icon will be shown in Header)
  if (is_collapsed) {
    return null;
  }

  return (
    <div className="telegram-link-section" style={{
      padding: '1rem',
      marginTop: '1rem',
      backgroundColor: '#f9fafb',
      borderRadius: '8px',
      border: '1px solid #e5e7eb',
      position: 'relative'
    }}>
      <button
        onClick={() => handle_collapse(true)}
        style={{
          position: 'absolute',
          top: '0.5rem',
          right: '0.5rem',
          padding: '0.25rem',
          backgroundColor: 'transparent',
          border: 'none',
          borderRadius: '4px',
          cursor: 'pointer',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          color: '#374151',
          transition: 'all 0.2s ease',
          zIndex: 10,
        }}
        onMouseEnter={(e) => {
          e.currentTarget.style.opacity = '0.7';
        }}
        onMouseLeave={(e) => {
          e.currentTarget.style.opacity = '1';
        }}
        title="Hide Telegram Notifications"
      >
        <ChevronUp size={16} />
      </button>
      
      <h4 style={{ marginBottom: '0.5rem', fontSize: '1rem', fontWeight: '600', paddingRight: '2rem' }}>
        Telegram Notifications
      </h4>
      {is_linked ? (
        <div style={{ fontSize: '0.9rem', color: '#059669' }}>
          ✅ Linked to Telegram ({telegram_username})
        </div>
      ) : (
        <div>
          <p style={{ fontSize: '0.85rem', color: '#6b7280', marginBottom: '0.5rem' }}>
            Link your Telegram account to receive notifications about parking availability and reservation reminders.
          </p>
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            <a
              href="https://t.me/Parkiraj_info_bot"
              target="_blank"
              rel="noopener noreferrer"
              style={{
                padding: '0.5rem 1rem',
                border: 'none',
                borderRadius: '6px',
                fontSize: '0.9rem',
                fontWeight: '500',
                cursor: 'pointer',
                background: '#0088cc',
                color: 'white',
                textDecoration: 'none',
                display: 'inline-block',
                textAlign: 'center',
                flex: '1',
                minWidth: '120px'
              }}
            >
              Open Bot
            </a>
            <button
              onClick={handle_link_telegram}
              style={{
                padding: '0.5rem 1rem',
                border: 'none',
                borderRadius: '6px',
                fontSize: '0.9rem',
                fontWeight: '500',
                cursor: 'pointer',
                background: '#3b82f6',
                color: 'white',
                flex: '1',
                minWidth: '120px'
              }}
            >
              Instructions
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

