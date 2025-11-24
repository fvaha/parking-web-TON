import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { Globe, ChevronDown, Edit3, Thermometer, CloudRain, ChevronUp, ChevronDown as ChevronDownIcon, Send } from 'lucide-react';
import { LanguageService } from '../services/language_service';
import { WeatherService } from '../services/weather_service';
import WeatherBackground from './WeatherBackground';
import type { Language } from '../services/language_service';
import type { WeatherData } from '../services/weather_service';
import './Header.css';

interface HeaderProps {
  license_plate: string | null;
  on_change_plate: () => void;
  telegram_collapsed?: boolean;
  on_telegram_expand?: () => void;
}

export const Header: React.FC<HeaderProps> = ({ license_plate, on_change_plate, telegram_collapsed = false, on_telegram_expand }) => {
  const [show_language_menu, set_show_language_menu] = useState(false);
  const [dropdown_position, set_dropdown_position] = useState({ top: 0, left: 0 });
  const [current_language, set_current_language] = useState<Language>('en');
  const [weather_data, set_weather_data] = useState<WeatherData | null>(null);
  const [weather_loading, set_weather_loading] = useState(true);
  const [weather_error, set_weather_error] = useState<string | null>(null);
  const [time_shade, set_time_shade] = useState(0); // 0 = full day, 1 = full night
  const [is_collapsed, set_is_collapsed] = useState(false);
  const language_menu_ref = useRef<HTMLDivElement>(null);
  const language_button_ref = useRef<HTMLButtonElement>(null);

  const toggle_language_menu = () => {
    const will_show = !show_language_menu;
    console.log('toggle_language_menu called, will_show:', will_show, 'current:', show_language_menu);
    
    if (language_button_ref.current) {
      try {
        const rect = language_button_ref.current.getBoundingClientRect();
        const new_position = {
          top: rect.bottom + 5,
          left: rect.right - 120 // Align right edge of dropdown with right edge of button (reduced from 140)
        };
        
        // Ensure position is within viewport bounds
        if (new_position.left < 0) {
          new_position.left = 10;
        }
        if (new_position.top + 200 > window.innerHeight) {
          new_position.top = rect.top - 205;
        }
        
        console.log('Setting dropdown position:', new_position);
        set_dropdown_position(new_position);
      } catch (error) {
        console.error('Error calculating dropdown position:', error);
        // Fallback position
        set_dropdown_position({ top: 100, left: window.innerWidth - 150 });
      }
    } else {
      console.warn('language_button_ref.current is null');
    }
    
    console.log('Setting show_language_menu to:', will_show);
    set_show_language_menu(will_show);
  };

  const language_service = LanguageService.getInstance();
  const weather_service = WeatherService.getInstance();

  // Memoize available languages to prevent unnecessary recalculations
  const available_languages = useMemo(() => {
    return language_service.get_available_languages();
  }, [language_service]);

  // Memoize language change handler
  const handle_language_change = useCallback((language: Language) => {
    language_service.set_language(language);
    set_current_language(language);
    set_show_language_menu(false);
  }, [language_service]);

  const handle_click_outside = useCallback((event: MouseEvent) => {
    // Use setTimeout to allow the click event to complete first
    setTimeout(() => {
      // Check if click is outside both the button and the dropdown
      const target = event.target as Node;
      const is_button_click = language_button_ref.current?.contains(target);
      const is_dropdown_click = language_menu_ref.current?.contains(target);
      
      console.log('handle_click_outside - is_button_click:', is_button_click, 'is_dropdown_click:', is_dropdown_click, 'target:', target);
      
      // Only close if click is truly outside both button and dropdown
      if (!is_button_click && !is_dropdown_click) {
        console.log('Click outside, closing menu');
        set_show_language_menu(false);
      } else {
        console.log('Click was on button or dropdown, keeping menu open');
      }
    }, 100); // Increased delay to allow button click to complete
  }, []);

  // Function to get current time in Belgrade
  const get_belgrade_time = (): Date => {
    const now = new Date();
    const belgrade_time = new Date(now.toLocaleString('en-US', { timeZone: 'Europe/Belgrade' }));
    return belgrade_time;
  };

  // Calculate darkness shade based on Belgrade time
  const calculate_time_shade = (): number => {
    const belgrade_time = get_belgrade_time();
    const hour = belgrade_time.getHours();
    const minute = belgrade_time.getMinutes();
    const total_minutes = hour * 60 + minute;

    // Dawn: 5:00 - 7:00 (gradually getting lighter)
    if (total_minutes >= 300 && total_minutes < 420) {
      // 5:00 = 1.0 (dark), 7:00 = 0.0 (light)
      return 1 - ((total_minutes - 300) / 120);
    }
    
    // Day: 7:00 - 18:00 (light)
    if (total_minutes >= 420 && total_minutes < 1080) {
      return 0;
    }
    
    // Dusk: 18:00 - 20:00 (gradually getting darker)
    if (total_minutes >= 1080 && total_minutes < 1200) {
      // 18:00 = 0.0 (light), 20:00 = 1.0 (dark)
      return (total_minutes - 1080) / 120;
    }
    
    // Night: 20:00 - 5:00 (dark)
    return 1;
  };

  // Update time shade periodically
  useEffect(() => {
    const update_shade = () => {
      set_time_shade(calculate_time_shade());
    };

    // Update immediately
    update_shade();

    // Update every minute
    const interval = setInterval(update_shade, 60000);

    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    load_language();
    fetch_weather_data();
  }, []);

  useEffect(() => {
    console.log('show_language_menu changed to:', show_language_menu);
    
    if (show_language_menu) {
      console.log('Adding click outside listener');
      // Use a longer delay before adding listener to avoid immediate closure
      const timeout_id = setTimeout(() => {
        document.addEventListener('click', handle_click_outside, true);
      }, 50);
      
      return () => {
        clearTimeout(timeout_id);
        console.log('Removing click outside listener');
        document.removeEventListener('click', handle_click_outside, true);
      };
    }

    return () => {
      document.removeEventListener('click', handle_click_outside, true);
    };
  }, [show_language_menu, handle_click_outside]);

  // Cleanup effect to ensure dropdown is removed on unmount
  useEffect(() => {
    return () => {
      set_show_language_menu(false);
    };
  }, []);

  const load_language = () => {
    const saved_language = language_service.get_current_language();
    set_current_language(saved_language);
  };

  const fetch_weather_data = async () => {
    try {
      set_weather_loading(true);
      set_weather_error(null);
      
      // Use hardcoded coordinates for now (Your location)
      const latitude = 43.1376;
      const longitude = 20.5156;
      
      const weather = await weather_service.get_weather_data(latitude, longitude);
      set_weather_data(weather);
      set_weather_loading(false);
    } catch (error) {
      console.error('Weather fetch error:', error);
      set_weather_error('Failed to fetch weather');
      set_weather_loading(false);
    }
  };

  const get_weather_background_type = (): 'clear-day' | 'clear-night' | 'cloudy' | 'rainy' | 'snowy' | 'stormy' | 'foggy' | 'hazy' | 'windy' => {
    if (!weather_data) return 'clear-day';
    
    // Map weather codes to background types
    const weather_code = weather_data.weather_code;
    
    if (weather_code >= 0 && weather_code <= 3) {
      return weather_data.is_day ? 'clear-day' : 'clear-night';
    } else if (weather_code >= 45 && weather_code <= 48) {
      return 'foggy';
    } else if (weather_code >= 51 && weather_code <= 67) {
      return 'rainy';
    } else if (weather_code >= 71 && weather_code <= 77) {
      return 'snowy';
    } else if (weather_code >= 80 && weather_code <= 82) {
      return 'cloudy';
    } else if (weather_code >= 85 && weather_code <= 86) {
      return 'snowy';
    } else if (weather_code >= 95 && weather_code <= 99) {
      return 'stormy';
    } else {
      return weather_data.is_day ? 'clear-day' : 'clear-night';
    }
  };

  // Calculate overlay color based on time shade
  const get_time_overlay_style = (): React.CSSProperties => {
    const shade = time_shade;
    // Create a dark overlay that gets more opaque as shade increases
    // Smooth transition from transparent (day) to dark gray/black (night)
    // Using a more natural color progression with smooth curve
    const opacity = Math.pow(shade, 0.75) * 0.88; // Smooth curve, max 88% opacity
    
    // Color progression: transparent (day) -> dark gray (dusk) -> black (night)
    // Using darker tones that blend naturally with weather backgrounds
    const red = Math.floor(8 + (shade * 22)); // 8-30 (darker red component)
    const green = Math.floor(12 + (shade * 18)); // 12-30 (darker green)
    const blue = Math.floor(18 + (shade * 37)); // 18-55 (darker gray, main component)
    
    return {
      position: 'absolute',
      top: 0,
      left: 0,
      width: '100%',
      height: '100%',
      backgroundColor: `rgba(${red}, ${green}, ${blue}, ${opacity})`,
      zIndex: 1,
      pointerEvents: 'none',
      transition: 'background-color 0.4s ease-in-out',
    };
  };

  // Calculate text color based on time shade (white for night, black for day)
  const get_text_color = (): string => {
    // Smooth transition: black (day, shade=0) -> white (night, shade=1)
    // Use threshold around 0.5 for smoother transition
    if (time_shade > 0.5) {
      // Night - white text
      const white_opacity = Math.min(1, (time_shade - 0.5) * 2); // 0.5 -> 0, 1.0 -> 1
      return `rgba(255, 255, 255, ${white_opacity})`;
    } else {
      // Day - black text
      const black_opacity = Math.max(0, 1 - (time_shade * 2)); // 0 -> 1, 0.5 -> 0
      return `rgba(26, 26, 26, ${black_opacity})`;
    }
  };

  // Get icon color based on time shade
  const get_icon_color = (): string => {
    if (time_shade > 0.5) {
      return '#ffffff'; // White for night
    } else {
      return '#6b7280'; // Gray for day
    }
  };

  // Collapsed toolbar view
  if (is_collapsed) {
    return (
      <>
      <header className="main-header collapsed-header" style={{ minHeight: '60px' }}>
        {/* BACKGROUND WEATHER ANIMATION - Full header background */}
        <WeatherBackground 
          weather_type={get_weather_background_type()}
        />
        
        {/* TIME-BASED DARKNESS OVERLAY */}
        <div style={{
          ...get_time_overlay_style(),
          pointerEvents: 'none' as const,
        }} />
        
        <div className="header-content collapsed-content" style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          padding: '0.5rem 0.75rem',
          height: '60px',
          position: 'relative',
        }}>
          {/* Single row: Logo → License Plate → Weather → Language → Telegram */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', width: '100%', flexWrap: 'nowrap', minWidth: 0 }}>
            {/* Logo */}
            <img 
              src={time_shade > 0.5 ? "/logo-svetli.png" : "/logo-tamni.png"}
              alt="Parkiraj.info" 
              style={{ height: '36px', width: 'auto', flexShrink: 0, transition: 'opacity 0.3s ease' }}
            />
            
            {/* License Plate */}
            {license_plate && (
              <div style={{
                padding: '0.2rem 0.5rem',
                backgroundColor: time_shade > 0.5 ? 'rgba(0, 0, 0, 0.15)' : 'rgba(255, 255, 255, 0.2)',
                borderRadius: '6px',
                fontSize: '0.75rem',
                fontWeight: '600',
                color: get_text_color(),
                backdropFilter: 'blur(10px)',
                whiteSpace: 'nowrap',
                transition: 'background-color 0.4s ease-in-out, color 0.4s ease-in-out',
                flexShrink: 0,
              }}>
                {license_plate}
              </div>
            )}
            
            {/* Weather Info - Compact version for collapsed header */}
            {weather_data && (
              <div style={{ 
                display: 'flex', 
                alignItems: 'center', 
                gap: '0.25rem', 
                flexShrink: 0,
                padding: '0.15rem 0.4rem',
                backgroundColor: time_shade > 0.5 ? 'rgba(0, 0, 0, 0.12)' : 'rgba(255, 255, 255, 0.16)',
                borderRadius: '6px',
                backdropFilter: 'blur(10px)',
                transition: 'background-color 0.4s ease-in-out',
              }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.15rem' }}>
                  <img src="/tem-icon.png" alt="Temp" style={{ width: '14px', height: '14px', flexShrink: 0 }} />
                  <span style={{ fontSize: '0.65rem', fontWeight: '600', color: get_text_color(), transition: 'color 0.4s ease-in-out', textShadow: 'none', whiteSpace: 'nowrap' }}>
                    {Math.round(weather_data.temperature)}°
                  </span>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.15rem' }}>
                  <img src="/rain-icon.png" alt="Humidity" style={{ width: '14px', height: '14px', flexShrink: 0 }} />
                  <span style={{ fontSize: '0.65rem', fontWeight: '600', color: get_text_color(), transition: 'color 0.4s ease-in-out', textShadow: 'none', whiteSpace: 'nowrap' }}>
                    {weather_data.humidity}%
                  </span>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.15rem' }}>
                  <img
                    src="/aqi-icon.png"
                    alt="AQI"
                    style={{
                      width: '16px',
                      height: '16px',
                      display: 'block',
                      objectFit: 'contain',
                      imageRendering: 'auto',
                      filter: time_shade > 0.5 ? 'brightness(1.2)' : 'none',
                      transition: 'filter 0.4s ease-in-out',
                      flexShrink: 0,
                    }}
                  />
                  <span style={{ fontSize: '0.65rem', fontWeight: '600', color: get_text_color(), transition: 'color 0.4s ease-in-out', textShadow: 'none', whiteSpace: 'nowrap' }}>
                    {weather_data.air_quality}
                  </span>
                </div>
              </div>
            )}
            
            {/* Spacer */}
            <div style={{ flex: 1 }} />
            
            {/* Right side controls - aligned to right */}
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', flexShrink: 0, zIndex: 2147483647, position: 'relative', pointerEvents: 'auto' }}>
              {/* Language button */}
              <div className="language-selector" style={{ flexShrink: 0 }}>
                <button 
                  className="language-btn" 
                  onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Language button clicked');
                    toggle_language_menu();
                  }}
                  onMouseDown={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    // Don't toggle on mousedown, only on click
                  }}
                  ref={language_button_ref}
                  style={{
                    minWidth: 'auto',
                    padding: '0.35rem 0.5rem',
                    pointerEvents: 'auto',
                    zIndex: 2147483647,
                    position: 'relative',
                  }}
                >
                  <Globe size={13} />
                  <span style={{ fontSize: '0.7rem' }}>{current_language.toUpperCase()}</span>
                </button>
              
              {show_language_menu && createPortal(
                <div 
                  ref={language_menu_ref}
                  className="language-menu-portal"
                  style={{
                    position: 'fixed',
                    top: `${dropdown_position.top}px`,
                    left: `${dropdown_position.left}px`,
                    zIndex: 2147483647,
                    backgroundColor: 'white',
                    border: '2px solid #6b7280',
                    borderRadius: '12px',
                    boxShadow: '0 8px 32px rgba(0, 0, 0, 0.3)',
                    padding: '0.25rem 0',
                    minWidth: '120px',
                    backdropFilter: 'blur(10px)',
                    maxHeight: '250px',
                    overflowY: 'auto',
                    overflowX: 'hidden',
                    transform: 'translateZ(0)',
                    pointerEvents: 'auto',
                    visibility: 'visible',
                    opacity: 1,
                    display: 'block',
                    fontFamily: 'system-ui, Avenir, Helvetica, Arial, sans-serif',
                    fontSize: '13px',
                    lineHeight: '1.3',
                    isolation: 'isolate',
                    willChange: 'transform',
                    contain: 'layout style paint',
                    transformStyle: 'preserve-3d',
                    perspective: '1000px',
                  }}
                  onClick={(e) => {
                    e.stopPropagation();
                  }}
                  onMouseDown={(e) => {
                    e.stopPropagation();
                  }}
                >
                  {available_languages.length > 0 ? (
                    available_languages.map((lang) => (
                      <button
                        key={lang.code}
                        className={`language-option ${lang.code === current_language ? 'active' : ''}`}
                        onClick={() => handle_language_change(lang.code)}
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          gap: '0.5rem',
                          padding: '0.4rem 0.7rem',
                          cursor: 'pointer',
                          transition: 'all 0.2s ease',
                          border: 'none',
                          background: lang.code === current_language ? 'rgba(107, 114, 128, 0.15)' : 'transparent',
                          width: '100%',
                          textAlign: 'left',
                          color: lang.code === current_language ? '#6b7280' : '#333',
                          fontSize: '0.8rem',
                          fontWeight: lang.code === current_language ? '600' : '500',
                          whiteSpace: 'nowrap',
                          borderRadius: '4px',
                          margin: '0.05rem 0.3rem',
                          minHeight: '32px',
                          boxSizing: 'border-box',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis'
                        }}
                        onMouseEnter={(e) => {
                          if (lang.code !== current_language) {
                            e.currentTarget.style.background = 'rgba(107, 114, 128, 0.1)';
                            e.currentTarget.style.color = '#6b7280';
                          }
                        }}
                        onMouseLeave={(e) => {
                          if (lang.code !== current_language) {
                            e.currentTarget.style.background = 'transparent';
                            e.currentTarget.style.color = '#333';
                          }
                        }}
                      >
                        <span className="flag" style={{ fontSize: '1rem', flexShrink: '0', display: 'inline-block' }}>{lang.flag}</span>
                        <span className="name" style={{ flex: '1', textAlign: 'left', display: 'inline-block', fontWeight: '500' }}>{lang.name}</span>
                      </button>
                    ))
                  ) : (
                    <div style={{ padding: '1rem', textAlign: 'center', color: '#666' }}>
                      No languages available
                    </div>
                  )}
                </div>,
                document.body
              )}
              </div>

              {/* Telegram icon (if telegram is collapsed) - before expand button */}
              {telegram_collapsed && on_telegram_expand && (
                <button
                  onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    on_telegram_expand();
                  }}
                  onMouseDown={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    on_telegram_expand();
                  }}
                  style={{
                    padding: '0.35rem',
                    backgroundColor: 'transparent',
                    border: 'none',
                    borderRadius: '6px',
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    transition: 'all 0.2s ease',
                    flexShrink: 0,
                    pointerEvents: 'auto',
                    zIndex: 2147483647,
                    position: 'relative',
                  }}
                  onMouseEnter={(e) => {
                    e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
                  }}
                  onMouseLeave={(e) => {
                    e.currentTarget.style.backgroundColor = 'transparent';
                  }}
                  title="Show Telegram Notifications"
                >
                  <Send size={16} style={{ color: get_icon_color(), transition: 'color 0.4s ease-in-out' }} />
                </button>
              )}

              {/* Expand button (to expand header) - next to telegram button */}
              <button
                onClick={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  set_is_collapsed(false);
                }}
                onMouseDown={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  set_is_collapsed(false);
                }}
                style={{
                  padding: '0.35rem',
                  backgroundColor: 'transparent',
                  border: 'none',
                  borderRadius: '6px',
                  cursor: 'pointer',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  transition: 'all 0.2s ease',
                  flexShrink: 0,
                  pointerEvents: 'auto',
                  zIndex: 2147483647,
                  position: 'relative',
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = 'transparent';
                }}
                title="Expand header"
              >
                <ChevronDownIcon size={16} style={{ color: get_icon_color(), transition: 'color 0.4s ease-in-out' }} />
              </button>
            </div>
          </div>
        </div>
      </header>
      </>
    );
  }

  return (
    <header className="main-header">
      {/* BACKGROUND WEATHER ANIMATION - Full header background */}
      <WeatherBackground 
        weather_type={get_weather_background_type()}
      />
      
      {/* TIME-BASED DARKNESS OVERLAY */}
      <div style={{
        ...get_time_overlay_style(),
        pointerEvents: 'none' as const,
      }} />
      
      {/* Top right corner: Language and Telegram - OUTSIDE header-content for proper z-index */}
      <div style={{
        position: 'absolute',
        top: '0.5rem',
        right: '0.5rem',
        zIndex: 2147483647,
        display: 'flex',
        alignItems: 'center',
        gap: '0.5rem',
        flexDirection: 'row',
        pointerEvents: 'auto',
      }}>
          {/* Language button */}
          <div className="language-selector" style={{ pointerEvents: 'auto' }}>
            <button 
              className="language-btn" 
              onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                console.log('Language button clicked (extended)');
                toggle_language_menu();
              }}
              ref={language_button_ref}
              style={{
                minWidth: 'auto',
                padding: '0.4rem 0.6rem',
                pointerEvents: 'auto',
                zIndex: 2147483647,
                position: 'relative',
              }}
              onMouseDown={(e) => {
                e.preventDefault();
                e.stopPropagation();
                // Don't toggle on mousedown, only on click
              }}
            >
              <Globe size={14} />
              <span style={{ fontSize: '0.75rem' }}>{current_language.toUpperCase()}</span>
            </button>
            
            {show_language_menu && createPortal(
              <div 
                ref={language_menu_ref}
                className="language-menu-portal"
                style={{
                  position: 'fixed',
                  top: `${dropdown_position.top}px`,
                  left: `${dropdown_position.left}px`,
                  zIndex: 2147483647,
                  backgroundColor: 'white',
                  border: '2px solid #6b7280',
                  borderRadius: '12px',
                  boxShadow: '0 8px 32px rgba(0, 0, 0, 0.3)',
                  padding: '0.25rem 0',
                  minWidth: '120px',
                  backdropFilter: 'blur(10px)',
                  maxHeight: '250px',
                  overflowY: 'auto',
                  overflowX: 'hidden',
                  transform: 'translateZ(0)',
                  pointerEvents: 'auto',
                  visibility: 'visible',
                  opacity: 1,
                  display: 'block',
                  fontFamily: 'system-ui, Avenir, Helvetica, Arial, sans-serif',
                  fontSize: '13px',
                  lineHeight: '1.3',
                  isolation: 'isolate',
                  willChange: 'transform',
                  contain: 'layout style paint',
                  transformStyle: 'preserve-3d',
                  perspective: '1000px',
                }}
                onClick={(e) => {
                  e.stopPropagation();
                }}
                onMouseDown={(e) => {
                  e.stopPropagation();
                }}
              >
                {available_languages.length > 0 ? (
                  available_languages.map((lang) => (
                    <button
                      key={lang.code}
                      className={`language-option ${lang.code === current_language ? 'active' : ''}`}
                      onClick={() => handle_language_change(lang.code)}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '0.5rem',
                        padding: '0.4rem 0.7rem',
                        cursor: 'pointer',
                        transition: 'all 0.2s ease',
                        border: 'none',
                        background: lang.code === current_language ? 'rgba(107, 114, 128, 0.15)' : 'transparent',
                        width: '100%',
                        textAlign: 'left',
                        color: lang.code === current_language ? '#6b7280' : '#333',
                        fontSize: '0.8rem',
                        fontWeight: lang.code === current_language ? '600' : '500',
                        whiteSpace: 'nowrap',
                        borderRadius: '4px',
                        margin: '0.05rem 0.3rem',
                        minHeight: '32px',
                        boxSizing: 'border-box',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis'
                      }}
                      onMouseEnter={(e) => {
                        if (lang.code !== current_language) {
                          e.currentTarget.style.background = 'rgba(107, 114, 128, 0.1)';
                          e.currentTarget.style.color = '#6b7280';
                        }
                      }}
                      onMouseLeave={(e) => {
                        if (lang.code !== current_language) {
                          e.currentTarget.style.background = 'transparent';
                          e.currentTarget.style.color = '#333';
                        }
                      }}
                    >
                      <span className="flag" style={{ fontSize: '1rem', flexShrink: '0', display: 'inline-block' }}>{lang.flag}</span>
                      <span className="name" style={{ flex: '1', textAlign: 'left', display: 'inline-block', fontWeight: '500' }}>{lang.name}</span>
                    </button>
                  ))
                ) : (
                  <div style={{ padding: '1rem', textAlign: 'center', color: '#666' }}>
                    No languages available
                  </div>
                )}
              </div>,
              document.body
            )}
          </div>
          
          {/* Telegram icon (only if telegram is collapsed) - before collapse button */}
          {telegram_collapsed && on_telegram_expand && (
            <button
              onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                if (on_telegram_expand) {
                  on_telegram_expand();
                }
              }}
              onMouseDown={(e) => {
                e.preventDefault();
                e.stopPropagation();
                if (on_telegram_expand) {
                  on_telegram_expand();
                }
              }}
              style={{
                padding: '0.4rem',
                backgroundColor: 'transparent',
                border: 'none',
                borderRadius: '8px',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                transition: 'all 0.2s ease',
                pointerEvents: 'auto',
                zIndex: 2147483647,
                position: 'relative',
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.backgroundColor = 'transparent';
              }}
              title="Show Telegram Notifications"
            >
              <Send size={18} style={{ color: get_text_color(), transition: 'color 0.4s ease-in-out' }} />
            </button>
          )}

          {/* Collapse button - next to telegram button */}
          <button
            onClick={(e) => {
              e.preventDefault();
              e.stopPropagation();
              set_is_collapsed(true);
            }}
            onMouseDown={(e) => {
              e.preventDefault();
              e.stopPropagation();
              set_is_collapsed(true);
            }}
            style={{
              padding: '0.4rem',
              backgroundColor: 'transparent',
              border: 'none',
              borderRadius: '8px',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              transition: 'all 0.2s ease',
              pointerEvents: 'auto',
              zIndex: 2147483647,
              position: 'relative',
            }}
            onMouseEnter={(e) => {
              e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.backgroundColor = 'transparent';
            }}
            title="Collapse header"
          >
            <ChevronUp size={18} style={{ color: get_text_color(), transition: 'color 0.4s ease-in-out' }} />
          </button>
      </div>
      
      <div className="header-content">
        {/* TOP ROW - APP TITLE */}
        <div className="header-top-row">
          <div className="header-app-name" style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <img 
              src={time_shade > 0.5 ? "/logo2-svetli.png" : "/logo2-tamni.png"}
              alt="Parkiraj.info" 
              style={{ height: '50px', width: 'auto', transition: 'opacity 0.3s ease' }}
            />
          </div>
        </div>
        
        {/* MIDDLE ROW - LICENSE PLATE AND CHANGE ICON */}
        <div className="header-middle-row">
          <div className="header-license-section">
            <div className="header-license-container">
              <div className="license-plate" style={{ color: get_text_color(), transition: 'color 0.4s ease-in-out' }}>
                {license_plate || 'ABC-123'}
              </div>
              <button 
                className="license-change-btn"
                onClick={on_change_plate}
                title={language_service.t('change')}
              >
                <Edit3 size={24} />
              </button>
            </div>
          </div>
        </div>
        
        {/* WEATHER INFO ROW - HORIZONTAL BELOW LICENSE PLATE */}
        <div className="header-weather-row">
          <div className="header-weather-section">
            {weather_loading ? (
              <div className="weather-loading">Loading...</div>
            ) : weather_error ? (
              <div className="weather-error">Error</div>
            ) : weather_data ? (
              <div className="weather-info-horizontal">
                <div className="weather-item-horizontal">
                  <img src="/tem-icon.png" alt="Temp" className="weather-icon-svg" />
                  <span className="weather-value-horizontal" style={{ color: get_text_color(), transition: 'color 0.4s ease-in-out' }}>{Math.round(weather_data.temperature)}°C</span>
                </div>
                <div className="weather-item-horizontal">
                  <img src="/rain-icon.png" alt="Humidity" className="weather-icon-svg" />
                  <span className="weather-value-horizontal" style={{ color: get_text_color(), transition: 'color 0.4s ease-in-out' }}>{weather_data.humidity}%</span>
                </div>
                <div className="weather-item-horizontal">
                  <img
                    src="/aqi-icon.png"
                    alt="AQI"
                    width={24}
                    height={24}
                    className="weather-icon-svg"
                    style={{
                      display: 'block',
                      objectFit: 'contain',
                      imageRendering: 'auto',
                      filter: time_shade > 0.5 ? 'brightness(1.2)' : 'none',
                      transition: 'filter 0.4s ease-in-out',
                    }}
                  />
                  <span className="weather-value-horizontal" style={{ color: get_text_color(), transition: 'color 0.4s ease-in-out' }}>{weather_data.air_quality}</span>
                </div>
              </div>
            ) : null}
          </div>
        </div>
      </div>
    </header>
  );
};
