<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const form = ref({ current_password: '', new_password: '', confirm_password: '' })
const loading = ref(false)

async function submit() {
  if (form.value.new_password !== form.value.confirm_password) {
    return toast.error('Konfirmasi password tidak sama')
  }
  loading.value = true
  try {
    await api.post('/auth/change-password', form.value)
    toast.success('Password diubah. Silakan login kembali.')
    auth.logoutLocal()
    router.push('/login')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  } finally { loading.value = false }
}
</script>
<template>
  <div class="max-w-md mx-auto">
    <h1 class="text-2xl font-bold mb-4">Ganti Password</h1>
    <form @submit.prevent="submit" class="card">
      <div class="card-body space-y-4">
        <div><label class="label">Password Saat Ini</label><input v-model="form.current_password" type="password" class="input" required /></div>
        <div><label class="label">Password Baru</label><input v-model="form.new_password" type="password" class="input" minlength="10" required /></div>
        <div><label class="label">Konfirmasi Password Baru</label><input v-model="form.confirm_password" type="password" class="input" minlength="10" required /></div>
        <p class="text-xs text-gray-500">Password minimal 10 karakter, mengandung huruf dan angka.</p>
      </div>
      <div class="card-body border-t flex justify-end">
        <button class="btn-primary" :disabled="loading" type="submit">{{ loading ? 'Menyimpan...' : 'Simpan' }}</button>
      </div>
    </form>
  </div>
</template>