<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, PencilIcon, TrashIcon, XMarkIcon,
  CalendarDaysIcon,
} from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const canManage = computed(() => auth.can('shift_pattern.manage'))

const items = ref([])
const loading = ref(false)
const shifts = ref([])

const showEditor = ref(false)
const editing = ref(false)
const form = ref(emptyForm())
const saving = ref(false)
const deletingId = ref(null)
const editorLabel = computed(() => editing.value
  ? t('master.shift_patterns.modal_edit')
  : t('master.shift_patterns.modal_create'))

function emptyForm() {
  return {
    id: null,
    code: '',
    name: '',
    description: '',
    cycle_days: 7,
    is_active: 1,
    pattern_data: defaultPattern(7),
  }
}

function defaultPattern(n) {
  const out = []
  for (let i = 0; i < n; i++) out.push({ shift_id: null, is_off: true, is_holiday: false, label: '' })
  return out
}

const shiftOptionsMap = computed(() => {
  const m = new Map()
  shifts.value.forEach(s => m.set(s.id, s))
  return m
})

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/shift-patterns', { params: { is_active: '' } })
    items.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.shift_patterns.load_failed'))
  } finally {
    loading.value = false
  }
}

async function loadShifts() {
  try {
    const res = await api.get('/shifts', { params: { per_page: 200 } })
    shifts.value = res.data.data || []
  } catch (_) { /* silent */ }
}

onMounted(() => {
  fetchData()
  loadShifts()
})

function openCreate() {
  form.value = emptyForm()
  editing.value = false
  showEditor.value = true
}

async function openEdit(item) {
  try {
    const res = await api.get(`/shift-patterns/${item.id}`)
    const data = res.data.data
    form.value = {
      ...emptyForm(),
      ...data,
      pattern_data: Array.isArray(data.pattern_data) && data.pattern_data.length
        ? data.pattern_data
        : defaultPattern(data.cycle_days || 7),
    }
    editing.value = true
    showEditor.value = true
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.shift_patterns.load_one_failed'))
  }
}

watch(() => form.value.cycle_days, (n) => {
  const target = Math.max(1, Math.min(366, parseInt(n, 10) || 1))
  const cur = form.value.pattern_data || []
  if (target > cur.length) {
    for (let i = cur.length; i < target; i++) {
      cur.push({ shift_id: null, is_off: true, is_holiday: false, label: '' })
    }
  } else if (target < cur.length) {
    cur.length = target
  }
  form.value.cycle_days = target
})

async function save() {
  if (saving.value) return
  if (! form.value.code || ! form.value.name) {
    toast.warning(t('master.shift_patterns.code_name_required'))
    return
  }
  saving.value = true
  try {
    const payload = { ...form.value }
    if (editing.value) {
      await api.put(`/shift-patterns/${payload.id}`, payload)
      toast.success(t('master.shift_patterns.saved_update'))
    } else {
      await api.post('/shift-patterns', payload)
      toast.success(t('master.shift_patterns.saved_create'))
    }
    showEditor.value = false
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.shift_patterns.save_failed'))
  } finally {
    saving.value = false
  }
}

async function remove(item) {
  if (deletingId.value !== null) return
  const ok = await confirmDialog({
    title: t('master.shift_patterns.delete_title'),
    message: t('master.shift_patterns.delete_msg', { name: item.name }),
    okText: t('master.shift_patterns.delete_ok'),
    variant: 'danger',
  })
  if (! ok) return
  deletingId.value = item.id
  try {
    await api.delete(`/shift-patterns/${item.id}`)
    toast.success(t('master.shift_patterns.deleted'))
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.shift_patterns.delete_failed'))
  } finally {
    deletingId.value = null
  }
}

