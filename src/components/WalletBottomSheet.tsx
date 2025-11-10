import React, { useEffect, useRef, useState, useCallback } from 'react';
import { TonConnectUI } from '@tonconnect/ui-react';
import { Wallet, Plus, X, ExternalLink } from 'lucide-react';

interface WalletBottomSheetProps {
  is_open: boolean;
  on_close: () => void;
  wallet_connected: boolean;
  on_wallet_connected: () => void;
}

export const WalletBottomSheet: React.FC<WalletBottomSheetProps> = ({
  is_open,
  on_close,
  wallet_connected,
  on_wallet_connected
}) => {
  const sheet_ref = useRef<HTMLDivElement>(null);
  const [is_dragging, set_is_dragging] = useState(false);
  const drag_state_ref = useRef({ start_y: 0, current_y: 0 });
  const [ton_connect_ui, set_ton_connect_ui] = useState<TonConnectUI | null>(null);
  const [wallet_address, set_wallet_address] = useState<string | null>(null);
  const [is_connecting, set_is_connecting] = useState(false);
  const [is_disconnecting, set_is_disconnecting] = useState(false);

  useEffect(() => {
    if (is_open) {
      // Initialize TonConnectUI when sheet opens
      const init_ton_connect = async () => {
        try {
          // Create a temporary button element for TonConnectUI
          let button_element = document.getElementById('wallet-connect-button-temp');
          if (!button_element) {
            button_element = document.createElement('div');
            button_element.id = 'wallet-connect-button-temp';
            button_element.style.display = 'none';
            document.body.appendChild(button_element);
          }

          const ui = new TonConnectUI({
            manifestUrl: `${window.location.origin}/tonconnect-manifest.json`,
            buttonRootId: 'wallet-connect-button-temp'
          });
          
          // Listen for wallet connection changes
          ui.onStatusChange((wallet) => {
            console.log('Wallet status changed:', wallet);
            if (wallet) {
              set_wallet_address(wallet.account.address);
              on_wallet_connected();
            } else {
              set_wallet_address(null);
              on_wallet_connected();
            }
          });

          // Check current wallet status
          if (ui.wallet) {
            set_wallet_address(ui.wallet.account.address);
          }

          set_ton_connect_ui(ui);
        } catch (error) {
          console.error('Failed to initialize TON Connect:', error);
        }
      };

      init_ton_connect();
    }

    return () => {
      // Cleanup: remove temporary button element
      const button_element = document.getElementById('wallet-connect-button-temp');
      if (button_element && button_element.parentNode) {
        button_element.parentNode.removeChild(button_element);
      }
    };
  }, [is_open, on_wallet_connected]);

  useEffect(() => {
    if (is_open && sheet_ref.current) {
      setTimeout(() => {
        if (sheet_ref.current) {
          sheet_ref.current.style.transform = 'translateY(0)';
        }
      }, 10);
    } else if (!is_open && sheet_ref.current) {
      sheet_ref.current.style.transform = 'translateY(100%)';
    }
  }, [is_open]);

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

  const handle_mouse_down = (e: React.MouseEvent) => {
    if (!sheet_ref.current) return;
    set_is_dragging(true);
    drag_state_ref.current.start_y = e.clientY;
    drag_state_ref.current.current_y = e.clientY;
  };

  const handle_mouse_move = useCallback((e: MouseEvent) => {
    if (!is_dragging || !sheet_ref.current) return;
    const current = e.clientY;
    drag_state_ref.current.current_y = current;
    const diff = current - drag_state_ref.current.start_y;
    if (diff > 0) {
      sheet_ref.current.style.transform = `translateY(${diff}px)`;
    }
  }, [is_dragging]);

  const handle_mouse_up = useCallback(() => {
    if (!is_dragging || !sheet_ref.current) return;
    const diff = drag_state_ref.current.current_y - drag_state_ref.current.start_y;
    if (diff > 100) {
      on_close();
    } else {
      sheet_ref.current.style.transform = 'translateY(0)';
    }
    set_is_dragging(false);
  }, [is_dragging, on_close]);

  useEffect(() => {
    if (is_dragging) {
      document.addEventListener('mousemove', handle_mouse_move);
      document.addEventListener('mouseup', handle_mouse_up);
      return () => {
        document.removeEventListener('mousemove', handle_mouse_move);
        document.removeEventListener('mouseup', handle_mouse_up);
      };
    }
  }, [is_dragging, handle_mouse_move, handle_mouse_up]);

  const handle_connect_wallet = async () => {
    if (!ton_connect_ui) return;
    
    // Check if wallet is already connected
    const current_wallet = ton_connect_ui.wallet;
    if (current_wallet) {
      console.log('Wallet already connected');
      set_wallet_address(current_wallet.account.address);
      on_wallet_connected();
      return;
    }
    
    set_is_connecting(true);
    try {
      // Open wallet connection modal
      await ton_connect_ui.openModal();
    } catch (error: any) {
      console.error('Failed to connect wallet:', error);
      // Check if error is about wallet already being connected
      if (error?.message?.includes('already connected')) {
        // Wallet is already connected, just update state
        const wallet = ton_connect_ui.wallet;
        if (wallet && 'account' in wallet) {
          set_wallet_address((wallet as any).account.address);
          on_wallet_connected();
        }
      } else {
        alert('Failed to connect wallet. Please try again.');
      }
    } finally {
      set_is_connecting(false);
    }
  };

  const handle_disconnect_wallet = async () => {
    if (!ton_connect_ui) return;
    
    set_is_disconnecting(true);
    try {
      await ton_connect_ui.disconnect();
      set_wallet_address(null);
      on_wallet_connected();
      on_close();
    } catch (error) {
      console.error('Failed to disconnect wallet:', error);
      alert('Failed to disconnect wallet. Please try again.');
    } finally {
      set_is_disconnecting(false);
    }
  };

  const handle_create_wallet = () => {
    // Open Tonkeeper web wallet in new tab
    // Direct link to web wallet creation
    window.open('https://wallet.tonkeeper.com/', '_blank');
  };

  const format_address = (address: string) => {
    if (!address) return '';
    return `${address.substring(0, 6)}...${address.substring(address.length - 4)}`;
  };

  if (!is_open) return null;

  return (
    <>
      {/* Backdrop */}
      <div
        className="wallet-bottom-sheet-backdrop"
        onClick={on_close}
        style={{
          position: 'fixed',
          top: 0,
          left: 0,
          width: '100%',
          height: '100%',
          backgroundColor: 'rgba(0, 0, 0, 0.5)',
          zIndex: 10000,
          animation: 'fadeIn 0.3s ease'
        }}
      />
      
      {/* Bottom Sheet */}
      <div
        ref={sheet_ref}
        className="wallet-bottom-sheet"
        onTouchStart={handle_touch_start}
        onTouchMove={handle_touch_move}
        onTouchEnd={handle_touch_end}
        style={{
          position: 'fixed',
          bottom: 0,
          left: 0,
          right: 0,
          backgroundColor: 'white',
          borderTopLeftRadius: '24px',
          borderTopRightRadius: '24px',
          boxShadow: '0 -4px 20px rgba(0, 0, 0, 0.15)',
          zIndex: 10001,
          maxHeight: '90vh',
          overflowY: 'auto',
          transform: 'translateY(100%)',
          transition: is_dragging ? 'none' : 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)',
          paddingBottom: 'env(safe-area-inset-bottom)'
        }}
      >
        {/* Drag Handle */}
        <div
          onMouseDown={handle_mouse_down}
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
            {wallet_connected && wallet_address && (
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

          {wallet_connected && wallet_address ? (
            // Disconnect option
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              <div style={{
                backgroundColor: '#f9fafb',
                borderRadius: '12px',
                padding: '1rem',
                border: '1px solid #e5e7eb'
              }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '0.75rem' }}>
                  <div style={{
                    width: '40px',
                    height: '40px',
                    borderRadius: '50%',
                    backgroundColor: '#10b981',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center'
                  }}>
                    <Wallet size={20} color="white" />
                  </div>
                  <div style={{ flex: 1 }}>
                    <p style={{ margin: 0, fontWeight: '600', color: '#1f2937' }}>Wallet Connected</p>
                    <p style={{ margin: 0, fontSize: '0.75rem', color: '#6b7280' }}>{wallet_address}</p>
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
                  gap: '0.5rem',
                  boxShadow: '0 4px 12px rgba(239, 68, 68, 0.3)'
                }}
              >
                <X size={18} />
                {is_disconnecting ? 'Disconnecting...' : 'Disconnect Wallet'}
              </button>
            </div>
          ) : (
            // Connect options
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              <button
                onClick={handle_connect_wallet}
                disabled={is_connecting || !ton_connect_ui}
                style={{
                  padding: '1rem',
                  border: 'none',
                  borderRadius: '12px',
                  fontSize: '1rem',
                  fontWeight: '600',
                  cursor: (is_connecting || !ton_connect_ui) ? 'not-allowed' : 'pointer',
                  background: (is_connecting || !ton_connect_ui) ? '#9ca3af' : '#3b82f6',
                  color: 'white',
                  width: '100%',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '0.5rem',
                  boxShadow: '0 4px 12px rgba(59, 130, 246, 0.3)'
                }}
              >
                <Wallet size={18} />
                {is_connecting ? 'Connecting...' : 'Connect Existing Wallet'}
              </button>

              <button
                onClick={handle_create_wallet}
                style={{
                  padding: '1rem',
                  border: '2px solid #3b82f6',
                  borderRadius: '12px',
                  fontSize: '1rem',
                  fontWeight: '600',
                  cursor: 'pointer',
                  background: 'white',
                  color: '#3b82f6',
                  width: '100%',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '0.5rem',
                  boxShadow: '0 2px 8px rgba(59, 130, 246, 0.2)'
                }}
              >
                <Plus size={18} />
                Create New Wallet (Tonkeeper)
                <ExternalLink size={16} />
              </button>

              <p style={{
                fontSize: '0.75rem',
                color: '#6b7280',
                textAlign: 'center',
                margin: '0.5rem 0 0 0'
              }}>
                Tonkeeper is a secure and easy-to-use TON wallet. Click above to create a new wallet.
              </p>
            </div>
          )}
        </div>
      </div>
    </>
  );
};

