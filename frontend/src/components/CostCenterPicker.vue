<script setup>
/**
 * CostCenterPicker — dropdown reusable untuk memilih cost center.
 *
 * Dipakai di form transaksi (reimbursement, loan, business trip, bonus)
 * dan form master (employee, MPP) untuk override default cost center.
 *
 * Props:
 *   modelValue   — id cost center (number | null)
 *   required     — bool (optional)
 *   placeholder  — string (default "— Pilih cost center —")
 *   disabled     — bool
 *   showClear    — bool (default true) untuk allow clear ke null
 *   includeInactive — bool (default false)
 *
 * Emits:
 *   update:modelValue — saat user pilih id baru
 *
 * Lookup endpoint /cost-centers/picker dilakukan sekali on mount
 * dan di-cache di module-level supaya banyak picker pada page yang
 * sama hanya 1 request.
 */
import { ref, watch, computed, onMounted } from 'vue'
import api from '@/api/client'

const props = defineProps({
  modelValue:      { type: [Number, null], default: null },
  required:        { type: Boolean, default: false },
  placeholder:     { type: String, default: '— Pilih cost center —' },
  disabled:        { type: Boolean, default: false },
  showClear:       { type: Boolean, default: true },
  includeInactive: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

// Module-level cache (shared across all picker instances on same page)
let cachePromise = null
const items = ref([])
const loading = ref(false)
const error = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    if (! cachePromise) {
      cachePromise = api.get('/cost-centers/picker')
    }
    const res = await cachePromise
    let rows = res.data?.data || []
    if (! props.includeInactive) {
      rows = rows.filter(r => r.is_active !== 0)
    }
    items.value = rows
  } catch (e) {
    error.value = e.response?.data?.message || 'Gagal memuat cost center'
    cachePromise = null // allow retry
  } finally {
    loading.value = false
  }
}

onMounted(load)

const internalValue = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v === '' || v == null ? null : Number(v)),
})

function labelFor(item) {
  let base = `${item.code} — ${item.name}`
  if (item.parent_code) base += ` (${item.parent_code})`
  return base
}
</script>

<template>
  <div>
    <select
      v-model="internalValue"
      :required="required"
      :disabled="disabled || loading"
      class="input"
    >
      <option v-if="showClear || !required" :value="null">
        {{ loading ? 'Memuat...' : placeholder }}
      </option>
      <option v-for="item in items" :key="item.id" :value="item.id">
        {{ labelFor(item) }}
      </option>
    </select>
    <p v-if="error" class="text-xs text-red-600 mt-1">{{ error }}</p>
  </div>
</template>