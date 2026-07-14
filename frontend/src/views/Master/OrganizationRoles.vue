<script setup>
/*
 * Organization Roles — master jabatan kunci HR Indonesia.
 *
 * Halaman ini menampilkan daftar role kunci (HR Manager, Direktur HR,
 * dst) dan menyediakan UI quick-assign untuk menetapkan siapa karyawan
 * yang menempati tiap jabatan. Hasil dipakai sebagai single source of
 * truth oleh modul lain (mis. dropdown approver di MPP).
 *
 * Action utama:
 *  - Search + filter level / scope.
 *  - Quick-assign penjabat dari dropdown employee picker (cari by name).
 *  - Backup PJS (Pejabat Pengganti Sementara) — multi-employee.
 *  - Tambah role custom (mis. "Section Head Production") untuk company
 *    yang punya struktur unik di luar default seed.
 *
 * Permission: org_structure.view (lihat) + org_structure.manage (edit).
 *
 * Lihat docs/ORG_STRUCTURE.md.
 */
import { ref, computed, onMounted, reactive } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  BuildingOffice2Icon, PlusIcon, PencilIcon, TrashIcon,
  UserCircleIcon, ArrowPathIcon, XMarkIcon,
  CheckBadgeIcon, ExclamationCircleIcon,
} from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'

const auth = useAuthStore()
const toast = useToast()
const { t } = useI18n()

// ─── State ──────────────────────────────────────────────────────────
const roles = ref([])
const loading = ref(false)
const filters = reactive({ q: '', level: '', has_scope: '', assigned_only: 0 })

// Master employees & departments untuk picker
const employees = ref([])
const departments = ref([])
const branches = ref([])

// Modal create/edit
const showForm = ref(false)
const editing = ref(null)

// Inline assign — modal kecil per row
const showAssign = ref(null)  // role row yang lagi di-edit penjabatnya
const assignForm = reactive({
  employee_id: null,
  backup_employee_ids: [],
})

const LEVELS = computed(() => [
  { value: 'BOD',             label: t('organization_roles.level_bod'),             badge: 'bg-rose-100 text-rose-700 border-rose-300' },
  { value: 'EXECUTIVE',       label: t('organization_roles.level_executive'),       badge: 'bg-purple-100 text-purple-700 border-purple-300' },
  { value: 'GENERAL_MANAGER', label: t('organization_roles.level_general_manager'), badge: 'bg-orange-100 text-orange-700 border-orange-300' },
  { value: 'MANAGER',         label: t('organization_roles.level_manager'),         badge: 'bg-blue-100 text-blue-700 border-blue-300' },
  { value: 'ASST_MANAGER',    label: t('organization_roles.level_asst_manager'),    badge: 'bg-indigo-100 text-indigo-700 border-indigo-300' },
  { value: 'SUPERVISOR',      label: t('organization_roles.level_supervisor'),      badge: 'bg-cyan-100 text-cyan-700 border-cyan-300' },
  { value: 'OFFICER',         label: t('organization_roles.level_officer'),         badge: 'bg-emerald-100 text-emerald-700 border-emerald-300' },
  { value: 'STAFF',           label: t('organization_roles.level_staff'),           badge: 'bg-gray-100 text-gray-600 border-gray-300' },
])

// Standard scopes — hint untuk dropdown saat edit
const STANDARD_SCOPES = [
  'mpp.budget', 'mpp.unbudget', 'mpp.fulfill',
  'recruitment.manage',
  'leave.long_term', 'resignation.approve',
  'severance.high_value', 'reimbursement.high_value', 'reimbursement.approve',
  'payroll.manage', 'payroll.finalize', 'allowance.manage',
  'training.manage', 'performance.manage',
  'attendance.review_anomaly',
  'disciplinary.issue',
  'onboarding.it_setup',
]

const canManage = computed(() => auth.can('org_structure.manage'))

// ─── Fetch ──────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const params = {}
    if (filters.q) params.q = filters.q
    if (filters.level) params.level = filters.level
    if (filters.has_scope) params.has_scope = filters.has_scope
    if (filters.assigned_only) params.assigned_only = 1
    const res = await api.get('/organization-roles', { params })
    roles.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('organization_roles.load_failed'))
  } finally {
    loading.value = false
  }
}

