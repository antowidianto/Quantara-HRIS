<script setup>
/**
 * S1.6 SaaS — Global subscription status banner.
 *
 * Tampilkan banner full-width di top of layout untuk informasikan status
 * subscription ke user. Banner muncul saat:
 *   - PAST_DUE  → kuning, "Pembayaran tertunggak, akan disuspend X hari"
 *   - SUSPENDED → merah, "Akun disuspend, semua aksi write disabled"
 *   - EXPIRED   → merah, "Akun expired, reactivate untuk lanjut"
 *   - TRIAL     → biru, hanya tampil bila days_remaining ≤ 5
 *
 * Saat ACTIVE atau no-subscription, banner tidak tampil.
 *
 * Mount sekali di AppLayout.vue di atas <main>.
 */
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useSubscription } from '@/composables/useSubscription'
import {
  ExclamationTriangleIcon,
  XCircleIcon,
  ClockIcon,
  ArrowRightIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const { subscription, status, isPastDue, isSuspended, isExpired, isTrial,
        daysRemaining } = useSubscription()

const visible = computed(() => {
  if (isSuspended.value || isExpired.value || isPastDue.value) return true
  if (isTrial.value && (daysRemaining.value ?? 999) <= 5) return true
  return false
})

const variant = computed(() => {
  if (isSuspended.value || isExpired.value) return 'error'
  if (isPastDue.value) return 'warning'
  if (isTrial.value) return 'info'
  return 'info'
})

const message = computed(() => {
  if (isSuspended.value) {
    return 'Akun Anda disuspend. Semua operasi pembuatan/perubahan data tidak bisa dilakukan sampai pembayaran tertunggak diselesaikan.'
  }
  if (isExpired.value) {
    return 'Subscription Anda sudah berakhir. Reaktivasi sekarang untuk melanjutkan penggunaan.'
  }
  if (isPastDue.value) {
    const days = daysRemaining.value
    return days !== null && days >= 0
      ? `Pembayaran Anda tertunggak. Akun akan disuspend dalam ${days} hari kalau belum dibayar.`
      : 'Pembayaran Anda tertunggak. Lakukan pembayaran segera untuk menghindari suspend.'
  }
  if (isTrial.value) {
    const days = daysRemaining.value
    if (days !== null && days <= 0) {
      return 'Trial Anda berakhir hari ini. Upgrade sekarang biar tidak kehilangan akses.'
    }
    return `Trial Anda berakhir dalam ${days} hari. Upgrade sebelum masa trial habis.`
  }
  return ''
})

const cta = computed(() => {
  if (isExpired.value || isSuspended.value) return { label: 'Reaktivasi', target: '/billing/plan' }
  if (isPastDue.value) return { label: 'Bayar Sekarang', target: '/billing/plan' }
  if (isTrial.value) return { label: 'Lihat Plan', target: '/billing/plan' }
  return { label: 'Lihat Detail', target: '/billing/plan' }
})

const styles = computed(() => {
  switch (variant.value) {
    case 'error':
      return 'bg-rose-50 dark:bg-rose-900/30 text-rose-800 dark:text-rose-200 border-rose-200 dark:border-rose-800'
    case 'warning':
      return 'bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200 border-amber-200 dark:border-amber-800'
    default:
      return 'bg-blue-50 dark:bg-blue-900/30 text-blue-800 dark:text-blue-200 border-blue-200 dark:border-blue-800'
  }
})

function goToCta() {
  router.push(cta.value.target)
}
</script>

<template>
  <div
    v-if="visible"
    class="border-b px-4 py-2.5 flex items-center gap-3 text-sm"
    :class="styles"
    role="alert"
  >
    <XCircleIcon
      v-if="variant === 'error'"
      class="h-5 w-5 shrink-0"
    />
    <ExclamationTriangleIcon
      v-else-if="variant === 'warning'"
      class="h-5 w-5 shrink-0"
    />
    <ClockIcon
      v-else
      class="h-5 w-5 shrink-0"
    />

    <div class="flex-1 min-w-0">
      <span class="font-semibold">{{ status }}</span>
      <span class="ml-2">{{ message }}</span>
    </div>

    <button
      type="button"
      class="shrink-0 inline-flex items-center gap-1 px-3 py-1.5 rounded text-xs font-semibold bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 border border-current/20"
      @click="goToCta"
    >
      {{ cta.label }}
      <ArrowRightIcon class="h-3 w-3" />
    </button>
  </div>
</template>