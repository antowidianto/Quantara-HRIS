<script setup>
/**
 * S3.5 SaaS — Detail Tenant (Super Admin Console).
 *
 * Drill-down view dengan:
 *   - Subscription info + history events
 *   - Usage stats (employees, payslips, periods)
 *   - Helpdesk tickets
 *   - Recent critical audit events
 *   - Feature overrides aktif
 *   - Quick actions: extend trial, feature override, suspend, impersonate
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  ArrowLeftIcon,
  UserCircleIcon,
  PauseCircleIcon,
  PlayCircleIcon,
  ClockIcon,
  CheckCircleIcon,
} from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()
const toast  = useToast()
const auth   = useAuthStore()

const detail = ref(null)
const loading = ref(true)
const acting = ref(false)

const companyId = computed(() => Number(route.params.id))
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin' || auth.user?.is_super_admin)

async function loadDetail() {
  loading.value = true
  try {
    const res = await api.get(`/admin/tenants/${companyId.value}`)
    detail.value = res.data?.data || null
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat detail tenant')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (! isSuperAdmin.value) {
    toast.error('Hanya super admin Quantara yang bisa akses')
    router.push('/')
    return
  }
  loadDetail()
})

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

function formatRupiah(v) {
  if (! v) return '-'
  return 'Rp ' + Number(v).toLocaleString('id-ID')
}

function statusBadge(status) {
  const map = {
    TRIAL:     'bg-blue-100 text-blue-800',
    ACTIVE:    'bg-emerald-100 text-emerald-800',
    PAST_DUE:  'bg-amber-100 text-amber-800',
    SUSPENDED: 'bg-rose-100 text-rose-800',
    CANCELLED: 'bg-gray-200 text-gray-700',
  }
  return map[status] || 'bg-gray-100'
}

function severityBadge(s) {
  const map = {
    HIGH:     'bg-amber-100 text-amber-800',
    CRITICAL: 'bg-rose-100 text-rose-800',
  }
  return map[s] || 'bg-gray-100'
}

async function impersonate() {
  const reason = window.prompt('Alasan impersonate (min 10 karakter, audited HIGH):')
  if (! reason || reason.trim().length < 10) {
    toast.error('Alasan minimal 10 karakter')
    return
  }
  const ok = await confirmDialog({
    title: 'Konfirmasi Impersonate',
    message: 'Token expired 15 menit. Action tercatat di audit log severity HIGH.',
    okText: 'Ya, Impersonate',
    variant: 'warning',
  })
  if (! ok) return

  acting.value = true
  try {
    const res = await api.post(`/admin/tenants/${companyId.value}/impersonate`, { reason: reason.trim() })
    const token = res.data?.data?.token
    if (token) {
      localStorage.setItem('impersonation_origin_token', auth.token || '')
      localStorage.setItem('impersonation_started_at', String(Date.now()))
      auth.setToken(token)
      toast.success('Login sebagai tenant. Reload…')
      setTimeout(() => { window.location.href = '/' }, 800)
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal impersonate')
  } finally {
    acting.value = false
  }
}

async function suspend() {
  const reason = window.prompt('Alasan suspend (min 10 karakter):')
  if (! reason || reason.trim().length < 10) {
    toast.error('Alasan minimal 10 karakter')
    return
  }
  const ok = await confirmDialog({
    title: '4-Eyes Confirmation',
    message: `Suspend tenant ${detail.value.company.name}?`,
    okText: 'Ya, Suspend',
    variant: 'danger',
  })
  if (! ok) return

  acting.value = true
  try {
    await api.post(`/admin/tenants/${companyId.value}/suspend`, { reason: reason.trim(), confirm: true })
    toast.success('Tenant disuspend')
    await loadDetail()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal suspend')
  } finally {
    acting.value = false
  }
}

async function unsuspend() {
  const ok = await confirmDialog({
    title: 'Aktifkan kembali tenant?',
    message: 'Pastikan invoice sudah dibayar.',
    okText: 'Ya, Aktifkan',
  })
  if (! ok) return

  acting.value = true
  try {
    await api.post(`/admin/tenants/${companyId.value}/unsuspend`)
    toast.success('Tenant aktif kembali')
    await loadDetail()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal unsuspend')
  } finally {
    acting.value = false
  }
}

async function extendTrial() {
  const days = window.prompt('Perpanjang trial berapa hari? (1-90)')
  const n = parseInt(days, 10)
  if (! n || n < 1 || n > 90) {
    toast.error('Masukkan angka 1-90')
    return
  }
  acting.value = true
  try {
    await api.post(`/admin/tenants/${companyId.value}/extend-trial`, { days: n })
    toast.success(`Trial diperpanjang ${n} hari`)
    await loadDetail()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal extend trial')
  } finally {
    acting.value = false
  }
}

async function addFeatureOverride() {
  const featureKey = window.prompt('Feature key (contoh: payroll.export_csv):')
  if (! featureKey || featureKey.length < 3) return
  const enabledStr = window.prompt('Enable (true) atau Disable (false)?')
  const enabled = enabledStr?.toLowerCase() === 'true' || enabledStr === '1'
  const expiresDays = parseInt(window.prompt('Expires in berapa hari? (kosong = permanen)') || '0', 10)
  const reason = window.prompt('Alasan override (min 10 karakter):')
  if (! reason || reason.trim().length < 10) {
    toast.error('Alasan minimal 10 karakter')
    return
  }

  acting.value = true
  try {
    await api.post(`/admin/tenants/${companyId.value}/feature-override`, {
      feature_key: featureKey.trim(),
      enabled,
      expires_in_days: expiresDays > 0 ? expiresDays : null,
      reason: reason.trim(),
    })
    toast.success('Feature override ditambahkan')
    await loadDetail()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal tambah override')
  } finally {
    acting.value = false
  }
}
</script>

<template>
  <div class="p-6 max-w-6xl mx-auto">
    <button
      type="button"
      class="inline-flex items-center gap-1 text-sm text-blue-600 hover:underline mb-4"
      @click="router.push('/admin/tenants')"
    >
      <ArrowLeftIcon class="h-4 w-4" />
      Kembali ke Tenant List
    </button>

    <div v-if="loading" class="text-gray-500 py-12 text-center">Memuat detail…</div>

    <div v-else-if="!detail" class="text-gray-500 py-12 text-center">Tenant tidak ditemukan</div>

    <div v-else class="space-y-6">
      <!-- Header company -->
      <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5 flex items-start justify-between flex-wrap gap-3">
        <div>
          <div class="flex items-center gap-3 mb-2">
            <h1 class="text-2xl font-bold dark:text-white">{{ detail.company.name }}</h1>
            <span
              v-if="detail.subscription?.status"
              class="px-2 py-0.5 text-xs font-semibold rounded"
              :class="statusBadge(detail.subscription.status)"
            >
              {{ detail.subscription.status }}
            </span>
          </div>
          <div class="text-sm text-gray-500">
            Code: <strong>{{ detail.company.code }}</strong>
            · ID: #{{ detail.company.id }}
            · Bergabung: {{ formatDate(detail.company.created_at) }}
          </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <button
            type="button"
            class="px-3 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-500 inline-flex items-center gap-1 disabled:opacity-50"
            :disabled="acting"
            @click="impersonate"
          >
            <UserCircleIcon class="h-4 w-4" /> Impersonate
          </button>
          <button
            type="button"
            class="px-3 py-2 text-sm bg-amber-500 text-white rounded hover:bg-amber-400 inline-flex items-center gap-1 disabled:opacity-50"
            :disabled="acting"
            @click="extendTrial"
          >
            <ClockIcon class="h-4 w-4" /> Extend Trial
          </button>
          <button
            type="button"
            class="px-3 py-2 text-sm bg-purple-600 text-white rounded hover:bg-purple-500 inline-flex items-center gap-1 disabled:opacity-50"
            :disabled="acting"
            @click="addFeatureOverride"
          >
            Feature Override
          </button>
          <button
            v-if="detail.subscription?.status === 'SUSPENDED'"
            type="button"
            class="px-3 py-2 text-sm bg-emerald-600 text-white rounded hover:bg-emerald-500 inline-flex items-center gap-1 disabled:opacity-50"
            :disabled="acting"
            @click="unsuspend"
          >
            <PlayCircleIcon class="h-4 w-4" /> Unsuspend
          </button>
          <button
            v-else
            type="button"
            class="px-3 py-2 text-sm bg-rose-600 text-white rounded hover:bg-rose-500 inline-flex items-center gap-1 disabled:opacity-50"
            :disabled="acting"
            @click="suspend"
          >
            <PauseCircleIcon class="h-4 w-4" /> Suspend
          </button>
        </div>
      </div>

      <!-- Subscription -->
      <div v-if="detail.subscription" class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-semibold mb-3 dark:text-white">Subscription</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div>
            <div class="text-xs text-gray-500">Plan</div>
            <div class="font-semibold dark:text-gray-200">{{ detail.subscription.plan_code || '-' }}</div>
            <div class="text-xs text-gray-500">{{ detail.subscription.plan_name }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">Billing Cycle</div>
            <div class="font-semibold dark:text-gray-200">{{ detail.subscription.billing_cycle || '-' }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">Harga</div>
            <div class="font-semibold dark:text-gray-200">
              {{ formatRupiah(detail.subscription.custom_price || detail.subscription.price_monthly) }}
            </div>
          </div>
          <div>
            <div class="text-xs text-gray-500">Period End</div>
            <div class="font-semibold dark:text-gray-200">{{ formatDate(detail.subscription.current_period_end) }}</div>
          </div>
          <div v-if="detail.subscription.trial_ends_at">
            <div class="text-xs text-gray-500">Trial Ends</div>
            <div class="text-blue-600 font-semibold">{{ formatDate(detail.subscription.trial_ends_at) }}</div>
          </div>
          <div v-if="detail.subscription.po_number">
            <div class="text-xs text-gray-500">PO Number</div>
            <div class="font-semibold dark:text-gray-200">{{ detail.subscription.po_number }}</div>
          </div>
          <div v-if="detail.subscription.cancelled_at">
            <div class="text-xs text-gray-500">Cancelled</div>
            <div class="text-rose-600 font-semibold">{{ formatDate(detail.subscription.cancelled_at) }}</div>
          </div>
        </div>
      </div>

      <!-- Usage stats -->
      <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-semibold mb-3 dark:text-white">Usage Stats</h3>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
          <div class="text-center p-3 border rounded dark:border-gray-700">
            <div class="text-2xl font-bold dark:text-white">{{ detail.usage?.employees_active || 0 }}</div>
            <div class="text-xs text-gray-500 mt-1">Karyawan Aktif</div>
          </div>
          <div class="text-center p-3 border rounded dark:border-gray-700">
            <div class="text-2xl font-bold dark:text-white">{{ detail.usage?.payslips_generated || 0 }}</div>
            <div class="text-xs text-gray-500 mt-1">Payslip Generated</div>
          </div>
          <div class="text-center p-3 border rounded dark:border-gray-700">
            <div class="text-2xl font-bold dark:text-white">{{ detail.usage?.payroll_periods || 0 }}</div>
            <div class="text-xs text-gray-500 mt-1">Payroll Period</div>
          </div>
          <div v-if="detail.tickets" class="text-center p-3 border rounded dark:border-gray-700">
            <div class="text-2xl font-bold text-amber-600">{{ detail.tickets.open || 0 }}</div>
            <div class="text-xs text-gray-500 mt-1">Open Ticket</div>
          </div>
          <div v-if="detail.tickets" class="text-center p-3 border rounded dark:border-gray-700">
            <div class="text-2xl font-bold text-emerald-600">{{ detail.tickets.closed_30d || 0 }}</div>
            <div class="text-xs text-gray-500 mt-1">Closed 30d</div>
          </div>
        </div>
      </div>

      <!-- Subscription events -->
      <div v-if="detail.subscription_events?.length" class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-semibold mb-3 dark:text-white">Subscription Events (Recent)</h3>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 dark:bg-gray-900">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Detail</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="ev in detail.subscription_events" :key="ev.id">
              <td class="px-3 py-2 font-medium dark:text-gray-200">{{ ev.event_type }}</td>
              <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">{{ ev.note || '-' }}</td>
              <td class="px-3 py-2 text-xs text-gray-500">{{ formatDate(ev.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Recent critical events -->
      <div v-if="detail.recent_critical_events?.length" class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-semibold mb-3 dark:text-white">Recent Critical Events (Audit)</h3>
        <div class="space-y-2">
          <div
            v-for="ev in detail.recent_critical_events"
            :key="ev.id"
            class="flex items-start gap-3 p-3 border rounded dark:border-gray-700"
          >
            <span class="px-2 py-0.5 text-xs font-semibold rounded shrink-0" :class="severityBadge(ev.severity)">
              {{ ev.severity }}
            </span>
            <div class="flex-1">
              <div class="text-sm font-medium dark:text-gray-200">{{ ev.action }}</div>
              <div class="text-xs text-gray-500">{{ ev.description }}</div>
              <div class="text-xs text-gray-400 mt-1">
                User #{{ ev.user_id }} · {{ formatDate(ev.created_at) }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Feature overrides -->
      <div v-if="detail.feature_overrides?.length" class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-semibold mb-3 dark:text-white">Active Feature Overrides</h3>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 dark:bg-gray-900">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Feature</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
              <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="ov in detail.feature_overrides" :key="ov.id">
              <td class="px-3 py-2 font-mono text-xs dark:text-gray-200">{{ ov.feature_key }}</td>
              <td class="px-3 py-2">
                <span :class="ov.enabled ? 'text-emerald-600' : 'text-rose-600'">
                  <CheckCircleIcon v-if="ov.enabled" class="h-4 w-4 inline" />
                  {{ ov.enabled ? 'ENABLED' : 'DISABLED' }}
                </span>
              </td>
              <td class="px-3 py-2 text-xs text-gray-600">{{ ov.expires_at ? formatDate(ov.expires_at) : 'Permanen' }}</td>
              <td class="px-3 py-2 text-xs text-gray-600">{{ ov.reason || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>