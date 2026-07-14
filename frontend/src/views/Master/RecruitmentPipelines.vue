<script setup>
/**
 * Master Recruitment Pipeline
 *
 * HR atur urutan stages untuk satu atau lebih template pipeline. Pakai
 * native HTML5 drag-and-drop (tidak butuh library). Setiap stage punya
 * stage_type (SCREENING/ONLINE_TEST/INTERVIEW/...) dan optional gate ke
 * kategori test tertentu.
 *
 * Lihat docs/RECRUITMENT_PIPELINE.md.
 */
import { ref, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useI18n } from '@/locales'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, PencilIcon, TrashIcon, DocumentDuplicateIcon,
  Bars3Icon, XMarkIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()
const { t } = useI18n()
const templates = ref([])
const categories = ref([])
const loading = ref(false)

const showEditor = ref(false)
const editingTpl = ref(null) // null untuk create
const form = ref({ code: '', name: '', description: '', is_default: 0, stages: [] })

const stageTypes = computed(() => [
  { value: 'SCREENING',        label: t('recruitment_pipelines.type_screening') },
  { value: 'ONLINE_TEST',      label: t('recruitment_pipelines.type_online_test') },
  { value: 'INTERVIEW',        label: t('recruitment_pipelines.type_interview') },
  { value: 'ASSESSMENT',       label: t('recruitment_pipelines.type_assessment') },
  { value: 'OFFER',            label: t('recruitment_pipelines.type_offer') },
  { value: 'BACKGROUND_CHECK', label: t('recruitment_pipelines.type_background_check') },
  { value: 'MEDICAL_CHECK',    label: t('recruitment_pipelines.type_medical_check') },
  { value: 'CUSTOM',           label: t('recruitment_pipelines.type_custom') },
  { value: 'HIRED',            label: t('recruitment_pipelines.type_hired') },
  { value: 'REJECTED',         label: t('recruitment_pipelines.type_rejected') },
  { value: 'WITHDRAWN',        label: t('recruitment_pipelines.type_withdrawn') },
])

const stageTypeColor = {
  SCREENING:        'bg-blue-100 text-blue-700',
  ONLINE_TEST:      'bg-purple-100 text-purple-700',
  INTERVIEW:        'bg-indigo-100 text-indigo-700',
  ASSESSMENT:       'bg-pink-100 text-pink-700',
  OFFER:            'bg-amber-100 text-amber-700',
  BACKGROUND_CHECK: 'bg-cyan-100 text-cyan-700',
  MEDICAL_CHECK:    'bg-teal-100 text-teal-700',
  CUSTOM:           'bg-gray-100 text-gray-700',
  HIRED:            'bg-green-100 text-green-700',
  REJECTED:         'bg-red-100 text-red-700',
  WITHDRAWN:        'bg-stone-100 text-stone-700',
}

async function fetchTemplates() {
  loading.value = true
  try {
    const [tpls, cats] = await Promise.all([
      api.get('/recruitment-pipelines', { params: { per_page: 100 } }),
      api.get('/online-test-categories/picker'),
    ])
    templates.value = tpls.data.data || []
    categories.value = cats.data?.data?.items || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('recruitment_pipelines.load_failed'))
  } finally {
    loading.value = false
  }
}

onMounted(fetchTemplates)

async function openCreate() {
  // Default: salin struktur template STANDARD (kalau ada) supaya HR
  // tinggal modifikasi
  const std = templates.value.find(t => t.code === 'STANDARD' && t.is_default === 1)
  if (std) {
    const detail = await api.get(`/recruitment-pipelines/${std.id}`)
    form.value = {
      code: '',
      name: '',
      description: detail.data?.data?.description || '',
      is_default: 0,
      stages: (detail.data?.data?.stages || []).map(s => ({ ...s, id: undefined, _key: makeKey() })),
    }
  } else {
    form.value = {
      code: '',
      name: '',
      description: '',
      is_default: 0,
      stages: [
        { _key: makeKey(), code: 'NEW', name: t('recruitment_pipelines.default_stage_new'), stage_type: 'SCREENING', order_index: 0, is_terminal: 0, is_optional: 0 },
        { _key: makeKey(), code: 'HIRED', name: t('recruitment_pipelines.default_stage_hired'), stage_type: 'HIRED', order_index: 1, is_terminal: 1, is_optional: 0 },
      ],
    }
  }
  editingTpl.value = null
  showEditor.value = true
}

