<script setup>
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useI18n } from '@/locales'

const toast = useToast()
const { t } = useI18n()
const email = ref('')
const sent = ref(false)
const loading = ref(false)

async function submit() {
  loading.value = true
  try {
    await api.post('/auth/forgot-password', { email: email.value })
    sent.value = true
    toast.success(t('auth.forgot_sent'))
  } catch (e) {
    toast.error(t('common.error'))
  } finally { loading.value = false }
}
</script>
<template>
  <div class="min-h-screen flex items-center justify-center px-4 bg-gray-50">
    <div class="max-w-md w-full">
      <div class="card">
        <div class="card-body">
          <h1 class="text-xl font-bold mb-2">{{ t('auth.forgot_title') }}</h1>
          <p class="text-sm text-gray-500 mb-6">{{ t('auth.forgot_subtitle') }}</p>
          <div v-if="sent" class="bg-green-50 text-green-700 p-4 rounded text-sm">
            {{ t('auth.forgot_sent') }}
          </div>
          <form v-else @submit.prevent="submit" class="space-y-4">
            <div>
              <label class="label">{{ t('auth.email') }}</label>
              <input v-model="email" type="email" class="input" required />
            </div>
            <button type="submit" class="btn-primary w-full justify-center" :disabled="loading">
              {{ loading ? t('common.processing') : t('auth.send_reset_link') }}
            </button>
          </form>
          <div class="text-center mt-4">
            <RouterLink to="/login" class="text-sm text-primary-600 hover:underline">{{ t('auth.back_to_login') }}</RouterLink>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
