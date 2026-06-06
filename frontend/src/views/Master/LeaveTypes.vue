<script setup>
import { computed } from 'vue'
import SimpleMaster from '@/components/SimpleMaster.vue'
import { useI18n } from '@/locales'

const { t } = useI18n()

const columns = computed(() => [
  { key: 'code', label: t('master.common.code') },
  { key: 'name', label: t('master.common.name') },
  { key: 'category', label: t('master.leave_types.category') },
  { key: 'default_days', label: t('master.leave_types.default_days') },
  { key: 'is_paid', label: t('master.leave_types.paid'), formatter: v => v ? t('master.leave_types.yes') : t('master.leave_types.no') },
])
const fields = computed(() => [
  { key: 'code', label: t('master.common.code'), required: true },
  { key: 'name', label: t('master.common.name'), required: true },
  { key: 'category', label: t('master.leave_types.category'), type: 'select', required: true, options: [
    { value: 'ANNUAL',      label: t('master.leave_types.cat_annual') },
    { value: 'SICK',        label: t('master.leave_types.cat_sick') },
    { value: 'MATERNITY',   label: t('master.leave_types.cat_maternity') },
    { value: 'PATERNITY',   label: t('master.leave_types.cat_paternity') },
    { value: 'MENSTRUAL',   label: t('master.leave_types.cat_menstrual') },
    { value: 'MARRIAGE',    label: t('master.leave_types.cat_marriage') },
    { value: 'BEREAVEMENT', label: t('master.leave_types.cat_bereavement') },
    { value: 'HAJJ',        label: t('master.leave_types.cat_hajj') },
    { value: 'UNPAID',      label: t('master.leave_types.cat_unpaid') },
    { value: 'SPECIAL',     label: t('master.leave_types.cat_special') },
  ]},
  { key: 'default_days', label: t('master.leave_types.default_days_year'), type: 'number' },
  { key: 'min_tenure_months', label: t('master.leave_types.min_tenure'), type: 'number' },
  { key: 'is_paid', label: t('master.leave_types.paid_field'), type: 'number' },
  { key: 'requires_doc', label: t('master.leave_types.requires_doc_field'), type: 'number' },
  { key: 'gender_restriction', label: t('master.leave_types.gender_restriction'), type: 'select', options: [
    { value: 'ALL', label: t('master.leave_types.all') },
    { value: 'M',   label: t('master.leave_types.male') },
    { value: 'F',   label: t('master.leave_types.female') },
  ]},
  { key: 'description', label: t('master.common.description'), type: 'textarea' },
])
</script>
<template>
  <SimpleMaster
    endpoint="/leave-types"
    :title="t('master.leave_types.title')"
    :columns="columns"
    :fields="fields"
    :defaults="{ is_active: 1, is_paid: 1, default_days: 12, min_tenure_months: 12, gender_restriction: 'ALL', reset_yearly: 1 }"
    import-resource="leave_types"
  />
</template>