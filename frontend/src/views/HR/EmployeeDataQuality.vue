<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from '@/locales'
import { useToast } from 'vue-toastification'
import { ArrowPathIcon, ShieldCheckIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import api from '@/api/client'

const { t, locale } = useI18n()
const toast = useToast()
const loading = ref(false)
const report = ref(null)
const selected = ref('identity_tax')

const categories = computed(() => report.value?.categories || {})
const selectedCategory = computed(() => categories.value[selected.value] || { count: 0, samples: [], truncated: false })

async function load() {
  loading.value = true
  try {
    const response = await api.get('/employees/data-quality', { params: { sample_limit: 100 } })
    report.value = response.data.data
    if (!categories.value[selected.value]) selected.value = Object.keys(categories.value)[0] || 'identity_tax'
  } catch (error) {
    toast.error(error.response?.data?.message || t('data_quality.load_failed'))
  } finally {
    loading.value = false
  }
}

function formatDate(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat(locale.value === 'id' ? 'id-ID' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function formatDetails(row) {
  const details = []
  if (row.expired_at) details.push(formatDate(row.expired_at))
  if (row.reference) details.push(row.reference)
  if (row.minimum_wage) details.push(`${row.wage_level} ${row.wage_year}: ${Number(row.minimum_wage).toLocaleString(locale.value === 'id' ? 'id-ID' : 'en-US')}`)
  return details.join(' · ') || '—'
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldCheckIcon class="h-7 w-7 text-emerald-600" />{{ t('data_quality.title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ t('data_quality.subtitle') }}</p>
      </div>
      <button class="btn-secondary" :disabled="loading" @click="load">
        <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': loading }" />{{ t('data_quality.refresh') }}
      </button>
    </div>

    <div v-if="loading && !report" class="card-body text-sm text-gray-500">{{ t('data_quality.loading') }}</div>
    <template v-else-if="report">
      <div class="grid gap-3 sm:grid-cols-3">
        <div class="card-body rounded-lg border border-gray-200"><div class="text-xs text-gray-500">{{ t('data_quality.active_employees') }}</div><div class="text-2xl font-bold">{{ report.total_active_employees }}</div></div>
        <div class="card-body rounded-lg border border-amber-200 bg-amber-50"><div class="text-xs text-amber-700">{{ t('data_quality.total_issues') }}</div><div class="text-2xl font-bold text-amber-900">{{ report.total_issues }}</div></div>
        <div class="card-body rounded-lg border border-gray-200"><div class="text-xs text-gray-500">{{ t('data_quality.generated_at') }}</div><div class="mt-1 text-sm font-medium">{{ formatDate(report.generated_at) }}</div></div>
      </div>

      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <button v-for="(category, key) in categories" :key="key" class="rounded-lg border p-4 text-left transition" :class="selected === key ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white hover:border-blue-300'" @click="selected = key">
          <div class="text-sm font-medium">{{ t(`data_quality.categories.${key}`) }}</div>
          <div class="mt-2 text-2xl font-bold" :class="category.count ? 'text-amber-700' : 'text-emerald-700'">{{ category.count }}</div>
        </button>
      </div>

      <div class="card overflow-hidden">
        <div class="flex items-center justify-between border-b px-4 py-3">
          <h2 class="font-semibold">{{ t(`data_quality.categories.${selected}`) }}</h2>
          <span class="text-sm text-gray-500">{{ selectedCategory.count }}</span>
        </div>
        <div v-if="!selectedCategory.samples.length" class="p-8 text-center text-sm text-emerald-700"><ShieldCheckIcon class="mx-auto mb-2 h-8 w-8" />{{ t('data_quality.no_issues') }}</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left">{{ t('data_quality.employee_number') }}</th><th class="px-4 py-2 text-left">{{ t('data_quality.employee_name') }}</th><th class="px-4 py-2 text-left">{{ t('data_quality.findings') }}</th><th class="px-4 py-2 text-left">{{ t('data_quality.details') }}</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(row, index) in selectedCategory.samples" :key="`${row.employee_id}-${index}`">
                <td class="px-4 py-3 font-mono text-xs">{{ row.employee_number || '—' }}</td><td class="px-4 py-3">{{ row.employee_name || '—' }}</td>
                <td class="px-4 py-3"><div class="flex flex-wrap gap-1"><span v-for="code in row.codes" :key="code" class="inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-1 text-xs text-amber-800"><ExclamationTriangleIcon class="h-3 w-3" />{{ t(`data_quality.codes.${code}`) }}</span></div></td>
                <td class="px-4 py-3 text-xs text-gray-500">{{ formatDetails(row) }}</td>
              </tr>
            </tbody>
          </table>
          <p class="border-t bg-gray-50 px-4 py-2 text-xs text-gray-500">{{ t('data_quality.samples_note') }}</p>
        </div>
      </div>
    </template>
  </div>
</template>
