export class AuthService {
  private static instance: AuthService;
  private is_authenticated: boolean = false;

  private constructor() {}

  static getInstance(): AuthService {
    if (!AuthService.instance) {
      AuthService.instance = new AuthService();
    }
    return AuthService.instance;
  }

  login(username: string, password: string): boolean {
    const admin_username = import.meta.env.VITE_ADMIN_USERNAME;
    const admin_password = import.meta.env.VITE_ADMIN_PASSWORD;

    if (username === admin_username && password === admin_password) {
      this.is_authenticated = true;
      localStorage.setItem('parking_admin_authenticated', 'true');
      return true;
    }
    return false;
  }

  logout(): void {
    this.is_authenticated = false;
    localStorage.removeItem('parking_admin_authenticated');
  }

  is_logged_in(): boolean {
    if (this.is_authenticated) return true;
    
    const stored = localStorage.getItem('parking_admin_authenticated');
    if (stored === 'true') {
      this.is_authenticated = true;
      return true;
    }
    return false;
  }

  check_auth(): boolean {
    return this.is_logged_in();
  }
}
