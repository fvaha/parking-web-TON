export interface Sensor {
  id: string;
  wpsd_id: string;
  wdc_id: string;
  name: string;
  status: 'live' | 'inactive';
  coordinates: {
    lat: number;
    lng: number;
  };
  street_name: string;
  zone_id?: string;
  zone?: ParkingZone;
  created_at: string;
  updated_at: string;
}

export interface ParkingZone {
  id: string;
  name: string;
  description: string;
  color: string;
  hourly_rate: number;
  daily_rate: number;
  is_active: boolean;
  is_premium: boolean;
  max_duration_hours?: number;
  space_count?: number;
  created_at: string;
  updated_at: string;
}

export interface ParkingSpace {
  id: string;
  sensor_id: string;
  status: 'vacant' | 'occupied' | 'reserved';
  license_plate?: string;
  reservation_time?: string;
  reservation_end_time?: string;
  occupied_since?: string;
  coordinates: {
    lat: number;
    lng: number;
  };
  zone?: ParkingZone;
}

export interface UserSession {
  license_plate: string;
  device_id: string;
  created_at: string;
  last_activity: string;
}

export interface ActiveSession {
  id: string;
  license_plate: string;
  parking_space_id: string;
  start_time: string;
  status: 'reserved' | 'occupied';
  reservation_time?: string;
  occupied_since?: string;
}

export interface ParkingUsage {
  id: string;
  license_plate: string;
  parking_space_id: string;
  start_time: string;
  end_time?: string;
  duration_minutes?: number;
  total_cost?: number;
}

export interface Reservation {
  id: string;
  license_plate: string;
  parking_space_id: string;
  start_time: string;
  end_time: string;
  status: 'active' | 'completed' | 'cancelled';
  created_at: string;
  duration_hours?: number;
}

export interface AdminUser {
  id: string;
  username: string;
  email: string;
  role: 'superadmin' | 'admin';
  is_active: boolean;
  last_login?: string;
  created_at: string;
  updated_at: string;
}

export interface Statistics {
  total_spaces: number;
  occupied_spaces: number;
  vacant_spaces: number;
  reserved_spaces: number;
  utilization_rate: number;
  average_duration: number;
  total_revenue: number;
  daily_usage: Array<{
    date: string;
    count: number;
    revenue: number;
  }>;
  hourly_usage: Array<{
    hour: number;
    count: number;
  }>;
}

export interface TonPayment {
  id: string;
  reservation_id?: string;
  parking_space_id: string;
  license_plate: string;
  tx_hash: string;
  amount_nano: string;
  amount_ton: number;
  status: 'pending' | 'verified' | 'failed';
  verified_at?: string;
  created_at: string;
}

export interface TelegramUser {
  id: string;
  telegram_user_id: number;
  username?: string;
  license_plate: string;
  chat_id: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}
