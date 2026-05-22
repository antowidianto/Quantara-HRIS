<script setup>
import { ref, computed, onMounted, reactive, watch } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate } from '@/composables/useResource'
import {
  ClipboardDocumentCheckIcon, UserPlusIcon, UserMinusIcon,
  CheckCircleIcon, ExclamationTriangleIcon, RocketLaunchIcon,
  PlusIcon,
} from '@heroicons/vue/24/outline'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

const auth = useAuthStore()
const toast = useToast()

const tabType = ref('ONBOARD')  // ONBOARD | OFFBOARD
const board = ref([])           // list employee with progress aggregate
const loading = ref(false)
const employees = ref([])
const templates = ref([])

// Generator modal
const showGenerator = ref(false)
const genInput = reactive({
  employee_id: '', type: 'ONBOARD', template_id: '', anchor_date: '',
})

// Detail modal — show tasks for an employee
const detailEmployee = ref(null)
const detailTasks = ref([])
const detailProgress = ref(null)

const canManage = computed(() => auth.can('onboarding.manage'))

async function fetchBoard() {
  loading.value = true
  try {
    const res = await api.get('/employee-tasks/board', { params: { type: tabType.value } })
    board.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat board')
  } finally {
    loading.value = false
  }
}

async function fetchMaster() {
  try {
    // Karyawan TIDAK lagi di-load semua sekaligus — picker pakai
    // EmployeeSearchSelect server-side untuk dataset besar.
    const t = await api.get('/task-templates')
    templates.value = t.data.data || []
  } catch (_) {}
}

onMounted(() => { fetchMaster(); fetchBoard() })
watch(tabType, fetchBoard)

function pct(row) {
  const tot = Number(row.total_tasks || 0)
  const done = Number(row.completed_tasks || 0)
  if (tot === 0) return 0
  return Math.round((done / tot) * 100)
}

function pctColor(p) {
  if (p >= 100) return 'bg-emerald-500'
  if (p >= 70) return 'bg-blue-500'
  if (p >= 40) return 'bg-amber-500'
  return 'bg-orange-500'
}

function openGenerator() {
  Object.assign(genInput, {
    employee_id: '', type: tabType.value, template_id: '', anchor_date: '',
  })
  showGenerator.value = true
}

