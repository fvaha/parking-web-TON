/// <reference types="../vite-env" />
import { TonConnectUI } from '@tonconnect/ui-react';
import type { ParkingSpace, ParkingZone } from '../types';

export class TonPaymentService {
  private static instance: TonPaymentService;
  private tonConnectUI: TonConnectUI | null = null;
  private is_initializing: boolean = false; // Flag to prevent multiple simultaneous initialization attempts
  private initialization_retry_timeout: ReturnType<typeof setTimeout> | null = null;
  // TON wallet address for receiving payments
  // Bounceable format (EQ...) - recommended for receiving payments
  // User-friendly: UQBahXxg
  // Raw format: 0:5a857c6037c1
  // Can be set in .env file as VITE_TON_RECIPIENT_ADDRESS
  private readonly RECIPIENT_ADDRESS = (import.meta.env.VITE_TON_RECIPIENT_ADDRESS as string | undefined) || 'EQBahXxgN8ErwSBkCGEyuPEzg3-PdeodtGTbpSzGNjKs6OgV';
  private readonly TON_DECIMALS = 9; // TON has 9 decimal places

  private constructor() {
    // Don't initialize immediately - wait for element to exist
  }

  static getInstance(): TonPaymentService {
    if (!TonPaymentService.instance) {
      TonPaymentService.instance = new TonPaymentService();
    }
    return TonPaymentService.instance;
  }

  private initializeTonConnect(retry_count: number = 0): boolean {
    // Prevent multiple simultaneous initialization attempts
    if (this.is_initializing) {
      console.log('[DEBUG] initializeTonConnect - already initializing, skipping');
      return false;
    }

    // Check if element exists before initializing
    if (typeof window === 'undefined') {
      console.log('[DEBUG] initializeTonConnect - window is undefined');
      return false;
    }

    // Check if DOM is ready
    if (document.readyState === 'loading') {
      console.log('[DEBUG] initializeTonConnect - DOM is still loading, will retry, retry_count:', retry_count);
      // Wait for DOM to be ready
      if (retry_count < 3) {
        this.is_initializing = true;
        setTimeout(() => {
          this.is_initializing = false;
          this.initializeTonConnect(retry_count + 1);
        }, 200);
      } else {
        this.is_initializing = false;
      }
      return false;
    }
    
    // Check if body exists
    if (!document.body) {
      console.log('[DEBUG] initializeTonConnect - document.body not available, will retry, retry_count:', retry_count);
      if (retry_count < 5 && !this.initialization_retry_timeout) {
        this.is_initializing = true;
        const delay = Math.min(200 * Math.pow(2, retry_count), 1000);
        this.initialization_retry_timeout = setTimeout(() => {
          this.initialization_retry_timeout = null;
          this.is_initializing = false;
          this.initializeTonConnect(retry_count + 1);
        }, delay);
      } else {
        this.is_initializing = false;
      }
      return false;
    }
    
    // Check if element exists, create it if it doesn't
    let buttonElement = document.getElementById('ton-connect-button');
    if (!buttonElement) {
      console.log('[DEBUG] initializeTonConnect - element not found, creating it, retry_count:', retry_count, 'body exists:', !!document.body);
      // Create the element if it doesn't exist
      // We already checked that body exists above, so it should be safe
      try {
        buttonElement = document.createElement('div');
        buttonElement.id = 'ton-connect-button';
        buttonElement.style.display = 'none';
        buttonElement.setAttribute('data-ton-connect', 'true');
        document.body.appendChild(buttonElement);
        console.log('[DEBUG] initializeTonConnect - element created and appended to body');
        // Verify it was created by querying again
        buttonElement = document.getElementById('ton-connect-button');
        if (!buttonElement) {
          console.error('[DEBUG] initializeTonConnect - element creation failed, element not found after append');
          // Try querySelector as fallback
          buttonElement = document.querySelector('#ton-connect-button');
          if (!buttonElement) {
            console.error('[DEBUG] initializeTonConnect - element not found even with querySelector');
            if (retry_count < 3 && !this.initialization_retry_timeout) {
              this.is_initializing = true;
              const delay = 100;
              this.initialization_retry_timeout = setTimeout(() => {
                this.initialization_retry_timeout = null;
                this.is_initializing = false;
                this.initializeTonConnect(retry_count + 1);
              }, delay);
            } else {
              this.is_initializing = false;
            }
            return false;
          }
        }
      } catch (error) {
        console.error('[DEBUG] initializeTonConnect - error creating element:', error);
        this.is_initializing = false;
        return false;
      }
    } else {
      console.log('[DEBUG] initializeTonConnect - element found in DOM');
    }

    // Clear any pending retry timeout
    if (this.initialization_retry_timeout) {
      clearTimeout(this.initialization_retry_timeout);
      this.initialization_retry_timeout = null;
    }

    try {
      if (this.tonConnectUI) {
        // Already initialized
        console.log('[DEBUG] initializeTonConnect - already initialized');
        this.is_initializing = false;
        return true;
      }

      this.is_initializing = true;
      console.log('[DEBUG] initializeTonConnect - creating new TonConnectUI instance');
      this.tonConnectUI = new TonConnectUI({
        manifestUrl: `${window.location.origin}/tonconnect-manifest.json`,
        buttonRootId: 'ton-connect-button'
      });
      
      console.log('[DEBUG] initializeTonConnect - TonConnectUI instance created successfully');
      
      // Listen for wallet connection changes
      this.tonConnectUI.onStatusChange((wallet) => {
        console.log('[DEBUG] TonPaymentService - Wallet status changed:', wallet ? 'connected' : 'disconnected');
        // This will be handled by components that use the service
      });
      
      this.is_initializing = false;
      return true;
    } catch (error) {
      console.error('[DEBUG] initializeTonConnect - Failed to initialize TON Connect:', error);
      this.is_initializing = false;
      // Retry if we haven't exceeded max retries
      if (retry_count < 3 && !this.initialization_retry_timeout) {
        const delay = Math.min(300 * Math.pow(2, retry_count), 1500);
        this.initialization_retry_timeout = setTimeout(() => {
          this.initialization_retry_timeout = null;
          this.initializeTonConnect(retry_count + 1);
        }, delay);
      }
      return false;
    }
  }

