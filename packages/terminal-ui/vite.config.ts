import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: '../../assets', // Output directly to the `/assets` folder
    sourcemap: true, // Include sourcemaps for debugging
    emptyOutDir: true, // Clean the `/assets` folder before each build
    rollupOptions: {
      input: './src/main.tsx',
      output: {
        format: 'iife', // Immediately Invoked Function Expression for browser compatibility
        entryFileNames: 'js/main.js', // Place JavaScript in `/assets/js/`
        chunkFileNames: 'js/[name]-[hash].js', // Additional chunks in `/assets/js/`
        assetFileNames: 'css/[name][extname]', // Place CSS in `/assets/css/`
      },
    },
  },
  server: {
    port: 5173,
    fs: {
      allow: ['..'], // Allow serving files from parent directories
    },
  },
});
