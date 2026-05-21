<script setup>
import SimpleMaster from '@/components/SimpleMaster.vue'
const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama' },
  { key: 'city', label: 'Kota' },
  { key: 'email', label: 'Email' },
  { key: 'is_active', label: 'Status', formatter: v => v ? 'Aktif' : 'Non-aktif' },
]
const fields = [
  { key: 'code', label: 'Kode', required: true },
  { key: 'name', label: 'Nama', required: true },
  { key: 'legal_name', label: 'Nama Legal' },
  { key: 'npwp', label: 'NPWP' },
  { key: 'email', label: 'Email', type: 'email' },
  { key: 'phone', label: 'Telepon' },
  { key: 'address', label: 'Alamat', type: 'textarea' },
  { key: 'province', label: 'Provinsi' },
  { key: 'city', label: 'Kota' },
  { key: 'timezone', label: 'Timezone' },
  { key: 'currency', label: 'Mata Uang' },
]
</script>
<template>
  <SimpleMaster endpoint="/companies" title="Perusahaan / Business Unit" :columns="columns" :fields="fields" :defaults="{ is_active: 1, timezone: 'Asia/Jakarta', currency: 'IDR' }" />
</template>