  // Public method to ensure initialization
  ensureInitialized(): boolean {
    if (this.tonConnectUI) {
      return true;
    }
    // Only start initialization if not already initializing
    if (!this.is_initializing) {
      return this.initializeTonConnect(0);
    }
    return false;
  }

  // Calculate price in TON based on zone pricing
  calculatePriceInTon(space: ParkingSpace, zone?: ParkingZone, duration_hours: number = 1): string {
    // Get hourly rate (default 2 TON if no zone)
    const hourly_rate = zone?.hourly_rate || 2.0;
    const price_in_ton = hourly_rate * duration_hours;
    
    // Convert to nanoTON (smallest unit)
    const nano_ton = Math.floor(price_in_ton * Math.pow(10, this.TON_DECIMALS));
    return nano_ton.toString();
  }

  // Check wallet balance
  async checkWalletBalance(wallet_address: string): Promise<{ success: boolean; balance_ton?: number; error?: string }> {
    if (!wallet_address) {
      return { success: false, error: 'Wallet address is required' };
    }

    try {
      // Normalize address - convert to raw format (0:...) for API
      let normalized_address = wallet_address;
      if (wallet_address.startsWith('EQ') || wallet_address.startsWith('UQ') || wallet_address.startsWith('kQ')) {
        // For now, use the address as-is - TON API should handle it
        normalized_address = wallet_address;
      }

      // Use TON API to get balance
      // Try tonapi.io first (more reliable)
      let api_url = `https://tonapi.io/v2/accounts/${encodeURIComponent(normalized_address)}`;
      
      try {
        const response = await fetch(api_url, {
          method: 'GET',
          headers: {
            'Accept': 'application/json'
          }
        });

        if (response.ok) {
          const data = await response.json();
          
          // Extract balance from response (in nanoTON)
          let balance_nano = 0;
          if (data?.balance) {
            balance_nano = parseInt(data.balance);
          } else if (data?.result?.balance) {
            balance_nano = parseInt(data.result.balance);
          }

          // Convert nanoTON to TON
          const balance_ton = balance_nano / Math.pow(10, this.TON_DECIMALS);
          
          return {
            success: true,
            balance_ton: balance_ton
          };
        }
      } catch (api_error) {
        console.warn('TON API (tonapi.io) failed, trying alternative:', api_error);
      }

      // Fallback: Try toncenter.com API
      api_url = `https://toncenter.com/api/v2/getAddressInformation?address=${encodeURIComponent(normalized_address)}`;
      
      try {
        const response = await fetch(api_url, {
          method: 'GET',
          headers: {
            'Accept': 'application/json'
          }
        });

        if (response.ok) {
          const data = await response.json();
          
          // Extract balance from response (in nanoTON)
          let balance_nano = 0;
          if (data?.result?.balance) {
            balance_nano = parseInt(data.result.balance);
          }

          // Convert nanoTON to TON
          const balance_ton = balance_nano / Math.pow(10, this.TON_DECIMALS);
          
          return {
            success: true,
            balance_ton: balance_ton
          };
        }
      } catch (api_error) {
        console.warn('TON API (toncenter.com) also failed:', api_error);
      }

      return { success: false, error: 'Failed to connect to TON API to check balance' };
    } catch (error: any) {
      console.error('Error checking wallet balance:', error);
      return {
        success: false,
        error: error.message || 'Failed to check wallet balance'
      };
    }
  }

