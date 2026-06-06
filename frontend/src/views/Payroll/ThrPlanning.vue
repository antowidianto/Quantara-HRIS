<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtCurrency } from '@/composables/useResource'
import {
  GiftIcon,
  ArrowPathIcon,
  ArrowDownTrayIcon,
  UsersIcon,
  BuildingOffice2Icon,
  BanknotesIcon,
  ChartBarIcon,
  InformationCircleIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 9 / H1.8 — THR Planning Dashboard.
 *
 * HR/Finance dapat memproyeksikan total THR per tahun sebelum payment date.
 * Berdasarkan PMK 6/2016: tenure ≥ 12 bulan → 1× gaji; < 12 bulan → proporsional.
 *
 * Endpoint: GET /thr/projection?year=YYYY&pay_date=YYYY-MM-DD
 *
 * Output backend:
 *   - count: total karyawan eligible
 *   - total_gross: grand total proyeksi
 *   - by_department[]: breakdown per dept
 *   - by_cost_center[]: breakdown per cost center
 *   - top_employees[]: 10 karyawan dengan THR terbesar
 */

const toast = useToast()
const { t } = useI18n()

const filters = reactive({
  year: new Date().getFullYear(),
  pay_date: '',
})

const data = ref(null)
const loading = ref(false)

async function fetchProjection() {
  loading.value = true
  try {
    const params = { year: filters.year }
    if (filters.pay_date) params.pay_date = filters.pay_date
    const res = await api.get('/thr/projection', { params })
    data.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || t('payroll.thr_planning.load_failed'))
  } finally {
    loading.value = false
  }
}

function exportCsv() {
  // Generate CSV dari top_employees + breakdown supaya finance bisa
  // edit di Excel sebelum forecast budget.
  if (!data.value) return
  const lines = [
    `THR Projection ${filters.year}`,
    `Generated: ${new Date().toISOString()}`,
    '',
    `Total Karyawan,${data.value.count}`,
    `Total Gross,${data.value.total_gross}`,
    '',
    '== Breakdown per Department ==',
    'Department,Count,Total',
    ...(data.value.by_department || []).map(
      (r) => `"${r.department_name || '—'}",${r.count},${r.total}`
    ),
    '',
    '== Breakdown per Cost Center ==',
    'Cost Center,Count,Total',
    ...(data.value.by_cost_center || []).map(
      (r) => `"${r.cost_center_name || '—'}",${r.count},${r.total}`
    ),
    '',
    '== Top 10 Karyawan ==',
    'Nama,Department,Tenure (bulan),THR Amount',
    ...(data.value.top_employees || []).map(
      (r) => `"${r.full_name}","${r.department_name || ''}",${r.tenure_months},${r.amount}`
    ),
  ]
  const csv = lines.join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `thr-projection-${filters.year}.csv`
  document.body.appendChild(a)
  a.click()
  setTimeout(() => {
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }, 0)
  toast.success(t('payroll.thr_planning.csv_downloaded'))
}

const avgPerEmployee = computed(() => {
  if (!data.value || data.value.count === 0) return 0
  return data.value.total_gross / data.value.count
})

// Tahun-tahun yang masuk akal: tahun lalu, sekarang, depan
const yearOptions = (() => {
  const y = new Date().getFullYear()
  return [y - 1, y, y + 1]
})()

