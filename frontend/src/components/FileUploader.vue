<script setup>
/**
 * FileUploader — komponen lampiran file generic.
 *
 * Pakai di form yang punya field `attachment_path` / `receipt_path` /
 * `evidence_path` / `file_path`. Komponen ini handle:
 *   1. Pilih file (drag-drop / klik)
 *   2. Validasi MIME + size client-side
 *   3. POST multipart/form-data ke /upload/attachment?context=<ctx>
 *   4. Emit path ke v-model parent
 *
 * Usage:
 *   <FileUploader
 *     v-model="form.attachment_path"
 *     context="leave"
 *     accept=".pdf,.jpg,.jpeg,.png,.webp"
 *     :max-size-mb="5"
 *     hint="Surat dokter, bukti tiket, dst."
 *   />
 *
 * Path yang di-bind di v-model adalah string relatif (mis.
 * "leaves/1/3/20260521_120000_a1b2c3d4.pdf"). Backend menyimpan apa adanya
 * di kolom `attachment_path`. Untuk preview/download, pakai signed_url
 * yang juga di-emit lewat event `uploaded`.
 */
import { computed, ref, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { ArrowUpTrayIcon, DocumentIcon, XMarkIcon, EyeIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  modelValue: { type: String, default: '' },
  context: { type: String, required: true },
  accept: { type: String, default: '.pdf,.jpg,.jpeg,.png,.webp' },
  maxSizeMb: { type: Number, default: 5 },
  hint: { type: String, default: '' },
  // Untuk preview lama (dari record existing) tampilkan nama file dari path
  initialName: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'uploaded', 'cleared'])

const toast = useToast()
const fileInput = ref(null)
const uploading = ref(false)
const progress = ref(0)
const previewUrl = ref('')
const fileName = ref(props.initialName || '')
const fileSize = ref(0)

watch(() => props.modelValue, (val, prev) => {
  // Jika parent reset model (mis. setelah submit), bersihkan UI lokal juga
  if (! val && prev) {
    fileName.value = ''
    fileSize.value = 0
    previewUrl.value = ''
    progress.value = 0
  }
}, { immediate: false })

const hasFile = computed(() => !! props.modelValue)
const displayName = computed(() => {
  if (fileName.value) return fileName.value
  if (! props.modelValue) return ''
  // Ambil basename dari path
  const parts = props.modelValue.split('/')
  return parts[parts.length - 1] || props.modelValue
})

function pick() {
  if (props.disabled || uploading.value) return
  fileInput.value?.click()
}

async function onFileChange(e) {
  const files = e.target.files
  if (! files || ! files.length) return
  await upload(files[0])
  // Reset input supaya bisa pilih file yang sama lagi
  e.target.value = ''
}

async function onDrop(e) {
  if (props.disabled || uploading.value) return
  const files = e.dataTransfer?.files
  if (! files || ! files.length) return
  await upload(files[0])
}

async function upload(file) {
  // Client-side guard — backend tetap validasi ulang
  const maxBytes = props.maxSizeMb * 1024 * 1024
  if (file.size > maxBytes) {
    toast.warning(`File terlalu besar (${(file.size/1024/1024).toFixed(1)} MB). Max ${props.maxSizeMb} MB.`)
    return
  }

  const fd = new FormData()
  fd.append('file', file)

  uploading.value = true
  progress.value = 0
  try {
    const res = await api.post(
      `/upload/attachment?context=${encodeURIComponent(props.context)}`,
      fd,
      {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (e) => {
          if (e.total) progress.value = Math.round((e.loaded / e.total) * 100)
        },
      },
    )
    const data = res.data?.data || res.data
    emit('update:modelValue', data.path)
    emit('uploaded', data)
    fileName.value = data.original_name || file.name
    fileSize.value = data.size || file.size
    previewUrl.value = data.signed_url || ''
    progress.value = 100
    toast.success('File terupload')
  } catch (e) {
    const msg = e.response?.data?.message || 'Gagal upload file'
    toast.error(msg)
    progress.value = 0
  } finally {
    uploading.value = false
  }
}

function clear() {
  emit('update:modelValue', '')
  emit('cleared')
  fileName.value = ''
  fileSize.value = 0
  previewUrl.value = ''
  progress.value = 0
}

function fmtSize(bytes) {
  if (! bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1024 / 1024).toFixed(1) + ' MB'
}
</script>

<template>
  <div class="space-y-1">
    <!-- Empty state: drop zone -->
    <div
      v-if="! hasFile && ! uploading"
      class="border-2 border-dashed border-gray-300 rounded-md p-4 text-center cursor-pointer hover:border-primary-400 hover:bg-primary-50/40 transition"
      :class="{ 'opacity-50 pointer-events-none': disabled }"
      @click="pick"
      @dragover.prevent
      @drop.prevent="onDrop"
    >
      <ArrowUpTrayIcon class="w-6 h-6 mx-auto text-gray-400" />
      <div class="text-sm text-gray-600 mt-2">
        Klik atau drop file di sini
      </div>
      <div class="text-xs text-gray-400 mt-1">
        {{ hint || `Max ${maxSizeMb} MB. Format: ${accept}` }}
      </div>
    </div>

    <!-- Uploading state -->
    <div v-else-if="uploading" class="border border-gray-200 rounded-md p-3 bg-gray-50">
      <div class="text-xs text-gray-600 mb-1">Mengunggah... {{ progress }}%</div>
      <div class="h-1.5 bg-gray-200 rounded-full overflow-hidden">
        <div class="h-full bg-primary-500 transition-all" :style="{ width: progress + '%' }"></div>
      </div>
    </div>

    <!-- Filled state -->
    <div v-else class="flex items-center gap-2 border border-gray-200 rounded-md p-2 bg-gray-50">
      <DocumentIcon class="w-5 h-5 text-primary-600 flex-shrink-0" />
      <div class="flex-1 min-w-0">
        <div class="text-sm text-gray-800 truncate" :title="displayName">{{ displayName }}</div>
        <div class="text-xs text-gray-400">{{ fmtSize(fileSize) }}</div>
      </div>
      <a
        v-if="previewUrl"
        :href="previewUrl"
        target="_blank"
        class="text-gray-500 hover:text-primary-600 p-1"
        title="Buka file"
      >
        <EyeIcon class="w-4 h-4" />
      </a>
      <button
        type="button"
        class="text-gray-400 hover:text-red-600 p-1"
        title="Hapus"
        :disabled="disabled"
        @click="clear"
      >
        <XMarkIcon class="w-4 h-4" />
      </button>
    </div>

    <input
      ref="fileInput"
      type="file"
      class="hidden"
      :accept="accept"
      :disabled="disabled || uploading"
      @change="onFileChange"
    />
  </div>
</template>