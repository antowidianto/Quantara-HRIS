<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const toast = useToast()

const email = ref('')
const password = ref('')
const loading = ref(false)
const showPassword = ref(false)

const requires2fa = ref(false)
const challenge = ref('')
const otpCode = ref('')

async function submit() {
  loading.value = true
  try {
    const result = await auth.login({ email: email.value, password: password.value })
    if (result?.requires_2fa) {
      requires2fa.value = true
      challenge.value = result.challenge
      toast.info('Masukkan kode 2FA dari aplikasi authenticator')
      return
    }
    if (result?.mustChangePassword) {
      router.push('/change-password')
      return
    }
    const redirect = route.query.redirect || '/dashboard'
    router.push(redirect)
  } catch (e) {
    const msg = e.response?.data?.message || 'Login gagal'
    toast.error(msg)
  } finally {
    loading.value = false
  }
}

async function submitOtp() {
  loading.value = true
  try {
    await auth.verifyTwoFactor(challenge.value, otpCode.value)
    router.push(route.query.redirect || '/dashboard')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Kode 2FA tidak valid')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-primary-50 via-white to-blue-50 px-4">
    <div class="max-w-md w-full">
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-primary-600 text-white text-3xl font-bold mb-4">H</div>
        <h1 class="text-2xl font-bold text-gray-900">HRIS</h1>
        <p class="text-gray-500 mt-1">Masuk ke akun Anda</p>
      </div>

      <div class="card">
        <div class="card-body">
          <form v-if="!requires2fa" @submit.prevent="submit" class="space-y-4">
            <div>
              <label class="label" for="email">Email</label>
              <input id="email" v-model="email" type="email" required autocomplete="email"
                class="input" placeholder="nama@perusahaan.id" />
            </div>
            <div>
              <label class="label" for="password">Password</label>
              <div class="relative">
                <input id="password" v-model="password" :type="showPassword ? 'text' : 'password'"
                  required autocomplete="current-password" class="input pr-10" />
                <button type="button" @click="showPassword = !showPassword"
                  class="absolute inset-y-0 right-0 px-3 text-gray-400 text-sm">
                  {{ showPassword ? 'Sembunyikan' : 'Tampilkan' }}
                </button>
              </div>
            </div>
            <div class="text-right">
              <RouterLink to="/forgot-password" class="text-sm text-primary-600 hover:underline">
                Lupa password?
              </RouterLink>
            </div>
            <button type="submit" :disabled="loading"
              class="btn-primary w-full justify-center py-2.5">
              {{ loading ? 'Memproses...' : 'Masuk' }}
            </button>
          </form>

          <form v-else @submit.prevent="submitOtp" class="space-y-4">
            <div class="text-center">
              <div class="text-sm text-gray-600">Verifikasi 2 Langkah</div>
              <div class="text-xs text-gray-500 mt-1">Masukkan kode 6-digit dari aplikasi authenticator Anda</div>
            </div>
            <div>
              <input v-model="otpCode" type="text" inputmode="numeric" maxlength="6" required
                class="input text-center text-2xl tracking-widest" placeholder="000000" autofocus />
            </div>
            <button type="submit" :disabled="loading"
              class="btn-primary w-full justify-center py-2.5">
              {{ loading ? 'Memverifikasi...' : 'Verifikasi' }}
            </button>
            <button type="button" @click="requires2fa = false; otpCode = ''"
              class="w-full text-sm text-gray-500 hover:text-gray-700">
              Kembali
            </button>
          </form>
        </div>
      </div>

      <p class="text-center text-xs text-gray-400 mt-6">© {{ new Date().getFullYear() }} HRIS. Semua data terenkripsi.</p>
    </div>
  </div>
</template>