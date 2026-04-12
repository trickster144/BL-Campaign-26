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
    port: 10011,
    proxy: {
      '/api': {
        target: 'http://localhost:10012',
        changeOrigin: true
      }
    }
  }
})