async function openEdit(tpl) {
  const res = await api.get(`/recruitment-pipelines/${tpl.id}`)
  const data = res.data?.data
  form.value = {
    id: data.id,
    code: data.code,
    name: data.name,
    description: data.description,
    is_default: data.is_default,
    stages: (data.stages || []).map(s => ({ ...s, _key: makeKey() })),
  }
  editingTpl.value = tpl
  showEditor.value = true
}

async function duplicate(tpl) {
  const newName = window.prompt(t('recruitment_pipelines.duplicate_name_prompt', { name: tpl.name }), tpl.name + t('recruitment_pipelines.copy_suffix'))
  if (! newName) return
  const newCode = window.prompt(t('recruitment_pipelines.duplicate_code_prompt'), tpl.code + '_COPY')
  if (! newCode) return
  try {
    await api.post(`/recruitment-pipelines/${tpl.id}/duplicate`, {
      name: newName,
      code: newCode.toUpperCase(),
    })
    toast.success(t('recruitment_pipelines.duplicated'))
    fetchTemplates()
  } catch (e) {
    toast.error(e.response?.data?.message || t('recruitment_pipelines.duplicate_failed'))
  }
}

async function remove(tpl) {
  const ok = await confirmDialog({
    title: t('recruitment_pipelines.delete_title'),
    message: t('recruitment_pipelines.delete_message', { name: tpl.name }),
    okText: t('recruitment_pipelines.delete'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/recruitment-pipelines/${tpl.id}`)
    toast.success(t('recruitment_pipelines.deleted'))
    fetchTemplates()
  } catch (e) {
    toast.error(e.response?.data?.message || t('recruitment_pipelines.delete_failed'))
  }
}

// ─── Stage editor ────────────────────────────────────────────────

function makeKey() { return Math.random().toString(36).slice(2) }

function addStage() {
  form.value.stages.push({
    _key: makeKey(),
    code: '',
    name: '',
    stage_type: 'CUSTOM',
    order_index: form.value.stages.length,
    required_test_category_id: null,
    is_terminal: 0,
    is_optional: 0,
    sla_hours: null,
  })
}

function removeStage(idx) {
  form.value.stages.splice(idx, 1)
}

// HTML5 drag-and-drop reorder
const dragIdx = ref(null)
function onDragStart(idx) { dragIdx.value = idx }
function onDragOver(e) { e.preventDefault() }
function onDrop(idx) {
  if (dragIdx.value === null || dragIdx.value === idx) return
  const stages = form.value.stages
  const moved = stages.splice(dragIdx.value, 1)[0]
  stages.splice(idx, 0, moved)
  // Re-compute order_index 0..N
  stages.forEach((s, i) => { s.order_index = i })
  dragIdx.value = null
}

function moveUp(idx) {
  if (idx === 0) return
  const stages = form.value.stages
  ;[stages[idx - 1], stages[idx]] = [stages[idx], stages[idx - 1]]
  stages.forEach((s, i) => { s.order_index = i })
}
function moveDown(idx) {
  const stages = form.value.stages
  if (idx === stages.length - 1) return
  ;[stages[idx + 1], stages[idx]] = [stages[idx], stages[idx + 1]]
  stages.forEach((s, i) => { s.order_index = i })
}

// Saat ganti stage_type → suggest is_terminal
function onStageTypeChange(stage) {
  if (['HIRED', 'REJECTED', 'WITHDRAWN'].includes(stage.stage_type)) {
    stage.is_terminal = 1
    if (! stage.code) stage.code = stage.stage_type
    if (! stage.name) {
      stage.name = stage.stage_type === 'HIRED' ? t('recruitment_pipelines.suggested_hired')
        : stage.stage_type === 'REJECTED' ? t('recruitment_pipelines.suggested_rejected')
        : t('recruitment_pipelines.suggested_withdrawn')
    }
  } else {
    stage.is_terminal = 0
  }
}

// Validasi sebelum save
const validation = computed(() => {
  const errs = []
  if (! form.value.code) errs.push(t('recruitment_pipelines.validation_code_required'))
  if (! form.value.name) errs.push(t('recruitment_pipelines.validation_name_required'))
  if (! form.value.stages.length) errs.push(t('recruitment_pipelines.validation_stage_required'))
  const codes = new Set()
  for (const s of form.value.stages) {
    if (! s.code) { errs.push(t('recruitment_pipelines.validation_stage_code_required')); break }
    const code = String(s.code).toUpperCase()
    if (! /^[A-Z0-9_]{1,32}$/.test(code)) {
      errs.push(t('recruitment_pipelines.validation_stage_code_invalid', { code: s.code }))
      break
    }
    if (codes.has(code)) { errs.push(t('recruitment_pipelines.validation_stage_code_duplicate', { code })); break }
    codes.add(code)
    if (! s.name) { errs.push(t('recruitment_pipelines.validation_stage_name_required', { code })); break }
  }
  const hasHired = form.value.stages.some(s => s.stage_type === 'HIRED')
  if (! hasHired) errs.push(t('recruitment_pipelines.validation_hired_required'))
  return errs
})

async function save() {
  if (validation.value.length) {
    toast.error(validation.value[0])
    return
  }
  try {
    const payload = {
      code: form.value.code.toUpperCase(),
      name: form.value.name,
      description: form.value.description,
      is_default: form.value.is_default ? 1 : 0,
      stages: form.value.stages.map(s => ({
        code: String(s.code).toUpperCase(),
        name: s.name,
        stage_type: s.stage_type,
        order_index: s.order_index,
        required_test_category_id: s.required_test_category_id || null,
        description: s.description || null,
        sla_hours: s.sla_hours || null,
        is_terminal: s.is_terminal ? 1 : 0,
        is_optional: s.is_optional ? 1 : 0,
      })),
    }
    if (editingTpl.value) {
      await api.put(`/recruitment-pipelines/${editingTpl.value.id}`, payload)
      toast.success(t('recruitment_pipelines.updated'))
    } else {
      await api.post('/recruitment-pipelines', payload)
      toast.success(t('recruitment_pipelines.created'))
    }
    showEditor.value = false
    fetchTemplates()
  } catch (e) {
    toast.error(e.response?.data?.message || t('recruitment_pipelines.save_failed'))
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('recruitment_pipelines.title') }}</h1>
        <p class="text-sm text-gray-500 mt-1">
          {{ t('recruitment_pipelines.subtitle') }}
        </p>
      </div>
      <button type="button" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> {{ t('recruitment_pipelines.add_pipeline') }}
      </button>
    </div>

    <div v-if="loading" class="card p-10 text-center text-gray-400">{{ t('recruitment_pipelines.loading') }}</div>
    <div v-else-if="templates.length === 0" class="card p-10 text-center text-gray-400">
      {{ t('recruitment_pipelines.empty') }}
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="tpl in templates" :key="tpl.id"
        class="card hover:shadow-md transition cursor-pointer"
        @click="openEdit(tpl)">
        <div class="card-body">
          <div class="flex items-start justify-between gap-2">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-xs font-mono px-1.5 py-0.5 bg-gray-100 rounded">{{ tpl.code }}</span>
                <span v-if="tpl.is_default"
                  class="text-xs px-1.5 py-0.5 bg-primary-100 text-primary-700 rounded">{{ t('recruitment_pipelines.default_badge') }}</span>
                <span v-if="! tpl.is_active" class="text-xs text-red-500">{{ t('recruitment_pipelines.inactive') }}</span>
              </div>
              <div class="font-semibold text-gray-800 mt-2">{{ tpl.name }}</div>
              <div v-if="tpl.description" class="text-xs text-gray-500 mt-1 line-clamp-2">
                {{ tpl.description }}
              </div>
            </div>
            <div class="flex flex-col gap-1" @click.stop>
              <button type="button" class="text-gray-400 hover:text-primary-600" @click="openEdit(tpl)" v-bind="{ title: t('recruitment_pipelines.edit'), 'aria-label': t('recruitment_pipelines.edit') }">
                <PencilIcon class="w-4 h-4" />
              </button>
              <button type="button" class="text-gray-400 hover:text-blue-600" @click="duplicate(tpl)" v-bind="{ title: t('recruitment_pipelines.duplicate'), 'aria-label': t('recruitment_pipelines.duplicate') }">
                <DocumentDuplicateIcon class="w-4 h-4" />
              </button>
              <button type="button" class="text-gray-400 hover:text-red-600" @click="remove(tpl)" v-bind="{ title: t('recruitment_pipelines.delete'), 'aria-label': t('recruitment_pipelines.delete') }">
                <TrashIcon class="w-4 h-4" />
              </button>
            </div>
          </div>
          <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
            <span>{{ t('recruitment_pipelines.stages_count', { count: tpl.stage_count }) }}</span>
            <span>{{ t('recruitment_pipelines.postings_count', { count: tpl.posting_count }) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Editor modal -->
    <div v-if="showEditor"
      class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[92vh] flex flex-col" role="dialog" aria-modal="true" v-bind="{ 'aria-label': editingTpl ? t('recruitment_pipelines.modal_edit') : t('recruitment_pipelines.modal_create') }">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editingTpl ? t('recruitment_pipelines.modal_edit') : t('recruitment_pipelines.modal_create') }}</h2>
          <button type="button" class="text-gray-500" @click="showEditor = false" v-bind="{ title: t('recruitment_pipelines.close'), 'aria-label': t('recruitment_pipelines.close') }">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-4 space-y-5">
          <!-- Header form -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('recruitment_pipelines.code') }} <span class="text-red-500">*</span></label>
              <input v-model="form.code" maxlength="32" class="input"
                v-bind="{ placeholder: t('recruitment_pipelines.template_code_ph') }" />
            </div>
            <div>
              <label class="label">{{ t('recruitment_pipelines.name') }} <span class="text-red-500">*</span></label>
              <input v-model="form.name" maxlength="200" class="input" v-bind="{ placeholder: t('recruitment_pipelines.template_name_ph') }" />
            </div>
            <div class="md:col-span-2">
              <label class="label">{{ t('recruitment_pipelines.description') }}</label>
              <textarea v-model="form.description" rows="2" class="input"
                v-bind="{ placeholder: t('recruitment_pipelines.description_ph') }"></textarea>
            </div>
            <div class="md:col-span-2">
              <label class="inline-flex items-center gap-2">
                <input v-model="form.is_default" type="checkbox" :true-value="1" :false-value="0" class="w-4 h-4" />
                <span class="text-sm">{{ t('recruitment_pipelines.default_help') }}</span>
              </label>
            </div>
          </div>

          <!-- Stages editor -->
          <div>
            <div class="flex items-center justify-between mb-2">
              <h3 class="font-semibold text-gray-800">{{ t('recruitment_pipelines.stages') }}</h3>
              <button type="button" class="btn-secondary text-sm" @click="addStage">
                <PlusIcon class="w-4 h-4 inline mr-1" /> {{ t('recruitment_pipelines.add_stage') }}
              </button>
            </div>
            <p class="text-xs text-gray-500 mb-3">
              {{ t('recruitment_pipelines.stages_help') }}
            </p>

            <div class="space-y-2">
              <div v-for="(stage, idx) in form.stages" :key="stage._key"
                class="border rounded-lg bg-white"
                :class="{ 'border-primary-300 bg-primary-50/30': dragIdx === idx }"
                draggable="true"
                @dragstart="onDragStart(idx)"
                @dragover="onDragOver"
                @drop.prevent="onDrop(idx)">
                <div class="flex items-stretch">
                  <div class="px-2 py-3 flex items-center text-gray-400 cursor-move border-r"
                    v-bind="{ title: t('recruitment_pipelines.drag_reorder'), 'aria-label': t('recruitment_pipelines.drag_reorder') }">
                    <Bars3Icon class="w-5 h-5" />
                  </div>
                  <div class="flex-1 p-3">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-2">
                      <div class="md:col-span-2">
                        <label class="text-xs text-gray-500">{{ t('recruitment_pipelines.code') }}</label>
                        <input v-model="stage.code" maxlength="32" class="input input-sm"
                          v-bind="{ placeholder: t('recruitment_pipelines.stage_code_ph') }" />
                      </div>
                      <div class="md:col-span-3">
                        <label class="text-xs text-gray-500">{{ t('recruitment_pipelines.name') }}</label>
                        <input v-model="stage.name" maxlength="200" class="input input-sm"
                          v-bind="{ placeholder: t('recruitment_pipelines.stage_name_ph') }" />
                      </div>
                      <div class="md:col-span-3">
                        <label class="text-xs text-gray-500">{{ t('recruitment_pipelines.type') }}</label>
                        <select v-model="stage.stage_type" class="input input-sm"
                          @change="onStageTypeChange(stage)">
                          <option v-for="t in stageTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                      </div>
                      <div class="md:col-span-3" v-if="stage.stage_type === 'ONLINE_TEST'">
                        <label class="text-xs text-gray-500">{{ t('recruitment_pipelines.test_gate') }}</label>
                        <select v-model="stage.required_test_category_id" class="input input-sm">
                          <option :value="null">{{ t('recruitment_pipelines.no_gate') }}</option>
                          <option v-for="c in categories" :key="c.id" :value="c.id">
                            {{ c.name }} ({{ c.code }})
                          </option>
                        </select>
                      </div>
                      <div class="md:col-span-3" v-else>
                        <label class="text-xs text-gray-500">{{ t('recruitment_pipelines.sla_hours') }}</label>
                        <input v-model.number="stage.sla_hours" type="number" min="0"
                          class="input input-sm" v-bind="{ placeholder: t('recruitment_pipelines.sla_ph') }" />
                      </div>
                      <div class="md:col-span-1 flex items-end gap-1">
                        <button class="text-gray-400 hover:text-gray-700" type="button"
                          @click="moveUp(idx)" :disabled="idx === 0" v-bind="{ title: t('recruitment_pipelines.move_up'), 'aria-label': t('recruitment_pipelines.move_up') }">↑</button>
                        <button class="text-gray-400 hover:text-gray-700" type="button"
                          @click="moveDown(idx)" :disabled="idx === form.stages.length - 1"
                          v-bind="{ title: t('recruitment_pipelines.move_down'), 'aria-label': t('recruitment_pipelines.move_down') }">↓</button>
                        <button class="text-gray-400 hover:text-red-600 ml-auto" type="button"
                          @click="removeStage(idx)" v-bind="{ title: t('recruitment_pipelines.remove_stage'), 'aria-label': t('recruitment_pipelines.remove_stage') }">
                          <TrashIcon class="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                    <div class="mt-2 flex items-center gap-3 text-xs">
                      <span class="px-1.5 py-0.5 rounded font-medium" :class="stageTypeColor[stage.stage_type] || 'bg-gray-100 text-gray-700'">
                        {{ stage.stage_type }}
                      </span>
                      <label class="inline-flex items-center gap-1">
                        <input v-model="stage.is_terminal" type="checkbox" :true-value="1" :false-value="0"
                          class="w-3.5 h-3.5" />
                        <span>{{ t('recruitment_pipelines.terminal') }}</span>
                      </label>
                      <label class="inline-flex items-center gap-1">
                        <input v-model="stage.is_optional" type="checkbox" :true-value="1" :false-value="0"
                          class="w-3.5 h-3.5" />
                        <span>{{ t('recruitment_pipelines.optional') }}</span>
                      </label>
                      <span class="text-gray-400 ml-auto">{{ t('recruitment_pipelines.order', { index: stage.order_index }) }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="validation.length" class="mt-3 bg-amber-50 border border-amber-200 rounded p-3">
              <p class="text-sm font-medium text-amber-800 mb-1">{{ t('recruitment_pipelines.validation_title') }}</p>
              <ul class="text-xs text-amber-700 list-disc list-inside space-y-1">
                <li v-for="v in validation" :key="v">{{ v }}</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showEditor = false">{{ t('recruitment_pipelines.cancel') }}</button>
          <button type="button" class="btn-primary" :disabled="validation.length > 0" @click="save">{{ t('recruitment_pipelines.save') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.input-sm {
  padding-top: 0.35rem;
  padding-bottom: 0.35rem;
  font-size: 0.875rem;
}
</style>
