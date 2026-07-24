<script setup>
/*
 * Bagan Struktur Organisasi — versi simplified.
 *
 * Filosofi UI:
 *   - Satu task per layar: lihat bagan + edit struktur. Jangan tampilkan
 *     legend, MPP, dan tips bersamaan — masukin ke modal "Bantuan".
 *   - Aksi utama jelas: tombol "Lihat & Atur" di tiap kartu untuk buka
 *     modal detail karyawan/posisi. Drag-drop tetap ada tapi optional.
 *   - Stats ringkas di header (1 baris), bukan 4 kartu besar.
 *   - Pending changes muncul sebagai sticky bar di atas, bukan banner
 *     yang gampang ke-skip.
 *
 * Editing flow:
 *   1. Klik "Lihat & Atur" pada kartu → modal detail (ini interface utama
 *      untuk pindahin karyawan, ubah jabatan, lihat sub-dept).
 *   2. Drag kartu department ke department lain → ubah parent (preview,
 *      perlu klik Simpan).
 *   3. Tombol + di header → tambah dept baru.
 *   4. Menu 3-titik di kartu → edit/hapus/sub-dept.
 *
 * Lihat docs/ORG_STRUCTURE.md.
 */
import { ref, computed, onMounted, onBeforeUnmount, reactive, watch } from 'vue'
import { useI18n } from '@/locales'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useAuthStore } from '@/stores/auth'
import {
  PlusIcon, BuildingOffice2Icon,
  ArrowsRightLeftIcon, ArrowPathIcon, ArrowUturnLeftIcon,
  CheckCircleIcon, XMarkIcon, ClipboardDocumentListIcon,
  QuestionMarkCircleIcon, ChevronRightIcon, ChevronDownIcon,
} from '@heroicons/vue/24/outline'
import DepartmentNode from './DepartmentNode.vue'
import DepartmentDetailModal from './DepartmentDetailModal.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

const toast = useToast()
const auth = useAuthStore()
const { t } = useI18n()
const canManageStructure = computed(() => auth.can('org_structure.manage'))
const canManageDepartments = computed(() => auth.can('department.manage'))

// ─── State ─────────────────────────────────────────────────────────
const loading = ref(false)
const saving = ref(false)
const tree = ref([])
const stats = ref({ total_departments: 0, total_positions: 0, total_employees: 0, total_open_mpps: 0 })
const collapsedIds = ref(new Set())
const employees = ref([])
const selectedDeptId = ref('')
const viewMode = ref('chart')
const chartViewport = ref(null)
const pan = reactive({ x: 0, y: 0 })
const panning = reactive({ active: false, pointerId: null, startX: 0, startY: 0, originX: 0, originY: 0 })

// Detail modal
const detailDeptId = ref(null)
function openDeptDetail(dept) { detailDeptId.value = dept.id }
function closeDeptDetail()    { detailDeptId.value = null }
function navigateToDept(id)   { detailDeptId.value = id }
async function onDetailChanged() { await load() }

// Help modal
const showHelp = ref(false)

// Pending moves untuk drag-drop department
const pendingMoves = ref([])
const dirty = computed(() => pendingMoves.value.length > 0)

// Modal create/edit dept
const showEditModal = ref(false)
const editForm = reactive({
  id: 0, code: '', name: '', description: '',
  manager_employee_id: '', is_active: 1, parent_id: null,
})
const editingNew = ref(false)

const flatDepartments = computed(() => flattenTree(tree.value))
const departmentOptions = computed(() => flattenDepartmentOptions(tree.value))
const selectedDept = computed(() => selectedDeptId.value ? findNode(Number(selectedDeptId.value)) : null)
const visibleTree = computed(() => {
  return selectedDept.value ? [selectedDept.value] : []
})
const visibleRows = computed(() => flattenRows(visibleTree.value))

