import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => ({
  plugins: [react()],
  build: {
    outDir: mode === 'production' ? '../../assets' : 'dist', // Output to `/assets` for production, `dist` for dev builds
    sourcemap: mode !== 'production', // Enable sourcemaps in non-production mode
    emptyOutDir: true, // Clean output directory before builds
    rollupOptions: {
      input: './src/main.tsx',
      output: {
        format: 'iife', // Browser-compatible format for production
        entryFileNames: 'js/main.js', // JavaScript file location
        chunkFileNames: 'js/[name]-[hash].js', // Additional chunks
        assetFileNames: 'css/[name][extname]', // CSS file location
      },
    },
  },
  server: {
    port: 5173, // Use this port for the dev server
    fs: {
      allow: ['..'], // Allow accessing files outside the project root
    },
  },
}));
