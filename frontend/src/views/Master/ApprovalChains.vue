<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, PencilIcon, TrashIcon, XMarkIcon, ArrowUpIcon, ArrowDownIcon,
  ShieldCheckIcon, AdjustmentsHorizontalIcon,
} from '@heroicons/vue/24/outline'

/**
 * Halaman konfigurasi Approval Chain.
 *
 * Konsep:
 *  - Sebuah "chain" adalah definisi langkah persetujuan (steps) untuk satu modul
 *    (LEAVE, OVERTIME, ATTENDANCE_CHANGE, REIMBURSEMENT, LOAN, GENERIC).
 *  - Step bisa berupa role/level (SUPERVISOR, MANAGER, HR, dll.) atau approver
 *    spesifik (user/employee tertentu) atau berdasarkan ROLE.
 *  - Assignment rules menentukan chain mana berlaku untuk dept/branch/grade
 *    tertentu — pakai priority untuk override umum.
 */

const toast = useToast()

const moduleOptions = [
  { value: 'LEAVE', label: 'Cuti' },
  { value: 'OVERTIME', label: 'Lembur' },
  { value: 'ATTENDANCE_CHANGE', label: 'Pengajuan Perubahan Absensi' },
  { value: 'REIMBURSEMENT', label: 'Reimbursement' },
  { value: 'LOAN', label: 'Kasbon / Pinjaman' },
  { value: 'GENERIC', label: 'Generic' },
]

const approverTypes = [
  { value: 'SUPERVISOR', label: 'Atasan Langsung (Supervisor)' },
  { value: 'MANAGER', label: 'Manager' },
  { value: 'DEPARTMENT_HEAD', label: 'Kepala Departemen' },
  { value: 'BRANCH_HEAD', label: 'Kepala Cabang' },
  { value: 'HR', label: 'HR' },
  { value: 'FINANCE', label: 'Finance' },
  { value: 'ROLE', label: 'Role tertentu' },
  { value: 'SPECIFIC_USER', label: 'User tertentu' },
  { value: 'SPECIFIC_EMPLOYEE', label: 'Karyawan tertentu' },
]

const fallbackOptions = [
  { value: 'SKIP', label: 'Skip step jika approver tak ada' },
  { value: 'BLOCK', label: 'Blokir / tahan permintaan' },
  { value: 'HR_FALLBACK', label: 'Limpahkan ke HR' },
]

const items = ref([])
const loading = ref(false)
const filterModule = ref('')

const showEditor = ref(false)
const editing = ref(false)
const form = ref(emptyForm())
const activeTab = ref('steps') // steps | assignments

// Master data untuk dropdown.
const departments = ref([])
const branches = ref([])
const jobGrades = ref([])
const roles = ref([])
const users = ref([])

const assignments = ref([])
const newAssignment = reactive({
  department_id: '',
  branch_id: '',
  job_grade_id: '',
  priority: 100,
  is_active: 1,
})

function emptyForm() {
  return {
    id: null,
    code: '',
    name: '',
    module: 'LEAVE',
    description: '',
    is_default: 0,
    fallback_strategy: 'HR_FALLBACK',
    is_active: 1,
    steps: [],
  }
}

function emptyStep() {
  return {
    name: '',
    approver_type: 'SUPERVISOR',
    approver_role_id: null,
    approver_user_id: null,
    approver_employee_id: null,
    is_required: 1,
    allow_skip_if_self: 1,
    sla_hours: null,
    condition_field: null,
    condition_op: null,
    condition_value: null,
  }
}

async function fetchData() {
  loading.value = true
  try {
    const params = {}
    if (filterModule.value) params.module = filterModule.value
    const res = await api.get('/approval-chains', { params })
    items.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat chain')
  } finally {
    loading.value = false
  }
}

async function loadMasters() {
  try {
    const [d, b, g, r] = await Promise.all([
      api.get('/departments', { params: { per_page: 200 } }).catch(() => null),
      api.get('/branches', { params: { per_page: 200 } }).catch(() => null),
      api.get('/job-grades', { params: { per_page: 200 } }).catch(() => null),
      api.get('/roles', { params: { per_page: 200 } }).catch(() => null),
    ])
    departments.value = d?.data?.data || []
    branches.value = b?.data?.data || []
    jobGrades.value = g?.data?.data || []
    roles.value = r?.data?.data || []
  } catch (_) { /* silent */ }
}

