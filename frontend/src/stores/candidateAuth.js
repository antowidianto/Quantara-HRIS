import { defineStore } from 'pinia'
import axios from 'axios'

const ACCESS_KEY = 'candidate_access'
const REFRESH_KEY = 'candidate_refresh'
const ACCOUNT_KEY = 'candidate_account'

const baseURL = (import.meta.env.VITE_USE_PROXY === 'true')
  ? '/api/v1'
  : `${import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080'}/api/v1`

/**
 * Pinia store untuk auth pelamar (kandidat) di Career Portal.
 *
 * Berbeda dari `useAuthStore` (HRIS internal) — pakai endpoint
 * /careers/{slug}/account/* dan /careers/account/*.
 *
 * Token disimpan di localStorage dengan key terpisah supaya kalau
 * user login HRIS internal di tab lain, sesi kandidat tetap utuh.
 */
export const useCandidateAuthStore = defineStore('candidateAuth', {
  state: () => ({
    accessToken:  localStorage.getItem(ACCESS_KEY) || null,
    refreshToken: localStorage.getItem(REFRESH_KEY) || null,
    account: JSON.parse(localStorage.getItem(ACCOUNT_KEY) || 'null'),
  }),

  getters: {
    isAuthenticated: (s) => !!s.accessToken && !!s.account,
  },

  actions: {
    async register(slug, payload) {
      const res = await axios.post(`${baseURL}/careers/${slug}/account/register`, payload)
      this._persist(res.data.data)
      return res.data.data
    },

    async login(slug, email, password) {
      const res = await axios.post(`${baseURL}/careers/${slug}/account/login`, { email, password })
      this._persist(res.data.data)
      return res.data.data
    },

    async forgotPassword(slug, email) {
      const res = await axios.post(`${baseURL}/careers/${slug}/account/forgot-password`, { email })
      return res.data
    },

    async resetPassword(token, password) {
      const res = await axios.post(`${baseURL}/careers/account/reset-password`, { token, password })
      return res.data
    },

    async refresh() {
      if (! this.refreshToken) throw new Error('No refresh token')
      const res = await axios.post(`${baseURL}/careers/account/refresh`, {
        refresh_token: this.refreshToken,
      })
      this._persist(res.data.data)
    },

    async logout() {
      try {
        await axios.post(`${baseURL}/careers/account/logout`,
          { refresh_token: this.refreshToken },
          { headers: { Authorization: `Bearer ${this.accessToken}` } })
      } catch (_) { /* ignore */ }
      this._clear()
    },

    async fetchMe() {
      const res = await axios.get(`${baseURL}/careers/account/me`, {
        headers: { Authorization: `Bearer ${this.accessToken}` },
      })
      this.account = res.data.data.account
      localStorage.setItem(ACCOUNT_KEY, JSON.stringify(this.account))
      return res.data.data
    },

    _persist(data) {
      this.accessToken  = data.access_token
      this.refreshToken = data.refresh_token
      this.account      = data.account || this.account
      localStorage.setItem(ACCESS_KEY,  data.access_token)
      localStorage.setItem(REFRESH_KEY, data.refresh_token)
      if (data.account) localStorage.setItem(ACCOUNT_KEY, JSON.stringify(data.account))
    },

    _clear() {
      this.accessToken = null
      this.refreshToken = null
      this.account = null
      localStorage.removeItem(ACCESS_KEY)
      localStorage.removeItem(REFRESH_KEY)
      localStorage.removeItem(ACCOUNT_KEY)
    },
  },
})

/**
 * Axios client khusus candidate. Auto-inject Authorization header dari
 * candidate auth store + auto-refresh on 401.
 *
 * Pakai untuk semua endpoint /careers/account/* dan /careers/{slug}/jobs/{slug}/apply.
 */
export function candidateApi() {
  const store = useCandidateAuthStore()
  const client = axios.create({ baseURL, timeout: 30000 })

  client.interceptors.request.use((config) => {
    if (store.accessToken) {
      config.headers.Authorization = `Bearer ${store.accessToken}`
    }
    return config
  })

  let refreshing = null
  client.interceptors.response.use(
    (r) => r,
    async (error) => {
      const original = error.config
      if (error.response?.status === 401 && ! original._retry && store.refreshToken) {
        original._retry = true
        try {
          if (! refreshing) refreshing = store.refresh()
          await refreshing
          refreshing = null
          original.headers.Authorization = `Bearer ${store.accessToken}`
          return client(original)
        } catch (e) {
          refreshing = null
          store._clear()
          // tidak redirect — caller (component) yang handle UI
        }
      }
      return Promise.reject(error)
    },
  )
  return client
}