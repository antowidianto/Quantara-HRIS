<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import {
  ChartBarIcon, ArrowPathIcon, ArrowDownTrayIcon, UserMinusIcon,
  ArrowTrendingUpIcon, ArrowTrendingDownIcon,
  ExclamationTriangleIcon, InformationCircleIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 12 / H2.6 — Turnover Analytics Dashboard.
 *
 * Konsumsi 3 endpoint:
 *   GET /turnover/snapshot?period=YYYY-MM&granularity=MONTH
 *   POST /turnover/snapshot (persist sebagai cache)
 *   GET /turnover/trend?from=YYYY-MM&to=YYYY-MM&granularity=MONTH
 *
 * Metrik yang ditampilkan:
 *   - Turnover rate
 *   - Voluntary vs involuntary
 *   - Early attrition rate (< 12 bulan)
 *   - Regrettable loss rate (perf rating ≥ 4)
 */

const toast = useToast()

const filters = reactive({
  granularity: 'MONTH',
  period: new Date().toISOString().slice(0, 7), // YYYY-MM
  from: '',
  to: '',
})

const snapshot = ref(null)
const trend = ref([])
const loading = ref(false)
const persisting = ref(false)

function defaultRange() {
  const now = new Date()
  const to = now.toISOString().slice(0, 7)
  const fromDate = new Date(now)
  fromDate.setMonth(fromDate.getMonth() - 11)
  const from = fromDate.toISOString().slice(0, 7)
  return { from, to }
}

async function fetchSnapshot() {
  loading.value = true
  try {
    const params = { period: filters.period, granularity: filters.granularity }
    const res = await api.get('/turnover/snapshot', { params })
    snapshot.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat snapshot')
  } finally {
    loading.value = false
  }
}

async function fetchTrend() {
  try {
    const params = { from: filters.from, to: filters.to, granularity: filters.granularity }
    const res = await api.get('/turnover/trend', { params })
    trend.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat trend')
  }
}

async function persistSnapshot() {
  persisting.value = true
  try {
    await api.post('/turnover/snapshot', {
      period: filters.period,
      granularity: filters.granularity,
    })
    toast.success('Snapshot tersimpan ke cache')
    fetchTrend()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan snapshot')
  } finally {
    persisting.value = false
  }
}

function exportCsv() {
  if (! trend.value.length) return
  const lines = [
    'Period,Headcount Start,Separations,Voluntary,Involuntary,Turnover Rate %,Early Attrition %,Regrettable %',
    ...trend.value.map(r => [
      r.period,
      r.headcount_start,
      r.separations,
      r.voluntary,
      r.involuntary,
      Number(r.turnover_rate || 0).toFixed(2),
      Number(r.early_attrition_rate || 0).toFixed(2),
      Number(r.regrettable_loss_rate || 0).toFixed(2),
    ].join(',')),
  ]
  const blob = new Blob([lines.join('\n')], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `turnover-trend-${filters.from}-${filters.to}.csv`
  document.body.appendChild(a); a.click()
  setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url) }, 0)
}

function fmtPct(n) {
  return (Number(n || 0)).toFixed(2) + '%'
}

const trendMaxRate = computed(() => {
  const rates = trend.value.map(r => Number(r.turnover_rate || 0))
  return Math.max(1, ...rates)
})

const compositionPct = computed(() => {
  if (! snapshot.value) return { vol: 0, invol: 0 }
  const total = snapshot.value.voluntary + snapshot.value.involuntary
  if (total === 0) return { vol: 0, invol: 0 }
  return {
    vol: Math.round((snapshot.value.voluntary / total) * 100),
    invol: Math.round((snapshot.value.involuntary / total) * 100),
  }
})

