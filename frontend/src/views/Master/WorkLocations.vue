<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import SimpleMaster from '@/components/SimpleMaster.vue'

// Sumber daftar branches untuk dropdown.
const branches = ref([])
onMounted(async () => {
  try {
    const res = await api.get('/branches', { params: { per_page: 200 } })
    branches.value = (res.data.data || []).map(b => ({ value: b.id, label: `${b.code} — ${b.name}` }))
  } catch (_) { /* silent */ }
})

const typeOptions = [
  { value: 'HEAD_OFFICE', label: 'Kantor Pusat' },
  { value: 'BRANCH_OFFICE', label: 'Kantor Cabang' },
  { value: 'SUB_OFFICE', label: 'Kantor Sub-Cabang' },
  { value: 'PROJECT_SITE', label: 'Lokasi Proyek' },
  { value: 'CLIENT_OFFICE', label: 'Kantor Klien' },
  { value: 'WAREHOUSE', label: 'Gudang' },
  { value: 'FACTORY', label: 'Pabrik' },
  { value: 'REMOTE', label: 'Remote' },
  { value: 'WFH', label: 'Work From Home' },
  { value: 'OTHER', label: 'Lainnya' },
]

const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama Lokasi' },
  { key: 'type', label: 'Tipe', formatter: v => typeOptions.find(t => t.value === v)?.label || v },
  { key: 'branch_name', label: 'Cabang Induk' },
  { key: 'city', label: 'Kota' },
  { key: 'lat', label: 'Geofence', formatter: (_v, item) => {
      if (item.lat && item.lng) {
        const r = item.geofence_radius_m ? `${item.geofence_radius_m} m` : 'tanpa radius'
        return `✓ ${r}`
      }
      return '— belum di-set'
    } },
  { key: 'allow_clock_here', label: 'Clock In/Out', formatter: v => v ? 'Ya' : 'Tidak' },
  { key: 'is_active', label: 'Status', formatter: v => v ? 'Aktif' : 'Non-aktif' },
]

const fields = [
  { key: 'code', label: 'Kode', required: true },
  { key: 'name', label: 'Nama Lokasi', required: true },
  { key: 'type', label: 'Tipe Lokasi', type: 'select', options: typeOptions, required: true },
  { key: 'branch_id', label: 'Cabang Induk', type: 'select', options: branches },
  { key: 'address', label: 'Alamat', type: 'textarea' },
  { key: 'province', label: 'Provinsi' },
  { key: 'city', label: 'Kota' },
  { key: 'postal_code', label: 'Kode Pos' },
  { key: 'phone', label: 'Telepon' },
  // Geofence picker (lat/lng + radius) — admin bisa "Ambil dari GPS sekarang"
  // saat berdiri di lokasi, atau isi manual decimal kalau dari desktop.
  { key: 'location', type: 'location' },
  { key: 'allow_clock_here', label: 'Izinkan Clock In/Out', type: 'checkbox' },
  { key: 'notes', label: 'Catatan', type: 'textarea' },
]
</script>

<template>
  <SimpleMaster
    endpoint="/work-locations"
    title="Lokasi Kerja"
    :columns="columns"
    :fields="fields"
    :defaults="{ is_active: 1, allow_clock_here: 1, type: 'BRANCH_OFFICE' }"
    import-resource="work_locations"
  />
</template>