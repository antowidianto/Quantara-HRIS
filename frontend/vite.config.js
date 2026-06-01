import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

/**
 * Vite config — Sprint E / D5 (bundle analyzer + code-splitting agresif).
 *
 * Bundle analyzer di-load lazily lewat env `ANALYZE=true`. Output:
 *   dist/stats.html (treemap) — buka di browser setelah `npm run build:analyze`.
 *
 * Plugin `rollup-plugin-visualizer` belum jadi devDependency wajib supaya
 * developer bisa skip kalau tidak butuh; dynamic import di bawah akan
 * silently skip kalau package tidak ter-install. Untuk install:
 *   npm install --save-dev rollup-plugin-visualizer@5.12.0
 *
 * manualChunks dipecah per-domain supaya:
 *   - vendor library (vue/router/pinia/axios) jadi 1 chunk yang stabil
 *     antar deploy (cache hit ratio tinggi)
 *   - charts, dayjs, heroicons di-split supaya tidak ikut bundle awal
 *   - composables + components dipakai cross-route, masuk vendor utility
 */
export default defineConfig(async ({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  const plugins = [vue()]

  // Bundle analyzer — opt-in via:
  //   - mode='analyze' (`npm run build:analyze`)
  //   - env ANALYZE=true (`ANALYZE=true npm run build` di shell yang support)
  const wantAnalyze = mode === 'analyze' || env.ANALYZE === 'true'
  if (wantAnalyze) {
    try {
      const { visualizer } = await import('rollup-plugin-visualizer')
      plugins.push(visualizer({
        filename: 'dist/stats.html',
        template: 'treemap',
        gzipSize: true,
        brotliSize: true,
        open: false,
      }))
      // eslint-disable-next-line no-console
      console.info('[vite] Bundle analyzer enabled — output: dist/stats.html')
    } catch (e) {
      // eslint-disable-next-line no-console
      console.warn('[vite] ANALYZE=true tapi rollup-plugin-visualizer tidak ter-install. Run:')
      // eslint-disable-next-line no-console
      console.warn('       npm install --save-dev rollup-plugin-visualizer@5.12.0')
    }
  }

  return {
    plugins,
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
      // Naikan dari default 500KB → 700KB. View HRIS punya beberapa form
      // besar (EmployeeModal 1.5K baris) yang sulit dipecah lebih kecil
      // tanpa breaking change. Sprint E refactor (A8) target: turun ke
      // ≤ 300KB per chunk view.
      chunkSizeWarningLimit: 700,
      rollupOptions: {
        output: {
          // Manual chunk strategy — group library yang stabil supaya browser
          // cache valid antar deploy (vendor jarang berubah, app code
          // sering berubah).
          manualChunks: {
            'vendor-vue':    ['vue', 'vue-router', 'pinia'],
            'vendor-axios':  ['axios'],
            'vendor-charts': ['chart.js', 'vue-chartjs'],
            'vendor-icons':  ['@heroicons/vue'],
            'vendor-date':   ['dayjs'],
            'vendor-util':   ['@vueuse/core', 'vue-toastification'],
          },
        },
      },
    },
  }
})