import React, { useState, useEffect } from 'react';
import type { AdminUser } from '../types';
import { Plus, Edit, Trash2, Lock, User, Shield, Mail } from 'lucide-react';
import { build_api_url } from '../config/api_config';

interface AdminManagementProps {
  is_superadmin: boolean;
}

export const AdminManagement: React.FC<AdminManagementProps> = ({ is_superadmin }) => {
  const [admins, set_admins] = useState<AdminUser[]>([]);
  const [loading, set_loading] = useState(true);
  const [show_form, set_show_form] = useState(false);
  const [show_password_form, set_show_password_form] = useState(false);
  const [editing_admin, set_editing_admin] = useState<AdminUser | null>(null);
  const [password_admin, set_password_admin] = useState<AdminUser | null>(null);
  const [form_data, set_form_data] = useState({
    username: '',
    email: '',
    password: '',
    role: 'admin' as 'admin' | 'superadmin'
  });
  const [password_data, set_password_data] = useState({
    new_password: '',
    confirm_password: ''
  });

  useEffect(() => {
    load_admins();
  }, []);

  const load_admins = async () => {
    try {
      set_loading(true);
      const response = await fetch(build_api_url('/api/admin-management.php'));
      const result = await response.json();
      
      if (result.success) {
        set_admins(result.data);
      } else {
        console.error('Failed to load admins:', result.error);
      }
    } catch (error) {
      console.error('Error loading admins:', error);
    } finally {
      set_loading(false);
    }
  };

  const handle_submit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    try {
      const url = editing_admin 
        ? build_api_url(`/api/admin-management.php?id=${editing_admin.id}`)
        : build_api_url('/api/admin-management.php');
      
      const method = editing_admin ? 'PUT' : 'POST';
      
      const response = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(form_data)
      });
      
      const result = await response.json();
      
      if (result.success) {
        set_show_form(false);
        set_editing_admin(null);
        reset_form();
        load_admins();
      } else {
        alert(`Error: ${result.error}`);
      }
    } catch (error) {
      console.error('Error saving admin:', error);
      alert('Failed to save admin user');
    }
  };

  const handle_password_change = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (password_data.new_password !== password_data.confirm_password) {
      alert('Passwords do not match');
      return;
    }
    
    if (password_data.new_password.length < 6) {
      alert('Password must be at least 6 characters long');
      return;
    }
    
    try {
      const response = await fetch(build_api_url(`/api/admin-management.php?id=${password_admin!.id}`), {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'change_password',
          new_password: password_data.new_password
        })
      });
      
      const result = await response.json();
      
      if (result.success) {
        set_show_password_form(false);
        set_password_admin(null);
        set_password_data({ new_password: '', confirm_password: '' });
        alert('Password changed successfully');
      } else {
        alert(`Error: ${result.error}`);
      }
    } catch (error) {
      console.error('Error changing password:', error);
      alert('Failed to change password');
    }
  };

  const handle_edit = (admin: AdminUser) => {
    set_editing_admin(admin);
    set_form_data({
      username: admin.username,
      email: admin.email,
      password: '',
      role: admin.role
    });
    set_show_form(true);
  };

  const handle_password_change_click = (admin: AdminUser) => {
    set_password_admin(admin);
    set_password_data({ new_password: '', confirm_password: '' });
    set_show_password_form(true);
  };

  const delete_admin = async (admin_id: string) => {
    if (!window.confirm('Are you sure you want to delete this admin user?')) {
      return;
    }
    
    try {
      const response = await fetch(build_api_url(`/api/admin-management.php?id=${admin_id}`), {
        method: 'DELETE',
        headers: {
          'Content-Type': 'application/json',
        }
      });
      
      const result = await response.json();
      
      if (result.success) {
        load_admins();
      } else {
        alert(`Error: ${result.error}`);
      }
    } catch (error) {
      console.error('Error deleting admin:', error);
      alert('Failed to delete admin user');
    }
  };

  const reset_form = () => {
    set_form_data({
      username: '',
      email: '',
      password: '',
      role: 'admin'
    });
  };

  const handle_cancel = () => {
    set_show_form(false);
    set_editing_admin(null);
    reset_form();
  };

  const handle_password_cancel = () => {
    set_show_password_form(false);
    set_password_admin(null);
    set_password_data({ new_password: '', confirm_password: '' });
  };

  if (loading) {
    return <div className="loading">Loading admin users...</div>;
  }

  return (
    <div className="admin-management">
      <div className="admin-header">
        <h2>Admin User Management</h2>
        {is_superadmin && (
          <button
            className="add-admin-btn"
            onClick={() => set_show_form(true)}
          >
            <Plus size={20} />
            Add Admin
          </button>
        )}
      </div>

      {show_form && (
        <div className="admin-form-container">
          <form onSubmit={handle_submit} className="admin-form">
            <h3>{editing_admin ? 'Edit Admin User' : 'Add New Admin User'}</h3>
            
            <div className="form-row">
              <div className="form-group">
                <label htmlFor="username">
                  <User size={16} />
                  Username *
                </label>
                <input
                  type="text"
                  id="username"
                  value={form_data.username}
                  onChange={(e) => set_form_data({...form_data, username: e.target.value})}
                  required
                  placeholder="Enter username"
                />
              </div>
              
              <div className="form-group">
                <label htmlFor="email">
                  <Mail size={16} />
                  Email *
                </label>
                <input
                  type="email"
                  id="email"
                  value={form_data.email}
                  onChange={(e) => set_form_data({...form_data, email: e.target.value})}
                  required
                  placeholder="Enter email"
                />
              </div>
            </div>

            {!editing_admin && (
              <div className="form-group">
                <label htmlFor="password">
                  <Lock size={16} />
                  Password *
                </label>
                <input
                  type="password"
                  id="password"
                  value={form_data.password}
                  onChange={(e) => set_form_data({...form_data, password: e.target.value})}
                  required
                  placeholder="Enter password"
                  minLength={6}
                />
              </div>
            )}

            <div className="form-group">
              <label htmlFor="role">
                <Shield size={16} />
                Role
              </label>
              <select
                id="role"
                value={form_data.role}
                onChange={(e) => set_form_data({...form_data, role: e.target.value as 'admin' | 'superadmin'})}
              >
                <option value="admin">Admin</option>
                {is_superadmin && <option value="superadmin">Super Admin</option>}
              </select>
              {form_data.role === 'superadmin' && (
                <small className="warning">
                  Only superadmins can create other superadmins
                </small>
              )}
            </div>

            <div className="form-actions">
              <button type="submit" className="save-btn">
                {editing_admin ? 'Update Admin' : 'Create Admin'}
              </button>
              <button type="button" className="cancel-btn" onClick={handle_cancel}>
                Cancel
              </button>
            </div>
          </form>
        </div>
      )}

      {show_password_form && (
        <div className="password-form-container">
          <form onSubmit={handle_password_change} className="password-form">
            <h3>Change Password for {password_admin?.username}</h3>
            
            <div className="form-group">
              <label htmlFor="new_password">
                <Lock size={16} />
                New Password *
              </label>
              <input
                type="password"
                id="new_password"
                value={password_data.new_password}
                onChange={(e) => set_password_data({...password_data, new_password: e.target.value})}
                required
                placeholder="Enter new password"
                minLength={6}
              />
            </div>

            <div className="form-group">
              <label htmlFor="confirm_password">
                <Lock size={16} />
                Confirm Password *
              </label>
              <input
                type="password"
                id="confirm_password"
                value={password_data.confirm_password}
                onChange={(e) => set_password_data({...password_data, confirm_password: e.target.value})}
                required
                placeholder="Confirm new password"
                minLength={6}
              />
            </div>

            <div className="form-actions">
              <button type="submit" className="save-btn">
                Change Password
              </button>
              <button type="button" className="cancel-btn" onClick={handle_password_cancel}>
                Cancel
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="admins-grid">
        {admins.map(admin => (
          <div key={admin.id} className="admin-card">
            <div className="admin-header">
              <div className="admin-info">
                <h3>{admin.username}</h3>
                <span className={`role-badge ${admin.role}`}>
                  {admin.role === 'superadmin' ? <Shield size={14} /> : <User size={14} />}
                  {admin.role}
                </span>
              </div>
              <div className="admin-status">
                <span className={`status-badge ${admin.is_active ? 'active' : 'inactive'}`}>
                  {admin.is_active ? 'Active' : 'Inactive'}
                </span>
              </div>
            </div>
            
            <div className="admin-details">
              <p className="admin-email">
                <Mail size={14} />
                {admin.email}
              </p>
              <p className="admin-created">
                Created: {new Date(admin.created_at).toLocaleDateString()}
              </p>
              {admin.last_login && (
                <p className="admin-last-login">
                  Last login: {new Date(admin.last_login).toLocaleString()}
                </p>
              )}
            </div>
            
            {is_superadmin && (
              <div className="admin-actions">
                <button
                  className="edit-btn"
                  onClick={() => handle_edit(admin)}
                >
                  <Edit size={16} />
                  Edit
                </button>
                <button
                  className="password-btn"
                  onClick={() => handle_password_change_click(admin)}
                >
                  <Lock size={16} />
                  Password
                </button>
                <button
                  className="delete-btn"
                  onClick={() => delete_admin(admin.id)}
                  disabled={admin.role === 'superadmin'}
                  title={admin.role === 'superadmin' ? 'Cannot delete superadmin' : 'Delete admin'}
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
