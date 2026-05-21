import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    plugins: [vue()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
      },
    },
    server: {
      // host: true → listen di 0.0.0.0 supaya bisa diakses dari HP/device lain di LAN.
      // Override via env DEV_HOST (mis. DEV_HOST=192.168.1.7) kalau perlu pin ke IP tertentu.
      host: env.DEV_HOST || true,
      port: Number(env.DEV_PORT) || 5173,
      strictPort: false,
      // Vite 5: izinkan akses lewat hostname / IP LAN (selain localhost).
      // true = terima semua host (cocok utk dev di LAN). Ganti ke array kalau mau lebih ketat,
      // mis. allowedHosts: ['192.168.1.7', 'kantor.local']
      allowedHosts: true,
      proxy: env.VITE_USE_PROXY === 'true' ? {
        '/api': {
          // Backend tetap dipanggil via localhost dari sisi PC (tempat Vite jalan),
          // jadi HP cukup buka http://<IP-PC>:5173 — request /api di-proxy ke sini.
          target: env.VITE_API_BASE_URL || 'http://localhost:8080',
          changeOrigin: true,
          secure: false,
        },
      } : undefined,
    },
    build: {
      outDir: 'dist',
      sourcemap: false,
      rollupOptions: {
        output: {
          manualChunks: {
            vendor: ['vue', 'vue-router', 'pinia', 'axios'],
            charts: ['chart.js', 'vue-chartjs'],
          },
        },
      },
    },
  }
})