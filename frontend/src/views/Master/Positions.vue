<script setup>
/**
 * Master Positions
 *
 * Posisi adalah slot organisasi (mis. "Backend Engineer di Tim Platform")
 * yang ditempel ke department + (opsional) job_grade. Sejak migration
 * #064, `job_title_id` jadi label terstandarisasi yang reusable lintas
 * tim — pakai picker dropdown supaya konsisten.
 */
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import SimpleMaster from '@/components/SimpleMaster.vue'

const jobTitles = ref([])
const departments = ref([])
const jobGrades = ref([])

async function loadOptions() {
  try {
    const [jt, dept, jg] = await Promise.all([
      api.get('/job-titles/picker').catch(() => ({ data: { data: { items: [] } } })),
      api.get('/departments', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
      api.get('/job-grades', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
    ])
    jobTitles.value = jt.data?.data?.items || []
    departments.value = dept.data?.data || []
    jobGrades.value = jg.data?.data || []
  } catch (_) { /* silent — fall back to free-text */ }
}

onMounted(loadOptions)

const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama Posisi' },
  { key: 'job_title_name', label: 'Master Jabatan', formatter: v => v || '—' },
  { key: 'department_name', label: 'Department', formatter: v => v || '—' },
  { key: 'tier', label: 'Tier', formatter: v => v || 'STAFF' },
  { key: 'is_active', label: 'Status', formatter: v => v ? 'Aktif' : 'Non-aktif' },
]

// Ref-based fields supaya options reaktif setelah loadOptions selesai
const fields = ref([
  { key: 'code', label: 'Kode', required: true },
  { key: 'name', label: 'Nama Posisi', required: true },
  {
    key: 'job_title_id',
    label: 'Master Jabatan',
    type: 'select',
    options: [],
  },
  {
    key: 'department_id',
    label: 'Department',
    type: 'select',
    options: [],
  },
  {
    key: 'job_grade_id',
    label: 'Job Grade',
    type: 'select',
    options: [],
  },
  {
    key: 'tier',
    label: 'Tier',
    type: 'select',
    options: [
      { value: 'MANAGER',      label: 'Manager' },
      { value: 'ASST_MANAGER', label: 'Asisten Manager' },
      { value: 'SUPERVISOR',   label: 'Supervisor' },
      { value: 'STAFF',        label: 'Staff' },
    ],
  },
  { key: 'description', label: 'Deskripsi', type: 'textarea' },
])

// Re-compute options setiap kali master data refresh selesai.
function syncFieldOptions() {
  fields.value = fields.value.map(f => {
    if (f.key === 'job_title_id') {
      return {
        ...f,
        options: jobTitles.value.map(j => ({
          value: j.id,
          label: j.family ? `${j.family} — ${j.name} (${j.level})` : `${j.name} (${j.level})`,
        })),
      }
    }
    if (f.key === 'department_id') {
      return {
        ...f,
        options: departments.value.map(d => ({ value: d.id, label: d.name })),
      }
    }
    if (f.key === 'job_grade_id') {
      return {
        ...f,
        options: jobGrades.value.map(g => ({
          value: g.id,
          label: g.code ? `${g.code} — ${g.name}` : g.name,
        })),
      }
    }
    return f
  })
}

// Watch sederhana via setTimeout setelah onMounted: pastikan dropdown
// terisi saat user buka modal. Hook callback dari onMounted lebih clean
// dengan watcher; pakai ini untuk kesederhanaan.
onMounted(async () => {
  await loadOptions()
  syncFieldOptions()
})
</script>

<template>
  <SimpleMaster
    endpoint="/positions"
    title="Posisi"
    :columns="columns"
    :fields="fields"
    :defaults="{ is_active: 1, tier: 'STAFF' }"
    import-resource="positions"
  />
</template>
