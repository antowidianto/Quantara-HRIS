<script setup>
/**
 * EmployeeSearchSelect — combobox dropdown dengan server-side search
 * untuk memilih karyawan. Cocok untuk dataset besar (ratusan/ribuan)
 * yang tidak bisa di-load semua sekaligus.
 *
 * Features:
 *   - Search debounced 250ms ke endpoint /employees (search=...)
 *   - Tampilkan avatar/inisial, nama, NIP, dept, posisi
 *   - Clearable; nilai null saat user kosongkan
 *   - Auto fetch label saat mount kalau modelValue di-set tapi belum
 *     punya selectedEmployee (mis. saat edit form yang dipreload).
 *   - Keyboard navigasi: ↑/↓ pindah highlight, Enter pilih, Esc tutup.
 *   - v-model compatible: <EmployeeSearchSelect v-model="emp_id" />
 *   - Bisa diberi filter tambahan via props (department_id, dll.)
 *     yang akan diteruskan sebagai query params ke backend.
 *
 * Usage:
 *   <EmployeeSearchSelect
 *     v-model="form.employee_id"
 *     placeholder="Pilih karyawan…"
 *     :only-active="true"
 *     :disabled="false"
 *   />
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import api from '@/api/client'
import {
  MagnifyingGlassIcon, XMarkIcon, ChevronDownIcon, UserIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  modelValue: { type: [Number, String, null], default: null },
  placeholder: { type: String, default: 'Cari & pilih karyawan…' },
  disabled: { type: Boolean, default: false },
  // Kalau true (default), hanya tampilkan karyawan aktif. Kirim
  // status=active ke backend supaya non-aktif tersembunyi.
  onlyActive: { type: Boolean, default: true },
  // Filter opsional yang diteruskan ke backend.
  departmentId: { type: [Number, String, null], default: null },
  branchId:     { type: [Number, String, null], default: null },
  // Tampilkan tombol "Hapus pilihan" — defaultnya iya.
  clearable: { type: Boolean, default: true },
  // Class tambahan untuk wrapper input.
  invalid: { type: Boolean, default: false },
  // Berapa banyak baris hasil dimuat per query.
  perPage: { type: Number, default: 25 },
})

const emit = defineEmits(['update:modelValue', 'change'])

// ─── State ──────────────────────────────────────────────────────────────
const open = ref(false)
const query = ref('')
const items = ref([])
const loading = ref(false)
const highlighted = ref(0)

// Detail karyawan yang sedang terpilih (untuk render tombol).
// Disimpan terpisah dari items supaya tetap tampil meski list di-replace
// oleh hasil search berikutnya.
const selectedEmployee = ref(null)

const triggerRef = ref(null)
const inputRef = ref(null)
const listRef = ref(null)

// ─── Helpers ────────────────────────────────────────────────────────────

/**
 * Build query params untuk endpoint /employees. Ikut kirim filter dari
 * props supaya kalau parent mau scoping ke dept/branch tertentu, list
 * yang muncul sudah pra-filter dari server (lebih efisien dibanding
 * filter client-side).
 */
function buildParams(extra = {}) {
  const p = { per_page: props.perPage, ...extra }
  if (props.onlyActive) p.status = 'active'
  if (props.departmentId) p.department_id = props.departmentId
  if (props.branchId) p.branch_id = props.branchId
  return p
}

let searchTimer = null
async function search(q) {
  loading.value = true
  try {
    const res = await api.get('/employees', { params: buildParams({ search: q || '' }) })
    items.value = res.data.data || []
    highlighted.value = 0
  } catch (_) {
    items.value = []
  } finally {
    loading.value = false
  }
}

function debouncedSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => search(query.value), 250)
}

/**
 * Saat parent mengubah modelValue tapi kita belum punya snapshot
 * employee-nya (mis. user me-load existing draft), fetch by id supaya
 * tombol bisa menampilkan nama, bukan cuma "Karyawan #123".
 */
async function ensureSelectedLoaded() {
  if (! props.modelValue) {
    selectedEmployee.value = null
    return
  }
  if (selectedEmployee.value && Number(selectedEmployee.value.id) === Number(props.modelValue)) {
    return
  }
  try {
    const res = await api.get('/employees/' + props.modelValue)
    selectedEmployee.value = res.data.data || null
  } catch (_) {
    selectedEmployee.value = null
  }
}

