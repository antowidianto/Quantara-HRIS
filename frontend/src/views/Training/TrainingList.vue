<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Training & Pengembangan</h1>
        <p class="text-sm text-gray-600">Kelola jadwal training, peserta, dan sertifikasi.</p>
      </div>
      <div class="flex gap-2">
        <router-link to="/certificates"
          class="px-3 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-medium hover:bg-gray-200">
          📜 Sertifikat
        </router-link>
        <button v-if="canManage" @click="openCreate"
          class="px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-medium">
          + Training Baru
        </button>
      </div>
    </div>

    <!-- Stats Dashboard -->
    <div v-if="stats" class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <div class="bg-white rounded-lg p-3 border border-gray-200">
        <div class="text-xs text-gray-500">Total Training</div>
        <div class="text-2xl font-bold text-gray-900">{{ stats.total_trainings }}</div>
      </div>
      <div class="bg-white rounded-lg p-3 border border-gray-200">
        <div class="text-xs text-gray-500">Peserta Aktif</div>
        <div class="text-2xl font-bold text-blue-600">{{ stats.active_registrations }}</div>
      </div>
      <div class="bg-white rounded-lg p-3 border border-gray-200">
        <div class="text-xs text-gray-500">Selesai</div>
        <div class="text-2xl font-bold text-emerald-600">{{ stats.completed_registrations }}</div>
      </div>
      <div class="bg-white rounded-lg p-3 border border-gray-200">
        <div class="text-xs text-gray-500">Sertifikat Aktif</div>
        <div class="text-2xl font-bold text-purple-600">{{ stats.active_certificates }}</div>
      </div>
      <div class="bg-white rounded-lg p-3 border border-gray-200"
        :class="{ 'ring-2 ring-amber-300': stats.expiring_certificates > 0 }">
        <div class="text-xs text-gray-500">Expire ≤30 hari</div>
        <div class="text-2xl font-bold" :class="stats.expiring_certificates > 0 ? 'text-amber-600' : 'text-gray-400'">
          {{ stats.expiring_certificates }}
        </div>
      </div>
    </div>

    <div v-if="stats?.budget" class="bg-white rounded-lg p-4 border border-gray-200">
      <div class="flex items-center justify-between mb-2">
        <div class="text-sm font-medium text-gray-700">Budget Training {{ stats.year }}</div>
        <div class="text-xs text-gray-500">Tersedia: <span class="font-bold text-emerald-600">{{ formatCurrency(stats.budget.available) }}</span></div>
      </div>
      <div class="flex h-3 rounded-full overflow-hidden bg-gray-200">
        <div class="bg-emerald-500" :style="{ width: budgetPercent('used') + '%' }"></div>
        <div class="bg-amber-400" :style="{ width: budgetPercent('reserved') + '%' }"></div>
      </div>
      <div class="flex justify-between mt-1 text-xs text-gray-500">
        <span>Terpakai {{ formatCurrency(stats.budget.used) }}</span>
        <span>Reserved {{ formatCurrency(stats.budget.reserved) }}</span>
        <span>Alokasi {{ formatCurrency(stats.budget.allocated) }}</span>
      </div>
    </div>

    <!-- Filter -->
    <div class="bg-white rounded-lg p-3 border border-gray-200 flex flex-wrap gap-2 items-center">
      <input v-model="filters.q" @keyup.enter="fetch" placeholder="Cari judul/kode/penyelenggara..."
        class="flex-1 min-w-[200px] px-3 py-2 rounded-lg border border-gray-300 text-sm focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
      <select v-model="filters.status" @change="fetch"
        class="px-3 py-2 rounded-lg border border-gray-300 text-sm focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
        <option value="">Semua Status</option>
        <option value="DRAFT">Draft</option>
        <option value="PUBLISHED">Published</option>
        <option value="ONGOING">Ongoing</option>
        <option value="COMPLETED">Completed</option>
        <option value="CANCELLED">Cancelled</option>
      </select>
      <select v-model="filters.category" @change="fetch"
        class="px-3 py-2 rounded-lg border border-gray-300 text-sm focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
        <option value="">Semua Kategori</option>
        <option value="TECHNICAL">Technical</option>
        <option value="SOFT_SKILL">Soft Skill</option>
        <option value="COMPLIANCE">Compliance</option>
        <option value="SAFETY">Safety</option>
        <option value="LEADERSHIP">Leadership</option>
        <option value="OTHER">Other</option>
      </select>
    </div>

    <!-- List -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Kode / Judul</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Kategori</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Tanggal</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Peserta</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Biaya</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Status</th>
            <th class="px-3 py-2 text-right font-medium text-gray-700">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="t in items" :key="t.id" class="hover:bg-gray-50">
            <td class="px-3 py-2">
              <div class="font-mono text-xs text-gray-500">{{ t.code }}</div>
              <div class="font-medium text-gray-900">{{ t.title }}</div>
              <div v-if="t.provider" class="text-xs text-gray-500">{{ t.provider }}</div>
            </td>
            <td class="px-3 py-2">
              <span class="px-2 py-0.5 rounded text-xs" :class="categoryClass(t.category)">{{ t.category }}</span>
            </td>
            <td class="px-3 py-2 text-xs text-gray-700">
              <div>{{ formatDate(t.start_date) }}</div>
              <div class="text-gray-500">s.d. {{ formatDate(t.end_date) }}</div>
            </td>
            <td class="px-3 py-2 text-gray-700">
              <span class="font-medium">{{ t.participant_count || 0 }}</span>
              <span v-if="t.max_participants > 0" class="text-gray-500"> / {{ t.max_participants }}</span>
            </td>
            <td class="px-3 py-2 text-xs text-gray-700">{{ formatCurrency(t.cost_per_participant) }}</td>
            <td class="px-3 py-2">
              <span class="px-2 py-0.5 rounded text-xs" :class="statusClass(t.status)">{{ statusLabel(t.status) }}</span>
            </td>
            <td class="px-3 py-2 text-right space-x-1 whitespace-nowrap">
              <router-link :to="`/training/${t.id}`"
                class="px-2 py-1 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 text-xs">
                Detail
              </router-link>
              <button v-if="canManage && t.status === 'DRAFT'" @click="publish(t)"
                class="px-2 py-1 rounded bg-emerald-100 hover:bg-emerald-200 text-emerald-700 text-xs">
                Publish
              </button>
            </td>
          </tr>
          <tr v-if="!items.length && !loading">
            <td colspan="7" class="px-3 py-12 text-center text-gray-500">Belum ada training.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal Create -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showModal = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b border-gray-200 flex justify-between items-center">
          <h2 class="font-bold text-gray-900">Training Baru</h2>
          <button @click="showModal = false" class="text-gray-400 hover:text-gray-600 text-xl">×</button>
        </div>
        <form @submit.prevent="save" class="p-5 space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Judul *</label>
            <input v-model="form.title" required maxlength="200"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Kategori *</label>
              <select v-model="form.category" required
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
                <option value="TECHNICAL">Technical</option>
                <option value="SOFT_SKILL">Soft Skill</option>
                <option value="COMPLIANCE">Compliance</option>
                <option value="SAFETY">Safety / K3</option>
                <option value="LEADERSHIP">Leadership</option>
                <option value="OTHER">Other</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tipe</label>
              <select v-model="form.training_type"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
                <option value="INTERNAL">Internal</option>
                <option value="EXTERNAL">External</option>
                <option value="ONLINE">Online</option>
                <option value="HYBRID">Hybrid</option>
              </select>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal Mulai *</label>
              <input v-model="form.start_date" type="date" required
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal Selesai *</label>
              <input v-model="form.end_date" type="date" required
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Durasi (jam)</label>
              <input v-model.number="form.duration_hours" type="number" min="0" step="0.5"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Max Peserta</label>
              <input v-model.number="form.max_participants" type="number" min="0"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                placeholder="0 = unlimited" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Biaya/Peserta</label>
              <input v-model.number="form.cost_per_participant" type="number" min="0"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Penyelenggara</label>
            <input v-model="form.provider"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
              placeholder="Nama vendor / lembaga" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Lokasi</label>
            <input v-model="form.location"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
          </div>
          <div class="bg-gray-50 rounded p-3 space-y-3 border border-gray-200">
            <div class="flex items-center gap-2">
              <input v-model="form.issues_certificate" type="checkbox" id="cert" class="w-4 h-4" />
              <label for="cert" class="text-sm font-medium text-gray-700">Mengeluarkan sertifikat</label>
            </div>
            <div v-if="form.issues_certificate" class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs text-gray-700 mb-1">Berlaku (bulan)</label>
                <input v-model.number="form.certificate_validity_months" type="number" min="0"
                  class="w-full px-3 py-1.5 rounded border border-gray-300 text-sm"
                  placeholder="kosong = lifetime" />
              </div>
              <div>
                <label class="block text-xs text-gray-700 mb-1">Skor lulus min.</label>
                <input v-model.number="form.passing_score" type="number" min="0" max="100"
                  class="w-full px-3 py-1.5 rounded border border-gray-300 text-sm"
                  placeholder="0-100" />
              </div>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Deskripsi</label>
            <textarea v-model="form.description" rows="3"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="showModal = false"
              class="px-4 py-2 rounded bg-gray-200 text-gray-700 hover:bg-gray-300">Batal</button>
            <button type="submit" :disabled="saving"
              class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white disabled:opacity-50">
              {{ saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const canManage = computed(() => auth.can('training.manage'))

const items = ref([])
const stats = ref(null)
const loading = ref(false)
const filters = ref({ q: '', status: '', category: '' })

const showModal = ref(false)
const saving = ref(false)
const form = ref(blankForm())

function blankForm() {
  return {
    title: '', description: '',
    category: 'TECHNICAL', training_type: 'INTERNAL',
    provider: '', location: '',
    start_date: '', end_date: '',
    duration_hours: 0, max_participants: 0, cost_per_participant: 0,
    issues_certificate: false, certificate_validity_months: null, passing_score: null,
  }
}

async function fetch() {
  loading.value = true
  try {
    const params = { per_page: 50 }
    if (filters.value.q) params.q = filters.value.q
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.category) params.category = filters.value.category
    const r = await api.get('/trainings', { params })
    items.value = r.data?.data || []
  } finally {
    loading.value = false
  }
}

async function fetchStats() {
  try {
    const r = await api.get('/trainings-stats')
    stats.value = r.data?.data
  } catch (e) {
    stats.value = null
  }
}

function openCreate() {
  form.value = blankForm()
  showModal.value = true
}

async function save() {
  saving.value = true
  try {
    await api.post('/trainings', form.value)
    showModal.value = false
    await fetch()
    await fetchStats()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal menyimpan')
  } finally {
    saving.value = false
  }
}

async function publish(t) {
  if (! confirm(`Publish training "${t.title}"? Setelah dipublish, karyawan bisa mendaftar.`)) return
  try {
    await api.post(`/trainings/${t.id}/publish`)
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal publish')
  }
}

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatCurrency(n) {
  return 'Rp ' + Number(n || 0).toLocaleString('id-ID')
}

function budgetPercent(field) {
  const total = Number(stats.value?.budget?.allocated || 0)
  if (total === 0) return 0
  return Math.min(100, (Number(stats.value.budget[field]) / total) * 100)
}

function statusLabel(s) {
  return { DRAFT: 'Draft', PUBLISHED: 'Published', ONGOING: 'Berlangsung', COMPLETED: 'Selesai', CANCELLED: 'Batal' }[s] || s
}

function statusClass(s) {
  return {
    DRAFT: 'bg-gray-100 text-gray-700',
    PUBLISHED: 'bg-blue-100 text-blue-700',
    ONGOING: 'bg-amber-100 text-amber-700',
    COMPLETED: 'bg-emerald-100 text-emerald-700',
    CANCELLED: 'bg-red-100 text-red-700',
  }[s] || 'bg-gray-100 text-gray-700'
}

function categoryClass(c) {
  return {
    TECHNICAL: 'bg-purple-100 text-purple-700',
    SOFT_SKILL: 'bg-pink-100 text-pink-700',
    COMPLIANCE: 'bg-blue-100 text-blue-700',
    SAFETY: 'bg-red-100 text-red-700',
    LEADERSHIP: 'bg-amber-100 text-amber-700',
  }[c] || 'bg-gray-100 text-gray-700'
}

onMounted(() => {
  fetch()
  fetchStats()
})
</script>