<script setup>
/**
 * S3.4 SaaS — Dashboard Customer Health (Super Admin Quantara).
 *
 * Listing semua tenant beserta health score (0-100) + tier (GREEN/YELLOW/RED).
 * Tujuan utama: identifikasi tenant berisiko churn supaya tim Customer
 * Success bisa proaktif outreach sebelum cancellation.
 *
 * Filter:
 *   - tier (GREEN/YELLOW/RED)
 *   - sort by score ASC (paling berisiko di atas)
 *
 * Action:
 *   - Recompute health on-demand
 *   - Drill-down ke detail tenant
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import {
  ArrowPathIcon,
  EyeIcon,
  HeartIcon,
  ExclamationTriangleIcon,
  CheckCircleIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const scores = ref([])
const loading = ref(true)
const acting = ref(null)

const filters = reactive({
  tier: '',
})

const isSuperAdmin = computed(() => auth.user?.role === 'super_admin' || auth.user?.is_super_admin)

const summary = computed(() => {
  const total = scores.value.length
  const green = scores.value.filter(s => s.tier === 'GREEN').length
  const yellow = scores.value.filter(s => s.tier === 'YELLOW').length
  const red = scores.value.filter(s => s.tier === 'RED').length
  const avgScore = total > 0
    ? Math.round(scores.value.reduce((sum, s) => sum + Number(s.score || 0), 0) / total)
    : 0
  return { total, green, yellow, red, avgScore }
})

async function loadScores() {
  loading.value = true
  try {
    const params = {}
    if (filters.tier) params.tier = filters.tier
    const res = await api.get('/admin/cs/health-scores', { params })
    scores.value = res.data?.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat health scores')
  } finally {
    loading.value = false
  }
}

async function recompute(companyId) {
  acting.value = companyId
  try {
    await api.post(`/admin/cs/recompute/${companyId}`, {})
    toast.success('Health score recomputed')
    await loadScores()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal recompute')
  } finally {
    acting.value = null
  }
}

function tierBadge(tier) {
  return {
    GREEN:  'bg-emerald-100 text-emerald-800',
    YELLOW: 'bg-amber-100 text-amber-800',
    RED:    'bg-rose-100 text-rose-800',
  }[tier] || 'bg-gray-100'
}

function scoreColor(score) {
  if (score >= 70) return 'text-emerald-600'
  if (score >= 40) return 'text-amber-600'
  return 'text-rose-600'
}

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

onMounted(() => {
  if (! isSuperAdmin.value) {
    toast.error('Hanya super admin Quantara yang bisa akses')
    router.push('/')
    return
  }
  loadScores()
})
</script>

<template>
  <div class="p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold dark:text-white">Customer Health Dashboard</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
          Monitor health score semua tenant. Prioritas outreach untuk RED tier.
        </p>
      </div>
      <router-link
        to="/admin/tenants"
        class="inline-flex items-center gap-1 px-3 py-2 text-sm border rounded hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
      >
        ← Tenant List
      </router-link>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
      <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-3 text-center">
        <div class="text-xs text-gray-500 uppercase">Total Tenant</div>
        <div class="text-2xl font-bold dark:text-white">{{ summary.total }}</div>
      </div>
      <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 p-3 text-center">
        <div class="text-xs text-emerald-700 dark:text-emerald-300 uppercase">GREEN</div>
        <div class="text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ summary.green }}</div>
      </div>
      <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 p-3 text-center">
        <div class="text-xs text-amber-700 dark:text-amber-300 uppercase">YELLOW</div>
        <div class="text-2xl font-bold text-amber-700 dark:text-amber-300">{{ summary.yellow }}</div>
      </div>
      <div class="rounded-lg bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-700 p-3 text-center">
        <div class="text-xs text-rose-700 dark:text-rose-300 uppercase">RED (At-Risk)</div>
        <div class="text-2xl font-bold text-rose-700 dark:text-rose-300">{{ summary.red }}</div>
      </div>
      <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-3 text-center">
        <div class="text-xs text-gray-500 uppercase">Avg Score</div>
        <div class="text-2xl font-bold" :class="scoreColor(summary.avgScore)">{{ summary.avgScore }}</div>
      </div>
    </div>

    <!-- Filter -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-4 flex items-center gap-3 flex-wrap">
      <label class="text-sm font-medium dark:text-gray-200">Filter Tier:</label>
      <select
        v-model="filters.tier"
        class="px-3 py-1.5 text-sm border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white"
        @change="loadScores"
      >
        <option value="">Semua</option>
        <option value="GREEN">GREEN (Healthy)</option>
        <option value="YELLOW">YELLOW (Warning)</option>
        <option value="RED">RED (At-Risk)</option>
      </select>
      <button
        type="button"
        class="ml-auto px-3 py-1.5 text-sm font-medium bg-blue-600 text-white rounded hover:bg-blue-500 inline-flex items-center gap-1"
        @click="loadScores"
      >
        <ArrowPathIcon class="h-4 w-4" /> Refresh
      </button>
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
      <div v-if="loading" class="text-gray-500 py-12 text-center">Memuat…</div>

      <div v-else-if="!scores.length" class="text-gray-500 py-12 text-center">
        Belum ada health score. Jalankan command <code class="text-xs">php spark cs:compute-health-scores</code> dulu.
      </div>

      <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        <thead class="bg-gray-50 dark:bg-gray-900">
          <tr>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tenant</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Score</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tier</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Breakdown</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Last Calc</th>
            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
          <tr
            v-for="row in scores"
            :key="row.id"
            class="hover:bg-gray-50 dark:hover:bg-gray-700"
            :class="row.tier === 'RED' ? 'bg-rose-50/50 dark:bg-rose-900/10' : ''"
          >
            <td class="px-3 py-3">
              <div class="text-sm font-medium dark:text-white">{{ row.company_name || `Tenant #${row.company_id}` }}</div>
              <div class="text-xs text-gray-500">{{ row.company_code }}</div>
            </td>
            <td class="px-3 py-3">
              <span class="text-2xl font-bold" :class="scoreColor(Number(row.score))">
                {{ Number(row.score).toFixed(0) }}
              </span>
              <span class="text-xs text-gray-500 ml-1">/100</span>
            </td>
            <td class="px-3 py-3">
              <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded" :class="tierBadge(row.tier)">
                <CheckCircleIcon v-if="row.tier === 'GREEN'" class="h-3 w-3" />
                <ExclamationTriangleIcon v-else-if="row.tier === 'YELLOW'" class="h-3 w-3" />
                <HeartIcon v-else-if="row.tier === 'RED'" class="h-3 w-3" />
                {{ row.tier }}
              </span>
            </td>
            <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-400">
              <div v-if="row.breakdown" class="space-y-0.5">
                <div v-for="(v, k) in row.breakdown" :key="k" class="flex items-center gap-1">
                  <span class="text-gray-500">{{ k }}:</span>
                  <span class="font-medium dark:text-gray-200">{{ typeof v === 'number' ? v.toFixed(0) : v }}</span>
                </div>
              </div>
              <span v-else>-</span>
            </td>
            <td class="px-3 py-3 text-xs text-gray-500">{{ formatDate(row.calculated_at) }}</td>
            <td class="px-3 py-3 text-right">
              <div class="flex items-center justify-end gap-1">
                <router-link
                  :to="`/admin/tenants/${row.company_id}`"
                  class="p-1.5 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded"
                  title="Detail"
                >
                  <EyeIcon class="h-4 w-4" />
                </router-link>
                <button
                  type="button"
                  class="p-1.5 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded disabled:opacity-50"
                  :disabled="acting === row.company_id"
                  title="Recompute"
                  @click="recompute(row.company_id)"
                >
                  <ArrowPathIcon class="h-4 w-4" :class="acting === row.company_id ? 'animate-spin' : ''" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>