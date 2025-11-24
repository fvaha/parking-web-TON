/// <reference types="../vite-env" />

/**
 * TON API Service - Enhanced transaction tracking and monitoring
 * Uses TON Console API (https://tonconsole.com) for reliable transaction tracking
 * Documentation: https://docs.tonconsole.com/
 */

export interface TransactionStatus {
  status: 'pending' | 'confirmed' | 'failed' | 'not_found';
  tx_hash?: string;
  block_time?: number;
  amount_nano?: string;
  amount_ton?: number;
  from_address?: string;
  to_address?: string;
  error?: string;
}

export interface AccountInfo {
  address: string;
  balance_nano: string;
  balance_ton: number;
  last_activity?: number;
  state?: 'active' | 'uninitialized' | 'frozen';
}

export interface WalletInfo {
  address: string;
  balance_nano: string;
  balance_ton: number;
  is_wallet: boolean;
  wallet_type?: string;
  seqno?: number;
  last_activity?: number;
}

export interface WalletByPublicKey {
  address: string;
  wallet_type: string;
  balance_nano: string;
  balance_ton: number;
}

export interface TransactionEvent {
  type: 'transaction' | 'balance_change';
  tx_hash?: string;
  address: string;
  amount_nano: string;
  amount_ton: number;
  timestamp: number;
  status: 'pending' | 'confirmed' | 'failed';
}

export class TonApiService {
  private static instance: TonApiService;
  private readonly TON_DECIMALS = 9;
  private readonly API_BASE_URL = 'https://tonapi.io/v2';
  private readonly API_KEY: string | null = null;
  private transaction_polling_intervals: Map<string, ReturnType<typeof setInterval>> = new Map();
  private balance_monitors: Map<string, (balance: number) => void> = new Map();
  private balance_polling_intervals: Map<string, ReturnType<typeof setInterval>> = new Map();

  private constructor() {
    // TON Console API key - set via environment variable or use provided key
    // Get API key from: https://tonconsole.com/
    this.API_KEY = import.meta.env.VITE_TON_API_KEY || 'AEBVUIEGT7B33CIAAAACRU7AU3R4JEQTEINL56KMISTCZ54TFTMMIBJ5J2AFFHBB42QRAKQ';
  }

  static getInstance(): TonApiService {
    if (!TonApiService.instance) {
      TonApiService.instance = new TonApiService();
    }
    return TonApiService.instance;
  }

  /**
   * Get account information including balance
   */
  async getAccountInfo(address: string): Promise<{ success: boolean; account?: AccountInfo; error?: string }> {
    try {
      const normalized_address = this.normalizeAddress(address);
      const url = `${this.API_BASE_URL}/accounts/${encodeURIComponent(normalized_address)}`;
      
      const headers: HeadersInit = {
        'Accept': 'application/json'
      };
      
      if (this.API_KEY) {
        headers['Authorization'] = `Bearer ${this.API_KEY}`;
      }

      const response = await fetch(url, {
        method: 'GET',
        headers
      });

      if (!response.ok) {
        if (response.status === 404) {
          return { success: false, error: 'Account not found' };
        }
        throw new Error(`API error: ${response.status} ${response.statusText}`);
      }

      const data = await response.json();
      
      // Extract balance from response
      let balance_nano = '0';
      if (data?.balance) {
        balance_nano = data.balance.toString();
      } else if (data?.result?.balance) {
        balance_nano = data.result.balance.toString();
      }

      const balance_ton = parseFloat(balance_nano) / Math.pow(10, this.TON_DECIMALS);

      return {
        success: true,
        account: {
          address: normalized_address,
          balance_nano,
          balance_ton,
          last_activity: data?.last_activity,
          state: data?.state || 'active'
        }
      };
    } catch (error: any) {
      console.error('Error getting account info:', error);
      return {
        success: false,
        error: error.message || 'Failed to get account information'
      };
    }
  }

