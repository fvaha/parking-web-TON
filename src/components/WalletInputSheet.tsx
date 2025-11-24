import React, { useEffect, useRef, useState } from 'react';
import { Wallet, X, CheckCircle2 } from 'lucide-react';
import { TonWalletService } from '../services/ton_wallet_service';

interface WalletInputSheetProps {
  is_open: boolean;
  on_close: () => void;
  wallet_connected: boolean;
  on_wallet_connected: (wallet_address?: string) => void;
}

export const WalletInputSheet: React.FC<WalletInputSheetProps> = ({
  is_open,
  on_close,
  wallet_connected,
  on_wallet_connected
}) => {
  const sheet_ref = useRef<HTMLDivElement>(null);
  const [is_dragging, set_is_dragging] = useState(false);
  const drag_state_ref = useRef({ start_y: 0, current_y: 0 });
  const [wallet_address, set_wallet_address] = useState<string>('');
  const [is_validating, set_is_validating] = useState(false);
  const [validation_error, set_validation_error] = useState<string | null>(null);
  const [is_disconnecting, set_is_disconnecting] = useState(false);

  const ton_wallet_service = TonWalletService.getInstance();

  // Load existing wallet address when sheet opens
  useEffect(() => {
    if (is_open) {
      const existing_address = ton_wallet_service.getWalletAddress();
      if (existing_address) {
        set_wallet_address(existing_address);
      } else {
        set_wallet_address('');
      }
      set_validation_error(null);
    }
  }, [is_open]);

  // Drag handlers
  const handle_touch_start = (e: React.TouchEvent) => {
    if (!sheet_ref.current) return;
    set_is_dragging(true);
    drag_state_ref.current.start_y = e.touches[0].clientY;
    drag_state_ref.current.current_y = e.touches[0].clientY;
  };

  const handle_touch_move = (e: React.TouchEvent) => {
    if (!is_dragging || !sheet_ref.current) return;
    const current = e.touches[0].clientY;
    drag_state_ref.current.current_y = current;
    const diff = current - drag_state_ref.current.start_y;
    if (diff > 0) {
      sheet_ref.current.style.transform = `translateY(${diff}px)`;
    }
  };

  const handle_touch_end = () => {
    if (!is_dragging || !sheet_ref.current) return;
    const diff = drag_state_ref.current.current_y - drag_state_ref.current.start_y;
    if (diff > 100) {
      on_close();
    } else {
      sheet_ref.current.style.transform = 'translateY(0)';
    }
    set_is_dragging(false);
  };

  // Connect wallet
  const handle_connect_wallet = async () => {
    if (!wallet_address.trim()) {
      set_validation_error('Please enter a wallet address');
      return;
    }

    set_is_validating(true);
    set_validation_error(null);

    try {
      const validation_result = await ton_wallet_service.validateWalletAddress(wallet_address.trim());
      
      if (validation_result.success && validation_result.valid) {
        ton_wallet_service.setWalletAddress(wallet_address.trim());
        on_wallet_connected(wallet_address.trim());
        setTimeout(() => on_close(), 500);
      } else {
        set_validation_error(validation_result.error || 'Invalid wallet address');
      }
    } catch (error: any) {
      set_validation_error(error.message || 'Failed to validate wallet address');
    } finally {
      set_is_validating(false);
    }
  };

  // Disconnect wallet
  const handle_disconnect_wallet = async () => {
    try {
      set_is_disconnecting(true);
      ton_wallet_service.disconnect();
      set_wallet_address('');
      on_wallet_connected();
      setTimeout(() => on_close(), 200);
    } catch (error) {
      console.error('Error disconnecting wallet:', error);
      alert('Failed to disconnect wallet. Please try again.');
    } finally {
      set_is_disconnecting(false);
    }
  };

  // Format address for display
  const format_address = (address: string) => {
    if (!address) return '';
    if (address.length <= 12) return address;
    return `${address.substring(0, 6)}...${address.substring(address.length - 4)}`;
  };

  if (!is_open) return null;

  const current_wallet = ton_wallet_service.getWalletAddress();

  return (
    <>
      <div
        style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          backgroundColor: 'rgba(0, 0, 0, 0.5)',
          zIndex: 9998,
          animation: 'fadeIn 0.2s ease-out'
        }}
        onClick={on_close}
      />
      <div
        ref={sheet_ref}
        style={{
          position: 'fixed',
          bottom: 0,
          left: 0,
          right: 0,
          backgroundColor: 'white',
          borderTopLeftRadius: '20px',
          borderTopRightRadius: '20px',
          boxShadow: '0 -4px 20px rgba(0, 0, 0, 0.15)',
          zIndex: 9999,
          maxHeight: '60vh',
          overflowY: 'auto',
          transform: 'translateY(0)',
          transition: is_dragging ? 'none' : 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)',
          paddingBottom: 'env(safe-area-inset-bottom)'
        }}
        onTouchStart={handle_touch_start}
        onTouchMove={handle_touch_move}
        onTouchEnd={handle_touch_end}
      >
        {/* Drag Handle */}
        <div
          style={{
            width: '100%',
            padding: '12px 0',
            display: 'flex',
            justifyContent: 'center',
            alignItems: 'center',
            cursor: 'grab',
            userSelect: 'none'
          }}
        >
          <div
            style={{
              width: '40px',
              height: '4px',
              backgroundColor: '#d1d5db',
              borderRadius: '2px'
            }}
          />
        </div>

        {/* Content */}
        <div style={{ padding: '0 1.5rem 1.5rem 1.5rem' }}>
          <div style={{ marginBottom: '1.5rem' }}>
            <h3 style={{
              fontSize: '1.5rem',
              fontWeight: '700',
              marginBottom: '0.5rem',
              color: '#1f2937',
              textAlign: 'center'
            }}>
              TON Wallet
            </h3>
            {current_wallet && (
              <p style={{
                fontSize: '0.875rem',
                color: '#6b7280',
                textAlign: 'center',
                marginTop: '0.5rem'
              }}>
                Connected: {format_address(current_wallet)}
              </p>
            )}
          </div>

          {current_wallet ? (
            // Wallet connected - show disconnect option
            <div>
              <div style={{
                backgroundColor: '#f9fafb',
                borderRadius: '12px',
                padding: '1rem',
                border: '1px solid #e5e7eb',
                marginBottom: '1rem'
              }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                  <div style={{
                    width: '40px',
                    height: '40px',
                    borderRadius: '50%',
                    backgroundColor: '#10b981',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center'
                  }}>
                    <CheckCircle2 size={20} color="white" />
                  </div>
                  <div style={{ flex: 1 }}>
                    <p style={{ margin: 0, fontWeight: '600', color: '#1f2937' }}>Wallet Connected</p>
                    <p style={{ margin: 0, fontSize: '0.75rem', color: '#6b7280', wordBreak: 'break-all' }}>{current_wallet}</p>
                  </div>
                </div>
              </div>

              <button
                onClick={handle_disconnect_wallet}
                disabled={is_disconnecting}
                style={{
                  padding: '1rem',
                  border: 'none',
                  borderRadius: '12px',
                  fontSize: '1rem',
                  fontWeight: '600',
                  cursor: is_disconnecting ? 'not-allowed' : 'pointer',
                  background: is_disconnecting ? '#9ca3af' : '#ef4444',
                  color: 'white',
                  width: '100%',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '0.5rem'
                }}
              >
                <X size={18} />
                {is_disconnecting ? 'Disconnecting...' : 'Disconnect Wallet'}
              </button>
            </div>
          ) : (
            // Wallet not connected - show input form
            <div>
              <div style={{ marginBottom: '1rem' }}>
                <label style={{
                  display: 'block',
                  fontSize: '0.875rem',
                  fontWeight: '600',
                  color: '#1f2937',
                  marginBottom: '0.5rem'
                }}>
                  Enter TON Wallet Address
                </label>
                <input
                  type="text"
                  value={wallet_address}
                  onChange={(e) => {
                    set_wallet_address(e.target.value);
                    set_validation_error(null);
                  }}
                  placeholder="EQD... or UQ..."
                  style={{
                    width: '100%',
                    padding: '0.875rem',
                    border: validation_error ? '2px solid #ef4444' : '1px solid #e5e7eb',
                    borderRadius: '8px',
                    fontSize: '0.9375rem',
                    fontFamily: 'monospace',
                    backgroundColor: validation_error ? '#fef2f2' : 'white'
                  }}
                  onKeyPress={(e) => {
                    if (e.key === 'Enter' && !is_validating) {
                      handle_connect_wallet();
                    }
                  }}
                />
                {validation_error && (
                  <p style={{
                    margin: '0.5rem 0 0 0',
                    fontSize: '0.75rem',
                    color: '#ef4444'
                  }}>
                    {validation_error}
                  </p>
                )}
                <p style={{
                  margin: '0.5rem 0 0 0',
                  fontSize: '0.75rem',
                  color: '#6b7280'
                }}>
                  Enter your TON wallet address (starts with EQ, UQ, or 0:)
                </p>
              </div>

              <button
                onClick={handle_connect_wallet}
                disabled={is_validating || !wallet_address.trim()}
                style={{
                  padding: '1rem',
                  border: 'none',
                  borderRadius: '12px',
                  fontSize: '1rem',
                  fontWeight: '600',
                  cursor: (is_validating || !wallet_address.trim()) ? 'not-allowed' : 'pointer',
                  background: (is_validating || !wallet_address.trim()) ? '#9ca3af' : 'linear-gradient(135deg, #0098EA 0%, #0088CC 100%)',
                  color: 'white',
                  width: '100%',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '0.5rem',
                  boxShadow: (is_validating || !wallet_address.trim()) ? 'none' : '0 4px 12px rgba(0, 152, 234, 0.3)'
                }}
              >
                <Wallet size={18} />
                {is_validating ? 'Validating...' : 'Connect Wallet'}
              </button>
            </div>
          )}
        </div>
      </div>
    </>
  );
};

