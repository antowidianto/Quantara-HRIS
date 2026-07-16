<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { fmtDate, fmtCurrency } from '@/composables/useResource'
import {
  DocumentChartBarIcon, ArrowPathIcon, ArrowDownTrayIcon,
  EyeIcon, ChartBarIcon, PlayIcon,
} from '@heroicons/vue/24/outline'

/**
 * ComplianceReports — HR menu untuk generate & download laporan
 * kepatuhan (Sprint 4 / CC-5).
 *
 * 8 report types: WLKP_ANNUAL, BPJS_MONTHLY, PKWT_EXPIRING, PHK_LIST,
 * AUDIT_SUMMARY, PROBATION_OVERDUE, BPJS_OVERDUE, TURNOVER_SUMMARY.
 */

const auth = useAuthStore()
const toast = useToast()

const types = ref([])
const runs = ref([])
const loading = ref(false)

const generateForm = reactive({
  report_type: '',
  year: new Date().getFullYear(),
  month: new Date().getMonth() + 1,
  parameters: {},
  force_refresh: false,
})

const showResultModal = ref(null)
const resultData = ref(null)

const canExport = computed(() => auth.isSuperAdmin || auth.can('compliance.report.export'))

async function fetchAll() {
  loading.value = true
  try {
    const [t, r] = await Promise.all([
      api.get('/compliance-reports/types'),
      api.get('/compliance-reports/runs', { params: { per_page: 50 } }),
    ])
    types.value = t.data.data || []
    runs.value = r.data.data || []
    if (! generateForm.report_type && types.value.length > 0) {
      generateForm.report_type = types.value[0].type
    }
  } catch (e) {
    toast.error('Gagal memuat')
  } finally {
    loading.value = false
  }
}

onMounted(fetchAll)

const needsYear = computed(() =>
  ['WLKP_ANNUAL', 'BPJS_MONTHLY', 'PHK_LIST', 'AUDIT_SUMMARY', 'TURNOVER_SUMMARY']
    .includes(generateForm.report_type),
)
const needsMonth = computed(() =>
  ['BPJS_MONTHLY', 'PHK_LIST', 'AUDIT_SUMMARY']
    .includes(generateForm.report_type),
)
const needsDaysAhead = computed(() =>
  generateForm.report_type === 'PKWT_EXPIRING',
)

async function generate() {
  if (! generateForm.report_type) {
    toast.error('Pilih report type')
    return
  }
  try {
    const payload = {
      report_type: generateForm.report_type,
      force_refresh: generateForm.force_refresh,
    }
    if (needsYear.value) payload.year = Number(generateForm.year)
    if (needsMonth.value) payload.month = Number(generateForm.month)
    if (needsDaysAhead.value) {
      payload.parameters = { days_ahead: Number(generateForm.parameters.days_ahead || 60) }
    }

    const res = await api.post('/compliance-reports/generate', payload)
    if (res.status === 202 && res.data.data?.approval_state === 'PENDING') {
      toast.warning('Generate report berisi PII menunggu approval. Ulangi request yang sama setelah disetujui.')
      return
    }
    toast.success(res.data.data?.cached ? 'Returned cached report' : 'Report generated')
    fetchAll()
    // Otomatis open hasil yang baru di-generate
    openRun(res.data.data)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal generate')
  }
}

async function openRun(run) {
  showResultModal.value = run
  try {
    const res = await api.get(`/compliance-reports/runs/${run.id}`)
    resultData.value = res.data.data
  } catch (e) {
    toast.error('Gagal memuat')
    showResultModal.value = null
  }
}

function downloadCsv(runId) {
  // Pakai window.open karena butuh hit endpoint dengan auth header.
  // Browser akan inherit cookie/token dari main session.
  const a = document.createElement('a')
  a.href = `/api/v1/compliance-reports/runs/${runId}/csv`
  a.target = '_blank'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
}

