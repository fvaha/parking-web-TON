import React from 'react';
import './WeatherBackground.css';

type WeatherType = 
  | 'clear-day' | 'clear-night'
  | 'cloudy' | 'rainy' | 'snowy' | 'stormy'
  | 'foggy' | 'hazy' | 'windy';

interface WeatherBackgroundProps {
  weather_type: WeatherType;
  className?: string;
}

const WeatherBackground: React.FC<WeatherBackgroundProps> = ({ 
  weather_type, 
  className = '' 
}) => {
  const getWeatherScene = () => {
    const baseClass = `weather-background ${className}`;
    
    switch (weather_type) {
      case 'clear-day':
        return (
          <div className={`${baseClass} clear-day-scene`}>
            {/* Animated Sun */}
            <div className="sun-container">
              <div className="sun-core"></div>
              <div className="sun-rays">
                <div className="ray ray-1"></div>
                <div className="ray ray-2"></div>
                <div className="ray ray-3"></div>
                <div className="ray ray-4"></div>
                <div className="ray ray-5"></div>
                <div className="ray ray-6"></div>
                <div className="ray ray-7"></div>
                <div className="ray ray-8"></div>
              </div>
            </div>
            
            {/* Floating Clouds */}
            <div className="clouds-container">
              <div className="cloud cloud-1"></div>
              <div className="cloud cloud-2"></div>
              <div className="cloud cloud-3"></div>
            </div>
            
            {/* Ambient Particles */}
            <div className="particles-container">
              <div className="particle particle-1"></div>
              <div className="particle particle-2"></div>
              <div className="particle particle-3"></div>
              <div className="particle particle-4"></div>
              <div className="particle particle-5"></div>
            </div>
          </div>
        );
        
      case 'clear-night':
        return (
          <div className={`${baseClass} clear-night-scene`}>
            {/* Moon */}
            <div className="moon-container">
              <div className="moon"></div>
              <div className="moon-glow"></div>
            </div>
            
            {/* Stars */}
            <div className="stars-container">
              <div className="star star-1"></div>
              <div className="star star-2"></div>
              <div className="star star-3"></div>
              <div className="star star-4"></div>
              <div className="star star-5"></div>
              <div className="star star-6"></div>
              <div className="star star-7"></div>
              <div className="star star-8"></div>
            </div>
            
            {/* Shooting Stars */}
            <div className="shooting-stars">
              <div className="shooting-star shooting-star-1"></div>
              <div className="shooting-star shooting-star-2"></div>
            </div>
          </div>
        );
        
      case 'cloudy':
        return (
          <div className={`${baseClass} cloudy-scene`}>
            {/* Main Clouds */}
            <div className="clouds-container">
              <div className="cloud cloud-main cloud-1"></div>
              <div className="cloud cloud-main cloud-2"></div>
              <div className="cloud cloud-main cloud-3"></div>
              <div className="cloud cloud-small cloud-4"></div>
              <div className="cloud cloud-small cloud-5"></div>
            </div>
            
            {/* Cloud Shadows */}
            <div className="cloud-shadows">
              <div className="shadow shadow-1"></div>
              <div className="shadow shadow-2"></div>
            </div>
            
            {/* Ambient Mist */}
            <div className="mist-container">
              <div className="mist mist-1"></div>
              <div className="mist mist-2"></div>
            </div>
          </div>
        );
        
      case 'rainy':
        return (
          <div className={`${baseClass} rainy-scene`}>
            {/* Rain Clouds */}
            <div className="rain-clouds">
              <div className="cloud rain-cloud cloud-1"></div>
              <div className="cloud rain-cloud cloud-2"></div>
            </div>
            
            {/* Rain Drops */}
            <div className="rain-container">
              <div className="rain-drop drop-1"></div>
              <div className="rain-drop drop-2"></div>
              <div className="rain-drop drop-3"></div>
              <div className="rain-drop drop-4"></div>
              <div className="rain-drop drop-5"></div>
              <div className="rain-drop drop-6"></div>
              <div className="rain-drop drop-7"></div>
              <div className="rain-drop drop-8"></div>
              <div className="rain-drop drop-9"></div>
              <div className="rain-drop drop-10"></div>
            </div>
            
            {/* Rain Puddles Effect */}
            <div className="rain-puddles">
              <div className="puddle puddle-1"></div>
              <div className="puddle puddle-2"></div>
            </div>
          </div>
        );
        
      case 'snowy':
        return (
          <div className={`${baseClass} snowy-scene`}>
            {/* Snow Clouds */}
            <div className="snow-clouds">
              <div className="cloud snow-cloud cloud-1"></div>
              <div className="cloud snow-cloud cloud-2"></div>
            </div>
            
            {/* Snowflakes */}
            <div className="snow-container">
              <div className="snowflake flake-1"></div>
              <div className="snowflake flake-2"></div>
              <div className="snowflake flake-3"></div>
              <div className="snowflake flake-4"></div>
              <div className="snowflake flake-5"></div>
              <div className="snowflake flake-6"></div>
              <div className="snowflake flake-7"></div>
              <div className="snowflake flake-8"></div>
            </div>
            
            {/* Snow Accumulation Effect */}
            <div className="snow-accumulation">
              <div className="snow-layer layer-1"></div>
              <div className="snow-layer layer-2"></div>
            </div>
          </div>
        );
        
      case 'stormy':
        return (
          <div className={`${baseClass} stormy-scene`}>
            {/* Storm Clouds */}
            <div className="storm-clouds">
              <div className="cloud storm-cloud cloud-1"></div>
              <div className="cloud storm-cloud cloud-2"></div>
              <div className="cloud storm-cloud cloud-3"></div>
            </div>
            
            {/* Lightning */}
            <div className="lightning-container">
              <div className="lightning bolt-1"></div>
              <div className="lightning bolt-2"></div>
            </div>
            
            {/* Heavy Rain */}
            <div className="storm-rain">
              <div className="rain-drop storm-drop drop-1"></div>
              <div className="rain-drop storm-drop drop-2"></div>
              <div className="rain-drop storm-drop drop-3"></div>
              <div className="rain-drop storm-drop drop-4"></div>
              <div className="rain-drop storm-drop drop-5"></div>
              <div className="rain-drop storm-drop drop-6"></div>
            </div>
            
            {/* Wind Effects */}
            <div className="wind-effects">
              <div className="wind-line wind-1"></div>
              <div className="wind-line wind-2"></div>
              <div className="wind-line wind-3"></div>
            </div>
          </div>
        );
        
      case 'foggy':
        return (
          <div className={`${baseClass} foggy-scene`}>
            {/* Fog Layers */}
            <div className="fog-layers">
              <div className="fog-layer layer-1"></div>
              <div className="fog-layer layer-2"></div>
              <div className="fog-layer layer-3"></div>
            </div>
            
            {/* Mist Particles */}
            <div className="mist-particles">
              <div className="mist-particle particle-1"></div>
              <div className="mist-particle particle-2"></div>
              <div className="mist-particle particle-3"></div>
              <div className="mist-particle particle-4"></div>
              <div className="mist-particle particle-5"></div>
            </div>
            
            {/* Ambient Glow */}
            <div className="fog-glow"></div>
          </div>
        );
        
      case 'hazy':
        return (
          <div className={`${baseClass} hazy-scene`}>
            {/* Haze Overlay */}
            <div className="haze-overlay"></div>
            
            {/* Dust Particles */}
            <div className="dust-container">
              <div className="dust-particle dust-1"></div>
              <div className="dust-particle dust-2"></div>
              <div className="dust-particle dust-3"></div>
              <div className="dust-particle dust-4"></div>
              <div className="dust-particle dust-5"></div>
            </div>
            
            {/* Sun Through Haze */}
            <div className="hazy-sun">
              <div className="sun-disc"></div>
              <div className="sun-halo"></div>
            </div>
          </div>
        );
        
      case 'windy':
        return (
          <div className={`${baseClass} windy-scene`}>
            {/* Wind Lines */}
            <div className="wind-lines">
              <div className="wind-line line-1"></div>
              <div className="wind-line line-2"></div>
              <div className="wind-line line-3"></div>
              <div className="wind-line line-4"></div>
              <div className="wind-line line-5"></div>
              <div className="wind-line line-6"></div>
            </div>
            
            {/* Floating Debris */}
            <div className="wind-debris">
              <div className="debris piece-1"></div>
              <div className="debris piece-2"></div>
              <div className="debris piece-3"></div>
            </div>
            
            {/* Cloud Movement */}
            <div className="wind-clouds">
              <div className="cloud wind-cloud cloud-1"></div>
              <div className="cloud wind-cloud cloud-2"></div>
            </div>
          </div>
        );
        
      default:
        return (
          <div className={`${baseClass} clear-day-scene`}>
            <div className="sun-container">
              <div className="sun-core"></div>
            </div>
          </div>
        );
    }
  };

  return getWeatherScene();
};

export default WeatherBackground;
