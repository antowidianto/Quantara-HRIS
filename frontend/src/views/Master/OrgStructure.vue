<script setup>
/*
 * Organization Development — Easy Setting.
 *
 * Halaman drag-and-drop untuk menata struktur organisasi:
 *  - Tarik-lepas department untuk mengubah parent (hirarki).
 *  - Tarik-lepas posisi untuk pindah department.
 *  - Klik kartu department untuk edit nama / set manager.
 *  - Edit posisi → set tier (MANAGER/ASST_MANAGER/SUPERVISOR/STAFF).
 *
 * Setelah perubahan apapun, backend otomatis hitung ulang
 * `employees.manager_id` (direct report) berdasarkan tier + hirarki dept.
 *
 * Drag-and-drop pakai HTML5 native DnD API (tanpa dependency baru). Payload
 * via `dataTransfer` JSON `{ type: 'department'|'position', id: number }`.
 *
 * Komponen tree rekursif `DepartmentNode` dipisah ke file SFC sendiri supaya
 * bisa self-reference dengan benar dan tidak mengganggu default export
 * halaman. Lihat docs/ORG_STRUCTURE.md untuk pola pemakaian.
 */
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, BuildingOffice2Icon, UserGroupIcon, BriefcaseIcon,
  ArrowsRightLeftIcon, TrashIcon, ArrowPathIcon, PencilIcon,
  ArrowUturnLeftIcon, CheckCircleIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'
import DepartmentNode from './DepartmentNode.vue'

const toast = useToast()

// Tier list untuk dropdown. Backend juga mengembalikan list ini di
// payload /org-structure (`tiers`), tapi kita hardcode juga supaya UI
// tetap berfungsi sebelum payload pertama datang.
const TIERS = [
  { value: 'MANAGER',      label: 'Manager' },
  { value: 'ASST_MANAGER', label: 'Assistant Manager' },
  { value: 'SUPERVISOR',   label: 'Supervisor' },
  { value: 'STAFF',        label: 'Staff' },
]

// ─── State ─────────────────────────────────────────────────────────
const loading = ref(false)
const saving = ref(false)
const tree = ref([])                  // array of root departments (nested children)
const unassignedPositions = ref([])   // positions tanpa department_id
const stats = ref({ total_departments: 0, total_positions: 0, total_employees: 0 })
const expandedIds = ref(new Set())    // departments yang di-expand
const employees = ref([])             // untuk picker manager

// Pending moves — supaya user bisa undo / save batch.
const pendingMoves = ref([])
const dirty = computed(() => pendingMoves.value.length > 0)

// Modal edit department
const showEditModal = ref(false)
const editForm = reactive({ id: 0, code: '', name: '', description: '', manager_employee_id: '', is_active: 1, parent_id: null })
const editingNew = ref(false)

// Modal create/edit position (sekaligus tier picker)
const showCreatePosModal = ref(false)
const createPosForm = reactive({
  id: 0, code: '', name: '', description: '',
  department_id: null, job_grade_id: '', tier: 'STAFF', is_active: 1,
})
const editingPos = ref(false)
const jobGrades = ref([])

const flatDepartments = computed(() => flattenTree(tree.value))

// ─── Fetch ─────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const [structRes, empRes, gradesRes] = await Promise.all([
      api.get('/org-structure'),
      api.get('/employees', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
      api.get('/job-grades', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } })),
    ])
    tree.value = structRes.data.data.departments || []
    unassignedPositions.value = structRes.data.data.unassigned_positions || []
    stats.value = structRes.data.data.stats || stats.value
    employees.value = empRes.data.data || []
    jobGrades.value = gradesRes.data.data || []
    if (expandedIds.value.size === 0) {
      tree.value.forEach(d => expandedIds.value.add(d.id))
    }
    pendingMoves.value = []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat struktur organisasi')
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ─── Tree helpers ──────────────────────────────────────────────────
function flattenTree(nodes, out = []) {
  for (const n of nodes) {
    out.push(n)
    if (n.children?.length) flattenTree(n.children, out)
  }
  return out
}

