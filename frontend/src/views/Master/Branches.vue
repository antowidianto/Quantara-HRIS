<script setup>
import SimpleMaster from '@/components/SimpleMaster.vue'

const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama' },
  { key: 'city', label: 'Kota' },
  { key: 'phone', label: 'Telepon' },
  { key: 'is_active', label: 'Status', formatter: v => v ? 'Aktif' : 'Non-aktif' },
]
const fields = [
  { key: 'code', label: 'Kode', required: true },
  { key: 'name', label: 'Nama', required: true },
  { key: 'address', label: 'Alamat', type: 'textarea' },
  { key: 'province', label: 'Provinsi' },
  { key: 'city', label: 'Kota' },
  { key: 'phone', label: 'Telepon' },
  { key: 'lat', label: 'Latitude', type: 'number', step: 'any' },
  { key: 'lng', label: 'Longitude', type: 'number', step: 'any' },
  { key: 'geofence_radius_m', label: 'Radius Geofence (m)', type: 'number' },
]
</script>

<template>
  <SimpleMaster endpoint="/branches" title="Cabang" :columns="columns" :fields="fields" :defaults="{ is_active: 1 }" />
</template>