  /**
   * Get transaction by hash
   */
  async getTransaction(tx_hash: string): Promise<{ success: boolean; transaction?: TransactionStatus; error?: string }> {
    try {
      // Check if it's a BOC (Bag of Cells) - if so, parse it first
      if (tx_hash.length > 100 || tx_hash.includes('=')) {
        // It's likely a BOC, try to parse it
        const parse_result = await this.parseBoc(tx_hash);
        if (parse_result.success && parse_result.tx_hash) {
          tx_hash = parse_result.tx_hash;
        }
      }

      const url = `${this.API_BASE_URL}/blockchain/transactions/${encodeURIComponent(tx_hash)}`;
      
      const headers: HeadersInit = {
        'Accept': 'application/json'
      };
      
      if (this.API_KEY) {
        headers['Authorization'] = `Bearer ${this.API_KEY}`;
      }

      const response = await fetch(url, {
        method: 'GET',
        headers
      });

      if (!response.ok) {
        if (response.status === 404) {
          return {
            success: true,
            transaction: {
              status: 'not_found',
              tx_hash,
              error: 'Transaction not found on blockchain'
            }
          };
        }
        throw new Error(`API error: ${response.status} ${response.statusText}`);
      }

      const data = await response.json();

      // Extract transaction details
      const in_msg = data?.in_msg || data?.transaction?.in_msg;
      const out_msgs = data?.out_msgs || data?.transaction?.out_msgs || [];

      // Check if transaction is successful
      const success = data?.success !== false && data?.transaction?.success !== false;
      
      if (!success) {
        return {
          success: true,
          transaction: {
            status: 'failed',
            tx_hash,
            error: 'Transaction failed on blockchain'
          }
        };
      }

      // Get amount from incoming message
      let amount_nano = '0';
      let from_address = '';
      let to_address = '';

      if (in_msg) {
        amount_nano = (in_msg.value || 0).toString();
        from_address = in_msg.source?.address || '';
        to_address = in_msg.destination?.address || '';
      }

      const amount_ton = parseFloat(amount_nano) / Math.pow(10, this.TON_DECIMALS);
      const block_time = data?.utime || data?.transaction?.utime || Date.now() / 1000;

      return {
        success: true,
        transaction: {
          status: 'confirmed',
          tx_hash,
          block_time,
          amount_nano,
          amount_ton,
          from_address,
          to_address
        }
      };
    } catch (error: any) {
      console.error('Error getting transaction:', error);
      return {
        success: false,
        error: error.message || 'Failed to get transaction'
      };
    }
  }

  /**
   * Parse BOC (Bag of Cells) to extract transaction hash
   */
  private async parseBoc(boc: string): Promise<{ success: boolean; tx_hash?: string; error?: string }> {
    try {
      const url = `${this.API_BASE_URL}/blockchain/parse-boc?boc=${encodeURIComponent(boc)}`;
      
      const headers: HeadersInit = {
        'Accept': 'application/json'
      };
      
      if (this.API_KEY) {
        headers['Authorization'] = `Bearer ${this.API_KEY}`;
      }

      const response = await fetch(url, {
        method: 'GET',
        headers
      });

      if (!response.ok) {
        return { success: false, error: 'Failed to parse BOC' };
      }

      const data = await response.json();
      
      // Extract transaction hash from parsed BOC
      const tx_hash = data?.hash || data?.result?.hash;
      
      if (tx_hash) {
        return { success: true, tx_hash };
      }

      return { success: false, error: 'Transaction hash not found in BOC' };
    } catch (error: any) {
      return { success: false, error: error.message || 'Failed to parse BOC' };
    }
  }

