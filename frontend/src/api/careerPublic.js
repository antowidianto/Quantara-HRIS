import axios from 'axios'
import { createIdempotencyKey, requiresIdempotencyKey } from '@/api/idempotency'

/**
 * Public Career Portal API client.
 * Tidak menempelkan JWT — endpoint /careers/* memang public di backend.
 */
const baseURL = (import.meta.env.VITE_USE_PROXY === 'true')
  ? '/api/v1'
  : `${import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080'}/api/v1`

const careerApi = axios.create({
  baseURL,
  timeout: 30000,
  withCredentials: false,
})

careerApi.interceptors.request.use((config) => {
  if (!requiresIdempotencyKey(config.method, config.url)) return config

  const existing = typeof config.headers?.get === 'function'
    ? config.headers.get('Idempotency-Key')
    : config.headers?.['Idempotency-Key']
  if (existing) return config

  if (typeof config.headers?.set === 'function') {
    config.headers.set('Idempotency-Key', createIdempotencyKey())
  } else {
    config.headers = { ...config.headers, 'Idempotency-Key': createIdempotencyKey() }
  }
  return config
})

export default careerApi

/**
 * Helper: konversi path file (dari upload public) ke URL signed agar bisa
 * ditampilkan tanpa auth — backend `/files/serve` public dengan signed token.
 */
export function fileUrl(path) {
  if (! path) return null
  if (/^https?:\/\//i.test(path)) return path
  // Public — fallback ke direct URL kalau backend tidak return signed URL.
  // Frontend tidak punya cara untuk mendapatkan signed URL tanpa auth, jadi
  // kita biarkan backend yang handle untuk asset public (logo, hero).
  // Untuk dokumen lamaran sendiri, pelamar tidak perlu lihat ulang.
  const root = baseURL.replace(/\/api\/v1$/, '')
  return `${root}/api/v1/files/serve?path=${encodeURIComponent(path)}`
}
