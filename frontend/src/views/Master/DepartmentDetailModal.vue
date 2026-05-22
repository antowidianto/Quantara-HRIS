<script setup>
/*
 * DepartmentDetailModal — versi simplified.
 *
 * Filosofi:
 *   - List karyawan jadi tabel sederhana, bukan kartu colorful yang ribet.
 *   - Setiap baris punya tombol "Pindahkan" yang jelas (tidak perlu drag).
 *   - Drag-drop tetap bisa untuk power user, tapi bukan interface utama.
 *   - Header info dipampang ringkas, bukan cardbox terpisah.
 *   - Tab navigation: Karyawan | Sub-dept | Lowongan, biar tidak overload
 *     satu layar dengan semua section sekaligus.
 *
 * Aksi yang tersedia:
 *   - Pindahkan karyawan ke department lain (modal kecil dengan dropdown).
 *   - Klik sub-dept untuk navigasi ke detail sub-dept tsb (drill-down).
 *   - Lihat MPP/lowongan yang sedang dibuka.
 */
import { ref, computed, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  XMarkIcon, BuildingOffice2Icon, UserGroupIcon, BriefcaseIcon,
  ClipboardDocumentListIcon, ArrowRightIcon, MagnifyingGlassIcon,
  ChevronRightIcon, UserCircleIcon,
} from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'

const props = defineProps({
  departmentId:   { type: Number, required: true },
  allDepartments: { type: Array,  default: () => [] },
})

const emit = defineEmits(['close', 'navigate', 'changed'])

const toast = useToast()

// ─── State ──────────────────────────────────────────────────────────
const loading = ref(false)
const data = ref(null)
const search = ref('')
const activeTab = ref('employees')   // 'employees' | 'subdepts' | 'mpp'

const department = computed(() => data.value?.department || {})
const employees  = computed(() => data.value?.employees || [])
const subDepts   = computed(() => data.value?.sub_departments || [])
const openMpps   = computed(() => data.value?.open_mpps || [])

const filteredEmployees = computed(() => {
  if (! search.value) return employees.value
  const q = search.value.toLowerCase()
  return employees.value.filter(e =>
    (e.full_name || '').toLowerCase().includes(q)
    || (e.employee_number || '').toLowerCase().includes(q)
    || (e.position_name || '').toLowerCase().includes(q)
  )
})

// ─── Fetch ──────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const res = await api.get(`/org-structure/department/${props.departmentId}`)
    data.value = res.data.data
    activeTab.value = 'employees'
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat detail')
  } finally {
    loading.value = false
  }
}

watch(() => props.departmentId, (id) => { if (id) load() }, { immediate: true })

// ─── Move karyawan ──────────────────────────────────────────────────
const moveEmp = ref(null)        // employee yang sedang dipindah
const moveTarget = ref('')

function openMove(emp) {
  moveEmp.value = emp
  moveTarget.value = ''
}

async function confirmMove() {
  const emp = moveEmp.value
  const targetId = moveTarget.value ? Number(moveTarget.value) : null
  if (! emp || targetId === department.value.id) {
    moveEmp.value = null; return
  }
  const targetName = props.allDepartments.find(d => d.id === targetId)?.name || 'Tanpa department'
  const ok = await confirmDialog({
    title: `Pindahkan ${emp.full_name}?`,
    message: `Dari ${department.value.name} ke ${targetName}. Atasan langsung akan disesuaikan otomatis.`,
    okText: 'Pindah',
  })
  if (! ok) { moveEmp.value = null; return }

  try {
    await api.post('/org-structure/move-employee', {
      employee_id: emp.id,
      new_department_id: targetId,
      new_position_id: null,
    })
    toast.success(`${emp.full_name} dipindahkan ke ${targetName}`)
    moveEmp.value = null
    await load()
    emit('changed')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memindahkan')
  }
}

// ─── Drag-drop opsional (untuk power user) ─────────────────────────
const draggedEmp = ref(null)

function onEmpDragStart(e, emp) {
  draggedEmp.value = emp
  e.dataTransfer.effectAllowed = 'move'
}
function onEmpDragEnd() { draggedEmp.value = null }

