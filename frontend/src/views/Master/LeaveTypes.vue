<script setup>
import SimpleMaster from '@/components/SimpleMaster.vue'
const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama' },
  { key: 'category', label: 'Kategori' },
  { key: 'default_days', label: 'Default Hari' },
  { key: 'is_paid', label: 'Berbayar', formatter: v => v ? 'Ya' : 'Tidak' },
]
const fields = [
  { key: 'code', label: 'Kode', required: true },
  { key: 'name', label: 'Nama', required: true },
  { key: 'category', label: 'Kategori', type: 'select', required: true, options: [
    { value: 'ANNUAL', label: 'Cuti Tahunan' },
    { value: 'SICK', label: 'Sakit' },
    { value: 'MATERNITY', label: 'Melahirkan' },
    { value: 'PATERNITY', label: 'Ayah' },
    { value: 'MENSTRUAL', label: 'Haid' },
    { value: 'MARRIAGE', label: 'Menikah' },
    { value: 'BEREAVEMENT', label: 'Duka' },
    { value: 'HAJJ', label: 'Haji' },
    { value: 'UNPAID', label: 'Tidak Berbayar' },
    { value: 'SPECIAL', label: 'Khusus' },
  ]},
  { key: 'default_days', label: 'Default Hari/Tahun', type: 'number' },
  { key: 'min_tenure_months', label: 'Minimal Masa Kerja (bulan)', type: 'number' },
  { key: 'is_paid', label: 'Berbayar (1/0)', type: 'number' },
  { key: 'requires_doc', label: 'Butuh Dokumen (1/0)', type: 'number' },
  { key: 'gender_restriction', label: 'Hanya Untuk', type: 'select', options: [
    { value: 'ALL', label: 'Semua' },
    { value: 'M', label: 'Laki-laki' },
    { value: 'F', label: 'Perempuan' },
  ]},
  { key: 'description', label: 'Deskripsi', type: 'textarea' },
]
</script>
<template><SimpleMaster endpoint="/leave-types" title="Tipe Cuti" :columns="columns" :fields="fields" :defaults="{ is_active: 1, is_paid: 1, default_days: 12, min_tenure_months: 12, gender_restriction: 'ALL', reset_yearly: 1 }" /></template>