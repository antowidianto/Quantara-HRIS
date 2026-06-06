<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useI18n } from '@/locales'

const router = useRouter()
const route = useRoute()
const toast = useToast()
const { t } = useI18n()
const token = ref('')
const newPassword = ref('')
const confirm = ref('')
const loading = ref(false)

onMounted(() => { token.value = route.query.token || '' })

async function submit() {
  if (newPassword.value !== confirm.value) return toast.error(t('auth.password_mismatch'))
  loading.value = true
  try {
    await api.post('/auth/reset-password', { token: token.value, new_password: newPassword.value })
    toast.success(t('auth.reset_success'))
    router.push('/login')
  } catch (e) {
    toast.error(e.response?.data?.message || t('auth.reset_token_invalid'))
  } finally { loading.value = false }
}
</script>
<template>
  <div class="min-h-screen flex items-center justify-center px-4 bg-gray-50">
    <div class="max-w-md w-full">
      <div class="card">
        <div class="card-body">
          <h1 class="text-xl font-bold mb-2">{{ t('auth.reset_title') }}</h1>
          <form @submit.prevent="submit" class="space-y-4">
            <div><label class="label">{{ t('auth.new_password') }}</label><input v-model="newPassword" type="password" class="input" minlength="10" required /></div>
            <div><label class="label">{{ t('auth.confirm_password') }}</label><input v-model="confirm" type="password" class="input" minlength="10" required /></div>
            <button type="submit" class="btn-primary w-full justify-center" :disabled="loading">{{ loading ? t('common.saving') : t('auth.reset_title') }}</button>
          </form>
          <div class="text-center mt-4">
            <RouterLink to="/login" class="text-sm text-primary-600 hover:underline">{{ t('auth.back_to_login') }}</RouterLink>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
