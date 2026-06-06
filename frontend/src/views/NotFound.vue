<script setup>
import { RouterLink } from 'vue-router'
import { useI18n } from '@/locales'

const { t } = useI18n()
</script>
<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
    <div class="text-center">
      <div class="text-6xl font-bold text-primary-600 mb-2">404</div>
      <h1 class="text-2xl font-semibold mb-2">{{ t('common.not_found_title') }}</h1>
      <p class="text-gray-500 mb-6">{{ t('common.not_found_subtitle') }}</p>
      <RouterLink to="/dashboard" class="btn-primary">{{ t('common.back_to_dashboard') }}</RouterLink>
    </div>
  </div>
</template>
