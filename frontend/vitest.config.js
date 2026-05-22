import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config.js'

// Vitest config — extends vite.config.js supaya alias '@/...' tetap jalan
// di test. Run via `npm run test` (single run) atau `npm run test:watch`.
export default mergeConfig(
  viteConfig({ mode: 'test' }),
  defineConfig({
    test: {
      environment: 'jsdom',
      globals: true,
      include: ['src/**/*.{test,spec}.{js,ts}'],
      coverage: {
        reporter: ['text', 'html'],
        include: ['src/**/*.{js,vue}'],
        exclude: [
          'src/**/*.{test,spec}.{js,ts}',
          'src/main.js',
          'src/router/**',
        ],
      },
    },
  }),
)