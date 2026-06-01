import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

/**
 * useDarkMode — Sprint E / E3.
 *
 * Composable global untuk theme mode (light/dark/auto). Test memastikan:
 *   - Mode default 'auto' dan resolve ke 'light'/'dark' sesuai OS preference
 *   - `setMode()` valid mode di-persist ke localStorage
 *   - Mode invalid di-ignore (defensive)
 *   - `toggle()` cycle: light → dark → auto → light
 *   - `<html>` mendapat class 'dark' / colorScheme yang benar
 *
 * Catatan: composable pakai module-level state (singleton). Untuk
 * memastikan setiap test dapat instance bersih, kita reset module cache
 * dan localStorage sebelum tiap test.
 */
describe('useDarkMode', () => {
  let localStore

  beforeEach(() => {
    // Reset module cache supaya `init()` jalan ulang per test
    vi.resetModules()

    // Stub localStorage minimal — jsdom default sudah punya, tapi kita
    // pakai map manual supaya gampang inspect
    localStore = new Map()
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      value: {
        getItem: (k) => (localStore.has(k) ? localStore.get(k) : null),
        setItem: (k, v) => localStore.set(k, String(v)),
        removeItem: (k) => localStore.delete(k),
        clear: () => localStore.clear(),
      },
    })

    // Reset html state
    document.documentElement.classList.remove('dark')
    document.documentElement.style.colorScheme = ''
  })

  afterEach(() => {
    document.documentElement.classList.remove('dark')
  })

  /**
   * Helper: stub matchMedia dengan flag prefers-color-scheme: dark.
   * matchMedia handler addListener fallback Safari < 14 di-stub no-op.
   */
  function stubMatchMedia(prefersDark) {
    window.matchMedia = vi.fn().mockImplementation((query) => ({
      matches: query.includes('dark') ? prefersDark : false,
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
    }))
  }

  it('defaults to "auto" mode and resolves to light when OS preference is light', async () => {
    stubMatchMedia(false)
    const { useDarkMode } = await import('../useDarkMode')
    const { mode, effective } = useDarkMode()

    expect(mode.value).toBe('auto')
    expect(effective.value).toBe('light')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
    expect(document.documentElement.style.colorScheme).toBe('light')
  })

  it('resolves to dark when OS preference is dark and mode is auto', async () => {
    stubMatchMedia(true)
    const { useDarkMode } = await import('../useDarkMode')
    const { mode, effective } = useDarkMode()

    expect(mode.value).toBe('auto')
    expect(effective.value).toBe('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(document.documentElement.style.colorScheme).toBe('dark')
  })

  it('reads mode from localStorage on init', async () => {
    localStore.set('hris-color-mode', 'dark')
    stubMatchMedia(false)
    const { useDarkMode } = await import('../useDarkMode')
    const { mode, effective } = useDarkMode()

    expect(mode.value).toBe('dark')
    expect(effective.value).toBe('dark')
  })

  it('ignores invalid stored mode and falls back to auto', async () => {
    localStore.set('hris-color-mode', 'plaid')
    stubMatchMedia(false)
    const { useDarkMode } = await import('../useDarkMode')
    const { mode } = useDarkMode()

    expect(mode.value).toBe('auto')
  })

  it('setMode persists to localStorage and updates html class', async () => {
    stubMatchMedia(false)
    const { useDarkMode } = await import('../useDarkMode')
    const { mode, effective, setMode } = useDarkMode()

    setMode('dark')
    // watch is async — flush microtask queue
    await Promise.resolve()
    await Promise.resolve()

    expect(mode.value).toBe('dark')
    expect(effective.value).toBe('dark')
    expect(localStore.get('hris-color-mode')).toBe('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
  })

  it('setMode rejects invalid mode', async () => {
    stubMatchMedia(false)
    const { useDarkMode } = await import('../useDarkMode')
    const { mode, setMode } = useDarkMode()

    const before = mode.value
    setMode('invalid')
    setMode('')
    setMode(null)

    expect(mode.value).toBe(before)
  })

  it('toggle cycles light → dark → auto → light', async () => {
    stubMatchMedia(false)
    const { useDarkMode } = await import('../useDarkMode')
    const { mode, setMode, toggle } = useDarkMode()

    // Mulai dari light supaya cycle deterministik
    setMode('light')
    await Promise.resolve()
    expect(mode.value).toBe('light')

    toggle()
    await Promise.resolve()
    expect(mode.value).toBe('dark')

    toggle()
    await Promise.resolve()
    expect(mode.value).toBe('auto')

    toggle()
    await Promise.resolve()
    expect(mode.value).toBe('light')
  })

  it('switching to light forces light even when OS prefers dark', async () => {
    stubMatchMedia(true) // OS = dark
    const { useDarkMode } = await import('../useDarkMode')
    const { effective, setMode } = useDarkMode()

    expect(effective.value).toBe('dark') // auto + OS dark

    setMode('light')
    await Promise.resolve()
    await Promise.resolve()

    expect(effective.value).toBe('light')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })

  it('survives missing matchMedia gracefully', async () => {
    // Hapus matchMedia (legacy browser)
    delete window.matchMedia
    const { useDarkMode } = await import('../useDarkMode')
    const { mode, effective } = useDarkMode()

    expect(mode.value).toBe('auto')
    // tanpa matchMedia, fallback ke 'light'
    expect(effective.value).toBe('light')
  })

  it('survives localStorage throw (private mode / quota) gracefully', async () => {
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      value: {
        getItem: () => { throw new Error('blocked') },
        setItem: () => { throw new Error('quota') },
        removeItem: () => {},
        clear: () => {},
      },
    })
    stubMatchMedia(false)
    const { useDarkMode } = await import('../useDarkMode')
    const { mode, setMode } = useDarkMode()

    expect(mode.value).toBe('auto')
    // setMode tidak boleh throw walaupun localStorage gagal
    expect(() => setMode('dark')).not.toThrow()
  })
})