  // Process payment for reservation
  async processPayment(
    space: ParkingSpace, 
    zone?: ParkingZone,
    duration_hours: number = 1
  ): Promise<{ success: boolean; tx_hash?: string; error?: string }> {
    // Ensure we have an instance (might be from fallback)
    if (!this.tonConnectUI) {
      console.log('[DEBUG] processPayment - tonConnectUI is null, trying to ensure initialization');
      this.ensureInitialized();
      if (!this.tonConnectUI) {
        return { success: false, error: 'TON Connect not initialized. Please connect your wallet first.' };
      }
    }

    try {
      // Check if wallet is connected
      // Use wallet property instead of getWallet() method
      const wallet = this.tonConnectUI.wallet;
      console.log('[DEBUG] processPayment - wallet check:', wallet ? 'exists' : 'null');
      if (!wallet) {
        return { success: false, error: 'Wallet not connected. Please connect your TON wallet first.' };
      }

      // Calculate payment amount
      const amount_nano = this.calculatePriceInTon(space, zone, duration_hours);
      const amount_ton = parseFloat(amount_nano) / Math.pow(10, this.TON_DECIMALS);
      
      // Step 1: Check wallet balance BEFORE attempting payment
      console.log('[DEBUG] Checking wallet balance before payment...');
      const balance_result = await this.checkWalletBalance(wallet.account.address);
      
      if (balance_result.success && balance_result.balance_ton !== undefined) {
        console.log(`[DEBUG] Wallet balance: ${balance_result.balance_ton} TON, Required: ${amount_ton} TON`);
        
        // Check if user has enough balance
        if (balance_result.balance_ton < amount_ton) {
          return {
            success: false,
            error: `Insufficient balance. You have ${balance_result.balance_ton.toFixed(3)} TON, but need ${amount_ton.toFixed(3)} TON for this reservation.`
          };
        }
      } else {
        // Could not check balance - warn user but allow payment attempt
        console.warn('[DEBUG] Could not check wallet balance:', balance_result.error);
        // Don't block payment, but warn user
        const proceed = window.confirm(
          `Could not verify wallet balance. You need ${amount_ton.toFixed(3)} TON for this reservation.\n\n` +
          `Do you want to proceed with payment?`
        );
        if (!proceed) {
          return { success: false, error: 'Payment cancelled by user' };
        }
      }
      
      // Step 2: Create and send transaction (BOC)
      console.log('[DEBUG] Creating transaction...');
      const transaction = {
        messages: [
          {
            address: this.RECIPIENT_ADDRESS,
            amount: amount_nano,
            payload: this.createReservationPayload(space.id, wallet.account.address)
          }
        ],
        validUntil: Math.floor(Date.now() / 1000) + 300 // 5 minutes
      };

      // Step 3: Send transaction
      console.log('[DEBUG] Sending transaction...');
      const result = await this.tonConnectUI.sendTransaction(transaction);
      
      // TON Connect returns BOC (Bag of Cells) which contains the transaction
      // Backend will extract transaction hash from BOC or search for matching transaction
      // by amount and recipient address
      const tx_hash = result.boc;
      console.log('[DEBUG] Transaction sent, BOC received:', tx_hash.substring(0, 50) + '...');
      
      return {
        success: true,
        tx_hash: tx_hash
      };
    } catch (error: any) {
      console.error('[DEBUG] TON payment error:', error);
      return {
        success: false,
        error: error.message || 'Payment failed'
      };
    }
  }

