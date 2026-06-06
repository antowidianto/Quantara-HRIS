<script setup>
/**
 * Master Positions
 *
 * Posisi adalah slot organisasi (mis. "Backend Engineer di Tim Platform")
 * yang ditempel ke department + (opsional) job_grade. Sejak migration
 * #064, `job_title_id` jadi label terstandarisasi yang reusable lintas
 * tim — pakai picker dropdown supaya konsisten.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import SimpleMaster from '@/components/SimpleMaster.vue'
import { useI18n } from '@/locales'

const { t } = useI18n()

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

const columns = computed(() => [
  { key: 'code', label: t('master.common.code') },
  { key: 'name', label: t('master.positions.position_name') },
  { key: 'job_title_name', label: t('master.common.job_title'), formatter: v => v || '—' },
  { key: 'department_name', label: t('master.common.department'), formatter: v => v || '—' },
  { key: 'tier', label: t('master.positions.tier'), formatter: v => v || 'STAFF' },
  { key: 'is_active', label: t('master.common.status'), formatter: v => v ? t('master.common.active') : t('master.common.inactive') },
])

// Ref-based fields supaya options reaktif setelah loadOptions selesai
const fields = computed(() => [
  { key: 'code', label: t('master.common.code'), required: true },
  { key: 'name', label: t('master.positions.position_name'), required: true },
  {
    key: 'job_title_id',
    label: t('master.common.job_title'),
    type: 'select',
    options: jobTitles.value.map(j => ({
      value: j.id,
      label: j.family ? `${j.family} — ${j.name} (${j.level})` : `${j.name} (${j.level})`,
    })),
  },
  {
    key: 'department_id',
    label: t('master.common.department'),
    type: 'select',
    options: departments.value.map(d => ({ value: d.id, label: d.name })),
  },
  {
    key: 'job_grade_id',
    label: t('master.common.job_grade'),
    type: 'select',
    options: jobGrades.value.map(g => ({
      value: g.id,
      label: g.code ? `${g.code} — ${g.name}` : g.name,
    })),
  },
  {
    key: 'tier',
    label: t('master.positions.tier'),
    type: 'select',
    options: [
      { value: 'MANAGER',      label: t('master.positions.manager') },
      { value: 'ASST_MANAGER', label: t('master.positions.asst_manager') },
      { value: 'SUPERVISOR',   label: t('master.positions.supervisor') },
      { value: 'STAFF',        label: t('master.positions.staff') },
    ],
  },
  { key: 'description', label: t('master.common.description'), type: 'textarea' },
])
</script>

<template>
  <SimpleMaster
    endpoint="/positions"
    :title="t('master.positions.title')"
    :columns="columns"
    :fields="fields"
    :defaults="{ is_active: 1, tier: 'STAFF' }"
    import-resource="positions"
  />
</template>