async function loadDeps() {
  try {
    const [eRes, dRes, bRes] = await Promise.all([
      api.get('/employees', { params: { per_page: 500 } }).catch(() => ({ data: { data: [] } })),
      api.get('/departments', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
      api.get('/branches', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } })),
    ])
    employees.value = (eRes.data.data || []).filter(e => e.is_active !== 0)
    departments.value = dRes.data.data || []
    branches.value = bRes.data.data || []
  } catch { /* deps optional */ }
}

onMounted(() => { load(); loadDeps() })

function applyFilter() { load() }
function resetFilter() {
  filters.q = ''; filters.level = ''; filters.has_scope = ''; filters.assigned_only = 0
  load()
}

// ─── Group by level untuk tampilan ─────────────────────────────────
const groupedRoles = computed(() => {
  const map = {}
  for (const r of roles.value) {
    if (! map[r.level]) map[r.level] = []
    map[r.level].push(r)
  }
  // Urutkan groups sesuai LEVELS order
  return LEVELS.value
    .filter(l => map[l.value]?.length)
    .map(l => ({ ...l, items: map[l.value] }))
})

// ─── Inline assign handlers ────────────────────────────────────────
function openAssign(role) {
  showAssign.value = role
  assignForm.employee_id = role.employee_id ?? null
  assignForm.backup_employee_ids = Array.isArray(role.backup_employee_ids) ? [...role.backup_employee_ids] : []
}

async function saveAssign() {
  if (! showAssign.value) return
  try {
    await api.post(`/organization-roles/${showAssign.value.id}/assign`, {
      employee_id: assignForm.employee_id,
      backup_employee_ids: assignForm.backup_employee_ids,
    })
    toast.success(assignForm.employee_id ? t('organization_roles.assigned') : t('organization_roles.cleared'))
    showAssign.value = null
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || t('organization_roles.save_failed'))
  }
}

function toggleBackup(empId) {
  const idx = assignForm.backup_employee_ids.indexOf(empId)
  if (idx >= 0) assignForm.backup_employee_ids.splice(idx, 1)
  else assignForm.backup_employee_ids.push(empId)
}

// ─── CRUD form ──────────────────────────────────────────────────────
function openCreate() {
  editing.value = {
    id: 0, role_key: '', title: '', level: 'MANAGER', description: '',
    department_id: null, branch_id: null, parent_role_id: null,
    employee_id: null, backup_employee_ids: [], approval_scopes: [],
    is_key_role: 1, is_active: 1, sort_order: 0,
  }
  showForm.value = true
}

function openEdit(role) {
  editing.value = JSON.parse(JSON.stringify({
    ...role,
    backup_employee_ids: Array.isArray(role.backup_employee_ids) ? role.backup_employee_ids : [],
    approval_scopes: Array.isArray(role.approval_scopes) ? role.approval_scopes : [],
  }))
  showForm.value = true
}

async function saveRole() {
  if (! editing.value.title?.trim()) { toast.warning(t('organization_roles.title_required')); return }
  if (! editing.value.id && ! editing.value.role_key?.trim()) {
    toast.warning(t('organization_roles.role_key_required'))
    return
  }
  if (editing.value.role_key && ! /^[A-Z][A-Z0-9_]*$/.test(editing.value.role_key)) {
    toast.warning(t('organization_roles.role_key_format'))
    return
  }
  const payload = { ...editing.value }
  // Drop role_key kalau update — backend ignore juga
  if (editing.value.id) delete payload.role_key
  try {
    if (editing.value.id) {
      await api.put(`/organization-roles/${editing.value.id}`, payload)
      toast.success(t('organization_roles.updated'))
    } else {
      await api.post('/organization-roles', payload)
      toast.success(t('organization_roles.created'))
    }
    showForm.value = false
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || t('organization_roles.save_failed'))
  }
}

