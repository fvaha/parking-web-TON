/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_TON_RECIPIENT_ADDRESS?: string;
  readonly VITE_GOOGLE_MAPS_API_KEY?: string;
  readonly VITE_ADMIN_USERNAME?: string;
  readonly VITE_ADMIN_PASSWORD?: string;
  readonly VITE_API_KEY?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

declare module 'vite' {
  interface ImportMetaEnv {
    readonly VITE_TON_RECIPIENT_ADDRESS?: string;
    readonly VITE_GOOGLE_MAPS_API_KEY?: string;
    readonly VITE_ADMIN_USERNAME?: string;
    readonly VITE_ADMIN_PASSWORD?: string;
  }
}
