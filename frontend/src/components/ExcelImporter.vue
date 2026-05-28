<script setup>
/**
 * ExcelImporter — komponen reusable untuk bulk-upload data dari xlsx.
 *
 * Pola pakai (lihat misalnya `views/Master/Departments.vue` setelah Migration #067):
 *
 *   <ExcelImporter
 *     resource="departments"        // slug yang terdaftar di backend ImportRegistry
 *     :button-label="'Import Excel'"
 *     @committed="onCommitted"      // event setelah berhasil commit
 *   />
 *
 * Flow di UI:
 *   1. User klik tombol → buka dialog
 *   2. Klik "Download Template" → backend stream xlsx kosong + sample
 *   3. Drag/drop atau pick file .xlsx → POST /imports/<resource>/preview
 *   4. Tampilkan summary (total, valid, error) + tabel preview
 *   5. Kalau ada error → user perbaiki file, re-upload
 *   6. Kalau valid → klik Commit → POST /imports/<resource>/commit/<batchId>
 *   7. Emit `committed` event ke parent supaya parent reload list-nya
 *
 * Backend di-handle ImportController (generic, satu controller untuk semua resource).
 * Lihat docs/XLSX_IMPORT_AUDIT.md.
 */
import { ref, computed, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  ArrowDownTrayIcon, ArrowUpTrayIcon, DocumentArrowUpIcon,
  CheckCircleIcon, ExclamationTriangleIcon, XCircleIcon,
  XMarkIcon, ClockIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  resource: { type: String, required: true },
  buttonLabel: { type: String, default: 'Import Excel' },
  // Auto-open dialog (untuk testing / direct link)
  autoOpen: { type: Boolean, default: false },
})

const emit = defineEmits(['committed'])

const toast = useToast()

// ─── State ──────────────────────────────────────────────────────────
const open = ref(props.autoOpen)
const loading = ref(false)
const downloading = ref(false)
const committing = ref(false)

const file = ref(null)
const fileName = ref('')

// Hasil dari /preview
const batch = ref(null)
const preview = ref([])
const errorsList = ref([])
const summary = ref(null)

const tab = ref('preview')   // preview | errors

// History batch
const showHistory = ref(false)
const history = ref([])
const historyLoading = ref(false)

// Schema (untuk render header table)
const schema = ref(null)

// Drag-and-drop
const isDragging = ref(false)

// ─── Computed ───────────────────────────────────────────────────────
const canCommit = computed(() =>
  batch.value && summary.value &&
  summary.value.errors === 0 &&
  summary.value.valid > 0 &&
  batch.value.status === 'PREVIEW'
)

const previewColumns = computed(() => {
  if (! schema.value || ! schema.value.columns) return []
  return schema.value.columns
})

// ─── Lifecycle: load schema saat dialog dibuka ──────────────────────
watch(open, async (val) => {
  if (val && ! schema.value) {
    await loadSchema()
  }
})

async function loadSchema() {
  try {
    const res = await api.get('/imports/resources')
    const list = res.data.data || []
    schema.value = list.find(r => r.slug === props.resource) || null
    if (schema.value && ! schema.value.allowed) {
      toast.warning(`Anda tidak punya permission ${schema.value.permission}`)
    }
  } catch (e) {
    toast.error('Gagal memuat skema import: ' + (e.response?.data?.message || e.message))
  }
}

