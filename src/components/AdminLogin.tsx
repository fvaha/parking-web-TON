import React, { useState } from 'react';
import AdminService from '../services/admin_service';
import type { LoginCredentials } from '../services/admin_service';
import { User, Lock, AlertCircle } from 'lucide-react';
import { LanguageService } from '../services/language_service';

interface AdminLoginProps {
  onLoginSuccess: () => void;
}

const AdminLogin: React.FC<AdminLoginProps> = ({ onLoginSuccess }) => {
  const [credentials, setCredentials] = useState<LoginCredentials>({
    username: '',
    password: ''
  });
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string>('');
  const [showPassword, setShowPassword] = useState(false);

  const adminService = AdminService.getInstance();
  const language_service = LanguageService.getInstance();

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setCredentials(prev => ({
      ...prev,
      [name]: value
    }));
    setError(''); // Clear error when user types
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!credentials.username || !credentials.password) {
      setError(language_service.t('please_enter_both_username_password'));
      return;
    }

    setIsLoading(true);
    setError('');

    try {
      console.log('Attempting login with:', credentials.username);
      const result = await adminService.login(credentials);
      console.log('Login result:', result);
      
      if (result.success && result.user) {
        console.log('Login successful, calling onLoginSuccess');
        onLoginSuccess();
      } else {
        console.log('Login failed:', result.error);
        setError(result.error || language_service.t('login_failed'));
      }
    } catch (err) {
      console.error('Login error:', err);
      setError(language_service.t('unexpected_error'));
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="admin-login-container">
      <div className="admin-login-card">
        <div className="admin-login-header">
          <h2>{language_service.t('admin_login')}</h2>
          <p>{language_service.t('enter_credentials_to_access_admin')}</p>
        </div>

        <form onSubmit={handleSubmit} className="admin-login-form">
          {error && (
            <div className="error-message">
              <AlertCircle size={16} />
              <span>{error}</span>
            </div>
          )}

          <div className="form-group">
            <label htmlFor="username">{language_service.t('username')}</label>
            <div className="input-wrapper">
              <User size={16} className="input-icon" />
              <input
                type="text"
                id="username"
                name="username"
                value={credentials.username}
                onChange={handleInputChange}
                placeholder={language_service.t('enter_username')}
                disabled={isLoading}
                required
              />
            </div>
          </div>

          <div className="form-group">
            <label htmlFor="password">{language_service.t('password')}</label>
            <div className="input-wrapper">
              <Lock size={16} className="input-icon" />
              <input
                type={showPassword ? 'text' : 'password'}
                id="password"
                name="password"
                value={credentials.password}
                onChange={handleInputChange}
                placeholder={language_service.t('enter_password')}
                disabled={isLoading}
                required
                autoComplete="current-password"
              />
              <button
                type="button"
                className="password-toggle"
                onClick={() => setShowPassword(!showPassword)}
                disabled={isLoading}
              >
                {showPassword ? language_service.t('hide') : language_service.t('show')}
              </button>
            </div>
          </div>

          <button
            type="submit"
            className="login-button"
            disabled={isLoading}
          >
            {isLoading ? language_service.t('signing_in') : language_service.t('sign_in')}
          </button>
        </form>

        <div className="admin-login-info">
          <h4>{language_service.t('default_credentials')}</h4>
          <div className="credentials-list">
            <div className="credential-item">
              <strong>{language_service.t('superadmin')}</strong> superadmin / superadmin123
            </div>
            <div className="credential-item">
              <strong>{language_service.t('admin')}</strong> admin / admin123
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default AdminLogin;