  // Create payload with reservation data
  private createReservationPayload(space_id: string, user_address: string): string {
    // Create a simple payload with reservation info
    const payload = JSON.stringify({
      type: 'parking_reservation',
      space_id: space_id,
      user_address: user_address,
      timestamp: Date.now()
    });
    
    // Convert to hex (browser-compatible)
    const encoder = new TextEncoder();
    const bytes = encoder.encode(payload);
    return Array.from(bytes)
      .map(byte => byte.toString(16).padStart(2, '0'))
      .join('');
  }

  // Get wallet connection status
  async isWalletConnected(): Promise<boolean> {
    // First check the instance
    if (this.tonConnectUI) {
      try {
        // Use wallet property instead of getWallet() method
        const wallet = this.tonConnectUI.wallet;
        if (wallet !== null && wallet !== undefined) {
          // Also verify it has account
          if (wallet && typeof wallet === 'object' && 'account' in wallet) {
            const account = (wallet as any).account;
            if (account && account.address) {
              return true;
            }
          }
        }
      } catch (error) {
        console.error('Error checking wallet from tonConnectUI instance:', error);
      }
    }
    
    // Fallback: Check localStorage (TonConnect stores wallet info there)
    try {
      // Try multiple possible storage keys
      const storage_keys = ['ton-connect-storage', 'tonconnect-storage', 'tonconnect'];
      
      for (const key of storage_keys) {
        const tonconnect_storage = localStorage.getItem(key);
        if (tonconnect_storage) {
          try {
            const storage_data = JSON.parse(tonconnect_storage);
            
            // Try different possible structures
            let stored_wallet: any = null;
            
            // Structure 1: storage_data.Connector.wallet
            if (storage_data?.Connector?.wallet) {
              stored_wallet = storage_data.Connector.wallet;
            }
            // Structure 2: storage_data.wallet
            else if (storage_data?.wallet) {
              stored_wallet = storage_data.wallet;
            }
            // Structure 3: storage_data directly
            else if (storage_data?.account) {
              stored_wallet = storage_data;
            }
            // Structure 4: Check all keys in storage_data
            else {
              // Try to find wallet in any key
              for (const storage_key in storage_data) {
                if (storage_data[storage_key]?.wallet) {
                  stored_wallet = storage_data[storage_key].wallet;
                  break;
                }
                if (storage_data[storage_key]?.account) {
                  stored_wallet = storage_data[storage_key];
                  break;
                }
              }
            }
            
            if (stored_wallet && stored_wallet.account && stored_wallet.account.address) {
              console.log('isWalletConnected: Found wallet in localStorage key:', key);
              return true;
            }
          } catch (parseError) {
            // Continue to next key
            continue;
          }
        }
      }
    } catch (error) {
      console.error('Error checking localStorage for wallet:', error);
    }
    
    return false;
  }

  // Get connected wallet address
  async getWalletAddress(): Promise<string | null> {
    if (!this.tonConnectUI) return null;
    try {
      // Use wallet property instead of getWallet() method
      const wallet = this.tonConnectUI.wallet;
      return wallet?.account.address || null;
    } catch {
      return null;
    }
  }

  // Disconnect wallet
  async disconnectWallet(): Promise<{ success: boolean; error?: string }> {
    if (!this.tonConnectUI) {
      return { success: false, error: 'TON Connect not initialized' };
    }

    try {
      await this.tonConnectUI.disconnect();
      return { success: true };
    } catch (error: any) {
      console.error('Failed to disconnect wallet:', error);
      return {
        success: false,
        error: error.message || 'Failed to disconnect wallet'
      };
    }
  }

  // Get TonConnectUI instance (for advanced usage)
  getTonConnectUI(): TonConnectUI | null {
    return this.tonConnectUI;
  }

  // Set TonConnectUI instance (for fallback instances from WalletBottomSheet)
  setTonConnectUI(instance: TonConnectUI): void {
    console.log('[DEBUG] TonPaymentService - Setting TonConnectUI instance from fallback');
    this.tonConnectUI = instance;
  }
}

