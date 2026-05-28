<script setup>
import SimpleMaster from '@/components/SimpleMaster.vue'
const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama' },
  { key: 'type', label: 'Tipe' },
  { key: 'category', label: 'Kategori' },
  { key: 'is_taxable', label: 'Kena Pajak', formatter: v => v ? 'Ya' : 'Tidak' },
]
const fields = [
  { key: 'code', label: 'Kode', required: true },
  { key: 'name', label: 'Nama', required: true },
  { key: 'type', label: 'Tipe', type: 'select', required: true, options: [
    { value: 'EARNING', label: 'Pendapatan' },
    { value: 'DEDUCTION', label: 'Potongan' },
  ]},
  { key: 'category', label: 'Kategori', type: 'select', required: true, options: [
    { value: 'BASIC', label: 'Gaji Pokok' },
    { value: 'ALLOWANCE', label: 'Tunjangan' },
    { value: 'BONUS', label: 'Bonus' },
    { value: 'OVERTIME', label: 'Lembur' },
    { value: 'THR', label: 'THR' },
    { value: 'REIMBURSEMENT', label: 'Reimbursement' },
    { value: 'BPJS_KES', label: 'BPJS Kesehatan' },
    { value: 'BPJS_TK', label: 'BPJS Ketenagakerjaan' },
    { value: 'PPH21', label: 'PPh 21' },
    { value: 'LOAN', label: 'Kasbon' },
    { value: 'OTHER', label: 'Lainnya' },
  ]},
  { key: 'is_taxable', label: 'Kena Pajak (1/0)', type: 'number' },
  { key: 'is_fixed', label: 'Tetap (1/0)', type: 'number' },
  { key: 'amount', label: 'Default Nominal', type: 'number' },
]
</script>
<template><SimpleMaster endpoint="/payroll-components" title="Komponen Gaji" :columns="columns" :fields="fields" :defaults="{ is_active: 1, is_taxable: 1, is_fixed: 1, amount: 0 }" import-resource="payroll_components" /></template>