async function deleteRole(role) {
  const ok = await confirmDialog({
    title: t('organization_roles.delete_title'),
    message: t('organization_roles.delete_message', { title: role.title, key: role.role_key }),
    okText: t('organization_roles.delete_action'), variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/organization-roles/${role.id}`)
    toast.success(t('organization_roles.deleted'))
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || t('organization_roles.delete_failed'))
  }
}

// ─── Employee picker filter ─────────────────────────────────────────
const empSearch = ref('')
const filteredEmployees = computed(() => {
  if (! empSearch.value) return employees.value.slice(0, 100)
  const q = empSearch.value.toLowerCase()
  return employees.value.filter(e =>
    (e.full_name || '').toLowerCase().includes(q)
    || (e.employee_number || '').toLowerCase().includes(q)
  ).slice(0, 100)
})

function toggleScope(scope) {
  if (! editing.value) return
  if (! Array.isArray(editing.value.approval_scopes)) editing.value.approval_scopes = []
  const idx = editing.value.approval_scopes.indexOf(scope)
  if (idx >= 0) editing.value.approval_scopes.splice(idx, 1)
  else editing.value.approval_scopes.push(scope)
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <BuildingOffice2Icon class="w-7 h-7 text-primary-600" />
          Master Jabatan Kunci
        </h1>
        <p class="text-sm text-gray-500">
          Tetapkan siapa karyawan yang menempati jabatan strategis
          (Direktur, HR Manager, Finance Manager, dst). Dipakai sebagai
          single source of truth untuk routing approval di seluruh modul.
        </p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <ExcelImporter
          v-if="canManage"
          resource="organization_structures"
          button-label="Import Excel"
          @committed="load()"
        />
        <button class="btn-secondary" @click="load" :disabled="loading">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> Refresh
        </button>
        <button v-if="canManage" class="btn-primary" @click="openCreate">
          <PlusIcon class="w-4 h-4" /> Role Baru
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="card card-body mb-4">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div>
          <label class="label">Cari role / title</label>
          <input v-model="filters.q" class="input" placeholder="HRD_MANAGER, Direktur..." @keydown.enter="applyFilter" />
        </div>
        <div>
          <label class="label">Level</label>
          <SearchableSelect
            v-model="filters.level"
            :items="LEVELS"
            value-key="value"
            label-key="label"
            placeholder="Semua"
          />
        </div>
        <div>
          <label class="label">Approval scope</label>
          <SearchableSelect
            v-model="filters.has_scope"
            :items="STANDARD_SCOPES.map(s => ({ value: s, label: s }))"
            value-key="value"
            label-key="label"
            placeholder="Semua scope"
          />
        </div>
        <div class="flex items-end">
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input type="checkbox" :checked="!!filters.assigned_only"
              @change="filters.assigned_only = $event.target.checked ? 1 : 0; applyFilter()" />
            Hanya yang sudah ada penjabat
          </label>
        </div>
        <div class="flex items-end gap-2">
          <button class="btn-secondary flex-1" @click="applyFilter">Filter</button>
          <button class="btn-secondary" @click="resetFilter" title="Reset">↺</button>
        </div>
      </div>
    </div>

    <!-- Body: grouped by level -->
    <div v-if="loading" class="card card-body text-center text-gray-400 py-12">Memuat...</div>
    <div v-else-if="roles.length === 0" class="card card-body text-center text-gray-400 py-12">
      Tidak ada role yang cocok dengan filter.
    </div>
    <div v-else class="space-y-4">
      <div v-for="group in groupedRoles" :key="group.value" class="card">
        <div class="px-4 py-2 border-b bg-slate-50 flex items-center justify-between">
          <span class="font-semibold text-sm flex items-center gap-2">
            <span class="px-2 py-0.5 rounded text-[10px] uppercase tracking-wide font-semibold border"
              :class="group.badge">
              {{ group.label }}
            </span>
            <span class="text-gray-500">{{ group.items.length }} jabatan</span>
          </span>
        </div>
        <table class="table">
          <thead>
            <tr>
              <th>Jabatan</th>
              <th>Penjabat Saat Ini</th>
              <th>Cakupan / Scope</th>
              <th>Backup (PJS)</th>
              <th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in group.items" :key="r.id" class="align-middle">
              <td>
                <div class="font-medium">{{ r.title }}</div>
                <div class="flex items-center gap-2 text-xs text-gray-500 mt-0.5">
                  <span class="font-mono">{{ r.role_key }}</span>
                  <span v-if="r.department_name" class="px-1.5 py-px bg-gray-100 rounded">{{ r.department_name }}</span>
                  <span v-if="r.branch_name" class="px-1.5 py-px bg-gray-100 rounded">{{ r.branch_name }}</span>
                </div>
              </td>
              <td>
                <div v-if="r.is_vacant" class="text-xs text-amber-700 flex items-center gap-1">
                  <ExclamationCircleIcon class="w-4 h-4" />
                  Belum di-assign
                </div>
                <div v-else class="flex items-center gap-2">
                  <div class="w-7 h-7 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center text-xs font-semibold">
                    {{ (r.employee_name || '?').charAt(0) }}
                  </div>
                  <div>
                    <div class="text-sm font-medium">{{ r.employee_name }}</div>
                    <div class="text-[10px] text-gray-500 font-mono">{{ r.employee_number }}</div>
                  </div>
                  <CheckBadgeIcon v-if="r.user_id" class="w-4 h-4 text-emerald-500"
                    title="User account aktif — bisa terima notifikasi & approve" />
                </div>
              </td>
              <td>
                <div class="flex flex-wrap gap-1">
                  <span v-for="s in (r.approval_scopes || [])" :key="s"
                    class="text-[10px] px-1.5 py-0.5 bg-blue-50 text-blue-700 border border-blue-200 rounded font-mono">
                    {{ s }}
                  </span>
                  <span v-if="!(r.approval_scopes || []).length" class="text-[11px] text-gray-400 italic">tanpa scope</span>
                </div>
              </td>
              <td>
                <div v-if="(r.backup_employee_ids || []).length" class="flex items-center gap-1 text-xs">
                  <span class="text-gray-500">{{ r.backup_employee_ids.length }} PJS</span>
                </div>
                <div v-else class="text-xs text-gray-400">—</div>
              </td>
              <td class="text-right">
                <div class="flex items-center justify-end gap-1">
                  <button v-if="canManage"
                    class="text-blue-600 hover:text-blue-800 p-1" title="Set/ganti penjabat" @click="openAssign(r)">
                    <UserCircleIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage"
                    class="text-gray-600 hover:text-primary-600 p-1" title="Edit role" @click="openEdit(r)">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage"
                    class="text-red-600 hover:text-red-800 p-1" title="Hapus role" @click="deleteRole(r)">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal: Assign penjabat -->
    <div v-if="showAssign" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <div>
            <h2 class="font-semibold">Assign Penjabat</h2>
            <p class="text-xs text-gray-500">{{ showAssign.title }} ({{ showAssign.role_key }})</p>
          </div>
          <button class="text-gray-400 hover:text-gray-700" @click="showAssign = null">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <div class="px-5 py-4 space-y-3">
          <div>
            <label class="label">Karyawan Penjabat Utama</label>
            <input v-model="empSearch" class="input mb-2" placeholder="Cari nama / NIP..." />
            <div class="border rounded max-h-60 overflow-y-auto">
              <label class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm border-b">
                <input type="radio" :value="null" v-model="assignForm.employee_id" />
                <span class="italic text-gray-500">- Tidak ada (vacant) -</span>
              </label>
              <label v-for="e in filteredEmployees" :key="e.id"
                class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm border-b last:border-b-0">
                <input type="radio" :value="e.id" v-model="assignForm.employee_id" />
                <div class="flex-1">
                  <div class="font-medium">{{ e.full_name }}</div>
                  <div class="text-xs text-gray-500 font-mono">{{ e.employee_number }}</div>
                </div>
                <span v-if="!e.user_id" class="text-[10px] text-amber-600" title="Tidak punya user account — tidak bisa terima approval">
                  No login
                </span>
              </label>
            </div>
          </div>
          <div>
            <label class="label">Backup / Pejabat Pengganti Sementara (PJS)</label>
            <p class="text-xs text-gray-500 mb-1">
              Dipakai sebagai fallback bila penjabat utama vacant / cuti panjang.
            </p>
            <div class="border rounded max-h-40 overflow-y-auto p-2 space-y-1">
              <label v-for="e in filteredEmployees.filter(e => e.id !== assignForm.employee_id)"
                :key="'b-' + e.id"
                class="flex items-center gap-2 hover:bg-gray-50 cursor-pointer text-xs px-2 py-1 rounded">
                <input type="checkbox"
                  :checked="assignForm.backup_employee_ids.includes(e.id)"
                  @change="toggleBackup(e.id)" />
                <span class="truncate">{{ e.full_name }}</span>
                <span class="text-gray-400 font-mono">{{ e.employee_number }}</span>
              </label>
            </div>
            <div v-if="assignForm.backup_employee_ids.length" class="text-xs text-gray-500 mt-1">
              {{ assignForm.backup_employee_ids.length }} PJS dipilih
            </div>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showAssign = null">Batal</button>
          <button class="btn-primary" @click="saveAssign">Simpan</button>
        </div>
      </div>
    </div>

    <!-- Modal: Create / Edit role -->
    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">{{ editing.id ? 'Edit Role' : 'Role Baru' }}</h2>
          <button class="text-gray-400 hover:text-gray-700" @click="showForm = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <div class="px-5 py-4 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="label">Role Key * <span class="text-gray-400">(uppercase + _)</span></label>
            <input v-model="editing.role_key" class="input font-mono" :disabled="!! editing.id"
              placeholder="HRD_MANAGER" maxlength="64" />
            <p class="text-[10px] text-gray-500 mt-1">
              Identifier stabil yang dipakai di kode. Tidak bisa diubah setelah dibuat.
            </p>
          </div>
          <div>
            <label class="label">Title *</label>
            <input v-model="editing.title" class="input" placeholder="HR Manager" maxlength="200" />
          </div>
          <div>
            <label class="label">Level *</label>
            <SearchableSelect
              v-model="editing.level"
              :items="LEVELS"
              value-key="value"
              label-key="label"
              :clearable="false"
            />
          </div>
          <div>
            <label class="label">Sort Order</label>
            <input type="number" v-model.number="editing.sort_order" class="input" />
          </div>
          <div>
            <label class="label">Department Scope</label>
            <SearchableSelect
              v-model="editing.department_id"
              :items="departments"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="- Lintas dept / korporat -"
            />
          </div>
          <div>
            <label class="label">Branch Scope</label>
            <SearchableSelect
              v-model="editing.branch_id"
              :items="branches"
              value-key="id"
              label-key="name"
              :search-keys="['name','code','city']"
              placeholder="- Semua cabang -"
            />
          </div>
          <div>
            <label class="label">Parent Role (atasan strategis)</label>
            <SearchableSelect
              v-model="editing.parent_role_id"
              :items="roles.filter(r => r.id !== editing.id)"
              value-key="id"
              label-key="title"
              sub-label-key="role_key"
              :search-keys="['title','role_key']"
              placeholder="- Tanpa parent (top level) -"
            />
          </div>
          <div>
            <label class="label">Penjabat Saat Ini</label>
            <SearchableSelect
              v-model="editing.employee_id"
              :items="employees"
              value-key="id"
              label-key="full_name"
              sub-label-key="employee_number"
              :search-keys="['full_name','employee_number']"
              placeholder="- Vacant -"
            />
          </div>
          <div class="md:col-span-2">
            <label class="label">Approval Scopes</label>
            <p class="text-xs text-gray-500 mb-1">
              Modul/aksi yang di-approve oleh penjabat role ini.
              Dipakai oleh routing engine untuk filter dropdown approver.
            </p>
            <div class="flex flex-wrap gap-1.5">
              <button v-for="s in STANDARD_SCOPES" :key="s"
                type="button"
                class="px-2 py-0.5 rounded text-xs border transition-colors"
                :class="(editing.approval_scopes || []).includes(s)
                  ? 'bg-blue-100 text-blue-700 border-blue-300 font-medium'
                  : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                @click="toggleScope(s)">
                {{ s }}
              </button>
            </div>
          </div>
          <div class="md:col-span-2">
            <label class="label">Deskripsi</label>
            <textarea v-model="editing.description" class="input" rows="2"></textarea>
          </div>
          <div class="md:col-span-2 flex items-center gap-4 text-sm">
            <label class="flex items-center gap-2">
              <input type="checkbox" :checked="!!editing.is_active"
                @change="editing.is_active = $event.target.checked ? 1 : 0" />
              Aktif
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" :checked="!!editing.is_key_role"
                @change="editing.is_key_role = $event.target.checked ? 1 : 0" />
              Key role (muncul di picker)
            </label>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showForm = false">Batal</button>
          <button class="btn-primary" @click="saveRole">Simpan</button>
        </div>
      </div>
    </div>
  </div>
</template>
