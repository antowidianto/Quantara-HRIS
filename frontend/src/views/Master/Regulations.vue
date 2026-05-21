<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, MagnifyingGlassIcon, PencilIcon, TrashIcon, EyeIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

/**
 * Halaman editor Peraturan Perusahaan.
 *
 * Setiap baris adalah satu klausul / pasal — saat menyusun kontrak, klausul
 * yang aktif akan otomatis di-render lewat placeholder {{regulations}} atau
 * {{regulations.<KATEGORI>}}.
 */

const toast = useToast()

const categories = [
  { value: 'GENERAL', label: 'Umum' },
  { value: 'WORKING_HOURS', label: 'Jam Kerja' },
  { value: 'LEAVE', label: 'Cuti' },
  { value: 'SALARY', label: 'Gaji & Tunjangan' },
  { value: 'OVERTIME', label: 'Lembur' },
  { value: 'DISCIPLINE', label: 'Disiplin & Sanksi' },
  { value: 'RESIGNATION', label: 'Pengunduran Diri' },
  { value: 'CONFIDENTIALITY', label: 'Kerahasiaan' },
  { value: 'INTELLECTUAL_PROPERTY', label: 'HAKI' },
  { value: 'BENEFITS', label: 'Benefit' },
  { value: 'TERMINATION', label: 'PHK' },
  { value: 'OTHER', label: 'Lainnya' },
]

const items = ref([])
const loading = ref(false)
const meta = ref({ total: 0, page: 1, pages: 1 })
const params = reactive({ page: 1, per_page: 50, search: '', category: '' })

const showModal = ref(false)
const editing = ref(false)
const form = ref(emptyForm())

const showPreview = ref(false)
const previewHtml = ref('')
const previewCategory = ref('')

function emptyForm() {
  return {
    id: null,
    category: 'GENERAL',
    code: '',
    title: '',
    order_no: 100,
    body_html: '',
    effective_from: '',
    effective_to: '',
    is_active: 1,
  }
}

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/regulations', { params })
    items.value = res.data.data || []
    meta.value = res.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat peraturan')
  } finally {
    loading.value = false
  }
}

onMounted(() => fetchData())

let timer = null
watch(() => [params.search, params.category], () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => { params.page = 1; fetchData() }, 300)
})

function openCreate() {
  form.value = emptyForm()
  editing.value = false
  showModal.value = true
}

function openEdit(item) {
  form.value = { ...emptyForm(), ...item }
  editing.value = true
  showModal.value = true
}