async function generate() {
  if (! genInput.employee_id) {
    toast.error('Pilih karyawan dulu')
    return
  }
  try {
    const payload = { ...genInput }
    Object.keys(payload).forEach(k => { if (payload[k] === '') delete payload[k] })
    const res = await api.post('/employee-tasks/generate', payload)
    const r = res.data.data
    toast.success(`${r.created} task dibuat (${r.skipped} skip duplikat)`)
    showGenerator.value = false
    fetchBoard()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

async function openDetail(row) {
  detailEmployee.value = { id: row.employee_id, full_name: row.full_name, employee_number: row.employee_number }
  try {
    const res = await api.get(`/employee-tasks/employee/${row.employee_id}`, { params: { type: tabType.value } })
    detailTasks.value = res.data.data.tasks || []
    detailProgress.value = res.data.data.progress
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat tasks')
  }
}

async function completeTask(t) {
  try {
    await api.post(`/employee-tasks/${t.id}/complete`)
    toast.success('Task selesai')
    if (detailEmployee.value) openDetail({ employee_id: detailEmployee.value.id })
    fetchBoard()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

async function skipTask(t) {
  try {
    await api.put(`/employee-tasks/${t.id}`, { status: 'SKIPPED' })
    toast.success('Task di-skip')
    if (detailEmployee.value) openDetail({ employee_id: detailEmployee.value.id })
    fetchBoard()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

const STATUS_BADGE = {
  PENDING:     { label: 'Pending',     cls: 'bg-gray-100 text-gray-700' },
  IN_PROGRESS: { label: 'Berjalan',    cls: 'bg-blue-100 text-blue-700' },
  COMPLETED:   { label: 'Selesai',     cls: 'bg-emerald-100 text-emerald-700' },
  SKIPPED:     { label: 'Dilewati',    cls: 'bg-gray-100 text-gray-500' },
  CANCELLED:   { label: 'Dibatalkan',  cls: 'bg-red-100 text-red-600' },
}

const today = new Date().toISOString().slice(0, 10)
function isOverdue(task) {
  return task.status === 'PENDING' && task.due_date && task.due_date < today
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ClipboardDocumentCheckIcon class="w-6 h-6 text-primary-600" />
          Onboarding & Offboarding
        </h1>
        <p class="text-sm text-gray-500">Checklist tugas otomatis untuk karyawan baru & yang akan keluar.</p>
      </div>
      <button v-if="canManage" class="btn-primary" @click="openGenerator">
        <PlusIcon class="w-4 h-4" /> Generate Tasks
      </button>
    </div>

    <!-- Tab type selector -->
    <div class="flex gap-2 border-b border-gray-200 mb-4">
      <button
        :class="['px-4 py-2 text-sm border-b-2 font-medium', tabType === 'ONBOARD' ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700']"
        @click="tabType = 'ONBOARD'">
        <UserPlusIcon class="w-4 h-4 inline-block mr-1" /> Onboarding (Karyawan Baru)
      </button>
      <button
        :class="['px-4 py-2 text-sm border-b-2 font-medium', tabType === 'OFFBOARD' ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700']"
        @click="tabType = 'OFFBOARD'">
        <UserMinusIcon class="w-4 h-4 inline-block mr-1" /> Offboarding (Karyawan Keluar)
      </button>
    </div>

    <!-- Board -->
    <div v-if="loading" class="text-center py-12 text-gray-400">Memuat…</div>
    <div v-else-if="board.length === 0" class="card card-body text-center py-12 text-gray-400">
      Belum ada {{ tabType === 'ONBOARD' ? 'onboarding' : 'offboarding' }} aktif. Klik <strong>Generate Tasks</strong> untuk mulai.
    </div>
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="row in board" :key="row.employee_id"
        class="card card-body cursor-pointer hover:shadow-md transition" @click="openDetail(row)">
        <div class="flex items-center justify-between mb-2">
          <div>
            <div class="font-semibold">{{ row.full_name }}</div>
            <div class="text-xs text-gray-500">{{ row.employee_number }} · {{ row.department_name || '-' }}</div>
            <div class="text-xs text-gray-500">{{ row.position_name || '-' }}</div>
          </div>
          <div class="text-right">
            <div class="text-xs text-gray-500">
              {{ tabType === 'ONBOARD' ? 'Bergabung' : 'Keluar' }}
            </div>
            <div class="text-sm font-medium">
              {{ fmtDate(tabType === 'ONBOARD' ? row.join_date : row.resign_date) }}
            </div>
          </div>
        </div>

        <!-- Progress bar -->
        <div class="mt-2">
          <div class="flex items-center justify-between text-xs mb-1">
            <span class="text-gray-600">Progress</span>
            <span class="font-medium">{{ row.completed_tasks }} / {{ row.total_tasks }} ({{ pct(row) }}%)</span>
          </div>
          <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
            <div :class="['h-2 transition-all', pctColor(pct(row))]" :style="{ width: pct(row) + '%' }"></div>
          </div>
        </div>

        <div class="flex gap-2 mt-3 text-xs">
          <span v-if="row.overdue_tasks > 0" class="px-2 py-0.5 rounded bg-red-100 text-red-700 font-medium">
            <ExclamationTriangleIcon class="w-3 h-3 inline" /> {{ row.overdue_tasks }} terlambat
          </span>
          <span v-if="pct(row) >= 100" class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 font-medium">
            <CheckCircleIcon class="w-3 h-3 inline" /> Selesai
          </span>
        </div>
      </div>
    </div>

    <!-- Generator modal -->
    <div v-if="showGenerator" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold flex items-center gap-2">
            <RocketLaunchIcon class="w-5 h-5" /> Generate Tasks
          </h2>
          <button class="text-gray-500" @click="showGenerator = false">&times;</button>
        </div>
        <div class="p-6 space-y-3">
          <div>
            <label class="label">Karyawan *</label>
            <EmployeeSearchSelect
              v-model="genInput.employee_id"
              placeholder="— Pilih karyawan —"
              :only-active="false"
            />
          </div>
          <div>
            <label class="label">Tipe *</label>
            <select v-model="genInput.type" class="input">
              <option value="ONBOARD">Onboarding (karyawan baru)</option>
              <option value="OFFBOARD">Offboarding (karyawan keluar)</option>
            </select>
          </div>
          <div>
            <label class="label">Template (opsional, pakai default kalau kosong)</label>
            <SearchableSelect
              v-model="genInput.template_id"
              :items="templates.filter(x => x.type === genInput.type)"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="— Pakai default —"
            />
          </div>
          <div>
            <label class="label">Anchor date (opsional)</label>
            <input type="date" v-model="genInput.anchor_date" class="input" />
            <div class="text-xs text-gray-500 mt-1">
              Default: ONBOARD pakai join_date karyawan, OFFBOARD pakai resign_date.
              Due date setiap task = anchor + offset_days.
            </div>
          </div>
          <p class="text-xs text-gray-500">
            Task baru akan dibuat dengan status PENDING dan di-assign ke owner_role
            yang sudah didefinisikan di template (HR / IT / SUPERVISOR / EMPLOYEE / FINANCE).
          </p>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2">
          <button class="btn-secondary" @click="showGenerator = false">Batal</button>
          <button class="btn-primary" @click="generate">Generate</button>
        </div>
      </div>
    </div>

    <!-- Detail modal -->
    <div v-if="detailEmployee" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">
            {{ tabType === 'ONBOARD' ? 'Onboarding' : 'Offboarding' }}: {{ detailEmployee.full_name }}
          </h2>
          <button class="text-gray-500" @click="detailEmployee = null">&times;</button>
        </div>

        <div v-if="detailProgress" class="px-6 py-4 bg-gray-50 border-b border-gray-200 grid grid-cols-4 gap-3 text-sm">
          <div>
            <div class="text-xs text-gray-500">Total</div>
            <div class="font-bold">{{ detailProgress.total }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">Selesai</div>
            <div class="font-bold text-emerald-700">{{ detailProgress.completed }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">Mandatory done</div>
            <div class="font-bold">{{ detailProgress.mandatory_done }} / {{ detailProgress.mandatory }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">Terlambat</div>
            <div class="font-bold text-red-600">{{ detailProgress.overdue }}</div>
          </div>
        </div>

        <table class="table">
          <thead>
            <tr><th>Task</th><th>Owner</th><th>Due</th><th>Status</th><th></th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="t in detailTasks" :key="t.id" :class="{ 'bg-red-50/50': isOverdue(t) }">
              <td>
                <div class="font-medium">
                  {{ t.task_name }}
                  <span v-if="t.mandatory" class="text-red-500 text-xs">*</span>
                </div>
                <div v-if="t.description" class="text-xs text-gray-500">{{ t.description }}</div>
              </td>
              <td class="text-xs">
                <div>{{ t.owner_role || '-' }}</div>
                <div class="text-gray-500">{{ t.assignee_name || '-' }}</div>
              </td>
              <td class="text-xs">
                {{ t.due_date ? fmtDate(t.due_date) : '-' }}
                <span v-if="isOverdue(t)" class="text-red-600">(terlambat)</span>
              </td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE[t.status]?.cls">
                  {{ STATUS_BADGE[t.status]?.label || t.status }}
                </span>
              </td>
              <td class="text-right">
                <div v-if="t.status === 'PENDING' || t.status === 'IN_PROGRESS'" class="flex gap-1 justify-end">
                  <button class="text-emerald-600 hover:text-emerald-800 p-1" title="Selesai" @click="completeTask(t)">
                    <CheckCircleIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && ! t.mandatory" class="text-gray-500 hover:text-gray-700 p-1 text-xs"
                    title="Skip (untuk task non-wajib)" @click="skipTask(t)">Skip</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="px-6 py-3 border-t border-gray-200 flex justify-end">
          <button class="btn-secondary" @click="detailEmployee = null">Tutup</button>
        </div>
      </div>
    </div>
  </div>
</template>