function findNode(id, nodes = tree.value) {
  for (const n of nodes) {
    if (n.id === id) return n
    if (n.children?.length) {
      const f = findNode(id, n.children)
      if (f) return f
    }
  }
  return null
}

function findParent(id, nodes = tree.value, parent = null) {
  for (const n of nodes) {
    if (n.id === id) return parent
    if (n.children?.length) {
      const f = findParent(id, n.children, n)
      if (f !== undefined) return f
    }
  }
  return undefined
}

function isDescendant(ancestorId, candidateId) {
  const anc = findNode(ancestorId)
  if (! anc) return false
  const stack = [...(anc.children || [])]
  while (stack.length) {
    const cur = stack.pop()
    if (cur.id === candidateId) return true
    if (cur.children?.length) stack.push(...cur.children)
  }
  return false
}

function toggleExpand(id) {
  if (expandedIds.value.has(id)) expandedIds.value.delete(id)
  else expandedIds.value.add(id)
  expandedIds.value = new Set(expandedIds.value)
}

function expandAll() {
  flattenTree(tree.value).forEach(d => expandedIds.value.add(d.id))
  expandedIds.value = new Set(expandedIds.value)
}

function collapseAll() {
  expandedIds.value = new Set()
}

// ─── Drag-and-drop handlers ────────────────────────────────────────
const dragOverId = ref(null)
const dragOverRoot = ref(false)
const dragOverUnassigned = ref(false)

function onDragStart(e, type, id) {
  const payload = { type, id }
  e.dataTransfer.setData('text/plain', JSON.stringify(payload))
  e.dataTransfer.effectAllowed = 'move'
}

function onDragOver(e, targetId = null, opts = {}) {
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  if (opts.root) dragOverRoot.value = true
  else if (opts.unassigned) dragOverUnassigned.value = true
  else dragOverId.value = targetId
}

function onDragLeave(opts = {}) {
  if (opts.root) dragOverRoot.value = false
  else if (opts.unassigned) dragOverUnassigned.value = false
  else dragOverId.value = null
}

function readPayload(e) {
  try { return JSON.parse(e.dataTransfer.getData('text/plain') || '{}') } catch { return {} }
}

function onDrop(e, targetDeptId) {
  e.preventDefault()
  e.stopPropagation()
  dragOverId.value = null
  dragOverRoot.value = false
  dragOverUnassigned.value = false

  const { type, id } = readPayload(e)
  if (! type || ! id) return

  if (type === 'department') {
    moveDepartment(id, targetDeptId)
  } else if (type === 'position') {
    movePosition(id, targetDeptId)
  }
}

function onDropUnassigned(e) {
  e.preventDefault()
  e.stopPropagation()
  dragOverId.value = null
  dragOverUnassigned.value = false

  const { type, id } = readPayload(e)
  if (type !== 'position' || ! id) return
  movePosition(id, null)
}

// ─── Move ops (optimistic, batched) ────────────────────────────────
function moveDepartment(deptId, newParentId) {
  if (deptId === newParentId) {
    toast.warning('Department tidak bisa jadi parent sendiri')
    return
  }
  if (newParentId !== null && isDescendant(deptId, newParentId)) {
    toast.warning('Tidak bisa pindah ke descendant sendiri (siklus)')
    return
  }

  const dept = findNode(deptId)
  if (! dept) return
  const oldParent = findParent(deptId)
  const oldParentId = oldParent ? oldParent.id : null
  if (oldParentId === newParentId) return

  if (oldParent) {
    oldParent.children = oldParent.children.filter(c => c.id !== deptId)
  } else {
    tree.value = tree.value.filter(d => d.id !== deptId)
  }
  dept.parent_id = newParentId
  if (newParentId === null) {
    tree.value.push(dept)
  } else {
    const newParent = findNode(newParentId)
    if (! newParent) {
      tree.value.push(dept)
      toast.error('Parent baru tidak ditemukan')
      return
    }
    newParent.children = [...(newParent.children || []), dept]
    expandedIds.value.add(newParentId)
    expandedIds.value = new Set(expandedIds.value)
  }

  const existing = pendingMoves.value.find(m => m.type === 'department' && m.id === deptId)
  if (existing) {
    existing.newParent = newParentId
    if (existing.oldParent === existing.newParent) {
      pendingMoves.value = pendingMoves.value.filter(m => m !== existing)
    }
  } else {
    pendingMoves.value.push({ type: 'department', id: deptId, oldParent: oldParentId, newParent: newParentId, name: dept.name })
  }
}