function quickFill(type) {
  const cycle = form.value.cycle_days
  const office = shifts.value.find(s => /office|kantor/i.test(s.code) || s.is_default)
  if (! office) {
    toast.info(t('master.shift_patterns.no_default_shift'))
    return
  }
  const out = []
  if (type === '5-2') {
    for (let i = 0; i < cycle; i++) {
      const d = i % 7
      out.push(d <= 4
        ? { shift_id: office.id, is_off: false, is_holiday: false, label: '' }
        : { shift_id: null, is_off: true, is_holiday: false, label: '' })
    }
  } else if (type === '4-2') {
    for (let i = 0; i < cycle; i++) {
      const d = i % 6
      out.push(d <= 3
        ? { shift_id: office.id, is_off: false, is_holiday: false, label: '' }
        : { shift_id: null, is_off: true, is_holiday: false, label: '' })
    }
  } else if (type === 'all-on') {
    for (let i = 0; i < cycle; i++) out.push({ shift_id: office.id, is_off: false, is_holiday: false, label: '' })
  } else if (type === 'all-off') {
    for (let i = 0; i < cycle; i++) out.push({ shift_id: null, is_off: true, is_holiday: false, label: '' })
  }
  form.value.pattern_data = out
}

function shiftColor(slot) {
  if (slot.is_off) return '#e5e7eb'
  if (! slot.shift_id) return '#f3f4f6'
  return shiftOptionsMap.value.get(Number(slot.shift_id))?.color || '#cbd5e1'
}

