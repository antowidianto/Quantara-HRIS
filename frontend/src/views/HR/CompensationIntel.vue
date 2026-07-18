<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtCurrency } from '@/composables/useResource'
import {
  ScaleIcon, ArrowPathIcon, PlusIcon, ExclamationTriangleIcon,
  ChartBarIcon, BanknotesIcon, ShieldCheckIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 19+ / H4.5 — Compensation Intelligence Dashboard.
 *
 * Tabs:
 *   1. Compa-Ratio — distribusi karyawan vs band (UNDERPAID/ON_BAND/EXCEEDS/OVERPAID)
 *   2. Pay Equity — gap analisis gender per grade (sample size ≥3)
 *   3. Band Recommendation — saran band internal vs market (40% × 60%)
 *   4. Benchmark Master — CRUD salary benchmarks
 *
 * Endpoints:
 *   GET /compensation/compa-ratio
 *   GET /compensation/gender-equity
 *   GET /compensation/band-recommendations
 *   GET/POST /salary-benchmarks
 */

const toast = useToast()

const tab = ref('compa')

// ─── Compa-Ratio ────────────────────────────────────
const compaData = ref(null)

async function loadCompa() {
  try {
    const res = await api.get('/compensation/compa-ratio')
    compaData.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat compa-ratio')
  }
}

const compaSummary = computed(() => {
  if (! compaData.value?.buckets) return null
  const buckets = compaData.value.buckets
  const total = (buckets.UNDERPAID || 0) + (buckets.DEVELOPING || 0)
              + (buckets.ON_BAND || 0) + (buckets.EXCEEDS || 0)
              + (buckets.OVERPAID || 0)
  return { total, buckets }
})

function bucketColor(b) {
  const map = {
    UNDERPAID:  'bg-red-500',
    DEVELOPING: 'bg-amber-500',
    ON_BAND:    'bg-emerald-500',
    EXCEEDS:    'bg-blue-500',
    OVERPAID:   'bg-purple-500',
  }
  return map[b] || 'bg-gray-500'
}

function bucketLabel(b) {
  const map = {
    UNDERPAID:  'Underpaid (< 80%)',
    DEVELOPING: 'Developing (80–95%)',
    ON_BAND:    'On Band (95–110%)',
    EXCEEDS:    'Exceeds (110–120%)',
    OVERPAID:   'Overpaid (> 120%)',
  }
  return map[b] || b
}

// ─── Gender Equity ──────────────────────────────────
const equityData = ref(null)

async function loadEquity() {
  try {
    const res = await api.get('/compensation/gender-equity')
    equityData.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat gender equity')
  }
}

function gapColor(pct) {
  const v = Math.abs(Number(pct || 0))
  if (v >= 10) return 'text-red-600'
  if (v >= 5) return 'text-amber-600'
  return 'text-emerald-600'
}

// ─── Band Recommendations ───────────────────────────
const bandData = ref([])

async function loadBands() {
  try {
    const res = await api.get('/compensation/band-recommendations')
    bandData.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat band recommendations')
  }
}

// ─── Benchmarks ─────────────────────────────────────
const benchmarks = ref([])
const showAddBench = ref(false)
const benchForm = reactive({
  position_name: '',
  grade_id: '',
  source: 'MERCER',
  region: 'JABODETABEK',
  median: 0,
  p25: 0,
  p75: 0,
  effective_date: new Date().toISOString().slice(0, 10),
})

async function loadBenchmarks() {
  try {
    const res = await api.get('/salary-benchmarks')
    benchmarks.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat benchmarks')
  }
}

async function addBenchmark() {
  if (! benchForm.position_name || ! benchForm.median) {
    toast.warning('Posisi & median wajib')
    return
  }
  try {
    await api.post('/salary-benchmarks', benchForm)
    toast.success('Benchmark ditambahkan')
    showAddBench.value = false
    Object.assign(benchForm, {
      position_name: '', grade_id: '', source: 'MERCER',
      region: 'JABODETABEK', median: 0, p25: 0, p75: 0,
      effective_date: new Date().toISOString().slice(0, 10),
    })
    loadBenchmarks()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menambah')
  }
}

function switchTab(t) {
  tab.value = t
  if (t === 'compa') loadCompa()
  else if (t === 'equity') loadEquity()
  else if (t === 'bands') loadBands()
  else if (t === 'benchmarks') loadBenchmarks()
}

onMounted(loadCompa)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ScaleIcon class="w-6 h-6" /> Compensation Intelligence
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Compa-ratio analysis, pay equity, band recommendation, dan master salary benchmarks.
        </p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-4 flex gap-1 flex-wrap">
      <button v-for="t in [
        { id: 'compa', label: 'Compa-Ratio' },
        { id: 'equity', label: 'Gender Equity' },
        { id: 'bands', label: 'Band Recommendation' },
        { id: 'benchmarks', label: 'Benchmark Master' },
      ]" :key="t.id" @click="switchTab(t.id)"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
          tab === t.id ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700'
        ]">
        {{ t.label }}
      </button>
    </div>

    <!-- ───── Tab: Compa-Ratio ───── -->
    <div v-if="tab === 'compa'">
      <div v-if="! compaData" class="card-body text-center py-12 text-gray-400 card">Memuat…</div>
      <div v-else>
        <!-- Distribution -->
        <div v-if="compaSummary" class="card mb-4">
          <div class="card-header flex items-center gap-2">
            <ChartBarIcon class="w-4 h-4" />
            <span class="font-medium">Distribusi Karyawan vs Band ({{ compaSummary.total }} karyawan)</span>
          </div>
          <div class="p-5 space-y-2">
            <div v-for="b in ['UNDERPAID', 'DEVELOPING', 'ON_BAND', 'EXCEEDS', 'OVERPAID']" :key="b"
              class="flex items-center gap-3">
              <span class="w-44 text-sm">{{ bucketLabel(b) }}</span>
              <div class="flex-1 bg-gray-100 rounded h-5 overflow-hidden">
                <div class="h-full transition-all" :class="bucketColor(b)"
                  :style="{ width: compaSummary.total > 0 ? ((compaSummary.buckets[b] || 0) / compaSummary.total * 100) + '%' : '0%' }">
                </div>
              </div>
              <span class="w-16 text-right text-sm font-medium">{{ compaSummary.buckets[b] || 0 }}</span>
              <span class="w-12 text-right text-xs text-gray-500">
                {{ compaSummary.total > 0 ? Math.round((compaSummary.buckets[b] || 0) / compaSummary.total * 100) : 0 }}%
              </span>
            </div>
          </div>
        </div>

        <!-- Underpaid alert -->
        <div v-if="compaData.underpaid_employees?.length" class="card mb-4">
          <div class="card-header flex items-center gap-2">
            <ExclamationTriangleIcon class="w-4 h-4 text-red-600" />
            <span class="font-medium">Karyawan Underpaid (compa-ratio < 80%)</span>
          </div>
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Karyawan</th>
                  <th>Posisi / Grade</th>
                  <th class="text-right">Salary Aktual</th>
                  <th class="text-right">Band Mid</th>
                  <th class="text-right">Compa-Ratio</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="e in compaData.underpaid_employees" :key="e.employee_id">
                  <td>
                    <div class="font-medium">{{ e.full_name }}</div>
                    <div class="text-xs text-gray-500 font-mono">{{ e.employee_number }}</div>
                  </td>
                  <td class="text-sm">{{ e.position_name }} / {{ e.grade_name }}</td>
                  <td class="text-right">{{ fmtCurrency(e.actual_salary) }}</td>
                  <td class="text-right text-gray-500">{{ fmtCurrency(e.band_mid) }}</td>
                  <td class="text-right">
                    <span class="inline-block px-2 py-0.5 rounded text-xs bg-red-100 text-red-700 font-medium">
                      {{ Number(e.compa_ratio * 100).toFixed(0) }}%
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Per-grade dispersion -->
        <div v-if="compaData.dispersion_by_grade?.length" class="card">
          <div class="card-header">
            <span class="font-medium">Coefficient of Variation per Grade</span>
          </div>
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Grade</th>
                  <th class="text-right">Karyawan</th>
                  <th class="text-right">Mean</th>
                  <th class="text-right">CoV</th>
                  <th>Flag</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="g in compaData.dispersion_by_grade" :key="g.grade_id">
                  <td>{{ g.grade_name }}</td>
                  <td class="text-right">{{ g.count }}</td>
                  <td class="text-right">{{ fmtCurrency(g.mean) }}</td>
                  <td class="text-right">{{ Number(g.coefficient_of_variation || 0).toFixed(2) }}</td>
                  <td>
                    <span v-if="g.flag === 'HIGH_DISPERSION'"
                      class="inline-block px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-700">
                      High Dispersion
                    </span>
                    <span v-else class="text-xs text-gray-500">Normal</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── Tab: Gender Equity ───── -->
    <div v-if="tab === 'equity'">
      <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-800 mb-4 flex items-start gap-2">
        <ShieldCheckIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
        <div>
          Analisis hanya menampilkan grade dengan sample size ≥ 3 untuk masing-masing
          gender — privacy gate. Gap dihitung dari median pay female vs male per grade.
        </div>
      </div>
      <div v-if="! equityData" class="card-body text-center py-12 text-gray-400 card">Memuat…</div>
      <div v-else>
        <div v-if="! equityData.grades?.length" class="card">
          <div class="card-body text-center py-8 text-gray-400">
            Tidak ada grade yang memenuhi sample size minimal untuk analisis equity.
          </div>
        </div>
        <div v-else class="card">
          <div class="card-header">
            <span class="font-medium">Pay Gap by Gender per Grade</span>
          </div>
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Grade</th>
                  <th class="text-right">Female (n)</th>
                  <th class="text-right">Male (n)</th>
                  <th class="text-right">Median Female</th>
                  <th class="text-right">Median Male</th>
                  <th class="text-right">Gap %</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="g in equityData.grades" :key="g.grade_id">
                  <td>{{ g.grade_name }}</td>
                  <td class="text-right">{{ g.female_count }}</td>
                  <td class="text-right">{{ g.male_count }}</td>
                  <td class="text-right">{{ fmtCurrency(g.female_median) }}</td>
                  <td class="text-right">{{ fmtCurrency(g.male_median) }}</td>
                  <td class="text-right font-medium" :class="gapColor(g.gap_pct)">
                    {{ Number(g.gap_pct || 0).toFixed(1) }}%
                  </td>
                  <td>
                    <span v-if="Math.abs(Number(g.gap_pct || 0)) >= 10"
                      class="inline-block px-2 py-0.5 rounded text-xs bg-red-100 text-red-700">
                      Gap Significant
                    </span>
                    <span v-else-if="Math.abs(Number(g.gap_pct || 0)) >= 5"
                      class="inline-block px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-700">
                      Watchlist
                    </span>
                    <span v-else
                      class="inline-block px-2 py-0.5 rounded text-xs bg-emerald-100 text-emerald-700">
                      Equitable
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── Tab: Band Recommendation ───── -->
    <div v-if="tab === 'bands'">
      <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-800 mb-4">
        Rekomendasi band salary baru: blend internal data 40% × market benchmark 60%.
        Bisa dijadikan referensi penyesuaian struktur upah tahunan.
      </div>
      <div v-if="bandData.length === 0" class="card-body text-center py-12 text-gray-400 card">
        Belum ada rekomendasi. Pastikan ada data internal + benchmark master.
      </div>
      <div v-else class="card">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Posisi / Grade</th>
                <th class="text-right">Internal Mid</th>
                <th class="text-right">Market Mid</th>
                <th class="text-right">Recommended Min</th>
                <th class="text-right">Recommended Mid</th>
                <th class="text-right">Recommended Max</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="b in bandData" :key="`${b.position_id || 0}-${b.grade_id || 0}`">
                <td>
                  <div class="font-medium">{{ b.position_name || b.grade_name }}</div>
                  <div v-if="b.position_name && b.grade_name" class="text-xs text-gray-500">
                    {{ b.grade_name }}
                  </div>
                </td>
                <td class="text-right">{{ fmtCurrency(b.internal_mid) }}</td>
                <td class="text-right">{{ fmtCurrency(b.market_mid) }}</td>
                <td class="text-right">{{ fmtCurrency(b.recommended_min) }}</td>
                <td class="text-right font-medium text-emerald-700">
                  {{ fmtCurrency(b.recommended_mid) }}
                </td>
                <td class="text-right">{{ fmtCurrency(b.recommended_max) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ───── Tab: Benchmarks ───── -->
    <div v-if="tab === 'benchmarks'">
      <div class="flex justify-end mb-3">
        <button class="btn-primary" @click="showAddBench = true">
          <PlusIcon class="w-4 h-4" /> Tambah Benchmark
        </button>
      </div>
      <div class="card">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Posisi</th>
                <th>Source</th>
                <th>Region</th>
                <th class="text-right">P25</th>
                <th class="text-right">Median</th>
                <th class="text-right">P75</th>
                <th>Effective Date</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="benchmarks.length === 0">
                <td colspan="7" class="text-center py-8 text-gray-400">
                  Belum ada benchmark
                </td>
              </tr>
              <tr v-for="b in benchmarks" :key="b.id">
                <td class="font-medium">{{ b.position_name }}</td>
                <td>
                  <span class="text-xs uppercase bg-gray-100 px-2 py-0.5 rounded">{{ b.source }}</span>
                </td>
                <td class="text-sm">{{ b.region }}</td>
                <td class="text-right">{{ fmtCurrency(b.p25) }}</td>
                <td class="text-right font-medium">{{ fmtCurrency(b.median) }}</td>
                <td class="text-right">{{ fmtCurrency(b.p75) }}</td>
                <td class="text-xs text-gray-500">{{ b.effective_date?.slice(0, 10) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal: Add benchmark -->
    <div v-if="showAddBench" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showAddBench = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-xl">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h3 class="font-semibold">Tambah Salary Benchmark</h3>
          <button @click="showAddBench = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <div class="p-5 grid grid-cols-2 gap-3">
          <div class="col-span-2">
            <label class="label">Nama Posisi</label>
            <input v-model="benchForm.position_name" class="input"
              placeholder="contoh: Senior Software Engineer" />
          </div>
          <div>
            <label class="label">Source</label>
            <select v-model="benchForm.source" class="input">
              <option value="MERCER">Mercer</option>
              <option value="WTW">Willis Towers Watson</option>
              <option value="GLINTS">Glints Salary Insight</option>
              <option value="JOBSTREET">JobStreet</option>
              <option value="MANUAL">Manual / Internal</option>
            </select>
          </div>
          <div>
            <label class="label">Region</label>
            <select v-model="benchForm.region" class="input">
              <option value="JABODETABEK">Jabodetabek</option>
              <option value="BANDUNG">Bandung</option>
              <option value="SURABAYA">Surabaya</option>
              <option value="MEDAN">Medan</option>
              <option value="NATIONAL">National</option>
            </select>
          </div>
          <div>
            <label class="label">P25</label>
            <input type="number" v-model.number="benchForm.p25" class="input" />
          </div>
          <div>
            <label class="label">Median</label>
            <input type="number" v-model.number="benchForm.median" class="input" />
          </div>
          <div>
            <label class="label">P75</label>
            <input type="number" v-model.number="benchForm.p75" class="input" />
          </div>
          <div>
            <label class="label">Effective Date</label>
            <AppDatePicker  v-model="benchForm.effective_date" class="input" />
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showAddBench = false">Batal</button>
          <button class="btn-primary" @click="addBenchmark">Tambah</button>
        </div>
      </div>
    </div>
  </div>
</template>