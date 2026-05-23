<script setup>
/**
 * AssignmentList — HR page untuk assign test ke kandidat dan memonitor status.
 */
import { ref, onMounted, computed } from 'vue'
import { useToast } from 'vue-toastification'
import api from '@/api/client'

const toast = useToast()
const loading = ref(true)
const assignments = ref([])
const candidates = ref([])
const tests = ref([])
const showAssign = ref(false)
const creating = ref(false)

const filters = ref({
  status: '',
  candidate_id: '',
  online_test_id: '',
})

const form = ref({
  online_test_id: '',
  candidate_id: '',
  available_from: '',
  due_at: '',
  max_attempts: 1,
  notes: '',
  advance_stage: '',
})

const STAGE_OPTIONS = [
  { value: '', label: 'Jangan pindahkan stage' },
  { value: 'ONLINE_TEST', label: 'Tes Online' },
  { value: 'INTERVIEW_HR', label: 'Interview HR' },
  { value: 'INTERVIEW_USER', label: 'Interview User' },
  { value: 'OFFER', label: 'Offering' },
]

onMounted(async () => {
  await Promise.all([loadAssignments(), loadCandidates(), loadTests()])
  loading.value = false
})

async function loadAssignments() {
  try {
    const res = await api.get('/online-test-assignments', { params: filters.value })
    assignments.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat assignment')
  }
}

async function loadCandidates() {
  try {
    const res = await api.get('/candidates', { params: { per_page: 100 } })
    candidates.value = res.data.data || []
  } catch (_) {}
}

async function loadTests() {
  try {
    const res = await api.get('/online-tests', { params: { per_page: 100 } })
    tests.value = res.data.data || []
  } catch (_) {}
}

async function createAssignment() {
  creating.value = true
  try {
    const payload = {
      ...form.value,
      online_test_id: Number(form.value.online_test_id),
      candidate_id: Number(form.value.candidate_id),
      max_attempts: Number(form.value.max_attempts),
    }
    await api.post('/online-test-assignments', payload)
    toast.success('Test berhasil ditugaskan ke kandidat')
    showAssign.value = false
    resetForm()
    await loadAssignments()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal membuat assignment')
  } finally {
    creating.value = false
  }
}

async function cancelAssignment(id) {
  if (!confirm('Batalkan assignment ini?')) return
  try {
    await api.post(`/online-test-assignments/${id}/cancel`)
    toast.success('Assignment dibatalkan')
    await loadAssignments()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal membatalkan')
  }
}

const statusLabel = (s) => ({
  PENDING: 'Menunggu',
  IN_PROGRESS: 'Sedang Dikerjakan',
  COMPLETED: 'Selesai',
  EXPIRED: 'Kadaluarsa',
  CANCELLED: 'Dibatalkan',
}[s] || s)

const statusColor = (s) => ({
  PENDING: 'bg-amber-100 text-amber-700',
  IN_PROGRESS: 'bg-blue-100 text-blue-700',
  COMPLETED: 'bg-emerald-100 text-emerald-700',
  EXPIRED: 'bg-rose-100 text-rose-700',
  CANCELLED: 'bg-slate-100 text-slate-500',
}[s] || 'bg-slate-100 text-slate-700')

function resetForm() {
  form.value = {
    online_test_id: '',
    candidate_id: '',
    available_from: '',
    due_at: '',
    max_attempts: 1,
    notes: '',
    advance_stage: '',
  }
}
</script>

