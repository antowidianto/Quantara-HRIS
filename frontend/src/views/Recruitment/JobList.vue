<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { useRouter } from 'vue-router'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import {
  BriefcaseIcon, PlusIcon, PencilIcon, TrashIcon, EyeIcon,
  PlayIcon, XCircleIcon, GlobeAltIcon, ClipboardDocumentListIcon,
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const toast = useToast()
const router = useRouter()

const items = ref([])
const total = ref(0)
const loading = ref(false)
const filters = reactive({ q: '', status: '', page: 1, per_page: 20 })

const showForm = ref(false)
const editing = ref(null)

const STATUS_BADGE = {
  DRAFT:  { label: 'Draft',     cls: 'bg-gray-100 text-gray-600' },
  OPEN:   { label: 'Buka',      cls: 'bg-emerald-100 text-emerald-700' },
  PAUSED: { label: 'Pause',     cls: 'bg-amber-100 text-amber-700' },
  CLOSED: { label: 'Tutup',     cls: 'bg-gray-100 text-gray-500' },
  FILLED: { label: 'Terisi',    cls: 'bg-blue-100 text-blue-700' },
}

const EMPLOYMENT_TYPES = ['PKWT','PKWTT','MAGANG','OUTSOURCING','PARTTIME','CONTRACT']

const canManage = computed(() => auth.can('recruitment.manage'))

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/job-postings', { params: filters })
    items.value = res.data.data
    total.value = res.data.meta?.total || 0
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
  finally { loading.value = false }
}

onMounted(fetchData)

function openCreate() {
  editing.value = {
    title: '', employment_type: 'PKWTT', description: '', requirements: '',
    salary_min: 0, salary_max: 0, salary_visible: 0, headcount: 1,
    opened_at: '', closed_at: '', notes: '',
  }
  showForm.value = true
}

function openEdit(row) {
  editing.value = JSON.parse(JSON.stringify(row))
  showForm.value = true
}

