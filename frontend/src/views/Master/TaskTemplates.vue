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
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useConfirm } from '@/composables/useConfirm'
import {
  ClipboardDocumentListIcon, PlusIcon, TrashIcon, ArrowUpIcon,
  ArrowDownIcon, BookmarkSquareIcon, DocumentDuplicateIcon, CheckBadgeIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()
const { confirm } = useConfirm()

const filterType = ref('') // '' | ONBOARD | OFFBOARD
const templates = ref([])
const loading = ref(false)
const selected = ref(null)
const draft = ref(null)
const saving = ref(false)

const ownerRoles = [
  { value: 'HR',         label: 'HR' },
  { value: 'IT',         label: 'IT' },
  { value: 'FINANCE',    label: 'Finance' },
  { value: 'SUPERVISOR', label: 'Atasan langsung' },
  { value: 'MANAGER',    label: 'Manajer' },
  { value: 'EMPLOYEE',   label: 'Karyawan ybs' },
]

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
    toast.error(e.response?.data?.message || 'Gagal memuat template')
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
  if (! draft.value) return
  if (! draft.value.code.trim() || ! draft.value.name.trim()) {
    toast.warning('Code dan Nama wajib diisi')
    return
  }
  if (! draft.value.tasks.length) {
    toast.warning('Tambahkan minimal satu task')
    return
  }
  // Validasi tiap task harus punya nama
  const invalid = draft.value.tasks.findIndex(t => ! (t.name || '').trim())
  if (invalid >= 0) {
    toast.warning(`Task #${invalid + 1} belum punya nama`)
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
      toast.success('Template diperbarui')
    } else {
      const res = await api.post('/task-templates', payload)
      toast.success('Template dibuat')
      draft.value.id = res.data?.data?.id
    }
    await fetchList()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan template')
  } finally {
    saving.value = false
  }
}

