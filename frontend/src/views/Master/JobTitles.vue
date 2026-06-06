<script setup>
/**
 * Master Job Titles
 *
 * Standar nama jabatan terstandarisasi untuk laporan compa-ratio,
 * salary benchmark, dan WLKP. Lihat backend migration #064.
 */
import { computed } from 'vue'
import SimpleMaster from '@/components/SimpleMaster.vue'
import { useI18n } from '@/locales'

const { t } = useI18n()

const columns = computed(() => [
  { key: 'code',     label: t('master.common.code') },
  { key: 'name',     label: t('master.job_titles.job_title_name') },
  { key: 'family',   label: t('master.job_titles.job_family'), formatter: v => v || '—' },
  { key: 'level',    label: t('master.job_titles.level') },
  { key: 'is_active', label: t('master.common.status'), formatter: v => v ? t('master.common.active') : t('master.common.inactive') },
])

const fields = computed(() => [
  { key: 'code',        label: t('master.job_titles.code_unique'), required: true },
  { key: 'name',        label: t('master.job_titles.job_title_name'), required: true },
  { key: 'family',      label: t('master.job_titles.job_family_field') },
  {
    key: 'level',
    label: t('master.job_titles.level'),
    type: 'select',
    options: [
      { value: 'JUNIOR',    label: t('master.job_titles.level_junior') },
      { value: 'MID',       label: t('master.job_titles.level_mid') },
      { value: 'SENIOR',    label: t('master.job_titles.level_senior') },
      { value: 'LEAD',      label: t('master.job_titles.level_lead') },
      { value: 'MANAGER',   label: t('master.job_titles.level_manager') },
      { value: 'DIRECTOR',  label: t('master.job_titles.level_director') },
      { value: 'EXECUTIVE', label: t('master.job_titles.level_executive') },
    ],
  },
  { key: 'description', label: t('master.common.description'), type: 'textarea' },
])
</script>

<template>
  <div>
    <div class="bg-blue-50 border border-blue-200 rounded p-3 mb-4 text-sm text-blue-900">
      <p class="font-semibold mb-1">{{ t('master.job_titles.info_title') }}</p>
      <p>
        {{ t('master.job_titles.info_desc', { bold: t('master.job_titles.info_bold') }) }}
      </p>
    </div>

    <SimpleMaster
      endpoint="/job-titles"
      :title="t('master.job_titles.title')"
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