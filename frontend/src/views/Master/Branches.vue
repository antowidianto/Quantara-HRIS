<script setup>
import { computed } from 'vue'
import SimpleMaster from '@/components/SimpleMaster.vue'
import { useI18n } from '@/locales'

const { t } = useI18n()

const columns = computed(() => [
  { key: 'code', label: t('master.branches.code') },
  { key: 'name', label: t('master.branches.name') },
  { key: 'city', label: t('master.branches.city') },
  { key: 'phone', label: t('master.branches.phone') },
  { key: 'lat', label: t('master.branches.geofence'), formatter: (_v, item) => {
      if (item.lat && item.lng) {
        const r = item.geofence_radius_m ? `${item.geofence_radius_m} m` : t('master.branches.no_radius')
        return `✓ ${r}`
      }
      return t('master.branches.not_set')
    } },
  { key: 'is_active', label: t('master.common.status'), formatter: v => v ? t('master.common.active') : t('master.common.inactive') },
])
const fields = computed(() => [
  { key: 'code', label: t('master.common.code'), required: true },
  { key: 'name', label: t('master.common.name'), required: true },
  { key: 'address', label: t('master.common.address'), type: 'textarea' },
  { key: 'province', label: t('master.common.province') },
  { key: 'city', label: t('master.common.city') },
  { key: 'phone', label: t('master.common.phone') },
  // Geofence picker (lat/lng + radius) — sumber kebenaran untuk validasi clock-in.
  { key: 'location', type: 'location' },
])
</script>

<template>
  <SimpleMaster
    endpoint="/branches"
    :title="t('master.branches.title')"
    :columns="columns"
    :fields="fields"
    :defaults="{ is_active: 1 }"
    import-resource="branches"
  />
</template>