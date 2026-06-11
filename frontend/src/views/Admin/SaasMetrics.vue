<script setup>
/**
 * S3.5 SaaS — Dashboard Metrics untuk Super Admin Quantara.
 *
 * Tampilkan KPI bisnis paling penting:
 *   - MRR (monthly recurring revenue) + ARR
 *   - Active subscriptions count
 *   - Trial conversions
 *   - Churn rate 30 hari
 *   - Plan distribution (FREE_TRIAL/STARTER/PRO/ENTERPRISE)
 *   - Signup funnel (signups vs activations vs paid)
 *   - New tenants per month chart (12 bulan terakhir)
 */
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import {
  CurrencyDollarIcon,
  ArrowTrendingUpIcon,
  ArrowTrendingDownIcon,
  UsersIcon,
  BuildingOffice2Icon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const metrics = ref(null)
const loading = ref(true)

const isSuperAdmin = computed(() => auth.user?.role === 'super_admin' || auth.user?.is_super_admin)

async function loadMetrics() {
  loading.value = true
  try {
    const res = await api.get('/admin/metrics/saas')
    metrics.value = res.data?.data || null
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat metrics')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (! isSuperAdmin.value) {
    toast.error('Hanya super admin Quantara yang bisa akses halaman ini')
    router.push('/')
    return
  }
  loadMetrics()
})

function formatRupiah(v) {
  if (! v) return 'Rp 0'
  return 'Rp ' + Number(v).toLocaleString('id-ID')
}

function formatRupiahCompact(v) {
  if (! v) return 'Rp 0'
  const n = Number(v)
  if (n >= 1_000_000_000) return 'Rp ' + (n / 1_000_000_000).toFixed(1) + 'M'
  if (n >= 1_000_000) return 'Rp ' + (n / 1_000_000).toFixed(1) + 'jt'
  if (n >= 1_000) return 'Rp ' + (n / 1_000).toFixed(0) + 'rb'
  return formatRupiah(n)
}

const planColors = {
  FREE_TRIAL: 'bg-blue-500',
  STARTER:    'bg-emerald-500',
  PRO:        'bg-purple-500',
  ENTERPRISE: 'bg-amber-500',
}

// Compute max untuk normalize bar chart new_tenants_per_month
const maxNewTenants = computed(() => {
  if (! metrics.value?.new_tenants_per_month?.length) return 1
  return Math.max(...metrics.value.new_tenants_per_month.map(m => m.count || 0), 1)
})

function monthLabel(yyyymm) {
  if (! yyyymm) return '-'
  const parts = String(yyyymm).split('-')
  if (parts.length !== 2) return yyyymm
  const [y, m] = parts
  const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des']
  return months[parseInt(m) - 1] + ' ' + y.slice(-2)
}
</script>

