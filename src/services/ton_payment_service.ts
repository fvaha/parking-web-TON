/// <reference types="../vite-env" />
import { TonConnectUI } from '@tonconnect/ui-react';
import type { ParkingSpace, ParkingZone } from '../types';
import { TonApiService, type TransactionStatus, type WalletInfo } from './ton_api_service';
import { TonWalletService } from './ton_wallet_service';

export class TonPaymentService {
  private static instance: TonPaymentService;
  private tonApiService: TonApiService;
  private tonWalletService: TonWalletService;
  private tonConnectUI: TonConnectUI | null = null;
  // TON wallet address for receiving payments
  // Bounceable format (EQ...) - recommended for receiving payments
  // User-friendly: UQBahXxg
  // Raw format: 0:5a857c6037c1
  // Can be set in .env file as VITE_TON_RECIPIENT_ADDRESS
  private readonly RECIPIENT_ADDRESS = (import.meta.env.VITE_TON_RECIPIENT_ADDRESS as string | undefined) || 'EQBahXxgN8ErwSBkCGEyuPEzg3-PdeodtGTbpSzGNjKs6OgV';
  private readonly TON_DECIMALS = 9; // TON has 9 decimal places

  private constructor() {
    this.tonApiService = TonApiService.getInstance();
    this.tonWalletService = TonWalletService.getInstance();
  }

