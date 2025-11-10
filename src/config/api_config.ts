// API Configuration
export const API_CONFIG = {
  // Live web server
  LIVE_SERVER: {
    BASE_URL: 'https://parkiraj.info', // App is at parkiraj.info
    ENDPOINTS: {
      DATA: '/api/data.php',
      SENSORS: '/api/sensors.php',
      PARKING_SPACES: '/api/parking-spaces.php',
      ADMIN_AUTH: '/api/admin-auth.php',
      ADMIN_USERS: '/api/admin-users.php',
      ADMIN_LOGS: '/api/admin-logs.php',
      ADMIN_MANAGEMENT: '/api/admin-management.php',
      ZONES: '/api/zones.php',
      STATISTICS: '/api/statistics.php'
    }
  },
  
  // Production - parkiraj.info
  PRODUCTION: {
    BASE_URL: 'https://parkiraj.info',
    ENDPOINTS: {
      DATA: '/api/data.php',
      SENSORS: '/api/sensors.php',
      PARKING_SPACES: '/api/parking-spaces.php',
      ADMIN_AUTH: '/api/admin-auth.php',
      ADMIN_USERS: '/api/admin-users.php',
      ADMIN_LOGS: '/api/admin-logs.php',
      ADMIN_MANAGEMENT: '/api/admin-management.php',
      ZONES: '/api/zones.php',
      STATISTICS: '/api/statistics.php'
    }
  }
};

// Current environment - change this to 'LIVE_SERVER' to use your live web server
export const CURRENT_ENV = 'LIVE_SERVER';

// Helper function to get current API config
export const get_api_config = () => {
  return API_CONFIG[CURRENT_ENV as keyof typeof API_CONFIG];
};

// Helper function to build API URLs
export const build_api_url = (endpoint: string) => {
  const config = get_api_config();
  return `${config.BASE_URL}${endpoint}`;
};

// Get API key from environment
export const get_api_key = (): string | null => {
  return import.meta.env.VITE_API_KEY || null;
};

// Helper function to create fetch options with API key
export const create_api_options = (method: string = 'GET', body?: any): RequestInit => {
  const options: RequestInit = {
    method,
    headers: {
      'Content-Type': 'application/json',
    },
  };

  const api_key = get_api_key();
  if (api_key) {
    options.headers = {
      ...options.headers,
      'X-API-Key': api_key,
    };
  }

  if (body) {
    options.body = JSON.stringify(body);
  }

  return options;
};