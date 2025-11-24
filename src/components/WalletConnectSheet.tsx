import React, { useEffect, useRef, useState } from 'react';
import { TonConnectUI } from '@tonconnect/ui-react';
import { Wallet, X, CheckCircle2 } from 'lucide-react';
import { TonWalletService } from '../services/ton_wallet_service';
import { TonPaymentService } from '../services/ton_payment_service';

interface WalletConnectSheetProps {
  is_open: boolean;
  on_close: () => void;
  wallet_connected: boolean;
  on_wallet_connected: (wallet_address?: string) => void;
}

export const WalletConnectSheet: React.FC<WalletConnectSheetProps> = ({
  is_open,
  on_close,
  wallet_connected,
  on_wallet_connected
}) => {
  const sheet_ref = useRef<HTMLDivElement>(null);
  const [is_dragging, set_is_dragging] = useState(false);
  const drag_state_ref = useRef({ start_y: 0, current_y: 0 });
  const [ton_connect_ui, set_ton_connect_ui] = useState<TonConnectUI | null>(null);
  const [is_connecting, set_is_connecting] = useState(false);
  const [is_disconnecting, set_is_disconnecting] = useState(false);

  const ton_wallet_service = TonWalletService.getInstance();
  const ton_payment_service = TonPaymentService.getInstance();

  // Get TonConnectUI instance from TonPaymentService (initialized in App.tsx)
  useEffect(() => {
    // Try to get existing TonConnectUI instance from service
    const existing_ui = ton_payment_service.getTonConnectUI();
    if (existing_ui) {
      set_ton_connect_ui(existing_ui);
    } else {
      // If not initialized yet, wait a bit and try again
      const check_interval = setInterval(() => {
        const ui = ton_payment_service.getTonConnectUI();
        if (ui) {
          set_ton_connect_ui(ui);
          clearInterval(check_interval);
        }
      }, 100);
      
      // Stop checking after 5 seconds
      setTimeout(() => {
        clearInterval(check_interval);
      }, 5000);
    }
  }, []);

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

  // Connect wallet using TON Connect UI
  const handle_connect_wallet = () => {
    if (!ton_connect_ui) return;
    set_is_connecting(true);
    try {
      ton_connect_ui.openModal();
    } catch (error) {
      console.error('Failed to open TON Connect modal:', error);
      set_is_connecting(false);
    }
  };

  // Disconnect wallet
  const handle_disconnect_wallet = async () => {
    if (!ton_connect_ui) return;
    try {
      set_is_disconnecting(true);
      await ton_connect_ui.disconnect();
      ton_wallet_service.disconnect();
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
  const wallet_address = current_wallet || (ton_connect_ui?.wallet?.account?.address);

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
            {wallet_address && (
              <p style={{
                fontSize: '0.875rem',
                color: '#6b7280',
                textAlign: 'center',
                marginTop: '0.5rem'
              }}>
                Connected: {format_address(wallet_address)}
              </p>
            )}
          </div>


          {wallet_address ? (
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
                    <p style={{ margin: 0, fontSize: '0.75rem', color: '#6b7280', wordBreak: 'break-all' }}>{wallet_address}</p>
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
            // Wallet not connected - show connect button
            <div>
              <button
                onClick={handle_connect_wallet}
                disabled={!ton_connect_ui || is_connecting}
                style={{
                  padding: '1rem',
                  border: 'none',
                  borderRadius: '12px',
                  fontSize: '1rem',
                  fontWeight: '600',
                  cursor: (!ton_connect_ui || is_connecting) ? 'not-allowed' : 'pointer',
                  background: (!ton_connect_ui || is_connecting) ? '#9ca3af' : 'linear-gradient(135deg, #0098EA 0%, #0088CC 100%)',
                  color: 'white',
                  width: '100%',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '0.5rem',
                  boxShadow: (!ton_connect_ui || is_connecting) ? 'none' : '0 4px 12px rgba(0, 152, 234, 0.3)'
                }}
              >
                <Wallet size={18} />
                {is_connecting ? 'Opening Wallet Options...' : 'Connect Wallet'}
              </button>
            </div>
          )}
        </div>
      </div>
    </>
  );
};