async function save() {
  try {
    const payload = { ...form.value }
    if (editing.value) {
      await api.put(`/regulations/${payload.id}`, payload)
      toast.success('Peraturan diperbarui')
    } else {
      await api.post('/regulations', payload)
      toast.success('Peraturan ditambahkan')
    }
    showModal.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: 'Hapus Peraturan',
    message: `Hapus klausul "${item.title}"?`,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/regulations/${item.id}`)
    toast.success('Berhasil dihapus')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

async function preview(category = '') {
  try {
    const res = await api.get('/regulations/preview', { params: { category } })
    previewHtml.value = res.data.data.html
    previewCategory.value = category
    showPreview.value = true
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal preview')
  }
}

function categoryLabel(v) {
  return categories.find(c => c.value === v)?.label || v
}

// Disimpan sebagai string biasa supaya parser Vue tidak menafsirkan tag HTML
// di dalam placeholder/teks petunjuk.
const placeholderHint = '<p>Tulis isi pasal di sini. Boleh pakai <ul>, <b>, <br>, dst.</p>'
const tagHints = ['<p>', '<ul><li>', '<b>']

function go(page) { params.page = page; fetchData() }
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Peraturan Perusahaan</h1>
      <div class="flex gap-2">
        <button class="btn-secondary" @click="preview('')">
          <EyeIcon class="w-4 h-4" /> Preview Semua
        </button>
        <button class="btn-primary" @click="openCreate">
          <PlusIcon class="w-4 h-4" /> Tambah Klausul
        </button>
      </div>
    </div>

    <div class="card">
      <div class="card-header flex flex-wrap gap-3 items-center">
        <div class="relative w-full md:w-64">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input v-model="params.search" placeholder="Cari klausul..." class="input pl-9" />
        </div>
        <select v-model="params.category" class="input w-full md:w-56">
          <option value="">— Semua Kategori —</option>
          <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
        </select>
        <button v-if="params.category" class="btn-secondary" @click="preview(params.category)">
          Preview Kategori
        </button>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th class="w-16">No.</th>
              <th>Kategori</th>
              <th>Kode</th>
              <th>Judul</th>
              <th class="w-28">Berlaku</th>
              <th class="w-24">Status</th>
              <th class="text-right w-32">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="7" class="text-center py-8 text-gray-400">Memuat...</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">Belum ada klausul</td></tr>
            <tr v-for="item in items" :key="item.id">
              <td class="text-gray-500">{{ item.order_no }}</td>
              <td>
                <span class="px-2 py-0.5 rounded-full text-xs bg-blue-50 text-blue-700">
                  {{ categoryLabel(item.category) }}
                </span>
              </td>
              <td class="text-gray-500 text-sm">{{ item.code || '-' }}</td>
              <td class="font-medium">{{ item.title }}</td>
              <td class="text-xs text-gray-500">
                <div v-if="item.effective_from">{{ item.effective_from }}</div>
                <div v-if="item.effective_to">s/d {{ item.effective_to }}</div>
              </td>
              <td>
                <span :class="[
                  'px-2 py-0.5 rounded-full text-xs',
                  item.is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'
                ]">
                  {{ item.is_active ? 'Aktif' : 'Non-aktif' }}
                </span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button class="text-gray-500 hover:text-primary-600" @click="openEdit(item)" title="Edit"><PencilIcon class="w-4 h-4" /></button>
                  <button class="text-gray-500 hover:text-red-600" @click="remove(item)" title="Hapus"><TrashIcon class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card-body flex items-center justify-between">
        <div class="text-sm text-gray-500">Total: {{ meta.total }}</div>
        <div class="flex gap-2">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="go(meta.page - 1)">Sebelumnya</button>
          <span class="px-3 py-2 text-sm">{{ meta.page }} / {{ meta.pages || 1 }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="go(meta.page + 1)">Berikutnya</button>
        </div>
      </div>
    </div>

    <!-- Modal Editor -->
    <div v-if="showModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white">
          <h2 class="font-semibold">{{ editing ? 'Edit' : 'Tambah' }} Peraturan</h2>
          <button class="text-gray-500" @click="showModal = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <form @submit.prevent="save" class="px-6 py-4 space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="md:col-span-1">
              <label class="label">Kategori *</label>
              <select v-model="form.category" class="input" required>
                <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
              </select>
            </div>
            <div class="md:col-span-1">
              <label class="label">Kode</label>
              <input v-model="form.code" class="input" placeholder="ex: PSL-1A" />
            </div>
            <div class="md:col-span-1">
              <label class="label">Urutan</label>
              <input v-model.number="form.order_no" type="number" class="input" />
            </div>
          </div>

          <div>
            <label class="label">Judul *</label>
            <input v-model="form.title" class="input" required placeholder="Contoh: Pasal 1 — Hari & Jam Kerja" />
          </div>

          <div>
            <label class="label">Isi Klausul (HTML didukung) *</label>
            <textarea v-model="form.body_html" class="input font-mono text-sm" rows="10"
              :placeholder="placeholderHint" required></textarea>
            <p class="text-xs text-gray-400 mt-1">
              Tip: gunakan tag HTML sederhana untuk format. Contoh:
              <code v-for="(t, i) in tagHints" :key="i" class="mr-1">{{ t }}</code>
            </p>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label class="label">Berlaku Mulai</label>
              <input v-model="form.effective_from" type="date" class="input" />
            </div>
            <div>
              <label class="label">Berlaku Sampai</label>
              <input v-model="form.effective_to" type="date" class="input" />
            </div>
            <div class="flex items-end">
              <label class="flex items-center gap-2">
                <input v-model="form.is_active" type="checkbox" :true-value="1" :false-value="0" class="w-4 h-4" />
                <span>Aktifkan klausul ini</span>
              </label>
            </div>
          </div>

          <div v-if="form.body_html" class="border border-gray-200 rounded-md p-3 bg-gray-50">
            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">Pratinjau</div>
            <div class="prose prose-sm max-w-none" v-html="form.body_html"></div>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showModal = false">Batal</button>
            <button type="submit" class="btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal Preview Semua -->
    <div v-if="showPreview" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white">
          <h2 class="font-semibold">
            Pratinjau Peraturan {{ previewCategory ? `— ${categoryLabel(previewCategory)}` : '(Semua)' }}
          </h2>
          <button class="text-gray-500" @click="showPreview = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <div class="px-6 py-4">
          <div class="prose max-w-none" v-html="previewHtml"></div>
        </div>
      </div>
    </div>
  </div>
</template>