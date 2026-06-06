<script setup>
import { computed } from 'vue'
import SimpleMaster from '@/components/SimpleMaster.vue'
import { fmtCurrency } from '@/composables/useResource'
import { useI18n } from '@/locales'

const { t } = useI18n()

const columns = computed(() => [
  { key: 'code', label: t('master.common.code') },
  { key: 'name', label: t('master.common.name') },
  { key: 'level', label: t('master.common.level') },
  { key: 'min_salary', label: t('master.common.min'), formatter: fmtCurrency },
  { key: 'max_salary', label: t('master.common.max'), formatter: fmtCurrency },
])
const fields = computed(() => [
  { key: 'code', label: t('master.common.code'), required: true },
  { key: 'name', label: t('master.common.name'), required: true },
  { key: 'level', label: t('master.common.level'), type: 'number', required: true },
  { key: 'min_salary', label: t('master.job_grades.min_salary'), type: 'number' },
  { key: 'max_salary', label: t('master.job_grades.max_salary'), type: 'number' },
])
</script>
<template>
  <SimpleMaster
    endpoint="/job-grades"
    :title="t('master.job_grades.title')"
    :columns="columns"
    :fields="fields"
    :defaults="{ is_active: 1, level: 1 }"
    import-resource="job_grades"
  />
</template>