  /**
   * Search for transactions by account address
   */
  async searchTransactions(
    address: string,
    limit: number = 10,
    before_lt?: string
  ): Promise<{ success: boolean; transactions?: TransactionStatus[]; error?: string }> {
    try {
      const normalized_address = this.normalizeAddress(address);
      let url = `${this.API_BASE_URL}/blockchain/accounts/${encodeURIComponent(normalized_address)}/transactions?limit=${limit}`;
      
      if (before_lt) {
        url += `&before_lt=${encodeURIComponent(before_lt)}`;
      }

      const headers: HeadersInit = {
        'Accept': 'application/json'
      };
      
      if (this.API_KEY) {
        headers['Authorization'] = `Bearer ${this.API_KEY}`;
      }

      const response = await fetch(url, {
        method: 'GET',
        headers
      });

      if (!response.ok) {
        throw new Error(`API error: ${response.status} ${response.statusText}`);
      }

      const data = await response.json();
      const transactions_data = data?.transactions || data?.result || [];

      const transactions: TransactionStatus[] = transactions_data.map((tx: any) => {
        const in_msg = tx.in_msg;
        let amount_nano = '0';
        let from_address = '';
        let to_address = '';

        if (in_msg) {
          amount_nano = (in_msg.value || 0).toString();
          from_address = in_msg.source?.address || '';
          to_address = in_msg.destination?.address || '';
        }

        const amount_ton = parseFloat(amount_nano) / Math.pow(10, this.TON_DECIMALS);

        return {
          status: tx.success !== false ? 'confirmed' : 'failed',
          tx_hash: tx.hash || tx.transaction_hash,
          block_time: tx.utime,
          amount_nano,
          amount_ton,
          from_address,
          to_address
        };
      });

      return { success: true, transactions };
    } catch (error: any) {
      console.error('Error searching transactions:', error);
      return {
        success: false,
        error: error.message || 'Failed to search transactions'
      };
    }
  }

  /**
   * Monitor transaction status with polling
   * Returns a function to stop monitoring
   */
  monitorTransaction(
    tx_hash: string,
    onUpdate: (status: TransactionStatus) => void,
    interval_ms: number = 5000,
    max_attempts: number = 60
  ): () => void {
    let attempts = 0;
    let last_status: TransactionStatus | null = null;

    const checkTransaction = async () => {
      attempts++;

      const result = await this.getTransaction(tx_hash);
      
      if (result.success && result.transaction) {
        const status = result.transaction;
        
        // Only call callback if status changed
        if (!last_status || last_status.status !== status.status) {
          onUpdate(status);
          last_status = status;
        }

        // Stop monitoring if transaction is confirmed or failed
        if (status.status === 'confirmed' || status.status === 'failed') {
          this.stopMonitoringTransaction(tx_hash);
          return;
        }
      }

      // Stop after max attempts
      if (attempts >= max_attempts) {
        this.stopMonitoringTransaction(tx_hash);
        if (last_status) {
          onUpdate({ ...last_status, status: 'not_found', error: 'Transaction monitoring timeout' });
        }
      }
    };

    // Start monitoring immediately
    checkTransaction();

    // Set up polling interval
    const interval = setInterval(checkTransaction, interval_ms);
    this.transaction_polling_intervals.set(tx_hash, interval);

    // Return stop function
    return () => this.stopMonitoringTransaction(tx_hash);
  }

  /**
   * Stop monitoring a transaction
   */
  stopMonitoringTransaction(tx_hash: string): void {
    const interval = this.transaction_polling_intervals.get(tx_hash);
    if (interval) {
      clearInterval(interval);
      this.transaction_polling_intervals.delete(tx_hash);
    }
  }

  /**
   * Monitor account balance with polling
   * Returns a function to stop monitoring
   */
  monitorBalance(
    address: string,
    onUpdate: (balance: number) => void,
    interval_ms: number = 10000
  ): () => void {
    // Store callback
    this.balance_monitors.set(address, onUpdate);

    const checkBalance = async () => {
      const result = await this.getAccountInfo(address);
      if (result.success && result.account) {
        onUpdate(result.account.balance_ton);
      }
    };

    // Check immediately
    checkBalance();

    // Set up polling interval
    const interval = setInterval(checkBalance, interval_ms);
    this.balance_polling_intervals.set(address, interval);

    // Return stop function
    return () => {
      this.balance_monitors.delete(address);
      const interval = this.balance_polling_intervals.get(address);
      if (interval) {
        clearInterval(interval);
        this.balance_polling_intervals.delete(address);
      }
    };
  }