  static getInstance(): TonPaymentService {
    if (!TonPaymentService.instance) {
      TonPaymentService.instance = new TonPaymentService();
    }
    return TonPaymentService.instance;
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

  // Check wallet balance using TON Console API
  async checkWalletBalance(wallet_address: string): Promise<{ success: boolean; balance_ton?: number; error?: string }> {
    if (!wallet_address) {
      return { success: false, error: 'Wallet address is required' };
    }

    try {
      const result = await this.tonApiService.getAccountInfo(wallet_address);
      
      if (result.success && result.account) {
        return {
          success: true,
          balance_ton: result.account.balance_ton
        };
      }

      return {
        success: false,
        error: result.error || 'Failed to get account balance'
      };
    } catch (error: any) {
      console.error('Error checking wallet balance:', error);
      return {
        success: false,
        error: error.message || 'Failed to check wallet balance'
      };
    }
  }

  // Monitor wallet balance with real-time updates
  monitorWalletBalance(
    wallet_address: string,
    onUpdate: (balance: number) => void,
    interval_ms: number = 10000
  ): () => void {
    return this.tonApiService.monitorBalance(wallet_address, onUpdate, interval_ms);
  }

  // Generate payment link (TON transfer URL)
  generatePaymentLink(
    space: ParkingSpace,
    zone?: ParkingZone,
    duration_hours: number = 1,
    from_address?: string
  ): { url: string; amount_ton: number; amount_nano: string } {
    const amount_nano = this.calculatePriceInTon(space, zone, duration_hours);
    const amount_ton = parseFloat(amount_nano) / Math.pow(10, this.TON_DECIMALS);
    
    // Create TON transfer URL
    // Format: ton://transfer/<recipient>?amount=<nano>&text=<message>
    const payload = this.createReservationPayload(space.id, from_address || '');
    const transfer_url = `ton://transfer/${this.RECIPIENT_ADDRESS}?amount=${amount_nano}&text=${encodeURIComponent(`Parking reservation: Space ${space.id}`)}`;
    
    return {
      url: transfer_url,
      amount_ton: amount_ton,
      amount_nano: amount_nano
    };
  }

  // Create payload with reservation data
  // TON Connect SDK expects base64 encoded payload or empty string
  private createReservationPayload(space_id: string, user_address: string): string {
    // For TON Connect, we can use empty payload or base64 encoded data
    // Empty payload is simpler and works for most cases
    // If you need to include reservation data, use base64 encoding
    
    // Option 1: Empty payload (simplest, recommended)
    return '';
    
    // Option 2: Base64 encoded payload (if you need to include data)
    // const payload = JSON.stringify({
    //   type: 'parking_reservation',
    //   space_id: space_id,
    //   user_address: user_address || 'unknown',
    //   timestamp: Date.now()
    // });
    // const encoder = new TextEncoder();
    // const bytes = encoder.encode(payload);
    // const base64 = btoa(String.fromCharCode(...bytes));
    // return base64;
  }

  // Get wallet connection status
  async isWalletConnected(): Promise<boolean> {
    return this.tonWalletService.isWalletConnected();
  }

  // Get connected wallet address
  async getWalletAddress(): Promise<string | null> {
    return this.tonWalletService.getWalletAddress();
  }

  // Disconnect wallet
  async disconnectWallet(): Promise<{ success: boolean; error?: string }> {
    try {
      this.tonWalletService.disconnect();
      return { success: true };
    } catch (error: any) {
      console.error('Failed to disconnect wallet:', error);
      return {
        success: false,
        error: error.message || 'Failed to disconnect wallet'
      };
    }
  }

  // Verify transaction using TON Console API
  async verifyTransaction(
    tx_hash: string,
    expected_amount_ton: number,
    expected_to_address?: string,
    expected_from_address?: string
  ): Promise<{ success: boolean; verified: boolean; transaction?: TransactionStatus; error?: string }> {
    const to_address = expected_to_address || this.RECIPIENT_ADDRESS;
    return await this.tonApiService.verifyTransaction(
      tx_hash,
      expected_amount_ton,
      to_address,
      expected_from_address
    );
  }

  // Get transaction status
  async getTransactionStatus(tx_hash: string): Promise<{ success: boolean; transaction?: TransactionStatus; error?: string }> {
    const result = await this.tonApiService.getTransaction(tx_hash);
    return result;
  }

  // Monitor transaction with real-time updates
  monitorTransaction(
    tx_hash: string,
    onUpdate: (status: TransactionStatus) => void,
    interval_ms: number = 5000,
    max_attempts: number = 60
  ): () => void {
    return this.tonApiService.monitorTransaction(tx_hash, onUpdate, interval_ms, max_attempts);
  }

  // Cleanup all monitoring
  cleanup(): void {
    this.tonApiService.cleanup();
  }

  // Verify wallet connection using Wallet API
  async verifyWalletConnection(wallet_address: string): Promise<{ 
    success: boolean; 
    verified: boolean; 
    wallet?: WalletInfo; 
    error?: string 
  }> {
    return await this.tonApiService.verifyWalletConnection(wallet_address);
  }

  // Get wallet information
  async getWalletInfo(wallet_address: string): Promise<{ success: boolean; wallet?: WalletInfo; error?: string }> {
    return await this.tonApiService.getWalletInfo(wallet_address);
  }

  // Get account seqno
  async getAccountSeqno(wallet_address: string): Promise<{ success: boolean; seqno?: number; error?: string }> {
    return await this.tonApiService.getAccountSeqno(wallet_address);
  }

  // Get TonApiService instance (for accessing explorer URLs)
  getTonApiService(): TonApiService {
    return this.tonApiService;
  }

  // Get TonWalletService instance
  getTonWalletService(): TonWalletService {
    return this.tonWalletService;
  }

  // Set TonConnectUI instance (called from WalletConnectSheet)
  setTonConnectUI(ui: TonConnectUI): void {
    this.tonConnectUI = ui;
  }

  // Get TonConnectUI instance
  getTonConnectUI(): TonConnectUI | null {
    return this.tonConnectUI;
  }

  // Process payment using TON Connect UI
  async processPayment(
    space: ParkingSpace,
    zone?: ParkingZone,
    duration_hours: number = 1,
    onTransactionStatus?: (status: TransactionStatus) => void
  ): Promise<{ success: boolean; tx_hash?: string; error?: string; monitorTransaction?: () => void }> {
    // If TonConnectUI is not set, try to get it from wallet service or check if wallet is connected via address
    if (!this.tonConnectUI) {
      // Check if wallet is connected via address (from TonWalletService)
      const wallet_address = await this.getWalletAddress();
      if (!wallet_address) {
        return { success: false, error: 'Wallet not connected. Please connect your TON wallet first.' };
      }
      
      // If we have wallet address but no TonConnectUI, we can't send transaction
      // User needs to reconnect wallet to initialize TonConnectUI
      return { success: false, error: 'TON Connect UI not initialized. Please reconnect your wallet by opening the wallet management sheet.' };
    }

    try {
      // Check if wallet is connected
      const wallet = this.tonConnectUI.wallet;
      if (!wallet || !wallet.account) {
        return { success: false, error: 'Wallet not connected. Please connect your TON wallet first.' };
      }

      // Calculate payment amount
      const amount_nano = this.calculatePriceInTon(space, zone, duration_hours);
      const amount_ton = parseFloat(amount_nano) / Math.pow(10, this.TON_DECIMALS);

      // Check wallet balance BEFORE attempting payment
      // Note: If API key is not set or balance check fails, we'll still try to send transaction
      // The wallet itself will reject if balance is insufficient
      try {
        const balance_result = await this.checkWalletBalance(wallet.account.address);
        if (balance_result.success && balance_result.balance_ton !== undefined) {
          if (balance_result.balance_ton < amount_ton) {
            return {
              success: false,
              error: `Insufficient balance. You have ${balance_result.balance_ton.toFixed(3)} TON, but need ${amount_ton.toFixed(3)} TON for this reservation.`
            };
          }
        }
      } catch (balance_error) {
        // If balance check fails (e.g., API key issue), continue anyway
        // The wallet will reject the transaction if balance is insufficient
        console.warn('Balance check failed, continuing with transaction:', balance_error);
      }

      // Create transaction
      // TON Connect SDK requires payload to be base64 encoded or omitted entirely
      const payload = this.createReservationPayload(space.id, wallet.account.address);
      const message: any = {
        address: this.RECIPIENT_ADDRESS,
        amount: amount_nano
      };
      
      // Only include payload if it's not empty
      if (payload && payload.length > 0) {
        message.payload = payload;
      }
      
      const transaction = {
        messages: [message],
        validUntil: Math.floor(Date.now() / 1000) + 300 // 5 minutes
      };

      // Send transaction
      const result = await this.tonConnectUI.sendTransaction(transaction);
      const tx_hash = result.boc;

      // Start monitoring transaction if callback provided
      let stopMonitoring: (() => void) | undefined;
      if (onTransactionStatus) {
        stopMonitoring = this.tonApiService.monitorTransaction(
          tx_hash,
          onTransactionStatus,
          5000, // Check every 5 seconds
          60 // Max 60 attempts (5 minutes)
        );
      }

      return {
        success: true,
        tx_hash: tx_hash,
        monitorTransaction: stopMonitoring
      };
    } catch (error: any) {
      console.error('TON payment error:', error);
      
      // Provide user-friendly error messages
      let error_message = 'Payment failed';
      
      if (error.message) {
        if (error.message.includes('User rejects') || error.message.includes('reject')) {
          error_message = 'Transaction was rejected. Please make sure you have enough TON in your wallet and approve the transaction.';
        } else if (error.message.includes('insufficient')) {
          error_message = 'Insufficient balance. You don\'t have enough TON in your wallet.';
        } else {
          error_message = error.message;
        }
      }
      
      return {
        success: false,
        error: error_message
      };
    }
  }
}
