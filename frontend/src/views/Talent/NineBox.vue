<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import {
  ChartBarIcon, PlusIcon, ArrowPathIcon, XMarkIcon,
  TrashIcon, UserCircleIcon, InformationCircleIcon,
  CheckIcon
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const toast = useToast()

const activeYear = ref(new Date().getFullYear())
const assessments = ref([])
const loading = ref(false)
const showModal = ref(false)
const isHR = computed(() => auth.can('performance.manage') || auth.isSuperAdmin)

// Form State
const form = reactive({
  id: null,
  employee_id: null,
  assessment_year: new Date().getFullYear(),
  performance_score: 3.0,
  potential_score: 3.0,
  notes: ''
})

const labelMapping = {
  'HIGH_HIGH': 'Star / Future Leader',
  'HIGH_MED': 'High Potential',
  'HIGH_LOW': 'Inconsistent Player',
  'MED_HIGH': 'Growth Employee',
  'MED_MED': 'Core Player',
  'MED_LOW': 'Average Performer',
  'LOW_HIGH': 'Enigma / Risk',
  'LOW_MED': 'Underperformer',
  'LOW_LOW': 'Talent Risk / Exit'
}

function getTier(val) {
  if (val >= 4.0) return 'HIGH'
  if (val >= 2.5) return 'MED'
  return 'LOW'
}

const computedLabel = computed(() => {
  const perfTier = getTier(form.performance_score)
  const potTier = getTier(form.potential_score)
  const key = `${potTier}_${perfTier}`
  return labelMapping[key] || 'Unclassified'
})

// ─── Actions ─────────────────────────────────────────────────────────────
async function loadAssessments() {
  loading.value = true
  try {
    const res = await api.get('/talent-assessments', { params: { year: activeYear.value } })
    assessments.value = res.data?.data || []
  } catch (e) {
    toast.error('Gagal memuat talent assessments')
  } finally {
    loading.value = false
  }
}

function openAddAssessment() {
  form.id = null
  form.employee_id = null
  form.assessment_year = activeYear.value
  form.performance_score = 3.0
  form.potential_score = 3.0
  form.notes = ''
  showModal.value = true
}

function openEditAssessment(item) {
  form.id = item.id
  form.employee_id = item.employee_id
  form.assessment_year = item.assessment_year
  form.performance_score = Number(item.performance_score)
  form.potential_score = Number(item.potential_score)
  form.notes = item.notes
  showModal.value = true
}

async function saveAssessment() {
  if (!form.employee_id) {
    toast.warning('Pilih karyawan terlebih dahulu')
    return
  }
  try {
    await api.post('/talent-assessments', form)
    toast.success(form.id ? 'Assessment berhasil diperbarui' : 'Assessment berhasil dibuat')
    showModal.value = false
    await loadAssessments()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan assessment')
  }
}

async function deleteAssessment(id) {
  if (!confirm('Apakah Anda yakin ingin menghapus assessment ini?')) return
  try {
    await api.delete(`/talent-assessments/${id}`)
    toast.success('Assessment berhasil dihapus')
    await loadAssessments()
  } catch (e) {
    toast.error('Gagal menghapus assessment')
  }
}

// Map assessments to 3x3 cells
// row 0 = High Potential, row 1 = Mid Potential, row 2 = Low Potential
// col 0 = Low Performance, col 1 = Mid Performance, col 2 = High Performance
const gridData = computed(() => {
  const grid = [
    [ { label: 'Enigma / Risk', bg: 'bg-amber-50 border-amber-200', text: 'text-amber-800', key: 'LOW_HIGH', emps: [] },
      { label: 'Growth Employee', bg: 'bg-indigo-50 border-indigo-200', text: 'text-indigo-800', key: 'MED_HIGH', emps: [] },
      { label: 'Star / Future Leader', bg: 'bg-emerald-50 border-emerald-300 ring-2 ring-emerald-500/20', text: 'text-emerald-800', key: 'HIGH_HIGH', emps: [] } ],
    [ { label: 'Underperformer', bg: 'bg-red-50 border-red-200', text: 'text-red-800', key: 'LOW_MED', emps: [] },
      { label: 'Core Player', bg: 'bg-blue-50 border-blue-200', text: 'text-blue-800', key: 'MED_MED', emps: [] },
      { label: 'High Potential', bg: 'bg-indigo-50 border-indigo-200', text: 'text-indigo-800', key: 'HIGH_MED', emps: [] } ],
    [ { label: 'Talent Risk / Exit', bg: 'bg-red-100 border-red-300', text: 'text-red-950', key: 'LOW_LOW', emps: [] },
      { label: 'Average Performer', bg: 'bg-gray-100 border-gray-200', text: 'text-gray-800', key: 'MED_LOW', emps: [] },
      { label: 'Inconsistent Player', bg: 'bg-amber-50 border-amber-200', text: 'text-amber-800', key: 'HIGH_LOW', emps: [] } ]
  ]

  assessments.value.forEach(emp => {
    const perfTier = getTier(Number(emp.performance_score))
    const potTier = getTier(Number(emp.potential_score))
    
    // Find correct grid coordinate
    let r = 1; let c = 1
    if (potTier === 'HIGH') r = 0
    else if (potTier === 'LOW') r = 2

    if (perfTier === 'HIGH') c = 2
    else if (perfTier === 'LOW') c = 0

    grid[r][c].emps.push(emp)
  })

  return grid
})

onMounted(() => {
  loadAssessments()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="relative bg-gradient-to-r from-teal-800 to-emerald-700 text-white rounded-2xl p-6 shadow-lg overflow-hidden">
      <div class="absolute -right-16 -top-16 w-48 h-48 bg-teal-500 rounded-full mix-blend-multiply filter blur-xl opacity-30 animate-pulse"></div>
      <div class="absolute -left-16 -bottom-16 w-48 h-48 bg-emerald-500 rounded-full mix-blend-multiply filter blur-xl opacity-30 animate-pulse"></div>
      
      <div class="relative z-10 flex justify-between items-center flex-wrap gap-4">
        <div>
          <h1 class="text-3xl font-extrabold flex items-center gap-3">
            <ChartBarIcon class="w-8 h-8 text-teal-200" />
            Nine-Box Matrix (Peta Talenta)
          </h1>
          <p class="text-teal-100 text-sm mt-2 max-w-2xl">
            Memetakan karyawan berdasarkan perbandingan tingkat performa kerja (Sumbu X) dan potensi pertumbuhan (Sumbu Y) untuk menyusun strategi pengembangan SDM.
          </p>
        </div>
        <div class="flex gap-2">
          <select v-model="activeYear" @change="loadAssessments" class="input py-2 px-3 bg-white/20 border-white/30 text-white font-semibold rounded-lg outline-none focus:ring-2 focus:ring-teal-400 select-white">
            <option v-for="y in [2024, 2025, 2026, 2027, 2028]" :key="y" :value="y" class="text-teal-950">{{ y }}</option>
          </select>
          <button v-if="isHR" class="btn bg-white text-teal-900 hover:bg-teal-50" @click="openAddAssessment">
            <PlusIcon class="w-4 h-4" /> Tambah Assessment
          </button>
        </div>
      </div>
    </div>

    <!-- The 3x3 Grid Visualisation -->
    <div class="card p-6">
      <div class="text-center font-bold text-gray-700 text-sm mb-4">POTENSI (POTENTIAL) →</div>
      
      <div class="grid grid-cols-12 gap-4">
        <!-- Performance axis label vertical -->
        <div class="col-span-1 flex items-center justify-center font-bold text-gray-700 text-sm transform -rotate-90 origin-center whitespace-nowrap">
          ← PERFORMA (PERFORMANCE)
        </div>

        <div class="col-span-11 grid grid-cols-3 gap-3">
          <!-- Row 0: High Potential -->
          <!-- Row 1: Med Potential -->
          <!-- Row 2: Low Potential -->
          <template v-for="(row, rIdx) in gridData" :key="rIdx">
            <div
              v-for="(cell, cIdx) in row"
              :key="cIdx"
              :class="[
                'border-2 border-dashed rounded-xl p-4 min-h-[160px] flex flex-col justify-between transition-all hover:shadow-lg',
                cell.bg
              ]"
            >
              <div>
                <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-white/70 shadow-sm" :class="cell.text">
                  {{ cell.label }}
                </span>
                
                <div class="mt-3 space-y-1.5 overflow-y-auto max-h-[100px]">
                  <div
                    v-for="emp in cell.emps"
                    :key="emp.id"
                    class="bg-white/80 backdrop-blur-sm rounded-lg p-2 border border-gray-150 text-xs shadow-sm flex items-center justify-between group"
                  >
                    <div class="min-w-0">
                      <div class="font-bold text-gray-800 truncate">{{ emp.full_name }}</div>
                      <div class="text-[10px] text-gray-500 truncate">{{ emp.position_name || 'No Position' }}</div>
                    </div>
                    <div v-if="isHR" class="opacity-0 group-hover:opacity-100 transition-opacity flex gap-1">
                      <button class="text-indigo-600 hover:text-indigo-800" @click="openEditAssessment(emp)">Edit</button>
                      <button class="text-red-600 hover:text-red-800" @click="deleteAssessment(emp.id)">×</button>
                    </div>
                  </div>
                  <div v-if="cell.emps.length === 0" class="text-gray-400 italic text-[11px] text-center py-4">
                    Kosong
                  </div>
                </div>
              </div>

              <div class="text-[10px] font-semibold text-gray-400 mt-2 text-right">
                Jumlah: {{ cell.emps.length }}
              </div>
            </div>
          </template>
        </div>
      </div>
      
      <div class="flex justify-between text-xs text-gray-400 mt-4 px-12">
        <span>Rendah (Low)</span>
        <span>Sedang (Medium)</span>
        <span>Tinggi (High)</span>
      </div>
    </div>

    <!-- Assessments table list -->
    <div class="card">
      <div class="card-header bg-gray-50/50 flex justify-between items-center">
        <span class="font-bold text-gray-900">Daftar Penilaian Talenta</span>
        <button @click="loadAssessments" class="btn-secondary text-xs py-1.5 px-3">
          <ArrowPathIcon class="w-4 h-4" /> Refresh
        </button>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Karyawan</th>
              <th>Skor Performa</th>
              <th>Skor Potensi</th>
              <th>Nine-Box Category</th>
              <th>Catatan / Notes</th>
              <th class="text-right" v-if="isHR">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="assessments.length === 0">
              <td colspan="6" class="text-center py-8 text-gray-400 text-xs">Belum ada data assessment untuk tahun ini.</td>
            </tr>
            <tr v-for="item in assessments" :key="item.id" class="hover:bg-gray-50/50">
              <td>
                <div class="flex items-center gap-2">
                  <UserCircleIcon class="w-8 h-8 text-gray-400" />
                  <div>
                    <div class="font-bold text-gray-900">{{ item.full_name }}</div>
                    <div class="text-[11px] text-gray-500 font-mono">NIP: {{ item.employee_number }}</div>
                  </div>
                </div>
              </td>
              <td>
                <div class="font-bold">{{ Number(item.performance_score).toFixed(2) }}</div>
              </td>
              <td>
                <div class="font-bold">{{ Number(item.potential_score).toFixed(2) }}</div>
              </td>
              <td>
                <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-700">
                  {{ item.nine_box_label }}
                </span>
              </td>
              <td class="text-xs text-gray-600 max-w-xs truncate" :title="item.notes">
                {{ item.notes || '-' }}
              </td>
              <td class="text-right space-x-2" v-if="isHR">
                <button class="text-indigo-600 hover:text-indigo-800 text-xs font-semibold" @click="openEditAssessment(item)">Edit</button>
                <button class="text-red-600 hover:text-red-800 text-xs font-semibold" @click="deleteAssessment(item.id)">Hapus</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ──────────────── MODAL: ADD / EDIT ASSESSMENT ──────────────── -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <div class="px-5 py-3 border-b flex items-center justify-between bg-gray-50">
          <h3 class="font-bold text-gray-900">{{ form.id ? 'Edit Talent Assessment' : 'Input Talent Assessment Baru' }}</h3>
          <button @click="showModal = false" class="text-gray-400 hover:text-gray-600"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        
        <div class="p-5 space-y-4">
          <div>
            <label class="label">Pilih Karyawan *</label>
            <EmployeeSearchSelect v-model="form.employee_id" placeholder="Cari karyawan..." :disabled="!!form.id" />
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Tahun Assessment *</label>
              <input v-model.number="form.assessment_year" type="number" class="input" :disabled="!!form.id" />
            </div>
            <div>
              <label class="label">Auto Category</label>
              <div class="input bg-gray-50 border-gray-200 text-indigo-700 font-bold text-xs truncate">
                {{ computedLabel }}
              </div>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Skor Performa (1.0 - 5.0)</label>
              <input v-model.number="form.performance_score" type="number" step="0.1" min="1" max="5" class="input" />
            </div>
            <div>
              <label class="label">Skor Potensi (1.0 - 5.0)</label>
              <input v-model.number="form.potential_score" type="number" step="0.1" min="1" max="5" class="input" />
            </div>
          </div>

          <div>
            <label class="label">Catatan / Justifikasi Penilaian</label>
            <textarea v-model="form.notes" class="input" rows="3" placeholder="Alasan penetapan grid talenta..."></textarea>
          </div>
        </div>

        <div class="px-5 py-3 border-t flex justify-end gap-2 bg-gray-50">
          <button class="btn-secondary text-xs" @click="showModal = false">Batal</button>
          <button class="btn-primary bg-emerald-600 hover:bg-emerald-700 text-xs" @click="saveAssessment">Simpan</button>
        </div>
      </div>
    </div>
  </div>
</template>
