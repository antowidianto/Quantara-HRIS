<script setup>
import { ref, reactive, onMounted, computed, watch } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, PencilIcon, TrashIcon, EyeIcon, XMarkIcon,
  DocumentTextIcon, BoltIcon,
} from '@heroicons/vue/24/outline'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

/**
 * Halaman editor Template Kontrak.
 *
 * Setiap template terdiri dari header, body, dan footer (HTML). Body bisa
 * memakai placeholder seperti {{employee.full_name}}, {{salary.basic|currency}},
 * {{regulations}} dst. Side panel menampilkan daftar variabel yang tersedia
 * dari endpoint /contract-templates/variables.
 */

const { t } = useI18n()
const toast = useToast()

const types = computed(() => [
  { value: 'PKWT', label: t('master.contract_templates.type_pkwt') },
  { value: 'PKWTT', label: t('master.contract_templates.type_pkwtt') },
  { value: 'PROBATION', label: t('master.contract_templates.type_probation') },
  { value: 'PKHL', label: t('master.contract_templates.type_pkhl') },
  { value: 'MAGANG', label: t('master.contract_templates.type_magang') },
  { value: 'OUTSOURCING', label: t('master.contract_templates.type_outsourcing') },
  { value: 'GENERIC', label: t('master.contract_templates.type_generic') },
])

const items = ref([])
const loading = ref(false)
const filterType = ref('')

const showEditor = ref(false)
const editing = ref(false)
const form = ref(emptyForm())
const activeTab = ref('body') // body | header | footer | preview

const variables = ref({ variables: [], filters: [] })
const employees = ref([])
const previewLoading = ref(false)
const previewHtml = ref('')
const previewEmpId = ref('')
const generateMode = ref(false)
const generateSubject = ref('')

function emptyForm() {
  return {
    id: null,
    code: '',
    name: '',
    contract_type: 'PKWT',
    language: 'id',
    description: '',
    header_html: '',
    body_html: '',
    footer_html: '',
    include_regulations: 1,
    is_default: 0,
    is_active: 1,
  }
}

const filteredVariables = ref('')
const visibleVariables = computed(() => {
  const q = filteredVariables.value.trim().toLowerCase()
  if (! q) return variables.value.variables || []
  return (variables.value.variables || []).filter(v =>
    v.key.toLowerCase().includes(q) || (v.description || '').toLowerCase().includes(q)
  )
})

async function fetchData() {
  loading.value = true
  try {
    const params = {}
    if (filterType.value) params.contract_type = filterType.value
    const res = await api.get('/contract-templates', { params })
    items.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.contract_templates.load_failed'))
  } finally {
    loading.value = false
  }
}

async function loadVariables() {
  try {
    const res = await api.get('/contract-templates/variables')
    variables.value = res.data.data || { variables: [], filters: [] }
  } catch (_) { /* silent */ }
}

async function loadEmployeesForPreview() {
  try {
    const res = await api.get('/employees', { params: { per_page: 200, status: 'ACTIVE' } })
    employees.value = res.data.data || []
  } catch (_) { /* silent */ }
}

onMounted(() => {
  fetchData()
  loadVariables()
})

watch(filterType, () => fetchData())

function openCreate() {
  form.value = emptyForm()
  editing.value = false
  activeTab.value = 'body'
  showEditor.value = true
  if (employees.value.length === 0) loadEmployeesForPreview()
}

async function openEdit(item) {
  // Listing hanya kirim metadata; ambil full body via show.
  try {
    const res = await api.get(`/contract-templates/${item.id}`)
    form.value = { ...emptyForm(), ...res.data.data }
    editing.value = true
    activeTab.value = 'body'
    showEditor.value = true
    if (employees.value.length === 0) loadEmployeesForPreview()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.contract_templates.load_one_failed'))
  }
}

