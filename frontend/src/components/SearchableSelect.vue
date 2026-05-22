<script setup>
/**
 * SearchableSelect — combobox dropdown generic dengan client-side search.
 *
 * Drop-in replacement untuk <select> ketika listnya berpotensi panjang
 * (puluhan-ratusan item) sehingga scrolling 1-per-1 menyusahkan user.
 *
 * Beda dengan EmployeeSearchSelect yang memang server-side (untuk
 * dataset karyawan yang bisa ribuan), komponen ini untuk data master
 * yang sudah di-load semua di parent (positions, departments, branches,
 * job grades, work locations, roles, users, dst.) — search-nya cukup
 * client-side karena datanya sudah ada di memori.
 *
 * Features:
 *   - v-model compatible: <SearchableSelect v-model="form.position_id" />
 *   - Filter realtime saat user mengetik (case-insensitive, multi-field)
 *   - Keyboard navigasi: ↑/↓ pindah, Enter pilih, Esc tutup
 *   - Clearable (default true) untuk membersihkan pilihan
 *   - Disabled state
 *   - Custom rendering via slot #item dan #selected
 *   - Placeholder bisa di-override
 *   - Allow-empty option (untuk filter "Semua") via placeholder + clearable
 *
 * Usage standar:
 *   <SearchableSelect
 *     v-model="form.position_id"
 *     :items="positions"
 *     value-key="id"
 *     label-key="name"
 *     placeholder="— Pilih posisi —"
 *   />
 *
 * Usage dengan sub-label dan custom format:
 *   <SearchableSelect
 *     v-model="form.position_id"
 *     :items="positions"
 *     value-key="id"
 *     label-key="name"
 *     sub-label-key="department_name"
 *     :search-keys="['name','code','department_name']"
 *   />
 *
 * Usage dengan slot custom:
 *   <SearchableSelect v-model="form.x" :items="rows">
 *     <template #item="{ item }">{{ item.code }} — {{ item.name }}</template>
 *   </SearchableSelect>
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import {
  MagnifyingGlassIcon, XMarkIcon, ChevronDownIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  /** Nilai terpilih (id biasanya). null/undefined = belum dipilih. */
  modelValue: { type: [Number, String, Boolean, null], default: null },
  /** Array data yang dapat dipilih. */
  items: { type: Array, default: () => [] },
  /** Field di item yang dipakai sebagai value (yang dikirim via v-model). */
  valueKey: { type: String, default: 'id' },
  /** Field di item yang ditampilkan sebagai label utama. */
  labelKey: { type: String, default: 'name' },
  /** Field di item yang ditampilkan sebagai label kedua (kecil, abu-abu). */
  subLabelKey: { type: String, default: '' },
  /** Field tambahan yang ikut dicari saat user mengetik. Otomatis sudah
   * mencakup labelKey dan subLabelKey kalau di-set. */
  searchKeys: { type: Array, default: () => [] },
  /** Placeholder ketika belum ada pilihan. */
  placeholder: { type: String, default: '— Pilih —' },
  /** Disable interaksi. */
  disabled: { type: Boolean, default: false },
  /** Tampilkan tombol clear (×). */
  clearable: { type: Boolean, default: true },
  /** Tampilkan input search dalam dropdown. */
  searchable: { type: Boolean, default: true },
  /** Tinggi maksimum panel hasil. */
  maxHeight: { type: String, default: '18rem' },
  /** Ukuran: sm | md (default md). */
  size: { type: String, default: 'md' },
  /** Tandai invalid (border merah). */
  invalid: { type: Boolean, default: false },
  /** Compare value secara longgar (string vs number) — default true. */
  loose: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue', 'change'])

// ─── State ──────────────────────────────────────────────────────────────
const open = ref(false)
const query = ref('')
const highlighted = ref(0)

const triggerRef = ref(null)
const inputRef = ref(null)
const listRef = ref(null)

// ─── Helpers ────────────────────────────────────────────────────────────

