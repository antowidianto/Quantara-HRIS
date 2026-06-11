<script setup>
/**
 * S1.4 SaaS — Usage Meter Dashboard.
 *
 * Tampilkan semua meter (employees, storage, payslips, whatsapp, api,
 * data_exports) dengan progress bar, status (OK/WARNING/CRITICAL/EXCEEDED),
 * dan metadata reset_period + period_start/end.
 *
 * Super admin Quantara internal bisa force recompute meter dari source.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { ArrowPathIcon, ChartBarIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

const toast = useToast()
const auth = useAuthStore()

const meters = ref([])
const loading = ref(true)
const recomputing = ref(null)

const isSuperAdmin = computed(() => auth.user?.role === 'super_admin' || auth.user?.is_super_admin)

const meterLabels = {
  employees: { name: 'Karyawan Aktif', icon: '👥', desc: 'Jumlah karyawan aktif (lifetime)' },
  storage_mb: { name: 'Storage', icon: '💾', desc: 'Total file upload (MB)' },
  payslips_generated: { name: 'Slip Gaji Diterbitkan', icon: '📄', desc: 'Total payslip finalized' },
  whatsapp_sent: { name: 'WhatsApp Notif', icon: '📱', desc: 'Pesan WA bulan ini' },
  api_calls: { name: 'API Calls', icon: '🔌', desc: 'Request API bulan ini' },
  data_exports: { name: 'Data Export', icon: '📦', desc: 'Request export bulan ini' },
}

async function loadUsage() {
  loading.value = true
  try {
    const res = await api.get('/billing/usage')
    meters.value = res.data?.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat usage')
  } finally {
    loading.value = false
  }
}

onMounted(loadUsage)

function statusColor(status) {
  switch (status) {
    case 'EXCEEDED': return { bar: 'bg-rose-600', badge: 'bg-rose-100 text-rose-800', text: 'text-rose-600' }
    case 'CRITICAL': return { bar: 'bg-rose-500', badge: 'bg-rose-100 text-rose-800', text: 'text-rose-600' }
    case 'WARNING':  return { bar: 'bg-amber-500', badge: 'bg-amber-100 text-amber-800', text: 'text-amber-600' }
    case 'UNLIMITED':return { bar: 'bg-emerald-500', badge: 'bg-emerald-100 text-emerald-800', text: 'text-emerald-600' }
    default:         return { bar: 'bg-blue-500', badge: 'bg-blue-100 text-blue-800', text: 'text-blue-600' }
  }
}

function formatNumber(n) {
  if (n === null || n === undefined) return '-'
  return Number(n).toLocaleString('id-ID')
}

function formatDate(d) {
  if (! d) return null
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

async function recompute(meterKey) {
  recomputing.value = meterKey
  try {
    const res = await api.post('/billing/usage/recompute', { meter_key: meterKey })
    toast.success(`Recomputed ${meterKey}: ${res.data?.data?.new_value ?? '-'}`)
    await loadUsage()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal recompute')
  } finally {
    recomputing.value = null
  }
}
</script>

<template>
  <div class="p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold dark:text-white">Usage & Quota</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
          Monitor penggunaan tenant Anda terhadap kuota plan saat ini
        </p>
      </div>
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium border rounded hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-gray-200"
          :disabled="loading"
          @click="loadUsage"
        >
          <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': loading }" />
          Refresh
        </button>
        <router-link
          to="/billing/plan"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium bg-blue-600 text-white rounded hover:bg-blue-500"
        >
          Lihat Plan
        </router-link>
      </div>
    </div>

    <div v-if="loading" class="text-gray-500 dark:text-gray-400 py-12 text-center">
      Memuat usage data…
    </div>

    <div v-else-if="!meters.length" class="text-gray-500 dark:text-gray-400 py-12 text-center">
      Belum ada usage data. Tunggu beberapa saat setelah aktivitas pertama.
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="m in meters"
        :key="m.meter_key"
        class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-5"
      >
        <div class="flex items-start justify-between gap-2 mb-2">
          <div class="flex items-center gap-2">
            <span class="text-2xl">{{ meterLabels[m.meter_key]?.icon || '📊' }}</span>
            <div>
              <h3 class="font-semibold text-sm dark:text-white">
                {{ meterLabels[m.meter_key]?.name || m.meter_key }}
              </h3>
              <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ meterLabels[m.meter_key]?.desc || '' }}
              </p>
            </div>
          </div>
          <span
            class="px-2 py-0.5 text-xs font-semibold rounded shrink-0"
            :class="statusColor(m.status).badge"
          >
            {{ m.status }}
          </span>
        </div>

        <div class="my-3">
          <div class="flex items-baseline gap-1">
            <span class="text-2xl font-bold dark:text-white">{{ formatNumber(m.current_value) }}</span>
            <span v-if="m.limit_value > 0" class="text-sm text-gray-500">/ {{ formatNumber(m.limit_value) }}</span>
            <span v-else-if="m.limit_value < 0" class="text-sm text-emerald-600">unlimited</span>
          </div>

          <!-- Progress bar -->
          <div v-if="m.limit_value > 0" class="mt-2 h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
            <div
              class="h-full transition-all duration-300"
              :class="statusColor(m.status).bar"
              :style="{ width: Math.min(100, m.percent_used) + '%' }"
            ></div>
          </div>
          <div v-if="m.limit_value > 0" class="text-xs mt-1" :class="statusColor(m.status).text">
            {{ m.percent_used }}% terpakai
          </div>
        </div>

        <!-- Period -->
        <div v-if="m.period_end" class="text-xs text-gray-500 dark:text-gray-400 mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
          Reset: {{ m.reset_period }}
          <span v-if="formatDate(m.period_end)"> · sampai {{ formatDate(m.period_end) }}</span>
        </div>
        <div v-else class="text-xs text-gray-500 dark:text-gray-400 mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
          Lifetime counter (tidak reset)
        </div>

        <!-- Warning saat hampir / sudah exceeded -->
        <div
          v-if="m.status === 'EXCEEDED'"
          class="mt-3 rounded bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-700 px-3 py-1.5 text-xs text-rose-800 dark:text-rose-200 flex items-center gap-1.5"
        >
          <ExclamationTriangleIcon class="h-4 w-4" />
          Limit terlampaui. Upgrade plan untuk lanjut.
        </div>
        <div
          v-else-if="m.status === 'CRITICAL'"
          class="mt-3 rounded bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 px-3 py-1.5 text-xs text-amber-800 dark:text-amber-200 flex items-center gap-1.5"
        >
          <ExclamationTriangleIcon class="h-4 w-4" />
          Hampir mencapai limit ({{ m.percent_used }}%)
        </div>

        <!-- Super admin: recompute -->
        <button
          v-if="isSuperAdmin"
          type="button"
          class="mt-3 w-full px-2 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-300"
          :disabled="recomputing === m.meter_key"
          @click="recompute(m.meter_key)"
        >
          <ArrowPathIcon class="inline h-3 w-3 mr-1" :class="{ 'animate-spin': recomputing === m.meter_key }" />
          Recompute (Super Admin)
        </button>
      </div>
    </div>
  </div>
</template>