<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'

const router = useRouter()
const route = useRoute()
const toast = useToast()
const token = ref('')
const newPassword = ref('')
const confirm = ref('')
const loading = ref(false)

onMounted(() => { token.value = route.query.token || '' })

async function submit() {
  if (newPassword.value !== confirm.value) return toast.error('Konfirmasi tidak sama')
  loading.value = true
  try {
    await api.post('/auth/reset-password', { token: token.value, new_password: newPassword.value })
    toast.success('Password berhasil direset. Silakan login.')
    router.push('/login')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Token tidak valid')
  } finally { loading.value = false }
}
</script>
<template>
  <div class="min-h-screen flex items-center justify-center px-4 bg-gray-50">
    <div class="max-w-md w-full">
      <div class="card">
        <div class="card-body">
          <h1 class="text-xl font-bold mb-2">Reset Password</h1>
          <form @submit.prevent="submit" class="space-y-4">
            <div><label class="label">Password Baru</label><input v-model="newPassword" type="password" class="input" minlength="10" required /></div>
            <div><label class="label">Konfirmasi</label><input v-model="confirm" type="password" class="input" minlength="10" required /></div>
            <button type="submit" class="btn-primary w-full justify-center" :disabled="loading">{{ loading ? 'Menyimpan...' : 'Reset Password' }}</button>
          </form>
          <div class="text-center mt-4">
            <RouterLink to="/login" class="text-sm text-primary-600 hover:underline">Kembali ke Login</RouterLink>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>