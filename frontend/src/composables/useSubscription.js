/**
 * S1.2 / S1.6 SaaS — Subscription status composable.
 *
 * Lightweight wrapper di atas GET /billing/subscription. Cache 60 detik
 * supaya tidak refetch di setiap component, tapi tetap up-to-date saat
 * status berubah (e.g. setelah upgrade/downgrade).
 *
 * Usage:
 *   import { useSubscription } from '@/composables/useSubscription'
 *   const { subscription, isSuspended, isPastDue, isTrial, daysRemaining,
 *           refresh, ready } = useSubscription()
 *
 * Banner & guard logic memakai computed:
 *   - isSuspended  → tampilkan SuspendedBanner + read-only mode
 *   - isPastDue    → tampilkan PastDue banner kuning + countdown
 *   - isTrial      → trial countdown (di komponen UpgradeBanner)
 */
import { ref, computed } from 'vue'
import api from '@/api/client'

const CACHE_TTL_MS = 60 * 1000

const state = ref(null)
const fetchedAt = ref(0)
let inflight = null

async function ensureLoaded(force = false) {
  const now = Date.now()
  if (! force && state.value && now - fetchedAt.value < CACHE_TTL_MS) {
    return state.value
  }
  if (inflight) return inflight

  inflight = api.get('/billing/subscription')
    .then(res => {
      state.value = res.data?.data || null
      fetchedAt.value = Date.now()
      return state.value
    })
    .catch(err => {
      // 404 = tenant tidak punya subscription (legacy / pre-SaaS) — silent
      if (err.response?.status !== 404) {
        // eslint-disable-next-line no-console
        console.warn('[useSubscription] fetch failed:', err?.message || err)
      }
      state.value = null
      fetchedAt.value = Date.now()
      return null
    })
    .finally(() => { inflight = null })

  return inflight
}

export function useSubscription() {
  // Auto-fetch on first use; return reactive state
  ensureLoaded()

  const subscription = computed(() => state.value)
  const status = computed(() => state.value?.status || null)

  return {
    subscription,
    status,
    ready: computed(() => fetchedAt.value > 0),

    // Status helpers
    isTrial:     computed(() => status.value === 'TRIAL'),
    isActive:    computed(() => status.value === 'ACTIVE'),
    isPastDue:   computed(() => status.value === 'PAST_DUE'),
    isSuspended: computed(() => status.value === 'SUSPENDED'),
    isCancelled: computed(() => status.value === 'CANCELLED'),
    isExpired:   computed(() => status.value === 'EXPIRED'),

    // Read-only kalau tenant SUSPENDED atau EXPIRED — mirror logic backend
    // SuspendedTenantFilter (block POST/PUT/DELETE).
    isReadOnly: computed(() =>
      ['SUSPENDED', 'EXPIRED', 'CANCELLED'].includes(status.value),
    ),

    // Days remaining helpers
    daysRemaining: computed(() => state.value?.days_remaining ?? null),
    trialEndsAt:   computed(() => state.value?.trial_ends_at || null),
    periodEnd:     computed(() => state.value?.current_period_end || null),

    refresh: () => ensureLoaded(true),
  }
}