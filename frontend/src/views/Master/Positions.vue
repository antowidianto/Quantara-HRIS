<script setup>
import SimpleMaster from '@/components/SimpleMaster.vue'
const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama' },
  { key: 'is_active', label: 'Status', formatter: v => v ? 'Aktif' : 'Non-aktif' },
]
const fields = [
  { key: 'code', label: 'Kode', required: true },
  { key: 'name', label: 'Nama', required: true },
  { key: 'description', label: 'Deskripsi', type: 'textarea' },
]
</script>
<template><SimpleMaster endpoint="/positions" title="Jabatan" :columns="columns" :fields="fields" :defaults="{ is_active: 1 }" /></template>