// ─── Template download ──────────────────────────────────────────────
async function downloadTemplate() {
  downloading.value = true
  try {
    const res = await api.get(`/imports/${props.resource}/template`, {
      responseType: 'blob',
    })
    const blob = new Blob([res.data], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = res.headers['x-filename'] || `import-template-${props.resource}.xlsx`
    document.body.appendChild(a)
    a.click()
    setTimeout(() => { URL.revokeObjectURL(url); a.remove() }, 100)
  } catch (e) {
    // Coba parse error JSON dari blob (backend kirim error body kalau gagal)
    let msg = 'Gagal download template'
    if (e.response?.data instanceof Blob) {
      try {
        const text = await e.response.data.text()
        msg = JSON.parse(text).message || msg
      } catch { /* keep default */ }
    } else {
      msg = e.response?.data?.message || e.message || msg
    }
    toast.error(msg)
  } finally {
    downloading.value = false
  }
}

// ─── File pick + preview ────────────────────────────────────────────
function onFileChange(e) {
  const f = e.target.files?.[0]
  if (f) handleFile(f)
}

function onDrop(e) {
  isDragging.value = false
  const f = e.dataTransfer.files?.[0]
  if (f) handleFile(f)
}

function onDragOver(e) {
  e.preventDefault()
  isDragging.value = true
}

function onDragLeave() {
  isDragging.value = false
}

async function handleFile(f) {
  if (! f.name.toLowerCase().endsWith('.xlsx')) {
    toast.warning('Hanya file .xlsx yang didukung. Untuk .xls, save-as ke .xlsx dulu.')
    return
  }
  if (f.size > 10 * 1024 * 1024) {
    toast.warning('Maks 10 MB. File terlalu besar.')
    return
  }
  file.value = f
  fileName.value = f.name
  await uploadPreview()
}

async function uploadPreview() {
  if (! file.value) return
  loading.value = true
  batch.value = null
  preview.value = []
  errorsList.value = []
  summary.value = null
  tab.value = 'preview'

  const fd = new FormData()
  fd.append('file', file.value)
  try {
    const res = await api.post(`/imports/${props.resource}/preview`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    const d = res.data.data
    summary.value = {
      batch_id: d.batch_id,
      total: d.total,
      valid: d.valid,
      errors: d.errors,
      status: d.status,
      idempotent: d.idempotent,
    }
    batch.value = { id: d.batch_id, status: d.status }
    preview.value = d.preview || []
    errorsList.value = d.errors_list || []
    if (d.errors > 0) tab.value = 'errors'
    if (d.idempotent) {
      toast.info('File ini sudah pernah di-upload sebelumnya. Menampilkan batch yang ada.')
    } else if (d.errors === 0) {
      toast.success(`${d.valid} baris siap di-commit. Cek preview lalu klik Commit.`)
    } else {
      toast.warning(`${d.errors} dari ${d.total} baris bermasalah. Cek tab Errors di bawah.`)
    }
  } catch (e) {
    const msg = e.response?.data?.message || 'Gagal upload file'
    toast.error(msg)
  } finally {
    loading.value = false
  }
}

// ─── Commit ─────────────────────────────────────────────────────────
async function commit() {
  if (! canCommit.value) return
  const ok = await confirmDialog({
    title: 'Commit Import?',
    message: `${summary.value.valid} baris akan di-insert ke ${schema.value?.label || props.resource}. Tindakan ini tidak bisa di-undo per-record (harus hapus manual).`,
    okText: 'Commit',
  })
  if (! ok) return
  committing.value = true
  try {
    const res = await api.post(`/imports/${props.resource}/commit/${batch.value.id}`)
    const d = res.data.data
    toast.success(`${d.inserted} baris berhasil di-import${d.skipped > 0 ? ` (${d.skipped} skipped)` : ''}`)
    emit('committed', d)
    open.value = false
    reset()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal commit')
  } finally {
    committing.value = false
  }
}

// ─── Cancel batch (saat masih PREVIEW) ──────────────────────────────
async function cancel() {
  if (! batch.value) { reset(); return }
  if (batch.value.status !== 'PREVIEW') { reset(); return }
  try {
    await api.post(`/imports/batches/${batch.value.id}/cancel`)
  } catch { /* ignore */ }
  reset()
}

function reset() {
  file.value = null
  fileName.value = ''
  batch.value = null
  preview.value = []
  errorsList.value = []
  summary.value = null
}

// ─── History ────────────────────────────────────────────────────────
async function loadHistory() {
  historyLoading.value = true
  try {
    const res = await api.get(`/imports/${props.resource}/history`, {
      params: { per_page: 20 },
    })
    history.value = res.data.data || []
  } catch (e) {
    toast.error('Gagal muat riwayat')
  } finally {
    historyLoading.value = false
  }
}

function toggleHistory() {
  showHistory.value = !showHistory.value
  if (showHistory.value && history.value.length === 0) loadHistory()
}

// ─── Helpers ────────────────────────────────────────────────────────
const STATUS_BADGE = {
  PENDING:   { label: 'Pending',    cls: 'bg-gray-100 text-gray-600' },
  PREVIEW:   { label: 'Preview',    cls: 'bg-amber-100 text-amber-700' },
  COMMITTED: { label: 'Committed',  cls: 'bg-emerald-100 text-emerald-700' },
  FAILED:    { label: 'Failed',     cls: 'bg-red-100 text-red-700' },
  CANCELLED: { label: 'Cancelled',  cls: 'bg-gray-100 text-gray-500' },
}

function fmtSize(bytes) {
  if (! bytes) return ''
  const kb = bytes / 1024
  if (kb < 1024) return `${kb.toFixed(1)} KB`
  return `${(kb / 1024).toFixed(2)} MB`
}

function fmtDate(s) {
  if (! s) return '-'
  const d = new Date(s)
  return d.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="inline-block">
    <button class="btn-secondary" @click="open = true">
      <DocumentArrowUpIcon class="w-4 h-4" />
      {{ buttonLabel }}
    </button>

    <!-- ─── Dialog ──────────────────────────────────────────────── -->
    <div v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl max-h-[92vh] overflow-y-auto">
        <!-- Header -->
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold flex items-center gap-2">
            <DocumentArrowUpIcon class="w-5 h-5 text-primary-600" />
            Import {{ schema?.label || resource }} dari Excel
          </h2>
          <button class="text-gray-500 hover:text-gray-800"
            @click="cancel(); open = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-6 space-y-4">
          <!-- Permission warning -->
          <div v-if="schema && !schema.allowed"
            class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <ExclamationTriangleIcon class="w-5 h-5 inline -mt-0.5 mr-1" />
            Anda tidak punya permission <strong>{{ schema.permission }}</strong> untuk
            import resource ini. Hubungi admin.
          </div>

          <!-- Step 1: Notes + download template -->
          <div class="rounded-lg bg-blue-50 border border-blue-200 p-4 text-sm">
            <p v-if="schema?.notes_md" class="text-blue-900 mb-2">
              {{ schema.notes_md }}
            </p>
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <p class="text-blue-700 text-xs">
                Disarankan: download template dulu, isi datanya di Excel, lalu upload kembali.
                Maks 10 MB / 5000 baris.
              </p>
              <button class="btn-secondary text-sm" @click="downloadTemplate"
                :disabled="downloading || !schema?.allowed">
                <ArrowDownTrayIcon class="w-4 h-4" />
                {{ downloading ? 'Menyiapkan...' : 'Download Template' }}
              </button>
            </div>
          </div>

          <!-- Step 2: Upload area -->
          <div v-if="!summary"
            class="rounded-lg border-2 border-dashed transition cursor-pointer p-8 text-center"
            :class="isDragging ? 'border-primary-500 bg-primary-50' : 'border-gray-300 hover:border-primary-400 hover:bg-gray-50'"
            @dragover.prevent="onDragOver"
            @dragleave="onDragLeave"
            @drop.prevent="onDrop"
            @click="$refs.fileInput.click()">
            <ArrowUpTrayIcon class="w-12 h-12 text-gray-400 mx-auto mb-3" />
            <p class="font-medium text-gray-700">
              {{ loading ? 'Memproses...' : 'Klik atau drag & drop file .xlsx ke sini' }}
            </p>
            <p class="text-xs text-gray-500 mt-1">
              File akan di-parse + di-validate dulu, BELUM masuk database sampai kamu klik Commit.
            </p>
            <input
              ref="fileInput"
              type="file"
              accept=".xlsx"
              class="hidden"
              @change="onFileChange"
              :disabled="loading"
            />
          </div>

          <!-- Step 3: Summary + tabs -->
          <div v-if="summary" class="space-y-3">
            <div class="rounded-lg border p-3 flex items-center gap-3 flex-wrap"
              :class="summary.errors === 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200'">
              <div class="flex-1">
                <div class="text-sm font-semibold flex items-center gap-2">
                  <CheckCircleIcon v-if="summary.errors === 0" class="w-5 h-5 text-emerald-600" />
                  <ExclamationTriangleIcon v-else class="w-5 h-5 text-red-600" />
                  File: <span class="font-mono text-xs">{{ fileName || '(dari batch lama)' }}</span>
                  <span class="text-xs px-2 py-0.5 rounded ml-2" :class="STATUS_BADGE[batch?.status]?.cls">
                    {{ STATUS_BADGE[batch?.status]?.label }}
                  </span>
                </div>
                <div class="text-xs text-gray-600 mt-1">
                  Total <strong>{{ summary.total }}</strong> baris ·
                  <span class="text-emerald-700">{{ summary.valid }} valid</span> ·
                  <span class="text-red-700">{{ summary.errors }} error</span>
                  <span v-if="summary.idempotent" class="ml-2 text-amber-700">
                    (dari upload sebelumnya)
                  </span>
                </div>
              </div>
              <button class="text-xs text-gray-500 hover:text-gray-800 underline"
                @click="reset()">Pilih file lain</button>
            </div>

            <!-- Tabs -->
            <div class="border-b flex items-center gap-4 text-sm">
              <button class="py-2 -mb-px border-b-2 transition"
                :class="tab === 'preview' ? 'border-primary-600 text-primary-600 font-medium' : 'border-transparent text-gray-500 hover:text-gray-800'"
                @click="tab = 'preview'">
                Preview ({{ preview.length }})
              </button>
              <button class="py-2 -mb-px border-b-2 transition"
                :class="tab === 'errors' ? 'border-red-600 text-red-600 font-medium' : 'border-transparent text-gray-500 hover:text-gray-800'"
                @click="tab = 'errors'">
                Errors ({{ errorsList.length }})
              </button>
            </div>

            <!-- Preview table -->
            <div v-if="tab === 'preview'" class="overflow-x-auto border rounded">
              <table class="w-full text-xs">
                <thead class="bg-gray-50 border-b">
                  <tr>
                    <th class="px-2 py-2 text-left">Row</th>
                    <th v-for="c in previewColumns" :key="c.field"
                      class="px-2 py-2 text-left whitespace-nowrap">
                      {{ c.header }}
                      <span v-if="c.required" class="text-red-500">*</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in preview" :key="row.row"
                    class="border-b hover:bg-gray-50"
                    :class="row.has_error ? 'bg-red-50' : ''">
                    <td class="px-2 py-1.5 font-mono text-gray-500">{{ row.row }}</td>
                    <td v-for="c in previewColumns" :key="c.field"
                      class="px-2 py-1.5 max-w-[200px] truncate"
                      :title="row.data?.[c.field]">
                      {{ row.data?.[c.field] ?? '—' }}
                    </td>
                  </tr>
                  <tr v-if="preview.length === 0">
                    <td :colspan="previewColumns.length + 1"
                      class="px-2 py-8 text-center text-gray-400">
                      Tidak ada baris. Pastikan file punya header sesuai template.
                    </td>
                  </tr>
                </tbody>
              </table>
              <div v-if="summary.total > preview.length"
                class="px-3 py-2 text-xs text-gray-500 bg-gray-50">
                Menampilkan {{ preview.length }} dari {{ summary.total }} baris.
                {{ summary.total - preview.length }} baris sisanya akan ikut di-commit kalau valid.
              </div>
            </div>

            <!-- Errors table -->
            <div v-else class="overflow-x-auto border rounded">
              <table class="w-full text-xs">
                <thead class="bg-red-50 border-b">
                  <tr>
                    <th class="px-2 py-2 text-left w-16">Row</th>
                    <th class="px-2 py-2 text-left w-48">Kolom</th>
                    <th class="px-2 py-2 text-left">Error</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(err, i) in errorsList" :key="i" class="border-b hover:bg-red-50/40">
                    <td class="px-2 py-1.5 font-mono text-red-700">{{ err.row }}</td>
                    <td class="px-2 py-1.5 font-medium">{{ err.column }}</td>
                    <td class="px-2 py-1.5 text-red-700">{{ err.error }}</td>
                  </tr>
                  <tr v-if="errorsList.length === 0">
                    <td colspan="3" class="px-2 py-8 text-center text-emerald-600">
                      <CheckCircleIcon class="w-6 h-6 inline -mt-1 mr-1" />
                      Tidak ada error. File siap di-commit.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ─── History ────────────────────────────────────────── -->
          <div class="border-t pt-3">
            <button class="text-xs text-gray-600 hover:text-primary-600 flex items-center gap-1"
              @click="toggleHistory">
              <ClockIcon class="w-4 h-4" />
              {{ showHistory ? 'Sembunyikan' : 'Lihat' }} Riwayat Import
            </button>
            <div v-if="showHistory" class="mt-2 border rounded overflow-hidden">
              <table class="w-full text-xs">
                <thead class="bg-gray-50 border-b">
                  <tr>
                    <th class="px-2 py-2 text-left">#</th>
                    <th class="px-2 py-2 text-left">File</th>
                    <th class="px-2 py-2 text-center">Total</th>
                    <th class="px-2 py-2 text-center">Inserted</th>
                    <th class="px-2 py-2">Status</th>
                    <th class="px-2 py-2">Oleh</th>
                    <th class="px-2 py-2">Waktu</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="historyLoading"><td colspan="7" class="text-center py-3 text-gray-400">Memuat...</td></tr>
                  <tr v-else-if="history.length === 0"><td colspan="7" class="text-center py-3 text-gray-400">Belum ada riwayat</td></tr>
                  <tr v-for="h in history" :key="h.id" class="border-b hover:bg-gray-50">
                    <td class="px-2 py-1.5 font-mono">#{{ h.id }}</td>
                    <td class="px-2 py-1.5">
                      {{ h.original_name }}
                      <span class="text-gray-400">({{ fmtSize(h.file_size) }})</span>
                    </td>
                    <td class="px-2 py-1.5 text-center font-mono">{{ h.total_rows }}</td>
                    <td class="px-2 py-1.5 text-center font-mono">{{ h.inserted_count }}</td>
                    <td class="px-2 py-1.5">
                      <span class="px-1.5 py-0.5 rounded text-xs" :class="STATUS_BADGE[h.status]?.cls">
                        {{ STATUS_BADGE[h.status]?.label }}
                      </span>
                    </td>
                    <td class="px-2 py-1.5">{{ h.user_name || '-' }}</td>
                    <td class="px-2 py-1.5 text-gray-500">{{ fmtDate(h.created_at) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Footer actions -->
        <div class="px-6 py-3 border-t bg-gray-50 flex justify-end gap-2">
          <button class="btn-secondary"
            @click="cancel(); open = false"
            :disabled="committing">
            Batal
          </button>
          <button class="btn-primary"
            :disabled="!canCommit || committing || !schema?.allowed"
            @click="commit">
            <CheckCircleIcon class="w-4 h-4" />
            {{ committing ? 'Commit...' : `Commit ${summary?.valid || 0} baris` }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>