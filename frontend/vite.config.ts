import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  build: {
    rollupOptions: {
      onwarn: () => {
        // Suppress warnings during build
      }
    }
  },
  server: {
    port: 3000,
    proxy: {
      '/api': {
        target: '10.0.0.28:10011',
        changeOrigin: true
      }
    }
  }
})