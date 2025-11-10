import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  base: '/', // Base public path when served
  build: {
    outDir: 'dist',
    emptyOutDir: true, // Empty first, then copy script adds PHP files
    assetsDir: 'assets',
  },
  server: {
    port: 5173,
    open: true
  },
  publicDir: 'public'
})

