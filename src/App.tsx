import React, { useState, useEffect, useRef } from 'react';
import { BrowserRouter as Router } from 'react-router-dom';
import { LicensePlateInput } from './components/LicensePlateInput';
import { ParkingMap } from './components/ParkingMap';
import { ParkingSpaceCard } from './components/ParkingSpaceCard';
import { AdminDashboard } from './components/AdminDashboard';
import AdminLogin from './components/AdminLogin';
import { Header } from './components/Header';
import { TelegramLink } from './components/TelegramLink';
import { BottomSheet } from './components/BottomSheet';
import { WalletConnectSheet } from './components/WalletConnectSheet';
import { StorageService } from './services/storage_service';
import AdminService from './services/admin_service';
import { LanguageService } from './services/language_service';
import { TonPaymentService } from './services/ton_payment_service';
import { TonWalletService } from './services/ton_wallet_service';
import type { TransactionStatus } from './services/ton_api_service';
import { TelegramWebAppService } from './services/telegram_webapp_service';
import { TonConnectUI } from '@tonconnect/ui-react';
import { MAPS_CONFIG, GOOGLE_MAPS_URLS, get_maps_api_key } from './config/maps_config';
import { build_api_url, create_api_options } from './config/api_config';

import type { Sensor, ParkingSpace, ActiveSession } from './types';
import { BarChart3, Map, Car, AlertCircle } from 'lucide-react';
import './App.css';
import './components/Base.css';
import './components/Header.css';
import './components/Navigation.css';
import './components/Content.css';