async function deleteTemplate() {
  if (! draft.value?.id) return
  const ok = await confirm({
    title: 'Hapus Template?',
    message: `Template "${draft.value.name}" akan di-soft-delete. Karyawan yang sudah pakai checklist tidak terpengaruh.`,
    confirmText: 'Hapus',
    type: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/task-templates/${draft.value.id}`)
    toast.success('Template dihapus')
    selected.value = null
    draft.value = null
    fetchList()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

function duplicateTemplate() {
  if (! draft.value) return
  draft.value = {
    ...JSON.parse(JSON.stringify(draft.value)),
    id: null,
    code: draft.value.code + '_COPY',
    name: draft.value.name + ' (copy)',
    is_default: 0,
  }
  selected.value = null
  toast.info('Salinan dibuat. Klik "Simpan" untuk menyimpan sebagai template baru.')
}

const taskCount = computed(() => draft.value?.tasks?.length || 0)
const mandatoryCount = computed(() =>
  (draft.value?.tasks || []).filter(t => t.mandatory).length
)

onMounted(() => fetchList())
watch(filterType, () => fetchList())

const typeBadge = (type) =>
  type === 'ONBOARD' ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                     : 'bg-orange-50 text-orange-700 border-orange-200'
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ClipboardDocumentListIcon class="w-6 h-6" />
          Template Onboarding / Offboarding
        </h1>
        <p class="text-sm text-gray-500">
          Atur task default yang otomatis dibuat saat karyawan baru masuk atau resign.
          Template dengan tanda <CheckBadgeIcon class="w-4 h-4 inline text-emerald-600" /> dipakai sebagai default.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model="filterType" class="input">
          <option value="">Semua Tipe</option>
          <option value="ONBOARD">Onboarding</option>
          <option value="OFFBOARD">Offboarding</option>
        </select>
        <button class="btn-primary" @click="newTemplate('ONBOARD')">
          <PlusIcon class="w-4 h-4" /> Tambah
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- DAFTAR TEMPLATE -->
      <div class="lg:col-span-1 card overflow-hidden">
        <div class="card-header text-sm text-gray-500">{{ templates.length }} template</div>
        <div class="divide-y divide-gray-100 max-h-[70vh] overflow-y-auto">
          <div v-if="loading" class="px-4 py-8 text-center text-sm text-gray-400">Memuat…</div>
          <div v-else-if="!templates.length" class="px-4 py-12 text-center text-sm text-gray-400">
            Belum ada template. Klik tombol "Tambah" di pojok kanan atas.
          </div>
          <button v-for="t in templates" :key="t.id"
            class="w-full text-left px-4 py-3 hover:bg-gray-50 transition"
            :class="{ 'bg-primary-50/40 border-l-4 border-primary-500': draft && draft.id === t.id }"
            @click="selectTemplate(t)">
            <div class="flex items-center justify-between mb-1">
              <span class="font-medium text-sm">{{ t.name }}</span>
              <span class="text-xs px-1.5 py-0.5 rounded border" :class="typeBadge(t.type)">{{ t.type }}</span>
            </div>
            <div class="text-xs text-gray-500 flex items-center gap-1.5">
              <code class="text-[11px]">{{ t.code }}</code>
              <span class="text-gray-400">·</span>
              <span>{{ (t.tasks || []).length }} task</span>
              <CheckBadgeIcon v-if="t.is_default" class="w-3.5 h-3.5 text-emerald-600 ml-auto" title="Default" />
              <span v-if="!t.is_active" class="ml-auto text-amber-600">Non-aktif</span>
            </div>
          </button>
        </div>
      </div>

      <!-- EDITOR -->
      <div class="lg:col-span-2 card">
        <div v-if="!draft" class="px-6 py-16 text-center text-sm text-gray-400">
          Pilih template di kiri, atau buat baru.
        </div>

        <div v-else>
          <div class="card-header">
            <div class="flex flex-wrap gap-3 items-center justify-between">
              <div>
                <div class="text-xs text-gray-500">{{ draft.id ? `Template #${draft.id}` : 'Template baru' }}</div>
                <input v-model="draft.name" class="text-lg font-semibold bg-transparent focus:bg-white focus:ring-1 focus:ring-primary-500 rounded px-1 -ml-1"
                  placeholder="Nama template (mis. Onboarding Karyawan Default)" />
              </div>
              <div class="flex gap-2">
                <button v-if="draft.id" class="btn-secondary" @click="duplicateTemplate" title="Duplikat">
                  <DocumentDuplicateIcon class="w-4 h-4" />
                </button>
                <button v-if="draft.id" class="btn-secondary text-red-600" @click="deleteTemplate" title="Hapus">
                  <TrashIcon class="w-4 h-4" />
                </button>
                <button class="btn-primary" :disabled="saving" @click="saveTemplate">
                  <BookmarkSquareIcon class="w-4 h-4" />
                  {{ saving ? 'Menyimpan…' : 'Simpan' }}
                </button>
              </div>
            </div>
          </div>

          <div class="card-body space-y-4">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="label">Kode *</label>
                <input v-model="draft.code" class="input font-mono uppercase" placeholder="TPL_ONBOARD_DEFAULT" />
              </div>
              <div>
                <label class="label">Tipe *</label>
                <select v-model="draft.type" class="input">
                  <option value="ONBOARD">Onboarding (karyawan masuk)</option>
                  <option value="OFFBOARD">Offboarding (karyawan resign)</option>
                </select>
              </div>
              <div class="col-span-2">
                <label class="label">Deskripsi</label>
                <textarea v-model="draft.description" rows="2" class="input"
                  placeholder="Catatan untuk HR — kapan template ini dipakai, batasan, dll."></textarea>
              </div>
              <div class="col-span-2 flex items-center gap-4">
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="draft.is_default" :true-value="1" :false-value="0" />
                  Pakai sebagai default untuk tipe ini
                </label>
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="draft.is_active" :true-value="1" :false-value="0" />
                  Aktif (bisa dipakai)
                </label>
                <span class="ml-auto text-xs text-gray-500">
                  Total: <strong>{{ taskCount }}</strong> task,
                  <strong class="text-amber-700">{{ mandatoryCount }}</strong> wajib
                </span>
              </div>
            </div>

            <div class="border-t border-gray-200 pt-4">
              <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-semibold text-gray-700">Daftar Task</h3>
                <button class="btn-secondary text-xs" @click="addTask">
                  <PlusIcon class="w-3.5 h-3.5" /> Tambah Task
                </button>
              </div>

              <div v-if="!draft.tasks.length" class="text-center text-sm text-gray-400 py-6 border border-dashed rounded">
                Belum ada task. Klik "Tambah Task" untuk mulai.
              </div>

              <div v-else class="space-y-2">
                <div v-for="(task, i) in draft.tasks" :key="i"
                  class="border border-gray-200 rounded-md p-3 bg-white hover:border-primary-300 transition">
                  <div class="flex items-start gap-2">
                    <div class="flex flex-col gap-0.5 mt-1">
                      <button class="text-gray-400 hover:text-gray-700 disabled:opacity-30"
                        :disabled="i === 0" @click="moveTask(i, -1)" title="Naikkan">
                        <ArrowUpIcon class="w-4 h-4" />
                      </button>
                      <button class="text-gray-400 hover:text-gray-700 disabled:opacity-30"
                        :disabled="i === draft.tasks.length - 1" @click="moveTask(i, 1)" title="Turunkan">
                        <ArrowDownIcon class="w-4 h-4" />
                      </button>
                    </div>
                    <div class="flex-1 grid grid-cols-12 gap-2">
                      <input v-model="task.name" class="input col-span-7" :placeholder="`Task #${i+1} — nama task`" />
                      <input v-model="task.key" class="input col-span-3 font-mono text-xs uppercase" placeholder="KEY (opsional)" />
                      <select v-model="task.owner_role" class="input col-span-2 text-xs">
                        <option v-for="r in ownerRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
                      </select>
                      <input v-model="task.description" class="input col-span-12 text-xs" placeholder="Deskripsi singkat (opsional)" />
                      <div class="col-span-12 flex items-center gap-3 text-xs text-gray-600">
                        <label class="flex items-center gap-1.5">
                          Due:
                          <input v-model.number="task.due_offset_days" type="number" class="input w-16 text-xs py-1" />
                          hari dari anchor ({{ draft.type === 'ONBOARD' ? 'tgl masuk' : 'tgl resign' }})
                        </label>
                        <label class="flex items-center gap-1.5">
                          <input type="checkbox" v-model="task.mandatory" />
                          Wajib
                        </label>
                        <button class="ml-auto text-red-500 hover:text-red-700" @click="removeTask(i)" title="Hapus task">
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