function formatTimeUntilExpiry(expiresAt) {
  if (! expiresAt) return '-'
  const ms = new Date(expiresAt).getTime() - Date.now()
  if (ms < 0) return 'expired'
  const hours = Math.floor(ms / 3600000)
  if (hours < 1) return `${Math.floor(ms / 60000)}m`
  if (hours < 24) return `${hours}h`
  return `${Math.floor(hours / 24)}d`
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <DocumentChartBarIcon class="w-6 h-6 text-cyan-600" />
          Compliance Reports
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
          Laporan kepatuhan HR siap cetak — WLKP, BPJS, PKWT, PHK, audit, turnover.
          Hasil di-cache 24 jam.
        </p>
      </div>
      <button class="btn-secondary text-xs" @click="fetchAll">
        <ArrowPathIcon class="w-3.5 h-3.5" /> Refresh
      </button>
    </div>

    <!-- Generate form -->
    <section class="bg-white rounded-lg border border-gray-200 p-4 mb-4">
      <h3 class="font-semibold text-sm mb-3">Generate Report Baru</h3>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="label">Report Type *</label>
          <select v-model="generateForm.report_type" class="input">
            <option v-for="t in types" :key="t.type" :value="t.type">{{ t.type }}</option>
          </select>
          <p v-if="generateForm.report_type" class="text-xs text-gray-500 mt-1">
            {{ types.find(t => t.type === generateForm.report_type)?.description }}
          </p>
        </div>
        <div v-if="needsYear">
          <label class="label">Tahun</label>
          <input type="number" v-model.number="generateForm.year" class="input" min="2020" :max="(new Date().getFullYear() + 1)" />
        </div>
        <div v-if="needsMonth">
          <label class="label">Bulan</label>
          <select v-model.number="generateForm.month" class="input">
            <option v-for="m in 12" :key="m" :value="m">{{ m }}</option>
          </select>
        </div>
        <div v-if="needsDaysAhead">
          <label class="label">Days Ahead (default 60)</label>
          <input type="number" v-model.number="generateForm.parameters.days_ahead" class="input" min="1" max="365" />
        </div>
        <div class="flex items-end">
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="generateForm.force_refresh" />
            <span>Force refresh (skip cache)</span>
          </label>
        </div>
      </div>
      <div class="mt-3 flex justify-end">
        <button class="btn-primary text-sm" @click="generate">
          <PlayIcon class="w-4 h-4" /> Generate
        </button>
      </div>
    </section>

    <!-- History runs -->
    <section class="bg-white rounded-lg border border-gray-200 p-4">
      <h3 class="font-semibold text-sm mb-3">Riwayat Generate ({{ runs.length }})</h3>
      <div v-if="loading" class="text-center py-12 text-gray-400">Memuat…</div>
      <div v-else-if="runs.length === 0" class="text-center py-8 text-gray-400 italic">
        Belum ada report yang di-generate.
      </div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
              <th class="px-2 py-2">Type</th>
              <th class="px-2 py-2">Periode</th>
              <th class="px-2 py-2">Rows</th>
              <th class="px-2 py-2">Generated</th>
              <th class="px-2 py-2">Cache TTL</th>
              <th class="px-2 py-2">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in runs" :key="r.id" class="border-b border-gray-100 hover:bg-gray-50">
              <td class="px-2 py-2 font-mono text-xs">{{ r.report_type }}</td>
              <td class="px-2 py-2">
                {{ r.period_year || '-' }}<span v-if="r.period_month">-{{ String(r.period_month).padStart(2, '0') }}</span>
              </td>
              <td class="px-2 py-2 font-mono">{{ r.row_count }}</td>
              <td class="px-2 py-2 text-xs">{{ fmtDate(r.generated_at) }}</td>
              <td class="px-2 py-2 text-xs">
                <span :class="formatTimeUntilExpiry(r.expires_at) === 'expired' ? 'text-rose-600' : 'text-gray-600'">
                  {{ formatTimeUntilExpiry(r.expires_at) }}
                </span>
              </td>
              <td class="px-2 py-2 flex gap-1">
                <button class="text-xs px-2 py-1 rounded border border-gray-200 hover:bg-gray-100"
                  @click="openRun(r)">
                  <EyeIcon class="w-3.5 h-3.5" />
                </button>
                <button v-if="canExport && r.row_count > 0"
                  class="text-xs px-2 py-1 rounded border border-blue-200 text-blue-600 hover:bg-blue-50"
                  @click="downloadCsv(r.id)">
                  <ArrowDownTrayIcon class="w-3.5 h-3.5" /> CSV
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Result modal -->
    <div v-if="showResultModal && resultData"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between sticky top-0 bg-white z-10">
          <div>
            <h2 class="font-semibold flex items-center gap-2">
              <ChartBarIcon class="w-5 h-5 text-cyan-600" />
              {{ resultData.report_type }}
            </h2>
            <p class="text-xs text-gray-500">
              Periode {{ resultData.period_year }}<span v-if="resultData.period_month">-{{ resultData.period_month }}</span>
              · Generated: {{ fmtDate(resultData.generated_at) }}
              · {{ resultData.row_count }} rows
            </p>
          </div>
          <button class="text-gray-500" @click="showResultModal = null">&times;</button>
        </div>

        <div class="p-6">
          <!-- WLKP_ANNUAL -->
          <div v-if="resultData.report_type === 'WLKP_ANNUAL' && resultData.result"
            class="space-y-3">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <div class="bg-blue-50 rounded p-3">
                <div class="text-xs text-gray-500">Total HC</div>
                <div class="text-2xl font-bold">{{ resultData.result.total_hc }}</div>
              </div>
              <div class="bg-emerald-50 rounded p-3">
                <div class="text-xs text-gray-500">Hires {{ resultData.result.period_year }}</div>
                <div class="text-2xl font-bold text-emerald-700">{{ resultData.result.new_hires_year }}</div>
              </div>
              <div class="bg-rose-50 rounded p-3">
                <div class="text-xs text-gray-500">Exits {{ resultData.result.period_year }}</div>
                <div class="text-2xl font-bold text-rose-700">{{ resultData.result.exits_year }}</div>
              </div>
              <div class="bg-purple-50 rounded p-3">
                <div class="text-xs text-gray-500">Pria / Wanita</div>
                <div class="text-lg font-bold">{{ resultData.result.male_hc }} / {{ resultData.result.female_hc }}</div>
              </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <div class="bg-gray-50 rounded p-3"><div class="text-xs">Usia < 25</div><div class="text-xl font-bold">{{ resultData.result.age_under_25 }}</div></div>
              <div class="bg-gray-50 rounded p-3"><div class="text-xs">25-40</div><div class="text-xl font-bold">{{ resultData.result.age_25_to_40 }}</div></div>
              <div class="bg-gray-50 rounded p-3"><div class="text-xs">40-55</div><div class="text-xl font-bold">{{ resultData.result.age_40_to_55 }}</div></div>
              <div class="bg-gray-50 rounded p-3"><div class="text-xs">> 55</div><div class="text-xl font-bold">{{ resultData.result.age_over_55 }}</div></div>
            </div>
            <div>
              <h4 class="font-semibold text-sm mb-2">By Department</h4>
              <ul class="space-y-1 text-sm">
                <li v-for="(count, dept) in resultData.result.by_department" :key="dept"
                  class="flex justify-between border-b border-gray-100 py-1">
                  <span>{{ dept }}</span>
                  <span class="font-mono font-semibold">{{ count }}</span>
                </li>
              </ul>
            </div>
          </div>

          <!-- TURNOVER_SUMMARY -->
          <div v-else-if="resultData.report_type === 'TURNOVER_SUMMARY' && resultData.result"
            class="space-y-3">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <div class="bg-rose-50 rounded p-3">
                <div class="text-xs text-gray-500">Turnover Rate</div>
                <div class="text-2xl font-bold text-rose-700">{{ resultData.result.turnover_rate }}%</div>
              </div>
              <div class="bg-blue-50 rounded p-3">
                <div class="text-xs text-gray-500">Active Start</div>
                <div class="text-2xl font-bold">{{ resultData.result.active_start }}</div>
              </div>
              <div class="bg-blue-50 rounded p-3">
                <div class="text-xs text-gray-500">Active End</div>
                <div class="text-2xl font-bold">{{ resultData.result.active_end }}</div>
              </div>
              <div class="bg-emerald-50 rounded p-3">
                <div class="text-xs text-gray-500">Hires / Exits</div>
                <div class="text-lg font-bold">{{ resultData.result.new_hires }} / {{ resultData.result.exits }}</div>
              </div>
            </div>
            <div v-if="(resultData.result.monthly_trend || []).length">
              <h4 class="font-semibold text-sm mb-2">Trend per Bulan</h4>
              <table class="w-full text-sm">
                <thead><tr class="border-b text-left text-xs text-gray-500">
                  <th class="px-2 py-1">Bulan</th><th class="px-2 py-1">Hires</th><th class="px-2 py-1">Exits</th>
                </tr></thead>
                <tbody>
                  <tr v-for="m in resultData.result.monthly_trend" :key="m.month" class="border-b border-gray-100">
                    <td class="px-2 py-1">{{ m.month }}</td>
                    <td class="px-2 py-1 font-mono text-emerald-600">+{{ m.hires }}</td>
                    <td class="px-2 py-1 font-mono text-rose-600">-{{ m.exits }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- AUDIT_SUMMARY -->
          <div v-else-if="resultData.report_type === 'AUDIT_SUMMARY' && resultData.result"
            class="space-y-3">
            <div class="text-sm">
              <strong>Total log:</strong> {{ resultData.result.total_logs }}
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <h4 class="font-semibold text-sm mb-2">By Severity</h4>
                <ul class="space-y-1 text-sm">
                  <li v-for="(c, s) in resultData.result.by_severity" :key="s"
                    class="flex justify-between border-b border-gray-100 py-1">
                    <span>{{ s }}</span>
                    <span class="font-mono">{{ c }}</span>
                  </li>
                </ul>
              </div>
              <div>
                <h4 class="font-semibold text-sm mb-2">By Module</h4>
                <ul class="space-y-1 text-sm">
                  <li v-for="(c, m) in resultData.result.by_module" :key="m"
                    class="flex justify-between border-b border-gray-100 py-1">
                    <span>{{ m }}</span>
                    <span class="font-mono">{{ c }}</span>
                  </li>
                </ul>
              </div>
            </div>
          </div>

          <!-- Generic table for row-based reports (PKWT, PHK, BPJS, etc) -->
          <div v-else-if="(resultData.result?.rows || []).length > 0">
            <div class="overflow-x-auto max-h-[60vh] overflow-y-auto">
              <table class="w-full text-xs">
                <thead class="sticky top-0 bg-gray-50">
                  <tr class="border-b text-left">
                    <th v-for="col in Object.keys(resultData.result.rows[0])" :key="col"
                      class="px-2 py-2 font-semibold text-gray-600">{{ col }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, idx) in resultData.result.rows" :key="idx"
                    class="border-b border-gray-100 hover:bg-gray-50">
                    <td v-for="(val, col) in row" :key="col" class="px-2 py-1 align-top">
                      {{ typeof val === 'object' ? JSON.stringify(val) : val }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div v-else class="text-center py-8 text-gray-400 italic">
            Tidak ada data dalam report ini.
          </div>
        </div>

        <div class="px-6 py-3 border-t flex justify-end gap-2 sticky bottom-0 bg-white">
          <button v-if="canExport && (resultData.result?.rows || []).length > 0"
            class="btn-secondary text-sm" @click="downloadCsv(resultData.id)">
            <ArrowDownTrayIcon class="w-4 h-4" /> Download CSV
          </button>
          <button class="btn-secondary" @click="showResultModal = null">Tutup</button>
        </div>
      </div>
    </div>
  </div>
</template>
