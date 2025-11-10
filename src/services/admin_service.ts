import { build_api_url } from '../config/api_config';

export interface AdminUser {
  id: number;
  username: string;
  email: string;
  role: 'admin' | 'superadmin';
  is_active: boolean;
  last_login?: string;
  created_at: string;
}

export interface AdminLog {
  id: number;
  admin_user_id: number;
  action: string;
  table_name: string;
  record_id: number;
  old_values?: string;
  new_values?: string;
  ip_address?: string;
  user_agent?: string;
  created_at: string;
  admin_username: string;
  admin_role: string;
}

export interface LoginCredentials {
  username: string;
  password: string;
}

export interface AdminUserFormData {
  username: string;
  password: string;
  email: string;
  role?: 'admin' | 'superadmin';
}

class AdminService {
  private static instance: AdminService;
  private currentUser: AdminUser | null = null;

  private constructor() {}

  public static getInstance(): AdminService {
    if (!AdminService.instance) {
      AdminService.instance = new AdminService();
    }
    return AdminService.instance;
  }

  // Authentication methods
  async login(credentials: LoginCredentials): Promise<{ success: boolean; user?: AdminUser; error?: string }> {
    try {
      const apiUrl = build_api_url('/api/admin-auth.php');
      console.log('Attempting login to:', apiUrl);
      
      const response = await fetch(apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'login',
          ...credentials
        }),
        credentials: 'include' // Important for session cookies
      });

      console.log('Login response status:', response.status);
      console.log('Login response headers:', response.headers);

      const data = await response.json();
      console.log('Login response data:', data);
      
      if (data.success && data.user) {
        this.currentUser = data.user;
        return { success: true, user: data.user };
      } else {
        return { success: false, error: data.error || 'Login failed' };
      }
    } catch (error) {
      console.error('Login error:', error);
      return { success: false, error: 'Network error during login' };
    }
  }

  async logout(): Promise<{ success: boolean; error?: string }> {
    try {
      const response = await fetch(build_api_url('/api/admin-auth.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'logout'
        }),
        credentials: 'include'
      });

      const data = await response.json();
      
      if (data.success) {
        this.currentUser = null;
        return { success: true };
      } else {
        return { success: false, error: data.error || 'Logout failed' };
      }
    } catch (error) {
      console.error('Logout error:', error);
      return { success: false, error: 'Network error during logout' };
    }
  }

  async checkSession(): Promise<{ success: boolean; authenticated: boolean; user?: AdminUser; error?: string }> {
    try {
      const apiUrl = build_api_url('/api/admin-auth.php');
      console.log('Checking session at:', apiUrl);
      
      const response = await fetch(apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'check_session'
        }),
        credentials: 'include'
      });

      console.log('Session check response status:', response.status);
      const data = await response.json();
      console.log('Session check response data:', data);
      
      if (data.success) {
        if (data.authenticated && data.user) {
          this.currentUser = data.user;
          return { success: true, authenticated: true, user: data.user };
        } else {
          this.currentUser = null;
          return { success: true, authenticated: false };
        }
      } else {
        return { success: false, authenticated: false, error: data.error || 'Session check failed' };
      }
    } catch (error) {
      console.error('Session check error:', error);
      return { success: false, authenticated: false, error: 'Network error during session check' };
    }
  }

  // Admin user management methods
  async getAdminUsers(): Promise<{ success: boolean; data?: AdminUser[]; error?: string }> {
    try {
      const response = await fetch(build_api_url('/api/admin-users.php'), {
        method: 'GET',
        credentials: 'include'
      });

      const data = await response.json();
      
      if (data.success) {
        return { success: true, data: data.data };
      } else {
        return { success: false, error: data.error || 'Failed to fetch admin users' };
      }
    } catch (error) {
      console.error('Get admin users error:', error);
      return { success: false, error: 'Network error while fetching admin users' };
    }
  }

  async addAdminUser(userData: AdminUserFormData): Promise<{ success: boolean; user_id?: number; error?: string }> {
    try {
      const response = await fetch(build_api_url('/api/admin-users.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(userData),
        credentials: 'include'
      });

      const data = await response.json();
      
      if (data.success) {
        return { success: true, user_id: data.user_id };
      } else {
        return { success: false, error: data.error || 'Failed to add admin user' };
      }
    } catch (error) {
      console.error('Add admin user error:', error);
      return { success: false, error: 'Network error while adding admin user' };
    }
  }

  async updateAdminUser(userId: number, userData: Partial<AdminUserFormData>): Promise<{ success: boolean; message?: string; error?: string }> {
    try {
      const response = await fetch(build_api_url(`/api/admin-users.php/${userId}`), {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(userData),
        credentials: 'include'
      });

      const data = await response.json();
      
      if (data.success) {
        return { success: true, message: data.message };
      } else {
        return { success: false, error: data.error || 'Failed to update admin user' };
      }
    } catch (error) {
      console.error('Update admin user error:', error);
      return { success: false, error: 'Network error while updating admin user' };
    }
  }

  async deleteAdminUser(userId: number): Promise<{ success: boolean; message?: string; error?: string }> {
    try {
      const response = await fetch(build_api_url(`/api/admin-users.php/${userId}`), {
        method: 'DELETE',
        credentials: 'include'
      });

      const data = await response.json();
      
      if (data.success) {
        return { success: true, message: data.message };
      } else {
        return { success: false, error: data.error || 'Failed to delete admin user' };
      }
    } catch (error) {
      console.error('Delete admin user error:', error);
      return { success: false, error: 'Network error while deleting admin user' };
    }
  }

  // Admin logs methods
  async getAdminLogs(limit: number = 100, offset: number = 0, filters: any = {}): Promise<{ success: boolean; data?: AdminLog[]; pagination?: any; error?: string }> {
    try {
      const queryParams = new URLSearchParams({
        limit: limit.toString(),
        offset: offset.toString(),
        ...filters
      });

      const response = await fetch(build_api_url(`/api/admin-logs.php?${queryParams}`), {
        method: 'GET',
        credentials: 'include'
      });

      const data = await response.json();
      
      if (data.success) {
        return { 
          success: true, 
          data: data.data, 
          pagination: data.pagination 
        };
      } else {
        return { success: false, error: data.error || 'Failed to fetch admin logs' };
      }
    } catch (error) {
      console.error('Get admin logs error:', error);
      return { success: false, error: 'Network error while fetching admin logs' };
    }
  }

  // Zone management methods
  async getParkingZones(): Promise<{ success: boolean; data?: any[]; error?: string }> {
    try {
      const response = await fetch(build_api_url('/api/zones.php'), {
        method: 'GET',
        credentials: 'include'
      });

      const data = await response.json();
      
      if (data.success) {
        return { success: true, data: data.data };
      } else {
        return { success: false, error: data.error || 'Failed to fetch parking zones' };
      }
    } catch (error) {
      console.error('Get parking zones error:', error);
      return { success: false, error: 'Network error while fetching parking zones' };
    }
  }

  async addParkingZone(zoneData: any): Promise<{ success: boolean; zone_id?: number; error?: string }> {
    try {
      const response = await fetch(build_api_url('/api/zones.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(zoneData),
        credentials: 'include'
      });

      const data = await response.json();
      
      if (data.success) {
        return { success: true, zone_id: data.data.id };
      } else {
        return { success: false, error: data.error || 'Failed to add parking zone' };
      }
    } catch (error) {
      console.error('Add parking zone error:', error);
      return { success: false, error: 'Network error while adding parking zone' };
    }
  }

  async updateParkingZone(zoneId: number, zoneData: any): Promise<{ success: boolean; message?: string; error?: string }> {
    try {
      const response = await fetch(build_api_url(`/api/zones.php?id=${zoneId}`), {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(zoneData),
        credentials: 'include'
      });

      const data = await response.json();
      
      if (data.success) {
        return { success: true, message: data.message };
      } else {
        return { success: false, error: data.error || 'Failed to update parking zone' };
      }
    } catch (error) {
      console.error('Update parking zone error:', error);
      return { success: false, error: 'Network error while updating parking zone' };
    }
  }

  async deleteParkingZone(zoneId: number): Promise<{ success: boolean; message?: string; error?: string }> {
    try {
      const response = await fetch(build_api_url(`/api/zones.php?id=${zoneId}`), {
        method: 'DELETE',
        credentials: 'include'
      });

      const data = await response.json();
      
      if (data.success) {
        return { success: true, message: data.message };
      } else {
        return { success: false, error: data.error || 'Failed to delete parking zone' };
      }
    } catch (error) {
      console.error('Delete parking zone error:', error);
      return { success: false, error: 'Network error while deleting parking zone' };
    }
  }

  // Utility methods
  getCurrentUser(): AdminUser | null {
    return this.currentUser;
  }

  isAuthenticated(): boolean {
    return this.currentUser !== null;
  }

  isSuperAdmin(): boolean {
    return this.currentUser?.role === 'superadmin';
  }

  hasRole(role: 'admin' | 'superadmin'): boolean {
    if (!this.currentUser) return false;
    if (role === 'superadmin') return this.currentUser.role === 'superadmin';
    return true; // Admin can access admin-level features
  }
}

export default AdminService;
