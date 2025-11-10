import React from 'react';
import './WeatherIconDemo.css';

const WeatherIconDemo: React.FC = () => {
  const weather_types = [
    // Clear conditions
    { type: 'clear-day', label: 'Clear Day' },
    { type: 'clear-night', label: 'Clear Night' },
    
    // Cloudy conditions
    { type: 'cloudy-1-day', label: 'Cloudy 1 Day' },
    { type: 'cloudy-1-night', label: 'Cloudy 1 Night' },
    { type: 'cloudy-2-day', label: 'Cloudy 2 Day' },
    { type: 'cloudy-2-night', label: 'Cloudy 2 Night' },
    { type: 'cloudy-3-day', label: 'Cloudy 3 Day' },
    { type: 'cloudy-3-night', label: 'Cloudy 3 Night' },
    { type: 'cloudy', label: 'Cloudy' },
    
    // Rain conditions
    { type: 'rainy-1-day', label: 'Rainy 1 Day' },
    { type: 'rainy-1-night', label: 'Rainy 1 Night' },
    { type: 'rainy-1', label: 'Rainy 1' },
    { type: 'rainy-2-day', label: 'Rainy 2 Day' },
    { type: 'rainy-2-night', label: 'Rainy 2 Night' },
    { type: 'rainy-2', label: 'Rainy 2' },
    { type: 'rainy-3-day', label: 'Rainy 3 Day' },
    { type: 'rainy-3-night', label: 'Rainy 3 Night' },
    { type: 'rainy-3', label: 'Rainy 3' },
    
    // Snow conditions
    { type: 'snowy-1-day', label: 'Snowy 1 Day' },
    { type: 'snowy-1-night', label: 'Snowy 1 Night' },
    { type: 'snowy-1', label: 'Snowy 1' },
    { type: 'snowy-2-day', label: 'Snowy 2 Day' },
    { type: 'snowy-2-night', label: 'Snowy 2 Night' },
    { type: 'snowy-2', label: 'Snowy 2' },
    { type: 'snowy-3-day', label: 'Snowy 3 Day' },
    { type: 'snowy-3-night', label: 'Snowy 3 Night' },
    { type: 'snowy-3', label: 'Snowy 3' },
    
    // Mixed precipitation
    { type: 'rain-and-sleet-mix', label: 'Rain & Sleet Mix' },
    { type: 'rain-and-snow-mix', label: 'Rain & Snow Mix' },
    { type: 'snow-and-sleet-mix', label: 'Snow & Sleet Mix' },
    
    // Thunderstorm conditions
    { type: 'isolated-thunderstorms-day', label: 'Isolated Thunderstorms Day' },
    { type: 'isolated-thunderstorms-night', label: 'Isolated Thunderstorms Night' },
    { type: 'isolated-thunderstorms', label: 'Isolated Thunderstorms' },
    { type: 'scattered-thunderstorms-day', label: 'Scattered Thunderstorms Day' },
    { type: 'scattered-thunderstorms-night', label: 'Scattered Thunderstorms Night' },
    { type: 'scattered-thunderstorms', label: 'Scattered Thunderstorms' },
    { type: 'thunderstorms', label: 'Thunderstorms' },
    { type: 'severe-thunderstorm', label: 'Severe Thunderstorm' },
    
    // Fog and haze conditions
    { type: 'fog-day', label: 'Fog Day' },
    { type: 'fog-night', label: 'Fog Night' },
    { type: 'fog', label: 'Fog' },
    { type: 'haze-day', label: 'Haze Day' },
    { type: 'haze-night', label: 'Haze Night' },
    { type: 'haze', label: 'Haze' },
    
    // Special conditions
    { type: 'dust', label: 'Dust' },
    { type: 'frost-day', label: 'Frost Day' },
    { type: 'frost-night', label: 'Frost Night' },
    { type: 'frost', label: 'Frost' },
    { type: 'hail', label: 'Hail' },
    { type: 'wind', label: 'Wind' },
    
    // Extreme weather
    { type: 'hurricane', label: 'Hurricane' },
    { type: 'tropical-storm', label: 'Tropical Storm' },
    { type: 'tornado', label: 'Tornado' }
  ];

  return (
    <div className="weather-demo">
      <h2>Complete Weather Icons Collection</h2>
      <p>Based on the <a href="https://github.com/Makin-Things/weather-icons" target="_blank" rel="noopener noreferrer">Makin-Things weather-icons</a> repository</p>
      <p>All icons automatically adapt to real weather data from your location</p>
      
      <div className="weather-icons-grid">
        {weather_types.map(({ type, label }) => (
          <div key={type} className="weather-icon-item">
            <div className="demo-weather-icon-placeholder">
              <span className="weather-type-text">{type}</span>
            </div>
            <span className="weather-label">{label}</span>
          </div>
        ))}
      </div>
      
      <div className="demo-info">
        <h3>Features:</h3>
        <ul>
          <li>✅ 50+ weather icon types covering all conditions</li>
          <li>✅ Day/night variants for appropriate weather types</li>
          <li>✅ Smooth SVG animations with 80% alpha opacity</li>
          <li>✅ Real-time weather data integration</li>
          <li>✅ Responsive design for all screen sizes</li>
          <li>✅ Hover effects and interactions</li>
          <li>✅ Dark mode support</li>
          <li>✅ Based on professional weather icon standards</li>
        </ul>
        
        <h3>Weather Data Integration:</h3>
        <ul>
          <li>🌍 Using hardcoded coordinates (Your location)</li>
          <li>🌤️ Real-time weather data from WeatherAPI</li>
          <li>🔄 Automatic weather icon selection based on conditions</li>
          <li>⏰ 5-minute refresh intervals</li>
          <li>📱 Responsive design for mobile and desktop</li>
        </ul>
      </div>
    </div>
  );
};

export default WeatherIconDemo;