function movePosition(posId, newDeptId) {
  let pos = null
  let oldDeptId = null

  const u = unassignedPositions.value.find(p => p.id === posId)
  if (u) {
    pos = u
    oldDeptId = null
  } else {
    const all = flattenTree(tree.value)
    for (const d of all) {
      const idx = (d.positions || []).findIndex(p => p.id === posId)
      if (idx >= 0) {
        pos = d.positions[idx]
        oldDeptId = d.id
        break
      }
    }
  }
  if (! pos) return
  if (oldDeptId === newDeptId) return

  if (oldDeptId === null) {
    unassignedPositions.value = unassignedPositions.value.filter(p => p.id !== posId)
  } else {
    const oldDept = findNode(oldDeptId)
    if (oldDept) oldDept.positions = (oldDept.positions || []).filter(p => p.id !== posId)
  }
  pos.department_id = newDeptId
  if (newDeptId === null) {
    unassignedPositions.value.push(pos)
  } else {
    const newDept = findNode(newDeptId)
    if (! newDept) {
      unassignedPositions.value.push(pos)
      toast.error('Department tujuan tidak ditemukan')
      return
    }
    newDept.positions = [...(newDept.positions || []), pos]
    expandedIds.value.add(newDeptId)
    expandedIds.value = new Set(expandedIds.value)
  }

  const existing = pendingMoves.value.find(m => m.type === 'position' && m.id === posId)
  if (existing) {
    existing.newDept = newDeptId
    if (existing.oldDept === existing.newDept) {
      pendingMoves.value = pendingMoves.value.filter(m => m !== existing)
    }
  } else {
    pendingMoves.value.push({ type: 'position', id: posId, oldDept: oldDeptId, newDept: newDeptId, name: pos.name })
  }
}

// ─── Save / Discard pending changes ────────────────────────────────
async function saveChanges() {
  if (! dirty.value) return
  saving.value = true
  try {
    const deptMoves = pendingMoves.value.filter(m => m.type === 'department')
    const posMoves = pendingMoves.value.filter(m => m.type === 'position')

    if (deptMoves.length) {
      await api.post('/org-structure/bulk-reorder', {
        moves: deptMoves.map(m => ({ department_id: m.id, new_parent_id: m.newParent })),
      })
    }

    for (const m of posMoves) {
      await api.post('/org-structure/move-position', {
        position_id: m.id,
        new_department_id: m.newDept,
      })
    }

    toast.success(`${pendingMoves.value.length} perubahan tersimpan & direct report disesuaikan`)
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan perubahan')
  } finally {
    saving.value = false
  }
}

async function discardChanges() {
  if (! dirty.value) return
  const ok = await confirmDialog({
    title: 'Batalkan perubahan',
    message: `Ada ${pendingMoves.value.length} perubahan yang belum disimpan. Yakin batalkan dan muat ulang?`,
    okText: 'Batalkan',
    variant: 'danger',
  })
  if (! ok) return
  await load()
}

