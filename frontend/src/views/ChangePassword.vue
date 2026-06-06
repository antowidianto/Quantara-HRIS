<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { useI18n } from '@/locales'

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const { t } = useI18n()
const form = ref({ current_password: '', new_password: '', confirm_password: '' })
const loading = ref(false)

async function submit() {
  if (form.value.new_password !== form.value.confirm_password) {
    return toast.error(t('auth.password_mismatch'))
  }
  loading.value = true
  try {
    await api.post('/auth/change-password', form.value)
    toast.success(t('auth.password_changed'))
    auth.logoutLocal()
    router.push('/login')
  } catch (e) {
    toast.error(e.response?.data?.message || t('common.error'))
  } finally { loading.value = false }
}
</script>
<template>
  <div class="max-w-md mx-auto">
    <h1 class="text-2xl font-bold mb-4">{{ t('auth.change_password_title') }}</h1>
    <form @submit.prevent="submit" class="card">
      <div class="card-body space-y-4">
        <div><label class="label">{{ t('auth.current_password') }}</label><input v-model="form.current_password" type="password" class="input" required /></div>
        <div><label class="label">{{ t('auth.new_password') }}</label><input v-model="form.new_password" type="password" class="input" minlength="10" required /></div>
        <div><label class="label">{{ t('auth.confirm_password') }}</label><input v-model="form.confirm_password" type="password" class="input" minlength="10" required /></div>
        <p class="text-xs text-gray-500">{{ t('auth.change_password_note') }}</p>
      </div>
      <div class="card-body border-t flex justify-end">
        <button class="btn-primary" :disabled="loading" type="submit">{{ loading ? t('common.saving') : t('common.save') }}</button>
      </div>
    </form>
  </div>
</template>
