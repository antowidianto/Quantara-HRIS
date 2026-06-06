<script setup>
import { computed } from 'vue'
import SimpleMaster from '@/components/SimpleMaster.vue'
import { useI18n } from '@/locales'

const { t } = useI18n()

const columns = computed(() => [
  { key: 'code', label: t('master.common.code') },
  { key: 'name', label: t('master.common.name') },
  { key: 'type', label: t('master.payroll_components.type') },
  { key: 'category', label: t('master.payroll_components.category') },
  { key: 'is_taxable', label: t('master.payroll_components.taxable'), formatter: v => v ? t('master.payroll_components.yes') : t('master.payroll_components.no') },
])
const fields = computed(() => [
  { key: 'code', label: t('master.common.code'), required: true },
  { key: 'name', label: t('master.common.name'), required: true },
  { key: 'type', label: t('master.payroll_components.type'), type: 'select', required: true, options: [
    { value: 'EARNING',   label: t('master.payroll_components.earning') },
    { value: 'DEDUCTION', label: t('master.payroll_components.deduction') },
  ]},
  { key: 'category', label: t('master.payroll_components.category'), type: 'select', required: true, options: [
    { value: 'BASIC',         label: t('master.payroll_components.cat_basic') },
    { value: 'ALLOWANCE',     label: t('master.payroll_components.cat_allowance') },
    { value: 'BONUS',         label: t('master.payroll_components.cat_bonus') },
    { value: 'OVERTIME',      label: t('master.payroll_components.cat_overtime') },
    { value: 'THR',           label: t('master.payroll_components.cat_thr') },
    { value: 'REIMBURSEMENT', label: t('master.payroll_components.cat_reimbursement') },
    { value: 'BPJS_KES',      label: t('master.payroll_components.cat_bpjs_kes') },
    { value: 'BPJS_TK',       label: t('master.payroll_components.cat_bpjs_tk') },
    { value: 'PPH21',         label: t('master.payroll_components.cat_pph21') },
    { value: 'LOAN',          label: t('master.payroll_components.cat_loan') },
    { value: 'OTHER',         label: t('master.payroll_components.cat_other') },
  ]},
  { key: 'is_taxable', label: t('master.payroll_components.taxable_field'), type: 'number' },
  { key: 'is_fixed', label: t('master.payroll_components.fixed_field'), type: 'number' },
  { key: 'amount', label: t('master.payroll_components.default_amount'), type: 'number' },
])
</script>
<template>
  <SimpleMaster
    endpoint="/payroll-components"
    :title="t('master.payroll_components.title')"
    :columns="columns"
    :fields="fields"
    :defaults="{ is_active: 1, is_taxable: 1, is_fixed: 1, amount: 0 }"
    import-resource="payroll_components"
  />
</template>