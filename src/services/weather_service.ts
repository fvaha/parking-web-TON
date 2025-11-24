export interface WeatherData {
  temperature: number;
  humidity: number;
  air_quality: number;
  weather_condition: string;
  weather_code: number;
  timestamp: number;
  is_day: boolean;
}

export interface WeatherResponse {
  current: {
    temp_c: number;
    humidity: number;
    condition: {
      text: string;
      code: number;
    };
    is_day: number;
  };
  location: {
    name: string;
    country: string;
  };
}

export class WeatherService {
  private static instance: WeatherService;
  private api_key: string;
  private base_url: string = 'https://api.weatherapi.com/v1';
  private cache_duration: number = 300000; // 5 minutes
  private cached_data: WeatherData | null = null;
  private last_fetch: number = 0;

  private constructor() {
    // Get API key from environment variable
    const envKey = import.meta.env.VITE_WEATHER_API_KEY;
    if (!envKey) {
      console.warn('VITE_WEATHER_API_KEY is not set in environment variables. Weather data may not be available.');
      this.api_key = '';
    } else {
      this.api_key = envKey;
    }
  }

  static getInstance(): WeatherService {
    if (!WeatherService.instance) {
      WeatherService.instance = new WeatherService();
    }
    return WeatherService.instance;
  }

  async get_weather_data(lat: number, lng: number): Promise<WeatherData> {
    const now = Date.now();
    
    // Return cached data if still valid
    if (this.cached_data && (now - this.last_fetch) < this.cache_duration) {
      return this.cached_data;
    }

    if (!this.api_key) {
      console.warn('Weather API key is not configured. Returning fallback data.');
      return {
        temperature: 20,
        humidity: 60,
        air_quality: 75,
        weather_condition: 'Partly cloudy',
        weather_code: 1003,
        timestamp: now,
        is_day: true
      };
    }

    try {
      console.log('Fetching weather data from weatherapi.com...');
      // Use real weather API
      const response = await fetch(
        `${this.base_url}/current.json?key=${this.api_key}&q=${lat},${lng}&aqi=yes`
      );
      
      if (!response.ok) {
        const errorText = await response.text();
        console.error('Weather API error response:', response.status, errorText);
        throw new Error(`Weather API request failed: ${response.status} ${errorText}`);
      }
      
      const data: WeatherResponse = await response.json();
      console.log('Weather data received:', data);
      
      const weather_data: WeatherData = {
        temperature: data.current.temp_c,
        humidity: data.current.humidity,
        air_quality: Math.floor(Math.random() * 100) + 50, // Mock AQI for now
        weather_condition: data.current.condition.text,
        weather_code: data.current.condition.code,
        timestamp: now,
        is_day: data.current.is_day === 1
      };
      
      this.cached_data = weather_data;
      this.last_fetch = now;
      
      return weather_data;
    } catch (error) {
      console.error('Error fetching weather data:', error);
      
      // Return fallback data
      return {
        temperature: 20,
        humidity: 60,
        air_quality: 75,
        weather_condition: 'Partly cloudy',
        weather_code: 1003,
        timestamp: now,
        is_day: true
      };
    }
  }

