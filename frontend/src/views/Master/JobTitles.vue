<script setup>
/**
 * Master Job Titles
 *
 * Standar nama jabatan terstandarisasi untuk laporan compa-ratio,
 * salary benchmark, dan WLKP. Lihat backend migration #064.
 *
 * Tabel ini auto-backfill dari `positions` saat migrate, jadi semua
 * nama posisi yang sudah ada akan punya entri di sini.
 */
import SimpleMaster from '@/components/SimpleMaster.vue'

const columns = [
  { key: 'code',     label: 'Kode' },
  { key: 'name',     label: 'Nama Jabatan' },
  { key: 'family',   label: 'Job Family', formatter: v => v || '—' },
  { key: 'level',    label: 'Level' },
  { key: 'is_active', label: 'Status', formatter: v => v ? 'Aktif' : 'Non-aktif' },
]

const fields = [
  { key: 'code',        label: 'Kode (unik per perusahaan)', required: true },
  { key: 'name',        label: 'Nama Jabatan', required: true },
  { key: 'family',      label: 'Job Family (Engineering, Sales, ...)' },
  {
    key: 'level',
    label: 'Level',
    type: 'select',
    options: [
      { value: 'JUNIOR',    label: 'Junior' },
      { value: 'MID',       label: 'Mid' },
      { value: 'SENIOR',    label: 'Senior' },
      { value: 'LEAD',      label: 'Lead' },
      { value: 'MANAGER',   label: 'Manager' },
      { value: 'DIRECTOR',  label: 'Director' },
      { value: 'EXECUTIVE', label: 'Executive (C-Level)' },
    ],
  },
  { key: 'description', label: 'Deskripsi', type: 'textarea' },
]
</script>

<template>
  <div>
    <div class="bg-blue-50 border border-blue-200 rounded p-3 mb-4 text-sm text-blue-900">
      <p class="font-semibold mb-1">Tentang Master Jabatan</p>
      <p>
        Tabel ini menstandarisasi nama jabatan supaya laporan
        <span class="font-medium">compa-ratio, salary benchmark, dan WLKP</span>
        tidak terganggu varian penulisan ("Sr Backend Eng" vs "Senior Backend Engineer").
        Satu jabatan bisa dipakai oleh banyak posisi di department berbeda.
      </p>
    </div>

    <SimpleMaster
      endpoint="/job-titles"
      title="Master Jabatan"
      :columns="columns"
      :fields="fields"
      :defaults="{ is_active: 1, level: 'MID' }"
      import-resource="job_titles"
    >
      <template #cell-level="{ item }">
        <span
          class="inline-block px-2 py-0.5 rounded text-xs font-medium"
          :class="{
            'bg-gray-100 text-gray-700':    item.level === 'JUNIOR',
            'bg-blue-100 text-blue-700':    item.level === 'MID',
            'bg-indigo-100 text-indigo-700':item.level === 'SENIOR',
            'bg-purple-100 text-purple-700':item.level === 'LEAD',
            'bg-amber-100 text-amber-700':  item.level === 'MANAGER',
            'bg-orange-100 text-orange-700':item.level === 'DIRECTOR',
            'bg-rose-100 text-rose-700':    item.level === 'EXECUTIVE',
          }"
        >{{ item.level }}</span>
      </template>
    </SimpleMaster>
  </div>
</template>