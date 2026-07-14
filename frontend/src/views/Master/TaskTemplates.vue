<script setup>
/**
 * Master/TaskTemplates.vue — Editor template checklist Onboarding/Offboarding.
 *
 * Endpoint:
 *   GET    /task-templates?type=ONBOARD|OFFBOARD
 *   POST   /task-templates           { code, name, type, description, is_default, is_active, tasks: [...] }
 *   PUT    /task-templates/:id
 *   DELETE /task-templates/:id
 *
 * Layout: 2 kolom — kiri daftar template, kanan editor task list dengan
 * drag-to-reorder ringan via tombol naik/turun (tanpa lib drag-and-drop).
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useConfirm } from '@/composables/useConfirm'
import {
  ClipboardDocumentListIcon, PlusIcon, TrashIcon, ArrowUpIcon,
  ArrowDownIcon, BookmarkSquareIcon, DocumentDuplicateIcon, CheckBadgeIcon,
} from '@heroicons/vue/24/outline'

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const filterType = ref('') // '' | ONBOARD | OFFBOARD
const templates = ref([])
const loading = ref(false)
const selected = ref(null)
const draft = ref(null)
const saving = ref(false)
const deleting = ref(false)

const ownerRoles = computed(() => [
  { value: 'HR',         label: t('master.task_templates.role_hr') },
  { value: 'IT',         label: t('master.task_templates.role_it') },
  { value: 'FINANCE',    label: t('master.task_templates.role_finance') },
  { value: 'SUPERVISOR', label: t('master.task_templates.role_supervisor') },
  { value: 'MANAGER',    label: t('master.task_templates.role_manager') },
  { value: 'EMPLOYEE',   label: t('master.task_templates.role_employee') },
])

async function fetchList() {
  loading.value = true
  try {
    const params = {}
    if (filterType.value) params.type = filterType.value
    const res = await api.get('/task-templates', { params })
    templates.value = res.data?.data || []
    if (! selected.value && templates.value.length) {
      selectTemplate(templates.value[0])
    } else if (selected.value) {
      const stillThere = templates.value.find(t => t.id === selected.value.id)
      if (stillThere) selectTemplate(stillThere)
      else if (templates.value.length) selectTemplate(templates.value[0])
      else { selected.value = null; draft.value = null }
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.task_templates.load_failed'))
  } finally {
    loading.value = false
  }
}

function selectTemplate(tpl) {
  selected.value = tpl
  // Clone biar edit lokal tanpa polusi cache list
  draft.value = JSON.parse(JSON.stringify({
    id: tpl.id,
    code: tpl.code,
    name: tpl.name,
    type: tpl.type,
    description: tpl.description || '',
    is_default: tpl.is_default ? 1 : 0,
    is_active: tpl.is_active ? 1 : 0,
    tasks: Array.isArray(tpl.tasks) ? tpl.tasks : [],
  }))
}

function newTemplate(type = 'ONBOARD') {
  selected.value = null
  draft.value = {
    id: null,
    code: '',
    name: '',
    type,
    description: '',
    is_default: 0,
    is_active: 1,
    tasks: [],
  }
}

function addTask() {
  if (! draft.value) return
  draft.value.tasks.push({
    key: '',
    name: '',
    description: '',
    owner_role: 'HR',
    due_offset_days: 0,
    mandatory: true,
  })
}

function removeTask(i) {
  draft.value.tasks.splice(i, 1)
}

function moveTask(i, dir) {
  const tasks = draft.value.tasks
  const j = i + dir
  if (j < 0 || j >= tasks.length) return
  const tmp = tasks[i]
  tasks[i] = tasks[j]
  tasks[j] = tmp
}

async function saveTemplate() {
  if (saving.value) return
  if (! draft.value) return
  if (! draft.value.code.trim() || ! draft.value.name.trim()) {
    toast.warning(t('master.task_templates.code_name_required'))
    return
  }
  if (! draft.value.tasks.length) {
    toast.warning(t('master.task_templates.need_task'))
    return
  }
  // Validasi tiap task harus punya nama
  const invalid = draft.value.tasks.findIndex(t => ! (t.name || '').trim())
  if (invalid >= 0) {
    toast.warning(t('master.task_templates.task_no_name', { n: invalid + 1 }))
    return
  }

  saving.value = true
  try {
    const payload = {
      code: draft.value.code.trim().toUpperCase(),
      name: draft.value.name.trim(),
      type: draft.value.type,
      description: draft.value.description?.trim() || null,
      is_default: draft.value.is_default ? 1 : 0,
      is_active: draft.value.is_active ? 1 : 0,
      tasks: draft.value.tasks.map(t => ({
        key: (t.key || '').trim().toUpperCase() || null,
        name: t.name.trim(),
        description: (t.description || '').trim() || null,
        owner_role: t.owner_role || 'HR',
        due_offset_days: Number(t.due_offset_days) || 0,
        mandatory: !! t.mandatory,
      })),
    }
    if (draft.value.id) {
      await api.put(`/task-templates/${draft.value.id}`, payload)
      toast.success(t('master.task_templates.saved_update'))
    } else {
      const res = await api.post('/task-templates', payload)
      toast.success(t('master.task_templates.saved_create'))
      draft.value.id = res.data?.data?.id
      selected.value = { id: draft.value.id }
    }
    await fetchList()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.task_templates.save_failed'))
  } finally {
    saving.value = false
  }
}

async function deleteTemplate() {
  if (deleting.value) return
  if (! draft.value?.id) return
  const ok = await confirm({
    title: t('master.task_templates.delete_title'),
    message: t('master.task_templates.delete_msg', { name: draft.value.name }),
    confirmText: t('master.task_templates.delete_confirm'),
    type: 'danger',
  })
  if (! ok) return
  deleting.value = true
  try {
    await api.delete(`/task-templates/${draft.value.id}`)
    toast.success(t('master.task_templates.deleted'))
    selected.value = null
    draft.value = null
    await fetchList()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.task_templates.delete_failed'))
  } finally {
    deleting.value = false
  }
}

function duplicateTemplate() {
  if (! draft.value) return
  draft.value = {
    ...JSON.parse(JSON.stringify(draft.value)),
    id: null,
    code: draft.value.code + '_COPY',
    name: draft.value.name,
    is_default: 0,
  }
  selected.value = null
  toast.info(t('master.task_templates.duplicate_done'))
}

const taskCount = computed(() => draft.value?.tasks?.length || 0)
const mandatoryCount = computed(() =>
  (draft.value?.tasks || []).filter(t => t.mandatory).length
)
const statsHtml = computed(() => t('master.task_templates.stats', {
  total: `<strong>${taskCount.value}</strong>`,
  mandatory: `<strong class="text-amber-700">${mandatoryCount.value}</strong>`,
}))

onMounted(() => fetchList())
watch(filterType, () => fetchList())

const typeBadge = (type) =>
  type === 'ONBOARD' ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                     : 'bg-orange-50 text-orange-700 border-orange-200'
const typeLabel = (type) => type === 'ONBOARD'
  ? t('master.task_templates.type_onboard_label')
  : t('master.task_templates.type_offboard_label')
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ClipboardDocumentListIcon class="w-6 h-6" />
          {{ t('master.task_templates.title') }}
        </h1>
        <p class="text-sm text-gray-500">
          {{ t('master.task_templates.subtitle_prefix') }}
          <CheckBadgeIcon class="w-4 h-4 inline text-emerald-600" />
          {{ t('master.task_templates.subtitle_suffix') }}
        </p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model="filterType" class="input">
          <option value="">{{ t('master.task_templates.filter_all') }}</option>
          <option value="ONBOARD">{{ t('master.task_templates.type_onboard_label') }}</option>
          <option value="OFFBOARD">{{ t('master.task_templates.type_offboard_label') }}</option>
        </select>
        <button type="button" class="btn-primary" @click="newTemplate('ONBOARD')">
          <PlusIcon class="w-4 h-4" /> {{ t('master.task_templates.btn_add') }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- DAFTAR TEMPLATE -->
      <div class="lg:col-span-1 card overflow-hidden">
        <div class="card-header text-sm text-gray-500">{{ t('master.task_templates.list_count', { n: templates.length }) }}</div>
        <div class="divide-y divide-gray-100 max-h-[70vh] overflow-y-auto">
          <div v-if="loading" class="px-4 py-8 text-center text-sm text-gray-400">{{ t('master.task_templates.loading') }}</div>
          <div v-else-if="!templates.length" class="px-4 py-12 text-center text-sm text-gray-400">
            {{ t('master.task_templates.no_templates') }}
          </div>
          <button v-for="tpl in templates" :key="tpl.id"
            type="button"
            class="w-full text-left px-4 py-3 hover:bg-gray-50 transition"
            :class="{ 'bg-primary-50/40 border-l-4 border-primary-500': draft && draft.id === tpl.id }"
            @click="selectTemplate(tpl)">
            <div class="flex items-center justify-between mb-1">
              <span class="font-medium text-sm">{{ tpl.name }}</span>
              <span class="text-xs px-1.5 py-0.5 rounded border" :class="typeBadge(tpl.type)">{{ typeLabel(tpl.type) }}</span>
            </div>
            <div class="text-xs text-gray-500 flex items-center gap-1.5">
              <code class="text-[11px]">{{ tpl.code }}</code>
              <span class="text-gray-400">·</span>
              <span>{{ t('master.task_templates.tasks_count', { n: (tpl.tasks || []).length }) }}</span>
              <CheckBadgeIcon v-if="tpl.is_default" class="w-3.5 h-3.5 text-emerald-600 ml-auto" v-bind="{ title: t('master.task_templates.default_badge'), 'aria-label': t('master.task_templates.default_badge') }" />
              <span v-if="!tpl.is_active" class="ml-auto text-amber-600">{{ t('master.task_templates.inactive_badge') }}</span>
            </div>
          </button>
        </div>
      </div>

      <!-- EDITOR -->
      <div class="lg:col-span-2 card">
        <div v-if="!draft" class="px-6 py-16 text-center text-sm text-gray-400">
          {{ t('master.task_templates.empty_state') }}
        </div>

        <div v-else>
          <div class="card-header">
            <div class="flex flex-wrap gap-3 items-center justify-between">
              <div>
                <div class="text-xs text-gray-500">{{ draft.id ? t('master.task_templates.template_id', { id: draft.id }) : t('master.task_templates.template_new') }}</div>
                <input v-model="draft.name" class="text-lg font-semibold bg-transparent focus:bg-white focus:ring-1 focus:ring-primary-500 rounded px-1 -ml-1"
                  v-bind="{ placeholder: t('master.task_templates.name_ph') }" />
              </div>
              <div class="flex gap-2">
                <button v-if="draft.id" type="button" class="btn-secondary" @click="duplicateTemplate" v-bind="{ title: t('master.task_templates.tip_duplicate'), 'aria-label': t('master.task_templates.tip_duplicate') }">
                  <DocumentDuplicateIcon class="w-4 h-4" />
                </button>
                <button v-if="draft.id" type="button" class="btn-secondary text-red-600" :disabled="deleting" @click="deleteTemplate" v-bind="{ title: t('master.task_templates.tip_delete'), 'aria-label': t('master.task_templates.tip_delete') }">
                  <TrashIcon class="w-4 h-4" />
                </button>
                <button type="button" class="btn-primary" :disabled="saving || deleting" @click="saveTemplate">
                  <BookmarkSquareIcon class="w-4 h-4" />
                  {{ saving ? t('master.task_templates.btn_saving') : t('master.task_templates.btn_save') }}
                </button>
              </div>
            </div>
          </div>

          <div class="card-body space-y-4">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="label">{{ t('master.task_templates.f_code') }}</label>
                <input v-model="draft.code" class="input font-mono uppercase" v-bind="{ placeholder: t('master.task_templates.f_code_ph') }" />
              </div>
              <div>
                <label class="label">{{ t('master.task_templates.f_type') }}</label>
                <select v-model="draft.type" class="input">
                  <option value="ONBOARD">{{ t('master.task_templates.type_onboard_full') }}</option>
                  <option value="OFFBOARD">{{ t('master.task_templates.type_offboard_full') }}</option>
                </select>
              </div>
              <div class="col-span-2">
                <label class="label">{{ t('master.task_templates.f_description') }}</label>
                <textarea v-model="draft.description" rows="2" class="input"
                  v-bind="{ placeholder: t('master.task_templates.f_description_ph') }"></textarea>
              </div>
              <div class="col-span-2 flex items-center gap-4">
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="draft.is_default" :true-value="1" :false-value="0" />
                  {{ t('master.task_templates.f_is_default') }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="draft.is_active" :true-value="1" :false-value="0" />
                  {{ t('master.task_templates.f_is_active') }}
                </label>
                <span class="ml-auto text-xs text-gray-500" v-html="statsHtml"></span>
              </div>
            </div>

            <div class="border-t border-gray-200 pt-4">
              <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-semibold text-gray-700">{{ t('master.task_templates.tasks_section') }}</h3>
                <button type="button" class="btn-secondary text-xs" @click="addTask">
                  <PlusIcon class="w-3.5 h-3.5" /> {{ t('master.task_templates.btn_add_task') }}
                </button>
              </div>

              <div v-if="!draft.tasks.length" class="text-center text-sm text-gray-400 py-6 border border-dashed rounded">
                {{ t('master.task_templates.no_tasks') }}
              </div>

              <div v-else class="space-y-2">
                <div v-for="(task, i) in draft.tasks" :key="i"
                  class="border border-gray-200 rounded-md p-3 bg-white hover:border-primary-300 transition">
                  <div class="flex items-start gap-2">
                    <div class="flex flex-col gap-0.5 mt-1">
                      <button type="button" class="text-gray-400 hover:text-gray-700 disabled:opacity-30"
                        :disabled="i === 0" @click="moveTask(i, -1)" v-bind="{ title: t('master.task_templates.tip_move_up'), 'aria-label': t('master.task_templates.tip_move_up') }">
                        <ArrowUpIcon class="w-4 h-4" />
                      </button>
                      <button type="button" class="text-gray-400 hover:text-gray-700 disabled:opacity-30"
                        :disabled="i === draft.tasks.length - 1" @click="moveTask(i, 1)" v-bind="{ title: t('master.task_templates.tip_move_down'), 'aria-label': t('master.task_templates.tip_move_down') }">
                        <ArrowDownIcon class="w-4 h-4" />
                      </button>
                    </div>
                    <div class="flex-1 grid grid-cols-12 gap-2">
                      <input v-model="task.name" class="input col-span-7" v-bind="{ placeholder: t('master.task_templates.task_name_ph', { n: i + 1 }) }" />
                      <input v-model="task.key" class="input col-span-3 font-mono text-xs uppercase" v-bind="{ placeholder: t('master.task_templates.task_key_ph') }" />
                      <select v-model="task.owner_role" class="input col-span-2 text-xs">
                        <option v-for="r in ownerRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
                      </select>
                      <input v-model="task.description" class="input col-span-12 text-xs" v-bind="{ placeholder: t('master.task_templates.task_desc_ph') }" />
                      <div class="col-span-12 flex items-center gap-3 text-xs text-gray-600">
                        <label class="flex items-center gap-1.5">
                          {{ t('master.task_templates.due_label') }}
                          <input v-model.number="task.due_offset_days" type="number" class="input w-16 text-xs py-1" />
                          {{ t('master.task_templates.due_anchor', { label: draft.type === 'ONBOARD' ? t('master.task_templates.due_anchor_onboard') : t('master.task_templates.due_anchor_offboard') }) }}
                        </label>
                        <label class="flex items-center gap-1.5">
                          <input type="checkbox" v-model="task.mandatory" />
                          {{ t('master.task_templates.task_mandatory') }}
                        </label>
                        <button type="button" class="ml-auto text-red-500 hover:text-red-700" @click="removeTask(i)" v-bind="{ title: t('master.task_templates.tip_remove_task'), 'aria-label': t('master.task_templates.tip_remove_task') }">
                          <TrashIcon class="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
