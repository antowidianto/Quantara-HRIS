<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import SimpleMaster from '@/components/SimpleMaster.vue'
import { useI18n } from '@/locales'

const { t } = useI18n()

// Sumber daftar branches untuk dropdown.
const branches = ref([])
onMounted(async () => {
  try {
    const res = await api.get('/branches', { params: { per_page: 200 } })
    branches.value = (res.data.data || []).map(b => ({ value: b.id, label: `${b.code} — ${b.name}` }))
  } catch (_) { /* silent */ }
})

const typeOptions = computed(() => [
  { value: 'HEAD_OFFICE',   label: t('master.work_locations.type_head_office') },
  { value: 'BRANCH_OFFICE', label: t('master.work_locations.type_branch_office') },
  { value: 'SUB_OFFICE',    label: t('master.work_locations.type_sub_office') },
  { value: 'PROJECT_SITE',  label: t('master.work_locations.type_project_site') },
  { value: 'CLIENT_OFFICE', label: t('master.work_locations.type_client_office') },
  { value: 'WAREHOUSE',     label: t('master.work_locations.type_warehouse') },
  { value: 'FACTORY',       label: t('master.work_locations.type_factory') },
  { value: 'REMOTE',        label: t('master.work_locations.type_remote') },
  { value: 'WFH',           label: t('master.work_locations.type_wfh') },
  { value: 'OTHER',         label: t('master.work_locations.type_other') },
])

const columns = computed(() => [
  { key: 'code', label: t('master.common.code') },
  { key: 'name', label: t('master.work_locations.location_name') },
  { key: 'type', label: t('master.common.type'), formatter: v => typeOptions.value.find(x => x.value === v)?.label || v },
  { key: 'branch_name', label: t('master.work_locations.parent_branch') },
  { key: 'city', label: t('master.common.city') },
  { key: 'lat', label: t('master.work_locations.geofence'), formatter: (_v, item) => {
      if (item.lat && item.lng) {
        const r = item.geofence_radius_m ? `${item.geofence_radius_m} m` : t('master.work_locations.no_radius')
        return `✓ ${r}`
      }
      return t('master.work_locations.not_set')
    } },
  { key: 'allow_clock_here', label: t('master.work_locations.allow_clock'), formatter: v => v ? t('master.work_locations.yes') : t('master.work_locations.no') },
  { key: 'is_active', label: t('master.common.status'), formatter: v => v ? t('master.common.active') : t('master.common.inactive') },
])

const fields = computed(() => [
  { key: 'code', label: t('master.common.code'), required: true },
  { key: 'name', label: t('master.work_locations.location_name'), required: true },
  { key: 'type', label: t('master.work_locations.location_type'), type: 'select', options: typeOptions.value, required: true },
  { key: 'branch_id', label: t('master.work_locations.parent_branch'), type: 'select', options: branches.value },
  { key: 'address', label: t('master.common.address'), type: 'textarea' },
  { key: 'province', label: t('master.common.province') },
  { key: 'city', label: t('master.common.city') },
  { key: 'postal_code', label: t('master.work_locations.postal_code') },
  { key: 'phone', label: t('master.common.phone') },
  // Geofence picker
  { key: 'location', type: 'location' },
  { key: 'allow_clock_here', label: t('master.work_locations.allow_clock_field'), type: 'checkbox' },
  { key: 'notes', label: t('common.notes'), type: 'textarea' },
])
</script>

<template>
  <SimpleMaster
    endpoint="/work-locations"
    :title="t('master.work_locations.title')"
    :columns="columns"
    :fields="fields"
    :defaults="{ is_active: 1, allow_clock_here: 1, type: 'BRANCH_OFFICE' }"
    import-resource="work_locations"
  />
</template>