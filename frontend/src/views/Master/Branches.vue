<script setup>
import SimpleMaster from '@/components/SimpleMaster.vue'

const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama' },
  { key: 'city', label: 'Kota' },
  { key: 'phone', label: 'Telepon' },
  { key: 'lat', label: 'Geofence', formatter: (_v, item) => {
      if (item.lat && item.lng) {
        const r = item.geofence_radius_m ? `${item.geofence_radius_m} m` : 'tanpa radius'
        return `✓ ${r}`
      }
      return '— belum di-set'
    } },
  { key: 'is_active', label: 'Status', formatter: v => v ? 'Aktif' : 'Non-aktif' },
]
const fields = [
  { key: 'code', label: 'Kode', required: true },
  { key: 'name', label: 'Nama', required: true },
  { key: 'address', label: 'Alamat', type: 'textarea' },
  { key: 'province', label: 'Provinsi' },
  { key: 'city', label: 'Kota' },
  { key: 'phone', label: 'Telepon' },
  // Geofence picker (lat/lng + radius) — sumber kebenaran untuk validasi clock-in.
  // Tombol "Ambil dari GPS sekarang" mengisi otomatis kalau admin sedang di lokasi.
  { key: 'location', type: 'location' },
]
</script>

<template>
  <SimpleMaster endpoint="/branches" title="Cabang" :columns="columns" :fields="fields" :defaults="{ is_active: 1 }" />
</template>