onMounted(fetchProjection)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <GiftIcon class="w-6 h-6" /> {{ $t('payroll.thr_planning.title') }}
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          {{ $t('payroll.thr_planning.subtitle') }}
        </p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary" @click="fetchProjection" :disabled="loading">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
          {{ $t('payroll.thr_planning.recalculate') }}
        </button>
        <button class="btn-primary" @click="exportCsv" :disabled="!data || loading">
          <ArrowDownTrayIcon class="w-4 h-4" /> {{ $t('payroll.thr_planning.export_csv') }}
        </button>
      </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
      <div class="card-body grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div>
          <label class="label">{{ $t('payroll.thr_planning.year') }}</label>
          <select v-model.number="filters.year" class="input" @change="fetchProjection">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div>
          <label class="label">{{ $t('payroll.thr_planning.pay_date_optional') }}</label>
          <input type="date" v-model="filters.pay_date" class="input" @change="fetchProjection" />
          <p class="text-xs text-gray-500 mt-1">
            {{ $t('payroll.thr_planning.pay_date_default', { year: filters.year }) }}
          </p>
        </div>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
      <div class="card-body bg-blue-50 rounded-lg border border-blue-100">
        <div class="flex items-center gap-2 text-blue-700">
          <UsersIcon class="w-4 h-4" />
          <div class="text-xs font-medium">{{ $t('payroll.thr_planning.eligible_employees') }}</div>
        </div>
        <div class="text-2xl font-bold text-blue-900 mt-1">
          {{ data?.count?.toLocaleString('id-ID') || 0 }}
        </div>
      </div>
      <div class="card-body bg-emerald-50 rounded-lg border border-emerald-100 md:col-span-2">
        <div class="flex items-center gap-2 text-emerald-700">
          <BanknotesIcon class="w-4 h-4" />
          <div class="text-xs font-medium">{{ $t('payroll.thr_planning.total_projection') }}</div>
        </div>
        <div class="text-2xl font-bold text-emerald-900 mt-1">
          {{ fmtCurrency(data?.total_gross || 0) }}
        </div>
      </div>
      <div class="card-body bg-purple-50 rounded-lg border border-purple-100">
        <div class="flex items-center gap-2 text-purple-700">
          <ChartBarIcon class="w-4 h-4" />
          <div class="text-xs font-medium">
            {{ $t('payroll.thr_planning.average_per_employee') }}
          </div>
        </div>
        <div class="text-2xl font-bold text-purple-900 mt-1">
          {{ fmtCurrency(avgPerEmployee) }}
        </div>
      </div>
    </div>

    <div v-if="loading" class="card">
      <div class="card-body text-center py-12 text-gray-400">
        {{ $t('payroll.thr_planning.calculating') }}
      </div>
    </div>

    <div v-else-if="!data" class="card">
      <div class="card-body text-center py-12 text-gray-400">
        {{ $t('payroll.thr_planning.no_data') }}
      </div>
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Per Department -->
      <div class="card">
        <div class="card-header flex items-center gap-2">
          <BuildingOffice2Icon class="w-4 h-4" />
          <span class="font-medium">{{ $t('payroll.thr_planning.breakdown_department') }}</span>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>{{ $t('payroll.thr_planning.department') }}</th>
                <th class="text-right">{{ $t('payroll.thr_planning.employees') }}</th>
                <th class="text-right">{{ $t('payroll.thr_planning.total') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="r in data.by_department || []" :key="`d${r.department_id || 0}`">
                <td>{{ r.department_name || $t('payroll.thr_planning.no_department') }}</td>
                <td class="text-right">{{ r.count }}</td>
                <td class="text-right font-medium">{{ fmtCurrency(r.total) }}</td>
              </tr>
              <tr v-if="(data.by_department || []).length === 0">
                <td colspan="3" class="text-center py-6 text-gray-400">
                  {{ $t('common.no_data') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Per Cost Center -->
      <div class="card">
        <div class="card-header flex items-center gap-2">
          <BanknotesIcon class="w-4 h-4" />
          <span class="font-medium">{{ $t('payroll.thr_planning.breakdown_cost_center') }}</span>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>{{ $t('payroll.thr_planning.cost_center') }}</th>
                <th class="text-right">{{ $t('payroll.thr_planning.employees') }}</th>
                <th class="text-right">{{ $t('payroll.thr_planning.total') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="r in data.by_cost_center || []" :key="`cc${r.cost_center_id || 0}`">
                <td>{{ r.cost_center_name || $t('payroll.thr_planning.no_mapping') }}</td>
                <td class="text-right">{{ r.count }}</td>
                <td class="text-right font-medium">{{ fmtCurrency(r.total) }}</td>
              </tr>
              <tr v-if="(data.by_cost_center || []).length === 0">
                <td colspan="3" class="text-center py-6 text-gray-400">
                  {{ $t('common.no_data') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Top employees -->
    <div v-if="data?.top_employees?.length" class="card mt-4">
      <div class="card-header">
        <span class="font-medium">{{ $t('payroll.thr_planning.top_employees_title') }}</span>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>{{ $t('payroll.thr_planning.employees') }}</th>
              <th>{{ $t('payroll.thr_planning.department') }}</th>
              <th class="text-right">{{ $t('payroll.thr_planning.tenure') }}</th>
              <th class="text-right">THR Amount</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(r, i) in data.top_employees" :key="r.employee_id">
              <td>{{ i + 1 }}</td>
              <td>
                <div class="font-medium">{{ r.full_name }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ r.employee_number }}</div>
              </td>
              <td>{{ r.department_name || '—' }}</td>
              <td class="text-right">
                {{ $t('payroll.thr_planning.months', { n: r.tenure_months }) }}
              </td>
              <td class="text-right font-medium">{{ fmtCurrency(r.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Help -->
    <div class="mt-4 flex items-start gap-2 text-sm text-gray-500 bg-gray-50 rounded p-3">
      <InformationCircleIcon class="w-5 h-5 flex-shrink-0 text-gray-400 mt-0.5" />
      <div>
        {{ $t('payroll.thr_planning.help_text') }}
      </div>
    </div>
  </div>
</template>
