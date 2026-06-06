<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import SimpleMaster from '@/components/SimpleMaster.vue'
import { useI18n } from '@/locales'

const { t } = useI18n()

// Sumber daftar branch / work_location / department untuk dropdown.
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

const columns = computed(() => [
  { key: 'code', label: t('master.common.code') },
  { key: 'name', label: t('master.shifts.shift_name') },
  { key: 'start_time', label: t('master.shifts.start_short'), formatter: v => v ? String(v).slice(0,5) : '-' },
  { key: 'end_time', label: t('master.shifts.end_short'), formatter: v => v ? String(v).slice(0,5) : '-' },
  { key: 'branch_name', label: t('master.shifts.branch') },
  { key: 'work_location_name', label: t('master.shifts.work_location') },
  { key: 'working_days', label: t('master.shifts.working_days'), formatter: v => formatWorkingDays(v) },
  { key: 'is_default', label: t('master.shifts.default_short'), formatter: v => Number(v) === 1 ? '✓' : '' },
])

const fields = computed(() => [
  { key: 'code', label: t('master.common.code'), required: true },
  { key: 'name', label: t('master.shifts.shift_name'), required: true },
  { key: 'start_time', label: t('master.shifts.start_time'), type: 'time', required: true },
  { key: 'end_time', label: t('master.shifts.end_time'), type: 'time', required: true },
  { key: 'crosses_midnight', label: t('master.shifts.crosses_midnight'), type: 'checkbox' },
  { key: 'break_minutes', label: t('master.shifts.break_minutes'), type: 'number' },
  { key: 'late_tolerance_minutes', label: t('master.shifts.late_tolerance'), type: 'number' },
  { key: 'early_leave_tolerance_minutes', label: t('master.shifts.early_leave_tolerance'), type: 'number' },
  { key: 'branch_id', label: t('master.shifts.branch_field'), type: 'select', options: branches.value },
  { key: 'work_location_id', label: t('master.shifts.work_location_field'), type: 'select', options: workLocations.value },
  { key: 'department_id', label: t('master.shifts.department_field'), type: 'select', options: departments.value },
  { key: 'working_days', label: t('master.shifts.working_days_field') },
  { key: 'color', label: t('master.shifts.color_field'), type: 'color' },
  { key: 'is_default', label: t('master.shifts.is_default_field'), type: 'checkbox' },
  { key: 'description', label: t('master.common.description'), type: 'textarea' },
])

function formatWorkingDays(csv) {
  if (! csv) return t('master.shifts.all_days')
  const map = {
    1: t('master.shifts.day_mon'),
    2: t('master.shifts.day_tue'),
    3: t('master.shifts.day_wed'),
    4: t('master.shifts.day_thu'),
    5: t('master.shifts.day_fri'),
    6: t('master.shifts.day_sat'),
    7: t('master.shifts.day_sun'),
  }
  return String(csv).split(',').map(d => map[Number(d.trim())]).filter(Boolean).join(', ')
}
</script>

<template>
  <SimpleMaster
    endpoint="/shifts"
    :title="t('master.shifts.title')"
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