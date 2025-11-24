import React, { useState, useEffect } from 'react';
import type { Sensor, ParkingSpace, Statistics, ParkingUsage, Reservation, ParkingZone } from '../types';
import AdminService from '../services/admin_service';
import type { AdminUser, AdminLog } from '../services/admin_service';
import { build_api_url } from '../config/api_config';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, LineChart, Line } from 'recharts';
import { Download, LogOut, BarChart3, Radio, Car, TrendingUp, Calendar, Users, FileText, MapPin } from 'lucide-react';
import { LanguageService } from '../services/language_service';
import { ZoneManagement } from './ZoneManagement';
import './AdminDashboard.css';

export const AdminDashboard: React.FC = () => {
  const [active_tab, set_active_tab] = useState('overview');
  const [sensors, set_sensors] = useState<Sensor[]>([]);
  const [parking_spaces, set_parking_spaces] = useState<ParkingSpace[]>([]);
  const [usage_data, set_usage_data] = useState<ParkingUsage[]>([]);
  const [reservations] = useState<Reservation[]>([]);
  const [statistics, set_statistics] = useState<Statistics | null>(null);

  // Authentication state
  const [is_authenticated, set_is_authenticated] = useState(false);
  const [is_loading, set_is_loading] = useState(true);
  const [current_user, set_current_user] = useState<AdminUser | null>(null);

  // Admin state
  const [admin_users, set_admin_users] = useState<AdminUser[]>([]);
  const [admin_logs, set_admin_logs] = useState<AdminLog[]>([]);
  const [show_admin_user_form, set_show_admin_user_form] = useState(false);
  const [editing_admin_user, set_editing_admin_user] = useState<AdminUser | null>(null);
  const [admin_user_form_data, set_admin_user_form_data] = useState({
    username: '',
    password: '',
    email: '',
    role: 'admin' as 'admin' | 'superadmin'
  });

  // Zone state
  const [zones, set_zones] = useState<ParkingZone[]>([]);
  const [zones_loading, set_zones_loading] = useState(false);
  
  // Real Sensors state
  const [real_sensors_logs, set_real_sensors_logs] = useState<any[]>([]);
  const [real_sensors_stats, set_real_sensors_stats] = useState<any>(null);
  const [real_sensors_server_status, set_real_sensors_server_status] = useState<string>('unknown');
  const [real_sensors_loading, set_real_sensors_loading] = useState(false);
  const [real_sensors_filters, set_real_sensors_filters] = useState({
    wpsd_id: '',
    action: '',
    date_from: '',
    date_to: '',
    limit: 100,
    offset: 0
  });
  const FIREWALL_PAGE_SIZE = 6;
  const [firewall_whitelist, set_firewall_whitelist] = useState<{ ip: string; label?: string }[]>([]);
  const [firewall_loading, set_firewall_loading] = useState(false);
  const [firewall_saving, set_firewall_saving] = useState(false);
  const [firewall_error, set_firewall_error] = useState<string | null>(null);
  const [firewall_success, set_firewall_success] = useState<string | null>(null);
  const [firewall_new_ip, set_firewall_new_ip] = useState('');
  const [firewall_new_label, set_firewall_new_label] = useState('');
  const [firewall_show_list, set_firewall_show_list] = useState(false);
  const [firewall_page, set_firewall_page] = useState(1);
  const [space_status_loading, set_space_status_loading] = useState<string | null>(null);
  const [space_status_feedback, set_space_status_feedback] = useState<string | null>(null);

  const total_firewall_pages = Math.max(1, Math.ceil(Math.max(firewall_whitelist.length, 1) / FIREWALL_PAGE_SIZE));
  const safe_firewall_page = Math.min(firewall_page, total_firewall_pages);
  const firewall_page_start = (safe_firewall_page - 1) * FIREWALL_PAGE_SIZE;
  const displayed_firewall_whitelist = firewall_whitelist.slice(firewall_page_start, firewall_page_start + FIREWALL_PAGE_SIZE);
  
  // Loading flags to prevent duplicate requests
  const [data_loading, set_data_loading] = useState(false);
  const [usage_loading, set_usage_loading] = useState(false);
  const [statistics_loading, set_statistics_loading] = useState(false);

  // Sensor management state
  const [editing_sensor, set_editing_sensor] = useState<Sensor | null>(null);
  const [show_sensor_form, set_show_sensor_form] = useState(false);
  const [new_sensor, set_new_sensor] = useState<Partial<Sensor>>({
    name: '',
    wpsd_id: '',
    wdc_id: '',
    status: 'live',
    coordinates: { lat: 0, lng: 0 },
    street_name: '',
    zone_id: ''
  });

  const [filter_date, set_filter_date] = useState('');

  // Responsive design state
  const [is_mobile, set_is_mobile] = useState(window.innerWidth <= 768);
  const [is_small_mobile, set_is_small_mobile] = useState(window.innerWidth <= 480);

  // Night mode state
  const [is_night_mode, set_is_night_mode] = useState(() => {
    const current_hour = new Date().getHours();
    return current_hour >= 18 || current_hour < 6;
  });

  // Handle window resize for responsive design
  useEffect(() => {
    const handle_resize = () => {
      set_is_mobile(window.innerWidth <= 768);
      set_is_small_mobile(window.innerWidth <= 480);
    };

    window.addEventListener('resize', handle_resize);
    return () => window.removeEventListener('resize', handle_resize);
  }, []);

  // Check for night mode every minute
  useEffect(() => {
    const interval = setInterval(() => {
      const current_hour = new Date().getHours();
      set_is_night_mode(current_hour >= 18 || current_hour < 6);
    }, 60000);

    return () => clearInterval(interval);
  }, []);

  const admin_service = AdminService.getInstance();
  const language_service = LanguageService.getInstance();

  useEffect(() => {
    check_authentication_and_load_data();
  }, []);

  const check_authentication_and_load_data = async () => {
    try {
      set_is_loading(true);
      
      // First check if we have a valid session
      const session_result = await admin_service.checkSession();
      
      if (!session_result.success || !session_result.authenticated) {
        // Session is invalid, set loading to false and show error
        set_is_authenticated(false);
        set_current_user(null);
        set_is_loading(false);
        console.error('Authentication failed:', session_result.error);
        return;
      }
      
      // User is authenticated, set state and load data
      set_is_authenticated(true);
      set_current_user(session_result.user || null);
      await load_data();
      set_is_loading(false);
    } catch (error) {
      console.error('Authentication check failed:', error);
      set_is_authenticated(false);
      set_current_user(null);
      set_is_loading(false);
    }
  };

  useEffect(() => {
    if (parking_spaces.length > 0) {
      generate_statistics();
    }
  }, [parking_spaces, usage_data, reservations]);

  const load_data = async () => {
    // Prevent duplicate requests
    if (data_loading) {
      return;
    }
    
    try {
      set_data_loading(true);
      
      // Load data from PHP backend
      const response = await fetch(build_api_url('/api/data.php'));
      if (response.ok) {
        const data = await response.json();
        if (data.success) {
          set_sensors(data.sensors || []);
          set_parking_spaces(data.parking_spaces || []);
        }
      }
      
      // Load parking usage data
      await load_parking_usage();
      
      // Load admin users if superadmin
      if (current_user?.role === 'superadmin') {
        const admin_users_result = await admin_service.getAdminUsers();
        if (admin_users_result.success && admin_users_result.data) {
          set_admin_users(admin_users_result.data as any); // Type assertion to handle mismatch
        }
      }
      
      // Load admin logs if superadmin
      if (current_user?.role === 'superadmin') {
        const admin_logs_result = await admin_service.getAdminLogs(50, 0);
        if (admin_logs_result.success && admin_logs_result.data) {
          set_admin_logs(admin_logs_result.data);
        }
      }
      
      // Load zones data for all admin users (needed for sensor forms)
      await load_zones();
      
      // Generate statistics after loading all data
      await generate_statistics();
    } catch (error) {
      console.error('Error loading data:', error);
    } finally {
      set_data_loading(false);
    }
  };

  const refresh_data = () => {
    load_data();
    generate_statistics();
  };

  const load_real_sensors_data = async () => {
    if (real_sensors_loading || current_user?.role !== 'superadmin') {
      return;
    }

    try {
      set_real_sensors_loading(true);
      
      const params = new URLSearchParams();
      if (real_sensors_filters.wpsd_id) params.append('wpsd_id', real_sensors_filters.wpsd_id);
      if (real_sensors_filters.action) params.append('action', real_sensors_filters.action);
      if (real_sensors_filters.date_from) params.append('date_from', real_sensors_filters.date_from);
      if (real_sensors_filters.date_to) params.append('date_to', real_sensors_filters.date_to);
      params.append('limit', real_sensors_filters.limit.toString());
      params.append('offset', real_sensors_filters.offset.toString());

      const response = await fetch(build_api_url(`/api/real-sensors.php?${params.toString()}`), {
        credentials: 'include'
      });

      if (response.ok) {
        const data = await response.json();
        if (data.success) {
          set_real_sensors_logs(data.data.logs || []);
          set_real_sensors_stats(data.data.statistics || null);
          set_real_sensors_server_status(data.data.server_status || 'unknown');
        }
      }
    } catch (error) {
      console.error('Error loading real sensors data:', error);
    } finally {
      set_real_sensors_loading(false);
    }
  };

  useEffect(() => {
    if (current_user?.role === 'superadmin' && active_tab === 'real_sensors') {
      load_firewall_whitelist();
    }
  }, [active_tab, current_user]);

  useEffect(() => {
    if (space_status_feedback) {
      const timeout = setTimeout(() => set_space_status_feedback(null), 5000);
      return () => clearTimeout(timeout);
    }
  }, [space_status_feedback]);

  useEffect(() => {
    const maxPage = Math.max(1, Math.ceil(Math.max(firewall_whitelist.length, 1) / FIREWALL_PAGE_SIZE));
    if (firewall_page > maxPage) {
      set_firewall_page(maxPage);
    }
  }, [firewall_whitelist.length, firewall_page]);

  const load_firewall_whitelist = async () => {
    if (firewall_loading || current_user?.role !== 'superadmin') {
      return;
    }
    try {
      set_firewall_loading(true);
      set_firewall_error(null);
      const response = await fetch(build_api_url('/api/firewall-whitelist.php'), {
        method: 'GET',
        credentials: 'include'
      });
      const data = await response.json();
      if (response.ok && data.success) {
        set_firewall_whitelist(data.data?.ips || []);
      } else {
        set_firewall_error(data.error || 'Failed to load whitelist.');
      }
    } catch (error) {
      console.error('Error loading firewall whitelist:', error);
      set_firewall_error('Failed to load whitelist.');
    } finally {
      set_firewall_loading(false);
    }
  };

  const save_firewall_whitelist = async () => {
    if (firewall_saving || current_user?.role !== 'superadmin') {
      return;
    }
    try {
      set_firewall_saving(true);
      set_firewall_error(null);
      set_firewall_success(null);
      const response = await fetch(build_api_url('/api/firewall-whitelist.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        credentials: 'include',
        body: JSON.stringify({ ips: firewall_whitelist })
      });
      const data = await response.json();
      if (response.ok && data.success) {
        set_firewall_success('Whitelist saved successfully.');
        set_firewall_whitelist(data.data?.ips || []);
      } else {
        set_firewall_error(data.error || 'Failed to save whitelist.');
      }
    } catch (error) {
      console.error('Error saving firewall whitelist:', error);
      set_firewall_error('Failed to save whitelist.');
    } finally {
      set_firewall_saving(false);
    }
  };

  const add_firewall_entry = () => {
    const trimmed_ip = firewall_new_ip.trim();
    if (!trimmed_ip) {
      set_firewall_error('IP address cannot be empty.');
      return;
    }

    const ipv4_regex = /^([0-9]{1,3}\.){3}[0-9]{1,3}$/;
    const ipv6_regex = /^[0-9a-fA-F:]+$/;
    if (!ipv4_regex.test(trimmed_ip) && !ipv6_regex.test(trimmed_ip)) {
      set_firewall_error('Invalid IP address format.');
      return;
    }

    set_firewall_whitelist(prev => [...prev, { ip: trimmed_ip, label: firewall_new_label.trim() }]);
    set_firewall_new_ip('');
    set_firewall_new_label('');
    set_firewall_error(null);
  };

  const remove_firewall_entry = (index: number) => {
    set_firewall_whitelist(prev => prev.filter((_, idx) => idx !== index));
  };

  const update_firewall_entry = (index: number, field: 'ip' | 'label', value: string) => {
    set_firewall_whitelist(prev => prev.map((entry, idx) => {
      if (idx !== index) return entry;
      return {
        ...entry,
        [field]: value
      };
    }));
  };

  const update_space_status = async (space_id: string, status: 'vacant' | 'occupied') => {
    if (space_status_loading || current_user?.role !== 'superadmin') {
      return;
    }
    try {
      set_space_status_loading(space_id);
      set_space_status_feedback(null);
      const response = await fetch(build_api_url('/api/admin-space-status.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        credentials: 'include',
        body: JSON.stringify({ space_id, status })
      });
      const data = await response.json();
      if (response.ok && data.success) {
        set_space_status_feedback(`Space #${space_id} set to ${status}.`);
        await load_data();
      } else {
        alert(data.error || 'Failed to update parking space status.');
      }
    } catch (error) {
      console.error('Error updating space status:', error);
      alert('Failed to update parking space status.');
    } finally {
      set_space_status_loading(null);
    }
  };

  const load_parking_usage = async () => {
    // Prevent duplicate requests
    if (usage_loading) {
      return;
    }
    
    try {
      set_usage_loading(true);
      const response = await fetch(build_api_url('/api/statistics.php?action=parking_usage&limit=100'));
      if (response.ok) {
        const data = await response.json();
        if (data.success) {
          set_usage_data(data.data);
        }
      }
    } catch (error) {
      console.error('Error loading parking usage:', error);
    } finally {
      set_usage_loading(false);
    }
  };

  const load_zones = async () => {
    // Prevent duplicate requests
    if (zones_loading) {
      return;
    }
    
    try {
      set_zones_loading(true);
      console.log('Loading zones...');
      const result = await admin_service.getParkingZones();
      console.log('Zones result:', result);
      if (result.success && result.data) {
        // Ensure zones data is properly formatted
        const formatted_zones = result.data.map((zone: any) => ({
          id: String(zone.id), // Convert to string to match interface
          name: zone.name || 'Unknown Zone',
          description: zone.description || '',
          color: zone.color || '#6b7280',
          hourly_rate: Number(zone.hourly_rate) || 0,
          daily_rate: Number(zone.daily_rate) || 0,
          is_active: Boolean(zone.is_active),
          is_premium: Boolean(zone.is_premium) || false,
          space_count: zone.space_count || 0,
          created_at: zone.created_at || '',
          updated_at: zone.updated_at || ''
        }));
        set_zones(formatted_zones);
        console.log('Zones loaded successfully:', formatted_zones);
      } else {
        console.error('Failed to load zones:', result.error);
        set_zones([]); // Set empty array on error
      }
    } catch (error) {
      console.error('Error loading zones:', error);
      set_zones([]); // Set empty array on error
    } finally {
      set_zones_loading(false);
    }
  };

  const generate_statistics = async () => {
    // Prevent duplicate requests
    if (statistics_loading) {
      return;
    }
    
    try {
      set_statistics_loading(true);
      
      // Fetch real statistics from backend
      const response = await fetch(build_api_url('/api/statistics.php?action=overview'));
      if (response.ok) {
        const data = await response.json();
        if (data.success) {
          const stats: Statistics = {
            ...data.data,
            daily_usage: await fetch_daily_usage(),
            hourly_usage: await fetch_hourly_usage()
          };
          set_statistics(stats);
          return;
        }
      }
    } catch (error) {
      console.error('Error fetching statistics:', error);
    } finally {
      set_statistics_loading(false);
    }

    // Fallback to local calculation if API fails
    const total_spaces = parking_spaces.length;
    const occupied_spaces = parking_spaces.filter(s => s.status === 'occupied').length;
    const vacant_spaces = parking_spaces.filter(s => s.status === 'vacant').length;
    const reserved_spaces = parking_spaces.filter(s => s.status === 'reserved').length;

    const stats: Statistics = {
      total_spaces,
      occupied_spaces,
      vacant_spaces,
      reserved_spaces,
      utilization_rate: total_spaces > 0 ? ((occupied_spaces + reserved_spaces) / total_spaces) * 100 : 0,
      average_duration: 0,
      total_revenue: 0,
      daily_usage: generate_daily_usage(),
      hourly_usage: generate_hourly_usage()
    };

    set_statistics(stats);
  };

  const fetch_daily_usage = async () => {
    try {
      const response = await fetch(build_api_url('/api/statistics.php?action=daily_usage&days=7'));
      if (response.ok) {
        const data = await response.json();
        if (data.success) {
          return data.data;
        }
      }
    } catch (error) {
      console.error('Error fetching daily usage:', error);
    }
    
    // Fallback to mock data
    return generate_daily_usage();
  };

  const fetch_hourly_usage = async () => {
    try {
      const response = await fetch(build_api_url('/api/statistics.php?action=hourly_usage&days=7'));
      if (response.ok) {
        const data = await response.json();
        if (data.success) {
          return data.data;
        }
      }
    } catch (error) {
      console.error('Error fetching hourly usage:', error);
    }
    
    // Fallback to mock data
    return generate_hourly_usage();
  };

  const generate_daily_usage = () => {
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    return days.map(day => {
      // For now, use mock data but this could be enhanced to use real usage data
      const mock_count = Math.floor(Math.random() * 20) + 5;
      const mock_revenue = Math.floor(Math.random() * 200) + 50;
      return {
        date: day,
        count: mock_count,
        revenue: mock_revenue
      };
    });
  };

  const generate_hourly_usage = () => {
    return Array.from({ length: 24 }, (_, i) => ({
      hour: i,
      count: Math.floor(Math.random() * 10) + 1
    }));
  };

  // Helper function to parse coordinates from various text formats
  const parse_coordinates_from_text = (text: string) => {
    // Try multiple formats:
    // 1. "43.140000, 20.517500" or "43.140000,20.517500"
    const comma_match = text.match(/([0-9.-]+)\s*,\s*([0-9.-]+)/);
    if (comma_match) {
      const lat = parseFloat(comma_match[1]);
      const lng = parseFloat(comma_match[2]);
      if (!isNaN(lat) && !isNaN(lng)) {
        return { lat, lng };
      }
    }
    
    // 2. "lat: 43.140000 lng: 20.517500" or "lat:43.140000 lng:20.517500"
    const lat_match = text.match(/lat[:\s]*([0-9.-]+)/i);
    const lng_match = text.match(/lng[:\s]*([0-9.-]+)/i);
    
    if (lat_match && lng_match) {
      const lat = parseFloat(lat_match[1]);
      const lng = parseFloat(lng_match[1]);
      if (!isNaN(lat) && !isNaN(lng)) {
        return { lat, lng };
      }
    } else if (lat_match) {
      const lat = parseFloat(lat_match[1]);
      if (!isNaN(lat) && new_sensor.coordinates?.lng !== undefined) {
        return { lat, lng: new_sensor.coordinates.lng };
      }
    } else if (lng_match) {
      const lng = parseFloat(lng_match[1]);
      if (!isNaN(lng) && new_sensor.coordinates?.lat !== undefined) {
        return { lat: new_sensor.coordinates.lat, lng };
      }
    }
    
    // 3. Try to find two numbers separated by space or comma
    const numbers = text.match(/([0-9.-]+)/g);
    if (numbers && numbers.length >= 2) {
      const lat = parseFloat(numbers[0]);
      const lng = parseFloat(numbers[1]);
      if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
        return { lat, lng };
      }
    }
    
    return null;
  };

  const handle_delete_sensor = async (sensor_id: string) => {
    if (window.confirm(language_service.t('confirm_delete_sensor'))) {
      try {
        const response = await fetch(build_api_url(`/api/sensors.php/${sensor_id}`), {
          method: 'DELETE',
          credentials: 'include'
        });
        
        if (response.ok) {
          // Refresh data from backend
          load_data();
        } else {
          alert('Failed to delete sensor');
        }
      } catch (error) {
        console.error('Error deleting sensor:', error);
        alert('Error deleting sensor');
      }
    }
  };

  // Sensor management functions
  const open_add_sensor_form = () => {
    set_editing_sensor(null);
    set_new_sensor({
      name: '',
      wpsd_id: '',
      wdc_id: '',
      status: 'live',
      coordinates: { lat: 0, lng: 0 },
      street_name: ''
    });
    set_show_sensor_form(true);
  };

  const open_edit_sensor_form = (sensor: Sensor) => {
    set_editing_sensor(sensor);
    set_new_sensor({
      name: sensor.name,
      wpsd_id: sensor.wpsd_id,
      wdc_id: sensor.wdc_id,
      status: sensor.status,
      coordinates: sensor.coordinates,
      street_name: sensor.street_name,
      zone_id: sensor.zone_id || ''
    });
    set_show_sensor_form(true);
  };

  const close_sensor_form = () => {
    set_show_sensor_form(false);
    set_editing_sensor(null);
    set_new_sensor({
      name: '',
      wpsd_id: '',
      wdc_id: '',
      status: 'live',
      coordinates: { lat: 0, lng: 0 },
      street_name: '',
      zone_id: ''
    });
  };



  const save_sensor = async () => {
    if (!new_sensor.name || !new_sensor.wpsd_id || !new_sensor.street_name) {
      alert(language_service.t('please_fill_required_fields'));
      return;
    }

    if (!new_sensor.coordinates?.lat || !new_sensor.coordinates?.lng) {
      alert(language_service.t('please_enter_valid_coordinates'));
      return;
    }

    try {
      if (editing_sensor) {
        // Update existing sensor
        // Convert ID to number if it's a string
        const sensor_id = typeof editing_sensor.id === 'string' ? parseInt(editing_sensor.id, 10) : editing_sensor.id;
        
        if (isNaN(sensor_id)) {
          alert('Invalid sensor ID');
          return;
        }
        
        const response = await fetch(build_api_url(`/api/sensors.php`), {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
          },
          credentials: 'include',
          body: JSON.stringify({
            id: sensor_id,
            name: new_sensor.name,
            wpsd_id: new_sensor.wpsd_id,
            wdc_id: new_sensor.wdc_id || '',
            street_name: new_sensor.street_name,
            latitude: parseFloat(new_sensor.coordinates.lat.toString()),
            longitude: parseFloat(new_sensor.coordinates.lng.toString()),
            zone_id: new_sensor.zone_id || null
          })
        });

        if (response.ok) {
          const result = await response.json();
          if (result.success) {
            // Update local state
            set_sensors(prev => prev.map(s => 
              s.id === editing_sensor.id ? { ...s, ...new_sensor } : s
            ));
            
            // Refresh data
            refresh_data();
            
            // Close form
            close_sensor_form();
            
            // Show success message
            alert('Sensor updated successfully');
          } else {
            // Show error from API
            alert(result.error || 'Failed to update sensor');
          }
        } else {
          // Try to parse error response
          try {
            const errorData = await response.json();
            alert(errorData.error || `Failed to update sensor: ${response.status} ${response.statusText}`);
          } catch {
            alert(`Failed to update sensor: ${response.status} ${response.statusText}`);
          }
        }
      } else {
        // Add new sensor
        const response = await fetch(build_api_url('/api/sensors.php'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          credentials: 'include',
          body: JSON.stringify({
            name: new_sensor.name,
            wpsd_id: new_sensor.wpsd_id,
            wdc_id: new_sensor.wdc_id || '',
            street_name: new_sensor.street_name,
            latitude: parseFloat(new_sensor.coordinates.lat.toString()),
            longitude: parseFloat(new_sensor.coordinates.lng.toString()),
            zone_id: new_sensor.zone_id || null
          })
        });

        if (response.ok) {
          const result = await response.json();
          if (result.success) {
            // Refresh data
            refresh_data();
            
            // Close form
            close_sensor_form();
            
            // Show success message
            alert('Sensor added successfully');
          } else {
            // Show error from API
            alert(result.error || 'Failed to add sensor');
          }
        } else {
          // Try to parse error response
          try {
            const errorData = await response.json();
            alert(errorData.error || `Failed to add sensor: ${response.status} ${response.statusText}`);
          } catch {
            alert(`Failed to add sensor: ${response.status} ${response.statusText}`);
          }
        }
      }
    } catch (error) {
      console.error('Error saving sensor:', error);
      alert(language_service.t('error_saving_sensor'));
    }
  };

  const export_report = () => {
    const report_data = {
      sensors,
      parking_spaces,
      usage_data,
      reservations,
      statistics,
      export_date: new Date().toISOString()
    };

    const blob = new Blob([JSON.stringify(report_data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `parking_report_${new Date().toISOString().split('T')[0]}.json`;
    a.click();
    URL.revokeObjectURL(url);
  };

  // Admin user management functions
  const handle_save_admin_user = async () => {
    if (!admin_user_form_data.username || !admin_user_form_data.email) {
      alert(language_service.t('please_fill_required_fields'));
      return;
    }

    if (!editing_admin_user && !admin_user_form_data.password) {
      alert(language_service.t('password_required_new'));
      return;
    }

    try {
      if (editing_admin_user) {
        // Update existing admin user
        const update_data: any = {
          email: admin_user_form_data.email,
          role: admin_user_form_data.role
        };
        
        if (admin_user_form_data.password) {
          update_data.password = admin_user_form_data.password;
        }

        const result = await admin_service.updateAdminUser(editing_admin_user.id, update_data);
        if (result.success) {
          alert('Admin user updated successfully');
          set_show_admin_user_form(false);
          load_data(); // Refresh admin users list
        } else {
          alert(`Failed to update admin user: ${result.error}`);
        }
      } else {
        // Add new admin user
        const result = await admin_service.addAdminUser(admin_user_form_data);
        if (result.success) {
          alert('Admin user added successfully');
          set_show_admin_user_form(false);
          load_data(); // Refresh admin users list
        } else {
          alert(`Failed to add admin user: ${result.error}`);
        }
      }
    } catch (error) {
      console.error('Error saving admin user:', error);
      alert('Error saving admin user');
    }
  };

  const handle_delete_admin_user = async (userId: number) => {
    if (window.confirm(language_service.t('confirm_delete_admin_user'))) {
      try {
        const result = await admin_service.deleteAdminUser(userId);
        if (result.success) {
          alert('Admin user deleted successfully');
          load_data(); // Refresh admin users list
        } else {
          alert(`Failed to delete admin user: ${result.error}`);
        }
      } catch (error) {
        console.error('Error deleting admin user:', error);
        alert('Error deleting admin user');
      }
    }
  };

  const handle_logout = async () => {
    try {
      // Call the logout API to clear the server session
      await admin_service.logout();
      
      // Clear local state
      set_is_authenticated(false);
      set_current_user(null);
      
      // Reload the page to show login form
      window.location.reload();
    } catch (error) {
      console.error('Logout error:', error);
      // Force reload even if logout fails
      window.location.reload();
    }
  };

  // Show loading state while checking authentication
  if (is_loading) {
    return (
      <div className="admin-dashboard loading-state" style={{ minHeight: 'auto', height: 'fit-content' }}>
        <div className="loading-container">
          <div className="loading-spinner"></div>
          <p>Checking authentication...</p>
        </div>
      </div>
      );
  }

  // Show error if not authenticated
  if (!is_authenticated) {
    return (
      <div className="admin-dashboard error-state" style={{ minHeight: 'auto', height: 'fit-content' }}>
        <div className="error-container">
          <h2>Authentication Required</h2>
          <p>You need to log in to access the admin dashboard.</p>
          <button className="btn-primary" onClick={() => window.location.reload()}>
            Retry
          </button>
        </div>
      </div>
    );
  }
  
  return (
    <div className="admin-dashboard" data-night-mode={is_night_mode} style={{
      maxWidth: '100%',
      margin: '0 auto',
      padding: is_small_mobile ? '0 0.25rem' : is_mobile ? '0 0.5rem' : '0 1rem',
      minHeight: '100vh',
      backgroundColor: is_night_mode ? '#1a1a2e' : '#f8fafc',
      color: is_night_mode ? '#ffffff' : '#1f2937',
      transition: 'all 0.3s ease'
    }}>
      <div className="dashboard-header" style={{
        display: 'flex',
        flexDirection: 'column',
        gap: '0.75rem',
        marginBottom: '1rem',
        padding: '0.75rem 0',
        borderBottom: `2px solid ${is_night_mode ? '#374151' : '#e5e7eb'}`
      }}>
        <h1 style={{
          fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.75rem' : '2rem',
          fontWeight: '700',
          color: is_night_mode ? '#ffffff' : '#1f2937',
          margin: '0',
          textAlign: 'center',
          textShadow: is_night_mode ? 'none' : '0 2px 4px rgba(0, 0, 0, 0.1)',
          transition: 'all 0.3s ease'
        }}>Admin Kontrolna Tabla</h1>
      </div>

      {/* Tab Rows */}
      <div className="dashboard-tabs" style={{
        display: 'flex',
        flexDirection: 'column',
        gap: '0.75rem',
        padding: '0.75rem 0',
        alignItems: 'stretch'
      }}>
        {/* First Row: Dashboard, Export, Day, Logout - All same color */}
        <div className="tab-row" style={{
          display: 'flex',
          gap: '0.5rem',
          flexWrap: 'nowrap',
          justifyContent: 'flex-start',
          width: '100%',
          overflowX: 'auto'
        }}>
          <button className="tab" onClick={refresh_data} style={{
            padding: '0.75rem 1rem',
            borderRadius: '8px',
            border: 'none',
            fontSize: '0.875rem',
            fontWeight: '600',
            cursor: 'pointer',
            display: 'flex',
            alignItems: 'center',
            gap: '0.5rem',
            backgroundColor: '#6b7280',
            color: 'white',
            boxShadow: '0 2px 4px rgba(107, 114, 128, 0.2)',
            whiteSpace: 'nowrap',
            minHeight: '44px',
            transition: 'all 0.2s ease',
            flex: '1 1 auto'
          }}>
            <BarChart3 size={18} />
            <span>Dashboard</span>
          </button>
          <button className="tab" onClick={export_report} style={{
            padding: '0.75rem 1rem',
            borderRadius: '8px',
            border: 'none',
            fontSize: '0.875rem',
            fontWeight: '600',
            cursor: 'pointer',
            display: 'flex',
            alignItems: 'center',
            gap: '0.5rem',
            backgroundColor: '#6b7280',
            color: 'white',
            boxShadow: '0 2px 4px rgba(107, 114, 128, 0.2)',
            whiteSpace: 'nowrap',
            minHeight: '44px',
            transition: 'all 0.2s ease',
            flex: '1 1 auto'
          }}>
            <Download size={18} />
            <span>Export</span>
          </button>
          <button 
            className="tab" 
            onClick={() => set_is_night_mode(!is_night_mode)} 
            style={{
              padding: '0.75rem 1rem',
              borderRadius: '8px',
              border: 'none',
              fontSize: '0.875rem',
              fontWeight: '600',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '0.5rem',
              backgroundColor: '#6b7280',
              color: 'white',
              boxShadow: '0 2px 4px rgba(107, 114, 128, 0.2)',
              whiteSpace: 'nowrap',
              minHeight: '44px',
              transition: 'all 0.2s ease',
              flex: '1 1 auto'
            }}
          >
            {is_night_mode ? '☀️' : '🌙'}
            <span>{is_night_mode ? 'Day' : 'Night'}</span>
          </button>
          <button className="tab" onClick={handle_logout} style={{
            padding: '0.75rem 1rem',
            borderRadius: '8px',
            border: 'none',
            fontSize: '0.875rem',
            fontWeight: '600',
            cursor: 'pointer',
            display: 'flex',
            alignItems: 'center',
            gap: '0.5rem',
            backgroundColor: '#6b7280',
            color: 'white',
            boxShadow: '0 2px 4px rgba(107, 114, 128, 0.2)',
            whiteSpace: 'nowrap',
            minHeight: '44px',
            transition: 'all 0.2s ease',
            flex: '1 1 auto'
          }}>
            <LogOut size={18} />
            <span>Logout</span>
          </button>
        </div>

        {/* Second Row: Overview, Sensors, Spaces, Analytics, Bookings, Sessions - Different gray shade */}
        <div className="tab-row" style={{
          display: 'flex',
          gap: '0.5rem',
          flexWrap: 'nowrap',
          justifyContent: 'flex-start',
          width: '100%',
          overflowX: 'auto'
        }}>
          <button 
            className={`tab ${active_tab === 'overview' ? 'active' : ''}`}
            onClick={() => set_active_tab('overview')}
            style={{
              padding: '0.75rem 1rem',
              borderRadius: '8px',
              border: 'none',
              fontSize: '0.875rem',
              fontWeight: '600',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '0.5rem',
              backgroundColor: active_tab === 'overview' ? '#4b5563' : '#e5e7eb',
              color: active_tab === 'overview' ? 'white' : '#374151',
              transition: 'all 0.2s ease',
              boxShadow: active_tab === 'overview' ? '0 2px 8px rgba(75, 85, 99, 0.3)' : '0 1px 3px rgba(0, 0, 0, 0.1)',
              whiteSpace: 'nowrap',
              minHeight: '44px',
              flex: '1 1 0',
              minWidth: '0'
            }}
          >
            <BarChart3 size={18} />
            <span>Overview</span>
          </button>
          <button 
            className={`tab ${active_tab === 'sensors' ? 'active' : ''}`}
            onClick={() => set_active_tab('sensors')}
            style={{
              padding: '0.75rem 1rem',
              borderRadius: '8px',
              border: 'none',
              fontSize: '0.875rem',
              fontWeight: '600',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '0.5rem',
              backgroundColor: active_tab === 'sensors' ? '#4b5563' : '#e5e7eb',
              color: active_tab === 'sensors' ? 'white' : '#374151',
              transition: 'all 0.2s ease',
              boxShadow: active_tab === 'sensors' ? '0 2px 8px rgba(75, 85, 99, 0.3)' : '0 1px 3px rgba(0, 0, 0, 0.1)',
              whiteSpace: 'nowrap',
              minHeight: '44px',
              flex: '1 1 0',
              minWidth: '0'
            }}
          >
            <Radio size={18} />
            <span>Sensors</span>
          </button>
          <button 
            className={`tab ${active_tab === 'spaces' ? 'active' : ''}`}
            onClick={() => set_active_tab('spaces')}
            style={{
              padding: '0.75rem 1rem',
              borderRadius: '8px',
              border: 'none',
              fontSize: '0.875rem',
              fontWeight: '600',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '0.5rem',
              backgroundColor: active_tab === 'spaces' ? '#4b5563' : '#e5e7eb',
              color: active_tab === 'spaces' ? 'white' : '#374151',
              transition: 'all 0.2s ease',
              boxShadow: active_tab === 'spaces' ? '0 2px 8px rgba(75, 85, 99, 0.3)' : '0 1px 3px rgba(0, 0, 0, 0.1)',
              whiteSpace: 'nowrap',
              minHeight: '44px',
              flex: '1 1 0',
              minWidth: '0'
            }}
          >
            <Car size={18} />
            <span>Spaces</span>
          </button>
          <button 
            className={`tab ${active_tab === 'usage' ? 'active' : ''}`}
            onClick={() => set_active_tab('usage')}
            style={{
              padding: '0.75rem 1rem',
              borderRadius: '8px',
              border: 'none',
              fontSize: '0.875rem',
              fontWeight: '600',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '0.5rem',
              backgroundColor: active_tab === 'usage' ? '#4b5563' : '#e5e7eb',
              color: active_tab === 'usage' ? 'white' : '#374151',
              transition: 'all 0.2s ease',
              boxShadow: active_tab === 'usage' ? '0 2px 8px rgba(75, 85, 99, 0.3)' : '0 1px 3px rgba(0, 0, 0, 0.1)',
              whiteSpace: 'nowrap',
              minHeight: '44px',
              flex: '1 1 0',
              minWidth: '0'
            }}
          >
            <TrendingUp size={18} />
            <span>Analytics</span>
          </button>
          <button 
            className={`tab ${active_tab === 'reservations' ? 'active' : ''}`}
            onClick={() => set_active_tab('reservations')}
            style={{
              padding: '0.75rem 1rem',
              borderRadius: '8px',
              border: 'none',
              fontSize: '0.875rem',
              fontWeight: '600',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '0.5rem',
              backgroundColor: active_tab === 'reservations' ? '#4b5563' : '#e5e7eb',
              color: active_tab === 'reservations' ? 'white' : '#374151',
              transition: 'all 0.2s ease',
              boxShadow: active_tab === 'reservations' ? '0 2px 8px rgba(75, 85, 99, 0.3)' : '0 1px 3px rgba(0, 0, 0, 0.1)',
              whiteSpace: 'nowrap',
              minHeight: '44px',
              flex: '1 1 0',
              minWidth: '0'
            }}
          >
            <Calendar size={18} />
            <span>Bookings</span>
          </button>
          <button 
            className={`tab ${active_tab === 'sessions' ? 'active' : ''}`}
            onClick={() => set_active_tab('sessions')}
            style={{
              padding: '0.75rem 1rem',
              borderRadius: '8px',
              border: 'none',
              fontSize: '0.875rem',
              fontWeight: '600',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '0.5rem',
              backgroundColor: active_tab === 'sessions' ? '#4b5563' : '#e5e7eb',
              color: active_tab === 'sessions' ? 'white' : '#374151',
              transition: 'all 0.2s ease',
              boxShadow: active_tab === 'sessions' ? '0 2px 8px rgba(75, 85, 99, 0.3)' : '0 1px 3px rgba(0, 0, 0, 0.1)',
              whiteSpace: 'nowrap',
              minHeight: '44px',
              flex: '1 1 0',
              minWidth: '0'
            }}
          >
            <Car size={18} />
            <span>Sessions</span>
          </button>
        </div>

        {/* Third Row: Super Admin Only - Zones, Users, Logs */}
        {current_user?.role === 'superadmin' && (
          <div className="tab-row" style={{
            display: 'flex',
            gap: '0.5rem',
            flexWrap: 'nowrap',
            justifyContent: 'flex-start',
            width: '100%',
            overflowX: 'auto'
          }}>
            <button 
              className={`tab ${active_tab === 'zones' ? 'active' : ''}`}
              onClick={() => set_active_tab('zones')}
              style={{
                padding: '0.75rem 1rem',
                borderRadius: '8px',
                border: 'none',
                fontSize: '0.875rem',
                fontWeight: '600',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                gap: '0.5rem',
                backgroundColor: active_tab === 'zones' ? '#4b5563' : '#e5e7eb',
                color: active_tab === 'zones' ? 'white' : '#374151',
                transition: 'all 0.2s ease',
                boxShadow: active_tab === 'zones' ? '0 2px 8px rgba(75, 85, 99, 0.3)' : '0 1px 3px rgba(0, 0, 0, 0.1)',
                whiteSpace: 'nowrap',
                minHeight: '44px',
                flex: '1 1 0',
                minWidth: '0'
              }}
            >
              <MapPin size={18} />
              <span>Zones</span>
            </button>
            <button 
              className={`tab ${active_tab === 'admin_users' ? 'active' : ''}`}
              onClick={() => set_active_tab('admin_users')}
              style={{
                padding: '0.75rem 1rem',
                borderRadius: '8px',
                border: 'none',
                fontSize: '0.875rem',
                fontWeight: '600',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                gap: '0.5rem',
                backgroundColor: active_tab === 'admin_users' ? '#4b5563' : '#e5e7eb',
                color: active_tab === 'admin_users' ? 'white' : '#374151',
                transition: 'all 0.2s ease',
                boxShadow: active_tab === 'admin_users' ? '0 2px 8px rgba(75, 85, 99, 0.3)' : '0 1px 3px rgba(0, 0, 0, 0.1)',
                whiteSpace: 'nowrap',
                minHeight: '44px',
                flex: '1 1 0',
                minWidth: '0'
              }}
            >
              <Users size={18} />
              <span>Users</span>
            </button>
            <button 
              className={`tab ${active_tab === 'admin_logs' ? 'active' : ''}`}
              onClick={() => set_active_tab('admin_logs')}
              style={{
                padding: '0.75rem 1rem',
                borderRadius: '8px',
                border: 'none',
                fontSize: '0.875rem',
                fontWeight: '600',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                gap: '0.5rem',
                backgroundColor: active_tab === 'admin_logs' ? '#4b5563' : '#e5e7eb',
                color: active_tab === 'admin_logs' ? 'white' : '#374151',
                transition: 'all 0.2s ease',
                boxShadow: active_tab === 'admin_logs' ? '0 2px 8px rgba(75, 85, 99, 0.3)' : '0 1px 3px rgba(0, 0, 0, 0.1)',
                whiteSpace: 'nowrap',
                minHeight: '44px',
                flex: '1 1 0',
                minWidth: '0'
              }}
            >
              <FileText size={18} />
              <span>Logs</span>
            </button>
          </div>
        )}

        {/* Real Sensors Tab - Superadmin only */}
        {current_user?.role === 'superadmin' && (
          <button
            className={`tab ${active_tab === 'real_sensors' ? 'active' : ''}`}
            onClick={() => {
              set_active_tab('real_sensors');
              setTimeout(() => {
                load_real_sensors_data();
                load_firewall_whitelist();
              }, 100);
            }}
            style={{
              padding: '0.75rem 1rem',
              borderRadius: '8px',
              border: 'none',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '0.5rem',
              fontSize: is_small_mobile ? '0.75rem' : is_mobile ? '0.875rem' : '0.9375rem',
              fontWeight: '500',
              backgroundColor: active_tab === 'real_sensors' ? '#4b5563' : '#e5e7eb',
              color: active_tab === 'real_sensors' ? 'white' : '#374151',
              transition: 'all 0.2s ease',
              boxShadow: active_tab === 'real_sensors' ? '0 2px 8px rgba(75, 85, 99, 0.3)' : '0 1px 3px rgba(0, 0, 0, 0.1)',
              whiteSpace: 'nowrap',
              minHeight: '44px',
              flex: '1 1 0',
              minWidth: '0'
            }}
          >
            <Radio size={18} />
            <span>Real Sensors</span>
          </button>
        )}
      </div>

      <div className="dashboard-content" style={{
        padding: '0.5rem 0',
        minHeight: '500px'
      }}>
        {active_tab === 'overview' && (
          <div className="overview-tab" style={{
            display: 'flex',
            flexDirection: 'column',
            gap: '0.5rem',
            alignItems: 'stretch',
            width: '100%',
            maxWidth: '100%',
            boxSizing: 'border-box'
          }}>
            {/* Reservations Summary */}
            <div className="reservations-summary" style={{
              width: '100%',
              maxWidth: '100%',
              padding: '0 0.5rem',
              marginBottom: '1.5rem'
            }}>
              <h3 style={{
                fontSize: is_small_mobile ? '1rem' : is_mobile ? '1.25rem' : '1.5rem',
                fontWeight: '600',
                color: is_night_mode ? '#ffffff' : '#1f2937',
                textAlign: 'center',
                marginBottom: '1rem',
                transition: 'all 0.3s ease'
              }}>Rezervacije</h3>
              <div style={{
                display: 'flex',
                gap: '1rem',
                justifyContent: 'center',
                flexWrap: 'wrap'
              }}>
                <div style={{
                  backgroundColor: is_night_mode ? '#374151' : '#f3f4f6',
                  padding: '1rem 1.5rem',
                  borderRadius: '12px',
                  textAlign: 'center',
                  border: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}`
                }}>
                  <div style={{
                    fontSize: '1.5rem',
                    fontWeight: '700',
                    color: is_night_mode ? '#d1d5db' : '#4b5563',
                    marginBottom: '0.25rem'
                  }}>{reservations.length}</div>
                  <div style={{
                    fontSize: '0.875rem',
                    color: is_night_mode ? '#9ca3af' : '#6b7280',
                    fontWeight: '600',
                    textTransform: 'uppercase'
                  }}>{language_service.t('reservations')}</div>
                </div>
                <div style={{
                  backgroundColor: is_night_mode ? '#374151' : '#f3f4f6',
                  padding: '1rem 1.5rem',
                  borderRadius: '12px',
                  textAlign: 'center',
                  border: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}`
                }}>
                  <div style={{
                    fontSize: '1.5rem',
                    fontWeight: '700',
                    color: is_night_mode ? '#d1d5db' : '#4b5563',
                    marginBottom: '0.25rem'
                  }}>{reservations.filter(r => r.status === 'active').length}</div>
                  <div style={{
                    fontSize: '0.875rem',
                    color: is_night_mode ? '#9ca3af' : '#6b7280',
                    fontWeight: '600',
                    textTransform: 'uppercase'
                  }}>{language_service.t('active_reservations')}</div>
                </div>
              </div>
            </div>

            {/* Data Summary */}
            <div className="data-summary" style={{
              width: '100%',
              maxWidth: '100%',
              padding: is_small_mobile ? '0' : '0 0.5rem',
              boxSizing: 'border-box',
              marginBottom: is_small_mobile ? '1.5rem' : '2rem'
            }}>
              <h3 style={{
                fontSize: is_small_mobile ? '1rem' : is_mobile ? '1.25rem' : '1.5rem',
                fontWeight: '600',
                color: is_night_mode ? '#ffffff' : '#1f2937',
                textAlign: 'center',
                marginBottom: '1.5rem',
                transition: 'all 0.3s ease'
              }}>{language_service.t('data_summary')}</h3>
              <div className="summary-grid" style={{
                display: 'grid',
                gridTemplateColumns: is_small_mobile ? '1fr' : is_mobile ? 'repeat(2, 1fr)' : 'repeat(3, 1fr)',
                gap: is_small_mobile ? '0.75rem' : is_mobile ? '0.75rem' : '1rem',
                padding: is_small_mobile ? '0' : '0 0.25rem',
                width: '100%',
                maxWidth: '100%',
                boxSizing: 'border-box'
              }}>
                <div className="summary-item" style={{
                  backgroundColor: is_night_mode ? '#374151' : '#f9fafb',
                  padding: is_small_mobile ? '0.75rem' : is_mobile ? '1rem' : '1.5rem',
                  borderRadius: '12px',
                  boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                  border: `1px solid ${is_night_mode ? '#4b5563' : '#e5e7eb'}`,
                  textAlign: 'center',
                  minHeight: is_small_mobile ? '80px' : is_mobile ? '90px' : '100px',
                  display: 'flex',
                  flexDirection: 'column',
                  justifyContent: 'center',
                  transition: 'all 0.3s ease'
                }}>
                  <div style={{
                    fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.5rem' : '2rem', 
                    fontWeight: '700', 
                    color: is_night_mode ? '#d1d5db' : '#4b5563', 
                    marginBottom: '0.5rem'
                  }}>{sensors.length}</div>
                  <div style={{
                    fontSize: is_small_mobile ? '0.7rem' : is_mobile ? '0.75rem' : '0.875rem', 
                    color: is_night_mode ? '#9ca3af' : '#6b7280', 
                    textTransform: 'uppercase', 
                    letterSpacing: '0.05em'
                  }}>{language_service.t('sensors')}</div>
                </div>
                <div className="summary-item" style={{
                  backgroundColor: is_night_mode ? '#4b5563' : '#f3f4f6',
                  padding: is_small_mobile ? '0.75rem' : is_mobile ? '1rem' : '1.5rem',
                  borderRadius: '12px',
                  boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                  border: `1px solid ${is_night_mode ? '#6b7280' : '#d1d5db'}`,
                  textAlign: 'center',
                  minHeight: is_small_mobile ? '80px' : is_mobile ? '90px' : '100px',
                  display: 'flex',
                  flexDirection: 'column',
                  justifyContent: 'center',
                  transition: 'all 0.3s ease'
                }}>
                  <div style={{
                    fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.5rem' : '2rem', 
                    fontWeight: '700', 
                    color: is_night_mode ? '#e5e7eb' : '#374151', 
                    marginBottom: '0.5rem'
                  }}>{parking_spaces.length}</div>
                  <div style={{
                    fontSize: is_small_mobile ? '0.7rem' : is_mobile ? '0.75rem' : '0.875rem', 
                    color: is_night_mode ? '#9ca3af' : '#6b7280', 
                    textTransform: 'uppercase', 
                    letterSpacing: '0.05em'
                  }}>{language_service.t('parking_spaces')}</div>
                </div>
                <div className="summary-item" style={{
                  backgroundColor: is_night_mode ? '#374151' : '#f9fafb',
                  padding: is_small_mobile ? '0.75rem' : is_mobile ? '1rem' : '1.5rem',
                  borderRadius: '12px',
                  boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                  border: `1px solid ${is_night_mode ? '#4b5563' : '#e5e7eb'}`,
                  textAlign: 'center',
                  minHeight: is_small_mobile ? '80px' : is_mobile ? '90px' : '100px',
                  display: 'flex',
                  flexDirection: 'column',
                  justifyContent: 'center',
                  transition: 'all 0.3s ease'
                }}>
                  <div style={{
                    fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.5rem' : '2rem', 
                    fontWeight: '700', 
                    color: is_night_mode ? '#d1d5db' : '#4b5563', 
                    marginBottom: '0.5rem'
                  }}>{usage_data.length}</div>
                  <div style={{
                    fontSize: is_small_mobile ? '0.7rem' : is_mobile ? '0.75rem' : '0.875rem', 
                    color: is_night_mode ? '#9ca3af' : '#6b7280', 
                    textTransform: 'uppercase', 
                    letterSpacing: '0.05em'
                  }}>{language_service.t('usage_records')}</div>
                </div>
                <div className="summary-item" style={{
                  backgroundColor: is_night_mode ? '#4b5563' : '#f3f4f6',
                  padding: is_small_mobile ? '0.75rem' : is_mobile ? '1rem' : '1.5rem',
                  borderRadius: '12px',
                  boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                  border: `1px solid ${is_night_mode ? '#6b7280' : '#d1d5db'}`,
                  textAlign: 'center',
                  minHeight: is_small_mobile ? '80px' : is_mobile ? '90px' : '100px',
                  display: 'flex',
                  flexDirection: 'column',
                  justifyContent: 'center',
                  transition: 'all 0.3s ease'
                }}>
                  <div style={{
                    fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.5rem' : '2rem', 
                    fontWeight: '700', 
                    color: is_night_mode ? '#e5e7eb' : '#374151', 
                    marginBottom: '0.5rem'
                  }}>{reservations.length}</div>
                  <div style={{
                    fontSize: is_small_mobile ? '0.7rem' : is_mobile ? '0.75rem' : '0.875rem', 
                    color: is_night_mode ? '#9ca3af' : '#6b7280', 
                    textTransform: 'uppercase', 
                    letterSpacing: '0.05em'
                  }}>{language_service.t('reservations')}</div>
                </div>
                <div className="summary-item" style={{
                  backgroundColor: is_night_mode ? '#374151' : '#f9fafb',
                  padding: is_small_mobile ? '0.75rem' : is_mobile ? '1rem' : '1.5rem',
                  borderRadius: '12px',
                  boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                  border: `1px solid ${is_night_mode ? '#4b5563' : '#e5e7eb'}`,
                  textAlign: 'center',
                  minHeight: is_small_mobile ? '80px' : is_mobile ? '90px' : '100px',
                  display: 'flex',
                  flexDirection: 'column',
                  justifyContent: 'center',
                  transition: 'all 0.3s ease'
                }}>
                  <div style={{
                    fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.5rem' : '2rem', 
                    fontWeight: '700', 
                    color: is_night_mode ? '#d1d5db' : '#4b5563', 
                    marginBottom: '0.5rem'
                  }}>{reservations.filter(r => r.status === 'active').length}</div>
                  <div style={{
                    fontSize: is_small_mobile ? '0.7rem' : is_mobile ? '0.75rem' : '0.875rem', 
                    color: is_night_mode ? '#9ca3af' : '#6b7280', 
                    textTransform: 'uppercase', 
                    letterSpacing: '0.05em'
                  }}>{language_service.t('active_reservations')}</div>
                </div>
                <div className="summary-item" style={{
                  backgroundColor: is_night_mode ? '#4b5563' : '#f3f4f6',
                  padding: is_small_mobile ? '0.75rem' : is_mobile ? '1rem' : '1.5rem',
                  borderRadius: '12px',
                  boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                  border: `1px solid ${is_night_mode ? '#6b7280' : '#d1d5db'}`,
                  textAlign: 'center',
                  minHeight: is_small_mobile ? '80px' : is_mobile ? '90px' : '100px',
                  display: 'flex',
                  flexDirection: 'column',
                  justifyContent: 'center',
                  transition: 'all 0.3s ease'
                }}>
                  <div style={{
                    fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.5rem' : '2rem', 
                    fontWeight: '700', 
                    color: is_night_mode ? '#e5e7eb' : '#374151', 
                    marginBottom: '0.5rem'
                  }}>{reservations.filter(r => r.status === 'completed').length}</div>
                  <div style={{
                    fontSize: is_small_mobile ? '0.7rem' : is_mobile ? '0.75rem' : '0.875rem', 
                    color: is_night_mode ? '#9ca3af' : '#6b7280', 
                    textTransform: 'uppercase', 
                    letterSpacing: '0.05em'
                  }}>{language_service.t('completed_reservations')}</div>
                </div>
              </div>
            </div>

            {statistics && (
              <div style={{
                width: '100%',
                maxWidth: '100%',
                padding: is_small_mobile ? '0 0.25rem' : is_mobile ? '0 0.5rem' : '0 0.5rem',
                boxSizing: 'border-box',
                marginTop: is_small_mobile ? '1.5rem' : is_mobile ? '1.5rem' : '2rem'
              }}>
                <h3 style={{
                  fontSize: is_small_mobile ? '1rem' : is_mobile ? '1.25rem' : '1.5rem',
                  fontWeight: '600',
                  color: is_night_mode ? '#ffffff' : '#1f2937',
                  textAlign: 'center',
                  marginBottom: is_small_mobile ? '1rem' : '0.75rem',
                  transition: 'all 0.3s ease'
                }}>Key Statistics</h3>
                <div style={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(2, 1fr)',
                  gap: is_small_mobile ? '0.5rem' : is_mobile ? '0.75rem' : '1rem',
                  width: '100%',
                  maxWidth: '100%',
                  boxSizing: 'border-box',
                  gridAutoRows: 'minmax(90px, auto)',
                  margin: '0',
                  padding: '0'
                } as React.CSSProperties}>
                  <div className="stat-card" style={{
                    backgroundColor: is_night_mode ? '#374151' : '#f9fafb',
                    padding: is_small_mobile ? '0.75rem' : is_mobile ? '1rem' : '1.5rem',
                    borderRadius: '12px',
                    boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                    border: `1px solid ${is_night_mode ? '#4b5563' : '#e5e7eb'}`,
                    textAlign: 'center',
                    transition: 'all 0.3s ease',
                    width: '100%',
                    minHeight: is_small_mobile ? '90px' : is_mobile ? '100px' : '120px',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'center',
                    boxSizing: 'border-box'
                  }}>
                    <h3 style={{
                      fontSize: is_small_mobile ? '0.65rem' : is_mobile ? '0.7rem' : '0.875rem', 
                      color: is_night_mode ? '#9ca3af' : '#6b7280', 
                      marginBottom: '0.5rem', 
                      textTransform: 'uppercase', 
                      letterSpacing: '0.05em',
                      lineHeight: '1.2'
                    }}>UKUPNO MESTA</h3>
                    <p style={{
                      fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.5rem' : 'clamp(1.5rem, 4vw, 2rem)', 
                      fontWeight: '700', 
                      color: is_night_mode ? '#d1d5db' : '#4b5563', 
                      margin: '0',
                      lineHeight: '1.2'
                    }}>{statistics.total_spaces}</p>
                  </div>
                  <div className="stat-card" style={{
                    backgroundColor: is_night_mode ? '#4b5563' : '#f3f4f6',
                    padding: is_small_mobile ? '0.75rem' : is_mobile ? '1rem' : '1.5rem',
                    borderRadius: '12px',
                    boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                    border: `1px solid ${is_night_mode ? '#6b7280' : '#d1d5db'}`,
                    textAlign: 'center',
                    transition: 'all 0.3s ease',
                    width: '100%',
                    minHeight: is_small_mobile ? '90px' : is_mobile ? '100px' : '120px',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'center',
                    boxSizing: 'border-box'
                  }}>
                    <h3 style={{
                      fontSize: is_small_mobile ? '0.65rem' : is_mobile ? '0.7rem' : '0.875rem', 
                      color: is_night_mode ? '#9ca3af' : '#6b7280', 
                      marginBottom: '0.5rem', 
                      textTransform: 'uppercase', 
                      letterSpacing: '0.05em',
                      lineHeight: '1.2'
                    }}>STOPA ISKORIŠĆENOSTI</h3>
                    <p style={{
                      fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.5rem' : '2rem', 
                      fontWeight: '700', 
                      color: is_night_mode ? '#e5e7eb' : '#374151', 
                      margin: '0',
                      lineHeight: '1.2'
                    }}>{statistics.utilization_rate.toFixed(1)}%</p>
                  </div>
                  <div className="stat-card" style={{
                    backgroundColor: is_night_mode ? '#374151' : '#f9fafb',
                    padding: is_small_mobile ? '0.75rem' : is_mobile ? '1rem' : '1.5rem',
                    borderRadius: '12px',
                    boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                    border: `1px solid ${is_night_mode ? '#4b5563' : '#e5e7eb'}`,
                    textAlign: 'center',
                    transition: 'all 0.3s ease',
                    width: '100%',
                    minHeight: is_small_mobile ? '90px' : is_mobile ? '100px' : '120px',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'center',
                    boxSizing: 'border-box'
                  }}>
                    <h3 style={{
                      fontSize: is_small_mobile ? '0.65rem' : is_mobile ? '0.7rem' : '0.875rem', 
                      color: is_night_mode ? '#9ca3af' : '#6b7280', 
                      marginBottom: '0.5rem', 
                      textTransform: 'uppercase', 
                      letterSpacing: '0.05em',
                      lineHeight: '1.2'
                    }}>UKUPAN PRIHOD</h3>
                    <p style={{
                      fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.5rem' : '2rem', 
                      fontWeight: '700', 
                      color: is_night_mode ? '#d1d5db' : '#4b5563', 
                      margin: '0',
                      lineHeight: '1.2'
                    }}>${statistics.total_revenue.toFixed(2)}</p>
                  </div>
                  <div className="stat-card" style={{
                    backgroundColor: is_night_mode ? '#4b5563' : '#f3f4f6',
                    padding: is_small_mobile ? '0.75rem' : is_mobile ? '1rem' : '1.5rem',
                    borderRadius: '12px',
                    boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                    border: `1px solid ${is_night_mode ? '#6b7280' : '#d1d5db'}`,
                    textAlign: 'center',
                    transition: 'all 0.3s ease',
                    width: '100%',
                    minHeight: is_small_mobile ? '90px' : is_mobile ? '100px' : '120px',
                    display: 'flex',
                    flexDirection: 'column',
                    justifyContent: 'center',
                    boxSizing: 'border-box'
                  }}>
                    <h3 style={{
                      fontSize: is_small_mobile ? '0.65rem' : is_mobile ? '0.7rem' : '0.875rem', 
                      color: is_night_mode ? '#9ca3af' : '#6b7280', 
                      marginBottom: '0.5rem', 
                      textTransform: 'uppercase', 
                      letterSpacing: '0.05em',
                      lineHeight: '1.2'
                    }}>PROSEČNO TRAJANJE</h3>
                    <p style={{
                      fontSize: is_small_mobile ? '1.25rem' : is_mobile ? '1.5rem' : '2rem', 
                      fontWeight: '700', 
                      color: is_night_mode ? '#e5e7eb' : '#374151', 
                      margin: '0',
                      lineHeight: '1.2'
                    }}>{statistics.average_duration} min</p>
                  </div>
                </div>
              </div>
            )}

            <div className="charts-section" style={{
              width: '100%',
              maxWidth: '100%',
              display: 'flex',
              flexDirection: 'column',
              gap: is_small_mobile ? '1rem' : is_mobile ? '1.25rem' : '1.5rem',
              alignItems: 'center',
              padding: '0 0.5rem'
            }}>
              <div className="chart-container" style={{
                width: '100%',
                backgroundColor: is_night_mode ? '#2d3748' : 'white',
                padding: is_small_mobile ? '1rem' : is_mobile ? '1.5rem' : '2rem',
                borderRadius: '16px',
                boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                border: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}`
              }}>
                <h3 style={{
                  fontSize: is_small_mobile ? '1rem' : is_mobile ? '1.1rem' : '1.25rem',
                  fontWeight: '600',
                  color: is_night_mode ? '#ffffff' : '#1f2937',
                  textAlign: 'center',
                  marginBottom: '1rem',
                  transition: 'all 0.3s ease'
                }}>{language_service.t('daily_usage')}</h3>
                <ResponsiveContainer width="100%" height={is_small_mobile ? 200 : is_mobile ? 225 : 250}>
                  <BarChart data={statistics?.daily_usage}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                    <XAxis dataKey="date" stroke="#6b7280" />
                    <YAxis stroke="#6b7280" />
                    <Tooltip 
                      contentStyle={{
                        backgroundColor: 'white',
                        border: '1px solid #e5e7eb',
                        borderRadius: '8px',
                        boxShadow: '0 4px 6px rgba(0, 0, 0, 0.1)'
                      }}
                    />
                    <Legend />
                    <Bar dataKey="count" fill="#6b7280" radius={[4, 4, 0, 0]} />
                    <Bar dataKey="revenue" fill="#10b981" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>

              <div className="chart-container" style={{
                width: '100%',
                backgroundColor: is_night_mode ? '#2d3748' : 'white',
                padding: is_small_mobile ? '1rem' : is_mobile ? '1.5rem' : '2rem',
                borderRadius: '16px',
                boxShadow: is_night_mode ? '0 4px 6px rgba(0, 0, 0, 0.3)' : '0 4px 6px rgba(0, 0, 0, 0.1)',
                border: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}`
              }}>
                <h3 style={{
                  fontSize: is_small_mobile ? '1rem' : is_mobile ? '1.1rem' : '1.25rem',
                  fontWeight: '600',
                  color: is_night_mode ? '#ffffff' : '#1f2937',
                  textAlign: 'center',
                  marginBottom: '1rem',
                  transition: 'all 0.3s ease'
                }}>{language_service.t('hourly_usage')}</h3>
                <ResponsiveContainer width="100%" height={is_small_mobile ? 200 : is_mobile ? 225 : 250}>
                  <LineChart data={statistics?.hourly_usage}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                    <XAxis dataKey="hour" stroke="#6b7280" />
                    <YAxis stroke="#6b7280" />
                    <Tooltip 
                      contentStyle={{
                        backgroundColor: 'white',
                        border: '1px solid #e5e7eb',
                        borderRadius: '8px',
                        boxShadow: '0 4px 6px rgba(0, 0, 0, 0.1)'
                      }}
                    />
                    <Legend />
                    <Line 
                      type="monotone" 
                      dataKey="count" 
                      stroke="#6b7280" 
                      strokeWidth={3}
                      dot={{ fill: '#6b7280', strokeWidth: 2, r: 4 }}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </div>
          </div>
        )}

        {active_tab === 'sensors' && (
          <div className="sensors-tab">
            <div className="tab-header">
              <h2>{language_service.t('sensors_management')}</h2>
              <button className="add-sensor-btn" onClick={open_add_sensor_form}>
                {language_service.t('add_sensor')}
              </button>
            </div>

            <div className="sensors-grid">
              {sensors.map(sensor => (
                <div key={sensor.id} className="sensor-card">
                  <div className="sensor-info">
                    <h3>{sensor.name}</h3>
                    <p>ID: {sensor.wpsd_id}</p>
                    <p>{language_service.t('status')}: <span className={`status ${sensor.status}`}>{sensor.status}</span></p>
                    <p>{language_service.t('street_name')}: {sensor.street_name}</p>
                    <p>{language_service.t('coordinates')}: {sensor.coordinates.lat.toFixed(6)}, {sensor.coordinates.lng.toFixed(6)}</p>
                    {sensor.zone && (
                      <div className="sensor-zone">
                        <span className="zone-badge" style={{backgroundColor: sensor.zone.color}}>
                          {sensor.zone.name}
                        </span>
                        <small>${sensor.zone.hourly_rate}/hour, ${sensor.zone.daily_rate}/day</small>
                      </div>
                    )}
                  </div>
                  <div className="sensor-actions">
                    <button 
                      className="edit-btn"
                      onClick={() => open_edit_sensor_form(sensor)}
                    >
                      {language_service.t('edit')}
                    </button>
                    <button 
                      className="delete-btn"
                      onClick={() => handle_delete_sensor(sensor.id)}
                    >
                      {language_service.t('delete')}
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {active_tab === 'spaces' && (
          <div className="spaces-tab">
            <h2>{language_service.t('parking_spaces')}</h2>
            {space_status_feedback && (
              <div style={{
                margin: '0.5rem 0 1rem',
                padding: '0.75rem 1rem',
                backgroundColor: '#dcfce7',
                color: '#166534',
                borderRadius: '6px'
              }}>
                {space_status_feedback}
              </div>
            )}
            <p style={{ color: '#6b7280', marginBottom: '1rem' }}>
              {language_service.t('spaces')} - {parking_spaces.length}
            </p>
            <div className="spaces-grid">
              {parking_spaces.map(space => (
                <div key={space.id} className="space-card">
                  <h3>{language_service.t('space')} {space.id}</h3>
                  <p>{language_service.t('status')}: <span className={`status ${space.status}`}>{space.status}</span></p>
                  <p>{language_service.t('sensor')}: {space.sensor_id}</p>
                  {space.license_plate && <p>{language_service.t('plate')}: {space.license_plate}</p>}
                  {current_user?.role === 'superadmin' && (
                    <div className="space-actions" style={{ marginTop: '0.75rem' }}>
                      <p style={{ fontSize: '0.85rem', color: '#6b7280', marginBottom: '0.5rem' }}>Manual override</p>
                      <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
                        <button
                          onClick={() => update_space_status(space.id, 'vacant')}
                          disabled={space_status_loading === space.id}
                          style={{
                            padding: '0.35rem 0.75rem',
                            borderRadius: '4px',
                            border: 'none',
                            backgroundColor: '#10b981',
                            color: 'white',
                            cursor: 'pointer'
                          }}
                        >
                          Set Vacant
                        </button>
                        <button
                          onClick={() => update_space_status(space.id, 'occupied')}
                          disabled={space_status_loading === space.id}
                          style={{
                            padding: '0.35rem 0.75rem',
                            borderRadius: '4px',
                            border: 'none',
                            backgroundColor: '#f97316',
                            color: 'white',
                            cursor: 'pointer'
                          }}
                        >
                          Set Occupied
                        </button>
                      </div>
                      {space_status_loading === space.id && (
                        <p style={{ fontSize: '0.75rem', color: '#6b7280', marginTop: '0.5rem' }}>Updating...</p>
                      )}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>
        )}

        {active_tab === 'usage' && (
          <div className="usage-tab">
            <div className="filter-section">
              <h2>{language_service.t('analytics')}</h2>
              <input
                type="date"
                value={filter_date}
                onChange={(e) => set_filter_date(e.target.value)}
                placeholder="Filter by date"
              />
            </div>
            <div className="usage-table">
              <table>
                <thead>
                  <tr>
                    <th>{language_service.t('license_plate')}</th>
                    <th>{language_service.t('space')}</th>
                    <th>Start Time</th>
                    <th>Duration</th>
                    <th>Cost</th>
                  </tr>
                </thead>
                <tbody>
                  {usage_data.map(usage => (
                    <tr key={usage.id}>
                      <td>{usage.license_plate}</td>
                      <td>{usage.parking_space_id}</td>
                      <td>{new Date(usage.start_time).toLocaleString()}</td>
                      <td>{usage.duration_minutes || 'N/A'} min</td>
                      <td>${usage.total_cost || 'N/A'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {active_tab === 'reservations' && (
          <div className="reservations-tab">
            <h2>{language_service.t('bookings')}</h2>
            <div className="reservations-grid">
              {reservations.map(reservation => (
                <div key={reservation.id} className="reservation-card">
                  <h3>{language_service.t('bookings')} {reservation.id}</h3>
                  <p>{language_service.t('plate')}: {reservation.license_plate}</p>
                  <p>{language_service.t('space')}: {reservation.parking_space_id}</p>
                  <p>{language_service.t('status')}: <span className={`status ${reservation.status}`}>{reservation.status}</span></p>
                  <p>Start: {new Date(reservation.start_time).toLocaleString()}</p>
                  <p>End: {new Date(reservation.end_time).toLocaleString()}</p>
                </div>
              ))}
            </div>
          </div>
        )}

        {active_tab === 'sessions' && (
          <div className="sessions-tab">
            <h2>{language_service.t('active_sessions')}</h2>
            <div className="sessions-grid">
              {parking_spaces
                .filter(space => space.status === 'reserved' || space.status === 'occupied')
                .map(space => {
                  const sensor = sensors.find(s => s.id === space.sensor_id);
                  const start_time = space.reservation_time || space.occupied_since;
                  const duration = start_time ? Math.round((Date.now() - new Date(start_time).getTime()) / (1000 * 60)) : 0;
                  
                  return (
                    <div key={space.id} className="session-card">
                      <h3>{language_service.t('space')} {space.id}</h3>
                      <p>{language_service.t('plate')}: {space.license_plate || 'N/A'}</p>
                      <p>{language_service.t('street_name')}: {sensor?.street_name || 'N/A'}</p>
                      <p>{language_service.t('status')}: <span className={`status ${space.status}`}>{space.status}</span></p>
                      <p>Start: {start_time ? new Date(start_time).toLocaleString() : 'N/A'}</p>
                      <p>Duration: {duration > 0 ? `${duration} min` : 'N/A'}</p>
                    </div>
                  );
                })}
            </div>
          </div>
        )}

        {/* Admin Users Tab */}
        {active_tab === 'admin_users' && admin_service.isSuperAdmin() && (
          <div className="admin-users-tab">
            <div className="tab-header">
              <h3>{language_service.t('admin_user_management')}</h3>
              <button className="add-admin-btn" onClick={() => set_show_admin_user_form(true)}>
                <Users size={16} />
                {language_service.t('add_admin_user')}
              </button>
            </div>
            
            <div className="admin-users-list">
              {admin_users.map(user => (
                <div key={user.id} className="admin-user-card">
                  <div className="user-info">
                    <div className="user-header">
                      <h4>{user.username}</h4>
                      <span className={`role-badge ${user.role}`}>{user.role}</span>
                    </div>
                    <p className="user-email">{user.email}</p>
                    <p className="user-status">
                      {language_service.t('status')}: <span className={user.is_active ? 'active' : 'inactive'}>
                        {user.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </p>
                    <p className="user-last-login">
                      Last Login: {user.last_login ? new Date(user.last_login).toLocaleString() : 'Never'}
                    </p>
                  </div>
                  <div className="user-actions">
                    <button 
                      className="edit-btn"
                      onClick={() => {
                        set_editing_admin_user(user);
                        set_admin_user_form_data({
                          username: user.username,
                          password: '',
                          email: user.email,
                          role: user.role
                        });
                        set_show_admin_user_form(true);
                      }}
                    >
                      {language_service.t('edit')}
                    </button>
                    {user.role !== 'superadmin' && (
                      <button 
                        className="delete-btn"
                        onClick={() => handle_delete_admin_user(user.id)}
                      >
                        {language_service.t('delete')}
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Admin Logs Tab */}
        {active_tab === 'admin_logs' && admin_service.isSuperAdmin() && (
          <div className="admin-logs-tab">
            <div className="tab-header">
              <h3>{language_service.t('admin_activity_logs')}</h3>
              <p>{language_service.t('track_admin_actions')}</p>
            </div>
            
            <div className="admin-logs-list">
              {admin_logs.map(log => (
                <div key={log.id} className="admin-log-card">
                  <div className="log-header">
                    <div className="log-action">
                      <span className={`action-badge ${log.action.toLowerCase()}`}>
                        {log.action}
                      </span>
                      <span className="log-table">{log.table_name}</span>
                    </div>
                    <span className="log-timestamp">
                      {new Date(log.created_at).toLocaleString()}
                    </span>
                  </div>
                  <div className="log-details">
                    <p className="log-admin">
                      {language_service.t('admin')}: <strong>{log.admin_username}</strong> ({log.admin_role})
                    </p>
                    {log.old_values && (
                      <div className="log-changes">
                        <p><strong>{language_service.t('previous_values')}</strong></p>
                        <pre>{log.old_values}</pre>
                      </div>
                    )}
                    {log.new_values && (
                      <div className="log-changes">
                        <p><strong>{language_service.t('new_values')}</strong></p>
                        <pre>{log.new_values}</pre>
                      </div>
                    )}
                    {log.ip_address && (
                      <p className="log-ip">IP: {log.ip_address}</p>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Zone Management Tab */}
        {active_tab === 'zones' && current_user?.role === 'superadmin' && (
          <ZoneManagement is_superadmin={true} />
        )}

        {/* Real Sensors Tab */}
        {active_tab === 'real_sensors' && current_user?.role === 'superadmin' && (
          <div className="real-sensors-tab" style={{ padding: '1rem' }}>
            <div className="tab-header" style={{ 
              display: 'flex', 
              justifyContent: 'space-between', 
              alignItems: 'center',
              marginBottom: '1rem'
            }}>
              <h2>Real Sensors</h2>
              <button 
                onClick={() => {
                  load_real_sensors_data();
                  load_firewall_whitelist();
                }}
                disabled={real_sensors_loading}
                style={{
                  padding: '0.5rem 1rem',
                  backgroundColor: '#4b5563',
                  color: 'white',
                  border: 'none',
                  borderRadius: '6px',
                  cursor: 'pointer'
                }}
              >
                {real_sensors_loading ? 'Loading...' : 'Refresh'}
              </button>
            </div>

            {/* Server Status */}
            <div style={{ 
              marginBottom: '1rem',
              padding: '1rem',
              backgroundColor: is_night_mode ? '#2d3748' : '#f3f4f6',
              borderRadius: '8px'
            }}>
              <h3 style={{ margin: '0 0 0.5rem 0' }}>Server Status</h3>
              <p style={{ margin: 0 }}>
                TCP Server: <span style={{ 
                  color: real_sensors_server_status === 'running' ? 'green' : 'red',
                  fontWeight: 'bold'
                }}>
                  {real_sensors_server_status}
                </span>
              </p>
            </div>

            <div style={{
              marginBottom: '1rem',
              padding: '1rem',
              backgroundColor: is_night_mode ? '#1f2937' : '#f9fafb',
              borderRadius: '8px',
              border: `1px solid ${is_night_mode ? '#374151' : '#e5e7eb'}`
            }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.5rem' }}>
                <div>
                  <h3 style={{ margin: 0 }}>Firewall Whitelist</h3>
                  <p style={{ margin: 0, fontSize: '0.875rem', color: '#6b7280' }}>
                    Maintain the IP allow list for your firewalld scripts. Stored IPs: {firewall_whitelist.length}.
                  </p>
                </div>
                <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
                  <button
                    onClick={() => {
                      set_firewall_show_list(prev => {
                        const next = !prev;
                        if (next) {
                          set_firewall_page(1);
                        }
                        return next;
                      });
                    }}
                    style={{
                      padding: '0.5rem 1rem',
                      backgroundColor: firewall_show_list ? '#4b5563' : '#1d4ed8',
                      color: 'white',
                      border: 'none',
                      borderRadius: '6px',
                      cursor: 'pointer'
                    }}
                  >
                    {firewall_show_list ? 'Hide List' : 'Show List'}
                  </button>
                  <button
                    onClick={save_firewall_whitelist}
                    disabled={firewall_saving || firewall_loading}
                    style={{
                      padding: '0.5rem 1rem',
                      backgroundColor: '#10b981',
                      color: 'white',
                      border: 'none',
                      borderRadius: '6px',
                      cursor: 'pointer'
                    }}
                  >
                    {firewall_saving ? 'Saving...' : 'Save'}
                  </button>
                </div>
              </div>
              {firewall_error && (
                <div style={{ marginTop: '0.5rem', padding: '0.5rem', backgroundColor: '#fee2e2', color: '#b91c1c', borderRadius: '4px' }}>
                  {firewall_error}
                </div>
              )}
              {firewall_success && (
                <div style={{ marginTop: '0.5rem', padding: '0.5rem', backgroundColor: '#dcfce7', color: '#166534', borderRadius: '4px' }}>
                  {firewall_success}
                </div>
              )}

              {firewall_show_list && (
                <div style={{ marginTop: '1rem', overflowX: 'auto' }}>
                  <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <thead>
                      <tr style={{ backgroundColor: is_night_mode ? '#374151' : '#e5e7eb' }}>
                        <th style={{ padding: '0.75rem', textAlign: 'left' }}>IP Address</th>
                        <th style={{ padding: '0.75rem', textAlign: 'left' }}>Label / Description</th>
                        <th style={{ padding: '0.75rem', textAlign: 'center' }}>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {firewall_loading ? (
                        <tr>
                          <td colSpan={3} style={{ padding: '1rem', textAlign: 'center' }}>Loading whitelist...</td>
                        </tr>
                      ) : firewall_whitelist.length === 0 ? (
                        <tr>
                          <td colSpan={3} style={{ padding: '1rem', textAlign: 'center', color: '#6b7280' }}>No IP addresses added yet.</td>
                        </tr>
                      ) : (
                        displayed_firewall_whitelist.map((entry, index) => {
                          const globalIndex = firewall_page_start + index;
                          return (
                            <tr key={`${entry.ip}-${globalIndex}`} style={{ borderBottom: `1px solid ${is_night_mode ? '#374151' : '#e5e7eb'}` }}>
                              <td style={{ padding: '0.5rem' }}>
                                <input
                                  type="text"
                                  value={entry.ip}
                                  onChange={(e) => update_firewall_entry(globalIndex, 'ip', e.target.value)}
                                  style={{
                                    width: '100%',
                                    padding: '0.5rem',
                                    border: '1px solid #d1d5db',
                                    borderRadius: '4px',
                                    fontFamily: 'monospace'
                                  }}
                                />
                              </td>
                              <td style={{ padding: '0.5rem' }}>
                                <input
                                  type="text"
                                  value={entry.label || ''}
                                  onChange={(e) => update_firewall_entry(globalIndex, 'label', e.target.value)}
                                  placeholder="e.g., Remote office"
                                  style={{
                                    width: '100%',
                                    padding: '0.5rem',
                                    border: '1px solid #d1d5db',
                                    borderRadius: '4px'
                                  }}
                                />
                              </td>
                              <td style={{ padding: '0.5rem', textAlign: 'center' }}>
                                <button
                                  onClick={() => remove_firewall_entry(globalIndex)}
                                  style={{
                                    padding: '0.25rem 0.75rem',
                                    backgroundColor: '#ef4444',
                                    color: 'white',
                                    border: 'none',
                                    borderRadius: '4px',
                                    cursor: 'pointer'
                                  }}
                                >
                                  Remove
                                </button>
                              </td>
                            </tr>
                          );
                        })
                      )}
                    </tbody>
                  </table>
                  {firewall_whitelist.length > FIREWALL_PAGE_SIZE && (
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '0.75rem' }}>
                      <span style={{ fontSize: '0.875rem', color: '#6b7280' }}>
                        Page {safe_firewall_page} of {total_firewall_pages}
                      </span>
                      <div style={{ display: 'flex', gap: '0.5rem' }}>
                        <button
                          onClick={() => set_firewall_page(prev => Math.max(1, prev - 1))}
                          disabled={safe_firewall_page === 1}
                          style={{
                            padding: '0.35rem 0.75rem',
                            borderRadius: '4px',
                            border: '1px solid #d1d5db',
                            backgroundColor: safe_firewall_page === 1 ? '#e5e7eb' : 'white',
                            cursor: safe_firewall_page === 1 ? 'not-allowed' : 'pointer'
                          }}
                        >
                          Previous
                        </button>
                        <button
                          onClick={() => set_firewall_page(prev => Math.min(total_firewall_pages, prev + 1))}
                          disabled={safe_firewall_page === total_firewall_pages}
                          style={{
                            padding: '0.35rem 0.75rem',
                            borderRadius: '4px',
                            border: '1px solid #d1d5db',
                            backgroundColor: safe_firewall_page === total_firewall_pages ? '#e5e7eb' : 'white',
                            cursor: safe_firewall_page === total_firewall_pages ? 'not-allowed' : 'pointer'
                          }}
                        >
                          Next
                        </button>
                      </div>
                    </div>
                  )}
                </div>
              )}

              <div style={{ marginTop: '1rem', display: 'grid', gridTemplateColumns: 'minmax(200px, 1fr) minmax(200px, 1fr) 120px', gap: '0.5rem', alignItems: 'center' }}>
                <input
                  type="text"
                  value={firewall_new_ip}
                  onChange={(e) => set_firewall_new_ip(e.target.value)}
                  placeholder="Add IP (e.g., 91.196.152.105)"
                  style={{
                    width: '100%',
                    padding: '0.5rem',
                    border: '1px solid #d1d5db',
                    borderRadius: '4px'
                  }}
                />
                <input
                  type="text"
                  value={firewall_new_label}
                  onChange={(e) => set_firewall_new_label(e.target.value)}
                  placeholder="Label (optional)"
                  style={{
                    width: '100%',
                    padding: '0.5rem',
                    border: '1px solid #d1d5db',
                    borderRadius: '4px'
                  }}
                />
                <button
                  onClick={add_firewall_entry}
                  style={{
                    padding: '0.5rem 0.75rem',
                    backgroundColor: '#2563eb',
                    color: 'white',
                    border: 'none',
                    borderRadius: '4px',
                    cursor: 'pointer'
                  }}
                >
                  Add
                </button>
              </div>
              <p style={{ marginTop: '0.75rem', fontSize: '0.875rem', color: '#6b7280' }}>
                Tip: Changes auto-apply when the server cron/timer runs <code>HELPERS/apply_firewall_whitelist.sh</code>.
              </p>
            </div>

            {/* Statistics */}
            {real_sensors_stats && (
              <div style={{ 
                marginBottom: '1rem',
                padding: '1rem',
                backgroundColor: is_night_mode ? '#2d3748' : '#f3f4f6',
                borderRadius: '8px',
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))',
                gap: '1rem'
              }}>
                <div>
                  <div style={{ fontSize: '0.875rem', color: '#6b7280' }}>Total Received</div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 'bold' }}>{real_sensors_stats.total_received || 0}</div>
                </div>
                <div>
                  <div style={{ fontSize: '0.875rem', color: '#6b7280' }}>Total Updated</div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 'bold', color: 'green' }}>{real_sensors_stats.total_updated || 0}</div>
                </div>
                <div>
                  <div style={{ fontSize: '0.875rem', color: '#6b7280' }}>Ignored (Reservation)</div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 'bold', color: 'orange' }}>{real_sensors_stats.total_ignored_reservation || 0}</div>
                </div>
                <div>
                  <div style={{ fontSize: '0.875rem', color: '#6b7280' }}>Ignored (Unknown)</div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 'bold', color: '#9ca3af' }}>{real_sensors_stats.total_ignored_unknown || 0}</div>
                </div>
                <div>
                  <div style={{ fontSize: '0.875rem', color: '#6b7280' }}>Errors</div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 'bold', color: 'red' }}>{real_sensors_stats.total_errors || 0}</div>
                </div>
              </div>
            )}

            {/* Filters */}
            <div style={{ 
              marginBottom: '1rem',
              padding: '1rem',
              backgroundColor: is_night_mode ? '#2d3748' : '#f3f4f6',
              borderRadius: '8px',
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
              gap: '1rem'
            }}>
              <div>
                <label style={{ display: 'block', marginBottom: '0.25rem', fontSize: '0.875rem' }}>WPSD ID</label>
                <input
                  type="text"
                  value={real_sensors_filters.wpsd_id}
                  onChange={(e) => set_real_sensors_filters({...real_sensors_filters, wpsd_id: e.target.value})}
                  placeholder="Filter by sensor ID"
                  style={{
                    width: '100%',
                    padding: '0.5rem',
                    border: '1px solid #d1d5db',
                    borderRadius: '4px'
                  }}
                />
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: '0.25rem', fontSize: '0.875rem' }}>Action</label>
                <select
                  value={real_sensors_filters.action}
                  onChange={(e) => set_real_sensors_filters({...real_sensors_filters, action: e.target.value})}
                  style={{
                    width: '100%',
                    padding: '0.5rem',
                    border: '1px solid #d1d5db',
                    borderRadius: '4px'
                  }}
                >
                  <option value="">All</option>
                  <option value="received">Received</option>
                  <option value="updated">Updated</option>
                  <option value="ignored_reservation">Ignored (Reservation)</option>
                  <option value="ignored_unknown">Ignored (Unknown)</option>
                  <option value="error">Error</option>
                </select>
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: '0.25rem', fontSize: '0.875rem' }}>Date From</label>
                <input
                  type="date"
                  value={real_sensors_filters.date_from}
                  onChange={(e) => set_real_sensors_filters({...real_sensors_filters, date_from: e.target.value})}
                  style={{
                    width: '100%',
                    padding: '0.5rem',
                    border: '1px solid #d1d5db',
                    borderRadius: '4px'
                  }}
                />
              </div>
              <div>
                <label style={{ display: 'block', marginBottom: '0.25rem', fontSize: '0.875rem' }}>Date To</label>
                <input
                  type="date"
                  value={real_sensors_filters.date_to}
                  onChange={(e) => set_real_sensors_filters({...real_sensors_filters, date_to: e.target.value})}
                  style={{
                    width: '100%',
                    padding: '0.5rem',
                    border: '1px solid #d1d5db',
                    borderRadius: '4px'
                  }}
                />
              </div>
              <div style={{ display: 'flex', alignItems: 'flex-end', gap: '0.5rem' }}>
                <button
                  onClick={() => {
                    set_real_sensors_filters({
                      wpsd_id: '',
                      action: '',
                      date_from: '',
                      date_to: '',
                      limit: 100,
                      offset: 0
                    });
                    setTimeout(() => {
                      load_real_sensors_data();
                      load_firewall_whitelist();
                    }, 100);
                  }}
                  style={{
                    padding: '0.5rem 1rem',
                    backgroundColor: '#6b7280',
                    color: 'white',
                    border: 'none',
                    borderRadius: '4px',
                    cursor: 'pointer'
                  }}
                >
                  Clear Filters
                </button>
                <button
                  onClick={load_real_sensors_data}
                  disabled={real_sensors_loading}
                  style={{
                    padding: '0.5rem 1rem',
                    backgroundColor: '#4b5563',
                    color: 'white',
                    border: 'none',
                    borderRadius: '4px',
                    cursor: 'pointer',
                    opacity: real_sensors_loading ? 0.6 : 1
                  }}
                >
                  Apply Filters
                </button>
              </div>
            </div>

            {/* Logs Table */}
            <div style={{ 
              overflowX: 'auto',
              backgroundColor: is_night_mode ? '#1a202c' : 'white',
              borderRadius: '8px',
              border: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}`
            }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={{ backgroundColor: is_night_mode ? '#2d3748' : '#f9fafb' }}>
                    <th style={{ padding: '0.75rem', textAlign: 'left', borderBottom: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}` }}>Timestamp</th>
                    <th style={{ padding: '0.75rem', textAlign: 'left', borderBottom: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}` }}>WPSD ID</th>
                    <th style={{ padding: '0.75rem', textAlign: 'left', borderBottom: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}` }}>WDC ID</th>
                    <th style={{ padding: '0.75rem', textAlign: 'left', borderBottom: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}` }}>Action</th>
                    <th style={{ padding: '0.75rem', textAlign: 'left', borderBottom: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}` }}>Status</th>
                    <th style={{ padding: '0.75rem', textAlign: 'left', borderBottom: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}` }}>Message</th>
                  </tr>
                </thead>
                <tbody>
                  {real_sensors_logs.length === 0 ? (
                    <tr>
                      <td colSpan={6} style={{ padding: '2rem', textAlign: 'center', color: '#6b7280' }}>
                        {real_sensors_loading ? 'Loading...' : 'No logs found'}
                      </td>
                    </tr>
                  ) : (
                    real_sensors_logs.map((log, index) => (
                      <tr key={index} style={{ borderBottom: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}` }}>
                        <td style={{ padding: '0.75rem' }}>{log.timestamp || '-'}</td>
                        <td style={{ padding: '0.75rem' }}>{log.wpsd_id || '-'}</td>
                        <td style={{ padding: '0.75rem' }}>{log.wdc_id || '-'}</td>
                        <td style={{ padding: '0.75rem' }}>
                          <span style={{
                            padding: '0.25rem 0.5rem',
                            borderRadius: '4px',
                            fontSize: '0.75rem',
                            backgroundColor: 
                              log.action === 'updated' ? '#10b981' :
                              log.action === 'ignored_reservation' ? '#f59e0b' :
                              log.action === 'ignored_unknown' ? '#6b7280' :
                              log.action === 'error' ? '#ef4444' : '#3b82f6',
                            color: 'white'
                          }}>
                            {log.action || '-'}
                          </span>
                        </td>
                        <td style={{ padding: '0.75rem' }}>
                          {log.parsed_data?.occupancy_status || '-'}
                        </td>
                        <td style={{ padding: '0.75rem', fontSize: '0.875rem' }}>{log.message || '-'}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}


      </div>

      {/* Admin User Form Modal */}
      {show_admin_user_form && (
        <div className="modal-overlay" style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          backgroundColor: 'rgba(0, 0, 0, 0.5)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 1000,
          padding: '1rem'
        }}>
          <div className="modal-content admin-user-form-modal" style={{
            backgroundColor: is_night_mode ? '#2d3748' : 'white',
            borderRadius: '12px',
            boxShadow: is_night_mode ? '0 20px 25px -5px rgba(0, 0, 0, 0.4), 0 10px 10px -5px rgba(0, 0, 0, 0.2)' : '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)',
            width: is_small_mobile ? '95%' : is_mobile ? '90%' : '500px',
            maxWidth: '95vw',
            maxHeight: '90vh',
            overflow: 'hidden',
            display: 'flex',
            flexDirection: 'column',
            transition: 'all 0.3s ease'
          }}>
                          <div className="modal-header" style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                padding: is_small_mobile ? '0.75rem 1rem' : '1rem 1.5rem',
                borderBottom: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}`,
                backgroundColor: is_night_mode ? '#1a202c' : '#f9fafb'
              }}>
              <h3 style={{
                margin: '0',
                fontSize: is_small_mobile ? '1rem' : is_mobile ? '1.1rem' : '1.25rem',
                fontWeight: '600',
                color: is_night_mode ? '#ffffff' : '#1f2937',
                flex: '1',
                paddingRight: is_small_mobile ? '1rem' : '2rem'
              }}>{editing_admin_user ? language_service.t('edit_admin_user') : language_service.t('add_new_admin_user')}</h3>
              <button 
                className="modal-close" 
                onClick={() => set_show_admin_user_form(false)}
                style={{
                  background: 'none',
                  border: 'none',
                  fontSize: '1.5rem',
                  fontWeight: 'bold',
                  color: is_night_mode ? '#d1d5db' : '#6b7280',
                  cursor: 'pointer',
                  padding: '0.25rem',
                  borderRadius: '4px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  width: '2rem',
                  height: '2rem',
                  transition: 'all 0.2s ease',
                  flexShrink: 0
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = is_night_mode ? '#374151' : '#f3f4f6';
                  e.currentTarget.style.color = is_night_mode ? '#ffffff' : '#374151';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = 'transparent';
                  e.currentTarget.style.color = is_night_mode ? '#d1d5db' : '#6b7280';
                }}
              >×</button>
            </div>
            
            <div className="modal-body" style={{
              padding: is_small_mobile ? '1rem' : is_mobile ? '1.25rem' : '1.5rem',
              maxHeight: '70vh',
              overflowY: 'auto'
            }}>
              <div className="form-group" style={{
                marginBottom: '1rem'
              }}>
                <label style={{
                  display: 'block',
                  marginBottom: '0.5rem',
                  fontWeight: '500',
                  color: is_night_mode ? '#d1d5db' : '#374151',
                  fontSize: '0.875rem'
                }}>{language_service.t('username')} *</label>
                <input
                  type="text"
                  value={admin_user_form_data.username}
                  onChange={(e) => set_admin_user_form_data(prev => ({ ...prev, username: e.target.value }))}
                  placeholder={language_service.t('enter_username')}
                  disabled={!!editing_admin_user}
                  style={{
                    width: '100%',
                    padding: is_small_mobile ? '0.6rem' : '0.75rem',
                    border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                    borderRadius: '6px',
                    fontSize: is_small_mobile ? '0.8rem' : '0.875rem',
                    backgroundColor: editing_admin_user ? (is_night_mode ? '#374151' : '#f3f4f6') : (is_night_mode ? '#2d3748' : 'white'),
                    color: is_night_mode ? '#ffffff' : '#1f2937',
                    minHeight: '44px'
                  }}
                />
              </div>
              
              <div className="form-group" style={{
                marginBottom: '1rem'
              }}>
                <label style={{
                  display: 'block',
                  marginBottom: '0.5rem',
                  fontWeight: '500',
                  color: is_night_mode ? '#d1d5db' : '#374151',
                  fontSize: '0.875rem'
                }}>{language_service.t('password')} {editing_admin_user ? `(${language_service.t('password_leave_blank')})` : '*'}</label>
                <input
                  type="password"
                  value={admin_user_form_data.password}
                  onChange={(e) => set_admin_user_form_data(prev => ({ ...prev, password: e.target.value }))}
                  placeholder={language_service.t('enter_password')}
                  style={{
                    width: '100%',
                    padding: '0.75rem',
                    border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                    borderRadius: '6px',
                    fontSize: '0.875rem',
                    backgroundColor: is_night_mode ? '#2d3748' : 'white',
                    color: is_night_mode ? '#ffffff' : '#1f2937'
                  }}
                />
              </div>
              
              <div className="form-group" style={{
                marginBottom: '1rem'
              }}>
                <label style={{
                  display: 'block',
                  marginBottom: '0.5rem',
                  fontWeight: '500',
                  color: is_night_mode ? '#d1d5db' : '#374151',
                  fontSize: '0.875rem'
                }}>{language_service.t('email')} *</label>
                <input
                  type="email"
                  value={admin_user_form_data.email}
                  onChange={(e) => set_admin_user_form_data(prev => ({ ...prev, email: e.target.value }))}
                  placeholder="Enter email"
                  style={{
                    width: '100%',
                    padding: '0.75rem',
                    border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                    borderRadius: '6px',
                    fontSize: '0.875rem',
                    backgroundColor: is_night_mode ? '#2d3748' : 'white',
                    color: is_night_mode ? '#ffffff' : '#1f2937'
                  }}
                />
              </div>
              
              <div className="form-group" style={{
                marginBottom: '1rem'
              }}>
                <label style={{
                  display: 'block',
                  marginBottom: '0.5rem',
                  fontWeight: '500',
                  color: is_night_mode ? '#d1d5db' : '#374151',
                  fontSize: '0.875rem'
                }}>{language_service.t('role')} *</label>
                <select
                  value={admin_user_form_data.role}
                  onChange={(e) => set_admin_user_form_data(prev => ({ ...prev, role: e.target.value as 'admin' | 'superadmin' }))}
                  style={{
                    width: '100%',
                    padding: '0.75rem',
                    border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                    borderRadius: '6px',
                    fontSize: '0.875rem',
                    backgroundColor: is_night_mode ? '#2d3748' : 'white',
                    color: is_night_mode ? '#ffffff' : '#1f2937'
                  }}
                >
                  <option value="admin">{language_service.t('admin')}</option>
                  <option value="superadmin">{language_service.t('superadmin')}</option>
                </select>
              </div>
            </div>
            
            <div className="modal-footer" style={{
              display: 'flex',
              justifyContent: 'flex-end',
              gap: is_small_mobile ? '0.5rem' : '0.75rem',
              padding: is_small_mobile ? '0.75rem 1rem' : '1rem 1.5rem',
              borderTop: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}`,
              backgroundColor: is_night_mode ? '#1a202c' : '#f9fafb',
              flexWrap: 'wrap'
            }}>
              <button 
                className="btn-secondary" 
                onClick={() => set_show_admin_user_form(false)}
                style={{
                  padding: is_small_mobile ? '0.6rem 1rem' : is_mobile ? '0.7rem 1.25rem' : '0.75rem 1.5rem',
                  border: '1px solid #d1d5db',
                  borderRadius: '6px',
                  backgroundColor: 'white',
                  color: '#374151',
                  fontSize: is_small_mobile ? '0.8rem' : is_mobile ? '0.85rem' : '0.875rem',
                  fontWeight: '500',
                  cursor: 'pointer',
                  transition: 'all 0.2s ease',
                  minHeight: '44px'
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = '#f3f4f6';
                  e.currentTarget.style.borderColor = '#9ca3af';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = 'white';
                  e.currentTarget.style.borderColor = '#d1d5db';
                }}
              >
                {language_service.t('cancel')}
              </button>
              <button 
                className="btn-primary" 
                onClick={handle_save_admin_user}
                style={{
                  padding: is_small_mobile ? '0.6rem 1rem' : is_mobile ? '0.7rem 1.25rem' : '0.75rem 1.5rem',
                  border: 'none',
                  borderRadius: '6px',
                  backgroundColor: '#6b7280',
                  color: 'white',
                  fontSize: is_small_mobile ? '0.8rem' : is_mobile ? '0.85rem' : '0.875rem',
                  fontWeight: '500',
                  cursor: 'pointer',
                  transition: 'all 0.2s ease',
                  minHeight: '44px'
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = '#4b5563';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = '#6b7280';
                }}
              >
                {editing_admin_user ? language_service.t('update_user') : language_service.t('add_user')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Sensor Form Modal */}
      {show_sensor_form && (
        <div className="modal-overlay" style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          backgroundColor: 'rgba(0, 0, 0, 0.5)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 1000,
          padding: '1rem'
        }}>
          <div className="modal-content sensor-form-modal" style={{
            backgroundColor: is_night_mode ? '#2d3748' : 'white',
            borderRadius: '12px',
            boxShadow: is_night_mode ? '0 20px 25px -5px rgba(0, 0, 0, 0.4), 0 10px 10px -5px rgba(0, 0, 0, 0.2)' : '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)',
            width: '100%',
            maxWidth: '600px',
            maxHeight: '90vh',
            overflow: 'hidden',
            display: 'flex',
            flexDirection: 'column',
            transition: 'all 0.3s ease'
          }}>
            <div className="modal-header" style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              padding: '1rem 1.5rem',
              borderBottom: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}`,
              backgroundColor: is_night_mode ? '#1a202c' : '#f9fafb'
            }}>
              <h3 style={{
                margin: '0',
                fontSize: '1.25rem',
                fontWeight: '600',
                color: is_night_mode ? '#ffffff' : '#1f2937',
                flex: '1',
                paddingRight: '2rem'
              }}>{editing_sensor ? language_service.t('edit_sensor') : language_service.t('add_new_sensor')}</h3>
              <button 
                className="modal-close" 
                onClick={close_sensor_form}
                style={{
                  background: 'none',
                  border: 'none',
                  fontSize: '1.5rem',
                  fontWeight: 'bold',
                  color: is_night_mode ? '#d1d5db' : '#6b7280',
                  cursor: 'pointer',
                  padding: '0.25rem',
                  borderRadius: '4px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  width: '2rem',
                  height: '2rem',
                  transition: 'all 0.2s ease',
                  flexShrink: 0
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = is_night_mode ? '#374151' : '#f3f4f6';
                  e.currentTarget.style.color = is_night_mode ? '#ffffff' : '#374151';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = 'transparent';
                  e.currentTarget.style.color = is_night_mode ? '#d1d5db' : '#6b7280';
                }}
              >×</button>
            </div>
            
            <div className="modal-body" style={{
              padding: '1.5rem',
              maxHeight: '70vh',
              overflowY: 'auto'
            }}>
              <div className="form-group" style={{
                marginBottom: '1rem'
              }}>
                <label style={{
                  display: 'block',
                  marginBottom: '0.5rem',
                  fontWeight: '500',
                  color: is_night_mode ? '#d1d5db' : '#374151',
                  fontSize: '0.875rem'
                }}>{language_service.t('sensor_name')} *</label>
                <input
                  type="text"
                  value={new_sensor.name}
                  onChange={(e) => set_new_sensor(prev => ({ ...prev, name: e.target.value }))}
                  placeholder="e.g., Parking Space 1"
                  style={{
                    width: '100%',
                    padding: '0.75rem',
                    border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                    borderRadius: '6px',
                    fontSize: '0.875rem',
                    backgroundColor: is_night_mode ? '#2d3748' : 'white',
                    color: is_night_mode ? '#ffffff' : '#1f2937'
                  }}
                />
              </div>
              
              <div className="form-group" style={{
                marginBottom: '1rem'
              }}>
                <label style={{
                  display: 'block',
                  marginBottom: '0.5rem',
                  fontWeight: '500',
                  color: is_night_mode ? '#d1d5db' : '#374151',
                  fontSize: '0.875rem'
                }}>WPSD ID *</label>
                <input
                  type="text"
                  value={new_sensor.wpsd_id}
                  onChange={(e) => set_new_sensor(prev => ({ ...prev, wpsd_id: e.target.value }))}
                  placeholder="e.g., 81CAE175"
                  style={{
                    width: '100%',
                    padding: '0.75rem',
                    border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                    borderRadius: '6px',
                    fontSize: '0.875rem',
                    backgroundColor: is_night_mode ? '#2d3748' : 'white',
                    color: is_night_mode ? '#ffffff' : '#1f2937'
                  }}
                />
              </div>
              
              <div className="form-group" style={{
                marginBottom: '1rem'
              }}>
                <label style={{
                  display: 'block',
                  marginBottom: '0.5rem',
                  fontWeight: '500',
                  color: is_night_mode ? '#d1d5db' : '#374151',
                  fontSize: '0.875rem'
                }}>WDC ID</label>
                <input
                  type="text"
                  value={new_sensor.wdc_id}
                  onChange={(e) => set_new_sensor(prev => ({ ...prev, wdc_id: e.target.value }))}
                  placeholder="e.g., 81CAE530"
                  style={{
                    width: '100%',
                    padding: '0.75rem',
                    border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                    borderRadius: '6px',
                    fontSize: '0.875rem',
                    backgroundColor: is_night_mode ? '#2d3748' : 'white',
                    color: is_night_mode ? '#ffffff' : '#1f2937'
                  }}
                />
              </div>
              
              <div className="form-group" style={{
                marginBottom: '1rem'
              }}>
                <label style={{
                  display: 'block',
                  marginBottom: '0.5rem',
                  fontWeight: '500',
                  color: is_night_mode ? '#d1d5db' : '#374151',
                  fontSize: '0.875rem'
                }}>{language_service.t('street_name')} *</label>
                <input
                  type="text"
                  value={new_sensor.street_name}
                  onChange={(e) => set_new_sensor(prev => ({ ...prev, street_name: e.target.value }))}
                  placeholder="e.g., Ulica Avnoja"
                  style={{
                    width: '100%',
                    padding: '0.75rem',
                    border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                    borderRadius: '6px',
                    fontSize: '0.875rem',
                    backgroundColor: is_night_mode ? '#2d3748' : 'white',
                    color: is_night_mode ? '#ffffff' : '#1f2937'
                  }}
                />
              </div>
              
              <div className="form-group" style={{
                marginBottom: '1rem'
              }}>
                <label style={{
                  display: 'block',
                  marginBottom: '0.5rem',
                  fontWeight: '500',
                  color: is_night_mode ? '#d1d5db' : '#374151',
                  fontSize: '0.875rem'
                }}>{language_service.t('parking_zone')}</label>
                <select
                  value={new_sensor.zone_id}
                  onChange={(e) => set_new_sensor(prev => ({ ...prev, zone_id: e.target.value }))}
                  style={{
                    width: '100%',
                    padding: '0.75rem',
                    border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                    borderRadius: '6px',
                    fontSize: '0.875rem',
                    backgroundColor: is_night_mode ? '#2d3748' : 'white',
                    color: is_night_mode ? '#ffffff' : '#1f2937'
                  }}
                >
                  <option value="">{language_service.t('select_zone_optional')}</option>
                  {zones && zones.length > 0 ? (
                    zones.map(zone => (
                      <option key={zone.id} value={zone.id} style={{
                        backgroundColor: is_night_mode ? '#2d3748' : 'white',
                        color: is_night_mode ? '#ffffff' : '#1f2937'
                      }}>
                        {zone.name}{zone.is_premium ? ' (Premium/TON)' : ''} - ${zone.hourly_rate}/hour, ${zone.daily_rate}/day
                      </option>
                    ))
                  ) : (
                    <option value="" disabled>Loading zones... ({zones ? zones.length : 'undefined'})</option>
                  )}
                </select>
                <div style={{
                  marginTop: '0.5rem',
                  fontSize: '0.75rem',
                  color: is_night_mode ? '#a0aec0' : '#6b7280'
                }}>
                  <div>{language_service.t('zone_pricing_info')}</div>
                  {zones && zones.length === 0 && (
                    <div style={{ color: '#f59e0b', marginTop: '0.25rem' }}>
                      No zones available. Create zones first in the Zone Management tab.
                    </div>
                  )}
                </div>
              </div>
              
              <div className="form-group" style={{
                marginBottom: '1rem'
              }}>
                <label style={{
                  display: 'block',
                  marginBottom: '0.5rem',
                  fontWeight: '500',
                  color: is_night_mode ? '#d1d5db' : '#374151',
                  fontSize: '0.875rem'
                }}>{language_service.t('coordinates')}</label>
                <div className="coordinates-inputs" style={{
                  display: 'grid',
                  gridTemplateColumns: '1fr 1fr',
                  gap: '0.75rem',
                  marginBottom: '0.75rem'
                }}>
                  <div className="coordinate-input">
                    <label style={{
                      display: 'block',
                      marginBottom: '0.25rem',
                      fontWeight: '500',
                      color: is_night_mode ? '#a0aec0' : '#6b7280',
                      fontSize: '0.75rem'
                    }}>{language_service.t('latitude')}</label>
                    <input
                      type="text"
                      value={new_sensor.coordinates?.lat || ''}
                      onChange={(e) => {
                        const lat_value = parseFloat(e.target.value);
                        if (!isNaN(lat_value)) {
                          set_new_sensor(prev => ({
                            ...prev,
                            coordinates: { ...prev.coordinates!, lat: lat_value }
                          }));
                        }
                      }}
                      placeholder="43.140000"
                      onPaste={(e) => {
                        e.preventDefault();
                        const pasted_text = e.clipboardData.getData('text');
                        // Try to parse both coordinates from pasted text
                        const coords = parse_coordinates_from_text(pasted_text);
                        if (coords) {
                          // If both coordinates found, set both
                          set_new_sensor(prev => ({
                            ...prev,
                            coordinates: coords
                          }));
                        } else {
                          // If only one number, try to parse as latitude
                          const lat_value = parseFloat(pasted_text);
                          if (!isNaN(lat_value)) {
                            set_new_sensor(prev => ({
                              ...prev,
                              coordinates: { ...prev.coordinates!, lat: lat_value }
                            }));
                          }
                        }
                      }}
                      style={{
                        width: '100%',
                        padding: '0.75rem',
                        border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                        borderRadius: '6px',
                        fontSize: '0.875rem',
                        backgroundColor: is_night_mode ? '#2d3748' : 'white',
                        color: is_night_mode ? '#ffffff' : '#1f2937'
                      }}
                    />
                  </div>
                  <div className="coordinate-input">
                    <label style={{
                      display: 'block',
                      marginBottom: '0.25rem',
                      fontWeight: '500',
                      color: is_night_mode ? '#a0aec0' : '#6b7280',
                      fontSize: '0.75rem'
                    }}>{language_service.t('longitude')}</label>
                    <input
                      type="text"
                      value={new_sensor.coordinates?.lng || ''}
                      onChange={(e) => {
                        const lng_value = parseFloat(e.target.value);
                        if (!isNaN(lng_value)) {
                          set_new_sensor(prev => ({
                            ...prev,
                            coordinates: { ...prev.coordinates!, lng: lng_value }
                          }));
                        }
                      }}
                      placeholder="20.517500"
                      onPaste={(e) => {
                        e.preventDefault();
                        const pasted_text = e.clipboardData.getData('text');
                        // Try to parse both coordinates from pasted text
                        const coords = parse_coordinates_from_text(pasted_text);
                        if (coords) {
                          // If both coordinates found, set both
                          set_new_sensor(prev => ({
                            ...prev,
                            coordinates: coords
                          }));
                        } else {
                          // If only one number, try to parse as longitude
                          const lng_value = parseFloat(pasted_text);
                          if (!isNaN(lng_value)) {
                            set_new_sensor(prev => ({
                              ...prev,
                              coordinates: { ...prev.coordinates!, lng: lng_value }
                            }));
                          }
                        }
                      }}
                      style={{
                        width: '100%',
                        padding: '0.75rem',
                        border: `1px solid ${is_night_mode ? '#4a5568' : '#d1d5db'}`,
                        borderRadius: '6px',
                        fontSize: '0.875rem',
                        backgroundColor: is_night_mode ? '#2d3748' : 'white',
                        color: is_night_mode ? '#ffffff' : '#1f2937'
                      }}
                    />
                  </div>
                </div>
                <div className="coordinate-help" style={{
                  marginBottom: '0.75rem'
                }}>
                  <button 
                    type="button" 
                    className="btn-secondary small"
                    onClick={async () => {
                      try {
                        const text = await navigator.clipboard.readText();
                        // Try to parse coordinates from clipboard text
                        const coords = parse_coordinates_from_text(text);
                        if (coords) {
                          set_new_sensor(prev => ({
                            ...prev,
                            coordinates: coords
                          }));
                        } else {
                          alert(language_service.t('coordinate_parsing_error'));
                        }
                      } catch (error) {
                        alert(language_service.t('clipboard_read_error'));
                      }
                    }}
                    style={{
                      padding: '0.5rem 1rem',
                      border: '1px solid #d1d5db',
                      borderRadius: '6px',
                      backgroundColor: '#f9fafb',
                      color: '#374151',
                      fontSize: '0.75rem',
                      fontWeight: '500',
                      cursor: 'pointer',
                      transition: 'all 0.2s ease'
                    }}
                    onMouseEnter={(e) => {
                      e.currentTarget.style.backgroundColor = '#f3f4f6';
                    }}
                    onMouseLeave={(e) => {
                      e.currentTarget.style.backgroundColor = '#f9fafb';
                    }}
                  >
                    {language_service.t('paste_coordinates_from_clipboard')}
                  </button>
                </div>
                <div className="coordinate-info">
                  <small style={{
                    fontSize: '0.75rem',
                    color: '#6b7280',
                    fontStyle: 'italic'
                  }}>{language_service.t('coordinate_info')}</small>
                </div>
              </div>
            </div>
            
            <div className="modal-footer" style={{
              display: 'flex',
              justifyContent: 'flex-end',
              gap: '0.75rem',
              padding: '1rem 1.5rem',
              borderTop: `1px solid ${is_night_mode ? '#4a5568' : '#e5e7eb'}`,
              backgroundColor: is_night_mode ? '#1a202c' : '#f9fafb'
            }}>
              <button 
                className="btn-secondary" 
                onClick={close_sensor_form}
                style={{
                  padding: '0.75rem 1.5rem',
                  border: '1px solid #d1d5db',
                  borderRadius: '6px',
                  backgroundColor: 'white',
                  color: '#374151',
                  fontSize: '0.875rem',
                  fontWeight: '500',
                  cursor: 'pointer',
                  transition: 'all 0.2s ease'
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = '#f3f4f6';
                  e.currentTarget.style.borderColor = '#9ca3af';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = 'white';
                  e.currentTarget.style.borderColor = '#d1d5db';
                }}
              >
                {language_service.t('cancel')}
              </button>
              <button 
                className="btn-primary" 
                onClick={save_sensor}
                style={{
                  padding: '0.75rem 1.5rem',
                  border: 'none',
                  borderRadius: '6px',
                  backgroundColor: '#6b7280',
                  color: 'white',
                  fontSize: '0.875rem',
                  fontWeight: '500',
                  cursor: 'pointer',
                  transition: 'all 0.2s ease'
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = '#4b5563';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = '#6b7280';
                }}
              >
                {editing_sensor ? language_service.t('update') : language_service.t('save')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
