import { defineStore } from 'pinia'
import api from '@/api/client'

const ACCESS_KEY  = 'hris_access'
const REFRESH_KEY = 'hris_refresh'
const ACTIVE_COMPANY_KEY = 'hris_active_company_id'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    accessToken: localStorage.getItem(ACCESS_KEY) || null,
    refreshToken: localStorage.getItem(REFRESH_KEY) || null,
    user: null,
    employee: null,
    company: null,
    branch: null,
    role: null,
    permissions: [],
    bootstrapped: false,

    // Khusus super admin: target company yang sedang dipilih untuk
    // operasi tulis (create/update/delete master data).
    activeCompanyId: parseInt(localStorage.getItem(ACTIVE_COMPANY_KEY) || '0', 10) || null,
    availableCompanies: [],
  }),

  getters: {
    isAuthenticated: (s) => !!s.accessToken && !!s.user,
    isSuperAdmin: (s) => s.role?.slug === 'super_admin',
    can: (s) => (perm) => s.role?.slug === 'super_admin' || s.permissions.includes(perm),
    /**
     * Untuk super admin: kalau belum pilih company, hampir semua aksi
     * tulis bakal ditolak backend dengan COMPANY_REQUIRED. Komponen UI
     * boleh mengandalkan flag ini untuk disable tombol Tambah.
     */
    needsCompanyContext: (s) => s.role?.slug === 'super_admin' && ! s.activeCompanyId,
  },

  actions: {
    async bootstrap() {
      if (! this.accessToken) {
        this.bootstrapped = true
        return
      }
      try {
        await this.fetchMe()
      } catch (e) {
        // Token might be expired; try refresh once
        if (this.refreshToken) {
          try { await this.refresh(); await this.fetchMe(); } catch (_) { this.logoutLocal() }
        } else {
          this.logoutLocal()
        }
      } finally {
        this.bootstrapped = true
      }
    },

    async login({ email, password }) {
      const res = await api.post('/auth/login', { email, password })
      const data = res.data.data || {}

      // 2FA challenge case
      if (data.requires_2fa) {
        return { requires_2fa: true, challenge: data.challenge }
      }

      this.setTokens(data.tokens)
      await this.fetchMe()
      return { mustChangePassword: !!data.must_change_password }
    },

    async verifyTwoFactor(challenge, code) {
      const res = await api.post('/auth/verify-2fa', { challenge, code })
      this.setTokens(res.data.data.tokens)
      await this.fetchMe()
    },

    async refresh() {
      const res = await api.post('/auth/refresh', { refresh_token: this.refreshToken })
      this.setTokens(res.data.data.tokens)
    },

    async fetchMe() {
      const res = await api.get('/auth/me')
      const d = res.data.data
      this.user = d.user
      this.employee = d.employee
      this.company = d.company
      this.branch = d.branch || null
      this.role = d.role
      this.permissions = d.permissions || []
      // avatar_url sudah ditandatangani backend (signed URL valid 1 jam).
      // Komponen lain (sidebar, header) bisa pakai langsung sebagai
      // <img :src="auth.user.avatar_url"> tanpa header Authorization.

      // User non-super-admin: activeCompany = company mereka
      if (d.role?.slug !== 'super_admin' && d.company?.id) {
        this.setActiveCompany(d.company.id)
      } else if (d.role?.slug === 'super_admin') {
        // Load list company supaya super admin bisa pilih
        try {
          const cRes = await api.get('/companies', { params: { per_page: 100 } })
          this.availableCompanies = cRes.data.data || []
          // Auto-pick kalau cuma ada 1 company aktif
          if (! this.activeCompanyId && this.availableCompanies.length === 1) {
            this.setActiveCompany(this.availableCompanies[0].id)
          }
        } catch (_) { /* abaikan, user belum punya akses */ }
      }
    },

    setActiveCompany(id) {
      this.activeCompanyId = id ? Number(id) : null
      if (this.activeCompanyId) {
        localStorage.setItem(ACTIVE_COMPANY_KEY, String(this.activeCompanyId))
      } else {
        localStorage.removeItem(ACTIVE_COMPANY_KEY)
      }
    },

    async logout() {
      try { await api.post('/auth/logout') } catch (_) {}
      this.logoutLocal()
    },

    /**
     * Update kolom user di store setelah upload/hapus avatar.
     * Dipanggil dari Profile.vue setelah POST/DELETE /auth/me/avatar
     * supaya semua komponen yang reaktif (sidebar, header) langsung
     * lihat foto baru tanpa perlu fetchMe ulang.
     */
    setAvatar(avatarPath, avatarUrl) {
      if (this.user) {
        this.user = { ...this.user, avatar_path: avatarPath, avatar_url: avatarUrl }
      }
      if (this.employee) {
        this.employee = { ...this.employee, avatar_path: avatarPath, avatar_url: avatarUrl }
      }
    },

    logoutLocal() {
      this.accessToken = null
      this.refreshToken = null
      this.user = null
      this.employee = null
      this.company = null
      this.branch = null
      this.role = null
      this.permissions = []
      this.activeCompanyId = null
      this.availableCompanies = []
      localStorage.removeItem(ACCESS_KEY)
      localStorage.removeItem(REFRESH_KEY)
      localStorage.removeItem(ACTIVE_COMPANY_KEY)
    },

    setTokens(tokens) {
      this.accessToken  = tokens.access_token
      this.refreshToken = tokens.refresh_token
      localStorage.setItem(ACCESS_KEY,  tokens.access_token)
      localStorage.setItem(REFRESH_KEY, tokens.refresh_token)
    },
  },
})