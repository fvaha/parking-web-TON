import React, { useState, useEffect } from 'react';
import type { ParkingSpace, Sensor, ActiveSession } from '../types';
import { MapPin, Clock, Car, Calendar, AlertCircle } from 'lucide-react';
import { LanguageService } from '../services/language_service';

interface ParkingSpaceCardProps {
  space: ParkingSpace;
  sensor: Sensor;
  on_reserve: (space: ParkingSpace) => void;
  on_navigate: (coordinates: { lat: number; lng: number }) => void;
  on_complete_session: (space: ParkingSpace) => void;
  active_session?: ActiveSession | null;
  license_plate?: string | null;
}

export const ParkingSpaceCard: React.FC<ParkingSpaceCardProps> = ({
  space,
  sensor,
  on_reserve,
  on_navigate,
  on_complete_session,
  active_session,
  license_plate
}) => {
  const language_service = LanguageService.getInstance();
  const [remaining_time, set_remaining_time] = useState<number | null>(null);
  const [time_percentage, set_time_percentage] = useState<number>(100);

  useEffect(() => {
    if (space.status === 'reserved' && space.reservation_end_time) {
      const update_timer = () => {
        const now = new Date().getTime();
        const end_time = new Date(space.reservation_end_time!).getTime();
        const remaining_ms = end_time - now;
        
        if (remaining_ms <= 0) {
          // Reservation expired - auto complete ONLY if this is the user's reservation
          set_remaining_time(0);
          set_time_percentage(0);
          
          // Only auto-complete if this is the user's own reservation
          if (space.license_plate === license_plate && license_plate) {
            // Trigger auto-complete after a short delay
            setTimeout(() => {
              on_complete_session(space);
            }, 1000);
          }
          return;
        }
        
        const remaining_minutes = Math.floor(remaining_ms / 60000);
        set_remaining_time(remaining_minutes);
        
        // Calculate percentage for color (green -> yellow -> red)
        // Red when less than 10 minutes remaining
        if (remaining_minutes <= 10) {
          set_time_percentage(0); // Red
        } else {
          // Calculate percentage based on total duration
          const start_time = space.reservation_time ? new Date(space.reservation_time).getTime() : now;
          const total_duration_ms = end_time - start_time;
          const elapsed_ms = now - start_time;
          const percentage = Math.max(0, Math.min(100, (elapsed_ms / total_duration_ms) * 100));
          set_time_percentage(percentage);
        }
      };
      
      update_timer();
      const interval = setInterval(update_timer, 1000); // Update every second
      
      return () => clearInterval(interval);
    } else {
      set_remaining_time(null);
      set_time_percentage(100);
    }
  }, [space.status, space.reservation_end_time, space.reservation_time, space.license_plate, license_plate, on_complete_session, space]);

  const get_circle_color = () => {
    if (!remaining_time) return '#10b981'; // Green (default)
    if (remaining_time <= 10) return '#ef4444'; // Red (last 10 minutes)
    if (time_percentage < 50) return '#10b981'; // Green (first half)
    if (time_percentage < 75) return '#f59e0b'; // Orange (middle)
    return '#6b7280'; // Gray (early)
  };

  const get_circle_opacity = () => {
    if (!remaining_time) return 1;
    if (remaining_time <= 10) return 1; // Full opacity for red
    return 1 - (time_percentage / 100) * 0.5; // Fade from 1 to 0.5
  };

  const get_status_text = (status: string): string => {
    switch (status) {
      case 'vacant': return language_service.t('available');
      case 'occupied': return language_service.t('occupied');
      case 'reserved': return language_service.t('reserved');
      default: return language_service.t('unknown');
    }
  };

  const get_status_class = (status: string): string => {
    switch (status) {
      case 'vacant': return 'status-vacant';
      case 'occupied': return 'status-occupied';
      case 'reserved': return 'status-reserved';
      default: return 'status-unknown';
    }
  };

  const format_coordinates = (coords: { lat: number; lng: number }): string => {
    return `${coords.lat.toFixed(6)}, ${coords.lng.toFixed(6)}`;
  };

  return (
    <div className={`parking-space-card ${get_status_class(space.status)}`}>
      <div className="card-header">
        <h3>{sensor.name}</h3>
        <div className="header-status" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          {space.status === 'reserved' && remaining_time !== null && (
            <div style={{
              width: '24px',
              height: '24px',
              borderRadius: '50%',
              backgroundColor: get_circle_color(),
              opacity: get_circle_opacity(),
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              transition: 'all 0.3s ease',
              boxShadow: `0 0 8px ${get_circle_color()}40`
            }} title={`${remaining_time} minutes remaining`}>
              {remaining_time <= 10 && (
                <span style={{ color: 'white', fontSize: '0.6rem', fontWeight: 'bold' }}>
                  {remaining_time}
                </span>
              )}
            </div>
          )}
          <span className={`status-badge ${get_status_class(space.status)}`}>
            {get_status_text(space.status)}
          </span>
          {active_session && space.status === 'vacant' && (
            <span className="session-warning" title={`You have an active session at space ${active_session.parking_space_id}`}>
              <AlertCircle size={14} />
              {language_service.t('session_active')}
            </span>
          )}
        </div>
      </div>

      <div className="card-body">
        <div className="info-row">
          <MapPin size={16} />
          <span>{sensor.street_name}</span>
        </div>

        <div className="info-row">
          <Car size={16} />
          <span>{language_service.t('sensor')}: {sensor.wpsd_id}</span>
        </div>

        <div className="info-row">
          <Clock size={16} />
          <span>{language_service.t('coordinates')}: {format_coordinates(space.coordinates)}</span>
        </div>

        {space.license_plate && (
          <div className="info-row">
            <Calendar size={16} />
            <span>{language_service.t('plate')}: {space.license_plate}</span>
          </div>
        )}

        {space.occupied_since && (
          <div className="info-row">
            <Clock size={16} />
            <span>{language_service.t('since')}: {new Date(space.occupied_since).toLocaleTimeString()}</span>
          </div>
        )}

        {space.status === 'reserved' && remaining_time !== null && (
          <div className="info-row">
            <Clock size={16} />
            <span style={{ 
              color: remaining_time <= 10 ? '#ef4444' : '#1f2937',
              fontWeight: remaining_time <= 10 ? '600' : '400'
            }}>
              {remaining_time > 0 
                ? `${remaining_time} minute${remaining_time !== 1 ? 's' : ''} remaining`
                : 'Reservation expired'
              }
            </span>
          </div>
        )}
      </div>

      <div className="card-actions">
        {space.status === 'vacant' && (
          <>
            {active_session ? (
              <div className="disabled-reserve-container">
                <button
                  className="reserve-btn disabled"
                  disabled
                  title={`You already have an active session at space ${active_session.parking_space_id}. Please complete or cancel your current session first.`}
                >
                  <AlertCircle size={16} />
                  {language_service.t('session_active')}
                </button>
                <small className="disabled-message">
                  {language_service.t('session_warning_message')}
                </small>
              </div>
            ) : (
              <button
                className="reserve-btn"
                onClick={() => {
                  // For premium zones, we need to open modal first to show payment UI
                  // For non-premium zones, we can reserve directly
                  if (space.zone?.is_premium) {
                    // Trigger modal opening - parent should handle this
                    // We'll call on_reserve which should open modal for premium zones
                    on_reserve(space);
                  } else {
                    // Non-premium: reserve directly
                    on_reserve(space);
                  }
                }}
              >
                {language_service.t('reserve_space')}
              </button>
            )}
          </>
        )}

        {space.status === 'reserved' && space.license_plate === license_plate && (
          <button
            className="complete-btn"
            onClick={() => on_complete_session(space)}
          >
            {language_service.t('complete_session')}
          </button>
        )}

        <button
          className="navigate-btn"
          onClick={() => on_navigate(space.coordinates)}
        >
          {language_service.t('navigate')}
        </button>
      </div>
    </div>
  );
};
