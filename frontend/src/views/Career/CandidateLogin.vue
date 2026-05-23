<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { useCandidateAuthStore } from '@/stores/candidateAuth'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const store = useCandidateAuthStore()

const slug = computed(() => route.params.slug)
const form = ref({ email: '', password: '' })
const submitting = ref(false)
const showForgot = ref(false)
const forgotEmail = ref('')

async function submit() {
  submitting.value = true
  try {
    await store.login(slug.value, form.value.email, form.value.password)
    toast.success('Login berhasil')
    const redirect = route.query.redirect || `/careers/${slug.value}/dashboard`
    router.push(redirect)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Email atau password salah')
  } finally {
    submitting.value = false
  }
}

async function sendForgot() {
  if (! forgotEmail.value) return toast.error('Masukkan email Anda')
  try {
    await store.forgotPassword(slug.value, forgotEmail.value)
    toast.success('Jika email terdaftar, link reset sudah dikirim ke email Anda')
    showForgot.value = false
    forgotEmail.value = ''
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mengirim email')
  }
}
</script>

<template>
  <div class="max-w-md mx-auto py-12 px-4">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
      <h1 class="text-2xl font-bold text-slate-900">Masuk Akun Pelamar</h1>
      <p class="text-sm text-slate-500 mt-1">
        Belum punya akun?
        <router-link :to="{ name: 'candidate-register', params: { slug } }" class="text-indigo-600 font-medium hover:underline">
          Daftar di sini
        </router-link>
      </p>

      <form @submit.prevent="submit" class="space-y-4 mt-6">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
          <input v-model="form.email" type="email" required autocomplete="email"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
          <input v-model="form.password" type="password" required autocomplete="current-password"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <button type="submit" :disabled="submitting"
          class="w-full bg-indigo-600 text-white py-2.5 rounded-lg font-medium hover:bg-indigo-700 disabled:opacity-50">
          {{ submitting ? 'Memproses...' : 'Masuk' }}
        </button>
      </form>

      <button type="button" @click="showForgot = !showForgot"
        class="text-sm text-slate-500 hover:text-indigo-600 mt-4 block">
        Lupa password?
      </button>

      <div v-if="showForgot" class="mt-3 p-3 bg-slate-50 rounded-lg border border-slate-200">
        <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
        <input v-model="forgotEmail" type="email"
          class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" />
        <button @click="sendForgot"
          class="mt-2 px-4 py-1.5 bg-slate-700 text-white rounded-md text-sm hover:bg-slate-800">
          Kirim Link Reset
        </button>
      </div>
    </div>
  </div>
</template>