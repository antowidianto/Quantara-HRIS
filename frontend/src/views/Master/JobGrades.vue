<script setup>
import SimpleMaster from '@/components/SimpleMaster.vue'
import { fmtCurrency } from '@/composables/useResource'
const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama' },
  { key: 'level', label: 'Level' },
  { key: 'min_salary', label: 'Min', formatter: fmtCurrency },
  { key: 'max_salary', label: 'Max', formatter: fmtCurrency },
]
const fields = [
  { key: 'code', label: 'Kode', required: true },
  { key: 'name', label: 'Nama', required: true },
  { key: 'level', label: 'Level', type: 'number', required: true },
  { key: 'min_salary', label: 'Gaji Min', type: 'number' },
  { key: 'max_salary', label: 'Gaji Max', type: 'number' },
]
</script>
<template><SimpleMaster endpoint="/job-grades" title="Job Grade" :columns="columns" :fields="fields" :defaults="{ is_active: 1, level: 1 }" /></template>