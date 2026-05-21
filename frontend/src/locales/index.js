/**
 * i18n minimalis tanpa dependensi tambahan.
 *
 * Tujuan:
 *   - Bundle size: 0 dep tambahan, kurang lebih 1 kB.
 *   - API mirip vue-i18n: `t('namespace.key', { name: 'Budi' })`.
 *   - Reactive: ganti locale → semua komponen yang pakai `t()` re-render.
 *   - Persisten: pilihan disimpan ke `localStorage`, default ikut bahasa
 *     browser (`navigator.language`) → fallback `id`.
 *
 * Cara migrasi bertahap:
 *   1. Komponen lama yang masih hard-code Bahasa Indonesia tetap jalan.
 *   2. Komponen baru / komponen yang di-touch panggil `const { t } =
 *      useI18n()` lalu `{{ t('common.save') }}` di template.
 *   3. Global helper `$t` juga di-inject (lihat main.js) untuk template
 *      yang tidak mau import composable.
 *
 * Kalau ke depan kebutuhan i18n bertambah (pluralization advanced,
 * datetime/number format per-locale, message compiler), tinggal swap
 * implementasi ini ke vue-i18n karena API-nya sengaja kompatibel.
 */

import { ref, computed } from 'vue'
import id from './id.js'
import en from './en.js'

const messages = { id, en }
const STORAGE_KEY = 'hris_locale'
const FALLBACK = 'id'

// Detect locale awal: localStorage → navigator → fallback.
function detectLocale() {
  if (typeof localStorage !== 'undefined') {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved && messages[saved]) return saved
  }
  if (typeof navigator !== 'undefined') {
    const browserLang = (navigator.language || '').toLowerCase()
    if (browserLang.startsWith('en')) return 'en'
    if (browserLang.startsWith('id')) return 'id'
  }
  return FALLBACK
}

// State global — module-scoped, jadi semua komponen share locale yang sama.
const currentLocale = ref(detectLocale())

/**
 * Resolusi key bertitik ("nav.dashboard") ke nilai dalam tree messages.
 * Return null kalau tidak ditemukan.
 */
function resolve(obj, path) {
  if (! obj || ! path) return null
  const parts = path.split('.')
  let cur = obj
  for (const p of parts) {
    if (cur == null || typeof cur !== 'object') return null
    cur = cur[p]
  }
  return cur
}

/**
 * Interpolasi placeholder `{name}` dari params object.
 *   t('hello {name}', { name: 'Budi' }) → "hello Budi"
 */
function interpolate(template, params) {
  if (! params || typeof template !== 'string') return template
  return template.replace(/\{(\w+)\}/g, (_, k) =>
    params[k] !== undefined ? String(params[k]) : `{${k}}`
  )
}

/**
 * Translate function — terima key + optional params.
 * Fallback chain: locale aktif → fallback (id) → key itu sendiri (supaya
 * dev cepat sadar key salah, bukan tampil string kosong).
 */
export function t(key, params) {
  const locale = currentLocale.value
  let val = resolve(messages[locale], key)
  if (val == null && locale !== FALLBACK) {
    val = resolve(messages[FALLBACK], key)
  }
  if (val == null) {
    if (import.meta?.env?.DEV) {
      console.warn(`[i18n] Missing key: ${key} (locale: ${locale})`)
    }
    return key
  }
  return interpolate(val, params)
}

export function setLocale(locale) {
  if (! messages[locale]) {
    console.warn(`[i18n] Unsupported locale: ${locale}`)
    return
  }
  currentLocale.value = locale
  if (typeof localStorage !== 'undefined') {
    localStorage.setItem(STORAGE_KEY, locale)
  }
  // Set <html lang="..."> supaya screen reader & SEO benar.
  if (typeof document !== 'undefined' && document.documentElement) {
    document.documentElement.setAttribute('lang', locale)
  }
}

export function availableLocales() {
  return [
    { code: 'id', label: 'Bahasa Indonesia', flag: '🇮🇩' },
    { code: 'en', label: 'English',          flag: '🇬🇧' },
  ]
}

/**
 * Composable hook — pola sama seperti vue-i18n.
 *   const { t, locale, setLocale } = useI18n()
 */
export function useI18n() {
  return {
    t,
    locale: computed(() => currentLocale.value),
    setLocale,
    availableLocales,
  }
}

/** Vue plugin — register `$t` global property + initial <html lang>. */
export const i18nPlugin = {
  install(app) {
    app.config.globalProperties.$t = t
    app.provide('i18n', { t, setLocale, locale: currentLocale })
    if (typeof document !== 'undefined' && document.documentElement) {
      document.documentElement.setAttribute('lang', currentLocale.value)
    }
  },
}