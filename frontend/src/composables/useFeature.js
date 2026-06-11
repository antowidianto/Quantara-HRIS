import { ref, computed } from 'vue'
import api from '@/api/client'

/**
 * S1.1 SaaS — Feature gate composable.
 *
 * Cached fetch ke `/api/v1/features/me` lalu expose helper:
 *   - has(key)        — return bool, true kalau feature aktif untuk tenant ini
 *   - quota(key)      — return number atau null
 *   - source(key)     — 'OVERRIDE' | 'PLAN' | 'DEFAULT'
 *   - reload()        — force refetch (dipanggil setelah upgrade plan)
 *
 * Usage di component:
 *   import { useFeature } from '@/composables/useFeature'
 *   const { has, ready } = useFeature()
 *   <UpgradeBanner v-if="!has('white_label.enabled')" feature="white_label.enabled" />
 *
 * Cache lifetime: per session (refresh manual via reload()). Dipanggil
 * sekali di App.vue mount, lalu shared di semua komponen.
 */

// Module-level singleton supaya semua component share state yang sama.
const features = ref(null)
const loading = ref(false)
const error = ref(null)
const ready = computed(() => features.value !== null)

async function load(force = false) {
  if (loading.value) return
  if (features.value !== null && ! force) return
  loading.value = true
  error.value = null
  try {
    const res = await api.get('/features/me')
    // Backend return [{key, enabled, quota_value, source, category, description}, …]
    const list = res.data?.data || []
    const map = {}
    for (const f of list) {
      if (! f || ! f.key) continue
      map[f.key] = {
        enabled: !! f.enabled,
        quota_value: f.quota_value,
        source: f.source || 'DEFAULT',
        category: f.category || null,
        description: f.description || null,
      }
    }
    features.value = map
  } catch (e) {
    error.value = e
    // Fallback: anggap semua disabled (fail-closed) saat error
    features.value = {}
  } finally {
    loading.value = false
  }
}

export function useFeature() {
  // Auto-load saat composable pertama kali dipanggil
  if (features.value === null && ! loading.value) {
    load()
  }

  function has(key) {
    if (features.value === null) return false
    return !! features.value[key]?.enabled
  }

  function quota(key) {
    if (features.value === null) return null
    return features.value[key]?.quota_value ?? null
  }

  function source(key) {
    return features.value?.[key]?.source || 'DEFAULT'
  }

  function info(key) {
    return features.value?.[key] || null
  }

  function reload() {
    return load(true)
  }

  return {
    features,
    ready,
    loading,
    error,
    has,
    quota,
    source,
    info,
    reload,
  }
}

// Helper standalone — pakai di route guard / non-component code.
export async function ensureFeaturesLoaded() {
  if (features.value === null && ! loading.value) {
    await load()
  }
  return features.value
}