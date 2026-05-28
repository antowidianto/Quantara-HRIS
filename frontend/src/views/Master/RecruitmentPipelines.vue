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
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, PencilIcon, TrashIcon, DocumentDuplicateIcon,
  Bars3Icon, XMarkIcon, ArrowsUpDownIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()
const templates = ref([])
const categories = ref([])
const loading = ref(false)

const showEditor = ref(false)
const editingTpl = ref(null) // null untuk create
const form = ref({ code: '', name: '', description: '', is_default: 0, stages: [] })

const stageTypes = [
  { value: 'SCREENING',        label: 'Screening / CV Review' },
  { value: 'ONLINE_TEST',      label: 'Online Test' },
  { value: 'INTERVIEW',        label: 'Interview (HR / User / BOD)' },
  { value: 'ASSESSMENT',       label: 'Assessment Center / FGD' },
  { value: 'OFFER',            label: 'Offer Letter' },
  { value: 'BACKGROUND_CHECK', label: 'Background Check' },
  { value: 'MEDICAL_CHECK',    label: 'Medical Check (MCU)' },
  { value: 'CUSTOM',           label: 'Custom (lain-lain)' },
  { value: 'HIRED',            label: 'Hired (terminal)' },
  { value: 'REJECTED',         label: 'Rejected (terminal)' },
  { value: 'WITHDRAWN',        label: 'Withdrawn (terminal)' },
]

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
    toast.error(e.response?.data?.message || 'Gagal memuat data')
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
        { _key: makeKey(), code: 'NEW', name: 'Baru Masuk', stage_type: 'SCREENING', order_index: 0, is_terminal: 0, is_optional: 0 },
        { _key: makeKey(), code: 'HIRED', name: 'Hired', stage_type: 'HIRED', order_index: 1, is_terminal: 1, is_optional: 0 },
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
  const newName = window.prompt(`Salin "${tpl.name}" jadi nama baru:`, tpl.name + ' (Copy)')
  if (! newName) return
  const newCode = window.prompt('Kode unik (huruf kapital + underscore):', tpl.code + '_COPY')
  if (! newCode) return
  try {
    await api.post(`/recruitment-pipelines/${tpl.id}/duplicate`, {
      name: newName,
      code: newCode.toUpperCase(),
    })
    toast.success('Pipeline diduplikasi')
    fetchTemplates()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal duplikasi')
  }
}