// ─── Fetch ─────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const [structRes, empRes] = await Promise.all([
      api.get('/org-structure'),
      api.get('/employees', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
    ])
    tree.value = structRes.data.data.departments || []
    if (selectedDeptId.value && !findNode(Number(selectedDeptId.value), tree.value)) {
      selectedDeptId.value = ''
    }
    stats.value = structRes.data.data.stats || stats.value
    employees.value = empRes.data.data || []
    pendingMoves.value = []
  } catch (e) {
    toast.error(e.response?.data?.message || t('org_structure.load_failed'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => {
  window.removeEventListener('pointermove', onPanMove)
  window.removeEventListener('pointerup', stopPan)
})

// Super admin bisa pindah-pindah company. Kalau tab/company yang aktif
// berubah, draft pendingMoves dari company sebelumnya tidak relevan lagi
// (id department-nya tidak ada di tree baru) — buang dan reload supaya
// user tidak dapat error "Department #X tidak ditemukan dalam scope".
watch(() => auth.activeCompanyId, (newId, oldId) => {
  if (newId === oldId) return
  if (pendingMoves.value.length > 0) {
    toast.info(t('org_structure.company_changed'))
    pendingMoves.value = []
  }
  load()
})

// ─── Tree helpers ──────────────────────────────────────────────────
function flattenTree(nodes, out = []) {
  for (const n of nodes) {
    out.push(n)
    if (n.children?.length) flattenTree(n.children, out)
  }
  return out
}
function flattenDepartmentOptions(nodes, depth = 0, out = []) {
  for (const n of nodes) {
    const prefix = depth > 0 ? `${'-- '.repeat(Math.min(depth, 6))}` : ''
    out.push({
      ...n,
      depth,
      display_name: `${prefix}${n.name}`,
      search_text: `${n.name} ${n.code || ''}`,
    })
    if (n.children?.length) flattenDepartmentOptions(n.children, depth + 1, out)
  }
  return out
}
function flattenRows(nodes, depth = 0, out = []) {
  for (const n of nodes) {
    out.push({ node: n, depth })
    if (!collapsedIds.value.has(n.id) && n.children?.length) {
      flattenRows(n.children, depth + 1, out)
    }
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
function toggleCollapse(id) {
  if (collapsedIds.value.has(id)) collapsedIds.value.delete(id)
  else collapsedIds.value.add(id)
  collapsedIds.value = new Set(collapsedIds.value)
}

function startPan(e) {
  if (viewMode.value !== 'chart' || e.button !== 0) return
  if (e.target?.closest?.('.org-card, button, input, select, textarea, a')) return

  panning.active = true
  panning.pointerId = e.pointerId
  panning.startX = e.clientX
  panning.startY = e.clientY
  panning.originX = pan.x
  panning.originY = pan.y
  chartViewport.value?.setPointerCapture?.(e.pointerId)
  window.addEventListener('pointermove', onPanMove)
  window.addEventListener('pointerup', stopPan)
}

function onPanMove(e) {
  if (!panning.active) return
  pan.x = panning.originX + (e.clientX - panning.startX)
  pan.y = panning.originY + (e.clientY - panning.startY)
}

function stopPan() {
  if (!panning.active) return
  chartViewport.value?.releasePointerCapture?.(panning.pointerId)
  panning.active = false
  panning.pointerId = null
  window.removeEventListener('pointermove', onPanMove)
  window.removeEventListener('pointerup', stopPan)
}

function resetPan() {
  pan.x = 0
  pan.y = 0
}

function selectDepartment(id) {
  selectedDeptId.value = String(id)
  resetPan()
}

// ─── Drag-and-drop department (cuma move parent) ───────────────────
const dragOverId = ref(null)

function onDragStart(e, type, id) {
  // Hanya support type='department' di halaman ini.
  // Type position/mpp dipindah dari modal detail karena lebih kontekstual.
  e.dataTransfer.setData('text/plain', JSON.stringify({ type, id }))
  e.dataTransfer.effectAllowed = 'move'
}
function onDragOver(e, targetId) {
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  dragOverId.value = targetId
}
function onDragLeave() { dragOverId.value = null }

function onDrop(e, targetDeptId) {
  e.preventDefault()
  e.stopPropagation()
  dragOverId.value = null
  let payload = {}
  try { payload = JSON.parse(e.dataTransfer.getData('text/plain') || '{}') } catch {}
  if (payload.type !== 'department' || ! payload.id) return
  moveDepartment(payload.id, targetDeptId)
}

function moveDepartment(deptId, newParentId) {
  if (deptId === newParentId) {
    toast.warning(t('org_structure.self_parent')); return
  }
  if (newParentId !== null && isDescendant(deptId, newParentId)) {
    toast.warning(t('org_structure.cycle_parent')); return
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
    if (! newParent) return
    newParent.children = [...(newParent.children || []), dept]
    collapsedIds.value.delete(newParentId)
    collapsedIds.value = new Set(collapsedIds.value)
  }

  const existing = pendingMoves.value.find(m => m.id === deptId)
  if (existing) {
    existing.newParent = newParentId
    if (existing.oldParent === existing.newParent) {
      pendingMoves.value = pendingMoves.value.filter(m => m !== existing)
    }
  } else {
    pendingMoves.value.push({ id: deptId, oldParent: oldParentId, newParent: newParentId, name: dept.name })
  }
}

async function saveChanges() {
  if (! dirty.value) return

  // Pruning: drop moves yang merujuk ke department yang sudah hilang
  // dari tree saat ini (mungkin dihapus / dipindah di sesi/tab lain).
  // Ini cegah error 409 STALE_TREE_STATE dari backend.
  const liveIds = new Set(flatDepartments.value.map(d => d.id))
  const before = pendingMoves.value.length
  pendingMoves.value = pendingMoves.value.filter(m => {
    if (! liveIds.has(m.id)) return false
    if (m.newParent !== null && ! liveIds.has(m.newParent)) return false
    return true
  })
  const dropped = before - pendingMoves.value.length
  if (dropped > 0) {
    toast.warning(t('org_structure.stale_changes', { count: dropped }))
  }
  if (pendingMoves.value.length === 0) {
    await load()
    return
  }

  saving.value = true
  try {
    await api.post('/org-structure/bulk-reorder', {
      moves: pendingMoves.value.map(m => ({ department_id: m.id, new_parent_id: m.newParent })),
    })
    toast.success(t('org_structure.changes_saved', { count: pendingMoves.value.length }))
    await load()
  } catch (e) {
    const code = e.response?.data?.code
    const msg  = e.response?.data?.message || t('org_structure.changes_save_failed')
    if (code === 'STALE_TREE_STATE') {
      // Backend kasih tahu bahwa state di FE tidak sinkron dengan DB.
      // Buang draft, reload tree, kasih pesan informatif.
      toast.error(msg, { timeout: 8000 })
      pendingMoves.value = []
      await load()
    } else {
      toast.error(msg)
    }
  } finally {
    saving.value = false
  }
}

async function discardChanges() {
  const ok = await confirmDialog({
    title: t('org_structure.discard_title'),
    message: t('org_structure.discard_message', { count: pendingMoves.value.length }),
    okText: t('org_structure.discard_action'),
    variant: 'danger',
  })
  if (! ok) return
  await load()
}

async function recomputeReports() {
  saving.value = true
  let preview = null
  try {
    const res = await api.get('/org-structure/recompute-reports/preview')
    preview = res.data?.data || {}
  } catch (e) {
    saving.value = false
    toast.error(e.response?.data?.message || t('org_structure.sync_preview_failed'))
    return
  }
  saving.value = false

  const updated = preview.updated || 0
  const unchanged = preview.unchanged || 0
  const managerUpdated = preview.manager_updated || 0
  const supervisorUpdated = preview.supervisor_updated || 0
  const sampleNames = (preview.sample || [])
    .slice(0, 3)
    .map(row => row.employee_name || row.employee_number || t('org_structure.employee_fallback', { id: row.employee_id }))
    .join(', ')

  const ok = await confirmDialog({
    title: t('org_structure.sync_title'),
    message: updated > 0
      ? t('org_structure.sync_preview_changes', { updated, managerUpdated, supervisorUpdated, unchanged, sample: sampleNames ? t('org_structure.sync_preview_sample', { names: sampleNames }) : '' })
      : t('org_structure.sync_preview_none', { unchanged }),
    okText: t('org_structure.sync_action'),
  })
  if (!ok) return
  saving.value = true
  try {
    const res = await api.post('/org-structure/recompute-reports')
    const data = res.data?.data || {}
    toast.success(t('org_structure.sync_success', { count: data.updated || 0 }))
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || t('org_structure.sync_failed'))
  } finally {
    saving.value = false
  }
}

// ─── Department CRUD ───────────────────────────────────────────────
function openCreateDept(parentId = null) {
  Object.assign(editForm, {
    id: 0, code: '', name: '', description: '',
    manager_employee_id: '', is_active: 1, parent_id: parentId,
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
    toast.warning(t('org_structure.required_code_name')); return
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
      toast.success(t('org_structure.department_created'))
    } else {
      await api.put(`/departments/${editForm.id}`, payload)
      await api.post('/org-structure/set-manager', {
        department_id: editForm.id,
        manager_employee_id: payload.manager_employee_id,
      }).catch(() => {})
      toast.success(t('org_structure.department_updated'))
    }
    showEditModal.value = false
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || t('org_structure.save_failed'))
  }
}

async function deleteDept(dept) {
  const childCount = (dept.children?.length || 0)
  const empCount = dept.employee_count || 0
  if (childCount > 0 || empCount > 0) {
    toast.warning(t('org_structure.delete_blocked', { children: childCount, employees: empCount }))
    return
  }
  const ok = await confirmDialog({
    title: t('org_structure.delete_title'),
    message: t('org_structure.delete_message', { name: dept.name }),
    okText: t('org_structure.delete_action'), variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/departments/${dept.id}`)
    toast.success(t('org_structure.department_deleted'))
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || t('org_structure.delete_failed'))
  }
}
</script>

<template>
  <div class="max-w-full">
    <!-- ── Header ringkas ────────────────────────────────────────── -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div class="flex items-center gap-3">
        <BuildingOffice2Icon class="w-7 h-7 text-primary-600" />
        <div>
          <h1 class="text-xl font-bold text-slate-900 leading-none">{{ t('org_structure.title') }}</h1>
          <div class="text-xs text-slate-500 mt-1 flex items-center gap-3">
            <span>{{ t('org_structure.departments_count', { count: stats.total_departments }) }}</span>
            <span>·</span>
            <span>{{ t('org_structure.employees_count', { count: stats.total_employees }) }}</span>
            <span v-if="stats.total_open_mpps">·</span>
            <span v-if="stats.total_open_mpps" class="text-amber-700">
              {{ t('org_structure.vacancies_count', { count: stats.total_open_mpps }) }}
            </span>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <div class="inline-flex h-9 rounded-md border border-slate-200 bg-white p-0.5">
          <button
            type="button" class="px-3 rounded text-sm font-medium"
            :class="viewMode === 'chart' ? 'bg-primary-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'"
            @click="viewMode = 'chart'"
            v-bind="{ title: t('org_structure.chart_view') }"
          >
            <BuildingOffice2Icon class="w-4 h-4 inline-block mr-1" /> {{ t('org_structure.chart') }}
          </button>
          <button
            type="button" class="px-3 rounded text-sm font-medium"
            :class="viewMode === 'list' ? 'bg-primary-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'"
            @click="viewMode = 'list'"
            v-bind="{ title: t('org_structure.list_view') }"
          >
            <ClipboardDocumentListIcon class="w-4 h-4 inline-block mr-1" /> {{ t('org_structure.list') }}
          </button>
        </div>
        <div v-if="tree.length" class="w-72 max-w-full">
          <SearchableSelect
            v-model="selectedDeptId"
            :items="departmentOptions"
            value-key="id"
            label-key="display_name"
            sub-label-key="code"
            :search-keys="['name', 'code', 'search_text']"
            v-bind="{ placeholder: t('org_structure.select_department') }"
            size="sm"
            @change="resetPan"
          />
        </div>
        <button type="button" class="btn-secondary" @click="showHelp = true" v-bind="{ title: t('org_structure.help_title_attr') }">
          <QuestionMarkCircleIcon class="w-4 h-4" /> {{ t('org_structure.help') }}
        </button>
        <button type="button" class="btn-secondary" @click="load" :disabled="loading || saving">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> {{ t('org_structure.reload') }}
        </button>
        <button v-if="viewMode === 'chart'" type="button" class="btn-secondary" @click="resetPan" :disabled="loading || saving">
          <ArrowUturnLeftIcon class="w-4 h-4" /> {{ t('org_structure.center') }}
        </button>
        <button v-if="canManageStructure" type="button" class="btn-secondary" @click="recomputeReports" :disabled="loading || saving">
          <ArrowsRightLeftIcon class="w-4 h-4" /> {{ t('org_structure.sync_supervisors') }}
        </button>
        <button v-if="canManageDepartments" type="button" class="btn-primary" @click="openCreateDept()">
          <PlusIcon class="w-4 h-4" /> {{ t('org_structure.new_department') }}
        </button>
      </div>
    </div>

    <!-- ── Sticky bar untuk pending changes ────────────────────────── -->
    <transition name="slide">
      <div v-if="dirty && canManageStructure"
        class="sticky top-0 z-30 mb-4 px-4 py-3 rounded-lg border-2 border-amber-400 bg-amber-50 flex items-center justify-between gap-3 shadow-sm">
        <div class="flex items-center gap-2 text-amber-900 text-sm">
          <ArrowsRightLeftIcon class="w-5 h-5" />
          <div>
            <div class="font-semibold">{{ t('org_structure.pending_changes', { count: pendingMoves.length }) }}</div>
            <div class="text-xs text-amber-700">{{ t('org_structure.pending_help') }}</div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" class="btn-secondary" :disabled="saving" @click="discardChanges">
            <ArrowUturnLeftIcon class="w-4 h-4" /> {{ t('org_structure.cancel') }}
          </button>
          <button type="button" class="btn-primary" :disabled="saving" @click="saveChanges">
            <CheckCircleIcon class="w-4 h-4" />
            {{ saving ? t('org_structure.saving') : t('org_structure.save') }}
          </button>
        </div>
      </div>
    </transition>

    <!-- ── Canvas bagan ─────────────────────────────────────────────── -->
    <div class="card bg-slate-50/60 min-h-[60vh] p-4 sm:p-6">
      <div v-if="loading" class="text-center text-slate-400 py-16">
        {{ t('org_structure.loading_structure') }}
      </div>
      <div v-else-if="tree.length === 0" class="text-center py-16">
        <BuildingOffice2Icon class="w-12 h-12 mx-auto text-slate-300 mb-3" />
        <p class="text-slate-500 mb-3">{{ t('org_structure.no_departments') }}</p>
        <button v-if="canManageDepartments" type="button" class="btn-primary" @click="openCreateDept()">
          <PlusIcon class="w-4 h-4" /> {{ t('org_structure.create_first') }}
        </button>
      </div>
      <div v-else-if="!selectedDeptId" class="max-w-3xl mx-auto py-10">
        <div class="text-center mb-6">
          <BuildingOffice2Icon class="w-12 h-12 mx-auto text-primary-500 mb-3" />
          <h2 class="text-lg font-semibold text-slate-900">{{ t('org_structure.select_chart_title') }}</h2>
          <p class="text-sm text-slate-500 mt-1">
            {{ t('org_structure.select_chart_help') }}
          </p>
        </div>

        <div class="mb-5">
          <SearchableSelect
            v-model="selectedDeptId"
            :items="departmentOptions"
            value-key="id"
            label-key="display_name"
            sub-label-key="code"
            :search-keys="['name', 'code', 'search_text']"
            v-bind="{ placeholder: t('org_structure.search_select_department') }"
            max-height="20rem"
            @change="resetPan"
          />
        </div>

        <div class="rounded-lg border border-slate-200 bg-white overflow-hidden">
          <div class="px-4 py-2 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {{ t('org_structure.available_departments') }}
          </div>
          <div class="max-h-72 overflow-y-auto divide-y divide-slate-100">
            <button
              v-for="node in departmentOptions"
              :key="node.id"
              type="button" class="w-full px-4 py-2.5 text-left hover:bg-primary-50/60 flex items-center justify-between gap-3"
              :style="{ paddingLeft: `${16 + Math.min(node.depth, 6) * 18}px` }"
              @click="selectDepartment(node.id)"
            >
              <span class="min-w-0">
                <span class="block text-sm font-medium text-slate-900 truncate">{{ node.name }}</span>
                <span class="block text-[11px] text-slate-400 font-mono truncate">{{ node.code || '-' }}</span>
              </span>
              <span class="text-xs text-slate-500 flex-shrink-0">
                {{ t('org_structure.employee_unit', { count: node.employee_count || 0 }) }}
              </span>
            </button>
          </div>
        </div>
      </div>
      <div v-else-if="viewMode === 'list'" class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="grid grid-cols-12 gap-3 bg-slate-50 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
          <div class="col-span-5">{{ t('org_structure.col_department') }}</div>
          <div class="col-span-3">{{ t('org_structure.col_manager') }}</div>
          <div class="col-span-2 text-right">{{ t('org_structure.col_employees') }}</div>
          <div class="col-span-2 text-right">{{ t('org_structure.col_actions') }}</div>
        </div>
        <div class="divide-y divide-slate-100">
          <div
            v-for="{ node, depth } in visibleRows"
            :key="node.id"
            class="grid grid-cols-12 gap-3 px-4 py-2.5 items-center bg-white hover:bg-primary-50/30 transition-colors"
            :class="dragOverId === node.id ? 'ring-2 ring-primary-200 bg-primary-50' : ''"
            :draggable="canManageStructure"
            @dragstart.stop="onDragStart($event, 'department', node.id)"
            @dragover.stop="onDragOver($event, node.id)"
            @dragleave.stop="onDragLeave"
            @drop.stop="onDrop($event, node.id)"
          >
            <div class="col-span-5 flex items-center min-w-0" :style="{ paddingLeft: `${Math.min(depth, 8) * 18}px` }">
              <button
                v-if="node.children?.length"
                type="button" class="w-6 h-6 mr-1 rounded hover:bg-slate-100 text-slate-500 flex items-center justify-center flex-shrink-0"
                @click.stop="toggleCollapse(node.id)"
                v-bind="{ title: collapsedIds.has(node.id) ? t('org_structure.expand_children') : t('org_structure.collapse_children'), 'aria-label': collapsedIds.has(node.id) ? t('org_structure.expand_children') : t('org_structure.collapse_children') }"
              >
                <ChevronRightIcon v-if="collapsedIds.has(node.id)" class="w-4 h-4" />
                <ChevronDownIcon v-else class="w-4 h-4" />
              </button>
              <span v-else class="w-7 flex-shrink-0"></span>
              <div class="w-8 h-8 rounded bg-primary-50 text-primary-700 flex items-center justify-center flex-shrink-0 mr-2">
                <BuildingOffice2Icon class="w-4 h-4" />
              </div>
              <div class="min-w-0">
                <div class="font-medium text-sm text-slate-900 truncate">{{ node.name }}</div>
                <div class="text-[10px] text-slate-400 font-mono truncate">{{ node.code || '-' }}</div>
              </div>
            </div>
            <div class="col-span-3 min-w-0 text-sm text-slate-600 truncate">
              <span v-if="node.manager_name">{{ node.manager_name }}</span>
              <span v-else class="italic text-amber-600">{{ t('org_structure.no_manager') }}</span>
            </div>
            <div class="col-span-2 text-right text-sm text-slate-600">
              <span class="font-semibold text-slate-900">{{ node.employee_count || 0 }}</span>
              <span v-if="(node.open_mpps || []).length" class="ml-2 text-xs text-amber-700">
                {{ t('org_structure.vacancy_unit', { count: node.open_mpps.length }) }}
              </span>
            </div>
            <div class="col-span-2 flex justify-end gap-1">
              <button v-if="canManageStructure" type="button" class="px-2 py-1 rounded border border-slate-200 text-xs text-slate-700 hover:bg-white"
                @click.stop="openDeptDetail(node)">
                {{ t('org_structure.manage') }}
              </button>
              <button v-if="canManageDepartments" type="button" class="px-2 py-1 rounded border border-slate-200 text-xs text-slate-700 hover:bg-white"
                @click.stop="openEditDept(node)">
                {{ t('org_structure.edit') }}
              </button>
              <button v-if="canManageDepartments" type="button" class="px-2 py-1 rounded border border-slate-200 text-xs text-slate-700 hover:bg-white"
                @click.stop="openCreateDept(node.id)">
                {{ t('org_structure.sub') }}
              </button>
            </div>
          </div>
        </div>
      </div>
      <div
        v-else
        ref="chartViewport"
        class="org-chart-viewport"
        :class="{ 'is-panning': panning.active }"
        @pointerdown="startPan"
      >
        <div
          class="org-pan-layer"
          :style="{ transform: `translate3d(${pan.x}px, ${pan.y}px, 0)` }"
        >
          <div class="org-root-grid">
            <DepartmentNode
              v-for="d in visibleTree"
              :key="d.id"
              :node="d"
              :level="0"
              :collapsed-ids="collapsedIds"
              :drag-over-id="dragOverId"
              :can-manage="canManageDepartments"
              @toggle="toggleCollapse"
              @drop-on="onDrop"
              @drag-over="(e, id) => onDragOver(e, id)"
              @drag-leave="onDragLeave"
              @drag-start="onDragStart"
              @edit="openEditDept"
              @delete="deleteDept"
              @add-child="openCreateDept"
              @view-detail="openDeptDetail"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- ── Footer hint (kecil, tidak ganggu) ────────────────────────── -->
    <p class="text-xs text-slate-500 text-center mt-3">
      {{ t('org_structure.pan_hint') }}
    </p>

    <!-- ── Modal: Detail department (drill-down) ─────────────────── -->
    <DepartmentDetailModal
      v-if="detailDeptId"
      :department-id="detailDeptId"
      :all-departments="flatDepartments"
      :can-manage="canManageStructure"
      @close="closeDeptDetail"
      @navigate="navigateToDept"
      @changed="onDetailChanged"
    />

    <!-- ── Modal: Edit/Create Department ─────────────────────────── -->
    <div v-if="showEditModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto" role="dialog" aria-modal="true" v-bind="{ 'aria-label': editingNew ? t('org_structure.add_title') : t('org_structure.edit_title') }">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">{{ editingNew ? t('org_structure.add_title') : t('org_structure.edit_title') }}</h2>
          <button type="button" class="text-slate-400 hover:text-slate-700" v-bind="{ title: t('org_structure.close'), 'aria-label': t('org_structure.close') }" @click="showEditModal = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <form @submit.prevent="saveDept" class="px-5 py-4 space-y-3">
          <div>
            <label class="label">{{ t('org_structure.short_code') }}</label>
            <input v-model="editForm.code" class="input" required maxlength="32"
              v-bind="{ placeholder: t('org_structure.short_code_ph') }" />
          </div>
          <div>
            <label class="label">{{ t('org_structure.full_name') }}</label>
            <input v-model="editForm.name" class="input" required maxlength="200"
              v-bind="{ placeholder: t('org_structure.full_name_ph') }" />
          </div>
          <div>
            <label class="label">{{ t('org_structure.description_optional') }}</label>
            <textarea v-model="editForm.description" class="input" rows="2"></textarea>
          </div>
          <div v-if="! editingNew">
            <label class="label">{{ t('org_structure.manager') }}</label>
            <SearchableSelect
              v-model="editForm.manager_employee_id"
              :items="employees"
              value-key="id"
              label-key="full_name"
              sub-label-key="employee_number"
              :search-keys="['full_name','employee_number','name']"
              v-bind="{ placeholder: t('org_structure.no_manager_option') }"
            />
            <p class="text-xs text-slate-500 mt-1">
              {{ t('org_structure.manager_help') }}
            </p>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" :checked="!! editForm.is_active"
              @change="editForm.is_active = $event.target.checked ? 1 : 0" />
            {{ t('org_structure.active_department') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showEditModal = false">{{ t('org_structure.cancel') }}</button>
            <button type="submit" class="btn-primary">{{ t('org_structure.save') }}</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Modal: Bantuan ──────────────────────────────────────────── -->
    <div v-if="showHelp" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40"
      @click.self="showHelp = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg" role="dialog" aria-modal="true" v-bind="{ 'aria-label': t('org_structure.help_title') }">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold flex items-center gap-2">
            <QuestionMarkCircleIcon class="w-5 h-5 text-primary-600" />
            {{ t('org_structure.help_title') }}
          </h2>
          <button type="button" class="text-slate-400 hover:text-slate-700" v-bind="{ title: t('org_structure.close'), 'aria-label': t('org_structure.close') }" @click="showHelp = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <div class="px-5 py-4 space-y-4 text-sm text-slate-700">
          <div>
            <div class="font-semibold mb-1">{{ t('org_structure.help_main_title') }}</div>
            <p class="text-slate-600">{{ t('org_structure.help_main_text') }}</p>
          </div>
          <div>
            <div class="font-semibold mb-1">{{ t('org_structure.help_people_title') }}</div>
            <p class="text-slate-600">{{ t('org_structure.help_people_intro') }}</p>
            <ul class="list-disc list-inside mt-1 text-slate-600 space-y-0.5">
              <li>{{ t('org_structure.help_people_1') }}</li>
              <li>{{ t('org_structure.help_people_2') }}</li>
              <li>{{ t('org_structure.help_people_3') }}</li>
            </ul>
          </div>
          <div>
            <div class="font-semibold mb-1">{{ t('org_structure.help_hierarchy_title') }}</div>
            <p class="text-slate-600">{{ t('org_structure.help_hierarchy_text') }}</p>
          </div>
          <div>
            <div class="font-semibold mb-1">{{ t('org_structure.help_actions_title') }}</div>
            <p class="text-slate-600">{{ t('org_structure.help_actions_text') }}</p>
          </div>
          <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-800">
            <strong>{{ t('org_structure.help_note_label') }}</strong> {{ t('org_structure.help_note') }}
          </div>
        </div>
        <div class="px-5 py-3 border-t text-right">
          <button type="button" class="btn-primary" @click="showHelp = false">{{ t('org_structure.understood') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.slide-enter-active, .slide-leave-active { transition: all 0.2s ease; }
.slide-enter-from, .slide-leave-to { opacity: 0; transform: translateY(-8px); }

.org-chart-viewport {
  max-width: 100%;
  height: clamp(32rem, calc(100vh - 16rem), 46rem);
  overflow: hidden;
  position: relative;
  cursor: grab;
  touch-action: none;
  user-select: none;
}

.org-chart-viewport.is-panning {
  cursor: grabbing;
}

.org-pan-layer {
  min-width: 100%;
  min-height: 100%;
  padding: 0.5rem 0.5rem 2rem;
  will-change: transform;
}

.org-root-grid {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: center;
  gap: 2.5rem 1.75rem;
  width: 100%;
  min-width: 0;
}
</style>