  /**
   * Verify transaction matches expected criteria
   */
  async verifyTransaction(
    tx_hash: string,
    expected_amount_ton: number,
    expected_to_address: string,
    expected_from_address?: string
  ): Promise<{ success: boolean; verified: boolean; transaction?: TransactionStatus; error?: string }> {
    const result = await this.getTransaction(tx_hash);

    if (!result.success || !result.transaction) {
      return {
        success: false,
        verified: false,
        error: result.error || 'Failed to get transaction'
      };
    }

    const tx = result.transaction;

    if (tx.status !== 'confirmed') {
      return {
        success: true,
        verified: false,
        transaction: tx,
        error: `Transaction status: ${tx.status}`
      };
    }

    // Verify amount (allow small tolerance for fees)
    const expected_nano = expected_amount_ton * Math.pow(10, this.TON_DECIMALS);
    const actual_nano = parseFloat(tx.amount_nano || '0');
    const tolerance = 0.001 * Math.pow(10, this.TON_DECIMALS); // 0.001 TON tolerance

    if (Math.abs(actual_nano - expected_nano) > tolerance) {
      return {
        success: true,
        verified: false,
        transaction: tx,
        error: `Amount mismatch. Expected: ${expected_amount_ton} TON, Got: ${tx.amount_ton} TON`
      };
    }

    // Verify recipient address
    const normalized_expected_to = this.normalizeAddress(expected_to_address);
    const normalized_actual_to = this.normalizeAddress(tx.to_address || '');

    if (normalized_actual_to !== normalized_expected_to) {
      return {
        success: true,
        verified: false,
        transaction: tx,
        error: 'Recipient address mismatch'
      };
    }

    // Verify sender address if provided
    if (expected_from_address) {
      const normalized_expected_from = this.normalizeAddress(expected_from_address);
      const normalized_actual_from = this.normalizeAddress(tx.from_address || '');

      if (normalized_actual_from !== normalized_expected_from) {
        return {
          success: true,
          verified: false,
          transaction: tx,
          error: 'Sender address mismatch'
        };
      }
    }

    return {
      success: true,
      verified: true,
      transaction: tx
    };
  }

  /**
   * Normalize TON address for comparison
   */
  private normalizeAddress(address: string): string {
    if (!address) return '';
    // Remove whitespace and convert to lowercase
    return address.trim().toLowerCase();
  }

  /**
   * Get wallet information (human-friendly, without low-level details)
   * Uses TON API Wallet service: https://docs.tonconsole.com/tonapi/rest-api/wallet
   */
  async getWalletInfo(address: string): Promise<{ success: boolean; wallet?: WalletInfo; error?: string }> {
    try {
      const normalized_address = this.normalizeAddress(address);
      const url = `${this.API_BASE_URL}/wallet/${encodeURIComponent(normalized_address)}`;
      
      const headers: HeadersInit = {
        'Accept': 'application/json'
      };
      
      if (this.API_KEY) {
        headers['Authorization'] = `Bearer ${this.API_KEY}`;
      }

      const response = await fetch(url, {
        method: 'GET',
        headers
      });

      if (!response.ok) {
        if (response.status === 404) {
          return { success: false, error: 'Wallet not found' };
        }
        throw new Error(`API error: ${response.status} ${response.statusText}`);
      }

      const data = await response.json();
      
      // Extract wallet information
      const balance_nano = (data?.balance || data?.result?.balance || '0').toString();
      const balance_ton = parseFloat(balance_nano) / Math.pow(10, this.TON_DECIMALS);

      return {
        success: true,
        wallet: {
          address: normalized_address,
          balance_nano,
          balance_ton,
          is_wallet: data?.is_wallet || data?.result?.is_wallet || false,
          wallet_type: data?.wallet_type || data?.result?.wallet_type,
          seqno: data?.seqno || data?.result?.seqno,
          last_activity: data?.last_activity || data?.result?.last_activity
        }
      };
    } catch (error: any) {
      console.error('Error getting wallet info:', error);
      return {
        success: false,
        error: error.message || 'Failed to get wallet information'
      };
    }
  }