async function onDropOnSubDept(e, sub) {
  e.preventDefault()
  const emp = draggedEmp.value
  draggedEmp.value = null
  if (! emp) return
  const ok = await confirmDialog({
    title: `Pindahkan ${emp.full_name}?`,
    message: `Ke sub-department "${sub.name}". Atasan langsung akan disesuaikan.`,
    okText: 'Pindah',
  })
  if (! ok) return
  try {
    await api.post('/org-structure/move-employee', {
      employee_id: emp.id, new_department_id: sub.id, new_position_id: null,
    })
    toast.success(`${emp.full_name} → ${sub.name}`)
    await load()
    emit('changed')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memindahkan')
  }
}

const moveTargetOptions = computed(() => {
  return [...(props.allDepartments || [])]
    .filter(d => d.id !== department.value.id)
    .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
})

// MPP status badge color (subtle)
const MPP_BADGE = {
  DRAFT:     'bg-slate-100 text-slate-700',
  SUBMITTED: 'bg-amber-100 text-amber-700',
  APPROVED:  'bg-emerald-100 text-emerald-700',
  HIRING:    'bg-blue-100 text-blue-700',
}
function mppBadge(s) { return MPP_BADGE[s] || MPP_BADGE.DRAFT }

// Tier badge — kompak
const TIER_LABEL = {
  MANAGER: 'Manager', ASST_MANAGER: 'Asst. Manager',
  SUPERVISOR: 'Supervisor', STAFF: 'Staff',
}
function tierLabel(t) { return TIER_LABEL[t] || 'Staff' }
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
    @click.self="emit('close')">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden">

      <!-- ── Header ─────────────────────────────────────────────── -->
      <div class="px-6 py-4 border-b flex items-start justify-between gap-3">
        <div class="flex items-start gap-3 flex-1 min-w-0">
          <div class="w-11 h-11 rounded-lg bg-primary-50 text-primary-700 flex items-center justify-center flex-shrink-0">
            <BuildingOffice2Icon class="w-6 h-6" />
          </div>
          <div class="flex-1 min-w-0">
            <div v-if="department.parent_name" class="text-xs text-slate-500 flex items-center gap-1 mb-0.5">
              <span>{{ department.parent_name }}</span>
              <ChevronRightIcon class="w-3 h-3" />
            </div>
            <h2 class="font-bold text-lg text-slate-900 truncate leading-tight">
              {{ department.name || 'Memuat...' }}
            </h2>
            <div class="text-xs text-slate-600 mt-1 flex items-center gap-1.5 flex-wrap">
              <span class="flex items-center gap-1">
                <UserCircleIcon class="w-3.5 h-3.5 text-slate-400" />
                <span v-if="department.manager_name" class="font-medium">{{ department.manager_name }}</span>
                <span v-else class="italic text-amber-600">Belum ada manager</span>
              </span>
              <span class="text-slate-300">·</span>
              <span>{{ employees.length }} karyawan</span>
              <span v-if="subDepts.length" class="text-slate-300">·</span>
              <span v-if="subDepts.length">{{ subDepts.length }} sub-dept</span>
            </div>
          </div>
        </div>
        <button class="p-1.5 text-slate-400 hover:text-slate-700 rounded hover:bg-slate-100"
          @click="emit('close')">
          <XMarkIcon class="w-5 h-5" />
        </button>
      </div>

      <!-- ── Tabs ───────────────────────────────────────────────── -->
      <div class="px-6 border-b flex items-center gap-1">
        <button
          class="px-3 py-2 text-sm font-medium border-b-2 transition-colors"
          :class="activeTab === 'employees'
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-slate-600 hover:text-slate-900'"
          @click="activeTab = 'employees'"
        >
          <UserGroupIcon class="w-4 h-4 inline-block mr-1" />
          Karyawan ({{ employees.length }})
        </button>
        <button
          v-if="subDepts.length"
          class="px-3 py-2 text-sm font-medium border-b-2 transition-colors"
          :class="activeTab === 'subdepts'
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-slate-600 hover:text-slate-900'"
          @click="activeTab = 'subdepts'"
        >
          <BuildingOffice2Icon class="w-4 h-4 inline-block mr-1" />
          Sub-Department ({{ subDepts.length }})
        </button>
        <button
          v-if="openMpps.length"
          class="px-3 py-2 text-sm font-medium border-b-2 transition-colors"
          :class="activeTab === 'mpp'
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-slate-600 hover:text-slate-900'"
          @click="activeTab = 'mpp'"
        >
          <ClipboardDocumentListIcon class="w-4 h-4 inline-block mr-1" />
          Lowongan ({{ openMpps.length }})
        </button>
      </div>

      <!-- ── Body ───────────────────────────────────────────────── -->
      <div class="flex-1 overflow-y-auto px-6 py-4">

        <!-- Loading -->
        <div v-if="loading" class="text-center text-slate-400 py-12">Memuat...</div>

        <!-- Tab: Karyawan -->
        <template v-else-if="activeTab === 'employees'">
          <!-- Search bar -->
          <div class="relative mb-3">
            <MagnifyingGlassIcon class="w-4 h-4 absolute left-2.5 top-2.5 text-slate-400" />
            <input v-model="search" class="input pl-8 h-9 text-sm"
              placeholder="Cari nama, NIP, atau jabatan..." />
          </div>

          <!-- Empty -->
          <div v-if="filteredEmployees.length === 0"
            class="text-center text-slate-400 py-10 border border-dashed rounded-lg">
            <UserGroupIcon class="w-10 h-10 mx-auto mb-2 text-slate-300" />
            <p class="text-sm">{{ search ? 'Tidak ada karyawan yang cocok' : 'Belum ada karyawan di department ini' }}</p>
          </div>

          <!-- Table-like list -->
          <div v-else class="border rounded-lg overflow-hidden">
            <div class="bg-slate-50 px-4 py-2 grid grid-cols-12 gap-3 text-[11px] font-semibold uppercase text-slate-500 tracking-wide">
              <div class="col-span-5">Karyawan</div>
              <div class="col-span-4">Jabatan</div>
              <div class="col-span-3 text-right">Aksi</div>
            </div>
            <ul class="divide-y divide-slate-100">
              <li v-for="e in filteredEmployees" :key="e.id"
                class="px-4 py-2.5 grid grid-cols-12 gap-3 items-center hover:bg-slate-50 transition-colors"
                :draggable="true"
                @dragstart="onEmpDragStart($event, e)"
                @dragend="onEmpDragEnd">
                <!-- Karyawan -->
                <div class="col-span-5 flex items-center gap-2.5 min-w-0">
                  <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center flex-shrink-0 font-semibold text-xs">
                    {{ (e.full_name || '?').charAt(0).toUpperCase() }}
                  </div>
                  <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-slate-900 truncate">{{ e.full_name }}</div>
                    <div class="text-[10px] text-slate-400 font-mono">{{ e.employee_number }}</div>
                  </div>
                </div>
                <!-- Jabatan -->
                <div class="col-span-4 min-w-0">
                  <div class="text-sm text-slate-700 truncate">{{ e.position_name || '—' }}</div>
                  <div v-if="e.position_tier" class="text-[10px] text-slate-500">{{ tierLabel(e.position_tier) }}</div>
                </div>
                <!-- Aksi -->
                <div class="col-span-3 flex items-center justify-end gap-1">
                  <button
                    class="px-2.5 py-1 text-xs rounded border border-slate-200 text-slate-700 hover:bg-primary-50 hover:border-primary-300 hover:text-primary-700 inline-flex items-center gap-1"
                    title="Pindahkan ke department lain"
                    @click="openMove(e)"
                  >
                    Pindahkan <ArrowRightIcon class="w-3 h-3" />
                  </button>
                </div>
              </li>
            </ul>
          </div>

          <p class="text-[11px] text-slate-500 mt-2 italic">
            💡 Tip: Anda juga bisa <strong>menarik</strong> karyawan ke tab Sub-Department di atas, atau gunakan tombol <strong>Pindahkan</strong> untuk pilih dept tujuan.
          </p>
        </template>

        <!-- Tab: Sub-departments -->
        <template v-else-if="activeTab === 'subdepts'">
          <p class="text-xs text-slate-500 mb-3">
            Klik sub-dept untuk lihat karyawannya. Tarik karyawan dari tab Karyawan ke salah satu kotak di sini untuk pindah.
          </p>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div v-for="sub in subDepts" :key="sub.id"
              class="group p-4 rounded-lg border-2 border-slate-200 bg-white hover:border-primary-400 hover:bg-primary-50/30 transition-colors cursor-pointer"
              @click="emit('navigate', sub.id)"
              @dragover.prevent
              @drop="onDropOnSubDept($event, sub)">
              <div class="flex items-start justify-between gap-2">
                <div class="flex items-start gap-2 flex-1 min-w-0">
                  <BuildingOffice2Icon class="w-5 h-5 text-slate-400 flex-shrink-0 mt-0.5" />
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-sm text-slate-900 truncate">{{ sub.name }}</div>
                    <div class="text-xs text-slate-500 mt-0.5">{{ sub.employee_count || 0 }} karyawan</div>
                  </div>
                </div>
                <ChevronRightIcon class="w-4 h-4 text-slate-300 group-hover:text-primary-600 flex-shrink-0" />
              </div>
            </div>
          </div>
        </template>

        <!-- Tab: MPP -->
        <template v-else-if="activeTab === 'mpp'">
          <p class="text-xs text-slate-500 mb-3">
            Lowongan/MPP yang sedang aktif untuk department ini.
          </p>
          <ul class="space-y-2">
            <li v-for="m in openMpps" :key="m.id"
              class="px-4 py-3 rounded-lg border border-slate-200 hover:border-primary-300 transition-colors flex items-center gap-3">
              <ClipboardDocumentListIcon class="w-5 h-5 text-amber-500 flex-shrink-0" />
              <div class="flex-1 min-w-0">
                <div class="font-medium text-sm text-slate-900 truncate">{{ m.proposed_title }}</div>
                <div class="text-xs text-slate-500 font-mono">{{ m.reference_number }}</div>
              </div>
              <div class="text-xs flex flex-col items-end gap-1">
                <span class="px-2 py-0.5 rounded font-semibold uppercase" :class="mppBadge(m.status)">
                  {{ m.status }}
                </span>
                <span class="text-slate-500 font-mono">{{ m.hired_count || 0 }}/{{ m.headcount }} terisi</span>
              </div>
            </li>
          </ul>
        </template>
      </div>

      <!-- ── Footer ─────────────────────────────────────────────── -->
      <div class="px-6 py-3 border-t bg-slate-50 flex items-center justify-end">
        <button class="btn-secondary" @click="emit('close')">Tutup</button>
      </div>
    </div>

    <!-- ── Sub-modal: Pilih dept tujuan ─────────────────────────── -->
    <div v-if="moveEmp" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50"
      @click.self="moveEmp = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <div>
            <h3 class="font-semibold">Pindahkan Karyawan</h3>
            <p class="text-xs text-slate-500 mt-0.5">{{ moveEmp.full_name }} ({{ moveEmp.employee_number }})</p>
          </div>
          <button class="text-slate-400 hover:text-slate-700" @click="moveEmp = null">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <div class="px-5 py-4 space-y-3">
          <div>
            <label class="label">Pindah ke department</label>
            <SearchableSelect
              v-model="moveTarget"
              :items="moveTargetOptions"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="- Pilih department tujuan -"
            />
          </div>
          <div class="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded p-2">
            Atasan langsung karyawan akan disesuaikan otomatis berdasarkan struktur dept tujuan.
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="moveEmp = null">Batal</button>
          <button class="btn-primary" :disabled="!moveTarget" @click="confirmMove">
            Pindahkan
          </button>
        </div>
      </div>
    </div>
  </div>
</template>