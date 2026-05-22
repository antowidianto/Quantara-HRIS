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
import { ref, computed, onMounted, reactive, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useAuthStore } from '@/stores/auth'
import {
  PlusIcon, BuildingOffice2Icon, UserGroupIcon, BriefcaseIcon,
  ArrowsRightLeftIcon, ArrowPathIcon, ArrowUturnLeftIcon,
  CheckCircleIcon, XMarkIcon, ClipboardDocumentListIcon,
  QuestionMarkCircleIcon,
} from '@heroicons/vue/24/outline'
import DepartmentNode from './DepartmentNode.vue'
import DepartmentDetailModal from './DepartmentDetailModal.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

const toast = useToast()
const router = useRouter()
const auth = useAuthStore()

// ─── State ─────────────────────────────────────────────────────────
const loading = ref(false)
const saving = ref(false)
const tree = ref([])
const stats = ref({ total_departments: 0, total_positions: 0, total_employees: 0, total_open_mpps: 0 })
const collapsedIds = ref(new Set())
const employees = ref([])

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

// ─── Fetch ─────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const [structRes, empRes] = await Promise.all([
      api.get('/org-structure'),
      api.get('/employees', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
    ])
    tree.value = structRes.data.data.departments || []
    stats.value = structRes.data.data.stats || stats.value
    employees.value = empRes.data.data || []
    pendingMoves.value = []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat struktur organisasi')
  } finally {
    loading.value = false
  }
}

onMounted(load)

// Super admin bisa pindah-pindah company. Kalau tab/company yang aktif
// berubah, draft pendingMoves dari company sebelumnya tidak relevan lagi
// (id department-nya tidak ada di tree baru) — buang dan reload supaya
// user tidak dapat error "Department #X tidak ditemukan dalam scope".
watch(() => auth.activeCompanyId, (newId, oldId) => {
  if (newId === oldId) return
  if (pendingMoves.value.length > 0) {
    toast.info('Perubahan struktur dibatalkan karena perusahaan aktif diganti.')
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
    toast.warning('Department tidak bisa jadi parent sendiri'); return
  }
  if (newParentId !== null && isDescendant(deptId, newParentId)) {
    toast.warning('Tidak bisa pindah ke sub-dept sendiri (akan membentuk siklus)'); return
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
    toast.warning(`${dropped} perubahan diabaikan karena department-nya tidak ada lagi di pohon.`)
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
    toast.success(`${pendingMoves.value.length} perubahan tersimpan`)
    await load()
  } catch (e) {
    const code = e.response?.data?.code
    const msg  = e.response?.data?.message || 'Gagal menyimpan perubahan'
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
    title: 'Batalkan perubahan',
    message: `Ada ${pendingMoves.value.length} perubahan yang belum disimpan. Yakin batalkan?`,
    okText: 'Batalkan',
    variant: 'danger',
  })
  if (! ok) return
  await load()
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
    toast.warning('Kode dan nama wajib diisi'); return
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
      await api.post('/org-structure/set-manager', {
        department_id: editForm.id,
        manager_employee_id: payload.manager_employee_id,
      }).catch(() => {})
      toast.success('Department diperbarui')
    }
    showEditModal.value = false
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