// ─── Department CRUD ───────────────────────────────────────────────
function openCreateDept(parentId = null) {
  Object.assign(editForm, {
    id: 0, code: '', name: '', description: '', manager_employee_id: '', is_active: 1, parent_id: parentId,
  })
  editingNew.value = true
  showEditModal.value = true
}

function openEditDept(dept) {
  Object.assign(editForm, {
    id: dept.id,
    code: dept.code || '',
    name: dept.name || '',
    description: dept.description || '',
    manager_employee_id: dept.manager_employee_id || '',
    is_active: dept.is_active ?? 1,
    parent_id: dept.parent_id,
  })
  editingNew.value = false
  showEditModal.value = true
}

async function saveDept() {
  if (! editForm.name?.trim() || ! editForm.code?.trim()) {
    toast.warning('Kode dan nama wajib diisi')
    return
  }
  try {
    const payload = {
      code: editForm.code,
      name: editForm.name,
      description: editForm.description,
      manager_employee_id: editForm.manager_employee_id || null,
      is_active: editForm.is_active ? 1 : 0,
      parent_id: editForm.parent_id || null,
    }
    if (editingNew.value) {
      await api.post('/departments', payload)
      toast.success('Department dibuat')
    } else {
      await api.put(`/departments/${editForm.id}`, payload)
      // set-manager via endpoint dedicated supaya direct report di
      // subtree dept ini ikut di-recompute.
      await api.post('/org-structure/set-manager', {
        department_id: editForm.id,
        manager_employee_id: payload.manager_employee_id,
      }).catch(() => {})
      toast.success('Department diperbarui')
    }
    showEditModal.value = false
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan department')
  }
}

