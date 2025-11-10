// Google Maps API Configuration
// SECURITY: Never hardcode API keys in this file. Always use environment variables.
// The API key is loaded from VITE_GOOGLE_MAPS_API_KEY environment variable.
export const MAPS_CONFIG = {
  // Map center coordinates (from the old project)
  DEFAULT_CENTER: {
    lat: 43.1422626446047,
    lng: 20.5180587785345
  },
  
  // Default zoom level - increased for better detail
  DEFAULT_ZOOM: 23,
  
  // User location coordinates (hardcoded from the old project)
  USER_LOCATION: {
    lat: 43.12403644439809,
    lng: 20.500166875297595
  }
};

// Environment-based configuration
export const get_maps_api_key = (): string => {
  const apiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY;
  if (!apiKey) {
    console.error('VITE_GOOGLE_MAPS_API_KEY is not set in environment variables');
    throw new Error('Google Maps API key is not configured. Please set VITE_GOOGLE_MAPS_API_KEY in your .env file.');
  }
  return apiKey;
};

// Google Maps URLs
export const GOOGLE_MAPS_URLS = {
  DIRECTIONS: 'https://www.google.com/maps/dir/',
  SEARCH: 'https://www.google.com/maps/search/',
  STREET_VIEW: 'https://www.google.com/maps/@'
};

// Map style types
export type MapStyle = 'light' | 'dark' | 'streets' | 'hybrid' | 'satellite';

// Tile layer configurations
export const TILE_LAYERS: Record<MapStyle, { url: string; attribution: string; name: string }> = {
  light: {
    url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution: '© OpenStreetMap contributors',
    name: 'Light'
  },
  dark: {
    url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
    attribution: '© OpenStreetMap © CARTO',
    name: 'Dark'
  },
  streets: {
    url: 'https://mt1.google.com/vt/lyrs=m&x={x}&y={y}&z={z}',
    attribution: '© Google Maps',
    name: 'Streets'
  },
  hybrid: {
    url: 'https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}',
    attribution: '© Google Maps',
    name: 'Hybrid'
  },
  satellite: {
    url: 'https://mt1.google.com/vt/lyrs=s&x={x}&y={y}&z={z}',
    attribution: '© Google Maps',
    name: 'Satellite'
  }
};
