import type { UserSession, ActiveSession } from '../types';

const DEVICE_ID_KEY = 'parking_device_id';
const LICENSE_PLATE_KEY = 'parking_license_plate';
const USER_SESSION_KEY = 'parking_user_session';
const ACTIVE_SESSION_KEY = 'parking_active_session';

export class StorageService {
  private static instance: StorageService;
  private device_id: string;

  private constructor() {
    this.device_id = this.get_or_create_device_id();
  }

  static getInstance(): StorageService {
    if (!StorageService.instance) {
      StorageService.instance = new StorageService();
    }
    return StorageService.instance;
  }

  private get_or_create_device_id(): string {
    let device_id = localStorage.getItem(DEVICE_ID_KEY);
    if (!device_id) {
      device_id = 'device_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
      localStorage.setItem(DEVICE_ID_KEY, device_id);
    }
    return device_id;
  }

  get_device_id(): string {
    return this.device_id;
  }

  save_license_plate(license_plate: string): void {
    localStorage.setItem(LICENSE_PLATE_KEY, license_plate);
    
    const session: UserSession = {
      license_plate,
      device_id: this.device_id,
      created_at: new Date().toISOString(),
      last_activity: new Date().toISOString()
    };
    
    localStorage.setItem(USER_SESSION_KEY, JSON.stringify(session));
  }

  get_license_plate(): string | null {
    return localStorage.getItem(LICENSE_PLATE_KEY);
  }

  get_user_session(): UserSession | null {
    const session = localStorage.getItem(USER_SESSION_KEY);
    return session ? JSON.parse(session) : null;
  }

  update_user_activity(): void {
    const session = this.get_user_session();
    if (session) {
      session.last_activity = new Date().toISOString();
      localStorage.setItem(USER_SESSION_KEY, JSON.stringify(session));
    }
  }

  clear_user_session(): void {
    localStorage.removeItem(LICENSE_PLATE_KEY);
    localStorage.removeItem(USER_SESSION_KEY);
    localStorage.removeItem(ACTIVE_SESSION_KEY);
  }

  // Session management methods
  get_active_session(): ActiveSession | null {
    const session = localStorage.getItem(ACTIVE_SESSION_KEY);
    return session ? JSON.parse(session) : null;
  }

  set_active_session(session: ActiveSession): void {
    localStorage.setItem(ACTIVE_SESSION_KEY, JSON.stringify(session));
  }

  clear_active_session(): void {
    localStorage.removeItem(ACTIVE_SESSION_KEY);
  }

  has_active_session(): boolean {
    return this.get_active_session() !== null;
  }

  // Initialize mock data if not exists
  initialize_mock_data(): void {
    // Mock data initialization removed - will use real database instead
    console.log('Mock data initialization removed - using real database');
  }
}