async function deleteDept(dept) {
  const childCount = (dept.children?.length || 0)
  const posCount = (dept.positions?.length || 0)
  const empCount = dept.employee_count || 0

  if (childCount > 0 || posCount > 0 || empCount > 0) {
    toast.warning(`Tidak bisa hapus: ${childCount} sub-dept, ${posCount} posisi, ${empCount} karyawan masih terhubung`)
    return
  }
  const ok = await confirmDialog({
    title: 'Hapus department',
    message: `Yakin hapus department "${dept.name}"? Tindakan tidak bisa dibatalkan.`,
    okText: 'Hapus',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/departments/${dept.id}`)
    toast.success('Department dihapus')
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus department')
  }
}

// ─── Position CRUD ─────────────────────────────────────────────────
function openCreatePos(deptId = null) {
  Object.assign(createPosForm, {
    id: 0, code: '', name: '', description: '',
    department_id: deptId, job_grade_id: '', tier: 'STAFF', is_active: 1,
  })
  editingPos.value = false
  showCreatePosModal.value = true
}

function openEditPos(pos) {
  Object.assign(createPosForm, {
    id: pos.id,
    code: pos.code || '',
    name: pos.name || '',
    description: pos.description || '',
    department_id: pos.department_id || null,
    job_grade_id: pos.job_grade_id || '',
    tier: pos.tier || 'STAFF',
    is_active: pos.is_active ?? 1,
  })
  editingPos.value = true
  showCreatePosModal.value = true
}

async function savePos() {
  if (! createPosForm.name?.trim() || ! createPosForm.code?.trim()) {
    toast.warning('Kode dan nama wajib diisi')
    return
  }
  const payload = {
    code: createPosForm.code,
    name: createPosForm.name,
    description: createPosForm.description,
    department_id: createPosForm.department_id || null,
    job_grade_id: createPosForm.job_grade_id || null,
    tier: createPosForm.tier || 'STAFF',
    is_active: createPosForm.is_active ? 1 : 0,
  }
  try {
    if (editingPos.value && createPosForm.id) {
      await api.put(`/positions/${createPosForm.id}`, payload)
      // Tier berubah ⇒ rantai laporan bisa berubah. Trigger recompute
      // company-wide. Backend juga dipanggil saat move-position;
      // di sini kita panggil eksplisit untuk perubahan tier saja.
      await api.post('/org-structure/recompute-reports').catch(() => {})
      toast.success('Posisi diperbarui & direct report disesuaikan')
    } else {
      await api.post('/positions', payload)
      toast.success('Posisi dibuat')
    }
    showCreatePosModal.value = false
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan posisi')
  }
}

async function deletePos(pos) {
  const ok = await confirmDialog({
    title: 'Hapus posisi',
    message: `Yakin hapus posisi "${pos.name}"?`,
    okText: 'Hapus',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/positions/${pos.id}`)
    toast.success('Posisi dihapus')
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus posisi')
  }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <BuildingOffice2Icon class="w-7 h-7 text-primary-600" />
          Organization Development
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Tarik-lepas department / posisi untuk menata struktur organisasi.
          Setiap perubahan otomatis menyesuaikan <span class="font-medium">atasan langsung</span>
          karyawan berdasarkan tier (Manager → Asst Manager → Supervisor → Staff).
        </p>
      </div>

      <div class="flex items-center gap-2">
        <button class="btn-secondary" @click="expandAll">Buka Semua</button>
        <button class="btn-secondary" @click="collapseAll">Tutup Semua</button>
        <button class="btn-secondary" @click="load" :disabled="loading || saving">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
          Refresh
        </button>
        <button class="btn-primary" @click="openCreateDept()">
          <PlusIcon class="w-4 h-4" /> Department
        </button>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
      <div class="card p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
          <BuildingOffice2Icon class="w-6 h-6 text-blue-600" />
        </div>
        <div>
          <div class="text-xs text-gray-500">Total Department</div>
          <div class="text-xl font-bold">{{ stats.total_departments }}</div>
        </div>
      </div>
      <div class="card p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
          <BriefcaseIcon class="w-6 h-6 text-emerald-600" />
        </div>
        <div>
          <div class="text-xs text-gray-500">Total Posisi</div>
          <div class="text-xl font-bold">{{ stats.total_positions }}</div>
        </div>
      </div>
      <div class="card p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center">
          <UserGroupIcon class="w-6 h-6 text-purple-600" />
        </div>
        <div>
          <div class="text-xs text-gray-500">Total Karyawan</div>
          <div class="text-xl font-bold">{{ stats.total_employees }}</div>
        </div>
      </div>
    </div>

    <!-- Pending changes banner -->
    <transition name="fade">
      <div v-if="dirty"
        class="mb-3 p-3 rounded-lg border border-amber-300 bg-amber-50 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-amber-900 text-sm">
          <ArrowsRightLeftIcon class="w-5 h-5" />
          <span>
            <span class="font-medium">{{ pendingMoves.length }}</span> perubahan belum disimpan
          </span>
        </div>
        <div class="flex items-center gap-2">
          <button class="btn-secondary" :disabled="saving" @click="discardChanges">
            <ArrowUturnLeftIcon class="w-4 h-4" /> Batal
          </button>
          <button class="btn-primary" :disabled="saving" @click="saveChanges">
            <CheckCircleIcon class="w-4 h-4" />
            {{ saving ? 'Menyimpan...' : 'Simpan Perubahan' }}
          </button>
        </div>
      </div>
    </transition>

    <!-- Tree + sidebar layout -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
      <!-- Tree -->
      <div class="lg:col-span-3">
        <div class="card p-4 min-h-[60vh]">
          <div v-if="loading" class="text-center text-gray-400 py-12">Memuat struktur...</div>
          <template v-else>
            <!-- Root drop zone -->
            <div
              class="rounded-md border-2 border-dashed p-3 mb-3 text-xs text-center transition-colors"
              :class="dragOverRoot ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-400'"
              @dragover="onDragOver($event, null, { root: true })"
              @dragleave="onDragLeave({ root: true })"
              @drop="onDrop($event, null)"
            >
              Drop di sini untuk menjadikan department <span class="font-medium">level paling atas</span>
            </div>

            <div v-if="tree.length === 0" class="text-center text-gray-400 py-8">
              Belum ada department. Klik tombol <span class="font-medium">+ Department</span> di kanan atas.
            </div>

            <ul class="space-y-1">
              <DepartmentNode
                v-for="d in tree"
                :key="d.id"
                :node="d"
                :level="0"
                :expanded-ids="expandedIds"
                :drag-over-id="dragOverId"
                @toggle="toggleExpand"
                @drop-on="onDrop"
                @drag-over="(e, id) => onDragOver(e, id)"
                @drag-leave="() => onDragLeave()"
                @drag-start="onDragStart"
                @edit="openEditDept"
                @delete="deleteDept"
                @add-child="openCreateDept"
                @add-position="openCreatePos"
                @delete-position="deletePos"
                @edit-position="openEditPos"
              />
            </ul>
          </template>
        </div>
      </div>

      <!-- Side panel: unassigned positions + legend -->
      <aside class="lg:col-span-1 space-y-3">
        <div class="card p-4">
          <div class="flex items-center justify-between mb-2">
            <h3 class="font-semibold text-sm flex items-center gap-1.5">
              <BriefcaseIcon class="w-4 h-4" /> Posisi Belum Ditugaskan
            </h3>
            <button class="text-xs text-primary-600 hover:underline" @click="openCreatePos(null)">+ Tambah</button>
          </div>
          <p class="text-xs text-gray-500 mb-3">Tarik posisi ke department untuk menugaskan.</p>

          <div
            class="rounded-md border-2 border-dashed p-2 transition-colors min-h-[80px]"
            :class="dragOverUnassigned ? 'border-primary-500 bg-primary-50' : 'border-gray-200'"
            @dragover="onDragOver($event, null, { unassigned: true })"
            @dragleave="onDragLeave({ unassigned: true })"
            @drop="onDropUnassigned"
          >
            <div v-if="unassignedPositions.length === 0" class="text-xs text-gray-400 text-center py-3">
              Semua posisi sudah ditugaskan
            </div>
            <ul v-else class="space-y-1">
              <li v-for="p in unassignedPositions" :key="p.id"
                class="flex items-center gap-2 px-2 py-1.5 rounded bg-gray-50 hover:bg-gray-100 cursor-move text-xs group/up"
                draggable="true"
                @dragstart="onDragStart($event, 'position', p.id)">
                <BriefcaseIcon class="w-3.5 h-3.5 text-gray-400" />
                <span class="truncate flex-1">{{ p.name }}</span>
                <span class="text-[9px] uppercase tracking-wide text-gray-400">{{ p.tier || 'STAFF' }}</span>
                <button class="text-gray-400 hover:text-primary-600 opacity-0 group-hover/up:opacity-100"
                  @click="openEditPos(p)" title="Edit posisi">
                  <PencilIcon class="w-3.5 h-3.5" />
                </button>
                <button class="text-gray-400 hover:text-red-600 opacity-0 group-hover/up:opacity-100"
                  @click="deletePos(p)" title="Hapus posisi">
                  <TrashIcon class="w-3.5 h-3.5" />
                </button>
              </li>
            </ul>
          </div>
        </div>

        <!-- Legenda tier -->
        <div class="card p-4">
          <h3 class="font-semibold text-sm text-gray-800 mb-2">Tier Posisi</h3>
          <div class="space-y-1 text-xs">
            <div class="flex items-center gap-2">
              <span class="w-3 h-3 rounded bg-rose-200"></span>
              <span class="font-medium">Manager</span>
              <span class="text-gray-400">— kepala department</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="w-3 h-3 rounded bg-amber-200"></span>
              <span class="font-medium">Asst Manager</span>
              <span class="text-gray-400">— wakil manager</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="w-3 h-3 rounded bg-blue-200"></span>
              <span class="font-medium">Supervisor</span>
              <span class="text-gray-400">— pengawas tim</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="w-3 h-3 rounded bg-emerald-200"></span>
              <span class="font-medium">Staff</span>
              <span class="text-gray-400">— pelaksana</span>
            </div>
          </div>
        </div>

        <div class="card p-4 text-xs text-gray-600 space-y-2">
          <h3 class="font-semibold text-sm text-gray-800">Petunjuk</h3>
          <ul class="list-disc list-inside space-y-1">
            <li>Tarik <span class="font-medium">department</span> ke department lain untuk menjadikannya sub-dept.</li>
            <li>Tarik <span class="font-medium">posisi</span> antar department untuk re-assignment.</li>
            <li>Klik <span class="font-medium">edit posisi</span> untuk ubah tier.</li>
            <li>Atasan langsung karyawan otomatis disesuaikan setelah simpan.</li>
          </ul>
        </div>
      </aside>
    </div>

    <!-- Modal: Edit/Create Department -->
    <div v-if="showEditModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">{{ editingNew ? 'Department Baru' : 'Edit Department' }}</h2>
          <button class="text-gray-400 hover:text-gray-700" @click="showEditModal = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <form @submit.prevent="saveDept" class="px-5 py-4 space-y-3">
          <div>
            <label class="label">Kode *</label>
            <input v-model="editForm.code" class="input" required maxlength="32" />
          </div>
          <div>
            <label class="label">Nama *</label>
            <input v-model="editForm.name" class="input" required maxlength="200" />
          </div>
          <div>
            <label class="label">Deskripsi</label>
            <textarea v-model="editForm.description" class="input" rows="2" />
          </div>
          <div v-if="! editingNew">
            <label class="label">Manager (opsional)</label>
            <select v-model="editForm.manager_employee_id" class="input">
              <option value="">- Tidak ada -</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">
                {{ e.full_name || e.name }}{{ e.employee_no ? ` (${e.employee_no})` : '' }}
              </option>
            </select>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" :checked="!! editForm.is_active"
              @change="editForm.is_active = $event.target.checked ? 1 : 0" />
            Aktif
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showEditModal = false">Batal</button>
            <button type="submit" class="btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: Create / Edit Position -->
    <div v-if="showCreatePosModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">{{ editingPos ? 'Edit Posisi' : 'Posisi Baru' }}</h2>
          <button class="text-gray-400 hover:text-gray-700" @click="showCreatePosModal = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <form @submit.prevent="savePos" class="px-5 py-4 space-y-3">
          <div>
            <label class="label">Kode *</label>
            <input v-model="createPosForm.code" class="input" required maxlength="32" />
          </div>
          <div>
            <label class="label">Nama *</label>
            <input v-model="createPosForm.name" class="input" required maxlength="200" />
          </div>
          <div>
            <label class="label">Tier *</label>
            <select v-model="createPosForm.tier" class="input" required>
              <option v-for="t in TIERS" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">
              Tier menentukan rantai laporan: Staff → Supervisor → Asst Manager → Manager.
              Atasan langsung karyawan akan otomatis dihitung ulang.
            </p>
          </div>
          <div>
            <label class="label">Department</label>
            <select v-model="createPosForm.department_id" class="input">
              <option :value="null">- Belum ditugaskan -</option>
              <option v-for="d in flatDepartments" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
          </div>
          <div>
            <label class="label">Job Grade (opsional)</label>
            <select v-model="createPosForm.job_grade_id" class="input">
              <option value="">- Tidak ada -</option>
              <option v-for="g in jobGrades" :key="g.id" :value="g.id">{{ g.name }}</option>
            </select>
          </div>
          <div>
            <label class="label">Deskripsi</label>
            <textarea v-model="createPosForm.description" class="input" rows="2" />
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showCreatePosModal = false">Batal</button>
            <button type="submit" class="btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>