  /**
   * Get wallets by public key
   * Uses TON API Wallet service: https://docs.tonconsole.com/tonapi/rest-api/wallet
   */
  async getWalletsByPublicKey(public_key: string): Promise<{ success: boolean; wallets?: WalletByPublicKey[]; error?: string }> {
    try {
      const url = `${this.API_BASE_URL}/wallet/findByPublicKey?public_key=${encodeURIComponent(public_key)}`;
      
      const headers: HeadersInit = {
        'Accept': 'application/json'
      };
      
      if (this.API_KEY) {
        headers['Authorization'] = `Bearer ${this.API_KEY}`;
      }

      const response = await fetch(url, {
        method: 'GET',
        headers
      });

      if (!response.ok) {
        throw new Error(`API error: ${response.status} ${response.statusText}`);
      }

      const data = await response.json();
      const wallets_data = data?.wallets || data?.result || [];

      const wallets: WalletByPublicKey[] = wallets_data.map((wallet: any) => {
        const balance_nano = (wallet.balance || '0').toString();
        const balance_ton = parseFloat(balance_nano) / Math.pow(10, this.TON_DECIMALS);

        return {
          address: wallet.address,
          wallet_type: wallet.wallet_type || 'unknown',
          balance_nano,
          balance_ton
        };
      });

      return { success: true, wallets };
    } catch (error: any) {
      console.error('Error getting wallets by public key:', error);
      return {
        success: false,
        error: error.message || 'Failed to get wallets by public key'
      };
    }
  }

  /**
   * Get account seqno (sequence number)
   * Uses TON API Wallet service: https://docs.tonconsole.com/tonapi/rest-api/wallet
   */
  async getAccountSeqno(address: string): Promise<{ success: boolean; seqno?: number; error?: string }> {
    try {
      const normalized_address = this.normalizeAddress(address);
      const url = `${this.API_BASE_URL}/wallet/${encodeURIComponent(normalized_address)}/seqno`;
      
      const headers: HeadersInit = {
        'Accept': 'application/json'
      };
      
      if (this.API_KEY) {
        headers['Authorization'] = `Bearer ${this.API_KEY}`;
      }

      const response = await fetch(url, {
        method: 'GET',
        headers
      });

      if (!response.ok) {
        if (response.status === 404) {
          return { success: false, error: 'Account not found' };
        }
        throw new Error(`API error: ${response.status} ${response.statusText}`);
      }

      const data = await response.json();
      const seqno = data?.seqno || data?.result?.seqno || 0;

      return { success: true, seqno };
    } catch (error: any) {
      console.error('Error getting account seqno:', error);
      return {
        success: false,
        error: error.message || 'Failed to get account seqno'
      };
    }
  }

  /**
   * Verify wallet connection and get detailed information
   * Combines multiple API calls to verify wallet is valid and active
   */
  async verifyWalletConnection(address: string): Promise<{ 
    success: boolean; 
    verified: boolean; 
    wallet?: WalletInfo; 
    error?: string 
  }> {
    try {
      // First, get account info to check if account exists
      const account_result = await this.getAccountInfo(address);
      if (!account_result.success || !account_result.account) {
        return {
          success: true,
          verified: false,
          error: account_result.error || 'Account not found'
        };
      }

      // Then, get wallet-specific info
      const wallet_result = await this.getWalletInfo(address);
      if (!wallet_result.success) {
        // If wallet info fails, account might still be valid (could be a simple account)
        // Return account info as fallback
        return {
          success: true,
          verified: true,
          wallet: {
            address: account_result.account.address,
            balance_nano: account_result.account.balance_nano,
            balance_ton: account_result.account.balance_ton,
            is_wallet: false,
            last_activity: account_result.account.last_activity
          }
        };
      }

      // Wallet info retrieved successfully
      return {
        success: true,
        verified: true,
        wallet: wallet_result.wallet
      };
    } catch (error: any) {
      console.error('Error verifying wallet connection:', error);
      return {
        success: false,
        verified: false,
        error: error.message || 'Failed to verify wallet connection'
      };
    }
  }

  /**
   * Get blockchain explorer URL for transaction
   * @param tx_hash Transaction hash (can be BOC or regular hash)
   * @param explorer Optional: 'tonscan' (default), 'tonviewer', or 'tonapi'
   */
  /**
   * Convert BOC to transaction hash using TON API
   * This is async but we'll make it sync for URL generation
   * For now, we'll return the BOC and let the explorer handle it
   */
  private async convertBocToHash(boc: string): Promise<string | null> {
    try {
      // Try to parse BOC using TON API
      const response = await fetch(`https://tonapi.io/v2/blockchain/parse-boc?boc=${encodeURIComponent(boc)}`);
      if (response.ok) {
        const data = await response.json();
        if (data.hash) {
          return data.hash;
        }
        if (data.transaction?.hash) {
          return data.transaction.hash;
        }
      }
    } catch (error) {
      console.warn('Failed to convert BOC to hash:', error);
    }
    return null;
  }