async function remove(tpl) {
  const ok = await confirmDialog({
    title: 'Hapus pipeline',
    message: `Yakin hapus "${tpl.name}"? Tidak bisa dihapus kalau masih dipakai job posting.`,
    okText: 'Hapus',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/recruitment-pipelines/${tpl.id}`)
    toast.success('Pipeline dihapus')
    fetchTemplates()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
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
      stage.name = stage.stage_type === 'HIRED' ? 'Hired'
        : stage.stage_type === 'REJECTED' ? 'Ditolak'
        : 'Mengundurkan Diri'
    }
  } else {
    stage.is_terminal = 0
  }
}

// Validasi sebelum save
const validation = computed(() => {
  const errs = []
  if (! form.value.code) errs.push('Kode template wajib diisi')
  if (! form.value.name) errs.push('Nama template wajib diisi')
  if (! form.value.stages.length) errs.push('Minimal 1 stage')
  const codes = new Set()
  for (const s of form.value.stages) {
    if (! s.code) { errs.push('Ada stage tanpa kode'); break }
    const code = String(s.code).toUpperCase()
    if (! /^[A-Z0-9_]{1,32}$/.test(code)) {
      errs.push(`Kode "${s.code}" tidak valid (huruf kapital/digit/_, maks 32)`)
      break
    }
    if (codes.has(code)) { errs.push(`Kode duplikat: ${code}`); break }
    codes.add(code)
    if (! s.name) { errs.push(`Stage "${code}": nama wajib`); break }
  }
  const hasHired = form.value.stages.some(s => s.stage_type === 'HIRED')
  if (! hasHired) errs.push('Wajib ada minimal 1 stage type=HIRED')
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
      toast.success('Pipeline diperbarui')
    } else {
      await api.post('/recruitment-pipelines', payload)
      toast.success('Pipeline ditambahkan')
    }
    showEditor.value = false
    fetchTemplates()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">Pipeline Rekrutmen</h1>
        <p class="text-sm text-gray-500 mt-1">
          Atur urutan tahapan rekrutmen sendiri. Bisa custom Online Test sebelum/sesudah Interview HR,
          tambah stage Psikotest, FGD, MCU, dst. Setiap job posting bisa pakai pipeline berbeda.
        </p>
      </div>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Tambah Pipeline
      </button>
    </div>

    <div v-if="loading" class="card p-10 text-center text-gray-400">Memuat...</div>
    <div v-else-if="templates.length === 0" class="card p-10 text-center text-gray-400">
      Belum ada pipeline. Mulai dengan klik "Tambah Pipeline" — sistem akan men-prefill dengan
      template standar yang bisa Anda modifikasi.
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
                  class="text-xs px-1.5 py-0.5 bg-primary-100 text-primary-700 rounded">DEFAULT</span>
                <span v-if="! tpl.is_active" class="text-xs text-red-500">Non-aktif</span>
              </div>
              <div class="font-semibold text-gray-800 mt-2">{{ tpl.name }}</div>
              <div v-if="tpl.description" class="text-xs text-gray-500 mt-1 line-clamp-2">
                {{ tpl.description }}
              </div>
            </div>
            <div class="flex flex-col gap-1" @click.stop>
              <button class="text-gray-400 hover:text-primary-600" @click="openEdit(tpl)" title="Edit">
                <PencilIcon class="w-4 h-4" />
              </button>
              <button class="text-gray-400 hover:text-blue-600" @click="duplicate(tpl)" title="Duplikat">
                <DocumentDuplicateIcon class="w-4 h-4" />
              </button>
              <button class="text-gray-400 hover:text-red-600" @click="remove(tpl)" title="Hapus">
                <TrashIcon class="w-4 h-4" />
              </button>
            </div>
          </div>
          <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
            <span><span class="font-medium text-gray-700">{{ tpl.stage_count }}</span> stages</span>
            <span><span class="font-medium text-gray-700">{{ tpl.posting_count }}</span> posting menggunakan</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Editor modal -->
    <div v-if="showEditor"
      class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[92vh] flex flex-col">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editingTpl ? 'Edit Pipeline' : 'Tambah Pipeline' }}</h2>
          <button class="text-gray-500" @click="showEditor = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-4 space-y-5">
          <!-- Header form -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="label">Kode <span class="text-red-500">*</span></label>
              <input v-model="form.code" maxlength="32" class="input"
                placeholder="MIS. SALES_PIPELINE" />
            </div>
            <div>
              <label class="label">Nama <span class="text-red-500">*</span></label>
              <input v-model="form.name" maxlength="200" class="input" placeholder="MIS. Sales Pipeline" />
            </div>
            <div class="md:col-span-2">
              <label class="label">Deskripsi</label>
              <textarea v-model="form.description" rows="2" class="input"
                placeholder="Catatan internal: kapan template ini dipakai"></textarea>
            </div>
            <div class="md:col-span-2">
              <label class="inline-flex items-center gap-2">
                <input v-model="form.is_default" type="checkbox" :true-value="1" :false-value="0" class="w-4 h-4" />
                <span class="text-sm">Jadikan default — dipakai job posting yang tidak set pipeline eksplisit</span>
              </label>
            </div>
          </div>

          <!-- Stages editor -->
          <div>
            <div class="flex items-center justify-between mb-2">
              <h3 class="font-semibold text-gray-800">Stages</h3>
              <button class="btn-secondary text-sm" @click="addStage">
                <PlusIcon class="w-4 h-4 inline mr-1" /> Tambah Stage
              </button>
            </div>
            <p class="text-xs text-gray-500 mb-3">
              Drag baris untuk mengatur urutan, atau pakai panah ↑/↓.
              Stage type <code>HIRED/REJECTED/WITHDRAWN</code> otomatis ditandai terminal.
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
                    title="Drag untuk reorder">
                    <Bars3Icon class="w-5 h-5" />
                  </div>
                  <div class="flex-1 p-3">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-2">
                      <div class="md:col-span-2">
                        <label class="text-xs text-gray-500">Kode</label>
                        <input v-model="stage.code" maxlength="32" class="input input-sm"
                          placeholder="ONLINE_PSIKO" />
                      </div>
                      <div class="md:col-span-3">
                        <label class="text-xs text-gray-500">Nama</label>
                        <input v-model="stage.name" maxlength="200" class="input input-sm"
                          placeholder="Psikotest" />
                      </div>
                      <div class="md:col-span-3">
                        <label class="text-xs text-gray-500">Tipe</label>
                        <select v-model="stage.stage_type" class="input input-sm"
                          @change="onStageTypeChange(stage)">
                          <option v-for="t in stageTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                      </div>
                      <div class="md:col-span-3" v-if="stage.stage_type === 'ONLINE_TEST'">
                        <label class="text-xs text-gray-500">Test Category Gate</label>
                        <select v-model="stage.required_test_category_id" class="input input-sm">
                          <option :value="null">— Tidak di-gate —</option>
                          <option v-for="c in categories" :key="c.id" :value="c.id">
                            {{ c.name }} ({{ c.code }})
                          </option>
                        </select>
                      </div>
                      <div class="md:col-span-3" v-else>
                        <label class="text-xs text-gray-500">SLA (jam)</label>
                        <input v-model.number="stage.sla_hours" type="number" min="0"
                          class="input input-sm" placeholder="Mis. 72" />
                      </div>
                      <div class="md:col-span-1 flex items-end gap-1">
                        <button class="text-gray-400 hover:text-gray-700" type="button"
                          @click="moveUp(idx)" :disabled="idx === 0" title="Naik">↑</button>
                        <button class="text-gray-400 hover:text-gray-700" type="button"
                          @click="moveDown(idx)" :disabled="idx === form.stages.length - 1"
                          title="Turun">↓</button>
                        <button class="text-gray-400 hover:text-red-600 ml-auto" type="button"
                          @click="removeStage(idx)" title="Hapus">
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
                        <span>Terminal</span>
                      </label>
                      <label class="inline-flex items-center gap-1">
                        <input v-model="stage.is_optional" type="checkbox" :true-value="1" :false-value="0"
                          class="w-3.5 h-3.5" />
                        <span>Optional (boleh skip)</span>
                      </label>
                      <span class="text-gray-400 ml-auto">Order: {{ stage.order_index }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="validation.length" class="mt-3 bg-amber-50 border border-amber-200 rounded p-3">
              <p class="text-sm font-medium text-amber-800 mb-1">Cek validasi:</p>
              <ul class="text-xs text-amber-700 list-disc list-inside space-y-1">
                <li v-for="v in validation" :key="v">{{ v }}</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2">
          <button class="btn-secondary" @click="showEditor = false">Batal</button>
          <button class="btn-primary" :disabled="validation.length > 0" @click="save">Simpan</button>
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