onMounted(() => {
  fetchData()
  loadMasters()
})

watch(filterModule, () => fetchData())

function openCreate() {
  form.value = emptyForm()
  form.value.steps = [{ ...emptyStep(), name: 'Atasan Langsung' }]
  editing.value = false
  activeTab.value = 'steps'
  assignments.value = []
  showEditor.value = true
}

async function openEdit(item) {
  try {
    const res = await api.get(`/approval-chains/${item.id}`)
    const data = res.data.data
    form.value = {
      ...emptyForm(),
      ...data,
      steps: (data.steps || []).map(s => ({ ...emptyStep(), ...s })),
    }
    assignments.value = data.assignments || []
    editing.value = true
    activeTab.value = 'steps'
    showEditor.value = true
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat chain')
  }
}

async function save() {
  // Validasi minimal: setidaknya 1 step yang valid.
  if (! form.value.steps || form.value.steps.length === 0) {
    toast.warning('Tambahkan minimal satu step.')
    return
  }
  const cleaned = form.value.steps.map((s, i) => ({
    ...s,
    step_order: i + 1,
    name: s.name || `Step ${i + 1}`,
  }))
  const payload = { ...form.value, steps: cleaned }

  try {
    if (editing.value) {
      await api.put(`/approval-chains/${payload.id}`, payload)
      toast.success('Chain diperbarui')
    } else {
      const res = await api.post('/approval-chains', payload)
      form.value.id = res.data.data?.id
      editing.value = true
      toast.success('Chain dibuat')
    }
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: 'Hapus Chain',
    message: `Hapus chain "${item.name}"?`,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/approval-chains/${item.id}`)
    toast.success('Berhasil dihapus')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

// ─── Step manipulation ────────────────────────────────────────
function addStep() {
  form.value.steps.push({ ...emptyStep(), name: `Step ${form.value.steps.length + 1}` })
}

function removeStep(index) {
  form.value.steps.splice(index, 1)
}

function moveStep(index, dir) {
  const ni = index + dir
  if (ni < 0 || ni >= form.value.steps.length) return
  const arr = form.value.steps
  ;[arr[index], arr[ni]] = [arr[ni], arr[index]]
}

// ─── Assignment rules ─────────────────────────────────────────
async function addAssignment() {
  if (! form.value.id) {
    toast.warning('Simpan chain terlebih dulu.')
    return
  }
  try {
    const payload = {
      chain_id: form.value.id,
      module: form.value.module,
      department_id: newAssignment.department_id || null,
      branch_id: newAssignment.branch_id || null,
      job_grade_id: newAssignment.job_grade_id || null,
      priority: Number(newAssignment.priority) || 100,
      is_active: 1,
    }
    await api.post('/approval-assignments', payload)
    toast.success('Assignment ditambahkan')
    await refreshAssignments()
    Object.assign(newAssignment, { department_id: '', branch_id: '', job_grade_id: '', priority: 100, is_active: 1 })
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menambahkan')
  }
}

async function refreshAssignments() {
  if (! form.value.id) return
  try {
    const res = await api.get(`/approval-chains/${form.value.id}`)
    assignments.value = res.data.data.assignments || []
  } catch (_) { /* silent */ }
}

async function removeAssignment(a) {
  const ok = await confirmDialog({
    title: 'Hapus Assignment',
    message: 'Hapus mapping ini?',
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/approval-assignments/${a.id}`)
    toast.success('Berhasil dihapus')
    refreshAssignments()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

function moduleLabel(v) { return moduleOptions.find(m => m.value === v)?.label || v }
function approverLabel(v) { return approverTypes.find(t => t.value === v)?.label || v }
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <ShieldCheckIcon class="w-6 h-6" /> Konfigurasi Approval
      </h1>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Buat Chain
      </button>
    </div>

    <div class="card">
      <div class="card-header">
        <select v-model="filterModule" class="input w-full md:w-64">
          <option value="">— Semua Modul —</option>
          <option v-for="m in moduleOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
        </select>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Kode</th>
              <th>Nama</th>
              <th>Modul</th>
              <th>Step</th>
              <th>Default</th>
              <th>Status</th>
              <th class="text-right w-32">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="7" class="text-center py-8 text-gray-400">Memuat...</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">Belum ada chain</td></tr>
            <tr v-for="item in items" :key="item.id">
              <td class="font-mono text-sm">{{ item.code }}</td>
              <td class="font-medium">{{ item.name }}</td>
              <td>{{ moduleLabel(item.module) }}</td>
              <td class="text-center">{{ item.step_count }}</td>
              <td>
                <span v-if="item.is_default" class="px-2 py-0.5 rounded-full text-xs bg-amber-50 text-amber-700">Default</span>
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
    </div>

    <!-- Editor Modal -->
    <div v-if="showEditor" class="fixed inset-0 z-40 flex items-stretch bg-black/40">
      <div class="bg-white w-full m-4 rounded-lg shadow-xl flex flex-col overflow-hidden">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editing ? 'Edit' : 'Buat' }} Approval Chain</h2>
          <button class="text-gray-500" @click="showEditor = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <!-- Metadata -->
        <div class="grid grid-cols-1 md:grid-cols-6 gap-3 px-6 py-3 border-b border-gray-100 bg-gray-50">
          <div class="md:col-span-1">
            <label class="label">Kode</label>
            <input v-model="form.code" class="input" :disabled="editing" required />
          </div>
          <div class="md:col-span-2">
            <label class="label">Nama</label>
            <input v-model="form.name" class="input" required />
          </div>
          <div class="md:col-span-1">
            <label class="label">Modul</label>
            <select v-model="form.module" class="input" required>
              <option v-for="m in moduleOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
          <div class="md:col-span-1">
            <label class="label">Fallback</label>
            <select v-model="form.fallback_strategy" class="input">
              <option v-for="f in fallbackOptions" :key="f.value" :value="f.value">{{ f.label }}</option>
            </select>
          </div>
          <div class="md:col-span-1 flex items-end gap-3">
            <label class="flex items-center gap-1 text-sm">
              <input type="checkbox" v-model="form.is_active" :true-value="1" :false-value="0" class="w-4 h-4" /> Aktif
            </label>
            <label class="flex items-center gap-1 text-sm">
              <input type="checkbox" v-model="form.is_default" :true-value="1" :false-value="0" class="w-4 h-4" /> Default
            </label>
          </div>
        </div>

        <!-- Tabs -->
        <div class="flex border-b border-gray-200 px-6 bg-white">
          <button :class="['px-4 py-2 text-sm border-b-2', activeTab === 'steps' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500']" @click="activeTab = 'steps'">
            Steps Persetujuan
          </button>
          <button :class="['px-4 py-2 text-sm border-b-2', activeTab === 'assignments' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500']" @click="activeTab = 'assignments'">
            <AdjustmentsHorizontalIcon class="w-4 h-4 inline -mt-0.5" /> Assignment Rules
          </button>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6">
          <!-- STEPS -->
          <div v-if="activeTab === 'steps'" class="space-y-4">
            <div class="text-sm text-gray-500">
              Susun urutan langkah persetujuan. Step pertama dijalankan duluan; jika semua disetujui, request diloloskan.
            </div>

            <div v-for="(s, i) in form.steps" :key="i" class="border border-gray-200 rounded-md p-4 bg-white">
              <div class="flex items-center justify-between mb-3">
                <div class="font-medium text-gray-700">Step {{ i + 1 }}</div>
                <div class="flex gap-1">
                  <button class="text-gray-500 hover:text-primary-600 disabled:opacity-30"
                    :disabled="i === 0" @click="moveStep(i, -1)"><ArrowUpIcon class="w-4 h-4" /></button>
                  <button class="text-gray-500 hover:text-primary-600 disabled:opacity-30"
                    :disabled="i === form.steps.length - 1" @click="moveStep(i, 1)"><ArrowDownIcon class="w-4 h-4" /></button>
                  <button class="text-red-500 hover:text-red-700" @click="removeStep(i)"
                    :disabled="form.steps.length <= 1"><TrashIcon class="w-4 h-4" /></button>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                  <label class="label">Nama Step</label>
                  <input v-model="s.name" class="input" placeholder="Contoh: Atasan Langsung" />
                </div>
                <div>
                  <label class="label">Tipe Approver</label>
                  <select v-model="s.approver_type" class="input">
                    <option v-for="t in approverTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                  </select>
                </div>
                <div v-if="s.approver_type === 'ROLE'">
                  <label class="label">Pilih Role</label>
                  <select v-model.number="s.approver_role_id" class="input">
                    <option :value="null">— Pilih —</option>
                    <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                  </select>
                </div>
                <div v-if="s.approver_type === 'SPECIFIC_USER'">
                  <label class="label">User ID</label>
                  <input v-model.number="s.approver_user_id" type="number" class="input" placeholder="ID user" />
                </div>
                <div v-if="s.approver_type === 'SPECIFIC_EMPLOYEE'">
                  <label class="label">Employee ID</label>
                  <input v-model.number="s.approver_employee_id" type="number" class="input" placeholder="ID karyawan" />
                </div>
                <div>
                  <label class="label">SLA (jam)</label>
                  <input v-model.number="s.sla_hours" type="number" class="input" placeholder="ex: 48" />
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                <div>
                  <label class="label">Kondisi Field (opsional)</label>
                  <input v-model="s.condition_field" class="input" placeholder="ex: amount, days" />
                </div>
                <div>
                  <label class="label">Operator</label>
                  <select v-model="s.condition_op" class="input">
                    <option :value="null">— Tidak ada —</option>
                    <option value=">">lebih dari</option>
                    <option value=">=">lebih dari sama dengan</option>
                    <option value="<">kurang dari</option>
                    <option value="<=">kurang dari sama dengan</option>
                    <option value="==">sama dengan</option>
                  </select>
                </div>
                <div>
                  <label class="label">Nilai</label>
                  <input v-model.number="s.condition_value" type="number" class="input" placeholder="ex: 1000000" />
                </div>
              </div>

              <div class="flex flex-wrap gap-4 mt-3">
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="s.is_required" :true-value="1" :false-value="0" class="w-4 h-4" />
                  Wajib disetujui
                </label>
                <label class="flex items-center gap-2 text-sm">
                  <input type="checkbox" v-model="s.allow_skip_if_self" :true-value="1" :false-value="0" class="w-4 h-4" />
                  Skip jika approver = pemohon
                </label>
              </div>
            </div>

            <button class="btn-secondary" @click="addStep">
              <PlusIcon class="w-4 h-4" /> Tambah Step
            </button>
          </div>

          <!-- ASSIGNMENTS -->
          <div v-else-if="activeTab === 'assignments'" class="space-y-4">
            <div class="text-sm text-gray-500" v-if="! form.id">
              Simpan chain terlebih dulu sebelum menambahkan assignment rule.
            </div>

            <div v-if="form.id" class="card-body bg-gray-50 border border-gray-200 rounded-md">
              <div class="font-medium text-sm mb-3">Tambah Mapping</div>
              <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <div>
                  <label class="label">Departemen</label>
                  <select v-model="newAssignment.department_id" class="input">
                    <option value="">Semua</option>
                    <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="label">Cabang</label>
                  <select v-model="newAssignment.branch_id" class="input">
                    <option value="">Semua</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="label">Job Grade</label>
                  <select v-model="newAssignment.job_grade_id" class="input">
                    <option value="">Semua</option>
                    <option v-for="g in jobGrades" :key="g.id" :value="g.id">{{ g.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="label">Prioritas</label>
                  <input v-model.number="newAssignment.priority" type="number" class="input" />
                </div>
                <div class="flex items-end">
                  <button class="btn-primary w-full" @click="addAssignment">
                    <PlusIcon class="w-4 h-4" /> Tambah
                  </button>
                </div>
              </div>
              <div class="text-xs text-gray-400 mt-2">
                Prioritas lebih kecil = lebih spesifik. Mapping kosong = berlaku untuk semua karyawan modul ini.
              </div>
            </div>

            <div class="overflow-x-auto" v-if="form.id">
              <table class="table">
                <thead>
                  <tr>
                    <th>Departemen</th>
                    <th>Cabang</th>
                    <th>Job Grade</th>
                    <th>Prioritas</th>
                    <th class="text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-if="assignments.length === 0">
                    <td colspan="5" class="text-center py-6 text-gray-400">Belum ada mapping</td>
                  </tr>
                  <tr v-for="a in assignments" :key="a.id">
                    <td>{{ a.department_name || 'Semua' }}</td>
                    <td>{{ a.branch_name || 'Semua' }}</td>
                    <td>{{ a.grade_name || 'Semua' }}</td>
                    <td>{{ a.priority }}</td>
                    <td class="text-right">
                      <button class="text-gray-500 hover:text-red-600" @click="removeAssignment(a)">
                        <TrashIcon class="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2 bg-white">
          <button class="btn-secondary" @click="showEditor = false">Tutup</button>
          <button class="btn-primary" @click="save">{{ editing ? 'Simpan Perubahan' : 'Simpan Chain' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>