async function save() {
  try {
    const payload = { ...form.value }
    if (editing.value) {
      await api.put(`/contract-templates/${payload.id}`, payload)
      toast.success(t('master.contract_templates.saved_update'))
    } else {
      const res = await api.post('/contract-templates', payload)
      form.value.id = res.data.data?.id
      editing.value = true
      toast.success(t('master.contract_templates.saved_create'))
    }
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.contract_templates.save_failed'))
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: t('master.contract_templates.delete_title'),
    message: t('master.contract_templates.delete_msg', { name: item.name }),
    okText: t('master.contract_templates.delete_ok'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/contract-templates/${item.id}`)
    toast.success(t('master.contract_templates.deleted'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.contract_templates.delete_failed'))
  }
}

async function doPreview() {
  if (! previewEmpId.value) {
    toast.warning(t('master.contract_templates.pick_employee_preview'))
    return
  }
  if (! form.value.id) {
    toast.warning(t('master.contract_templates.save_first_preview'))
    return
  }
  previewLoading.value = true
  try {
    const res = await api.post(`/contract-templates/${form.value.id}/preview`, {
      employee_id: Number(previewEmpId.value),
    })
    previewHtml.value = res.data.data.html
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.contract_templates.preview_failed'))
  } finally {
    previewLoading.value = false
  }
}

async function doGenerate() {
  if (! previewEmpId.value) {
    toast.warning(t('master.contract_templates.pick_employee_first'))
    return
  }
  if (! form.value.id) {
    toast.warning(t('master.contract_templates.save_first_generate'))
    return
  }
  try {
    const res = await api.post(`/contract-templates/${form.value.id}/generate`, {
      employee_id: Number(previewEmpId.value),
      subject: generateSubject.value || form.value.name,
    })
    toast.success(t('master.contract_templates.generated', { number: res.data.data.contract_number }))
    generateMode.value = false
  } catch (e) {
    toast.error(e.response?.data?.message || t('master.contract_templates.generate_failed'))
  }
}

// Helper supaya kita tidak menulis literal `{{ ... }}` di template Vue
// (yang akan diparse sebagai interpolation oleh compiler).
function wrapVar(key) {
  return '{{' + key + '}}'
}

function insertVariable(key) {
  const wrapped = wrapVar(key)
  // Append ke tab aktif
  const target = activeTab.value === 'header' ? 'header_html'
    : activeTab.value === 'footer' ? 'footer_html'
    : 'body_html'
  form.value[target] = (form.value[target] || '') + wrapped
}

function typeLabel(v) {
  return types.value.find(tt => tt.value === v)?.label || v
}

// Disimpan sebagai string biasa supaya Vue compiler tidak salah parse
// double-curly-braces nested pada placeholder petunjuk.
const placeholderHints = {
  fullName: '{{employee.full_name}}',
  salary:   '{{salary.basic|currency}}',
  body:     '{{employee.full_name}}',
  regs:     '{{regulations}}',
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <DocumentTextIcon class="w-6 h-6" /> {{ t('master.contract_templates.title') }}
      </h1>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> {{ t('master.contract_templates.add') }}
      </button>
    </div>

    <div class="card">
      <div class="card-header">
        <select v-model="filterType" class="input w-full md:w-72">
          <option value="">{{ t('master.contract_templates.all_types') }}</option>
          <option v-for="ty in types" :key="ty.value" :value="ty.value">{{ ty.label }}</option>
        </select>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('master.contract_templates.col_code') }}</th>
              <th>{{ t('master.contract_templates.col_name') }}</th>
              <th>{{ t('master.contract_templates.col_type') }}</th>
              <th>{{ t('master.contract_templates.col_language') }}</th>
              <th>{{ t('master.contract_templates.col_default') }}</th>
              <th>{{ t('master.contract_templates.col_status') }}</th>
              <th class="text-right w-32">{{ t('master.contract_templates.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="7" class="text-center py-8 text-gray-400">{{ t('master.contract_templates.loading') }}</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">{{ t('master.contract_templates.no_templates') }}</td></tr>
            <tr v-for="item in items" :key="item.id">
              <td class="font-mono text-sm">{{ item.code }}</td>
              <td class="font-medium">{{ item.name }}</td>
              <td>{{ typeLabel(item.contract_type) }}</td>
              <td class="text-sm text-gray-500">{{ item.language === 'id' ? t('master.contract_templates.lang_id') : t('master.contract_templates.lang_en') }}</td>
              <td>
                <span v-if="item.is_default" class="px-2 py-0.5 rounded-full text-xs bg-amber-50 text-amber-700">
                  {{ t('master.contract_templates.default_badge') }}
                </span>
              </td>
              <td>
                <span :class="[
                  'px-2 py-0.5 rounded-full text-xs',
                  item.is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'
                ]">
                  {{ item.is_active ? t('master.contract_templates.active') : t('master.contract_templates.inactive') }}
                </span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button class="text-gray-500 hover:text-primary-600" @click="openEdit(item)" :title="t('master.contract_templates.edit')"><PencilIcon class="w-4 h-4" /></button>
                  <button class="text-gray-500 hover:text-red-600" @click="remove(item)" :title="t('master.contract_templates.delete')"><TrashIcon class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Editor Modal (full screen) -->
    <div v-if="showEditor" class="fixed inset-0 z-40 flex items-stretch bg-black/40">
      <div class="bg-white w-full m-4 rounded-lg shadow-xl flex flex-col overflow-hidden">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <div>
            <h2 class="font-semibold">{{ editing ? t('master.contract_templates.modal_edit') : t('master.contract_templates.modal_add') }}</h2>
            <div class="text-xs text-gray-500">
              {{ t('master.contract_templates.placeholder_hint', { fullName: placeholderHints.fullName, salary: placeholderHints.salary }) }}
            </div>
          </div>
          <button class="text-gray-500" @click="showEditor = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <!-- Metadata bar -->
        <div class="grid grid-cols-1 md:grid-cols-6 gap-3 px-6 py-3 border-b border-gray-100 bg-gray-50">
          <div class="md:col-span-1">
            <label class="label">{{ t('master.contract_templates.f_code') }}</label>
            <input v-model="form.code" class="input" required :disabled="editing" />
          </div>
          <div class="md:col-span-2">
            <label class="label">{{ t('master.contract_templates.f_name') }}</label>
            <input v-model="form.name" class="input" required />
          </div>
          <div class="md:col-span-1">
            <label class="label">{{ t('master.contract_templates.f_type') }}</label>
            <select v-model="form.contract_type" class="input" required>
              <option v-for="ty in types" :key="ty.value" :value="ty.value">{{ ty.value }}</option>
            </select>
          </div>
          <div class="md:col-span-1">
            <label class="label">{{ t('master.contract_templates.f_language') }}</label>
            <select v-model="form.language" class="input">
              <option value="id">{{ t('master.contract_templates.lang_id') }}</option>
              <option value="en">{{ t('master.contract_templates.lang_en') }}</option>
            </select>
          </div>
          <div class="md:col-span-1 flex items-end gap-3">
            <label class="flex items-center gap-1 text-sm">
              <input type="checkbox" v-model="form.is_active" :true-value="1" :false-value="0" class="w-4 h-4" /> {{ t('master.contract_templates.f_active') }}
            </label>
            <label class="flex items-center gap-1 text-sm">
              <input type="checkbox" v-model="form.is_default" :true-value="1" :false-value="0" class="w-4 h-4" /> {{ t('master.contract_templates.f_default') }}
            </label>
          </div>
        </div>

        <!-- Tabs -->
        <div class="flex border-b border-gray-200 px-6 bg-white">
          <button :class="['px-4 py-2 text-sm border-b-2', activeTab === 'body' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500']" @click="activeTab = 'body'">{{ t('master.contract_templates.tab_body') }}</button>
          <button :class="['px-4 py-2 text-sm border-b-2', activeTab === 'header' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500']" @click="activeTab = 'header'">{{ t('master.contract_templates.tab_header') }}</button>
          <button :class="['px-4 py-2 text-sm border-b-2', activeTab === 'footer' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500']" @click="activeTab = 'footer'">{{ t('master.contract_templates.tab_footer') }}</button>
          <button :class="['px-4 py-2 text-sm border-b-2', activeTab === 'settings' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500']" @click="activeTab = 'settings'">{{ t('master.contract_templates.tab_settings') }}</button>
          <button :class="['px-4 py-2 text-sm border-b-2', activeTab === 'preview' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500']" @click="activeTab = 'preview'">{{ t('master.contract_templates.tab_preview') }}</button>
        </div>

        <!-- Body content area -->
        <div class="flex-1 grid grid-cols-1 lg:grid-cols-4 overflow-hidden">
          <div class="lg:col-span-3 p-6 overflow-y-auto">
            <div v-if="activeTab === 'body'">
              <textarea v-model="form.body_html" class="input font-mono text-sm w-full h-[55vh]"
                :placeholder="t('master.contract_templates.ph_body', { var: placeholderHints.body })"></textarea>
            </div>
            <div v-else-if="activeTab === 'header'">
              <textarea v-model="form.header_html" class="input font-mono text-sm w-full h-[55vh]"
                :placeholder="t('master.contract_templates.ph_header')"></textarea>
            </div>
            <div v-else-if="activeTab === 'footer'">
              <textarea v-model="form.footer_html" class="input font-mono text-sm w-full h-[55vh]"
                :placeholder="t('master.contract_templates.ph_footer')"></textarea>
            </div>
            <div v-else-if="activeTab === 'settings'" class="space-y-4 max-w-2xl">
              <div>
                <label class="label">{{ t('master.contract_templates.f_description') }}</label>
                <textarea v-model="form.description" class="input" rows="3"></textarea>
              </div>
              <div>
                <label class="flex items-center gap-2">
                  <input type="checkbox" v-model="form.include_regulations" :true-value="1" :false-value="0" class="w-4 h-4" />
                  <span>{{ t('master.contract_templates.include_regs', { tag: placeholderHints.regs }) }}</span>
                </label>
              </div>
            </div>
            <div v-else-if="activeTab === 'preview'" class="space-y-4">
              <div class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[260px]">
                  <label class="label">{{ t('master.contract_templates.preview_employee') }}</label>
                  <EmployeeSearchSelect
                    v-model="previewEmpId"
                    :placeholder="t('master.contract_templates.preview_pick_ph')"
                    :only-active="true"
                  />
                </div>
                <button class="btn-secondary" @click="doPreview" :disabled="previewLoading">
                  <EyeIcon class="w-4 h-4" /> {{ previewLoading ? t('master.contract_templates.preview_loading') : t('master.contract_templates.preview_btn') }}
                </button>
                <button class="btn-primary" @click="generateMode = ! generateMode">
                  <BoltIcon class="w-4 h-4" /> {{ t('master.contract_templates.generate_btn') }}
                </button>
              </div>

              <div v-if="generateMode" class="card-body bg-amber-50 border border-amber-200 rounded-md">
                <div class="text-sm font-medium text-amber-900 mb-2">{{ t('master.contract_templates.generate_panel_title') }}</div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                  <input v-model="generateSubject" class="input md:col-span-2" :placeholder="t('master.contract_templates.generate_subject_ph')" />
                  <button class="btn-primary" @click="doGenerate">{{ t('master.contract_templates.generate_create') }}</button>
                </div>
                <div class="text-xs text-amber-800 mt-2">
                  {{ t('master.contract_templates.generate_panel_note') }}
                </div>
              </div>

              <div class="border border-gray-200 rounded-md bg-white p-6 max-h-[55vh] overflow-y-auto">
                <div v-if="!previewHtml" class="text-gray-400 text-sm text-center py-12">
                  {{ t('master.contract_templates.preview_empty') }}
                </div>
                <div v-else class="prose max-w-none" v-html="previewHtml"></div>
              </div>
            </div>
          </div>

          <!-- Sidebar variabel -->
          <aside class="border-l border-gray-200 bg-gray-50 p-4 overflow-y-auto" v-if="activeTab !== 'settings' && activeTab !== 'preview'">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">{{ t('master.contract_templates.sidebar_variables') }}</div>
            <input v-model="filteredVariables" :placeholder="t('master.contract_templates.sidebar_search_var')" class="input mb-3 text-sm" />
            <div class="space-y-1 mb-4">
              <button v-for="v in visibleVariables" :key="v.key"
                class="w-full text-left px-2 py-1 text-xs rounded hover:bg-primary-50 hover:text-primary-700 group"
                @click="insertVariable(v.key)">
                <code class="font-mono">{{ wrapVar(v.key) }}</code>
                <div class="text-[11px] text-gray-500 group-hover:text-primary-600">{{ v.description }}</div>
              </button>
              <div v-if="visibleVariables.length === 0" class="text-xs text-gray-400 px-2">{{ t('master.contract_templates.no_var_match') }}</div>
            </div>

            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">{{ t('master.contract_templates.sidebar_filters') }}</div>
            <div class="space-y-1">
              <div v-for="f in (variables.filters || [])" :key="f.key"
                class="px-2 py-1 text-xs">
                <code class="font-mono">| {{ f.key }}</code>
                <div class="text-[11px] text-gray-500">{{ f.description }}</div>
              </div>
            </div>
          </aside>
        </div>

        <!-- Footer -->
        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2 bg-white">
          <button class="btn-secondary" @click="showEditor = false">{{ t('master.contract_templates.btn_close') }}</button>
          <button class="btn-primary" @click="save">{{ editing ? t('master.contract_templates.btn_save_changes') : t('master.contract_templates.btn_save') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>