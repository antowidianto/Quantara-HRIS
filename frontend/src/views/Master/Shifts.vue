<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import SimpleMaster from '@/components/SimpleMaster.vue'

// Sumber daftar branch / work_location / department untuk dropdown.
// Reaktif: kita populate setelah mount supaya FE tidak block.
const branches = ref([])
const workLocations = ref([])
const departments = ref([])

onMounted(async () => {
  try {
    const [b, w, d] = await Promise.all([
      api.get('/branches', { params: { per_page: 200 } }),
      api.get('/work-locations', { params: { per_page: 200 } }),
      api.get('/departments', { params: { per_page: 200 } }),
    ])
    branches.value = (b.data.data || []).map(x => ({ value: x.id, label: `${x.code} — ${x.name}` }))
    workLocations.value = (w.data.data || []).map(x => ({ value: x.id, label: `${x.code} — ${x.name}` }))
    departments.value = (d.data.data || []).map(x => ({ value: x.id, label: x.name }))
  } catch (_) { /* silent */ }
})

const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama Shift' },
  { key: 'start_time', label: 'Mulai', formatter: v => v ? String(v).slice(0,5) : '-' },
  { key: 'end_time', label: 'Selesai', formatter: v => v ? String(v).slice(0,5) : '-' },
  { key: 'branch_name', label: 'Cabang' },
  { key: 'work_location_name', label: 'Lokasi Kerja' },
  { key: 'working_days', label: 'Hari Kerja', formatter: v => formatWorkingDays(v) },
  { key: 'is_default', label: 'Default', formatter: v => Number(v) === 1 ? '✓' : '' },
]

const fields = [
  { key: 'code', label: 'Kode', required: true },
  { key: 'name', label: 'Nama Shift', required: true },
  { key: 'start_time', label: 'Jam Mulai', type: 'time', required: true },
  { key: 'end_time', label: 'Jam Selesai', type: 'time', required: true },
  { key: 'crosses_midnight', label: 'Lewat Tengah Malam (shift malam)', type: 'checkbox' },
  { key: 'break_minutes', label: 'Istirahat (menit)', type: 'number' },
  { key: 'late_tolerance_minutes', label: 'Toleransi Telat (menit)', type: 'number' },
  { key: 'early_leave_tolerance_minutes', label: 'Toleransi Pulang Cepat (menit)', type: 'number' },
  { key: 'branch_id', label: 'Cabang (kosongkan = berlaku company-wide)', type: 'select', options: branches },
  { key: 'work_location_id', label: 'Lokasi Kerja (opsional)', type: 'select', options: workLocations },
  { key: 'department_id', label: 'Departemen (opsional)', type: 'select', options: departments },
  { key: 'working_days', label: 'Hari Kerja (CSV ISO 1=Sen … 7=Min, kosong = semua hari)' },
  { key: 'color', label: 'Warna (hex, mis. #2563eb)', type: 'color' },
  { key: 'is_default', label: 'Jadikan default untuk scope ini', type: 'checkbox' },
  { key: 'description', label: 'Deskripsi', type: 'textarea' },
]

function formatWorkingDays(csv) {
  if (! csv) return 'Semua hari'
  const map = { 1: 'Sen', 2: 'Sel', 3: 'Rab', 4: 'Kam', 5: 'Jum', 6: 'Sab', 7: 'Min' }
  return String(csv).split(',').map(d => map[Number(d.trim())]).filter(Boolean).join(', ')
}
</script>

<template>
  <SimpleMaster
    endpoint="/shifts"
    title="Shift Kerja"
    :columns="columns"
    :fields="fields"
    import-resource="shifts"
    :defaults="{
      is_active: 1,
      break_minutes: 60,
      late_tolerance_minutes: 0,
      early_leave_tolerance_minutes: 0,
      crosses_midnight: 0,
      is_default: 0,
      working_days: '1,2,3,4,5',
      color: '#2563eb',
    }"
  >
    <template #cell-color="{ item }">
      <span class="inline-flex items-center gap-2">
        <span
          class="inline-block w-4 h-4 rounded border border-gray-200"
          :style="{ background: item.color || '#94a3b8' }"
        ></span>
        <span class="text-xs text-gray-500">{{ item.color || '-' }}</span>
      </span>
    </template>
  </SimpleMaster>
</template>