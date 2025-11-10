import React, { useState, useEffect } from 'react';
import { StorageService } from '../services/storage_service';
import { LanguageService } from '../services/language_service';

interface LicensePlateInputProps {
  onLicensePlateSet: (license_plate: string) => void;
}

export const LicensePlateInput: React.FC<LicensePlateInputProps> = ({ onLicensePlateSet }) => {
  const [license_plate, set_license_plate] = useState('');
  const [is_submitting, set_is_submitting] = useState(false);
  const [input_error, set_input_error] = useState('');
  const [is_focused, set_is_focused] = useState(false);
  const [time_shade, set_time_shade] = useState(0); // 0 = full day, 1 = full night
  const storage_service = StorageService.getInstance();
  const language_service = LanguageService.getInstance();

  // Calculate time shade based on current time (same logic as Header)
  useEffect(() => {
    const calculate_time_shade = () => {
      const now = new Date();
      const hours = now.getHours();
      const minutes = now.getMinutes();
      const total_minutes = hours * 60 + minutes;
      
      // Sunrise at 6:00 (360 minutes), Sunset at 20:00 (1200 minutes)
      const sunrise_minutes = 6 * 60;
      const sunset_minutes = 20 * 60;
      
      let shade = 0;
      if (total_minutes < sunrise_minutes) {
        // Before sunrise - full night
        shade = 1;
      } else if (total_minutes >= sunrise_minutes && total_minutes < sunrise_minutes + 60) {
        // Sunrise hour (6:00-7:00) - transition from night to day
        shade = 1 - ((total_minutes - sunrise_minutes) / 60);
      } else if (total_minutes >= sunrise_minutes + 60 && total_minutes < sunset_minutes - 60) {
        // Daytime (7:00-19:00) - full day
        shade = 0;
      } else if (total_minutes >= sunset_minutes - 60 && total_minutes < sunset_minutes) {
        // Sunset hour (19:00-20:00) - transition from day to night
        shade = ((total_minutes - (sunset_minutes - 60)) / 60);
      } else {
        // After sunset - full night
        shade = 1;
      }
      
      set_time_shade(shade);
    };
    
    calculate_time_shade();
    const interval = setInterval(calculate_time_shade, 60000); // Update every minute
    
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    const saved_plate = storage_service.get_license_plate();
    if (saved_plate) {
      onLicensePlateSet(saved_plate);
    }
  }, [onLicensePlateSet]);

  const validate_license_plate = (plate: string): boolean => {
    const trimmed_plate = plate.trim();
    if (trimmed_plate.length < 2) {
      set_input_error(language_service.t('license_plate_min_length'));
      return false;
    }
    if (trimmed_plate.length > 10) {
      set_input_error(language_service.t('license_plate_max_length'));
      return false;
    }
    if (!/^[A-Z0-9\-\s]+$/i.test(trimmed_plate)) {
      set_input_error(language_service.t('license_plate_invalid_chars'));
      return false;
    }
    set_input_error('');
    return true;
  };

  const handle_input_change = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value;
    set_license_plate(value);
    if (input_error) {
      validate_license_plate(value);
    }
  };

  const handle_submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (validate_license_plate(license_plate)) {
      set_is_submitting(true);
      
      setTimeout(() => {
        const formatted_plate = license_plate.trim().toUpperCase();
        storage_service.save_license_plate(formatted_plate);
        onLicensePlateSet(formatted_plate);
        set_is_submitting(false);
      }, 500);
    }
  };

  const handle_clear = () => {
    storage_service.clear_user_session();
    set_license_plate('');
    set_input_error('');
    window.location.reload();
  };

  const handle_key_press = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && license_plate.trim()) {
      handle_submit(e as any);
    }
  };

  return (
    <div className="license-plate-modal">
      <div className="license-plate-content">
        <div className="modal-header" style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '1rem' }}>
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '1rem' }}>
            <img 
              src={time_shade > 0.5 ? "/logo2-svetli.png" : "/logo2-tamni.png"}
              alt="Parkiraj.info" 
              style={{ height: '60px', width: 'auto', maxWidth: '100%', transition: 'opacity 0.3s ease' }}
            />
            <h2 className="modal-title" style={{ margin: 0, textAlign: 'center' }}>{language_service.t('welcome_to_smart_parking')}</h2>
            <p className="modal-description" style={{ margin: 0, textAlign: 'center' }}>{language_service.t('enter_license_plate_to_continue')}</p>
          </div>
        </div>
        
        <form onSubmit={handle_submit} className="license-form">
          <div className="input-group">
            <div className={`input-wrapper ${is_focused ? 'focused' : ''} ${input_error ? 'error' : ''}`}>
              <input
                type="text"
                value={license_plate}
                onChange={handle_input_change}
                onFocus={() => set_is_focused(true)}
                onBlur={() => set_is_focused(false)}
                onKeyPress={handle_key_press}
                placeholder={language_service.t('enter_license_plate_placeholder')}
                className="license-plate-input"
                maxLength={10}
                required
                autoFocus
              />
              {input_error && (
                <div className="error-message">
                  {input_error}
                </div>
              )}
            </div>
          </div>
          
          <div className="button-group">
            <button
              type="submit"
              className="license-plate-submit"
              disabled={is_submitting || !license_plate.trim()}
            >
              {is_submitting ? (
                <span className="loading-text">
                  <span className="loading-dots">{language_service.t('setting')}</span>
                </span>
              ) : (
                language_service.t('continue')
              )}
            </button>
            
            {storage_service.get_license_plate() && (
              <button
                type="button"
                onClick={handle_clear}
                className="change-plate-btn"
              >
                {language_service.t('change_plate')}
              </button>
            )}
          </div>
        </form>
        
        <div className="info-text">
          <p>{language_service.t('license_plate_saved_info')}</p>
          <p>{language_service.t('can_change_anytime_info')}</p>
        </div>
      </div>
    </div>
  );
};