function getValue(item) {
  if (item == null) return null
  return item?.[props.valueKey]
}
function getLabel(item) {
  if (item == null) return ''
  const v = item?.[props.labelKey]
  return v == null ? '' : String(v)
}
function getSubLabel(item) {
  if (! props.subLabelKey || item == null) return ''
  const v = item?.[props.subLabelKey]
  return v == null ? '' : String(v)
}

function valueEqual(a, b) {
  if (a == null && b == null) return true
  if (a == null || b == null) return false
  if (props.loose) {
    // eslint-disable-next-line eqeqeq
    return a == b
  }
  return a === b
}

/**
 * Item yang sedang terpilih (dicari di items berdasarkan modelValue).
 * Kalau tidak ketemu (mis. items belum ke-load), kembalikan null.
 */
const selectedItem = computed(() => {
  if (props.modelValue == null || props.modelValue === '') return null
  return props.items.find(it => valueEqual(getValue(it), props.modelValue)) || null
})

/**
 * Hasil filter berdasar query. Kalau query kosong, kembalikan semua items.
 * Pencarian case-insensitive di labelKey + subLabelKey + searchKeys.
 */
const filteredItems = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (! q) return props.items

  const keys = new Set([props.labelKey, props.subLabelKey, ...props.searchKeys].filter(Boolean))
  return props.items.filter(it => {
    for (const k of keys) {
      const v = it?.[k]
      if (v == null) continue
      if (String(v).toLowerCase().includes(q)) return true
    }
    return false
  })
})

// ─── Open/close + selection ─────────────────────────────────────────────
async function openMenu() {
  if (props.disabled) return
  open.value = true
  query.value = ''
  // Set highlighted ke item terpilih kalau ada
  if (selectedItem.value) {
    const idx = filteredItems.value.findIndex(it => valueEqual(getValue(it), props.modelValue))
    highlighted.value = idx >= 0 ? idx : 0
  } else {
    highlighted.value = 0
  }
  await nextTick()
  if (props.searchable) inputRef.value?.focus()
  scrollHighlightedIntoView()
}

function closeMenu() {
  open.value = false
  query.value = ''
}

function pick(item) {
  emit('update:modelValue', item == null ? null : getValue(item))
  emit('change', item)
  closeMenu()
}

function clearSelection(ev) {
  ev?.stopPropagation()
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
    highlighted.value = Math.min(highlighted.value + 1, filteredItems.value.length - 1)
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
    const it = filteredItems.value[highlighted.value]
    if (it) pick(it)
    return
  }
  if (ev.key === 'Home') {
    ev.preventDefault()
    highlighted.value = 0
    scrollHighlightedIntoView()
    return
  }
  if (ev.key === 'End') {
    ev.preventDefault()
    highlighted.value = filteredItems.value.length - 1
    scrollHighlightedIntoView()
    return
  }
}

function scrollHighlightedIntoView() {
  nextTick(() => {
    const el = listRef.value?.querySelector(`[data-idx="${highlighted.value}"]`)
    el?.scrollIntoView({ block: 'nearest' })
  })
}

// Reset highlight saat hasil filter berubah (setelah user mengetik)
watch(filteredItems, () => {
  highlighted.value = 0
})

// ─── Click-outside handling ────────────────────────────────────────────
function onDocClick(ev) {
  if (! open.value) return
  if (! triggerRef.value) return
  const container = triggerRef.value.closest('.searchable-select-root')
  if (container && ! container.contains(ev.target)) {
    closeMenu()
  }
}

// ─── Lifecycle ─────────────────────────────────────────────────────────
onMounted(() => {
  document.addEventListener('mousedown', onDocClick)
})
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocClick)
})

// ─── Display ───────────────────────────────────────────────────────────
const sizeClass = computed(() => props.size === 'sm'
  ? 'px-2 py-1 text-xs'
  : 'px-2.5 py-2 text-sm')
</script>