  getTransactionExplorerUrl(tx_hash: string, explorer: 'tonscan' | 'tonviewer' | 'tonapi' = 'tonscan'): string {
    if (!tx_hash || tx_hash.trim().length === 0) {
      // Return search page if hash is invalid
      return 'https://tonscan.org/';
    }
    
    let hash = tx_hash.trim();
    
    // Remove any base64 padding if present
    hash = hash.replace(/=+$/, '');
    
    // Validate hash format
    // TON transaction hash can be:
    // - Hex string (64 chars) - preferred format for TONScan
    // - Base64 BOC (variable length, usually 100+ chars)
    // - Base64url (variable length)
    
    // Check if it's already a valid hex hash (64 chars)
    const isHexHash = /^[0-9a-fA-F]{64}$/.test(hash);
    
    // Check if it's a BOC format (base64/base64url)
    const isBOC = /^[A-Za-z0-9+\/_-]+$/.test(hash) && hash.length > 20 && !isHexHash;
    
    // Different explorers use different URL formats
    switch (explorer) {
      case 'tonscan':
        // TONScan prefers hex hash format (64 chars)
        // If we have BOC, TONScan might not accept it directly
        // In that case, we should use TONViewer or TONAPI which handle BOC better
        if (isHexHash) {
          return `https://tonscan.org/tx/${hash}`;
        } else if (isBOC) {
          // TONScan doesn't handle BOC well - use TONViewer instead for BOC
          // Or return search URL to let user search manually
          console.warn('BOC format detected - TONScan may not support this. Consider using TONViewer.');
          // Try TONViewer which handles BOC better
          return `https://tonviewer.com/transaction/${encodeURIComponent(hash)}`;
        } else {
          // Invalid format - return search page
          console.warn('Invalid transaction hash format:', hash.substring(0, 50));
          return 'https://tonscan.org/';
        }
      case 'tonviewer':
        // TONViewer handles both hex and BOC formats
        return `https://tonviewer.com/transaction/${encodeURIComponent(hash)}`;
      case 'tonapi':
        // TONAPI handles both hex and BOC formats
        return `https://tonapi.io/transaction/${encodeURIComponent(hash)}`;
      default:
        if (isHexHash) {
          return `https://tonscan.org/tx/${hash}`;
        } else if (isBOC) {
          // Default to TONViewer for BOC as it handles it better
          return `https://tonviewer.com/transaction/${encodeURIComponent(hash)}`;
        } else {
          return 'https://tonscan.org/';
        }
    }
  }

  /**
   * Get blockchain explorer URL for address
   */
  getAddressExplorerUrl(address: string, explorer: 'tonscan' | 'tonviewer' | 'tonapi' = 'tonscan'): string {
    const normalized_address = this.normalizeAddress(address);
    
    switch (explorer) {
      case 'tonscan':
        return `https://tonscan.org/address/${encodeURIComponent(normalized_address)}`;
      case 'tonviewer':
        return `https://tonviewer.com/${encodeURIComponent(normalized_address)}`;
      case 'tonapi':
        return `https://tonapi.io/account/${encodeURIComponent(normalized_address)}`;
      default:
        return `https://tonscan.org/address/${encodeURIComponent(normalized_address)}`;
    }
  }

  /**
   * Clean up all monitoring
   */
  cleanup(): void {
    // Stop all transaction monitoring
    for (const tx_hash of this.transaction_polling_intervals.keys()) {
      this.stopMonitoringTransaction(tx_hash);
    }

    // Stop all balance monitoring
    for (const address of this.balance_polling_intervals.keys()) {
      const interval = this.balance_polling_intervals.get(address);
      if (interval) {
        clearInterval(interval);
      }
    }
    this.balance_polling_intervals.clear();
    this.balance_monitors.clear();
  }
}