async function save() {
  try {
    if (editing.value.id) {
      await api.put(`/job-postings/${editing.value.id}`, editing.value)
      toast.success('Diupdate')
    } else {
      await api.post('/job-postings', editing.value)
      toast.success('Posting dibuat')
    }
    showForm.value = false
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function openJob(row) {
  try {
    await api.post(`/job-postings/${row.id}/open`)
    toast.success('Posting dibuka')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function closeJob(row) {
  const ok = await confirmDialog({
    title: 'Tutup posting?',
    message: `${row.reference_number} akan ditutup. Kandidat existing tetap bisa diproses.`,
    okText: 'Tutup',
  })
  if (! ok) return
  try {
    await api.post(`/job-postings/${row.id}/close`)
    toast.success('Posting ditutup')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function publishCareer(row) {
  const ok = await confirmDialog({
    title: 'Publish ke Career Portal?',
    message: `Posting "${row.title}" akan tampil di halaman karier publik perusahaan.`,
    okText: 'Publish',
  })
  if (! ok) return
  try {
    const res = await api.post(`/job-postings/${row.id}/publish`)
    toast.success('Dipublish ke career portal')
    const url = res.data.data?.public_url
    if (url) {
      setTimeout(() => {
        if (confirm('Buka halaman public di tab baru?\n\n' + url)) {
          window.open(url, '_blank')
        }
      }, 200)
    }
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function unpublishCareer(row) {
  const ok = await confirmDialog({
    title: 'Unpublish dari Career Portal?',
    message: `Posting "${row.title}" tidak akan tampil di halaman karier publik. Pelamar baru tidak bisa apply.`,
    okText: 'Unpublish',
    variant: 'warning',
  })
  if (! ok) return
  try {
    await api.post(`/job-postings/${row.id}/unpublish`)
    toast.success('Di-unpublish dari career portal')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function remove(row) {
  const ok = await confirmDialog({
    title: 'Hapus posting?', message: row.title,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/job-postings/${row.id}`)
    toast.success('Dihapus')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

function viewPipeline(row) {
  router.push(`/recruitment/jobs/${row.id}`)
}

function applyFilter() { filters.page = 1; fetchData() }
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filters.per_page)))
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <BriefcaseIcon class="w-6 h-6 text-primary-600" />
          Recruitment / Lowongan Kerja
        </h1>
        <p class="text-sm text-gray-500">Kelola posting lowongan dan pipeline kandidat.</p>
      </div>
      <div class="flex items-center gap-2">
        <RouterLink to="/recruitment/mpp" class="btn-secondary"
          title="Lihat pengajuan kebutuhan SDM (MPP) yang menunggu untuk dibuatkan lowongan">
          <ClipboardDocumentListIcon class="w-4 h-4" /> Manpower Planning
        </RouterLink>
        <button v-if="canManage" class="btn-primary" @click="openCreate">
          <PlusIcon class="w-4 h-4" /> Posting Baru
        </button>
      </div>
    </div>

    <div class="card card-body mb-4">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div><label class="label">Cari</label>
          <input v-model="filters.q" class="input" placeholder="Title, ref, deskripsi..." @keydown.enter="applyFilter" /></div>
        <div><label class="label">Status</label>
          <select v-model="filters.status" class="input">
            <option value="">Semua</option>
            <option v-for="(b, k) in STATUS_BADGE" :key="k" :value="k">{{ b.label }}</option>
          </select></div>
        <div class="flex items-end"><button class="btn-secondary w-full" @click="applyFilter">Filter</button></div>
      </div>
    </div>

    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr><th>No</th><th>Posisi</th><th>Tipe</th><th>Headcount</th><th>Kandidat</th>
              <th>Status</th><th>Dibuka</th><th></th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="8" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="items.length === 0">
              <td colspan="8" class="text-center py-12 text-gray-400">Belum ada posting.</td>
            </tr>
            <tr v-for="r in items" :key="r.id" class="cursor-pointer" @click="viewPipeline(r)">
              <td class="font-mono text-xs">{{ r.reference_number }}</td>
              <td>
                <div class="font-medium">{{ r.title }}</div>
                <div class="text-xs text-gray-500">{{ r.department_name || '-' }} · {{ r.branch_name || 'Semua cabang' }}</div>
              </td>
              <td class="text-xs">{{ r.employment_type }}</td>
              <td class="text-center font-mono">{{ r.headcount }}</td>
              <td class="text-center">
                <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs">{{ r.candidate_count || 0 }}</span>
              </td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE[r.status]?.cls">
                  {{ STATUS_BADGE[r.status]?.label || r.status }}
                </span>
                <span v-if="r.is_published_to_career"
                  class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-indigo-100 text-indigo-700 inline-block"
                  title="Tampil di public Career Portal">
                  🌐 Career
                </span>
              </td>
              <td class="text-xs">{{ r.opened_at ? fmtDate(r.opened_at) : '-' }}</td>
              <td class="text-right" @click.stop>
                <div class="flex items-center justify-end gap-1">
                  <button class="text-blue-600 hover:text-blue-800 p-1" title="Detail" @click="viewPipeline(r)">
                    <EyeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && ['DRAFT','PAUSED'].includes(r.status)"
                    class="text-emerald-600 hover:text-emerald-800 p-1" title="Buka" @click="openJob(r)">
                    <PlayIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && ['OPEN','PAUSED','FILLED'].includes(r.status)"
                    class="text-gray-600 hover:text-gray-800 p-1" title="Tutup" @click="closeJob(r)">
                    <XCircleIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && r.status === 'OPEN' && ! r.is_published_to_career"
                    class="text-indigo-600 hover:text-indigo-800 p-1" title="Publish ke Career Portal" @click="publishCareer(r)">
                    <GlobeAltIcon class="w-4 h-4" />
                  </button>
                  <button v-else-if="canManage && r.is_published_to_career"
                    class="text-emerald-600 hover:text-emerald-800 p-1" title="Sudah dipublish (klik untuk unpublish)" @click="unpublishCareer(r)">
                    <GlobeAltIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage" class="text-gray-600 hover:text-primary-600 p-1" title="Edit" @click="openEdit(r)">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage" class="text-red-600 hover:text-red-800 p-1" title="Hapus" @click="remove(r)">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="totalPages > 1" class="px-4 py-3 flex items-center justify-between border-t text-sm">
        <span class="text-gray-500">{{ total }} posting · halaman {{ filters.page }} / {{ totalPages }}</span>
        <div class="flex gap-1">
          <button :disabled="filters.page <= 1" class="btn-secondary px-3 py-1 text-sm" @click="filters.page--; fetchData()">Prev</button>
          <button :disabled="filters.page >= totalPages" class="btn-secondary px-3 py-1 text-sm" @click="filters.page++; fetchData()">Next</button>
        </div>
      </div>
    </div>

    <!-- Form modal -->
    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">{{ editing.id ? 'Edit Posting' : 'Posting Baru' }}</h2>
          <button class="text-gray-500" @click="showForm = false">&times;</button>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="md:col-span-2">
            <label class="label">Judul Posisi *</label>
            <input v-model="editing.title" class="input" placeholder="mis. Senior Backend Developer" />
          </div>
          <div>
            <label class="label">Tipe Pekerjaan</label>
            <select v-model="editing.employment_type" class="input">
              <option v-for="t in EMPLOYMENT_TYPES" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div>
            <label class="label">Headcount</label>
            <input type="number" v-model="editing.headcount" class="input" min="1" />
          </div>
          <div>
            <label class="label">Tgl Buka</label>
            <input type="date" v-model="editing.opened_at" class="input" />
          </div>
          <div>
            <label class="label">Tgl Tutup (deadline)</label>
            <input type="date" v-model="editing.closed_at" class="input" />
          </div>
          <div>
            <label class="label">Salary Min (Rp)</label>
            <input type="number" v-model="editing.salary_min" class="input" />
          </div>
          <div>
            <label class="label">Salary Max (Rp)</label>
            <input type="number" v-model="editing.salary_max" class="input" />
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input type="checkbox" v-model="editing.salary_visible" :true-value="1" :false-value="0" />
              Tampilkan range gaji ke kandidat
            </label>
          </div>
          <div class="md:col-span-2">
            <label class="label">Deskripsi Pekerjaan</label>
            <textarea v-model="editing.description" rows="4" class="input"></textarea>
          </div>
          <div class="md:col-span-2">
            <label class="label">Persyaratan Kandidat</label>
            <textarea v-model="editing.requirements" rows="3" class="input"></textarea>
          </div>
          <div class="md:col-span-2">
            <label class="label">Catatan internal</label>
            <textarea v-model="editing.notes" rows="2" class="input"></textarea>
          </div>
          <p class="md:col-span-2 text-xs text-gray-500">
            Posting dibuat sebagai DRAFT. Klik tombol "Buka" di list untuk publish.
          </p>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showForm = false">Batal</button>
          <button class="btn-primary" @click="save">Simpan</button>
        </div>
      </div>
    </div>
  </div>
</template>