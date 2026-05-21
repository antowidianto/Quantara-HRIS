<script setup>
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'

const toast = useToast()
const email = ref('')
const sent = ref(false)
const loading = ref(false)

async function submit() {
  loading.value = true
  try {
    await api.post('/auth/forgot-password', { email: email.value })
    sent.value = true
    toast.success('Jika email terdaftar, link reset password akan dikirim')
  } catch (e) {
    toast.error('Gagal mengirim permintaan')
  } finally { loading.value = false }
}
</script>
<template>
  <div class="min-h-screen flex items-center justify-center px-4 bg-gray-50">
    <div class="max-w-md w-full">
      <div class="card">
        <div class="card-body">
          <h1 class="text-xl font-bold mb-2">Lupa Password</h1>
          <p class="text-sm text-gray-500 mb-6">Masukkan email Anda. Kami akan kirim link reset password.</p>
          <div v-if="sent" class="bg-green-50 text-green-700 p-4 rounded text-sm">
            Permintaan berhasil dikirim. Cek email Anda.
          </div>
          <form v-else @submit.prevent="submit" class="space-y-4">
            <div>
              <label class="label">Email</label>
              <input v-model="email" type="email" class="input" required />
            </div>
            <button type="submit" class="btn-primary w-full justify-center" :disabled="loading">
              {{ loading ? 'Mengirim...' : 'Kirim Link Reset' }}
            </button>
          </form>
          <div class="text-center mt-4">
            <RouterLink to="/login" class="text-sm text-primary-600 hover:underline">Kembali ke Login</RouterLink>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>