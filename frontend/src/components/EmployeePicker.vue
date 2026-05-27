<script setup>
/**
 * EmployeePicker — combobox karyawan dengan search + filter level/tier.
 *
 * Dipakai di form yang butuh pilih karyawan (manager cost center, approver,
 * supervisor, interviewer, dst.). Fitur:
 *   - Tampilkan label kaya: nama + posisi + grade + departemen.
 *   - Search debounce ke backend (`/employees?search=...`).
 *   - Filter level/tier supaya cuma manager keatas yang muncul (opsional).
 *   - Show selected employee meskipun tidak ada di hasil filter saat ini
 *     (penting kalau editing record yang manager-nya sudah resign/non-aktif).
 *   - Keyboard nav (arrow up/down + enter).
 *   - Close ketika klik di luar.
 *
 * Props:
 *   modelValue       — id employee terpilih (number | null)
 *   placeholder      — string
 *   disabled         — bool
 *   minGradeLevel    — int (default 0). 7 = Manager keatas.
 *   positionTier     — 'MANAGER'|'ASST_MANAGER'|'SUPERVISOR'|'STAFF'|null.
 *                      Berlaku sebagai filter alternatif kalau gradeLevel
 *                      tidak diisi atau hasilnya kosong.
 *   activeOnly       — bool (default true). Hanya karyawan aktif.
 *   excludeIds       — number[] (default []). ID yang tidak boleh muncul
 *                      (mis. di edit form, jangan tampilkan diri sendiri).
 *   helpText         — string opsional, render di bawah input.
 *
 * Emits:
 *   update:modelValue
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import api from '@/api/client'
import {
  MagnifyingGlassIcon, XMarkIcon, ChevronUpDownIcon,
  CheckIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  modelValue:    { type: [Number, null], default: null },
  placeholder:   { type: String, default: 'Cari & pilih karyawan…' },
  disabled:      { type: Boolean, default: false },
  minGradeLevel: { type: Number, default: 0 },
  positionTier:  { type: String, default: '' },
  activeOnly:    { type: Boolean, default: true },
  excludeIds:    { type: Array, default: () => [] },
  helpText:      { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const open      = ref(false)
const query     = ref('')
const items     = ref([])
const loading   = ref(false)
const cursor    = ref(0)        // index hover untuk keyboard nav
const selected  = ref(null)     // detail employee yang sedang terpilih
const rootEl    = ref(null)
const inputEl   = ref(null)

// ─── Backend fetch ────────────────────────────────────────────────
let debounceTimer = null

function buildParams(extra = {}) {
  const p = { per_page: 50 }
  if (props.activeOnly) p.status = 'active'
  if (props.minGradeLevel > 0) p.min_grade_level = props.minGradeLevel
  if (props.positionTier) p.position_tier = props.positionTier
  return { ...p, ...extra }
}

async function fetchList(searchText = '') {
  loading.value = true
  try {
    const res = await api.get('/employees', {
      params: buildParams(searchText ? { search: searchText } : {}),
    })
    let rows = res.data?.data || []

    // Fallback: kalau filter gradeLevel ketat tapi 0 hasil, longgarkan
    // (drop minGradeLevel) — biar UX tidak buntu di company yg level
    // job_grade-nya tidak ter-set.
    if (rows.length === 0 && props.minGradeLevel > 0 && searchText === '') {
      const params2 = buildParams()
      delete params2.min_grade_level
      const res2 = await api.get('/employees', { params: params2 })
      rows = res2.data?.data || []
    }

    if (props.excludeIds.length) {
      rows = rows.filter(r => ! props.excludeIds.includes(r.id))
    }

    items.value = rows
    cursor.value = 0
  } catch (_) {
    items.value = []
  } finally {
    loading.value = false
  }
}

watch(query, (v) => {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => fetchList(v.trim()), 250)
})

// Resolve detail employee yang sedang dipakai (untuk display label).
async function loadSelectedDetail(id) {
  if (! id) { selected.value = null; return }
  // Coba ambil dari list dulu (kalau lagi terbuka).
  const inList = items.value.find(e => e.id === id)
  if (inList) { selected.value = inList; return }
  try {
    const res = await api.get(`/employees/${id}`)
    selected.value = res.data?.data || null
  } catch (_) {
    selected.value = null
  }
}

watch(() => props.modelValue, (id) => loadSelectedDetail(id), { immediate: true })

// Reload list kalau filter berubah eksternal.
watch(() => [props.minGradeLevel, props.positionTier, props.activeOnly], () => {
  if (open.value) fetchList(query.value.trim())
})

// ─── Display helpers ─────────────────────────────────────────────
function labelFor(e) {
  if (! e) return ''
  const parts = [e.full_name]
  if (e.position_name) parts.push('— ' + e.position_name)
  return parts.join(' ')
}

function subLabelFor(e) {
  if (! e) return ''
  const tail = []
  if (e.employee_number) tail.push(e.employee_number)
  if (e.job_grade_code) tail.push(e.job_grade_code)
  if (e.department_name) tail.push(e.department_name)
  if (e.branch_name) tail.push(e.branch_name)
  return tail.join(' · ')
}

const displayValue = computed(() => {
  if (! selected.value) return ''
  return labelFor(selected.value)
})

// ─── Open / select ───────────────────────────────────────────────
async function openDropdown() {
  if (props.disabled) return
  open.value = true
  if (items.value.length === 0) await fetchList(query.value.trim())
  await nextTick()
  inputEl.value?.focus()
}

function closeDropdown() {
  open.value = false
  // Reset query agar kalau dibuka lagi langsung tampilkan default list.
  query.value = ''
}

function selectItem(item) {
  selected.value = item
  emit('update:modelValue', item.id)
  closeDropdown()
}

function clearSelection() {
  selected.value = null
  emit('update:modelValue', null)
}

// ─── Keyboard nav ─────────────────────────────────────────────────
function onKeyDown(e) {
  if (! open.value) {
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
      e.preventDefault()
      openDropdown()
    }
    return
  }
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    cursor.value = Math.min(cursor.value + 1, items.value.length - 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    cursor.value = Math.max(cursor.value - 1, 0)
  } else if (e.key === 'Enter') {
    e.preventDefault()
    if (items.value[cursor.value]) selectItem(items.value[cursor.value])
  } else if (e.key === 'Escape') {
    closeDropdown()
  }
}

// ─── Click-outside ────────────────────────────────────────────────
function handleDocClick(e) {
  if (! open.value) return
  if (rootEl.value && ! rootEl.value.contains(e.target)) closeDropdown()
}
onMounted(() => document.addEventListener('mousedown', handleDocClick))
onBeforeUnmount(() => document.removeEventListener('mousedown', handleDocClick))
</script>

<template>
  <div ref="rootEl" class="relative">
    <!-- Trigger button — looks like an input -->
    <button
      type="button"
      class="input flex items-center justify-between text-left w-full pr-9"
      :class="{ 'opacity-60 cursor-not-allowed': disabled }"
      :disabled="disabled"
      @click="open ? closeDropdown() : openDropdown()"
    >
      <span class="flex-1 truncate">
        <span v-if="selected">
          <span class="font-medium">{{ labelFor(selected) }}</span>
          <span v-if="subLabelFor(selected)" class="text-xs text-gray-500 ml-1">
            ({{ subLabelFor(selected) }})
          </span>
        </span>
        <span v-else class="text-gray-400">{{ placeholder }}</span>
      </span>

      <span v-if="selected" class="absolute right-7 top-1/2 -translate-y-1/2">
        <button
          type="button"
          class="text-gray-400 hover:text-red-500"
          title="Clear"
          @click.stop="clearSelection"
        >
          <XMarkIcon class="w-4 h-4" />
        </button>
      </span>
      <ChevronUpDownIcon class="w-4 h-4 text-gray-400 absolute right-2.5 top-1/2 -translate-y-1/2" />
    </button>

    <p v-if="helpText" class="text-xs text-gray-500 mt-1">{{ helpText }}</p>

    <!-- Dropdown panel -->
    <div
      v-if="open"
      class="absolute z-30 mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg overflow-hidden"
    >
      <!-- Search input -->
      <div class="p-2 border-b border-gray-100 dark:border-gray-700">
        <div class="relative">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-2 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            ref="inputEl"
            v-model="query"
            type="text"
            class="input pl-8 py-1.5 text-sm"
            placeholder="Cari nama, NIK, atau email…"
            @keydown="onKeyDown"
          />
        </div>
      </div>

      <!-- Result list -->
      <ul class="max-h-72 overflow-y-auto">
        <li v-if="loading" class="px-3 py-4 text-center text-sm text-gray-400">
          Memuat…
        </li>
        <li v-else-if="items.length === 0" class="px-3 py-4 text-center text-sm text-gray-400">
          Tidak ada karyawan yang cocok
        </li>
        <li
          v-for="(it, idx) in items"
          :key="it.id"
          :class="[
            'px-3 py-2 cursor-pointer flex items-start gap-2',
            idx === cursor ? 'bg-primary-50 dark:bg-primary-900/30' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50',
            it.id === modelValue ? 'font-semibold' : '',
          ]"
          @mouseenter="cursor = idx"
          @mousedown.prevent="selectItem(it)"
        >
          <CheckIcon
            class="w-4 h-4 mt-0.5 flex-shrink-0"
            :class="it.id === modelValue ? 'text-primary-600' : 'text-transparent'"
          />
          <div class="flex-1 min-w-0">
            <div class="text-sm truncate">{{ labelFor(it) }}</div>
            <div class="text-xs text-gray-500 truncate">{{ subLabelFor(it) }}</div>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>