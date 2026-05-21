<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { fmtDate } from '@/composables/useResource'
import {
  TicketIcon, PlusIcon, EyeIcon, ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const items = ref([])
const total = ref(0)
const stats = ref({ OPEN: 0, IN_PROGRESS: 0, ANSWERED: 0, CLOSED: 0, REOPENED: 0, overdue: 0, total: 0 })
const loading = ref(false)
const filters = reactive({ q: '', status: '', category: '', mine: 0, page: 1, per_page: 20 })

const showCreate = ref(false)
const form = reactive({
  subject: '', body: '', category: 'OTHER', priority: 'MEDIUM',
})

const STATUS_BADGE = {
  OPEN:        { label: 'Buka',         cls: 'bg-emerald-100 text-emerald-700' },
  IN_PROGRESS: { label: 'Dikerjakan',   cls: 'bg-blue-100 text-blue-700' },
  ANSWERED:    { label: 'Dibalas',      cls: 'bg-purple-100 text-purple-700' },
  CLOSED:      { label: 'Selesai',      cls: 'bg-gray-100 text-gray-500' },
  REOPENED:    { label: 'Dibuka ulang', cls: 'bg-amber-100 text-amber-700' },
}
const PRIORITY_BADGE = {
  URGENT: { label: '🔴 Urgent', cls: 'bg-red-100 text-red-700' },
  HIGH:   { label: '🟠 Tinggi', cls: 'bg-orange-100 text-orange-700' },
  MEDIUM: { label: '🟡 Sedang', cls: 'bg-amber-100 text-amber-700' },
  LOW:    { label: '🟢 Rendah', cls: 'bg-emerald-100 text-emerald-700' },
}
const CATEGORIES = ['PAYROLL','LEAVE','ATTENDANCE','BENEFITS','CONTRACT','IT','FACILITY','OTHER']
const CATEGORY_LABEL = {
  PAYROLL: 'Penggajian', LEAVE: 'Cuti', ATTENDANCE: 'Absensi',
  BENEFITS: 'Tunjangan', CONTRACT: 'Kontrak', IT: 'IT/Sistem',
  FACILITY: 'Fasilitas', OTHER: 'Lainnya',
}

const canViewAll = computed(() => auth.can('helpdesk.view_all'))

async function fetchData() {
  loading.value = true
  try {
    const [list, st] = await Promise.all([
      api.get('/helpdesk/tickets', { params: filters }),
      api.get('/helpdesk/stats'),
    ])
    items.value = list.data.data
    total.value = list.data.meta?.total || 0
    stats.value = st.data.data
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
  finally { loading.value = false }
}

onMounted(fetchData)

function openCreate() {
  Object.assign(form, { subject: '', body: '', category: 'OTHER', priority: 'MEDIUM' })
  showCreate.value = true
}

async function save() {
  if (form.subject.length < 5) { toast.error('Subject min 5 karakter'); return }
  if (form.body.length < 10) { toast.error('Body min 10 karakter'); return }
  try {
    const res = await api.post('/helpdesk/tickets', form)
    toast.success(`Tiket dibuat: ${res.data.data.ticket_number}`)
    showCreate.value = false
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

function openDetail(row) {
  router.push(`/helpdesk/tickets/${row.id}`)
}

function applyFilter() { filters.page = 1; fetchData() }
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filters.per_page)))

function isOverdue(row) {
  if (! row.sla_due_at) return false
  if (['CLOSED'].includes(row.status)) return false
  return new Date(row.sla_due_at) < new Date()
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <TicketIcon class="w-6 h-6 text-primary-600" />
          HR Helpdesk
        </h1>
        <p class="text-sm text-gray-500">Tiket pertanyaan & request ke tim HR.</p>
      </div>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Tiket Baru
      </button>
    </div>

    <!-- Stats cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
      <div class="card card-body text-center">
        <div class="text-xs text-gray-500">Total</div>
        <div class="text-2xl font-bold">{{ stats.total || 0 }}</div>
      </div>
      <div class="card card-body text-center">
        <div class="text-xs text-emerald-600">Buka</div>
        <div class="text-2xl font-bold text-emerald-600">{{ stats.OPEN || 0 }}</div>
      </div>
      <div class="card card-body text-center">
        <div class="text-xs text-blue-600">Dikerjakan</div>
        <div class="text-2xl font-bold text-blue-600">{{ stats.IN_PROGRESS || 0 }}</div>
      </div>
      <div class="card card-body text-center">
        <div class="text-xs text-gray-500">Selesai</div>
        <div class="text-2xl font-bold text-gray-500">{{ stats.CLOSED || 0 }}</div>
      </div>
      <div class="card card-body text-center" :class="stats.overdue > 0 ? 'ring-1 ring-red-300' : ''">
        <div class="text-xs text-red-600 flex items-center justify-center gap-1">
          <ExclamationTriangleIcon class="w-3 h-3" /> Overdue
        </div>
        <div class="text-2xl font-bold text-red-600">{{ stats.overdue || 0 }}</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="card card-body mb-4">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div>
          <label class="label">Cari</label>
          <input v-model="filters.q" class="input" placeholder="Subject, ref, body..." @keydown.enter="applyFilter" />
        </div>
        <div>
          <label class="label">Status</label>
          <select v-model="filters.status" class="input">
            <option value="">Semua</option>
            <option v-for="(b, k) in STATUS_BADGE" :key="k" :value="k">{{ b.label }}</option>
          </select>
        </div>
        <div>
          <label class="label">Kategori</label>
          <select v-model="filters.category" class="input">
            <option value="">Semua</option>
            <option v-for="c in CATEGORIES" :key="c" :value="c">{{ CATEGORY_LABEL[c] }}</option>
          </select>
        </div>
        <div v-if="canViewAll">
          <label class="label">Tampilkan</label>
          <select v-model="filters.mine" class="input">
            <option :value="0">Semua tiket</option>
            <option :value="1">Hanya milik saya</option>
          </select>
        </div>
        <div class="flex items-end"><button class="btn-secondary w-full" @click="applyFilter">Filter</button></div>
      </div>
    </div>

    <!-- List -->
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>No</th>
              <th>Subject</th>
              <th>Pelapor</th>
              <th>Kategori</th>
              <th>Prioritas</th>
              <th>Status</th>
              <th>SLA</th>
              <th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="8" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="items.length === 0">
              <td colspan="8" class="text-center py-12 text-gray-400">Belum ada tiket.</td>
            </tr>
            <tr v-for="r in items" :key="r.id"
              class="cursor-pointer hover:bg-gray-50"
              :class="isOverdue(r) ? 'bg-red-50/50' : ''"
              @click="openDetail(r)">
              <td class="font-mono text-xs">{{ r.ticket_number }}</td>
              <td>
                <div class="font-medium">{{ r.subject }}</div>
                <div class="text-xs text-gray-500 line-clamp-1">{{ r.body }}</div>
              </td>
              <td class="text-sm">
                {{ r.employee_name }}
                <div class="text-xs text-gray-500">{{ r.employee_number }}</div>
              </td>
              <td class="text-xs">{{ CATEGORY_LABEL[r.category] || r.category }}</td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="PRIORITY_BADGE[r.priority]?.cls">
                  {{ PRIORITY_BADGE[r.priority]?.label || r.priority }}
                </span>
              </td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE[r.status]?.cls">
                  {{ STATUS_BADGE[r.status]?.label || r.status }}
                </span>
              </td>
              <td class="text-xs">
                <span v-if="isOverdue(r)" class="text-red-600 font-medium">Lewat SLA</span>
                <span v-else-if="r.sla_due_at" class="text-gray-500">{{ fmtDate(r.sla_due_at) }}</span>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="text-right">
                <button class="text-blue-600 hover:text-blue-800 p-1" @click.stop="openDetail(r)">
                  <EyeIcon class="w-4 h-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="totalPages > 1" class="px-4 py-3 flex items-center justify-between border-t text-sm">
        <span class="text-gray-500">{{ total }} tiket · halaman {{ filters.page }} / {{ totalPages }}</span>
        <div class="flex gap-1">
          <button :disabled="filters.page <= 1" class="btn-secondary px-3 py-1 text-sm" @click="filters.page--; fetchData()">Prev</button>
          <button :disabled="filters.page >= totalPages" class="btn-secondary px-3 py-1 text-sm" @click="filters.page++; fetchData()">Next</button>
        </div>
      </div>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Tiket Baru</h2>
          <button class="text-gray-500" @click="showCreate = false">&times;</button>
        </div>
        <div class="p-6 space-y-3">
          <div>
            <label class="label">Subject *</label>
            <input v-model="form.subject" class="input" placeholder="Min 5 karakter, ringkasan masalah" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Kategori *</label>
              <select v-model="form.category" class="input">
                <option v-for="c in CATEGORIES" :key="c" :value="c">{{ CATEGORY_LABEL[c] }}</option>
              </select>
            </div>
            <div>
              <label class="label">Prioritas</label>
              <select v-model="form.priority" class="input">
                <option v-for="(b, k) in PRIORITY_BADGE" :key="k" :value="k">{{ b.label }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="label">Body *</label>
            <textarea v-model="form.body" rows="6" class="input"
              placeholder="Jelaskan masalah/pertanyaan dengan detail. Min 10 karakter."></textarea>
            <p class="text-xs text-gray-500 mt-1">SLA otomatis: URGENT 4j · HIGH 24j · MEDIUM 72j · LOW 1 minggu.</p>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showCreate = false">Batal</button>
          <button class="btn-primary" @click="save">Kirim Tiket</button>
        </div>
      </div>
    </div>
  </div>
</template>