  get_weather_icon_type(weather_code: number, is_day: boolean = true): string {
    // Map weather codes to icon types based on Makin-Things weather-icons
    if (weather_code >= 1000 && weather_code <= 1003) {
      return is_day ? 'clear-day' : 'clear-night';
    } else if (weather_code >= 1006 && weather_code <= 1009) {
      if (weather_code === 1006) return is_day ? 'cloudy-1-day' : 'cloudy-1-night';
      if (weather_code === 1007) return is_day ? 'cloudy-2-day' : 'cloudy-2-night';
      if (weather_code === 1008) return is_day ? 'cloudy-3-day' : 'cloudy-3-night';
      return 'cloudy';
    } else if (weather_code >= 1030 && weather_code <= 1032) {
      return is_day ? 'fog-day' : 'fog-night';
    } else if (weather_code >= 1063 && weather_code <= 1087) {
      if (weather_code <= 1066) return is_day ? 'rainy-1-day' : 'rainy-1-night';
      if (weather_code <= 1072) return is_day ? 'rainy-2-day' : 'rainy-2-night';
      return is_day ? 'rainy-3-day' : 'rainy-3-night';
    } else if (weather_code >= 1114 && weather_code <= 1117) {
      if (weather_code <= 1115) return is_day ? 'snowy-1-day' : 'snowy-1-night';
      if (weather_code <= 1116) return is_day ? 'snowy-2-day' : 'snowy-2-night';
      return is_day ? 'snowy-3-day' : 'snowy-3-night';
    } else if (weather_code >= 1135 && weather_code <= 1147) {
      return 'fog';
    } else if (weather_code >= 1150 && weather_code <= 1201) {
      if (weather_code <= 1156) return is_day ? 'rainy-1-day' : 'rainy-1-night';
      if (weather_code <= 1171) return is_day ? 'rainy-2-day' : 'rainy-2-night';
      return is_day ? 'rainy-3-day' : 'rainy-3-night';
    } else if (weather_code >= 1204 && weather_code <= 1237) {
      if (weather_code <= 1210) return is_day ? 'snowy-1-day' : 'snowy-1-night';
      if (weather_code <= 1225) return is_day ? 'snowy-2-day' : 'snowy-2-night';
      return is_day ? 'snowy-3-day' : 'snowy-3-night';
    } else if (weather_code >= 1240 && weather_code <= 1264) {
      if (weather_code <= 1246) return is_day ? 'rainy-1-day' : 'rainy-1-night';
      if (weather_code <= 1258) return is_day ? 'rainy-2-day' : 'rainy-2-night';
      return is_day ? 'rainy-3-day' : 'rainy-3-night';
    } else if (weather_code >= 1273 && weather_code <= 1282) {
      if (weather_code <= 1276) return 'isolated-thunderstorms';
      return 'thunderstorms';
    } else if (weather_code >= 2000 && weather_code <= 2021) {
      return 'thunderstorms';
    } else if (weather_code >= 2100 && weather_code <= 2124) {
      return 'isolated-thunderstorms';
    } else if (weather_code >= 2200 && weather_code <= 2210) {
      return 'thunderstorms';
    } else if (weather_code >= 2300 && weather_code <= 2310) {
      return 'thunderstorms';
    } else if (weather_code >= 2400 && weather_code <= 2463) {
      return 'thunderstorms';
    } else if (weather_code >= 2500 && weather_code <= 2599) {
      return 'thunderstorms';
    } else if (weather_code >= 2600 && weather_code <= 2699) {
      return 'thunderstorms';
    } else if (weather_code >= 2700 && weather_code <= 2749) {
      return 'thunderstorms';
    } else if (weather_code >= 2750 && weather_code <= 2799) {
      return 'thunderstorms';
    } else if (weather_code >= 2800 && weather_code <= 2849) {
      return 'thunderstorms';
    } else if (weather_code >= 2850 && weather_code <= 2899) {
      return 'thunderstorms';
    } else if (weather_code >= 2900 && weather_code <= 2999) {
      return 'thunderstorms';
    } else if (weather_code >= 3000 && weather_code <= 3999) {
      return 'thunderstorms';
    } else if (weather_code >= 4000 && weather_code <= 4999) {
      return 'thunderstorms';
    } else if (weather_code >= 5000 && weather_code <= 5999) {
      return 'thunderstorms';
    } else if (weather_code >= 6000 && weather_code <= 6999) {
      return 'thunderstorms';
    } else if (weather_code >= 7000 && weather_code <= 7999) {
      return 'thunderstorms';
    } else if (weather_code >= 8000 && weather_code <= 8999) {
      return 'thunderstorms';
    } else if (weather_code >= 9000 && weather_code <= 9999) {
      return 'thunderstorms';
    }
    
    // Default fallback
    return is_day ? 'clear-day' : 'clear-night';
  }

  get_weather_background(weather_code: number): string {
    // Weather code ranges for different conditions
    if (weather_code >= 1000 && weather_code <= 1003) {
      return 'linear-gradient(135deg, #87CEEB, #98D8E8)'; // Sunny/Clear
    } else if (weather_code >= 1006 && weather_code <= 1009) {
      return 'linear-gradient(135deg, #B0C4DE, #C0C0C0)'; // Cloudy
    } else if (weather_code >= 1063 && weather_code <= 1087) {
      return 'linear-gradient(135deg, #4682B4, #5F9EA0)'; // Rain
    } else if (weather_code >= 1114 && weather_code <= 1117) {
      return 'linear-gradient(135deg, #E6E6FA, #F0F8FF)'; // Snow
    } else if (weather_code >= 1135 && weather_code <= 1147) {
      return 'linear-gradient(135deg, #D3D3D3, #A9A9A9)'; // Fog/Mist
    } else {
      return 'linear-gradient(135deg, #87CEEB, #98D8E8)'; // Default sunny
    }
  }

  get_air_quality_color(aqi: number): string {
    if (aqi <= 50) return '#00E400'; // Good - Green
    if (aqi <= 100) return '#FFFF00'; // Moderate - Yellow
    if (aqi <= 150) return '#FF7E00'; // Unhealthy for Sensitive - Orange
    if (aqi <= 200) return '#FF0000'; // Unhealthy - Red
    if (aqi <= 300) return '#4b5563'; // Very Unhealthy - Dark Gray
    return '#7E0023'; // Hazardous - Maroon
  }

  get_air_quality_label(aqi: number): string {
    if (aqi <= 50) return 'Good';
    if (aqi <= 100) return 'Moderate';
    if (aqi <= 150) return 'Unhealthy for Sensitive';
    if (aqi <= 200) return 'Unhealthy';
    if (aqi <= 300) return 'Very Unhealthy';
    return 'Hazardous';
  }
}
