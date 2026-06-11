<script setup>
/**
 * S1.3 SaaS — Email Verification handler.
 *
 * Halaman ini diakses dari link di email verifikasi yang dikirim setelah
 * user signup. Backend response include JWT token + redirect_to=/onboarding.
 *
 * Flow:
 *   1. Mount → ambil token dari query string
 *   2. Call backend GET /auth/verify-signup?token=…
 *   3a. Sukses (status 200) → simpan JWT ke auth store + redirect ke /onboarding
 *   3b. Already provisioned → redirect ke /login
 *   3c. Token expired/failed → tampilkan error + tombol signup ulang
 */
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import { useAuthStore } from '@/stores/auth'
import {
  CheckCircleIcon,
  XCircleIcon,
  ArrowPathIcon,
} from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const state = ref('verifying') // verifying | success | already | error
const error = ref('')
const errorCode = ref('')

async function verify() {
  const token = String(route.query.token || '').trim()
  if (! token || token.length !== 64) {
    state.value = 'error'
    error.value = 'Token tidak valid atau format salah.'
    errorCode.value = 'INVALID_TOKEN'
    return
  }

  try {
    const baseUrl = import.meta.env.VITE_API_URL || '/api/v1'
    const res = await axios.get(`${baseUrl}/auth/verify-signup`, {
      params: { token },
    })
    const data = res.data?.data || {}

    if (data.already_provisioned) {
      state.value = 'already'
      return
    }

    if (data.token) {
      // Auto-login dengan JWT yang baru di-generate
      auth.setToken(data.token)
      // Hint redirect default ke /onboarding (wizard 8-step)
      const redirectTo = data.redirect_to || '/onboarding'
      state.value = 'success'

      // Tunggu 1 detik supaya user lihat success message, lalu redirect
      setTimeout(async () => {
        // Reload user info dari backend supaya stores ter-populate
        try {
          await auth.fetchUser?.()
        } catch {
          // ignore — user akan di-fetch lagi di halaman tujuan
        }
        router.push(redirectTo)
      }, 1500)
    } else {
      // Edge case: backend success tapi tanpa JWT — fallback ke login
      state.value = 'already'
    }
  } catch (e) {
    state.value = 'error'
    error.value = e.response?.data?.message || 'Verifikasi gagal'
    errorCode.value = e.response?.data?.code || 'UNKNOWN'
  }
}

onMounted(verify)
</script>

<template>
  <div class="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-gray-900 dark:to-gray-800 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
      <div class="bg-white dark:bg-gray-800 shadow-xl rounded-lg p-8 text-center">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Verifikasi Email</h1>

        <!-- Verifying state -->
        <div v-if="state === 'verifying'" class="py-6">
          <ArrowPathIcon class="h-12 w-12 mx-auto text-blue-500 animate-spin mb-4" />
          <p class="text-gray-600 dark:text-gray-300">Memverifikasi email Anda…</p>
          <p class="text-xs text-gray-500 mt-2">Sedang setup tenant + subscription trial. Mohon tunggu.</p>
        </div>

        <!-- Success state -->
        <div v-else-if="state === 'success'" class="py-6">
          <div class="mx-auto w-16 h-16 bg-emerald-100 dark:bg-emerald-900/30 rounded-full flex items-center justify-center mb-4">
            <CheckCircleIcon class="h-10 w-10 text-emerald-600" />
          </div>
          <h2 class="text-xl font-semibold text-emerald-700 dark:text-emerald-300 mb-2">Akun Aktif!</h2>
          <p class="text-gray-600 dark:text-gray-300">
            Tenant berhasil di-provision. Anda akan diarahkan ke onboarding wizard.
          </p>
          <ArrowPathIcon class="h-5 w-5 mx-auto text-blue-500 animate-spin mt-4" />
        </div>

        <!-- Already provisioned -->
        <div v-else-if="state === 'already'" class="py-6">
          <div class="mx-auto w-16 h-16 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center mb-4">
            <CheckCircleIcon class="h-10 w-10 text-blue-600" />
          </div>
          <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Sudah Aktif</h2>
          <p class="text-gray-600 dark:text-gray-300 mb-4">
            Akun ini sudah aktif sebelumnya. Silakan login dengan email dan password Anda.
          </p>
          <router-link
            to="/login"
            class="inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-500 text-sm font-medium"
          >
            Login Sekarang
          </router-link>
        </div>

        <!-- Error state -->
        <div v-else class="py-6">
          <div class="mx-auto w-16 h-16 bg-rose-100 dark:bg-rose-900/30 rounded-full flex items-center justify-center mb-4">
            <XCircleIcon class="h-10 w-10 text-rose-600" />
          </div>
          <h2 class="text-xl font-semibold text-rose-700 dark:text-rose-300 mb-2">
            Verifikasi Gagal
          </h2>
          <p class="text-gray-600 dark:text-gray-300 text-sm mb-2">{{ error }}</p>
          <p v-if="errorCode" class="text-xs text-gray-400 mb-4">Code: {{ errorCode }}</p>

          <div class="space-y-2">
            <router-link
              v-if="errorCode === 'TOKEN_EXPIRED' || errorCode === 'TOKEN_NOT_FOUND'"
              to="/signup"
              class="block w-full px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-500 text-sm font-medium"
            >
              Signup Ulang
            </router-link>
            <router-link
              to="/login"
              class="block w-full px-4 py-2 border rounded hover:bg-gray-50 text-sm font-medium dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
            >
              Kembali ke Login
            </router-link>
          </div>
        </div>
      </div>

      <p class="text-center text-xs text-gray-500 dark:text-gray-400 mt-4">
        Quantara HRIS · Trial 14 hari gratis
      </p>
    </div>
  </div>
</template>