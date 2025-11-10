/// <reference types="../vite-env" />
import { TonConnectUI } from '@tonconnect/ui-react';
import type { ParkingSpace, ParkingZone } from '../types';

export class TonPaymentService {
  private static instance: TonPaymentService;
  private tonConnectUI: TonConnectUI | null = null;
  // TON wallet address for receiving payments
  // Bounceable format (EQ...) - recommended for receiving payments
  // User-friendly: UQBahXxgN8ErwSBkCGEyuPEzg3-PdeodtGTbpSzGNjKs6LXQ
  // Raw format: 0:5a857c6037c12bc12064086132b8f133837f8f75ea1db464dba52cc63632ace8
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

  private initializeTonConnect() {
    // Check if element exists before initializing
    if (typeof window === 'undefined') return;
    
    const buttonElement = document.getElementById('ton-connect-button');
    if (!buttonElement) {
      // Element doesn't exist yet, will be initialized when needed
      return;
    }

    try {
      if (this.tonConnectUI) {
        // Already initialized
        return;
      }

      this.tonConnectUI = new TonConnectUI({
        manifestUrl: `${window.location.origin}/tonconnect-manifest.json`,
        buttonRootId: 'ton-connect-button'
      });
      
      // Listen for wallet connection changes
      this.tonConnectUI.onStatusChange((wallet) => {
        console.log('Wallet status changed:', wallet);
      });
    } catch (error) {
      console.error('Failed to initialize TON Connect:', error);
    }
  }

  // Public method to ensure initialization
  ensureInitialized() {
    if (!this.tonConnectUI) {
      this.initializeTonConnect();
    }
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

  // Process payment for reservation
  async processPayment(
    space: ParkingSpace, 
    zone?: ParkingZone,
    duration_hours: number = 1
  ): Promise<{ success: boolean; tx_hash?: string; error?: string }> {
    if (!this.tonConnectUI) {
      return { success: false, error: 'TON Connect not initialized' };
    }

    try {
      // Check if wallet is connected
      // Use wallet property instead of getWallet() method
      const wallet = this.tonConnectUI.wallet;
      if (!wallet) {
        return { success: false, error: 'Wallet not connected. Please connect your TON wallet first.' };
      }

      // Calculate payment amount
      const amount_nano = this.calculatePriceInTon(space, zone, duration_hours);
      
      // Create transaction
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

      // Send transaction
      const result = await this.tonConnectUI.sendTransaction(transaction);
      
      // TON Connect returns BOC (Bag of Cells) which contains the transaction
      // Backend will extract transaction hash from BOC or search for matching transaction
      // by amount and recipient address
      const tx_hash = result.boc;
      
      return {
        success: true,
        tx_hash: tx_hash
      };
    } catch (error: any) {
      console.error('TON payment error:', error);
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
    if (!this.tonConnectUI) return false;
    try {
      // Use wallet property instead of getWallet() method
      const wallet = this.tonConnectUI.wallet;
      return wallet !== null;
    } catch {
      return false;
    }
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
}

