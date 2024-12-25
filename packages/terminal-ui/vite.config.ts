import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
	plugins: [react()],
	build: {
		cssCodeSplit: true, // Ensures CSS is extracted into separate files
		outDir: '../../assets', // Output directory
		rollupOptions: {
			input: './src/main.tsx',
			output: {
				entryFileNames: 'js/main.js', // JavaScript output location
				chunkFileNames: 'js/[name]-[hash].js', // Chunk files
				assetFileNames: (assetInfo) => {
					// Handle CSS files separately
					if (assetInfo.name?.endsWith('.css')) {
						return 'css/[name][extname]';
					}
					return 'assets/[name][extname]'; // Other assets (e.g., images, fonts)
				},
			},
		},
	},
});
