<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import {
  KeyIcon, PlusIcon, ArrowPathIcon, XMarkIcon,
  TrashIcon, UserCircleIcon, ShieldCheckIcon,
  SparklesIcon, UserMinusIcon, ChevronRightIcon, ChevronDownIcon
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const toast = useToast()

const criticalPositions = ref([])
const activePositionsList = ref([]) // Master positions list
const loading = ref(false)
const loadingDetail = ref(false)
const isHR = computed(() => auth.can('performance.manage') || auth.isSuperAdmin)

// Selection & Details
const selectedPositionId = ref(null)
const selectedPositionDetail = ref(null)

// Modals
const showPositionModal = ref(false)
const showCandidateModal = ref(false)

// Forms
const positionForm = reactive({
  id: null,
  position_id: null,
  incumbent_id: null,
  risk_level: 'MEDIUM',
  notes: ''
})

const candidateForm = reactive({
  id: null,
  critical_position_id: null,
  candidate_id: null,
  readiness: 'NOT_READY',
  development_plan: ''
})

// ─── Actions: Critical Positions ──────────────────────────────────────────
async function loadCriticalPositions() {
  loading.value = true
  try {
    const res = await api.get('/succession/positions')
    criticalPositions.value = res.data?.data || []
    if (selectedPositionId.value) {
      await viewPositionDetail(selectedPositionId.value)
    }
  } catch (e) {
    toast.error('Gagal memuat posisi krusial')
  } finally {
    loading.value = false
  }
}

async function loadPositionsMaster() {
  try {
    const res = await api.get('/positions', { params: { per_page: 250 } })
    activePositionsList.value = res.data?.data || []
  } catch (e) {
    console.error('Error loading positions master:', e)
  }
}

async function viewPositionDetail(id) {
  selectedPositionId.value = id
  loadingDetail.value = true
  try {
    const res = await api.get(`/succession/positions/${id}`)
    selectedPositionDetail.value = res.data?.data || null
  } catch (e) {
    toast.error('Gagal memuat detail successor')
  } finally {
    loadingDetail.value = false
  }
}

function openAddPosition() {
  positionForm.id = null
  positionForm.position_id = null
  positionForm.incumbent_id = null
  positionForm.risk_level = 'MEDIUM'
  positionForm.notes = ''
  showPositionModal.value = true
}

function openEditPosition(cp) {
  positionForm.id = cp.id
  positionForm.position_id = cp.position_id
  positionForm.incumbent_id = cp.incumbent_id
  positionForm.risk_level = cp.risk_level
  positionForm.notes = cp.notes
  showPositionModal.value = true
}

async function savePosition() {
  if (!positionForm.position_id) {
    toast.warning('Pilih posisi terlebih dahulu')
    return
  }
  try {
    const payload = { ...positionForm }
    if (positionForm.id) {
      await api.put(`/succession/positions/${positionForm.id}`, payload)
      toast.success('Posisi krusial berhasil diperbarui')
    } else {
      await api.post('/succession/positions', payload)
      toast.success('Posisi krusial berhasil didefinisikan')
    }
    showPositionModal.value = false
    await loadCriticalPositions()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan posisi krusial')
  }
}

async function deletePosition(id) {
  if (!confirm('Apakah Anda yakin ingin menghapus posisi krusial ini beserta kandidatnya?')) return
  try {
    await api.delete(`/succession/positions/${id}`)
    toast.success('Posisi krusial berhasil dihapus')
    if (selectedPositionId.value === id) {
      selectedPositionId.value = null
      selectedPositionDetail.value = null
    }
    await loadCriticalPositions()
  } catch (e) {
    toast.error('Gagal menghapus posisi krusial')
  }
}

// ─── Actions: Successors / Candidates ────────────────────────────────────
function openAddCandidate() {
  if (!selectedPositionId.value) {
    toast.warning('Pilih posisi krusial terlebih dahulu')
    return
  }
  candidateForm.id = null
  candidateForm.critical_position_id = selectedPositionId.value
  candidateForm.candidate_id = null
  candidateForm.readiness = 'NOT_READY'
  candidateForm.development_plan = ''
  showCandidateModal.value = true
}

function openEditCandidate(cand) {
  candidateForm.id = cand.id
  candidateForm.critical_position_id = cand.critical_position_id
  candidateForm.candidate_id = cand.candidate_id
  candidateForm.readiness = cand.readiness
  candidateForm.development_plan = cand.development_plan
  showCandidateModal.value = true
}

async function saveCandidate() {
  if (!candidateForm.candidate_id) {
    toast.warning('Pilih kandidat karyawan terlebih dahulu')
    return
  }
  try {
    const payload = { ...candidateForm }
    if (candidateForm.id) {
      await api.put(`/succession/candidates/${candidateForm.id}`, payload)
      toast.success('Kandidat successor berhasil diperbarui')
    } else {
      await api.post('/succession/candidates', payload)
      toast.success('Kandidat successor berhasil dinominasikan')
    }
    showCandidateModal.value = false
    await viewPositionDetail(selectedPositionId.value)
    await loadCriticalPositions()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan kandidat successor')
  }
}

async function removeCandidate(id) {
  if (!confirm('Apakah Anda yakin ingin menghapus kandidat successor ini?')) return
  try {
    await api.delete(`/succession/candidates/${id}`)
    toast.success('Kandidat successor berhasil dihapus')
    await viewPositionDetail(selectedPositionId.value)
    await loadCriticalPositions()
  } catch (e) {
    toast.error('Gagal menghapus kandidat')
  }
}

// Helpers
function getRiskBadgeClass(level) {
  const mapping = {
    'LOW': 'bg-blue-50 text-blue-700 border-blue-200',
    'MEDIUM': 'bg-yellow-50 text-yellow-700 border-yellow-200',
    'HIGH': 'bg-orange-50 text-orange-700 border-orange-200',
    'CRITICAL': 'bg-red-50 text-red-700 border-red-200 animate-pulse'
  }
  return mapping[level] || 'bg-gray-50 text-gray-700 border-gray-200'
}

function getReadinessClass(level) {
  const mapping = {
    'READY_NOW': 'bg-emerald-100 text-emerald-800',
    'READY_1_2Y': 'bg-blue-100 text-blue-800',
    'NOT_READY': 'bg-red-100 text-red-800'
  }
  return mapping[level] || 'bg-gray-100 text-gray-800'
}

function getReadinessLabel(level) {
  const mapping = {
    'READY_NOW': 'Siap Sekarang (Ready Now)',
    'READY_1_2Y': 'Siap 1-2 Tahun (Ready 1-2 Years)',
    'NOT_READY': 'Belum Siap (Not Ready)'
  }
  return mapping[level] || level
}

// Summary Statistics
const summaryStats = computed(() => {
  const total = criticalPositions.value.length
  const criticalCount = criticalPositions.value.filter(p => p.risk_level === 'CRITICAL' || p.risk_level === 'HIGH').length
  const coveredCount = criticalPositions.value.filter(p => p.candidates_count > 0).length
  const coverageRatio = total > 0 ? (coveredCount / total) * 100 : 0
  
  return {
    total,
    criticalCount,
    coveredCount,
    coverageRatio
  }
})

onMounted(() => {
  loadCriticalPositions()
  loadPositionsMaster()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="relative bg-gradient-to-r from-indigo-900 to-indigo-700 text-white rounded-2xl p-6 shadow-lg overflow-hidden">
      <div class="absolute -right-16 -top-16 w-48 h-48 bg-indigo-500 rounded-full mix-blend-multiply filter blur-xl opacity-30 animate-pulse"></div>
      <div class="absolute -left-16 -bottom-16 w-48 h-48 bg-blue-500 rounded-full mix-blend-multiply filter blur-xl opacity-30 animate-pulse"></div>
      
      <div class="relative z-10 flex justify-between items-center flex-wrap gap-4">
        <div>
          <h1 class="text-3xl font-extrabold flex items-center gap-3">
            <KeyIcon class="w-8 h-8 text-indigo-300" />
            Succession Planning (Perencanaan Suksesi)
          </h1>
          <p class="text-indigo-100 text-sm mt-2 max-w-2xl">
            Identifikasi posisi strategis, amankan kesinambungan kepemimpinan dengan menetapkan kandidat potensial, serta pantau status kesiapan (readiness) secara berkala.
          </p>
        </div>
        <div class="flex gap-2">
          <button v-if="isHR" class="btn bg-white text-indigo-900 hover:bg-indigo-50" @click="openAddPosition">
            <PlusIcon class="w-4 h-4" /> Tambah Posisi Krusial
          </button>
        </div>
      </div>
    </div>

    <!-- Analytics Dashboard Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="card p-4 bg-gradient-to-br from-white to-gray-50 flex items-center gap-4 border border-gray-200">
        <div class="p-3 rounded-xl bg-indigo-50 text-indigo-700">
          <KeyIcon class="w-6 h-6" />
        </div>
        <div>
          <span class="text-xs text-gray-500 font-bold uppercase tracking-wider block">Total Posisi Krusial</span>
          <span class="text-2xl font-black text-gray-800">{{ summaryStats.total }}</span>
        </div>
      </div>

      <div class="card p-4 bg-gradient-to-br from-white to-gray-50 flex items-center gap-4 border border-gray-200">
        <div class="p-3 rounded-xl bg-red-50 text-red-700 animate-pulse">
          <ShieldCheckIcon class="w-6 h-6" />
        </div>
        <div>
          <span class="text-xs text-gray-500 font-bold uppercase tracking-wider block">Risiko Tinggi / Kritis</span>
          <span class="text-2xl font-black text-red-600">{{ summaryStats.criticalCount }}</span>
        </div>
      </div>

      <div class="card p-4 bg-gradient-to-br from-white to-gray-50 flex items-center gap-4 border border-gray-200">
        <div class="p-3 rounded-xl bg-emerald-50 text-emerald-700">
          <SparklesIcon class="w-6 h-6" />
        </div>
        <div>
          <span class="text-xs text-gray-500 font-bold uppercase tracking-wider block">Posisi Memiliki Suksesor</span>
          <span class="text-2xl font-black text-emerald-600">{{ summaryStats.coveredCount }} / {{ summaryStats.total }}</span>
        </div>
      </div>

      <div class="card p-4 bg-gradient-to-br from-white to-gray-50 flex items-center gap-4 border border-gray-200">
        <div class="p-3 rounded-xl bg-blue-50 text-blue-700">
          <ShieldCheckIcon class="w-6 h-6" />
        </div>
        <div>
          <span class="text-xs text-gray-500 font-bold uppercase tracking-wider block">Rasio Cakupan (Coverage)</span>
          <span class="text-2xl font-black text-blue-600">{{ summaryStats.coverageRatio.toFixed(1) }}%</span>
        </div>
      </div>
    </div>

    <!-- Main Workspace: Split columns -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <!-- Left: Critical Positions List -->
      <div class="lg:col-span-5 space-y-4">
        <div class="card">
          <div class="card-header bg-gray-50/50 flex justify-between items-center">
            <span class="font-bold text-gray-900">Daftar Posisi Kunci & Krusial</span>
            <button @click="loadCriticalPositions" class="btn-secondary text-[11px] py-1 px-2">
              <ArrowPathIcon class="w-3.5 h-3.5" />
            </button>
          </div>

          <div class="card-body p-2">
            <div v-if="loading" class="text-center py-12 text-gray-400">
              Memuat data posisi...
            </div>
            <div v-else-if="criticalPositions.length === 0" class="text-center py-16 text-gray-400">
              Belum ada posisi krusial yang didefinisikan.
            </div>
            <div v-else class="space-y-1">
              <div
                v-for="cp in criticalPositions"
                :key="cp.id"
                @click="viewPositionDetail(cp.id)"
                :class="[
                  'w-full text-left px-3 py-3.5 rounded-xl border transition-all cursor-pointer flex justify-between items-center group',
                  selectedPositionId === cp.id
                    ? 'bg-indigo-50/80 border-indigo-200 ring-1 ring-indigo-500/20 shadow-sm'
                    : 'bg-white hover:bg-gray-50 border-gray-150'
                ]"
              >
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-extrabold text-gray-900 text-sm truncate">{{ cp.position_name }}</span>
                    <span class="px-2 py-0.5 rounded-full text-[9px] font-bold border uppercase" :class="getRiskBadgeClass(cp.risk_level)">
                      {{ cp.risk_level }}
                    </span>
                  </div>
                  <div class="text-[11px] text-gray-500 mt-1">
                    Incumbent: <span class="font-medium text-gray-700">{{ cp.incumbent_name || 'Kosong / Belum Ada' }}</span>
                  </div>
                </div>

                <div class="flex items-center gap-3">
                  <div class="text-right">
                    <span class="text-[9px] text-gray-400 block font-bold uppercase">Kandidat</span>
                    <span class="font-extrabold text-sm text-indigo-600">{{ cp.candidates_count }}</span>
                  </div>
                  <ChevronRightIcon class="w-4 h-4 text-gray-400 group-hover:translate-x-0.5 transition-transform" />
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right: Detailed Candidates and Successor Planning -->
      <div class="lg:col-span-7">
        <div v-if="selectedPositionDetail" class="card">
          <div class="card-header bg-gradient-to-r from-gray-50 to-white flex justify-between items-center border-b border-gray-150">
            <div>
              <span class="text-[10px] font-extrabold text-indigo-600 uppercase tracking-wide">Detail Suksesi Posisi</span>
              <h2 class="text-base font-extrabold text-gray-900 mt-0.5">{{ selectedPositionDetail.position_name }}</h2>
              <p class="text-xs text-gray-500">
                Pejabat Saat Ini: <span class="font-bold">{{ selectedPositionDetail.incumbent_name || 'Tidak ada' }}</span>
              </p>
            </div>
            
            <div class="flex gap-2">
              <button v-if="isHR" class="btn-secondary text-[11px] py-1 px-2.5" @click="openEditPosition(selectedPositionDetail)">
                Edit Posisi
              </button>
              <button v-if="isHR" class="btn-danger text-[11px] py-1 px-2.5" @click="deletePosition(selectedPositionDetail.id)">
                Hapus
              </button>
              <button v-if="isHR" class="btn-primary bg-indigo-600 hover:bg-indigo-700 text-[11px] py-1 px-2.5" @click="openAddCandidate">
                + Suksesor
              </button>
            </div>
          </div>

          <div class="card-body space-y-5">
            <!-- Notes -->
            <div v-if="selectedPositionDetail.notes" class="p-3 bg-gray-50 rounded-xl border border-gray-200 text-xs text-gray-600">
              <span class="font-bold text-gray-800 block mb-0.5">Catatan / Justifikasi:</span>
              {{ selectedPositionDetail.notes }}
            </div>

            <!-- Nominated successor list -->
            <div>
              <h3 class="font-bold text-sm text-gray-800 mb-3 flex items-center gap-1.5">
                <SparklesIcon class="w-4 h-4 text-indigo-500" />
                Daftar Nominasi Suksesor Terpilih
              </h3>

              <div v-if="loadingDetail" class="text-center py-6 text-gray-400 text-xs">Memuat detail suksesor...</div>
              <div v-else-if="!selectedPositionDetail.candidates || selectedPositionDetail.candidates.length === 0" class="text-center py-12 border border-dashed rounded-xl text-gray-400 text-xs">
                Belum ada suksesor yang dicalonkan untuk posisi strategis ini. Klik "+ Suksesor" untuk mulai.
              </div>
              <div v-else class="space-y-3">
                <div v-for="cand in selectedPositionDetail.candidates" :key="cand.id" class="p-4 border border-gray-150 rounded-xl hover:shadow-sm transition-all bg-white flex flex-col gap-3">
                  <div class="flex justify-between items-start">
                    <div class="flex items-center gap-2.5">
                      <UserCircleIcon class="w-9 h-9 text-gray-400" />
                      <div>
                        <div class="font-extrabold text-sm text-gray-900">{{ cand.candidate_name }}</div>
                        <div class="text-[10px] text-gray-400 font-mono">NIP: {{ cand.employee_number }}</div>
                      </div>
                    </div>

                    <div class="flex items-center gap-2">
                      <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase shadow-sm" :class="getReadinessClass(cand.readiness)">
                        {{ getReadinessLabel(cand.readiness) }}
                      </span>
                      <div v-if="isHR" class="flex gap-1">
                        <button class="text-xs text-indigo-600 hover:text-indigo-800 px-1.5 py-0.5 rounded hover:bg-indigo-50 font-semibold" @click="openEditCandidate(cand)">Edit</button>
                        <button class="text-xs text-red-600 hover:text-red-800 px-1.5 py-0.5 rounded hover:bg-red-50 font-semibold" @click="removeCandidate(cand.id)">Hapus</button>
                      </div>
                    </div>
                  </div>

                  <!-- 9-box mapping reference if available -->
                  <div v-if="cand.nine_box_label" class="flex items-center gap-1.5 text-[10px] text-indigo-700 bg-indigo-50/50 py-1 px-2.5 rounded-lg border border-indigo-100/30 w-max">
                    <SparklesIcon class="w-3.5 h-3.5 text-indigo-600" />
                    Peta Bakat 9-Box: <span class="font-bold">{{ cand.nine_box_label }}</span> (Performa: {{ Number(cand.performance_score).toFixed(1) }} / Potensi: {{ Number(cand.potential_score).toFixed(1) }})
                  </div>

                  <!-- Development Plan -->
                  <div v-if="cand.development_plan" class="bg-gray-50/50 p-2.5 rounded-lg border border-gray-100 text-[11px] text-gray-600">
                    <span class="font-bold text-gray-700 block mb-0.5">Rencana Pengembangan (Development Plan):</span>
                    {{ cand.development_plan }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="card py-32 text-center text-gray-400 text-sm">
          Pilih salah satu posisi krusial di sebelah kiri untuk melihat peta suksesi dan nominasi kandidat pengganti.
        </div>
      </div>
    </div>

    <!-- ──────────────── MODAL: DEFINE CRITICAL POSITION ──────────────── -->
    <div v-if="showPositionModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showPositionModal = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <div class="px-5 py-3 border-b flex items-center justify-between bg-gray-50">
          <h3 class="font-bold text-gray-900">{{ positionForm.id ? 'Edit Posisi Krusial' : 'Definisikan Posisi Krusial Baru' }}</h3>
          <button @click="showPositionModal = false" class="text-gray-400 hover:text-gray-600"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <div class="p-5 space-y-4">
          <div>
            <label class="label">Pilih Jabatan / Posisi *</label>
            <select v-model="positionForm.position_id" class="input" :disabled="!!positionForm.id">
              <option value="">— Pilih posisi —</option>
              <option v-for="p in activePositionsList" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>

          <div>
            <label class="label">Pejabat Saat Ini (Incumbent)</label>
            <EmployeeSearchSelect v-model="positionForm.incumbent_id" placeholder="Pilih incumbent..." />
          </div>

          <div>
            <label class="label">Tingkat Risiko Kekosongan (Vacancy Risk)</label>
            <select v-model="positionForm.risk_level" class="input">
              <option value="LOW">Low Risk</option>
              <option value="MEDIUM">Medium Risk</option>
              <option value="HIGH">High Risk</option>
              <option value="CRITICAL">Critical Risk</option>
            </select>
          </div>

          <div>
            <label class="label">Notes / Alasan Kritikalitas</label>
            <textarea v-model="positionForm.notes" class="input" rows="3" placeholder="Sebutkan justifikasi posisi ini krusial..."></textarea>
          </div>
        </div>

        <div class="px-5 py-3 border-t flex justify-end gap-2 bg-gray-50">
          <button class="btn-secondary text-xs" @click="showPositionModal = false">Batal</button>
          <button class="btn-primary bg-indigo-600 hover:bg-indigo-700 text-xs" @click="savePosition">Simpan</button>
        </div>
      </div>
    </div>

    <!-- ──────────────── MODAL: NOMINATE CANDIDATE ──────────────── -->
    <div v-if="showCandidateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showCandidateModal = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <div class="px-5 py-3 border-b flex items-center justify-between bg-gray-50">
          <h3 class="font-bold text-gray-900">{{ candidateForm.id ? 'Edit Kesiapan Suksesor' : 'Nominasi Suksesor Baru' }}</h3>
          <button @click="showCandidateModal = false" class="text-gray-400 hover:text-gray-600"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <div class="p-5 space-y-4">
          <div>
            <label class="label">Pilih Calon Pengganti (Successor) *</label>
            <EmployeeSearchSelect v-model="candidateForm.candidate_id" placeholder="Cari karyawan..." :disabled="!!candidateForm.id" />
          </div>

          <div>
            <label class="label">Tingkat Kesiapan (Readiness)</label>
            <select v-model="candidateForm.readiness" class="input">
              <option value="READY_NOW">Siap Sekarang (Ready Now)</option>
              <option value="READY_1_2Y">Siap 1-2 Tahun (Ready 1-2 Years)</option>
              <option value="NOT_READY">Belum Siap (Not Ready)</option>
            </select>
          </div>

          <div>
            <label class="label">Rencana Pengembangan (Development Plan)</label>
            <textarea v-model="candidateForm.development_plan" class="input" rows="3" placeholder="Training, sertifikasi, mentoring atau penugasan khusus..."></textarea>
          </div>
        </div>

        <div class="px-5 py-3 border-t flex justify-end gap-2 bg-gray-50">
          <button class="btn-secondary text-xs" @click="showCandidateModal = false">Batal</button>
          <button class="btn-primary bg-indigo-600 hover:bg-indigo-700 text-xs" @click="saveCandidate">Simpan</button>
        </div>
      </div>
    </div>
  </div>
</template>
