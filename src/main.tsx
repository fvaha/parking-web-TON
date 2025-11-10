import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'

// Suppress Telegram WebView debug messages in production
if (typeof window !== 'undefined' && import.meta.env.PROD) {
  const originalLog = console.log;
  console.log = (...args: any[]) => {
    // Filter out Telegram WebView debug messages
    const message = args[0]?.toString() || '';
    if (message.includes('[Telegram.WebView]')) {
      return; // Suppress Telegram WebView debug messages
    }
    originalLog.apply(console, args);
  };
}

// Initialize Telegram Web App if available
if (typeof window !== 'undefined' && window.Telegram?.WebApp) {
  window.Telegram.WebApp.ready();
  window.Telegram.WebApp.expand();
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
