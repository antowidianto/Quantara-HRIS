import axios from 'axios'
import { useAuthStore } from '@/stores/auth'
import { createIdempotencyKey, requiresIdempotencyKey } from '@/api/idempotency'

const baseURL = (import.meta.env.VITE_USE_PROXY === 'true')
  ? '/api/v1'
  : `${import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080'}/api/v1`

const api = axios.create({
  baseURL,
  timeout: 30000,
  withCredentials: false,
})

let refreshing = null

api.interceptors.request.use((config) => {
  const auth = useAuthStore()
  if (auth.accessToken) {
    config.headers.Authorization = `Bearer ${auth.accessToken}`
  }
  // Untuk super admin, kirim active company agar backend tahu target tenant
  if (auth.isSuperAdmin && auth.activeCompanyId) {
    config.headers['X-Company-Id'] = auth.activeCompanyId
  }
  if (requiresIdempotencyKey(config.method, config.url)) {
    const existing = typeof config.headers?.get === 'function'
      ? config.headers.get('Idempotency-Key')
      : config.headers?.['Idempotency-Key']
    if (! existing) {
      if (typeof config.headers?.set === 'function') {
        config.headers.set('Idempotency-Key', createIdempotencyKey())
      } else {
        config.headers['Idempotency-Key'] = createIdempotencyKey()
      }
    }
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const original = error.config
    if (! error.response) return Promise.reject(error)

    const auth = useAuthStore()

    if (error.response.status === 401 && ! original._retry && auth.refreshToken) {
      original._retry = true
      try {
        if (! refreshing) {
          refreshing = auth.refresh()
        }
        await refreshing
        refreshing = null
        original.headers.Authorization = `Bearer ${auth.accessToken}`
        return api(original)
      } catch (e) {
        refreshing = null
        auth.logoutLocal()
        if (typeof window !== 'undefined') {
          window.location.href = '/login'
        }
        return Promise.reject(e)
      }
    }
    return Promise.reject(error)
  },
)

export default api