onMounted(async () => {
  const { from, to } = defaultRange()
  filters.from = from
  filters.to = to
  await fetchSnapshot()
  await fetchTrend()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <UserMinusIcon class="w-6 h-6" /> Turnover Analytics
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Indikator kesehatan retensi: turnover rate, early attrition, regrettable loss.
        </p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary" @click="fetchSnapshot(); fetchTrend()" :disabled="loading">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> Refresh
        </button>
        <button class="btn-secondary" @click="persistSnapshot" :disabled="persisting">
          <span v-if="persisting">Menyimpan…</span>
          <span v-else>Cache Snapshot Periode Ini</span>
        </button>
        <button class="btn-primary" @click="exportCsv" :disabled="trend.length === 0">
          <ArrowDownTrayIcon class="w-4 h-4" /> Export CSV
        </button>
      </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
      <div class="card-body grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
        <div>
          <label class="label">Granularity</label>
          <select v-model="filters.granularity" class="input"
            @change="fetchSnapshot(); fetchTrend()">
            <option value="MONTH">Bulanan</option>
            <option value="YEAR">Tahunan</option>
          </select>
        </div>
        <div>
          <label class="label">Periode Snapshot</label>
          <input
            :type="filters.granularity === 'YEAR' ? 'number' : 'month'"
            v-model="filters.period"
            class="input"
            @change="fetchSnapshot"
          />
        </div>
        <div>
          <label class="label">Trend Dari</label>
          <input :type="filters.granularity === 'YEAR' ? 'number' : 'month'"
            v-model="filters.from" class="input" @change="fetchTrend" />
        </div>
        <div>
          <label class="label">Trend Sampai</label>
          <input :type="filters.granularity === 'YEAR' ? 'number' : 'month'"
            v-model="filters.to" class="input" @change="fetchTrend" />
        </div>
      </div>
    </div>

    <!-- Snapshot kartu -->
    <div v-if="snapshot" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="card-body bg-blue-50 rounded-lg border border-blue-100">
        <div class="text-xs text-blue-700 font-medium">Headcount Awal</div>
        <div class="text-2xl font-bold text-blue-900">
          {{ snapshot.headcount_start?.toLocaleString('id-ID') }}
        </div>
      </div>
      <div class="card-body bg-red-50 rounded-lg border border-red-100">
        <div class="text-xs text-red-700 font-medium">Separations</div>
        <div class="text-2xl font-bold text-red-900">
          {{ snapshot.separations }}
        </div>
        <div class="text-xs text-red-600 mt-1">
          {{ fmtPct(snapshot.turnover_rate) }} turnover rate
        </div>
      </div>
      <div class="card-body bg-amber-50 rounded-lg border border-amber-100">
        <div class="text-xs text-amber-700 font-medium">Early Attrition</div>
        <div class="text-2xl font-bold text-amber-900">
          {{ fmtPct(snapshot.early_attrition_rate) }}
        </div>
        <div class="text-xs text-amber-600 mt-1">
          tenure kurang dari 12 bulan
        </div>
      </div>
      <div class="card-body bg-purple-50 rounded-lg border border-purple-100">
        <div class="text-xs text-purple-700 font-medium">Regrettable Loss</div>
        <div class="text-2xl font-bold text-purple-900">
          {{ fmtPct(snapshot.regrettable_loss_rate) }}
        </div>
        <div class="text-xs text-purple-600 mt-1">
          perf rating ≥ 4
        </div>
      </div>
    </div>

    <!-- Komposisi voluntary vs involuntary -->
    <div v-if="snapshot" class="card mb-4">
      <div class="card-header">
        <span class="font-medium">Komposisi Separation: Voluntary vs Involuntary</span>
      </div>
      <div class="p-5">
        <div class="flex items-center mb-2 text-sm">
          <ArrowTrendingDownIcon class="w-4 h-4 text-emerald-600 mr-1" />
          <span class="font-medium">Voluntary</span>
          <span class="ml-auto">{{ snapshot.voluntary }} ({{ compositionPct.vol }}%)</span>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden mb-3">
          <div class="bg-emerald-500 h-full rounded-full transition-all"
            :style="{ width: compositionPct.vol + '%' }"></div>
        </div>
        <div class="flex items-center mb-2 text-sm">
          <ArrowTrendingUpIcon class="w-4 h-4 text-red-600 mr-1" />
          <span class="font-medium">Involuntary</span>
          <span class="ml-auto">{{ snapshot.involuntary }} ({{ compositionPct.invol }}%)</span>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
          <div class="bg-red-500 h-full rounded-full transition-all"
            :style="{ width: compositionPct.invol + '%' }"></div>
        </div>
        <div class="mt-3 text-xs text-gray-500">
          Voluntary = RESIGNATION (kecuali RETIREMENT/CONTRACT_END kategorikan terpisah).
          Involuntary = TERMINATION (PHK).
        </div>
      </div>
    </div>

    <!-- Trend chart -->
    <div class="card">
      <div class="card-header flex items-center gap-2">
        <ChartBarIcon class="w-4 h-4" />
        <span class="font-medium">Trend Turnover Rate</span>
      </div>
      <div class="p-5">
        <div v-if="trend.length === 0" class="text-center py-8 text-gray-400">
          Belum ada data trend pada rentang ini. Tekan "Cache Snapshot Periode Ini"
          untuk mulai mengisi history.
        </div>
        <div v-else class="space-y-2">
          <div v-for="r in trend" :key="r.period"
            class="grid grid-cols-12 gap-2 items-center text-sm">
            <div class="col-span-2 font-mono text-xs">{{ r.period }}</div>
            <div class="col-span-7">
              <div class="relative w-full bg-gray-100 rounded h-5 overflow-hidden">
                <div class="absolute left-0 top-0 h-full bg-red-400 transition-all"
                  :style="{ width: ((Number(r.turnover_rate) / trendMaxRate) * 100) + '%' }"></div>
                <div class="absolute inset-0 flex items-center px-2 text-xs font-medium">
                  {{ fmtPct(r.turnover_rate) }}
                </div>
              </div>
            </div>
            <div class="col-span-3 text-xs text-gray-600 text-right">
              {{ r.separations }} sep / {{ r.headcount_start }} HC
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabel detail trend -->
    <div v-if="trend.length" class="card mt-4">
      <div class="card-header">
        <span class="font-medium">Detail Trend per Periode</span>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Period</th>
              <th class="text-right">HC Awal</th>
              <th class="text-right">Separations</th>
              <th class="text-right">Voluntary</th>
              <th class="text-right">Involuntary</th>
              <th class="text-right">Turnover %</th>
              <th class="text-right">Early Attr %</th>
              <th class="text-right">Regrettable %</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in trend" :key="`detail-${r.period}`">
              <td class="font-mono">{{ r.period }}</td>
              <td class="text-right">{{ r.headcount_start }}</td>
              <td class="text-right">{{ r.separations }}</td>
              <td class="text-right text-emerald-700">{{ r.voluntary }}</td>
              <td class="text-right text-red-700">{{ r.involuntary }}</td>
              <td class="text-right font-medium">{{ fmtPct(r.turnover_rate) }}</td>
              <td class="text-right">{{ fmtPct(r.early_attrition_rate) }}</td>
              <td class="text-right">{{ fmtPct(r.regrettable_loss_rate) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Help -->
    <div class="mt-4 flex items-start gap-2 text-sm text-gray-500 bg-gray-50 rounded p-3">
      <InformationCircleIcon class="w-5 h-5 flex-shrink-0 text-gray-400 mt-0.5" />
      <div>
        <strong>Turnover Rate</strong> = (separations / headcount awal) × 100%.
        <strong>Early Attrition</strong> melacak resign tenure kurang dari 12 bulan — indikator masalah onboarding.
        <strong>Regrettable Loss</strong> melacak resign karyawan dengan rating performa terakhir ≥ 4 — indikator
        retensi top performer. Cache snapshot tiap akhir periode untuk membangun history yang reliable.
      </div>
    </div>
  </div>
</template>