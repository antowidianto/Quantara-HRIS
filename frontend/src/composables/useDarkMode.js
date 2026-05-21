import { ref, watch } from 'vue'

/**
 * useDarkMode — toggle Tailwind dark mode dengan persist localStorage.
 *
 * Modes:
 *   'light'  → force light, ignore OS
 *   'dark'   → force dark, ignore OS
 *   'auto'   → follow OS preference (default)
 *
 * Browser support:
 *   - localStorage untuk persist pilihan user
 *   - matchMedia('(prefers-color-scheme: dark)') untuk auto mode
 *   - graceful fallback ke 'light' saat di SSR / browser tua
 *
 * Catatan implementasi: dark mode butuh Tailwind utility `dark:*` di setiap
 * komponen. Sprint 5-6 hanya menyediakan toggle infrastruktur. Pages individual
 * akan dimigrasi inkremental — start dari Login & Dashboard yang paling sering
 * dilihat user.
 *
 * Sprint 5-6 item S.
 */

const STORAGE_KEY = 'hris-color-mode'
const VALID_MODES = ['light', 'dark', 'auto']

// State global supaya satu instance per app
const mode = ref('auto')
const effective = ref('light')  // 'light' | 'dark' (resolved dari mode + OS)

let mediaQuery = null
let initialized = false

function readStored() {
  try {
    const v = localStorage.getItem(STORAGE_KEY)
    return VALID_MODES.includes(v) ? v : 'auto'
  } catch (_) {
    return 'auto'
  }
}

function writeStored(m) {
  try { localStorage.setItem(STORAGE_KEY, m) } catch (_) { /* ignore quota */ }
}

function osPrefersDark() {
  if (typeof window === 'undefined' || ! window.matchMedia) return false
  return window.matchMedia('(prefers-color-scheme: dark)').matches
}

function resolveEffective(m) {
  if (m === 'dark') return 'dark'
  if (m === 'light') return 'light'
  return osPrefersDark() ? 'dark' : 'light'  // auto
}

function applyToHtml(eff) {
  const html = document.documentElement
  if (eff === 'dark') html.classList.add('dark')
  else html.classList.remove('dark')
  // Beri tahu browser supaya scrollbar / form control native ikutan
  html.style.colorScheme = eff
}

function init() {
  if (initialized || typeof window === 'undefined') return
  initialized = true
  mode.value = readStored()
  effective.value = resolveEffective(mode.value)
  applyToHtml(effective.value)

  // Listen perubahan OS preference saat mode = 'auto'
  if (window.matchMedia) {
    mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    const handler = () => {
      if (mode.value === 'auto') {
        effective.value = osPrefersDark() ? 'dark' : 'light'
        applyToHtml(effective.value)
      }
    }
    if (mediaQuery.addEventListener) {
      mediaQuery.addEventListener('change', handler)
    } else if (mediaQuery.addListener) {
      // Safari < 14
      mediaQuery.addListener(handler)
    }
  }

  watch(mode, (m) => {
    writeStored(m)
    effective.value = resolveEffective(m)
    applyToHtml(effective.value)
  })
}

/** Set mode — 'light' | 'dark' | 'auto'. */
function setMode(m) {
  if (! VALID_MODES.includes(m)) return
  mode.value = m
}

/** Toggle cycle: light → dark → auto → light. */
function toggle() {
  const next = mode.value === 'light' ? 'dark' : (mode.value === 'dark' ? 'auto' : 'light')
  setMode(next)
}

export function useDarkMode() {
  init()
  return {
    mode,           // ref<'light'|'dark'|'auto'>
    effective,      // ref<'light'|'dark'> — actual yang dipakai
    setMode,
    toggle,
  }
}