// ─── Open/close + selection ─────────────────────────────────────────────
async function openMenu() {
  if (props.disabled) return
  open.value = true
  await nextTick()
  inputRef.value?.focus()
  // Initial fetch saat panel pertama dibuka, kalau belum ada items.
  if (items.value.length === 0) {
    search(query.value)
  }
}

function closeMenu() {
  open.value = false
  query.value = ''
}

function pick(emp) {
  selectedEmployee.value = emp
  emit('update:modelValue', emp ? emp.id : null)
  emit('change', emp)
  closeMenu()
}

function clearSelection(ev) {
  ev?.stopPropagation()
  selectedEmployee.value = null
  emit('update:modelValue', null)
  emit('change', null)
}

// ─── Keyboard navigasi ─────────────────────────────────────────────────
function onKeydown(ev) {
  if (! open.value) return
  if (ev.key === 'Escape') {
    ev.preventDefault()
    closeMenu()
    return
  }
  if (ev.key === 'ArrowDown') {
    ev.preventDefault()
    highlighted.value = Math.min(highlighted.value + 1, items.value.length - 1)
    scrollHighlightedIntoView()
    return
  }
  if (ev.key === 'ArrowUp') {
    ev.preventDefault()
    highlighted.value = Math.max(highlighted.value - 1, 0)
    scrollHighlightedIntoView()
    return
  }
  if (ev.key === 'Enter') {
    ev.preventDefault()
    const it = items.value[highlighted.value]
    if (it) pick(it)
    return
  }
}

function scrollHighlightedIntoView() {
  nextTick(() => {
    const el = listRef.value?.querySelector(`[data-idx="${highlighted.value}"]`)
    el?.scrollIntoView({ block: 'nearest' })
  })
}

// ─── Click-outside handling ────────────────────────────────────────────
function onDocClick(ev) {
  if (! open.value) return
  if (! triggerRef.value) return
  // Container mungkin null kalau component sudah di-unmount, guard saja.
  const container = triggerRef.value.closest('.emp-search-select-root')
  if (container && ! container.contains(ev.target)) {
    closeMenu()
  }
}

// ─── Lifecycle / watchers ──────────────────────────────────────────────
onMounted(() => {
  document.addEventListener('mousedown', onDocClick)
  ensureSelectedLoaded()
})
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocClick)
  if (searchTimer) clearTimeout(searchTimer)
})

watch(() => props.modelValue, () => ensureSelectedLoaded())
watch(query, () => debouncedSearch())

// Re-fetch jika filter dari props berubah — supaya list selalu konsisten
// dengan scope yang diminta parent.
watch(() => [props.departmentId, props.branchId, props.onlyActive], () => {
  if (open.value) search(query.value)
})

// ─── Display helper ─────────────────────────────────────────────────────
function initialOf(name) {
  if (! name) return '?'
  const parts = String(name).trim().split(/\s+/)
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?'
}
const placeholderText = computed(() => {
  if (selectedEmployee.value) return null
  return props.placeholder
})
</script>

