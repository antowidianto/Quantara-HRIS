<script setup>
import { computed } from 'vue'
import { useI18n } from '@/locales'
import SimpleMaster from '@/components/SimpleMaster.vue'

const { t } = useI18n()

const columns = computed(() => [
  { key: 'code', label: t('companies.code') },
  { key: 'name', label: t('companies.name') },
  { key: 'city', label: t('companies.city') },
  { key: 'email', label: t('companies.email') },
  { key: 'is_active', label: t('companies.status'), formatter: v => v ? t('common.active') : t('common.inactive') },
])

const fields = computed(() => [
  { key: 'code', label: t('companies.code'), required: true },
  { key: 'name', label: t('companies.name'), required: true },
  { key: 'legal_name', label: t('companies.legalName') },
  { key: 'npwp', label: t('companies.npwp') },
  { key: 'email', label: t('companies.email'), type: 'email' },
  { key: 'phone', label: t('companies.phone') },
  { key: 'address', label: t('companies.address'), type: 'textarea' },
  { key: 'province', label: t('companies.province') },
  { key: 'city', label: t('companies.city') },
  { key: 'timezone', label: t('companies.timezone') },
  { key: 'currency', label: t('companies.currency') },
])
</script>
<template>
  <SimpleMaster endpoint="/companies" :title="t('companies.title')" :columns="columns" :fields="fields" :defaults="{ is_active: 1, timezone: 'Asia/Jakarta', currency: 'IDR' }" />
</template>
