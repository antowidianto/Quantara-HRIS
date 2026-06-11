<script setup>
/**
 * S1.1 + S1.2 SaaS — Billing & Subscription Dashboard.
 *
 * Tampilkan:
 *   - Current subscription card (plan, status, days remaining, trial countdown)
 *   - Quick usage summary (employees, storage, payslips)
 *   - Plan comparison table dengan highlight current
 *   - Action: upgrade / downgrade / cancel / reactivate
 *
 * Permission: tenant boleh lihat sendiri (super_company_admin perlu untuk
 * action mutate via permission `billing.manage`).
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useFeature } from '@/composables/useFeature'
import {
  CheckCircleIcon,
  ArrowUpCircleIcon,
  ArrowDownCircleIcon,
  XCircleIcon,
  ArrowPathIcon,
  ClockIcon,
  UsersIcon,
  CreditCardIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()
const { reload: reloadFeatures } = useFeature()

const subscription = ref(null)
const usage = ref(null)
const daysRemaining = ref(null)
const trialDaysRemaining = ref(null)
const plans = ref([])
const featuresMap = ref({})
const loading = ref(true)
const acting = ref(false)

const currentPlanCode = computed(() => subscription.value?.plan_code || null)
const isTrial = computed(() => subscription.value?.status === 'TRIAL')
const isActive = computed(() => subscription.value?.status === 'ACTIVE')
const isPastDue = computed(() => subscription.value?.status === 'PAST_DUE')
const isSuspended = computed(() => subscription.value?.status === 'SUSPENDED')
const isCancelled = computed(() => subscription.value?.status === 'CANCELLED')

async function loadSubscription() {
  try {
    const res = await api.get('/billing/subscription')
    const data = res.data?.data || {}
    subscription.value = data.subscription || null
    usage.value = data.usage || null
    daysRemaining.value = data.days_remaining ?? null
    trialDaysRemaining.value = data.trial_days_remaining ?? null
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat subscription')
  }
}

async function loadPlans() {
  try {
    const res = await api.get('/plans')
    plans.value = res.data?.data?.plans || []
    featuresMap.value = res.data?.data?.features_map || {}
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat daftar plan')
  }
}

onMounted(async () => {
  loading.value = true
  await Promise.all([loadSubscription(), loadPlans()])
  loading.value = false
})

function statusBadge(status) {
  const map = {
    TRIAL:     { bg: 'bg-blue-100 text-blue-800', label: 'TRIAL' },
    ACTIVE:    { bg: 'bg-emerald-100 text-emerald-800', label: 'ACTIVE' },
    PAST_DUE:  { bg: 'bg-amber-100 text-amber-800', label: 'PAST DUE' },
    SUSPENDED: { bg: 'bg-rose-100 text-rose-800', label: 'SUSPENDED' },
    CANCELLED: { bg: 'bg-gray-200 text-gray-700', label: 'CANCELLED' },
  }
  return map[status] || { bg: 'bg-gray-100', label: status }
}

function formatRupiah(v) {
  if (! v) return 'Rp 0'
  return 'Rp ' + Number(v).toLocaleString('id-ID')
}

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

async function upgrade(planCode) {
  const ok = await confirmDialog({
    title: `Upgrade ke ${planCode}?`,
    message: `Pembayaran akan dikalkulasi dengan proration berdasarkan sisa hari di period saat ini. Anda akan dapat akses ke fitur ${planCode} segera setelah upgrade.`,
    okText: 'Upgrade Sekarang',
    variant: 'primary',
  })
  if (! ok) return
  acting.value = true
  try {
    await api.post('/billing/upgrade', { plan_code: planCode })
    toast.success(`Berhasil upgrade ke ${planCode}`)
    await loadSubscription()
    await reloadFeatures()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal upgrade')
  } finally {
    acting.value = false
  }
}

async function downgrade(planCode) {
  const ok = await confirmDialog({
    title: `Downgrade ke ${planCode}?`,
    message: `Downgrade akan dijadwalkan untuk period berikutnya (efektif ${formatDate(subscription.value?.current_period_end)}). Anda tetap dapat menggunakan fitur saat ini sampai akhir period.`,
    okText: 'Schedule Downgrade',
    variant: 'warning',
  })
  if (! ok) return
  acting.value = true
  try {
    await api.post('/billing/downgrade', { plan_code: planCode })
    toast.success(`Downgrade ke ${planCode} dijadwalkan`)
    await loadSubscription()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal downgrade')
  } finally {
    acting.value = false
  }
}

async function cancelSubscription() {
  const reason = window.prompt('Alasan pembatalan (minimum 10 karakter, untuk feedback kami):')
  if (! reason || reason.trim().length < 10) {
    toast.error('Alasan minimal 10 karakter')
    return
  }
  const ok = await confirmDialog({
    title: 'Batalkan Subscription?',
    message: `Akun tetap aktif sampai ${formatDate(subscription.value?.current_period_end)}. Setelah itu, akun akan masuk mode read-only. Anda bisa reactivate kapan saja.`,
    okText: 'Ya, Batalkan',
    variant: 'danger',
  })
  if (! ok) return
  acting.value = true
  try {
    await api.post('/billing/cancel', { reason: reason.trim() })
    toast.success('Subscription dibatalkan')
    await loadSubscription()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal cancel')
  } finally {
    acting.value = false
  }
}

async function reactivateSubscription() {
  const ok = await confirmDialog({
    title: 'Aktifkan Kembali Subscription?',
    message: 'Subscription akan kembali aktif. Bila ada tagihan tertunggak, mohon selesaikan pembayaran.',
    okText: 'Reactivate',
    variant: 'primary',
  })
  if (! ok) return
  acting.value = true
  try {
    await api.post('/billing/reactivate')
    toast.success('Subscription aktif kembali')
    await loadSubscription()
    await reloadFeatures()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal reactivate')
  } finally {
    acting.value = false
  }
}

function comparePlanLevel(targetCode) {
  const order = { FREE_TRIAL: 0, STARTER: 1, PRO: 2, ENTERPRISE: 3 }
  const cur = order[currentPlanCode.value] ?? -1
  const tgt = order[targetCode] ?? -1
  if (tgt > cur) return 'upgrade'
  if (tgt < cur) return 'downgrade'
  return 'current'
}
</script>

<template>
  <div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold mb-6 dark:text-white">Subscription & Billing</h1>

    <div v-if="loading" class="text-gray-500 dark:text-gray-400 py-12 text-center">
      Memuat data subscription…
    </div>

    <!-- Current subscription card -->
    <div
      v-else-if="subscription"
      class="rounded-lg bg-white dark:bg-gray-800 shadow border border-gray-200 dark:border-gray-700 p-6 mb-6"
    >
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div class="min-w-0">
          <div class="flex items-center gap-2 mb-2">
            <h2 class="text-xl font-semibold dark:text-white">
              {{ subscription.plan_name || subscription.plan_code }}
            </h2>
            <span
              class="px-2 py-0.5 text-xs font-semibold rounded"
              :class="statusBadge(subscription.status).bg"
            >
              {{ statusBadge(subscription.status).label }}
            </span>
          </div>
          <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
            <span v-if="subscription.billing_cycle === 'YEARLY'">Tagihan tahunan</span>
            <span v-else>Tagihan bulanan</span>
            ·
            {{ formatRupiah(subscription.custom_price || subscription.price_monthly) }}/bulan
          </p>

          <div class="flex items-center gap-6 text-sm flex-wrap">
            <div class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400">
              <ClockIcon class="h-4 w-4" />
              <span v-if="isTrial && trialDaysRemaining !== null">
                Trial berakhir dalam <strong class="text-blue-600">{{ trialDaysRemaining }} hari</strong>
              </span>
              <span v-else-if="daysRemaining !== null">
                Period berakhir dalam <strong>{{ daysRemaining }} hari</strong>
                ({{ formatDate(subscription.current_period_end) }})
              </span>
            </div>
            <div v-if="usage" class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400">
              <UsersIcon class="h-4 w-4" />
              <span>
                {{ usage.employees_current }}
                <span v-if="usage.employees_limit"> / {{ usage.employees_limit }}</span>
                karyawan
                <span v-if="usage.employees_pct !== null && usage.employees_pct >= 80"
                  class="ml-1 text-amber-600">
                  ({{ usage.employees_pct }}%)
                </span>
              </span>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
          <router-link
            to="/billing/usage"
            class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium border rounded hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-gray-200"
          >
            <CreditCardIcon class="h-4 w-4" />
            Lihat Usage
          </router-link>
          <button
            v-if="isActive || isTrial || isPastDue"
            type="button"
            class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium border border-rose-300 text-rose-700 rounded hover:bg-rose-50 dark:border-rose-700 dark:text-rose-400"
            :disabled="acting"
            @click="cancelSubscription"
          >
            <XCircleIcon class="h-4 w-4" />
            Cancel
          </button>
          <button
            v-if="isCancelled || isSuspended"
            type="button"
            class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium bg-emerald-600 text-white rounded hover:bg-emerald-500"
            :disabled="acting"
            @click="reactivateSubscription"
          >
            <ArrowPathIcon class="h-4 w-4" />
            Reactivate
          </button>
        </div>
      </div>

      <!-- Banner: trial soon expire -->
      <div
        v-if="isTrial && trialDaysRemaining !== null && trialDaysRemaining <= 3"
        class="mt-4 rounded bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 px-4 py-2 text-sm text-amber-800 dark:text-amber-200"
      >
        ⚠️ Trial akan berakhir dalam {{ trialDaysRemaining }} hari. Pilih plan untuk lanjut.
      </div>

      <!-- Banner: PAST_DUE -->
      <div
        v-if="isPastDue"
        class="mt-4 rounded bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 px-4 py-2 text-sm text-amber-800 dark:text-amber-200"
      >
        ⚠️ Tagihan Anda lewat jatuh tempo. Segera lakukan pembayaran sebelum H+10 untuk menghindari suspend.
      </div>

      <!-- Banner: SUSPENDED -->
      <div
        v-if="isSuspended"
        class="mt-4 rounded bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-700 px-4 py-2 text-sm text-rose-800 dark:text-rose-200"
      >
        🔒 Akun Anda <strong>SUSPENDED</strong>. Mode read-only aktif. Klik Reactivate setelah pembayaran.
      </div>
    </div>

    <!-- Plan comparison -->
    <div v-if="!loading && plans.length" class="mt-8">
      <h2 class="text-xl font-bold mb-4 dark:text-white">Pilih Plan</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div
          v-for="plan in plans"
          :key="plan.code"
          class="rounded-lg border p-5 flex flex-col"
          :class="plan.code === currentPlanCode
            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-700'
            : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800'"
        >
          <div class="mb-3">
            <h3 class="text-lg font-bold dark:text-white">{{ plan.name }}</h3>
            <p class="text-xs text-gray-500 mt-1">{{ plan.description }}</p>
          </div>

          <div class="text-3xl font-bold dark:text-white mb-1">
            {{ formatRupiah(plan.price_monthly) }}
            <span class="text-sm font-normal text-gray-500">/bulan</span>
          </div>
          <div class="text-xs text-gray-400 mb-4">
            atau {{ formatRupiah(plan.price_yearly) }}/tahun (hemat 20%)
          </div>

          <ul class="text-sm space-y-1.5 mb-5 flex-1 dark:text-gray-300">
            <li class="flex items-start gap-1.5">
              <CheckCircleIcon class="h-4 w-4 text-emerald-500 flex-shrink-0 mt-0.5" />
              <span>{{ plan.max_employees ? `Hingga ${plan.max_employees}` : 'Unlimited' }} karyawan</span>
            </li>
            <li v-if="plan.max_storage_gb" class="flex items-start gap-1.5">
              <CheckCircleIcon class="h-4 w-4 text-emerald-500 flex-shrink-0 mt-0.5" />
              <span>{{ plan.max_storage_gb }} GB storage</span>
            </li>
            <li v-if="plan.max_api_calls_month" class="flex items-start gap-1.5">
              <CheckCircleIcon class="h-4 w-4 text-emerald-500 flex-shrink-0 mt-0.5" />
              <span>{{ plan.max_api_calls_month.toLocaleString('id-ID') }} API calls/bulan</span>
            </li>
            <li
              v-for="f in (featuresMap[plan.code] || []).slice(0, 6)"
              :key="f.key"
              class="flex items-start gap-1.5"
            >
              <CheckCircleIcon class="h-4 w-4 text-emerald-500 flex-shrink-0 mt-0.5" />
              <span class="truncate">{{ f.description || f.key }}</span>
            </li>
            <li v-if="(featuresMap[plan.code] || []).length > 6" class="text-xs text-gray-400 italic pl-5">
              + {{ (featuresMap[plan.code] || []).length - 6 }} fitur lain
            </li>
          </ul>

          <button
            v-if="plan.code === currentPlanCode"
            type="button"
            disabled
            class="w-full px-3 py-2 text-sm font-medium rounded bg-gray-100 dark:bg-gray-700 text-gray-500 cursor-not-allowed"
          >
            Plan Aktif
          </button>
          <button
            v-else-if="comparePlanLevel(plan.code) === 'upgrade'"
            type="button"
            class="w-full inline-flex items-center justify-center gap-1 px-3 py-2 text-sm font-medium rounded bg-blue-600 text-white hover:bg-blue-500"
            :disabled="acting"
            @click="upgrade(plan.code)"
          >
            <ArrowUpCircleIcon class="h-4 w-4" />
            Upgrade
          </button>
          <button
            v-else
            type="button"
            class="w-full inline-flex items-center justify-center gap-1 px-3 py-2 text-sm font-medium rounded border border-amber-400 text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-400"
            :disabled="acting"
            @click="downgrade(plan.code)"
          >
            <ArrowDownCircleIcon class="h-4 w-4" />
            Downgrade
          </button>
        </div>
      </div>
    </div>
  </div>
</template>