function App() {
  const [license_plate, set_license_plate] = useState<string | null>(null);
  const [telegram_collapsed, set_telegram_collapsed] = useState(false);
  const [active_view, set_active_view] = useState<'map' | 'spaces' | 'admin'>('map');
  const [sensors, set_sensors] = useState<Sensor[]>([]);
  const [parking_spaces, set_parking_spaces] = useState<ParkingSpace[]>([]);
  const [selected_space, set_selected_space] = useState<ParkingSpace | null>(null);
  const [show_reservation_modal, set_show_reservation_modal] = useState(false);
  const [pending_reservation_space, set_pending_reservation_space] = useState<ParkingSpace | null>(null);
  const [is_admin_authenticated, set_is_admin_authenticated] = useState(false);
  const [street_search, set_street_search] = useState<string>('');
  const [filtered_parking_spaces, set_filtered_parking_spaces] = useState<ParkingSpace[]>([]);
  const [active_session, set_active_session] = useState<ActiveSession | null>(null);

  const storage_service = StorageService.getInstance();
  const admin_service = AdminService.getInstance();
  const language_service = LanguageService.getInstance();
  const ton_payment_service = TonPaymentService.getInstance();
  const ton_wallet_service = TonWalletService.getInstance();
  const telegram_webapp = TelegramWebAppService.getInstance();
  
  const [payment_processing, set_payment_processing] = useState(false);
  const [payment_tx_hash, set_payment_tx_hash] = useState<string | null>(null);
  const [payment_tx_status, set_payment_tx_status] = useState<'pending' | 'confirmed' | 'failed' | null>(null);
  const [wallet_connected, set_wallet_connected] = useState(false);
  const [show_wallet_sheet, set_show_wallet_sheet] = useState(false);
  const [reservation_duration_hours, set_reservation_duration_hours] = useState<number>(1);
  const [is_telegram_webapp, set_is_telegram_webapp] = useState(false);
  const [telegram_user, set_telegram_user] = useState<any>(null);
  const [user_location, set_user_location] = useState<{ lat: number; lng: number } | null>(null);
  const check_wallet_timeout_ref = useRef<ReturnType<typeof setTimeout> | null>(null);
  const check_wallet_in_progress_ref = useRef<boolean>(false);
  const last_wallet_check_ref = useRef<number>(0);

  useEffect(() => {
    // Set default location immediately (no geolocation request without user gesture)
    set_user_location(MAPS_CONFIG.USER_LOCATION);
  }, []);

  // Request geolocation only when user interacts with the map
  const request_user_location = () => {
    if (navigator.geolocation && (!user_location || (user_location.lat === MAPS_CONFIG.USER_LOCATION.lat && user_location.lng === MAPS_CONFIG.USER_LOCATION.lng))) {
      navigator.geolocation.getCurrentPosition(
        (position) => {
          set_user_location({
            lat: position.coords.latitude,
            lng: position.coords.longitude
          });
        },
        (error) => {
          console.warn('Geolocation error:', error);
          // Keep default location if geolocation fails
        },
        {
          enableHighAccuracy: true,
          timeout: 10000,
          maximumAge: 0
        }
      );
    }
  };

  useEffect(() => {
    // Check if opened from Telegram Web App
    const is_telegram = telegram_webapp.isTelegramWebApp();
    set_is_telegram_webapp(is_telegram);
    
    if (is_telegram) {
      const tg_user = telegram_webapp.getUser();
      if (tg_user) {
        set_telegram_user(tg_user);
        // Auto-link Telegram user if not already linked
        // This will be handled when user enters license plate
      }
      
      // Configure Telegram Web App appearance
      telegram_webapp.setHeaderColor('#6b7280');
      telegram_webapp.setBackgroundColor('#ffffff');
    }
    
    // First, auto-complete expired reservations
    auto_complete_reservations();
    
    load_data();
    // Check if admin is already authenticated
    check_admin_auth();
    // Load active session if exists
    const session = storage_service.get_active_session();
    if (session) {
      set_active_session(session);
    }
    // Check wallet connection status
    check_wallet_connection();
    
    // Set up periodic auto-complete check (every 30 seconds)
    // Use setTimeout with recursion to avoid blocking UI
    let timeout_id: ReturnType<typeof setTimeout> | null = null;
    const schedule_auto_complete = () => {
      timeout_id = setTimeout(() => {
        // Run in next tick to avoid blocking
        requestAnimationFrame(() => {
          auto_complete_reservations();
          schedule_auto_complete();
        });
      }, 30000);
    };
    schedule_auto_complete();
    
    return () => {
      if (timeout_id) clearTimeout(timeout_id);
    };
  }, []);

  // Read URL parameters for deep linking from Telegram bot
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const reserve_id = params.get('reserve');
    const is_premium = params.get('premium') === 'true';
    
    // Only process if we have a reserve parameter and parking spaces are loaded
    if (reserve_id && parking_spaces.length > 0) {
      // ParkingSpace.id is a string, so compare directly
      const space = parking_spaces.find(s => s.id === reserve_id);
      
      if (space) {
        // Check if license plate is required
        if (!license_plate) {
          // Show message that license plate is needed first
          const message = language_service.t('license_plate_required_for_reservation') || 
                         'Please enter your license plate first to reserve a parking space.';
          alert(message);
          // Clean URL (remove parameters)
          window.history.replaceState({}, '', window.location.pathname);
          return;
        }
        
        // Check if space is available
        if (space.status !== 'vacant') {
          const status_msg = language_service.t('space_not_available') || 
                            `This parking space is currently ${space.status}.`;
          alert(status_msg);
          // Clean URL (remove parameters)
          window.history.replaceState({}, '', window.location.pathname);
          return;
        }
        
        // Auto-open reservation modal
        set_selected_space(space);
        set_reservation_duration_hours(1); // Reset to 1 hour when opening
        set_show_reservation_modal(true);
        
        // If premium, check wallet connection
        if (is_premium && space.zone?.is_premium) {
          setTimeout(() => {
            check_wallet_connection();
          }, 200);
        }
        
        // Clean URL (remove parameters)
        window.history.replaceState({}, '', window.location.pathname);
      } else {
        // Space not found
        const not_found_msg = language_service.t('space_not_found') || 
                             `Parking space #${reserve_id} not found.`;
        alert(not_found_msg);
        // Clean URL (remove parameters)
        window.history.replaceState({}, '', window.location.pathname);
      }
    }
  }, [parking_spaces, license_plate]); // Run when data is loaded

  // Auto-refresh parking data every 30 seconds to keep map updated in real-time
  useEffect(() => {
    // Only start polling if we have data loaded
    if (parking_spaces.length === 0 && sensors.length === 0) {
      return;
    }

    const refresh_interval = setInterval(() => {
      // Silently refresh data to update map with latest status
      load_data(true);
    }, 5000); // Refresh every 5 seconds

    // Cleanup interval on unmount
    return () => {
      clearInterval(refresh_interval);
    };
  }, [parking_spaces.length, sensors.length]); // Re-run if data changes

  // Initialize TON Connect UI on app load
  useEffect(() => {
    // Initialize TON Connect UI as soon as app loads to restore wallet connection
    const initTonConnect = () => {
      try {
        // Create button root element for TonConnectUI
        let button_element = document.getElementById('ton-connect-button-root');
        if (!button_element) {
          button_element = document.createElement('div');
          button_element.id = 'ton-connect-button-root';
          button_element.style.display = 'none';
          document.body.appendChild(button_element);
        }

        // Initialize TonConnectUI
        const ui = new TonConnectUI({
          manifestUrl: `${window.location.origin}/tonconnect-manifest.json`,
          buttonRootId: 'ton-connect-button-root',
          restoreConnection: true
        });

        // Share TonConnectUI instance with TonPaymentService
        ton_payment_service.setTonConnectUI(ui);

        // Listen for wallet connection changes
        ui.onStatusChange((wallet) => {
          if (wallet && wallet.account) {
            const address = wallet.account.address;
            ton_wallet_service.setWalletAddress(address);
            set_wallet_connected(true);
          } else {
            ton_wallet_service.disconnect();
            set_wallet_connected(false);
            set_payment_tx_hash(null);
            set_payment_tx_status(null);
          }
        });

        // Check if wallet is already connected after restoration
        ui.connectionRestored.then(() => {
          if (ui.wallet && ui.wallet.account) {
            const address = ui.wallet.account.address;
            ton_wallet_service.setWalletAddress(address);
            set_wallet_connected(true);
          }
          // Also check wallet connection status
          check_wallet_connection();
        }).catch(() => {
          // Ignore restoration errors, but still check connection
          check_wallet_connection();
        });
      } catch (error) {
        console.error('Failed to initialize TON Connect UI:', error);
        // Still check wallet connection even if initialization fails
        check_wallet_connection();
      }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      initTonConnect();
    } else {
      const handle_dom_ready = () => {
        initTonConnect();
      };
      if (document.addEventListener) {
        document.addEventListener('DOMContentLoaded', handle_dom_ready, { once: true });
      }
      // Also try after a short delay as fallback
      setTimeout(() => {
        initTonConnect();
      }, 500);
    }
  }, []); // Run only once on mount

  const check_wallet_connection = () => {
    // Debounce: Clear any pending check
    if (check_wallet_timeout_ref.current) {
      clearTimeout(check_wallet_timeout_ref.current);
    }
    
    // Schedule check in next tick to avoid blocking
    check_wallet_timeout_ref.current = setTimeout(() => {
      check_wallet_in_progress_ref.current = true;
      last_wallet_check_ref.current = Date.now();
      
      // Check wallet connection using TonWalletService
      const connected = ton_wallet_service.isWalletConnected();
      
      // Only update state if we actually found a wallet connection
      if (connected) {
        set_wallet_connected(true);
      } else {
        // If wallet is disconnected, clear payment state
        set_payment_tx_hash(null);
        set_payment_tx_status(null);
      }
      
      check_wallet_timeout_ref.current = null;
      check_wallet_in_progress_ref.current = false;
    }, 300); // Debounce delay - increased to reduce calls
  };

  const handle_disconnect_wallet = async () => {
    try {
      // Use the service method to disconnect
      const result = await ton_payment_service.disconnectWallet();
      
      if (result.success) {
        // Clear wallet state
        set_wallet_connected(false);
        set_payment_tx_hash(null);
        
        // Re-check connection status
        await check_wallet_connection();
        
        console.log('Wallet disconnected successfully');
      } else {
        alert(`Failed to disconnect wallet: ${result.error || 'Unknown error'}`);
      }
    } catch (error) {
      console.error('Failed to disconnect wallet:', error);
      alert('Failed to disconnect wallet. Please try again.');
    }
  };
  
  // Check wallet connection when modal opens with premium zone
  useEffect(() => {
    if (show_reservation_modal && selected_space) {
      // Check if premium zone
      const is_premium = selected_space.zone?.is_premium === true || 
                        (typeof selected_space.zone?.is_premium === 'number' && selected_space.zone.is_premium === 1);
      
      if (is_premium) {
        // Check wallet connection immediately
        check_wallet_connection();
      }
    }
  }, [show_reservation_modal, selected_space]);

  const check_admin_auth = async () => {
    try {
      const result = await admin_service.checkSession();
      if (result.success && result.authenticated) {
        set_is_admin_authenticated(true);
      } else {
        set_is_admin_authenticated(false);
      }
    } catch (error) {
      console.error('Error checking admin auth:', error);
      set_is_admin_authenticated(false);
    }
  };

  // Filter parking spaces by street search
  useEffect(() => {
    console.log('Filtering parking spaces - parking_spaces:', parking_spaces.length, 'sensors:', sensors.length);
    
    if (street_search.trim() === '') {
      // Filter out spaces that don't have matching sensors
      const valid_spaces = parking_spaces.filter(space => {
        const sensor = sensors.find(s => String(s.id) === String(space.sensor_id));
        if (!sensor) {
          console.warn(`No sensor found for space ${space.id} with sensor_id ${space.sensor_id}`);
          return false;
        }
        return true;
      });
      console.log('Valid spaces (with sensors):', valid_spaces.length);
      set_filtered_parking_spaces(valid_spaces);
    } else {
      const filtered = parking_spaces.filter(space => {
        const sensor = sensors.find(s => String(s.id) === String(space.sensor_id));
        if (!sensor) {
          console.warn(`No sensor found for space ${space.id} with sensor_id ${space.sensor_id}`);
          return false;
        }
        return sensor.street_name.toLowerCase().includes(street_search.toLowerCase());
      });
      console.log('Filtered spaces:', filtered.length);
      set_filtered_parking_spaces(filtered);
    }
  }, [street_search, parking_spaces, sensors]);

  // Get unique streets for suggestions
  const get_unique_streets = () => {
    const street_names = new Set<string>();
    parking_spaces.forEach(space => {
      const sensor = sensors.find(s => s.id === space.sensor_id);
      if (sensor) {
        street_names.add(sensor.street_name);
      }
    });
    return Array.from(street_names).sort();
  };

  const auto_complete_reservations = async () => {
    try {
      const response = await fetch(build_api_url('/api/auto-complete-reservations.php'), create_api_options('POST', {}));
      if (response.ok) {
        const data = await response.json();
        if (data.success && data.completed_count > 0) {
          // Reload data to reflect changes (silently to avoid noise)
          await load_data(true);
        }
      }
    } catch (error) {
      // Silently fail - this is a background task
      console.error('Auto-complete reservations error:', error);
    }
  };

  // Retry helper function with exponential backoff
  const fetchWithRetry = async (url: string, options: RequestInit = {}, maxRetries: number = 3, retryDelay: number = 1000): Promise<Response> => {
    let lastError: Error | null = null;
    
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      try {
        // Add timeout to fetch
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
        
        const response = await fetch(url, {
          ...options,
          signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        
        // If successful or client error (4xx), don't retry
        if (response.ok || (response.status >= 400 && response.status < 500)) {
          return response;
        }
        
        // Server error (5xx) - retry
        if (response.status >= 500 && attempt < maxRetries) {
          const delay = retryDelay * Math.pow(2, attempt); // Exponential backoff
          console.warn(`API returned ${response.status}, retrying in ${delay}ms... (attempt ${attempt + 1}/${maxRetries + 1})`);
          await new Promise(resolve => setTimeout(resolve, delay));
          continue;
        }
        
        return response;
      } catch (error: any) {
        lastError = error;
        
        // Network error or timeout - retry
        if ((error.name === 'AbortError' || error.name === 'TypeError') && attempt < maxRetries) {
          const delay = retryDelay * Math.pow(2, attempt);
          console.warn(`Network error, retrying in ${delay}ms... (attempt ${attempt + 1}/${maxRetries + 1})`);
          await new Promise(resolve => setTimeout(resolve, delay));
          continue;
        }
        
        // If it's the last attempt, throw the error
        if (attempt === maxRetries) {
          throw error;
        }
      }
    }
    
    throw lastError || new Error('Failed to fetch data after retries');
  };

  const load_data = async (silent: boolean = false) => {
    try {
      const response = await fetchWithRetry(build_api_url('/api/data.php'), {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json'
        }
      });
      
      if (!response.ok) {
        // For 500 errors, try to parse error response
        if (response.status === 500) {
          try {
            const errorData = await response.json();
            if (!silent) {
              console.error('API Error:', errorData.error || 'Internal server error');
              if (errorData.details) {
                console.error('Error details:', errorData.details);
              }
            }
          } catch {
            // If can't parse error, just log status
            if (!silent) {
              console.error(`API returned ${response.status}: ${response.statusText}`);
            }
          }
        }
        
        // Don't throw for 500 errors - use cached data if available
        if (response.status === 500) {
          console.warn('API returned 500, keeping existing data if available');
          return;
        }
        
        throw new Error(`API returned ${response.status}: ${response.statusText}`);
      }
      
      const data = await response.json();
      
      // Log debug information if available
      if (data.debug) {
        console.log('API Debug Info:', data.debug);
      }
      
      if (data.success) {
        const sensors_data = data.sensors || [];
        const spaces = data.parking_spaces || [];
        
        // Ensure IDs are strings for consistency
        const normalized_sensors = sensors_data.map((s: any) => ({
          ...s,
          id: String(s.id)
        }));
        
        // Debug: Log raw spaces data before normalization to see if zones are present
        console.log('Raw spaces from API (first 2):', JSON.stringify(spaces.slice(0, 2), null, 2));
        console.log('Raw spaces from API - checking zones:', spaces.slice(0, 2).map((s: any) => ({ 
          id: s.id, 
          sensor_id: s.sensor_id, 
          has_zone: !!s.zone,
          zone: s.zone,
          all_keys: Object.keys(s)
        })));
        
        const normalized_spaces = spaces.map((space: any) => ({
          ...space,
          id: String(space.id),
          sensor_id: String(space.sensor_id),
          // Preserve zone data if it exists
          zone: space.zone ? {
            ...space.zone,
            id: String(space.zone.id || space.zone.zone_id || '')
          } : undefined
        }));
        
        console.log('Loaded sensors:', normalized_sensors.length, normalized_sensors.map((s: any) => ({ id: s.id, name: s.name })));
        console.log('Loaded parking spaces:', normalized_spaces.length, normalized_spaces.map((s: any) => ({ id: s.id, sensor_id: s.sensor_id, has_zone: !!s.zone })));
        
        // Debug: Log zone information for all spaces
        normalized_spaces.forEach((space: any) => {
          if (space.zone) {
            console.log(`Space ${space.id} - Zone:`, space.zone, 'is_premium:', space.zone.is_premium, 'type:', typeof space.zone.is_premium);
          } else {
            console.log(`Space ${space.id} - NO ZONE`);
          }
        });
        
        set_sensors(normalized_sensors);
        set_parking_spaces(normalized_spaces);
      } else {
        if (!silent) {
          console.error('Failed to load data from API:', data.error);
        }
        // Don't clear existing data - keep what we have
        // Only clear if we have no data at all
        if (sensors.length === 0 && parking_spaces.length === 0) {
          set_sensors([]);
          set_parking_spaces([]);
        }
      }
    } catch (error: any) {
      // Only log error if not silent mode
      if (!silent) {
        console.error('Error loading data from API:', error);
      }
      
      // Don't clear existing data on error - keep what we have
      // This prevents UI from breaking when API is temporarily unavailable
      // Only clear if we have no data at all
      if (sensors.length === 0 && parking_spaces.length === 0) {
        set_sensors([]);
        set_parking_spaces([]);
      }
    }
  };

  const handle_license_plate_set = async (plate: string) => {
    // Check if this license plate already has an active session
    const existing_session = storage_service.get_active_session();
    if (existing_session && existing_session.license_plate === plate) {
      // Restore the existing session
      set_active_session(existing_session);
      set_license_plate(plate);
      storage_service.update_user_activity();
    } else {
      // Clear any existing session and start fresh
      storage_service.clear_active_session();
      set_active_session(null);
      set_license_plate(plate);
      storage_service.update_user_activity();
    }
    
    // Check if there's a saved wallet address for auto-connection
    const saved_wallet_address = storage_service.get_saved_wallet_for_auto_connect();
    if (saved_wallet_address) {
      console.log('[DEBUG] Found saved wallet address for auto-connection:', saved_wallet_address.substring(0, 10) + '...');
      
      // Try to auto-connect wallet
      try {
        // Validate and set wallet address
        const validation_result = await ton_wallet_service.validateWalletAddress(saved_wallet_address);
        if (validation_result.success && validation_result.valid) {
          ton_wallet_service.setWalletAddress(saved_wallet_address);
          set_wallet_connected(true);
          // Clear saved wallet address since it's now connected
          storage_service.clear_saved_wallet_for_auto_connect();
          console.log('[DEBUG] ✅ Wallet auto-connected successfully');
        } else {
          console.log('[DEBUG] ⚠️ Wallet could not be auto-connected, user will need to connect manually');
          // Don't clear saved wallet address - user might want to connect later
        }
      } catch (error) {
        console.error('[DEBUG] Error during wallet auto-connection:', error);
        // Don't fail the license plate set if auto-connection fails
      }
    }
  };

  const handle_space_click = (space: ParkingSpace) => {
    // Check if user already has an active session
    if (active_session) {
      alert(`You already have an active session at parking space ${active_session.parking_space_id}. Please complete or cancel your current session before reserving another space.`);
      return;
    }
    
    // Only allow clicking on vacant spaces
    if (space.status !== 'vacant') {
      alert(`This parking space is currently ${space.status}. Only vacant spaces can be reserved.`);
      return;
    }
    
    // Debug: Log space and zone info
    console.log('handle_space_click - space:', space);
    console.log('handle_space_click - zone:', space.zone);
    if (space.zone) {
      console.log('handle_space_click - is_premium:', space.zone.is_premium, 'type:', typeof space.zone.is_premium);
    } else {
      console.log('handle_space_click - NO ZONE FOUND for space:', space.id);
    }
    
    set_selected_space(space);
    set_reservation_duration_hours(1); // Reset to 1 hour when opening
    set_show_reservation_modal(true);
  };

  const handle_reserve_space = async (space: ParkingSpace) => {
    if (!license_plate) {
      alert('Please enter your license plate first.');
      return;
    }

    // Check if user already has an active session
    if (active_session) {
      alert(`You already have an active session at parking space ${active_session.parking_space_id}. Please complete or cancel your current session before reserving another space.`);
      return;
    }

    // Check if space is already reserved or occupied (client-side check)
    if (space.status !== 'vacant') {
      const status_text = space.status === 'reserved' ? 'reserved' : space.status === 'occupied' ? 'occupied' : space.status;
      alert(`This parking space is already ${status_text}. Please select another space.`);
      // Reload data to get latest status
      await load_data(true);
      return;
    }

    // Check if this is a premium zone - MUST check zone from database/API
    const is_premium = space.zone?.is_premium === true || (typeof space.zone?.is_premium === 'number' && space.zone.is_premium === 1);
    
    console.log('handle_reserve_space - space:', space);
    console.log('handle_reserve_space - is_premium:', is_premium, 'zone:', space.zone);

    // For premium zones, ALWAYS open the reservation modal (same as map click)
    // The modal will handle wallet connection and payment
    if (is_premium) {
      set_selected_space(space);
      set_reservation_duration_hours(1);
      set_show_reservation_modal(true);
      return;
    }

    // For non-premium zones, proceed with direct reservation
    // (This path is kept for backward compatibility, but premium zones always use modal)

    const current_time = new Date().toISOString();
    
    try {
      // Update parking space status via API
      const request_body: any = {
        status: 'reserved',
        license_plate: license_plate,
        reservation_time: current_time,
        duration_hours: reservation_duration_hours
      };

      const response = await fetch(build_api_url(`/api/parking-spaces.php/${space.id}`), create_api_options('PUT', request_body));
      
      const response_data = await response.json();
      
      if (response.ok && response_data.success) {
        // Create new active session
        const new_session: ActiveSession = {
          id: `session_${Date.now()}`,
          license_plate: license_plate,
          parking_space_id: space.id,
          start_time: current_time,
          status: 'reserved',
          reservation_time: current_time
        };

        // Calculate end_time based on duration
        const end_time = new Date(current_time);
        end_time.setHours(end_time.getHours() + reservation_duration_hours);
        const end_time_iso = end_time.toISOString();

        // Update local state
        const updated_spaces = parking_spaces.map(s => 
          s.id === space.id 
            ? { 
                ...s, 
                status: 'reserved' as const, 
                license_plate, 
                reservation_time: current_time,
                reservation_end_time: end_time_iso
              }
            : s
        );
        set_parking_spaces(updated_spaces);
        
        // Set active session
        set_active_session(new_session);
        storage_service.set_active_session(new_session);
        
        // Clear payment state
        set_payment_tx_hash(null);
        
        console.log('Parking space reserved successfully');
        set_show_reservation_modal(false);
        
        // Show navigation option after successful reservation ONLY if payment was completed for premium zones
        // For premium zones, navigation should only be shown after payment is verified
        const is_premium = space.zone?.is_premium || false;
        const should_show_navigation = !is_premium || (is_premium && payment_tx_hash);
        
        if (should_show_navigation) {
          const sensor = sensors.find(s => s.id === space.sensor_id);
          if (sensor && sensor.coordinates) {
            // Show custom dialog with navigation options
            const nav_choice = window.confirm(
              `Reservation successful!\n\n` +
              `Location: ${sensor.street_name || 'Parking Space'}\n` +
              `Would you like to open navigation?\n\n` +
              `Click OK for Google Maps\n` +
              `Click Cancel to skip`
            );
            
            if (nav_choice) {
              // Open Google Maps navigation
              const google_maps_url = `https://www.google.com/maps/dir/?api=1&destination=${sensor.coordinates.lat},${sensor.coordinates.lng}&travelmode=driving`;
              window.open(google_maps_url, '_blank');
              
              // Also try to open Waze if available (mobile)
              const waze_url = `https://waze.com/ul?ll=${sensor.coordinates.lat},${sensor.coordinates.lng}&navigate=yes`;
              // Waze will open automatically on mobile if app is installed
              setTimeout(() => {
                window.open(waze_url, '_blank');
              }, 500);
            }
          }
        }
        
        // Reset payment hash after successful reservation
        set_payment_tx_hash('');
        set_selected_space(null);
        
        // Reload data to get latest status from server
        await load_data(true);
      } else {
        // Handle specific error cases
        let error_msg = response_data.error || 'Failed to reserve parking space';
        
        // Check if space is already reserved/occupied (HTTP 409 Conflict)
        if (response.status === 409 || (response_data.error && (
          response_data.error.includes('already') || 
          response_data.error.includes('reserved') || 
          response_data.error.includes('occupied') ||
          response_data.error.includes('status has changed')
        ))) {
          error_msg = `This parking space is no longer available. It has been ${response_data.current_status || 'reserved'} by another user. Please select another space.`;
          // Reload data to get latest status
          await load_data(true);
        }
        
        alert(error_msg);
        console.error('Failed to reserve parking space:', error_msg, 'Status:', response.status);
      }
    } catch (error) {
      console.error('Error reserving parking space:', error);
      alert('An error occurred while reserving the space. Please try again.');
      // Reload data on error to ensure UI is in sync
      await load_data(true);
    }
  };

  // Handle reservation confirmation from modal (for both premium and non-premium zones)
  const handle_confirm_reservation = async (space: ParkingSpace) => {
    if (!license_plate) {
      alert('Please enter your license plate first.');
      return;
    }

    // Check if user already has an active session
    if (active_session) {
      alert(`You already have an active session at parking space ${active_session.parking_space_id}. Please complete or cancel your current session before reserving another space.`);
      return;
    }

    // Check if space is already reserved or occupied (client-side check)
    if (space.status !== 'vacant') {
      const status_text = space.status === 'reserved' ? 'reserved' : space.status === 'occupied' ? 'occupied' : space.status;
      alert(`This parking space is already ${status_text}. Please select another space.`);
      // Reload data to get latest status
      await load_data(true);
      return;
    }

    // Check if this is a premium zone
    const is_premium = space.zone?.is_premium === true || (typeof space.zone?.is_premium === 'number' && space.zone.is_premium === 1);
    let tx_hash = payment_tx_hash;

    // For premium zones, verify payment before creating reservation
    if (is_premium) {
      if (!tx_hash) {
        alert('Please complete payment first before confirming reservation.');
        return;
      }

      // Verify payment on blockchain before creating reservation
      set_payment_processing(true);
      try {
        const verify_response = await fetch(build_api_url('/api/verify-ton-payment.php'), create_api_options('POST', {
          space_id: space.id,
          tx_hash: tx_hash,
          license_plate: license_plate,
          amount_nano: ton_payment_service.calculatePriceInTon(space, space.zone, reservation_duration_hours)
        }));

        const verify_data = await verify_response.json();
        
        if (!verify_response.ok || !verify_data.success) {
          alert(`Payment verification failed on blockchain: ${verify_data.error || 'Transaction not verified'}\n\nPlease complete payment first.`);
          set_payment_processing(false);
          set_payment_tx_hash(null); // Clear invalid tx_hash
          return;
        }
        
        // Payment is verified on blockchain - proceed with reservation
        console.log('Payment verified on blockchain, proceeding with reservation');
      } catch (error) {
        console.error('Payment verification error:', error);
        alert('Failed to verify payment on blockchain. Please try again.');
        set_payment_processing(false);
        set_payment_tx_hash(null); // Clear invalid tx_hash
        return;
      }
      set_payment_processing(false);
    }

    const current_time = new Date().toISOString();
    
    try {
      // Update parking space status via API
      const request_body: any = {
        status: 'reserved',
        license_plate: license_plate,
        reservation_time: current_time,
        duration_hours: reservation_duration_hours
      };

      // Add payment tx hash if premium zone
      if (is_premium && tx_hash) {
        request_body.payment_tx_hash = tx_hash;
      }

      const response = await fetch(build_api_url(`/api/parking-spaces.php/${space.id}`), create_api_options('PUT', request_body));
      
      const response_data = await response.json();
      
      if (response.ok && response_data.success) {
        // Create new active session
        const new_session: ActiveSession = {
          id: `session_${Date.now()}`,
          license_plate: license_plate,
          parking_space_id: space.id,
          start_time: current_time,
          status: 'reserved',
          reservation_time: current_time
        };

        // Calculate end_time based on duration
        const end_time = new Date(current_time);
        end_time.setHours(end_time.getHours() + reservation_duration_hours);
        const end_time_iso = end_time.toISOString();

        // Update local state
        const updated_spaces = parking_spaces.map(s => 
          s.id === space.id 
            ? { 
                ...s, 
                status: 'reserved' as const, 
                license_plate, 
                reservation_time: current_time,
                reservation_end_time: end_time_iso
              }
            : s
        );
        set_parking_spaces(updated_spaces);
        
        // Set active session
        set_active_session(new_session);
        storage_service.set_active_session(new_session);
        
        // Clear payment state
        set_payment_tx_hash(null);
        
        console.log('Parking space reserved successfully');
        set_show_reservation_modal(false);
        
        // Show navigation option after successful reservation
        const sensor = sensors.find(s => s.id === space.sensor_id);
        if (sensor && sensor.coordinates) {
          // Show custom dialog with navigation options
          const nav_choice = window.confirm(
            `Reservation successful!\n\n` +
            `Location: ${sensor.street_name || 'Parking Space'}\n` +
            `Would you like to open navigation?\n\n` +
            `Click OK for Google Maps\n` +
            `Click Cancel to skip`
          );
          
          if (nav_choice) {
            // Open Google Maps navigation
            const google_maps_url = `https://www.google.com/maps/dir/?api=1&destination=${sensor.coordinates.lat},${sensor.coordinates.lng}&travelmode=driving`;
            window.open(google_maps_url, '_blank');
            
            // Also try to open Waze if available (mobile)
            const waze_url = `https://waze.com/ul?ll=${sensor.coordinates.lat},${sensor.coordinates.lng}&navigate=yes`;
            // Waze will open automatically on mobile if app is installed
            setTimeout(() => {
              window.open(waze_url, '_blank');
            }, 500);
          }
        }
        
        // Reset payment hash after successful reservation
        set_payment_tx_hash('');
        set_selected_space(null);
        
        // Reload data to get latest status from server
        await load_data(true);
      } else {
        // Handle specific error cases
        let error_msg = response_data.error || 'Failed to reserve parking space';
        
        // Check if space is already reserved/occupied (HTTP 409 Conflict)
        if (response.status === 409 || (response_data.error && (
          response_data.error.includes('already') || 
          response_data.error.includes('reserved') || 
          response_data.error.includes('occupied') ||
          response_data.error.includes('status has changed')
        ))) {
          error_msg = `This parking space is no longer available. It has been ${response_data.current_status || 'reserved'} by another user. Please select another space.`;
          // Reload data to get latest status
          await load_data(true);
        }
        
        alert(error_msg);
        console.error('Failed to reserve parking space:', error_msg, 'Status:', response.status);
      }
    } catch (error) {
      console.error('Error reserving parking space:', error);
      alert('An error occurred while reserving the space. Please try again.');
      // Reload data on error to ensure UI is in sync
      await load_data(true);
    }
  };

  const handle_ton_payment = async (space: ParkingSpace) => {
    if (!space.zone) {
      alert('Zone information not available');
      return;
    }

    if (!license_plate) {
      alert('Please enter your license plate first.');
      return;
    }

    // Check if wallet is connected
    if (!wallet_connected) {
      alert('Please connect your TON wallet first.');
      return;
    }

    // Check if user already has an active session
    if (active_session) {
      alert(`You already have an active session at parking space ${active_session.parking_space_id}. Please complete or cancel your current session before reserving another space.`);
      return;
    }

    set_payment_processing(true);
    let stop_monitoring: (() => void) | undefined;
    let reservation_created = false;

    try {
      const amount_nano = ton_payment_service.calculatePriceInTon(space, space.zone, reservation_duration_hours);
      const amount_ton = parseFloat(amount_nano) / Math.pow(10, 9);

      // Callback for transaction status updates
      const onTransactionStatus = async (status: TransactionStatus) => {
        set_payment_tx_status(status.status === 'confirmed' ? 'confirmed' : status.status === 'failed' ? 'failed' : 'pending');
        
        if (status.status === 'confirmed' && !reservation_created) {
          reservation_created = true;
          
          if (stop_monitoring) {
            stop_monitoring();
          }

          // Verify payment with backend
          try {
            const verify_response = await fetch(build_api_url('/api/verify-ton-payment.php'), create_api_options('POST', {
              space_id: space.id,
              tx_hash: status.tx_hash || payment_tx_hash || '',
              license_plate: license_plate,
              amount_nano: amount_nano
            }));

            const verify_data = await verify_response.json();
            
            if (!verify_response.ok || !verify_data.success) {
              alert(`Transaction confirmed but verification failed: ${verify_data.error || 'Unknown error'}\n\nPlease try to confirm reservation manually.`);
              set_payment_processing(false);
              return;
            }

            // Payment verified - automatically create reservation
            await create_reservation_after_payment(space, status.tx_hash || payment_tx_hash || '');
            
          } catch (verify_error) {
            console.error('Payment verification error:', verify_error);
            // Show alert only for backend verification errors (not TON Connect UI errors)
            alert('Transaction confirmed but verification failed. Please try to confirm reservation manually.');
            set_payment_processing(false);
          }
        } else if (status.status === 'failed') {
          // TON Connect UI will show its own error message for transaction failures
          // Only show alert if it's a backend/system error, not user rejection
          if (status.error && !status.error.includes('reject') && !status.error.includes('User rejects')) {
            alert(`Transaction failed: ${status.error || 'Unknown error'}`);
          }
          set_payment_processing(false);
          if (stop_monitoring) {
            stop_monitoring();
          }
        }
      };

      const payment_result = await ton_payment_service.processPayment(
        space, 
        space.zone, 
        reservation_duration_hours,
        onTransactionStatus
      );
      
      if (!payment_result.success || !payment_result.tx_hash) {
        // TON Connect UI will show its own error message - no need for duplicate alert
        // Only log the error for debugging
        if (payment_result.error) {
          console.error('Payment failed:', payment_result.error);
        }
        set_payment_processing(false);
        return;
      }

      // Payment sent - store transaction hash and start monitoring
      const tx_hash = payment_result.tx_hash;
      set_payment_tx_hash(tx_hash);
      set_payment_tx_status('pending');
      stop_monitoring = payment_result.monitorTransaction;
      
      // TON Connect UI will show success message, no need for additional alert
      
    } catch (error) {
      console.error('Payment error:', error);
      // Only show alert for unexpected errors, not TON Connect UI errors
      const error_message = error instanceof Error ? error.message : 'An error occurred during payment';
      if (!error_message.includes('TON_CONNECT') && !error_message.includes('reject')) {
        alert(`Payment error: ${error_message}`);
      }
      set_payment_processing(false);
      if (stop_monitoring) {
        stop_monitoring();
      }
    }
  };

  // Helper function to create reservation after payment is verified
  const create_reservation_after_payment = async (space: ParkingSpace, tx_hash: string) => {
    try {
      const current_time = new Date().toISOString();
      
      // Update parking space status via API
      const request_body: any = {
        status: 'reserved',
        license_plate: license_plate,
        reservation_time: current_time,
        duration_hours: reservation_duration_hours,
        payment_tx_hash: tx_hash
      };

      const response = await fetch(build_api_url(`/api/parking-spaces.php/${space.id}`), create_api_options('PUT', request_body));
      
      const response_data = await response.json();
      
      if (response.ok && response_data.success) {
        // Create new active session
        const new_session: ActiveSession = {
          id: `session_${Date.now()}`,
          license_plate: license_plate!,
          parking_space_id: space.id,
          start_time: current_time,
          status: 'reserved',
          reservation_time: current_time
        };

        // Calculate end_time based on duration
        const end_time = new Date(current_time);
        end_time.setHours(end_time.getHours() + reservation_duration_hours);
        const end_time_iso = end_time.toISOString();

        // Update local state
        const updated_spaces = parking_spaces.map(s => 
          s.id === space.id 
            ? { 
                ...s, 
                status: 'reserved' as const, 
                license_plate: license_plate || undefined, 
                reservation_time: current_time,
                reservation_end_time: end_time_iso
              }
            : s
        );
        set_parking_spaces(updated_spaces);
        
        // Set active session
        set_active_session(new_session);
        storage_service.set_active_session(new_session);
        
        // Clear payment state
        set_payment_tx_hash(null);
        set_payment_tx_status(null);
        set_payment_processing(false);
        
        console.log('Parking space reserved successfully after payment confirmation');
        set_show_reservation_modal(false);
        
        // Show success message
        const sensor = sensors.find(s => s.id === space.sensor_id);
        const location_name = sensor?.street_name || 'Parking Space';
        
        const nav_choice = window.confirm(
          `✅ Reservation created successfully!\n\n` +
          `Location: ${location_name}\n` +
          `Transaction confirmed on blockchain.\n\n` +
          `Would you like to open navigation?\n\n` +
          `Click OK for Google Maps\n` +
          `Click Cancel to skip`
        );
        
        if (nav_choice && sensor?.coordinates) {
          // Open Google Maps navigation
          const google_maps_url = `https://www.google.com/maps/dir/?api=1&destination=${sensor.coordinates.lat},${sensor.coordinates.lng}&travelmode=driving`;
          window.open(google_maps_url, '_blank');
          
          // Also try to open Waze if available (mobile)
          const waze_url = `https://waze.com/ul?ll=${sensor.coordinates.lat},${sensor.coordinates.lng}&navigate=yes`;
          setTimeout(() => {
            window.open(waze_url, '_blank');
          }, 500);
        }
        
        set_selected_space(null);
      } else {
        const error_msg = response_data.error || 'Failed to reserve parking space';
        alert(`Reservation failed: ${error_msg}`);
        console.error('Failed to reserve parking space:', error_msg);
        set_payment_processing(false);
      }
    } catch (error) {
      console.error('Error creating reservation after payment:', error);
      alert('An error occurred while creating the reservation. Please try again or contact support.');
      set_payment_processing(false);
    }
  };

  const handle_complete_session = async (space: ParkingSpace) => {
    if (!license_plate) {
      alert('You must be logged in to complete a session.');
      return;
    }
    
    // Verify that this is the user's reservation
    if (space.license_plate !== license_plate) {
      alert('You can only complete sessions for your own license plate.');
      return;
    }
    
    try {
      // Update parking space status via API
      const response = await fetch(build_api_url(`/api/parking-spaces.php/${space.id}`), {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          status: 'vacant',
          license_plate: license_plate, // Send license_plate for verification
          reservation_time: null,
          occupied_since: null
        })
      });
      
      if (response.ok) {
        // Update local state
        const updated_spaces = parking_spaces.map(s => 
          s.id === space.id 
            ? { ...s, status: 'vacant' as const, license_plate: undefined, reservation_time: undefined, occupied_since: undefined }
            : s
        );
        set_parking_spaces(updated_spaces);
        
        // Clear active session if this was the user's session
        if (active_session && active_session.parking_space_id === space.id) {
          set_active_session(null);
          storage_service.clear_active_session();
        }
        
        console.log('Session completed for space:', space.id);
      } else {
        console.error('Failed to complete session');
      }
    } catch (error) {
      console.error('Error completing session:', error);
    }
  };

  const handle_navigate = (coordinates: { lat: number; lng: number }) => {
    const current_user_location = user_location || MAPS_CONFIG.USER_LOCATION;
    // Use the Google Maps API key from environment variables
    const url = `${GOOGLE_MAPS_URLS.DIRECTIONS}?api=1&destination=${coordinates.lat},${coordinates.lng}&travelmode=driving&origin=${current_user_location.lat},${current_user_location.lng}&key=${get_maps_api_key()}`;
    window.open(url, '_blank');
  };

  const handle_change_plate = () => {
    storage_service.clear_user_session();
    storage_service.clear_active_session();
    set_license_plate(null);
    set_active_session(null);
  };

  const handle_admin_login_success = () => {
    set_is_admin_authenticated(true);
    // Refresh admin authentication status
    check_admin_auth();
  };

  const handle_cancel_session = async () => {
    if (!active_session) return;

    try {
      // Update parking space status via API
      const response = await fetch(build_api_url(`/api/parking-spaces.php/${active_session.parking_space_id}`), {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          status: 'vacant',
          license_plate: null,
          reservation_time: null,
          occupied_since: null
        })
      });
      
      if (response.ok) {
        // Update local state
        const updated_spaces = parking_spaces.map(s => 
          s.id === active_session.parking_space_id 
            ? { ...s, status: 'vacant' as const, license_plate: undefined, reservation_time: undefined, occupied_since: undefined }
            : s
        );
        set_parking_spaces(updated_spaces);
        
        // Clear active session
        set_active_session(null);
        storage_service.clear_active_session();
        
        console.log('Session cancelled successfully');
      } else {
        console.error('Failed to cancel session');
      }
    } catch (error) {
      console.error('Error cancelling session:', error);
    }
  };

  const handle_park_car = async () => {
    if (!active_session || active_session.status !== 'reserved') return;

    const current_time = new Date().toISOString();
    
    try {
      // Update parking space status to occupied
      const response = await fetch(build_api_url(`/api/parking-spaces.php/${active_session.parking_space_id}`), {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          status: 'occupied',
          license_plate: active_session.license_plate,
          occupied_since: current_time
        })
      });
      
      if (response.ok) {
        // Update local state
        const updated_spaces = parking_spaces.map(s => 
          s.id === active_session.parking_space_id 
            ? { ...s, status: 'occupied' as const, occupied_since: current_time }
            : s
        );
        set_parking_spaces(updated_spaces);
        
        // Update active session
        const updated_session = { ...active_session, status: 'occupied' as const, occupied_since: current_time };
        set_active_session(updated_session);
        storage_service.set_active_session(updated_session);
        
        console.log('Car parked successfully');
      } else {
        console.error('Failed to park car');
      }
    } catch (error) {
      console.error('Error parking car:', error);
    }
  };

  if (!license_plate) {
    return <LicensePlateInput onLicensePlateSet={handle_license_plate_set} />;
  }

  return (
    <Router>
        <div className="app">
        <div className="header-container">
          <Header
            license_plate={license_plate}
            on_change_plate={handle_change_plate}
            telegram_collapsed={telegram_collapsed}
            on_telegram_expand={() => set_telegram_collapsed(false)}
          />
          {!telegram_collapsed && (
            <TelegramLink 
              license_plate={license_plate || ''} 
              on_collapsed_change={set_telegram_collapsed}
            />
          )}
        </div>

        <nav className="app-navigation">
          <button 
            className={`nav-btn ${active_view === 'map' ? 'active' : ''}`}
            onClick={() => set_active_view('map')}
          >
            <Map size={20} />
            {language_service.t('map_view')}
          </button>
          <button 
            className={`nav-btn ${active_view === 'spaces' ? 'active' : ''}`}
            onClick={() => set_active_view('spaces')}
          >
            <Car size={20} />
            {language_service.t('spaces')}
          </button>
          <button 
            className={`nav-btn ${active_view === 'admin' ? 'active' : ''}`}
            onClick={() => set_active_view('admin')}
          >
            <BarChart3 size={20} />
            {language_service.t('admin')}
          </button>
        </nav>

        {/* Session Status Indicator */}
        {active_session && (
          <div className="session-status-indicator">
            <div className="session-info">
              <h4>{language_service.t('active_session')}</h4>
              <p>{language_service.t('space')}: {active_session.parking_space_id}</p>
              <p>{language_service.t('status')}: <span className={`status-${active_session.status}`}>{active_session.status}</span></p>
              <p>{language_service.t('started')}: {new Date(active_session.start_time).toLocaleString()}</p>
              <div className="session-notice">
                <AlertCircle size={16} />
                <span>{language_service.t('cannot_reserve_other_spaces')}</span>
              </div>
            </div>
            <div className="session-actions">
              {active_session.status === 'reserved' && (
                <button 
                  className="park-car-btn"
                  onClick={handle_park_car}
                >
                  {language_service.t('park_car')}
                </button>
              )}
              <button 
                className="cancel-session-btn"
                onClick={handle_cancel_session}
              >
                {language_service.t('cancel_session')}
              </button>
              <button 
                className="complete-session-btn"
                onClick={() => {
                  const space = parking_spaces.find(s => s.id === active_session.parking_space_id);
                  if (space) handle_complete_session(space);
                }}
              >
                {language_service.t('complete_session')}
              </button>
            </div>
          </div>
        )}

        <main className="app-main">
          {active_view === 'map' && (
            <div className="map-view">
              <ParkingMap
                parking_spaces={parking_spaces}
                sensors={sensors}
                on_space_click={handle_space_click}
                show_reservation_modal={false}
                selected_space={null}
                license_plate={license_plate}
                on_reserve_space={handle_reserve_space}
                on_close_reservation_modal={() => set_show_reservation_modal(false)}
                user_location={user_location || MAPS_CONFIG.USER_LOCATION}
                on_request_location={request_user_location}
              />
            </div>
          )}

          {active_view === 'spaces' && (
            <div className="spaces-view">
              <div className="street-search-container">
                <input
                  type="text"
                  placeholder={language_service.t('search_streets_placeholder')}
                  value={street_search}
                  onChange={(e) => set_street_search(e.target.value)}
                  className="street-search-input"
                />
                <div className="street-search-info">
                  {street_search.trim() === '' 
                    ? language_service.t('showing_all_spaces').replace('{count}', parking_spaces.length.toString())
                    : language_service.t('found_spaces_in_street').replace('{count}', filtered_parking_spaces.length.toString()).replace('{street}', street_search)
                  }
                </div>
                <div className="street-suggestions">
                  <span className="suggestions-label">{language_service.t('available_streets')}</span>
                  <div className="street-tags">
                    {get_unique_streets().map(street => (
                      <button
                        key={street}
                        className={`street-tag ${street_search === street ? 'active' : ''}`}
                        onClick={() => set_street_search(street)}
                        title={language_service.t('show_parking_spaces_in_street').replace('{street}', street)}
                      >
                        {street}
                      </button>
                    ))}
                  </div>
                </div>
              </div>
              <div className="spaces-grid">
                {filtered_parking_spaces.map(space => {
                  const sensor = sensors.find(s => s.id === space.sensor_id);
                  if (!sensor) return null;
                  
                  return (
                    <ParkingSpaceCard
                      key={space.id}
                      space={space}
                      sensor={sensor}
                      on_reserve={handle_reserve_space}
                      on_navigate={handle_navigate}
                      on_complete_session={handle_complete_session}
                      active_session={active_session}
                      license_plate={license_plate}
                    />
                  );
                })}
              </div>
            </div>
          )}

          {active_view === 'admin' && (
            is_admin_authenticated ? (
              <AdminDashboard />
            ) : (
              <AdminLogin onLoginSuccess={handle_admin_login_success} />
            )
          )}


        </main>

        {/* Reservation Bottom Sheet */}
        {show_reservation_modal && selected_space && (
          <BottomSheet
            is_open={show_reservation_modal}
            on_close={() => {
              set_show_reservation_modal(false);
              set_reservation_duration_hours(1); // Reset duration when closing
            }}
            space={selected_space}
            license_plate={license_plate || ''}
          >
            <div style={{ textAlign: 'center' }}>
              <h3 
                className="modal-title"
                style={{
                  fontSize: '1.5rem',
                  fontWeight: '700',
                  marginBottom: '1.5rem',
                  color: '#2c3e50',
                  textAlign: 'center'
                }}
              >
                {language_service.t('reserve_parking_space')}
              </h3>
              
              <div className="space-details" style={{
                backgroundColor: '#f9fafb',
                borderRadius: '12px',
                padding: '1rem',
                marginBottom: '1.5rem'
              }}>
                <div className="space-detail-item" style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  padding: '0.75rem 0',
                  borderBottom: '1px solid #e5e7eb'
                }}>
                  <span className="space-detail-label" style={{ color: '#6b7280', fontWeight: '500' }}>{language_service.t('space_id')}:</span>
                  <span className="space-detail-value" style={{ color: '#1f2937', fontWeight: '600' }}>{selected_space.id}</span>
                </div>
                <div className="space-detail-item" style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  padding: '0.75rem 0',
                  borderBottom: '1px solid #e5e7eb'
                }}>
                  <span className="space-detail-label" style={{ color: '#6b7280', fontWeight: '500' }}>{language_service.t('license_plate')}:</span>
                  <span className="space-detail-value" style={{ color: '#1f2937', fontWeight: '600' }}>{license_plate}</span>
                </div>
                <div className="space-detail-item" style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  padding: '0.75rem 0',
                  borderBottom: selected_space.zone ? '1px solid #e5e7eb' : 'none'
                }}>
                  <span className="space-detail-label" style={{ color: '#6b7280', fontWeight: '500' }}>{language_service.t('status')}:</span>
                  <span className="space-detail-value" style={{ color: '#1f2937', fontWeight: '600', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                    <span className={`status-indicator status-${selected_space.status}`} style={{
                      width: '12px',
                      height: '12px',
                      borderRadius: '50%',
                      display: 'inline-block'
                    }}></span>
                    {selected_space.status}
                  </span>
                </div>
                {selected_space.zone && (
                  <div className="space-detail-item" style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    padding: '0.75rem 0',
                    alignItems: 'center'
                  }}>
                    <span className="space-detail-label" style={{ color: '#6b7280', fontWeight: '500' }}>Zone:</span>
                    <span className="space-detail-value" style={{ color: '#1f2937', fontWeight: '600', display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                      {selected_space.zone.name}
                      {selected_space.zone.is_premium && (
                        <span style={{ color: '#f59e0b', fontWeight: 'bold', fontSize: '0.875rem' }}>(Premium)</span>
                      )}
                    </span>
                  </div>
                )}
              </div>

              {/* Duration Selection */}
              {selected_space.zone && (
                <div style={{
                  marginTop: '1.5rem',
                  marginBottom: '1.5rem',
                  padding: '1rem',
                  backgroundColor: '#f9fafb',
                  borderRadius: '12px',
                  border: '1px solid #e5e7eb'
                }}>
                  <label style={{
                    display: 'block',
                    marginBottom: '0.75rem',
                    fontSize: '0.9rem',
                    fontWeight: '600',
                    color: '#1f2937'
                  }}>
                    Select Duration (Hours)
                  </label>
                  <div style={{
                    display: 'flex',
                    gap: '0.5rem',
                    flexWrap: 'wrap',
                    justifyContent: 'center'
                  }}>
                    {Array.from({ length: selected_space.zone.max_duration_hours || 4 }, (_, i) => i + 1).map((hours) => (
                      <button
                        key={hours}
                        onClick={() => set_reservation_duration_hours(hours)}
                        style={{
                          padding: '0.75rem 1.25rem',
                          border: reservation_duration_hours === hours ? '2px solid #6b7280' : '1px solid #e5e7eb',
                          borderRadius: '8px',
                          fontSize: '0.9rem',
                          fontWeight: reservation_duration_hours === hours ? '600' : '500',
                          cursor: 'pointer',
                          background: reservation_duration_hours === hours ? '#6b7280' : 'white',
                          color: reservation_duration_hours === hours ? 'white' : '#1f2937',
                          transition: 'all 0.2s ease',
                          minWidth: '60px'
                        }}
                      >
                        {hours}h
                      </button>
                    ))}
                  </div>
                  <p style={{
                    marginTop: '0.75rem',
                    fontSize: '0.85rem',
                    color: '#6b7280',
                    textAlign: 'center'
                  }}>
                    Max duration: {selected_space.zone.max_duration_hours || 4} hours
                  </p>
                </div>
              )}

              {/* TON Payment Section for Premium Zones */}
              {(() => {
                if (!selected_space.zone) {
                  console.log('BottomSheet - No zone found for space:', selected_space.id);
                  return false;
                }
                const is_premium = selected_space.zone.is_premium === true || 
                                  (typeof selected_space.zone.is_premium === 'number' && selected_space.zone.is_premium === 1) ||
                                  (typeof selected_space.zone.is_premium === 'string' && selected_space.zone.is_premium === '1');
                console.log('BottomSheet - selected_space:', selected_space);
                console.log('BottomSheet - zone:', selected_space.zone);
                console.log('BottomSheet - is_premium check:', is_premium, 'type:', typeof selected_space.zone.is_premium, 'value:', selected_space.zone.is_premium);
                console.log('BottomSheet - wallet_connected state:', wallet_connected);
                console.log('BottomSheet - payment_tx_hash:', payment_tx_hash);
                return is_premium;
              })() && selected_space.zone && (
                <div className="ton-payment-section" style={{
                  marginTop: '1.5rem',
                  marginBottom: '1.5rem',
                  padding: '1rem',
                  backgroundColor: '#f9fafb',
                  borderRadius: '12px',
                  border: '1px solid #e5e7eb'
                }}>
                  <div style={{ marginBottom: '1rem' }}>
                    <strong style={{ color: '#1f2937' }}>Payment Required (Premium Zone)</strong>
                    <p style={{ margin: '0.5rem 0', fontSize: '0.9rem', color: '#6b7280' }}>
                      Price: {selected_space.zone?.hourly_rate || 0} TON/hour × {reservation_duration_hours} hour{reservation_duration_hours > 1 ? 's' : ''} = {(selected_space.zone?.hourly_rate || 0) * reservation_duration_hours} TON
                    </p>
                  </div>
                  
                  {!wallet_connected && (
                    <div style={{ marginBottom: '1rem' }}>
                      <p style={{ fontSize: '0.9rem', color: '#6b7280', marginBottom: '0.75rem' }}>
                        Connect your TON wallet to proceed:
                      </p>
                      <button
                        onClick={async () => {
                          // Re-check wallet connection before opening modal
                          await check_wallet_connection();
                          // Wait a bit for state to update
                          await new Promise(resolve => setTimeout(resolve, 100));
                          // Check wallet connection
                          check_wallet_connection();
                          if (!wallet_connected) {
                            set_show_wallet_sheet(true);
                          }
                        }}
                        style={{
                          padding: '0.75rem 1.5rem',
                          border: 'none',
                          borderRadius: '8px',
                          fontSize: '0.9rem',
                          fontWeight: '600',
                          cursor: 'pointer',
                          background: '#6b7280',
                          color: 'white',
                          width: '100%',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          gap: '0.5rem',
                          boxShadow: '0 4px 12px rgba(107, 114, 128, 0.3)'
                        }}
                      >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                          <path d="M21 12V7H5a2 2 0 0 1 0-4h14v4" />
                          <path d="M3 5v14a2 2 0 0 0 2 2h16v-5" />
                          <path d="M18 12a2 2 0 0 0 0 4h4v-4Z" />
                        </svg>
                        Connect Wallet
                      </button>
                    </div>
                  )}

                  {wallet_connected && (
                    <div style={{ marginBottom: '1rem', display: 'flex', gap: '0.5rem', flexDirection: 'column' }}>
                      <button
                        onClick={() => set_show_wallet_sheet(true)}
                        style={{
                          padding: '0.5rem 1rem',
                          border: '1px solid #e5e7eb',
                          borderRadius: '8px',
                          fontSize: '0.85rem',
                          fontWeight: '500',
                          cursor: 'pointer',
                          background: 'white',
                          color: '#6b7280',
                          width: '100%',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          gap: '0.5rem'
                        }}
                      >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                          <path d="M21 12V7H5a2 2 0 0 1 0-4h14v4" />
                          <path d="M3 5v14a2 2 0 0 0 2 2h16v-5" />
                          <path d="M18 12a2 2 0 0 0 0 4h4v-4Z" />
                        </svg>
                        Manage Wallet
                      </button>
                      <button
                        onClick={handle_disconnect_wallet}
                        style={{
                          padding: '0.5rem 1rem',
                          border: '1px solid #ef4444',
                          borderRadius: '8px',
                          fontSize: '0.85rem',
                          fontWeight: '500',
                          cursor: 'pointer',
                          background: 'white',
                          color: '#ef4444',
                          width: '100%',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          gap: '0.5rem'
                        }}
                      >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                          <path d="M18 6L6 18M6 6l12 12" />
                        </svg>
                        Disconnect Wallet
                      </button>
                    </div>
                  )}

                  {wallet_connected && !payment_tx_hash && (
                    <button
                      onClick={() => handle_ton_payment(selected_space)}
                      disabled={payment_processing}
                      style={{
                        padding: '0.75rem 1.5rem',
                        border: 'none',
                        borderRadius: '8px',
                        fontSize: '0.9rem',
                        fontWeight: '600',
                        cursor: payment_processing ? 'not-allowed' : 'pointer',
                        background: payment_processing ? '#9ca3af' : '#6b7280',
                        color: 'white',
                        width: '100%',
                        marginBottom: '1rem'
                      }}
                    >
                      {payment_processing ? 'Processing Payment...' : `Pay ${(selected_space.zone?.hourly_rate || 0) * reservation_duration_hours} TON`}
                    </button>
                  )}

                  {payment_tx_hash && (
                    <div style={{
                      padding: '1rem',
                      backgroundColor: payment_tx_status === 'confirmed' ? '#d1fae5' : payment_tx_status === 'failed' ? '#fee2e2' : '#fef3c7',
                      borderRadius: '8px',
                      marginBottom: '1rem',
                      border: `1px solid ${payment_tx_status === 'confirmed' ? '#10b981' : payment_tx_status === 'failed' ? '#ef4444' : '#f59e0b'}`
                    }}>
                      <div style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '0.5rem',
                        marginBottom: '0.5rem'
                      }}>
                        {payment_tx_status === 'confirmed' ? (
                          <span style={{ fontSize: '1.2rem' }}>✅</span>
                        ) : payment_tx_status === 'failed' ? (
                          <span style={{ fontSize: '1.2rem' }}>❌</span>
                        ) : (
                          <span style={{ fontSize: '1.2rem' }}>⏳</span>
                        )}
                        <strong style={{
                          fontSize: '0.9rem',
                          color: payment_tx_status === 'confirmed' ? '#065f46' : payment_tx_status === 'failed' ? '#991b1b' : '#92400e'
                        }}>
                          {payment_tx_status === 'confirmed' ? 'Transaction Confirmed' : payment_tx_status === 'failed' ? 'Transaction Failed' : 'Transaction Pending'}
                        </strong>
                      </div>
                      <div style={{
                        fontSize: '0.8rem',
                        color: payment_tx_status === 'confirmed' ? '#047857' : payment_tx_status === 'failed' ? '#dc2626' : '#d97706',
                        marginBottom: '0.75rem',
                        wordBreak: 'break-all'
                      }}>
                        Hash: {payment_tx_hash.substring(0, 30)}...
                      </div>
                      <a
                        href={ton_payment_service.getTonApiService().getTransactionExplorerUrl(payment_tx_hash, 'tonviewer')}
                        target="_blank"
                        rel="noopener noreferrer"
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '0.5rem',
                          padding: '0.5rem 1rem',
                          backgroundColor: payment_tx_status === 'confirmed' ? '#10b981' : payment_tx_status === 'failed' ? '#ef4444' : '#f59e0b',
                          color: 'white',
                          borderRadius: '6px',
                          textDecoration: 'none',
                          fontSize: '0.85rem',
                          fontWeight: '500',
                          cursor: 'pointer',
                          transition: 'opacity 0.2s'
                        }}
                        onMouseEnter={(e) => e.currentTarget.style.opacity = '0.8'}
                        onMouseLeave={(e) => e.currentTarget.style.opacity = '1'}
                      >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                          <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                          <polyline points="15 3 21 3 21 9" />
                          <line x1="10" y1="14" x2="21" y2="3" />
                        </svg>
                        View on TONScan
                      </a>
                    </div>
                  )}
                </div>
              )}
              
              <div className="reservation-actions" style={{
                display: 'flex',
                flexDirection: 'column',
                gap: '0.75rem',
                marginTop: '1.5rem'
              }}>
                <button 
                  onClick={() => handle_confirm_reservation(selected_space)}
                  className="reservation-btn primary"
                  disabled={payment_processing || ((selected_space.zone?.is_premium === true || (typeof selected_space.zone?.is_premium === 'number' && selected_space.zone.is_premium === 1)) && !payment_tx_hash)}
                  style={{
                    padding: '1rem 2rem',
                    border: 'none',
                    borderRadius: '12px',
                    fontSize: '1rem',
                    fontWeight: '600',
                    cursor: (payment_processing || ((selected_space.zone?.is_premium === true || (typeof selected_space.zone?.is_premium === 'number' && selected_space.zone.is_premium === 1)) && !payment_tx_hash)) ? 'not-allowed' : 'pointer',
                    width: '100%',
                    background: (payment_processing || ((selected_space.zone?.is_premium === true || (typeof selected_space.zone?.is_premium === 'number' && selected_space.zone.is_premium === 1)) && !payment_tx_hash)) ? '#9ca3af' : '#10b981',
                    color: 'white',
                    boxShadow: '0 4px 12px rgba(16, 185, 129, 0.3)'
                  }}
                >
                  {payment_processing ? 'Processing...' : language_service.t('confirm_reservation')}
                </button>
                <button 
                  onClick={() => set_show_reservation_modal(false)}
                  className="reservation-btn secondary"
                  style={{
                    padding: '1rem 2rem',
                    border: 'none',
                    borderRadius: '12px',
                    fontSize: '1rem',
                    fontWeight: '600',
                    cursor: 'pointer',
                    width: '100%',
                    background: '#6b7280',
                    color: 'white',
                    boxShadow: '0 4px 12px rgba(107, 114, 128, 0.3)'
                  }}
                >
                  {language_service.t('cancel')}
                </button>
              </div>
            </div>
          </BottomSheet>
        )}

        {/* Wallet Management Connect Sheet */}
        <WalletConnectSheet
          is_open={show_wallet_sheet}
          on_close={() => {
            set_show_wallet_sheet(false);
            // Re-check wallet connection when modal closes to ensure UI is updated
            setTimeout(() => {
              check_wallet_connection();
            }, 100);
          }}
          wallet_connected={wallet_connected}
          on_wallet_connected={(wallet_address?: string) => {
            if (wallet_address) {
              set_wallet_connected(true);
            } else {
              set_wallet_connected(false);
              set_payment_tx_hash(null);
              set_payment_tx_status(null);
            }
          }}
        />

        </div>
      </Router>
  );
}

export default App;
