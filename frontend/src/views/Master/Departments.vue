<script setup>
import { computed } from 'vue'
import SimpleMaster from '@/components/SimpleMaster.vue'
import { useI18n } from '@/locales'

const { t } = useI18n()

const columns = computed(() => [
  { key: 'code', label: t('master.common.code') },
  { key: 'name', label: t('master.common.name') },
  { key: 'description', label: t('master.common.description') },
  { key: 'is_active', label: t('master.common.status'), formatter: v => v ? t('master.common.active') : t('master.common.inactive') },
])
const fields = computed(() => [
  { key: 'code', label: t('master.common.code'), required: true },
  { key: 'name', label: t('master.common.name'), required: true },
  { key: 'description', label: t('master.common.description'), type: 'textarea' },
])
</script>
<template>
  <SimpleMaster
    endpoint="/departments"
    :title="t('master.departments.title')"
    :columns="columns"
    :fields="fields"
    :defaults="{ is_active: 1 }"
    import-resource="departments"
  />
</template>