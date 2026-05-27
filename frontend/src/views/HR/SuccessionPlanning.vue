<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import {
  KeyIcon, PlusIcon, ArrowPathIcon, UserCircleIcon,
  ShieldExclamationIcon, ChartPieIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 14 / H3.2 — Succession Planning UI.
 *
 * Tabs:
 *   1. Critical Positions — daftar posisi kunci + coverage status
 *   2. Coverage Dashboard — % posisi yang sudah punya successor
 *
 * Workflow:
 *   - HR define critical position (level criticality, target_replacement_count)
 *   - HR/HRBP nominate successor (employee_id) → readiness auto-compute
 *     dari competency gap
 *   - Dashboard tampilkan coverage ratio & gap list
 *
 * Endpoints:
 *   GET/POST /critical-positions
 *   GET/POST /critical-positions/:id/successors
 *   POST /succession-candidates/:id/recompute-readiness
 *   GET /succession/coverage
 */

const auth = useAuthStore()
const toast = useToast()

const tab = ref('positions')
const isHR = computed(() =>
  auth.can('succession.manage') || auth.can('succession.view') || auth.isSuperAdmin
)

// ─── Critical positions ─────────────────────────────
const criticalPositions = ref([])
const positions = ref([])
const showAdd = ref(false)
const addForm = reactive({
  position_id: '',
  criticality: 'HIGH',
  target_replacement_count: 1,
  reason: '',
})

async function loadCritical() {
  try {
    const res = await api.get('/critical-positions')
    criticalPositions.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat critical positions')
  }
}

async function loadPositions() {
  try {
    const res = await api.get('/positions', { params: { per_page: 200 } })
    positions.value = res.data.data || []
  } catch (_) {}
}

async function addCritical() {
  if (! addForm.position_id) {
    toast.warning('Pilih posisi')
    return
  }
  try {
    await api.post('/critical-positions', addForm)
    toast.success('Critical position ditambahkan')
    showAdd.value = false
    Object.assign(addForm, { position_id: '', criticality: 'HIGH', target_replacement_count: 1, reason: '' })
    loadCritical()
    loadCoverage()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menambah')
  }
}

// ─── Successors ─────────────────────────────────────
const expanded = ref(null)
const successors = ref({})
const showNominate = ref(null)
const nominateForm = reactive({ employee_id: '', notes: '' })

async function toggleExpand(cp) {
  if (expanded.value === cp.id) {
    expanded.value = null
    return
  }
  expanded.value = cp.id
  if (! successors.value[cp.id]) {
    await loadSuccessors(cp.id)
  }
}

async function loadSuccessors(cpId) {
  try {
    const res = await api.get(`/critical-positions/${cpId}/successors`)
    successors.value = { ...successors.value, [cpId]: res.data.data || [] }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat successors')
  }
}

async function nominate() {
  if (! nominateForm.employee_id) {
    toast.warning('Employee ID wajib')
    return
  }
  try {
    await api.post(`/critical-positions/${showNominate.value.id}/successors`, nominateForm)
    toast.success('Successor dinominasikan')
    const cpId = showNominate.value.id
    showNominate.value = null
    nominateForm.employee_id = ''
    nominateForm.notes = ''
    await loadSuccessors(cpId)
    loadCoverage()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal nominate')
  }
}

async function recomputeReadiness(succ) {
  try {
    await api.post(`/succession-candidates/${succ.id}/recompute-readiness`)
    toast.success('Readiness di-recompute')
    await loadSuccessors(succ.critical_position_id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal recompute')
  }
}

// ─── Coverage ───────────────────────────────────────
const coverage = ref(null)

async function loadCoverage() {
  try {
    const res = await api.get('/succession/coverage')
    coverage.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat coverage')
  }
}

function readinessBadge(r) {
  const map = {
    READY_NOW:    'bg-emerald-100 text-emerald-700',
    READY_1_YEAR: 'bg-blue-100 text-blue-700',
    READY_3_YEAR: 'bg-amber-100 text-amber-700',
    NOT_READY:    'bg-red-100 text-red-700',
  }
  return map[r] || 'bg-gray-100 text-gray-700'
}

function criticalityBadge(c) {
  const map = {
    HIGH:   'bg-red-100 text-red-700',
    MEDIUM: 'bg-amber-100 text-amber-700',
    LOW:    'bg-blue-100 text-blue-700',
  }
  return map[c] || 'bg-gray-100 text-gray-700'
}

function coverageStatus(cp) {
  const succs = successors.value[cp.id] || []
  const ready = succs.filter(s => s.readiness === 'READY_NOW').length
  const total = succs.length
  if (ready >= cp.target_replacement_count) return 'covered'
  if (total >= cp.target_replacement_count) return 'partial'
  return 'gap'
}

onMounted(() => {
  loadCritical()
  loadPositions()
  loadCoverage()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <KeyIcon class="w-6 h-6" /> Succession Planning
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Identifikasi posisi kunci, nominasi successor, dan pastikan coverage rasio aman.
        </p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary" @click="loadCritical(); loadCoverage()">
          <ArrowPathIcon class="w-4 h-4" /> Refresh
        </button>
        <button v-if="isHR" class="btn-primary" @click="showAdd = true">
          <PlusIcon class="w-4 h-4" /> Tambah Critical Position
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-4 flex gap-1">
      <button v-for="t in [
        { id: 'positions', label: 'Critical Positions' },
        { id: 'coverage', label: 'Coverage Dashboard' },
      ]" :key="t.id" @click="tab = t.id"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
          tab === t.id ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700'
        ]">
        {{ t.label }}
      </button>
    </div>

    <!-- ───── Tab: Positions ───── -->
    <div v-if="tab === 'positions'">
      <div class="space-y-3">
        <div v-if="criticalPositions.length === 0" class="card">
          <div class="card-body text-center py-12 text-gray-400">
            Belum ada critical position. HR perlu tandai posisi-posisi kunci dulu
            sebelum bisa nominasi successor.
          </div>
        </div>
        <div v-for="cp in criticalPositions" :key="cp.id" class="card">
          <div class="card-body cursor-pointer" @click="toggleExpand(cp)">
            <div class="flex items-center gap-3">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <h3 class="font-semibold text-gray-900">{{ cp.position_name }}</h3>
                  <span class="inline-block px-2 py-0.5 rounded text-xs" :class="criticalityBadge(cp.criticality)">
                    {{ cp.criticality }}
                  </span>
                  <span class="text-xs text-gray-500">
                    Target: {{ cp.target_replacement_count }} successor
                  </span>
                </div>
                <div class="text-xs text-gray-500 mt-1">
                  <span v-if="cp.department_name">{{ cp.department_name }}</span>
                  <span v-if="cp.incumbent_name"> • Incumbent: {{ cp.incumbent_name }}</span>
                </div>
                <p v-if="cp.reason" class="text-sm text-gray-600 mt-1">{{ cp.reason }}</p>
              </div>
              <div class="text-right">
                <div class="text-xs text-gray-500">Successors</div>
                <div class="font-bold text-lg">
                  {{ (successors[cp.id] || []).length }} / {{ cp.target_replacement_count }}
                </div>
              </div>
              <button v-if="isHR" class="btn-secondary text-xs" @click.stop="showNominate = cp">
                <PlusIcon class="w-4 h-4" /> Nominate
              </button>
            </div>
          </div>
          <div v-if="expanded === cp.id" class="border-t bg-gray-50">
            <div class="p-4">
              <div v-if="! successors[cp.id]" class="text-center py-4 text-gray-400 text-sm">Memuat…</div>
              <div v-else-if="successors[cp.id].length === 0" class="text-center py-4 text-gray-400 text-sm">
                Belum ada successor. Klik "Nominate" untuk menambahkan.
              </div>
              <table v-else class="table">
                <thead>
                  <tr>
                    <th>Successor</th>
                    <th>Readiness</th>
                    <th>Gap Score</th>
                    <th>Notes</th>
                    <th class="text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="s in successors[cp.id]" :key="s.id">
                    <td>
                      <div class="flex items-center gap-2">
                        <UserCircleIcon class="w-5 h-5 text-gray-400" />
                        <div>
                          <div class="font-medium">{{ s.employee_name }}</div>
                          <div class="text-xs text-gray-500">{{ s.current_position }}</div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <span class="inline-block px-2 py-0.5 rounded text-xs font-medium"
                        :class="readinessBadge(s.readiness)">
                        {{ s.readiness?.replace(/_/g, ' ') }}
                      </span>
                    </td>
                    <td>
                      <span v-if="s.gap_score !== null" class="text-sm font-mono">
                        {{ Number(s.gap_score).toFixed(2) }}
                      </span>
                      <span v-else class="text-xs text-gray-400">—</span>
                    </td>
                    <td class="text-sm text-gray-600">{{ s.notes || '—' }}</td>
                    <td class="text-right">
                      <button class="btn-icon text-primary-600" title="Recompute readiness"
                        @click="recomputeReadiness(s)">
                        <ArrowPathIcon class="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── Tab: Coverage ───── -->
    <div v-if="tab === 'coverage'">
      <div v-if="! coverage" class="card">
        <div class="card-body text-center py-12 text-gray-400">Memuat coverage…</div>
      </div>
      <div v-else>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
          <div class="card-body bg-blue-50 rounded-lg border border-blue-100">
            <div class="text-xs text-blue-700">Total Critical</div>
            <div class="text-2xl font-bold text-blue-900">{{ coverage.total_critical }}</div>
          </div>
          <div class="card-body bg-emerald-50 rounded-lg border border-emerald-100">
            <div class="text-xs text-emerald-700">Fully Covered</div>
            <div class="text-2xl font-bold text-emerald-900">{{ coverage.covered }}</div>
          </div>
          <div class="card-body bg-amber-50 rounded-lg border border-amber-100">
            <div class="text-xs text-amber-700">Partial Coverage</div>
            <div class="text-2xl font-bold text-amber-900">{{ coverage.partial }}</div>
          </div>
          <div class="card-body bg-red-50 rounded-lg border border-red-100">
            <div class="text-xs text-red-700">No Successor</div>
            <div class="text-2xl font-bold text-red-900">{{ coverage.gap }}</div>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-header flex items-center gap-2">
            <ChartPieIcon class="w-4 h-4" />
            <span class="font-medium">Coverage Ratio</span>
            <span class="ml-auto text-sm text-gray-600">
              {{ coverage.coverage_ratio_pct }}% covered
            </span>
          </div>
          <div class="p-5">
            <div class="w-full bg-gray-100 rounded-full h-4 overflow-hidden">
              <div class="bg-emerald-500 h-full transition-all"
                :style="{ width: coverage.coverage_ratio_pct + '%' }"></div>
            </div>
            <div class="text-xs text-gray-500 mt-2">
              Target ideal: ≥ 80% critical position memiliki minimal 1 successor READY_NOW.
              Saat ini: {{ coverage.coverage_ratio_pct }}%.
            </div>
          </div>
        </div>

        <div v-if="coverage.gaps?.length" class="card">
          <div class="card-header flex items-center gap-2">
            <ShieldExclamationIcon class="w-4 h-4 text-red-600" />
            <span class="font-medium">Posisi Tanpa Successor</span>
          </div>
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Posisi</th>
                  <th>Department</th>
                  <th>Criticality</th>
                  <th>Incumbent</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="g in coverage.gaps" :key="g.position_id">
                  <td class="font-medium">{{ g.position_name }}</td>
                  <td>{{ g.department_name || '—' }}</td>
                  <td>
                    <span class="inline-block px-2 py-0.5 rounded text-xs" :class="criticalityBadge(g.criticality)">
                      {{ g.criticality }}
                    </span>
                  </td>
                  <td class="text-sm">{{ g.incumbent_name || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: Add critical -->
    <div v-if="showAdd" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showAdd = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h3 class="font-semibold">Tambah Critical Position</h3>
          <button @click="showAdd = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <div class="p-5 space-y-3">
          <div>
            <label class="label">Posisi</label>
            <select v-model="addForm.position_id" class="input">
              <option value="">— Pilih posisi —</option>
              <option v-for="p in positions" :key="p.id" :value="p.id">
                {{ p.name }}{{ p.department_name ? ` — ${p.department_name}` : '' }}
              </option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Criticality</label>
              <select v-model="addForm.criticality" class="input">
                <option value="HIGH">High</option>
                <option value="MEDIUM">Medium</option>
                <option value="LOW">Low</option>
              </select>
            </div>
            <div>
              <label class="label">Target Successor</label>
              <input type="number" min="1" max="5" v-model.number="addForm.target_replacement_count" class="input" />
            </div>
          </div>
          <div>
            <label class="label">Alasan / Justifikasi</label>
            <textarea v-model="addForm.reason" class="input" rows="2"
              placeholder="contoh: Posisi strategis yang tidak boleh kosong, leadership pipeline, dst"></textarea>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showAdd = false">Batal</button>
          <button class="btn-primary" @click="addCritical">Tambah</button>
        </div>
      </div>
    </div>

    <!-- Modal: Nominate successor -->
    <div v-if="showNominate" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showNominate = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h3 class="font-semibold">Nominate Successor</h3>
          <button @click="showNominate = null"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <div class="p-5 space-y-3">
          <div class="text-sm bg-blue-50 rounded p-2 text-blue-800">
            Untuk posisi: <strong>{{ showNominate.position_name }}</strong>
          </div>
          <div>
            <label class="label">Employee ID</label>
            <input type="number" v-model.number="nominateForm.employee_id" class="input"
              placeholder="ID karyawan yang dinominasikan" />
          </div>
          <div>
            <label class="label">Notes</label>
            <textarea v-model="nominateForm.notes" class="input" rows="2"
              placeholder="Alasan nominasi, area development, dll"></textarea>
          </div>
          <p class="text-xs text-gray-500">
            Readiness akan auto-compute berdasarkan competency gap antara posisi target dan
            posisi karyawan saat ini.
          </p>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showNominate = null">Batal</button>
          <button class="btn-primary" @click="nominate">Nominate</button>
        </div>
      </div>
    </div>
  </div>
</template>