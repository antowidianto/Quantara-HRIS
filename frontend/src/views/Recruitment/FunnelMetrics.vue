<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import {
  ChartBarIcon, ArrowPathIcon, ExclamationTriangleIcon,
  ClockIcon, FunnelIcon, UserIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 9 / H1.4 — Time-in-Stage Recruitment Funnel.
 *
 * Dashboard untuk recruiter mengidentifikasi bottleneck di pipeline:
 *   - Funnel chart: rata-rata jam per stage
 *   - Stale candidates: kandidat yang sudah > N hari di stage non-terminal
 *
 * Endpoint:
 *   GET /recruitment/funnel-metrics?job_posting_id=&since=
 *   GET /recruitment/stale-candidates?threshold_days=
 */

const toast = useToast()

const filters = reactive({
  job_posting_id: '',
  since: '',
  threshold_days: 7,
})

const jobPostings = ref([])
const funnel = ref([])
const stale = ref([])
const loading = ref(false)

async function loadJobs() {
  try {
    const res = await api.get('/job-postings', { params: { per_page: 200, status: 'OPEN' } })
    jobPostings.value = res.data.data || []
  } catch (_) {}
}

async function fetchAll() {
  loading.value = true
  try {
    const params = {}
    if (filters.job_posting_id) params.job_posting_id = filters.job_posting_id
    if (filters.since) params.since = filters.since
    const [funnelRes, staleRes] = await Promise.all([
      api.get('/recruitment/funnel-metrics', { params }),
      api.get('/recruitment/stale-candidates', { params: { threshold_days: filters.threshold_days } }),
    ])
    funnel.value = funnelRes.data.data || []
    stale.value = staleRes.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat metrik funnel')
  } finally {
    loading.value = false
  }
}

// Hitung max hours untuk normalisasi width bar funnel
const maxHours = computed(() => {
  const maxes = funnel.value.map(r => Number(r.avg_hours || 0))
  return Math.max(1, ...maxes)
})

function fmtHours(h) {
  const v = Number(h || 0)
  if (v < 24) return `${v.toFixed(1)} jam`
  return `${(v / 24).toFixed(1)} hari`
}

function stageColor(stage) {
  const map = {
    APPLIED:    'bg-slate-500',
    SCREENING:  'bg-blue-500',
    TEST:       'bg-cyan-500',
    INTERVIEW:  'bg-indigo-500',
    OFFER:      'bg-amber-500',
    HIRED:      'bg-emerald-500',
    REJECTED:   'bg-red-500',
    WITHDRAWN:  'bg-gray-400',
  }
  return map[stage] || 'bg-gray-500'
}

function staleSeverity(days) {
  if (days >= 30) return 'bg-red-50 text-red-700 border-red-200'
  if (days >= 14) return 'bg-amber-50 text-amber-700 border-amber-200'
  return 'bg-yellow-50 text-yellow-700 border-yellow-200'
}

watch(() => filters.threshold_days, () => {
  // Hanya re-fetch stale (cheap) saat threshold berubah
  fetchAll()
})

onMounted(async () => {
  await loadJobs()
  await fetchAll()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <FunnelIcon class="w-6 h-6" /> Recruitment Funnel
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Visibilitas waktu rata-rata kandidat di tiap stage. Identifikasi bottleneck pipeline.
        </p>
      </div>
      <button class="btn-secondary" @click="fetchAll" :disabled="loading">
        <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> Refresh
      </button>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
      <div class="card-body grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="label">Job Posting</label>
          <select v-model="filters.job_posting_id" class="input" @change="fetchAll">
            <option value="">Semua posisi</option>
            <option v-for="j in jobPostings" :key="j.id" :value="j.id">
              {{ j.title }}{{ j.position_name ? ` — ${j.position_name}` : '' }}
            </option>
          </select>
        </div>
        <div>
          <label class="label">Sejak (opsional)</label>
          <AppDatePicker  v-model="filters.since" class="input" @change="fetchAll" />
        </div>
        <div>
          <label class="label">Threshold Stale (hari)</label>
          <select v-model.number="filters.threshold_days" class="input">
            <option :value="3">3 hari</option>
            <option :value="7">7 hari</option>
            <option :value="14">14 hari</option>
            <option :value="30">30 hari</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Funnel chart -->
    <div class="card mb-4">
      <div class="card-header flex items-center gap-2">
        <ChartBarIcon class="w-4 h-4" />
        <span class="font-medium">Waktu Rata-rata per Stage</span>
      </div>
      <div class="p-5">
        <div v-if="loading" class="text-center py-8 text-gray-400">Memuat…</div>
        <div v-else-if="funnel.length === 0" class="text-center py-8 text-gray-400">
          Belum ada data tracking — pastikan ada kandidat yang move-stage di tenant ini.
        </div>
        <div v-else class="space-y-3">
          <div v-for="r in funnel" :key="r.stage" class="space-y-1">
            <div class="flex items-center justify-between text-sm">
              <div class="flex items-center gap-2">
                <span class="font-medium">{{ r.stage }}</span>
                <span class="text-xs text-gray-500">({{ r.count }} kandidat)</span>
              </div>
              <div class="flex items-center gap-3 text-xs text-gray-600">
                <span>Avg: <strong class="text-gray-900">{{ fmtHours(r.avg_hours) }}</strong></span>
                <span>Max: <strong class="text-gray-900">{{ fmtHours(r.max_hours) }}</strong></span>
              </div>
            </div>
            <div class="relative w-full bg-gray-100 rounded-full h-3 overflow-hidden">
              <div
                class="absolute left-0 top-0 h-full rounded-full transition-all duration-500"
                :class="stageColor(r.stage)"
                :style="{ width: ((Number(r.avg_hours) / maxHours) * 100) + '%' }"
              ></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Stale candidates -->
    <div class="card">
      <div class="card-header flex items-center justify-between">
        <div class="flex items-center gap-2">
          <ExclamationTriangleIcon class="w-4 h-4" />
          <span class="font-medium">
            Kandidat Stagnan ({{ filters.threshold_days }}+ hari)
          </span>
        </div>
        <span class="text-xs text-gray-500">
          {{ stale.length }} kandidat
        </span>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Kandidat</th>
              <th>Job Posting</th>
              <th>Stage</th>
              <th class="text-right">Lama di Stage</th>
              <th>Sejak</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="5" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="stale.length === 0">
              <td colspan="5" class="text-center py-8 text-gray-400">
                Tidak ada kandidat yang stagnan di stage non-terminal
              </td>
            </tr>
            <tr v-for="r in stale" :key="`${r.candidate_id}-${r.stage}`">
              <td>
                <RouterLink
                  v-if="r.job_posting_id"
                  :to="`/recruitment/jobs/${r.job_posting_id}?candidate=${r.candidate_id}`"
                  class="font-medium text-primary-700 hover:underline flex items-center gap-1"
                >
                  <UserIcon class="w-4 h-4" />
                  {{ r.full_name }}
                </RouterLink>
                <span v-else class="font-medium">{{ r.full_name }}</span>
              </td>
              <td class="text-sm">{{ r.job_title || '—' }}</td>
              <td>
                <span class="inline-block px-2 py-0.5 rounded-full text-xs text-white" :class="stageColor(r.stage)">
                  {{ r.stage }}
                </span>
              </td>
              <td class="text-right">
                <span class="inline-block px-2 py-0.5 rounded-full text-xs border" :class="staleSeverity(r.days_in_stage)">
                  <ClockIcon class="w-3 h-3 inline" /> {{ Math.round(r.days_in_stage) }} hari
                </span>
              </td>
              <td class="text-xs text-gray-500">{{ r.entered_at?.slice(0, 16) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>