<template>
  <div class="max-w-6xl mx-auto py-6 px-4">
    <div class="flex items-center justify-between mb-5">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Penugasan Online Test</h1>
        <p class="text-sm text-slate-500">Assign test ke kandidat yang lolos screening.</p>
      </div>
      <button @click="showAssign = true"
        class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
        + Tugaskan Test
      </button>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl p-4 mb-4 grid grid-cols-1 md:grid-cols-4 gap-3">
      <select v-model="filters.status" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Semua status</option>
        <option value="PENDING">PENDING</option>
        <option value="IN_PROGRESS">IN_PROGRESS</option>
        <option value="COMPLETED">COMPLETED</option>
        <option value="EXPIRED">EXPIRED</option>
        <option value="CANCELLED">CANCELLED</option>
      </select>
      <select v-model="filters.candidate_id" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Semua kandidat</option>
        <option v-for="c in candidates" :key="c.id" :value="c.id">{{ c.full_name }} — {{ c.job_title || c.email }}</option>
      </select>
      <select v-model="filters.online_test_id" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Semua test</option>
        <option v-for="t in tests" :key="t.id" :value="t.id">{{ t.name }}</option>
      </select>
      <button @click="loadAssignments" class="px-4 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">
        Filter
      </button>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-400">Memuat...</div>

    <div v-else class="space-y-3">
      <div v-for="a in assignments" :key="a.id" class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="flex items-start justify-between gap-3">
          <div class="flex-1">
            <div class="flex items-center gap-2">
              <div class="font-semibold text-slate-900">{{ a.test_name }}</div>
              <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="statusColor(a.status)">
                {{ statusLabel(a.status) }}
              </span>
            </div>
            <div class="text-sm text-slate-600 mt-1">
              Kandidat: <strong>{{ a.candidate_name }}</strong>
              <span class="text-slate-400">· {{ a.job_title || a.job_ref || '-' }}</span>
            </div>
            <div class="text-xs text-slate-500 mt-1 space-x-3">
              <span>Available: {{ a.available_from || '-' }}</span>
              <span>Due: {{ a.due_at || '-' }}</span>
              <span>Attempt: {{ a.attempts_used }}/{{ a.max_attempts }}</span>
              <span v-if="a.best_percentage !== null && a.best_percentage !== undefined">Best: {{ a.best_percentage }}%</span>
              <span v-if="a.best_result">Result: {{ a.best_result }}</span>
            </div>
            <div v-if="a.notes" class="text-xs text-slate-500 mt-1 italic">{{ a.notes }}</div>
          </div>
          <div class="flex gap-2">
            <router-link
              v-if="a.status === 'COMPLETED'"
              :to="{ name: 'online-test-attempt-review', params: { id: a.id } }"
              class="px-3 py-1.5 border border-indigo-300 text-indigo-700 rounded-md text-sm hover:bg-indigo-50">
              Review
            </router-link>
            <button
              v-if="['PENDING','IN_PROGRESS'].includes(a.status)"
              @click="cancelAssignment(a.id)"
              class="px-3 py-1.5 border border-rose-200 text-rose-600 rounded-md text-sm hover:bg-rose-50">
              Batalkan
            </button>
          </div>
        </div>
      </div>

      <div v-if="assignments.length === 0" class="text-center py-12 text-slate-400 bg-slate-50 rounded-xl border-2 border-dashed border-slate-200">
        Belum ada assignment test.
      </div>
    </div>

    <!-- Assign modal -->
    <div v-if="showAssign" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showAssign = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6">
        <h2 class="text-lg font-bold mb-4">Tugaskan Test ke Kandidat</h2>
        <div class="space-y-3 text-sm">
          <div>
            <label class="block text-xs font-medium mb-1">Test</label>
            <select v-model="form.online_test_id" class="w-full px-3 py-2 border rounded-lg">
              <option value="">— Pilih Test —</option>
              <option v-for="t in tests" :key="t.id" :value="t.id">{{ t.name }} · {{ t.category }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium mb-1">Kandidat</label>
            <select v-model="form.candidate_id" class="w-full px-3 py-2 border rounded-lg">
              <option value="">— Pilih Kandidat —</option>
              <option v-for="c in candidates" :key="c.id" :value="c.id">
                {{ c.full_name }} — {{ c.job_title || c.email }}
              </option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium mb-1">Available From</label>
              <input v-model="form.available_from" type="datetime-local" class="w-full px-3 py-2 border rounded-lg" />
            </div>
            <div>
              <label class="block text-xs font-medium mb-1">Due At</label>
              <input v-model="form.due_at" type="datetime-local" class="w-full px-3 py-2 border rounded-lg" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium mb-1">Max Attempts</label>
              <input v-model.number="form.max_attempts" type="number" min="1" max="5" class="w-full px-3 py-2 border rounded-lg" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium mb-1">Catatan (opsional)</label>
            <textarea v-model="form.notes" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea>
          </div>
          <div>
            <label class="block text-xs font-medium mb-1">Pindahkan ke Stage</label>
            <select v-model="form.advance_stage" class="w-full px-3 py-2 border rounded-lg text-sm">
              <option v-for="opt in STAGE_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
            <p class="text-xs text-slate-400 mt-1">Stage kandidat akan otomatis maju ke tahap ini setelah ditugaskan test</p>
          </div>
        </div>

        <div class="flex justify-end gap-2 mt-5">
          <button @click="showAssign = false; resetForm()" class="px-4 py-2 border rounded-lg text-sm">Batal</button>
          <button @click="createAssignment" :disabled="creating || !form.online_test_id || !form.candidate_id"
            class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium disabled:opacity-50">
            {{ creating ? 'Menyimpan...' : 'Tugaskan' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>