function shiftLabel(slot) {
  if (slot.is_holiday) return t('master.shift_patterns.lbl_holiday')
  if (slot.is_off) return t('master.shift_patterns.lbl_off')
  const s = shiftOptionsMap.value.get(Number(slot.shift_id))
  return s ? s.code : '—'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <CalendarDaysIcon class="w-6 h-6" /> {{ t('master.shift_patterns.title') }}
      </h1>
      <button v-if="canManage" type="button" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> {{ t('master.shift_patterns.btn_create') }}
      </button>
    </div>

    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('master.shift_patterns.col_code') }}</th>
              <th>{{ t('master.shift_patterns.col_name') }}</th>
              <th>{{ t('master.shift_patterns.col_cycle') }}</th>
              <th>{{ t('master.shift_patterns.col_visualization') }}</th>
              <th class="text-center">{{ t('master.shift_patterns.col_assignment') }}</th>
              <th>{{ t('master.shift_patterns.col_status') }}</th>
              <th class="text-right w-32">{{ t('master.shift_patterns.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="7" class="text-center py-8 text-gray-400">{{ t('master.shift_patterns.loading') }}</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">{{ t('master.shift_patterns.no_patterns') }}</td></tr>
            <tr v-for="item in items" :key="item.id">
              <td class="font-mono text-sm">{{ item.code }}</td>
              <td class="font-medium">{{ item.name }}</td>
              <td class="text-center">{{ item.cycle_days }}</td>
              <td>
                <div class="flex flex-wrap gap-1 max-w-md">
                  <span
                    v-for="(slot, i) in (item.pattern_data || []).slice(0, 28)"
                    :key="i"
                    class="inline-block w-4 h-5 rounded-sm border border-white shadow-sm"
                    :style="{ background: shiftColor(slot) }"
                    v-bind="{ title: shiftLabel(slot) }"
                  ></span>
                  <span v-if="(item.pattern_data || []).length > 28" class="text-xs text-gray-400 ml-1">
                    +{{ item.pattern_data.length - 28 }}
                  </span>
                </div>
              </td>
              <td class="text-center">{{ item.assignment_count || 0 }}</td>
              <td>
                <span :class="['px-2 py-0.5 rounded-full text-xs',
                  Number(item.is_active) === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'
                ]">
                  {{ Number(item.is_active) === 1 ? t('master.shift_patterns.active') : t('master.shift_patterns.inactive') }}
                </span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button v-if="canManage" type="button" class="text-gray-500 hover:text-primary-600" @click="openEdit(item)" v-bind="{ title: t('common.edit'), 'aria-label': t('common.edit') }"><PencilIcon class="w-4 h-4" /></button>
                  <button v-if="canManage" type="button" class="text-gray-500 hover:text-red-600 disabled:opacity-50" @click="remove(item)" :disabled="deletingId !== null" v-bind="{ title: t('common.delete'), 'aria-label': t('common.delete') }"><TrashIcon class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="showEditor" class="fixed inset-0 z-40 flex items-stretch bg-black/40 p-4" role="dialog" aria-modal="true" v-bind="{ 'aria-label': editorLabel }">
      <div class="bg-white w-full m-auto rounded-lg shadow-xl flex flex-col max-h-[95vh]" style="max-width: 1100px">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editing ? t('master.shift_patterns.modal_edit') : t('master.shift_patterns.modal_create') }}</h2>
          <button type="button" class="text-gray-500" @click="showEditor = false" v-bind="{ title: t('master.shift_patterns.btn_close'), 'aria-label': t('master.shift_patterns.btn_close') }"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-6 gap-3 px-6 py-3 border-b border-gray-100 bg-gray-50">
          <div class="md:col-span-1">
            <label class="label">{{ t('master.shift_patterns.f_code') }}</label>
            <input v-model="form.code" class="input" :disabled="editing" v-bind="{ placeholder: t('master.shift_patterns.f_code_ph') }" required />
          </div>
          <div class="md:col-span-2">
            <label class="label">{{ t('master.shift_patterns.f_name') }}</label>
            <input v-model="form.name" class="input" v-bind="{ placeholder: t('master.shift_patterns.f_name_ph') }" required />
          </div>
          <div class="md:col-span-1">
            <label class="label">{{ t('master.shift_patterns.f_cycle_days') }}</label>
            <input v-model.number="form.cycle_days" type="number" min="1" max="366" class="input" />
          </div>
          <div class="md:col-span-2 flex items-end gap-3 flex-wrap">
            <button type="button" class="btn-secondary text-xs" @click="quickFill('5-2')">{{ t('master.shift_patterns.qf_5_2') }}</button>
            <button type="button" class="btn-secondary text-xs" @click="quickFill('4-2')">{{ t('master.shift_patterns.qf_4_2') }}</button>
            <button type="button" class="btn-secondary text-xs" @click="quickFill('all-on')">{{ t('master.shift_patterns.qf_all_on') }}</button>
            <button type="button" class="btn-secondary text-xs" @click="quickFill('all-off')">{{ t('master.shift_patterns.qf_all_off') }}</button>
          </div>
          <div class="md:col-span-6">
            <label class="label">{{ t('master.shift_patterns.f_description') }}</label>
            <input v-model="form.description" class="input" v-bind="{ placeholder: t('master.shift_patterns.f_description_ph') }" />
          </div>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
          <div class="text-sm text-gray-500 mb-3">
            {{ t('master.shift_patterns.pattern_intro', { anchor: 'anchor_date' }) }}
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-3">
            <div v-for="(slot, i) in form.pattern_data" :key="i"
              class="border border-gray-200 rounded-md p-3 bg-white"
              :style="{ borderLeft: '4px solid ' + shiftColor(slot) }"
            >
              <div class="text-xs font-medium text-gray-500 mb-2">{{ t('master.shift_patterns.day_label', { n: i + 1 }) }}</div>
              <SearchableSelect
                v-model="slot.shift_id"
                :items="shifts"
                value-key="id"
                label-key="name"
                :search-keys="['name','code']"
                v-bind="{ placeholder: t('master.shift_patterns.f_pick_shift') }"
                :disabled="slot.is_off || slot.is_holiday"
                size="sm"
                class="mb-2"
              >
                <template #item="{ item }">
                  <span class="block text-sm truncate">
                    {{ item.name }}
                    <span class="text-xs text-gray-500">
                      ({{ String(item.start_time).slice(0,5) }}–{{ String(item.end_time).slice(0,5) }})
                    </span>
                  </span>
                </template>
              </SearchableSelect>
              <div class="flex flex-col gap-1 text-xs">
                <label class="flex items-center gap-1">
                  <input v-model="slot.is_off" type="checkbox"
                    @change="slot.is_off ? (slot.shift_id = null) : null" />
                  {{ t('master.shift_patterns.f_off') }}
                </label>
                <label class="flex items-center gap-1">
                  <input v-model="slot.is_holiday" type="checkbox" />
                  {{ t('master.shift_patterns.f_holiday') }}
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2 bg-white">
          <button type="button" class="btn-secondary" @click="showEditor = false">{{ t('master.shift_patterns.btn_close') }}</button>
          <button type="button" class="btn-primary" @click="save" :disabled="saving">
            {{ saving ? t('common.saving') : (editing ? t('master.shift_patterns.btn_save_changes') : t('master.shift_patterns.btn_save')) }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
