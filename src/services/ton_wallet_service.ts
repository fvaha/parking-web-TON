/// <reference types="../vite-env" />
import { TonApiService } from './ton_api_service';

/**
 * TON Wallet Service - Manages wallet address input and validation
 * Uses TON Console API for wallet verification
 */
export class TonWalletService {
  private static instance: TonWalletService;
  private walletAddress: string | null = null;
  private tonApiService: TonApiService;

  private constructor() {
    this.tonApiService = TonApiService.getInstance();
  }

  static getInstance(): TonWalletService {
    if (!TonWalletService.instance) {
      TonWalletService.instance = new TonWalletService();
    }
    return TonWalletService.instance;
  }

  /**
   * Set wallet address and save to localStorage
   */
  setWalletAddress(address: string): void {
    this.walletAddress = address;
    localStorage.setItem('ton_wallet_address', address);
  }

  /**
   * Get wallet address from memory or localStorage
   */
  getWalletAddress(): string | null {
    if (!this.walletAddress) {
      this.walletAddress = localStorage.getItem('ton_wallet_address');
    }
    return this.walletAddress;
  }

  /**
   * Check if wallet is connected
   */
  isWalletConnected(): boolean {
    return this.getWalletAddress() !== null;
  }

  /**
   * Validate wallet address format and check if it exists on blockchain
   */
  async validateWalletAddress(address: string): Promise<{ success: boolean; valid: boolean; error?: string }> {
    if (!address || address.trim().length === 0) {
      return { success: false, valid: false, error: 'Wallet address is required' };
    }

    const trimmedAddress = address.trim();

    // Basic format validation (TON addresses start with EQ, UQ, or 0:)
    const isValidFormat = /^(EQ|UQ|0:)[A-Za-z0-9_-]+$/.test(trimmedAddress);
    if (!isValidFormat) {
      return { success: true, valid: false, error: 'Invalid wallet address format' };
    }

    // Check if address exists on blockchain
    try {
      const accountInfo = await this.tonApiService.getAccountInfo(trimmedAddress);
      if (accountInfo.success && accountInfo.account) {
        return { success: true, valid: true };
      } else {
        return { success: true, valid: false, error: 'Wallet address not found on blockchain' };
      }
    } catch (error: any) {
      return { success: false, valid: false, error: error.message || 'Failed to validate wallet address' };
    }
  }

  /**
   * Check wallet balance
   */
  async checkBalance(): Promise<{ success: boolean; balance_ton?: number; error?: string }> {
    const address = this.getWalletAddress();
    if (!address) {
      return { success: false, error: 'Wallet address not set' };
    }

    const result = await this.tonApiService.getAccountInfo(address);
    if (result.success && result.account) {
      return {
        success: true,
        balance_ton: result.account.balance_ton
      };
    }

    return {
      success: false,
      error: result.error || 'Failed to get wallet balance'
    };
  }

  /**
   * Disconnect wallet (clear address)
   */
  disconnect(): void {
    this.walletAddress = null;
    localStorage.removeItem('ton_wallet_address');
  }

  /**
   * Get wallet info
   */
  async getWalletInfo(): Promise<{ success: boolean; address?: string; balance_ton?: number; error?: string }> {
    const address = this.getWalletAddress();
    if (!address) {
      return { success: false, error: 'Wallet not connected' };
    }

    const balanceResult = await this.checkBalance();
    if (balanceResult.success) {
      return {
        success: true,
        address: address,
        balance_ton: balanceResult.balance_ton
      };
    }

    return {
      success: false,
      error: balanceResult.error || 'Failed to get wallet info'
    };
  }
}