<template>
  <div class="searchable-select-root relative">
    <!-- Trigger -->
    <button
      ref="triggerRef"
      type="button"
      :disabled="disabled"
      @click="open ? closeMenu() : openMenu()"
      class="w-full inline-flex items-center gap-2 rounded-md border bg-white text-left transition-colors dark:bg-gray-800 dark:text-gray-100"
      :class="[
        sizeClass,
        disabled ? 'opacity-60 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50 cursor-pointer',
        invalid ? 'border-red-400 focus:ring-red-400' : 'border-gray-300 dark:border-gray-600',
      ]"
    >
      <span class="flex-1 min-w-0">
        <template v-if="selectedItem">
          <slot name="selected" :item="selectedItem">
            <span class="block truncate font-medium">{{ getLabel(selectedItem) }}</span>
            <span v-if="getSubLabel(selectedItem)"
              class="block text-[11px] text-gray-500 truncate">
              {{ getSubLabel(selectedItem) }}
            </span>
          </slot>
        </template>
        <template v-else>
          <span class="block truncate text-gray-400">{{ placeholder }}</span>
        </template>
      </span>
      <button
        v-if="clearable && selectedItem && ! disabled"
        type="button"
        class="text-gray-400 hover:text-red-500 p-0.5 rounded flex-shrink-0"
        @click="clearSelection"
        title="Hapus pilihan"
      >
        <XMarkIcon class="w-4 h-4" />
      </button>
      <ChevronDownIcon class="w-4 h-4 text-gray-400 flex-shrink-0 transition-transform"
        :class="open ? 'rotate-180' : ''" />
    </button>

    <!-- Dropdown panel -->
    <div
      v-if="open"
      class="absolute z-50 mt-1 w-full min-w-[14rem] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-xl overflow-hidden"
    >
      <!-- Search input -->
      <div v-if="searchable" class="p-2 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">
        <div class="relative">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            ref="inputRef"
            v-model="query"
            type="text"
            placeholder="Ketik untuk mencari…"
            class="w-full pl-8 pr-2 py-1.5 text-sm border border-gray-200 dark:border-gray-600 rounded-md bg-white dark:bg-gray-800 focus:outline-none focus:ring-1 focus:ring-primary-400 focus:border-primary-400"
            @keydown="onKeydown"
          />
        </div>
      </div>

      <!-- Result list -->
      <div ref="listRef" class="overflow-y-auto" :style="{ maxHeight: maxHeight }">
        <div v-if="filteredItems.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
          <span v-if="query">Tidak ada hasil untuk "<strong>{{ query }}</strong>".</span>
          <span v-else>Tidak ada data.</span>
        </div>
        <button
          v-for="(item, idx) in filteredItems"
          :key="getValue(item) ?? idx"
          type="button"
          :data-idx="idx"
          class="w-full flex items-start gap-2 px-3 py-2 text-left hover:bg-primary-50 dark:hover:bg-primary-900/30 border-b border-gray-50 dark:border-gray-700/60 last:border-0 transition-colors"
          :class="[
            highlighted === idx ? 'bg-primary-50 dark:bg-primary-900/30' : '',
            valueEqual(getValue(item), modelValue) ? 'bg-primary-100/60 dark:bg-primary-900/50' : '',
          ]"
          @click="pick(item)"
          @mousemove="highlighted = idx"
        >
          <span class="flex-1 min-w-0">
            <slot name="item" :item="item" :selected="valueEqual(getValue(item), modelValue)">
              <span class="block text-sm truncate"
                :class="valueEqual(getValue(item), modelValue) ? 'font-semibold' : ''">
                {{ getLabel(item) }}
              </span>
              <span v-if="getSubLabel(item)"
                class="block text-[11px] text-gray-500 dark:text-gray-400 truncate">
                {{ getSubLabel(item) }}
              </span>
            </slot>
          </span>
        </button>
      </div>

      <!-- Footer hint -->
      <div v-if="searchable && filteredItems.length > 0"
        class="px-3 py-1.5 text-[11px] text-gray-400 bg-gray-50 dark:bg-gray-900/40 border-t border-gray-100 dark:border-gray-700">
        {{ filteredItems.length }} item · ↑↓ pilih · Enter konfirmasi · Esc tutup
      </div>
    </div>
  </div>
</template>