<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Performance Management</h1>
        <p class="text-sm text-gray-600">Kelola siklus performa, KPI/OKR, dan review.</p>
      </div>
      <div class="flex gap-2">
        <router-link to="/performance/my"
          class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium">
          🎯 Goal & Review Saya
        </router-link>
        <button v-if="canManage" @click="openCreate"
          class="px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-medium">
          + Cycle Baru
        </button>
      </div>
    </div>

    <!-- Cycles list -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Kode / Nama</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Tipe</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Periode</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Status</th>
            <th class="px-3 py-2 text-right font-medium text-gray-700">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="c in cycles" :key="c.id" class="hover:bg-gray-50">
            <td class="px-3 py-2">
              <div class="font-mono text-xs text-gray-500">{{ c.code }}</div>
              <div class="font-medium text-gray-900">{{ c.name }}</div>
            </td>
            <td class="px-3 py-2 text-xs text-gray-700">{{ c.cycle_type }}</td>
            <td class="px-3 py-2 text-xs text-gray-700">
              {{ formatDate(c.start_date) }} – {{ formatDate(c.end_date) }}
            </td>
            <td class="px-3 py-2">
              <span class="px-2 py-0.5 rounded text-xs" :class="statusClass(c.status)">{{ c.status }}</span>
            </td>
            <td class="px-3 py-2 text-right space-x-1 whitespace-nowrap">
              <router-link :to="`/performance/cycles/${c.id}`"
                class="px-2 py-1 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 text-xs">
                Detail
              </router-link>
              <button v-if="canManage" @click="openStatus(c)"
                class="px-2 py-1 rounded bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs">
                Status
              </button>
            </td>
          </tr>
          <tr v-if="!cycles.length && !loading">
            <td colspan="5" class="px-3 py-12 text-center text-gray-500">Belum ada cycle.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal Create -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showModal = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-xl">
        <div class="px-5 py-3 border-b border-gray-200 flex justify-between items-center">
          <h2 class="font-bold text-gray-900">Cycle Baru</h2>
          <button @click="showModal = false" class="text-gray-400 hover:text-gray-600 text-xl">×</button>
        </div>
        <form @submit.prevent="save" class="p-5 space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Kode *</label>
              <input v-model="form.code" required maxlength="50"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                placeholder="mis. Q1-2026" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tipe *</label>
              <select v-model="form.cycle_type" required
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
                <option value="QUARTERLY">Quarterly</option>
                <option value="YEARLY">Yearly</option>
                <option value="MONTHLY">Monthly</option>
                <option value="CUSTOM">Custom</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nama *</label>
            <input v-model="form.name" required maxlength="200"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mulai *</label>
              <input v-model="form.start_date" type="date" required
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Selesai *</label>
              <input v-model="form.end_date" type="date" required
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Deadline Set Goal</label>
              <input v-model="form.goal_setting_deadline" type="date"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Periode Review</label>
              <input v-model="form.review_start_date" type="date"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
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

    <!-- Modal Status -->
    <div v-if="statusModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="statusModal.show = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b border-gray-200 flex justify-between items-center">
          <h2 class="font-bold text-gray-900">Ubah Status Cycle</h2>
          <button @click="statusModal.show = false" class="text-gray-400 hover:text-gray-600 text-xl">×</button>
        </div>
        <div class="p-5 space-y-3">
          <div class="text-sm text-gray-700">
            <span class="font-medium">{{ statusModal.cycle?.name }}</span>
            <div class="text-xs text-gray-500 mt-1">Status sekarang: <span class="font-medium">{{ statusModal.cycle?.status }}</span></div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status Baru</label>
            <select v-model="statusModal.newStatus"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
              <option value="DRAFT">Draft</option>
              <option value="OPEN">Open</option>
              <option value="GOAL_SETTING">Goal Setting</option>
              <option value="IN_PROGRESS">In Progress</option>
              <option value="REVIEW">Review</option>
              <option value="CLOSED">Closed</option>
              <option value="CANCELLED">Cancelled</option>
            </select>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="statusModal.show = false"
              class="px-4 py-2 rounded bg-gray-200 text-gray-700 hover:bg-gray-300">Batal</button>
            <button @click="saveStatus" :disabled="statusModal.saving"
              class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white disabled:opacity-50">
              {{ statusModal.saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const canManage = computed(() => auth.can('performance.manage'))

const cycles = ref([])
const loading = ref(false)
const showModal = ref(false)
const saving = ref(false)
const form = ref(blankForm())
const statusModal = ref({ show: false, cycle: null, newStatus: 'OPEN', saving: false })

function blankForm() {
  return {
    code: '', name: '', cycle_type: 'QUARTERLY',
    start_date: '', end_date: '',
    goal_setting_deadline: '', review_start_date: '', review_end_date: '',
    description: '',
  }
}

async function fetch() {
  loading.value = true
  try {
    const r = await api.get('/performance-cycles')
    cycles.value = r.data?.data || []
  } finally { loading.value = false }
}

function openCreate() {
  form.value = blankForm()
  showModal.value = true
}

async function save() {
  saving.value = true
  try {
    await api.post('/performance-cycles', form.value)
    showModal.value = false
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal menyimpan')
  } finally { saving.value = false }
}

function openStatus(c) {
  statusModal.value = { show: true, cycle: c, newStatus: c.status, saving: false }
}

async function saveStatus() {
  statusModal.value.saving = true
  try {
    await api.post(`/performance-cycles/${statusModal.value.cycle.id}/status`, { status: statusModal.value.newStatus })
    statusModal.value.show = false
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal')
  } finally { statusModal.value.saving = false }
}

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function statusClass(s) {
  return {
    DRAFT: 'bg-gray-100 text-gray-700',
    OPEN: 'bg-blue-100 text-blue-700',
    GOAL_SETTING: 'bg-purple-100 text-purple-700',
    IN_PROGRESS: 'bg-amber-100 text-amber-700',
    REVIEW: 'bg-orange-100 text-orange-700',
    CLOSED: 'bg-emerald-100 text-emerald-700',
    CANCELLED: 'bg-red-100 text-red-700',
  }[s] || 'bg-gray-100 text-gray-700'
}

onMounted(fetch)
</script>