<template>
  <div class="p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold dark:text-white">SaaS Business Metrics</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
          KPI bisnis Quantara HRIS — MRR, ARR, churn, plan distribution
        </p>
      </div>
      <router-link
        to="/admin/tenants"
        class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium border rounded hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
      >
        ← Kembali ke Tenant List
      </router-link>
    </div>

    <div v-if="loading" class="text-gray-500 py-12 text-center">Memuat metrics…</div>

    <div v-else-if="!metrics" class="text-gray-500 py-12 text-center">
      Tidak ada data
    </div>

    <div v-else class="space-y-6">
      <!-- KPI cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- MRR -->
        <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
          <div class="flex items-center gap-2 mb-2">
            <CurrencyDollarIcon class="h-5 w-5 text-emerald-600" />
            <span class="text-xs text-gray-500 uppercase font-medium">MRR</span>
          </div>
          <div class="text-3xl font-bold dark:text-white">
            {{ formatRupiahCompact(metrics.mrr) }}
          </div>
          <div class="text-xs text-gray-500 mt-1">
            Monthly Recurring Revenue
          </div>
          <div v-if="metrics.mrr_growth_percent !== null && metrics.mrr_growth_percent !== undefined" class="mt-2 flex items-center gap-1 text-xs">
            <ArrowTrendingUpIcon
              v-if="metrics.mrr_growth_percent >= 0"
              class="h-4 w-4 text-emerald-600"
            />
            <ArrowTrendingDownIcon v-else class="h-4 w-4 text-rose-600" />
            <span :class="metrics.mrr_growth_percent >= 0 ? 'text-emerald-600' : 'text-rose-600'">
              {{ metrics.mrr_growth_percent >= 0 ? '+' : '' }}{{ metrics.mrr_growth_percent }}%
            </span>
            <span class="text-gray-500">dari bulan lalu</span>
          </div>
        </div>

        <!-- ARR -->
        <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
          <div class="flex items-center gap-2 mb-2">
            <CurrencyDollarIcon class="h-5 w-5 text-blue-600" />
            <span class="text-xs text-gray-500 uppercase font-medium">ARR</span>
          </div>
          <div class="text-3xl font-bold dark:text-white">
            {{ formatRupiahCompact(metrics.arr) }}
          </div>
          <div class="text-xs text-gray-500 mt-1">
            Annual Recurring Revenue
          </div>
        </div>

        <!-- Active tenants -->
        <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
          <div class="flex items-center gap-2 mb-2">
            <BuildingOffice2Icon class="h-5 w-5 text-purple-600" />
            <span class="text-xs text-gray-500 uppercase font-medium">Active Tenants</span>
          </div>
          <div class="text-3xl font-bold dark:text-white">
            {{ metrics.active_tenants || 0 }}
          </div>
          <div class="text-xs text-gray-500 mt-1">
            Total: {{ metrics.total_tenants || 0 }}
            · Trial: {{ metrics.trial_tenants || 0 }}
          </div>
        </div>

        <!-- Churn rate -->
        <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
          <div class="flex items-center gap-2 mb-2">
            <ArrowTrendingDownIcon class="h-5 w-5 text-rose-600" />
            <span class="text-xs text-gray-500 uppercase font-medium">Churn 30d</span>
          </div>
          <div class="text-3xl font-bold dark:text-white">
            {{ metrics.churn_rate_30d !== null && metrics.churn_rate_30d !== undefined ? metrics.churn_rate_30d.toFixed(1) : '0.0' }}%
          </div>
          <div class="text-xs text-gray-500 mt-1">
            {{ metrics.cancelled_30d || 0 }} cancelled / {{ (metrics.active_tenants || 0) + (metrics.cancelled_30d || 0) }} base
          </div>
          <div v-if="metrics.churn_rate_30d > 8" class="mt-2 text-xs text-rose-600">
            ⚠️ Di atas target 8%
          </div>
          <div v-else-if="metrics.churn_rate_30d !== null" class="mt-2 text-xs text-emerald-600">
            ✓ Di bawah target 8%
          </div>
        </div>
      </div>

      <!-- Plan distribution + Signup funnel -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
          <h3 class="text-lg font-semibold mb-4 dark:text-white">Distribusi Plan</h3>
          <div v-if="!metrics.plan_distribution?.length" class="text-sm text-gray-500">
            Tidak ada data plan distribution
          </div>
          <div v-else class="space-y-3">
            <div v-for="p in metrics.plan_distribution" :key="`${p.plan}-${p.status}`">
              <div class="flex items-center justify-between text-sm mb-1">
                <span class="dark:text-gray-200">
                  {{ p.plan || 'NO_PLAN' }}
                  <span class="text-xs text-gray-500 ml-1">({{ p.status }})</span>
                </span>
                <span class="font-semibold dark:text-white">{{ p.count }}</span>
              </div>
              <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                <div
                  :class="planColors[p.plan] || 'bg-gray-400'"
                  class="h-full"
                  :style="{ width: ((p.count / Math.max(metrics.total_tenants, 1)) * 100) + '%' }"
                ></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Signup funnel -->
        <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
          <h3 class="text-lg font-semibold mb-4 dark:text-white">Signup Funnel (30d)</h3>
          <div v-if="!metrics.signup_funnel" class="text-sm text-gray-500">
            Belum ada signup data
          </div>
          <div v-else class="space-y-3">
            <div class="flex items-center gap-3">
              <div class="flex-1">
                <div class="text-xs text-gray-500 mb-0.5">Signup</div>
                <div class="h-8 bg-blue-500 rounded flex items-center px-3 text-white text-sm font-medium">
                  {{ metrics.signup_funnel.signups || 0 }} signup
                </div>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <div class="flex-1">
                <div class="text-xs text-gray-500 mb-0.5">
                  Activated (verified email + onboarding done)
                </div>
                <div
                  class="h-8 bg-purple-500 rounded flex items-center px-3 text-white text-sm font-medium"
                  :style="{ width: Math.min(100, ((metrics.signup_funnel.activated || 0) / Math.max(metrics.signup_funnel.signups || 1, 1)) * 100) + '%' }"
                >
                  {{ metrics.signup_funnel.activated || 0 }} activated
                </div>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <div class="flex-1">
                <div class="text-xs text-gray-500 mb-0.5">Paid (upgrade dari trial)</div>
                <div
                  class="h-8 bg-emerald-500 rounded flex items-center px-3 text-white text-sm font-medium"
                  :style="{ width: Math.min(100, ((metrics.signup_funnel.paid || 0) / Math.max(metrics.signup_funnel.signups || 1, 1)) * 100) + '%' }"
                >
                  {{ metrics.signup_funnel.paid || 0 }} paid
                </div>
              </div>
            </div>
            <div class="text-xs text-gray-500 mt-3">
              Conversion rate:
              <strong class="dark:text-gray-200">
                {{ metrics.signup_funnel.signups
                  ? (((metrics.signup_funnel.paid || 0) / metrics.signup_funnel.signups) * 100).toFixed(1)
                  : '0.0' }}%
              </strong>
              (target ≥ 25%)
            </div>
          </div>
        </div>
      </div>

      <!-- New tenants per month chart -->
      <div class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5">
        <h3 class="text-lg font-semibold mb-4 dark:text-white">Tenant Baru per Bulan (12 bulan terakhir)</h3>
        <div v-if="!metrics.new_tenants_per_month?.length" class="text-sm text-gray-500">
          Belum ada data
        </div>
        <div v-else class="grid grid-cols-12 gap-1 items-end h-40">
          <div
            v-for="m in metrics.new_tenants_per_month"
            :key="m.month"
            class="flex flex-col items-center gap-1"
          >
            <div class="text-xs font-medium dark:text-gray-200">{{ m.count }}</div>
            <div
              class="w-full bg-blue-500 dark:bg-blue-600 rounded-t transition-all hover:bg-blue-600"
              :style="{ height: ((m.count / maxNewTenants) * 100) + '%', minHeight: m.count > 0 ? '4px' : '2px' }"
              :title="`${monthLabel(m.month)}: ${m.count} tenant`"
            ></div>
            <div class="text-xs text-gray-500 -rotate-45 origin-top-left translate-y-3 whitespace-nowrap">
              {{ monthLabel(m.month) }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>