<template>
  <div class="emp-search-select-root relative">
    <!-- Trigger button -->
    <button
      ref="triggerRef"
      type="button"
      :disabled="disabled"
      @click="open ? closeMenu() : openMenu()"
      class="w-full inline-flex items-center gap-2 px-2.5 py-2 rounded-md border bg-white text-left transition-colors"
      :class="[
        disabled ? 'opacity-60 cursor-not-allowed' : 'hover:bg-gray-50 cursor-pointer',
        invalid ? 'border-red-400 focus:ring-red-400' : 'border-gray-300',
      ]"
    >
      <!-- Selected state -->
      <template v-if="selectedEmployee">
        <span class="w-7 h-7 rounded-full bg-primary-100 text-primary-700 text-xs font-semibold flex items-center justify-center flex-shrink-0">
          <img v-if="selectedEmployee.avatar_url"
            :src="selectedEmployee.avatar_url"
            class="w-full h-full rounded-full object-cover"
            @error="(ev) => (ev.target.style.display = 'none')" />
          <span v-else>{{ initialOf(selectedEmployee.full_name) }}</span>
        </span>
        <span class="flex-1 min-w-0">
          <span class="block text-sm font-medium text-gray-900 truncate">{{ selectedEmployee.full_name }}</span>
          <span class="block text-xs text-gray-500 truncate">
            <span class="font-mono">{{ selectedEmployee.employee_number }}</span>
            <template v-if="selectedEmployee.position_name"> · {{ selectedEmployee.position_name }}</template>
            <template v-if="selectedEmployee.department_name"> · {{ selectedEmployee.department_name }}</template>
          </span>
        </span>
        <button
          v-if="clearable && ! disabled"
          type="button"
          class="text-gray-400 hover:text-red-500 p-1 rounded flex-shrink-0"
          @click="clearSelection"
          title="Hapus pilihan"
        >
          <XMarkIcon class="w-4 h-4" />
        </button>
      </template>

      <!-- Empty state -->
      <template v-else>
        <UserIcon class="w-4 h-4 text-gray-400 flex-shrink-0" />
        <span class="flex-1 text-sm text-gray-500 truncate">{{ placeholderText }}</span>
      </template>

      <ChevronDownIcon class="w-4 h-4 text-gray-400 flex-shrink-0 transition-transform"
        :class="open ? 'rotate-180' : ''" />
    </button>

    <!-- Dropdown panel -->
    <div
      v-if="open"
      class="absolute z-40 mt-1 w-full min-w-[20rem] bg-white border border-gray-200 rounded-lg shadow-xl overflow-hidden"
    >
      <!-- Search input -->
      <div class="p-2 border-b border-gray-100 bg-gray-50">
        <div class="relative">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            ref="inputRef"
            v-model="query"
            type="text"
            placeholder="Cari nama, NIP, atau email…"
            class="w-full pl-8 pr-2 py-1.5 text-sm border border-gray-200 rounded-md focus:outline-none focus:ring-1 focus:ring-primary-400 focus:border-primary-400"
            @keydown="onKeydown"
          />
        </div>
      </div>

      <!-- Result list -->
      <div ref="listRef" class="max-h-72 overflow-y-auto">
        <div v-if="loading" class="px-4 py-6 text-center text-sm text-gray-400">
          Mencari…
        </div>
        <div v-else-if="items.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
          <span v-if="query">Tidak ada karyawan cocok dengan "<strong>{{ query }}</strong>".</span>
          <span v-else>Tidak ada karyawan.</span>
        </div>
        <button
          v-for="(emp, idx) in items"
          :key="emp.id"
          type="button"
          :data-idx="idx"
          class="w-full flex items-start gap-2 px-3 py-2 text-left hover:bg-primary-50 border-b border-gray-50 last:border-0 transition-colors"
          :class="[
            highlighted === idx ? 'bg-primary-50' : '',
            Number(modelValue) === Number(emp.id) ? 'bg-primary-100/60' : '',
          ]"
          @click="pick(emp)"
          @mousemove="highlighted = idx"
        >
          <span class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 text-xs font-semibold flex items-center justify-center flex-shrink-0 mt-0.5">
            <img v-if="emp.avatar_url"
              :src="emp.avatar_url"
              class="w-full h-full rounded-full object-cover"
              @error="(ev) => (ev.target.style.display = 'none')" />
            <span v-else>{{ initialOf(emp.full_name) }}</span>
          </span>
          <span class="flex-1 min-w-0">
            <span class="block text-sm font-medium text-gray-900 truncate">{{ emp.full_name }}</span>
            <span class="block text-xs text-gray-500 truncate">
              <span class="font-mono">{{ emp.employee_number }}</span>
              <template v-if="emp.position_name"> · {{ emp.position_name }}</template>
            </span>
            <span v-if="emp.department_name || emp.branch_name" class="block text-[11px] text-gray-400 truncate">
              <span v-if="emp.department_name">{{ emp.department_name }}</span>
              <span v-if="emp.department_name && emp.branch_name"> · </span>
              <span v-if="emp.branch_name">{{ emp.branch_name }}</span>
            </span>
          </span>
          <span v-if="emp.is_active === 0 || emp.is_active === false"
            class="text-[10px] uppercase tracking-wide text-gray-400 mt-1">non-aktif</span>
        </button>
      </div>

      <!-- Footer hint -->
      <div class="px-3 py-1.5 text-[11px] text-gray-400 bg-gray-50 border-t border-gray-100">
        Ketik untuk mencari · ↑↓ pilih · Enter konfirmasi · Esc tutup
      </div>
    </div>
  </div>
</template>