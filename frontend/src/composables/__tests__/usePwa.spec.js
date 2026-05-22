import { describe, it, expect } from 'vitest'

/**
 * Sample/smoke test untuk infrastruktur Vitest.
 *
 * Ini bukan test fungsionalitas penuh — hanya verifikasi bahwa setup test
 * runner jalan benar. Test selanjutnya bisa import composable real dari
 * '@/composables/...' karena alias sudah dikonfigurasi via vite.config.js.
 *
 * Run: npm run test
 */
describe('vitest infrastructure', () => {
  it('runs basic assertion', () => {
    expect(1 + 1).toBe(2)
  })

  it('jsdom environment exposes document/window', () => {
    expect(typeof document).toBe('object')
    expect(typeof window).toBe('object')
  })

  it('supports async/await', async () => {
    const value = await Promise.resolve(42)
    expect(value).toBe(42)
  })
})