async function deleteDept(dept) {
  const childCount = (dept.children?.length || 0)
  const empCount = dept.employee_count || 0
  if (childCount > 0 || empCount > 0) {
    toast.warning(`Tidak bisa hapus: masih ada ${childCount} sub-dept dan/atau ${empCount} karyawan. Pindahkan dulu.`)
    return
  }
  const ok = await confirmDialog({
    title: 'Hapus department',
    message: `Yakin hapus "${dept.name}"? Tindakan ini tidak bisa dibatalkan.`,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/departments/${dept.id}`)
    toast.success('Department dihapus')
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
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
          <h1 class="text-xl font-bold text-slate-900 leading-none">Struktur Organisasi</h1>
          <div class="text-xs text-slate-500 mt-1 flex items-center gap-3">
            <span>{{ stats.total_departments }} department</span>
            <span>·</span>
            <span>{{ stats.total_employees }} karyawan</span>
            <span v-if="stats.total_open_mpps">·</span>
            <span v-if="stats.total_open_mpps" class="text-amber-700">
              {{ stats.total_open_mpps }} lowongan aktif
            </span>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <button class="btn-secondary" @click="showHelp = true" title="Cara pakai halaman ini">
          <QuestionMarkCircleIcon class="w-4 h-4" /> Bantuan
        </button>
        <button class="btn-secondary" @click="load" :disabled="loading || saving">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> Muat ulang
        </button>
        <button class="btn-primary" @click="openCreateDept()">
          <PlusIcon class="w-4 h-4" /> Department Baru
        </button>
      </div>
    </div>

    <!-- ── Sticky bar untuk pending changes ────────────────────────── -->
    <transition name="slide">
      <div v-if="dirty"
        class="sticky top-0 z-30 mb-4 px-4 py-3 rounded-lg border-2 border-amber-400 bg-amber-50 flex items-center justify-between gap-3 shadow-sm">
        <div class="flex items-center gap-2 text-amber-900 text-sm">
          <ArrowsRightLeftIcon class="w-5 h-5" />
          <div>
            <div class="font-semibold">{{ pendingMoves.length }} perubahan struktur belum disimpan</div>
            <div class="text-xs text-amber-700">Klik <strong>Simpan</strong> supaya manager_id karyawan ikut disesuaikan.</div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button class="btn-secondary" :disabled="saving" @click="discardChanges">
            <ArrowUturnLeftIcon class="w-4 h-4" /> Batal
          </button>
          <button class="btn-primary" :disabled="saving" @click="saveChanges">
            <CheckCircleIcon class="w-4 h-4" />
            {{ saving ? 'Menyimpan...' : 'Simpan' }}
          </button>
        </div>
      </div>
    </transition>

    <!-- ── Canvas bagan ─────────────────────────────────────────────── -->
    <div class="card overflow-auto bg-slate-50/60 min-h-[60vh] p-6">
      <div v-if="loading" class="text-center text-slate-400 py-16">
        Memuat struktur...
      </div>
      <div v-else-if="tree.length === 0" class="text-center py-16">
        <BuildingOffice2Icon class="w-12 h-12 mx-auto text-slate-300 mb-3" />
        <p class="text-slate-500 mb-3">Belum ada department.</p>
        <button class="btn-primary" @click="openCreateDept()">
          <PlusIcon class="w-4 h-4" /> Buat Department Pertama
        </button>
      </div>
      <div v-else class="inline-flex items-start justify-center min-w-full pb-6">
        <div class="flex items-start gap-8 px-2">
          <DepartmentNode
            v-for="d in tree"
            :key="d.id"
            :node="d"
            :level="0"
            :collapsed-ids="collapsedIds"
            :drag-over-id="dragOverId"
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

    <!-- ── Footer hint (kecil, tidak ganggu) ────────────────────────── -->
    <p class="text-xs text-slate-500 text-center mt-3">
      Klik <strong>Lihat & Atur Karyawan</strong> di kartu untuk pindah orang antar dept · Tarik kartu antar level untuk ubah hierarki
    </p>

    <!-- ── Modal: Detail department (drill-down) ─────────────────── -->
    <DepartmentDetailModal
      v-if="detailDeptId"
      :department-id="detailDeptId"
      :all-departments="flatDepartments"
      @close="closeDeptDetail"
      @navigate="navigateToDept"
      @changed="onDetailChanged"
    />

    <!-- ── Modal: Edit/Create Department ─────────────────────────── -->
    <div v-if="showEditModal" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">{{ editingNew ? 'Department Baru' : 'Edit Department' }}</h2>
          <button class="text-slate-400 hover:text-slate-700" @click="showEditModal = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <form @submit.prevent="saveDept" class="px-5 py-4 space-y-3">
          <div>
            <label class="label">Kode singkat *</label>
            <input v-model="editForm.code" class="input" required maxlength="32"
              placeholder="HR, FIN, IT, ..." />
          </div>
          <div>
            <label class="label">Nama lengkap *</label>
            <input v-model="editForm.name" class="input" required maxlength="200"
              placeholder="Human Resources" />
          </div>
          <div>
            <label class="label">Deskripsi (opsional)</label>
            <textarea v-model="editForm.description" class="input" rows="2"></textarea>
          </div>
          <div v-if="! editingNew">
            <label class="label">Manager</label>
            <SearchableSelect
              v-model="editForm.manager_employee_id"
              :items="employees"
              value-key="id"
              label-key="full_name"
              sub-label-key="employee_number"
              :search-keys="['full_name','employee_number','name']"
              placeholder="- Belum ada manager -"
            />
            <p class="text-xs text-slate-500 mt-1">
              Manager akan jadi atasan langsung semua staff di dept ini.
            </p>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" :checked="!! editForm.is_active"
              @change="editForm.is_active = $event.target.checked ? 1 : 0" />
            Department aktif
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showEditModal = false">Batal</button>
            <button type="submit" class="btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Modal: Bantuan ──────────────────────────────────────────── -->
    <div v-if="showHelp" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40"
      @click.self="showHelp = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold flex items-center gap-2">
            <QuestionMarkCircleIcon class="w-5 h-5 text-primary-600" />
            Cara menggunakan halaman ini
          </h2>
          <button class="text-slate-400 hover:text-slate-700" @click="showHelp = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <div class="px-5 py-4 space-y-4 text-sm text-slate-700">
          <div>
            <div class="font-semibold mb-1">📋 Tampilan utama</div>
            <p class="text-slate-600">
              Setiap kotak adalah satu department. Garis menghubungkan department ke parent-nya
              (atasan/induk). Angka di kartu = jumlah karyawan dan lowongan aktif di dept itu.
            </p>
          </div>
          <div>
            <div class="font-semibold mb-1">👥 Mengatur karyawan dalam satu department</div>
            <p class="text-slate-600">
              Klik tombol biru <strong>"Lihat & Atur Karyawan"</strong> di kartu. Akan muncul
              modal yang menampilkan list karyawan + posisi mereka. Dari situ Anda bisa:
            </p>
            <ul class="list-disc list-inside mt-1 text-slate-600 space-y-0.5">
              <li>Tarik karyawan ke posisi lain di dept yang sama</li>
              <li>Tarik karyawan ke sub-dept yang muncul di kanan</li>
              <li>Pakai tombol panah → untuk pindah ke dept di luar tampilan</li>
            </ul>
          </div>
          <div>
            <div class="font-semibold mb-1">🌳 Mengubah hierarki department</div>
            <p class="text-slate-600">
              Tarik satu kartu department ke kartu lain untuk menjadikannya sub-dept. Perubahan
              tidak langsung tersimpan — muncul sticky bar kuning di atas, klik <strong>Simpan</strong>
              setelah selesai.
            </p>
          </div>
          <div>
            <div class="font-semibold mb-1">⚙️ Edit / hapus / tambah sub-dept</div>
            <p class="text-slate-600">
              Hover kartu → klik ikon tiga titik di pojok kanan atas → pilih aksi. Tombol +
              Department di kanan atas halaman membuat dept baru tanpa parent.
            </p>
          </div>
          <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-800">
            <strong>Catatan:</strong> Setiap perubahan di modal detail langsung tersimpan. Hanya
            pindah hierarki department (drag kartu ke kartu) yang perlu diklik <strong>Simpan</strong>.
            Manager_id (atasan langsung) karyawan otomatis disesuaikan.
          </div>
        </div>
        <div class="px-5 py-3 border-t text-right">
          <button class="btn-primary" @click="showHelp = false">Mengerti</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.slide-enter-active, .slide-leave-active { transition: all 0.2s ease; }
.slide-enter-from, .slide-leave-to { opacity: 0; transform: translateY(-8px); }
</style>