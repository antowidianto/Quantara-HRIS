import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config.js'

// Vitest config — extends vite.config.js supaya alias '@/...' tetap jalan
// di test. Run via `npm run test` (single run) atau `npm run test:watch`.
export default defineConfig(async () => {
  const baseConfig = await viteConfig({ mode: 'test', command: 'serve' })

  return mergeConfig(
    baseConfig,
    defineConfig({
    test: {
      environment: 'jsdom',
      globals: true,
      include: ['src/**/*.{test,spec}.{js,ts}'],
      coverage: {
        // Sprint A / E5 — turunkan dengan progressive ratchet:
        // saat ini coverage masih ~0% jadi threshold sengaja rendah.
        // Naikkan setiap sprint sesuai roadmap audit.
        reporter: ['text', 'html', 'lcov'],
        include: ['src/**/*.{js,vue}'],
        exclude: [
          'src/**/*.{test,spec}.{js,ts}',
          'src/main.js',
          'src/router/**',
          // Views dan layouts saat ini tanpa test — keluarkan dulu
          // dari hitungan supaya threshold realistis untuk composables/utils
          'src/views/**',
          'src/layouts/**',
        ],
        // Threshold tahap awal — DI-COMMENT supaya CI tidak gagal saat
        // baseline coverage masih ~0%. Begitu Sprint E (frontend refactor)
        // mencapai cakupan layak, aktifkan dan ratchet bertahap.
        thresholds: {
          lines: 20,
          functions: 20,
          branches: 15,
          statements: 20,
        },
      },
    },
    }),
  )
})
