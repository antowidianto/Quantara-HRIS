import axios from 'axios'
import { useAuthStore } from '@/stores/auth'
import { createIdempotencyKey, requiresIdempotencyKey } from '@/api/idempotency'

const baseURL =
  import.meta.env.VITE_USE_PROXY === 'true'
    ? '/api/v1'
    : `${import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080'}/api/v1`

const api = axios.create({
  baseURL,
  timeout: 30000,
  withCredentials: false,
})

let refreshing = null
let redirectingToLogin = false

const PUBLIC_AUTH_PATHS = new Set([
  '/auth/login',
  '/auth/refresh',
  '/auth/forgot-password',
  '/auth/reset-password',
  '/auth/verify-2fa',
  '/auth/signup',
])

export function isPublicAuthPath(url) {
  if (!url) return false
  try {
    const path = new URL(url, 'http://local').pathname.replace(/^\/api\/v1/, '')
    return PUBLIC_AUTH_PATHS.has(path)
  } catch (_) {
    return false
  }
}

export function tokenExpiresWithin(
  token,
  seconds = 30,
  nowSeconds = Math.floor(Date.now() / 1000)
) {
  try {
    const encoded = String(token).split('.')[1].replace(/-/g, '+').replace(/_/g, '/')
    const padded = encoded.padEnd(Math.ceil(encoded.length / 4) * 4, '=')
    const payload = JSON.parse(atob(padded))
    return !Number(payload.exp) || Number(payload.exp) <= nowSeconds + seconds
  } catch (_) {
    return true
  }
}

function redirectToLogin() {
  if (redirectingToLogin || typeof window === 'undefined') return
  redirectingToLogin = true
  if (window.location.pathname !== '/login') {
    window.location.replace('/login?session=expired')
  }
}

async function refreshSession(auth) {
  if (!refreshing) {
    refreshing = auth.refresh().finally(() => {
      refreshing = null
    })
  }
  return refreshing
}

api.interceptors.request.use(async (config) => {
  const auth = useAuthStore()
  const skipAuth = config.skipAuth === true || isPublicAuthPath(config.url)
  if (!skipAuth && auth.accessToken && auth.refreshToken && tokenExpiresWithin(auth.accessToken)) {
    try {
      await refreshSession(auth)
    } catch (error) {
      auth.logoutLocal()
      redirectToLogin()
      return Promise.reject(error)
    }
  }
  if (!skipAuth && auth.accessToken) {
    config.headers.Authorization = `Bearer ${auth.accessToken}`
  }
  // Untuk super admin, kirim active company agar backend tahu target tenant
  if (auth.isSuperAdmin && auth.activeCompanyId) {
    config.headers['X-Company-Id'] = auth.activeCompanyId
  }
  if (requiresIdempotencyKey(config.method, config.url)) {
    const existing =
      typeof config.headers?.get === 'function'
        ? config.headers.get('Idempotency-Key')
        : config.headers?.['Idempotency-Key']
    if (!existing) {
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
    if (!error.response) return Promise.reject(error)

    const auth = useAuthStore()

    if (
      error.response.status === 401 &&
      !original?.skipAuthRefresh &&
      !isPublicAuthPath(original?.url) &&
      !original?._retry &&
      auth.refreshToken
    ) {
      original._retry = true
      try {
        await refreshSession(auth)
        original.headers.Authorization = `Bearer ${auth.accessToken}`
        return api(original)
      } catch (e) {
        auth.logoutLocal()
        redirectToLogin()
        return Promise.reject(e)
      }
    }
    if (error.response.status === 401 && !isPublicAuthPath(original?.url)) {
      auth.logoutLocal()
      redirectToLogin()
    }
    return Promise.reject(error)
  }
)

export default api
