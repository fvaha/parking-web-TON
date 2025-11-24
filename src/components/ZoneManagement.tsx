import React, { useState, useEffect } from 'react';
import type { ParkingZone } from '../types';
import { Plus, Edit, Trash2, DollarSign, Clock, Palette } from 'lucide-react';
import AdminService from '../services/admin_service';

interface ZoneManagementProps {
  is_superadmin: boolean;
}

export const ZoneManagement: React.FC<ZoneManagementProps> = ({ is_superadmin }) => {
  const [zones, set_zones] = useState<ParkingZone[]>([]);
  const [loading, set_loading] = useState(true);
  const [show_form, set_show_form] = useState(false);
  const [editing_zone, set_editing_zone] = useState<ParkingZone | null>(null);
  const [form_data, set_form_data] = useState({
    name: '',
    description: '',
    color: '#6b7280',
    hourly_rate: 2.00,
    daily_rate: 20.00,
    is_premium: false,
    max_duration_hours: 4
  });

  useEffect(() => {
    load_zones();
  }, []);

  const load_zones = async () => {
    try {
      set_loading(true);
      const admin_service = AdminService.getInstance();
      const result = await admin_service.getParkingZones();
      
      if (result.success && result.data) {
        set_zones(result.data);
      } else {
        console.error('Failed to load zones:', result.error);
      }
    } catch (error) {
      console.error('Error loading zones:', error);
    } finally {
      set_loading(false);
    }
  };

  const handle_submit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    try {
      const admin_service = AdminService.getInstance();
      let result;
      
      if (editing_zone) {
        // Update existing zone
        result = await admin_service.updateParkingZone(parseInt(editing_zone.id), form_data);
      } else {
        // Add new zone
        result = await admin_service.addParkingZone(form_data);
      }
      
      if (result.success) {
        set_show_form(false);
        set_editing_zone(null);
        reset_form();
        load_zones();
        alert(editing_zone ? 'Zone updated successfully!' : 'Zone created successfully!');
      } else {
        alert(`Error: ${result.error}`);
      }
    } catch (error) {
      console.error('Error saving zone:', error);
      alert('Failed to save zone');
    }
  };

  const handle_edit = (zone: ParkingZone) => {
    set_editing_zone(zone);
    set_form_data({
      name: zone.name,
      description: zone.description,
      color: zone.color,
      hourly_rate: zone.hourly_rate,
      daily_rate: zone.daily_rate,
      is_premium: zone.is_premium || false,
      max_duration_hours: zone.max_duration_hours || 4
    });
    set_show_form(true);
  };

  const handle_delete = async (zone_id: string) => {
    if (!confirm('Are you sure you want to delete this zone?')) {
      return;
    }
    
    try {
      const admin_service = AdminService.getInstance();
      const result = await admin_service.deleteParkingZone(parseInt(zone_id));
      
      if (result.success) {
        load_zones();
        alert('Zone deleted successfully!');
      } else {
        alert(`Error: ${result.error}`);
      }
    } catch (error) {
      console.error('Error deleting zone:', error);
      alert('Failed to delete zone');
    }
  };

  const reset_form = () => {
    set_form_data({
      name: '',
      description: '',
      color: '#6b7280',
      hourly_rate: 2.00,
      daily_rate: 20.00,
      is_premium: false,
      max_duration_hours: 4
    });
  };

  const handle_cancel = () => {
    set_show_form(false);
    set_editing_zone(null);
    reset_form();
  };

  if (loading) {
    return <div className="loading">Loading zones...</div>;
  }

  return (
    <div className="zone-management">
      <div className="zone-header">
        <h2>Parking Zone Management</h2>
        {is_superadmin && (
          <button
            className="add-zone-btn"
            onClick={() => set_show_form(true)}
          >
            <Plus size={20} />
            Add Zone
          </button>
        )}
      </div>

      {show_form && (
        <div className="zone-form-container">
          <form onSubmit={handle_submit} className="zone-form">
            <h3>{editing_zone ? 'Edit Zone' : 'Add New Zone'}</h3>
            
            <div className="form-row">
              <div className="form-group">
                <label htmlFor="name">Zone Name *</label>
                <input
                  type="text"
                  id="name"
                  value={form_data.name}
                  onChange={(e) => set_form_data({...form_data, name: e.target.value})}
                  required
                  placeholder="e.g., Downtown Zone"
                />
              </div>
              
              <div className="form-group">
                <label htmlFor="color">Color</label>
                <div className="color-input">
                  <input
                    type="color"
                    id="color"
                    value={form_data.color}
                    onChange={(e) => set_form_data({...form_data, color: e.target.value})}
                  />
                  <span className="color-preview" style={{backgroundColor: form_data.color}}></span>
                </div>
              </div>
            </div>

            <div className="form-group">
              <label htmlFor="description">Description</label>
              <textarea
                id="description"
                value={form_data.description}
                onChange={(e) => set_form_data({...form_data, description: e.target.value})}
                placeholder="Describe the zone characteristics..."
                rows={3}
              />
            </div>

            <div className="form-row">
              <div className="form-group">
                <label htmlFor="hourly_rate">
                  <Clock size={16} />
                  Hourly Rate ($)
                </label>
                <input
                  type="number"
                  id="hourly_rate"
                  value={form_data.hourly_rate}
                  onChange={(e) => set_form_data({...form_data, hourly_rate: parseFloat(e.target.value)})}
                  min="0"
                  step="0.01"
                  required
                />
              </div>
              
              <div className="form-group">
                <label htmlFor="daily_rate">
                  <DollarSign size={16} />
                  Daily Rate ($)
                </label>
                <input
                  type="number"
                  id="daily_rate"
                  value={form_data.daily_rate}
                  onChange={(e) => set_form_data({...form_data, daily_rate: parseFloat(e.target.value)})}
                  min="0"
                  step="0.01"
                  required
                />
              </div>
              
              <div className="form-group">
                <label htmlFor="max_duration_hours">
                  <Clock size={16} />
                  Max Duration (Hours) *
                </label>
                <input
                  type="number"
                  id="max_duration_hours"
                  value={form_data.max_duration_hours}
                  onChange={(e) => set_form_data({...form_data, max_duration_hours: parseInt(e.target.value) || 4})}
                  min="1"
                  max="24"
                  step="1"
                  required
                />
                <p style={{ marginTop: '0.25rem', fontSize: '0.75rem', color: '#6b7280' }}>
                  Maximum reservation duration allowed for this zone
                </p>
              </div>
            </div>

            <div className="form-group">
              <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer' }}>
                <input
                  type="checkbox"
                  checked={form_data.is_premium}
                  onChange={(e) => set_form_data({...form_data, is_premium: e.target.checked})}
                  style={{ width: '18px', height: '18px', cursor: 'pointer' }}
                />
                <span style={{ fontWeight: '500' }}>Premium Zone (TON Payment Required)</span>
              </label>
              <p style={{ marginTop: '0.25rem', fontSize: '0.875rem', color: '#6b7280', marginLeft: '1.75rem' }}>
                Premium zones require TON token payment for reservations
              </p>
            </div>

            <div className="form-actions">
              <button type="submit" className="save-btn">
                {editing_zone ? 'Update Zone' : 'Create Zone'}
              </button>
              <button type="button" className="cancel-btn" onClick={handle_cancel}>
                Cancel
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="zones-grid">
        {zones.map(zone => (
          <div key={zone.id} className="zone-card" style={{borderLeftColor: zone.color}}>
            <div className="zone-header">
              <h3>{zone.name}</h3>
              <div className="zone-status">
                {zone.is_premium && (
                  <span className="status-badge premium" style={{ backgroundColor: '#f59e0b', color: 'white', marginRight: '0.5rem' }}>
                    Premium (TON)
                  </span>
                )}
                <span className={`status-badge ${zone.is_active ? 'active' : 'inactive'}`}>
                  {zone.is_active ? 'Active' : 'Inactive'}
                </span>
              </div>
            </div>
            
            <p className="zone-description">{zone.description}</p>
            
            <div className="zone-pricing">
              <div className="pricing-item">
                <Clock size={16} />
                <span>${zone.hourly_rate.toFixed(2)}/hour</span>
              </div>
              <div className="pricing-item">
                <DollarSign size={16} />
                <span>${zone.daily_rate.toFixed(2)}/day</span>
              </div>
              {zone.max_duration_hours && (
                <div className="pricing-item">
                  <Clock size={16} />
                  <span>Max: {zone.max_duration_hours}h</span>
                </div>
              )}
            </div>
            
            <div className="zone-stats">
              <span className="stat">
                <Palette size={14} />
                {zone.space_count || 0} spaces
              </span>
            </div>
            
            {is_superadmin && (
              <div className="zone-actions">
                <button
                  className="edit-btn"
                  onClick={() => handle_edit(zone)}
                >
                  <Edit size={16} />
                  Edit
                </button>
                <button
                  className="delete-btn"
                  onClick={() => handle_delete(zone.id)}
                >
                  <Trash2 size={16} />
                  Delete
                </button>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
};
