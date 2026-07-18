<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import {
  UserGroupIcon, PlusIcon, ArrowPathIcon, XMarkIcon,
  CheckIcon, DocumentChartBarIcon, CalendarIcon,
  ShieldCheckIcon, StarIcon, AcademicCapIcon, TrashIcon
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const toast = useToast()

const tab = ref('my-feedback')

const isHR = computed(() =>
  auth.can('performance.manage') || auth.isSuperAdmin
)

// List of standard competencies
const COMPETENCIES = [
  { id: 'communication', label: 'Komunikasi', desc: 'Kemampuan menyampaikan ide dengan jelas dan mendengarkan orang lain secara efektif.' },
  { id: 'collaboration', label: 'Kolaborasi', desc: 'Kemampuan bekerja sama dalam tim untuk mencapai tujuan bersama.' },
  { id: 'reliability', label: 'Keandalan', desc: 'Konsistensi dalam memberikan hasil kerja yang berkualitas tepat waktu.' },
  { id: 'technical_skill', label: 'Keahlian Teknis', desc: 'Tingkat penguasaan keterampilan teknis yang dibutuhkan untuk peran tersebut.' },
  { id: 'leadership', label: 'Kepemimpinan', desc: 'Kemampuan membimbing, memotivasi, dan mengarahkan orang lain.' }
]

// ─── State ──────────────────────────────────────────────────────────────
const cycles = ref([])
const activeCycleId = ref('')
const selectedCycle = ref(null)
const myFeedbacks = ref([]) // Nominations assigned to the current user as reviewer
const aggregateData = ref([])
const loadingCycles = ref(false)
const loadingFeedbacks = ref(false)
const loadingAggregate = ref(false)

// Cycle Form (Create / Edit)
const showCycleModal = ref(false)
const cycleForm = reactive({
  id: null,
  name: '',
  description: '',
  year: new Date().getFullYear(),
  start_date: '',
  end_date: '',
  status: 'DRAFT'
})

// Nomination Form
const showNominationModal = ref(false)
const nominationForm = reactive({
  cycle_id: '',
  reviewee_id: null,
  reviewer_id: null,
  relationship: 'PEER'
})

// Feedback Form
const activeFeedback = ref(null)
const feedbackScores = ref({})
const feedbackComments = ref({})
const submittingFeedback = ref(false)

// ─── Actions: Cycles ────────────────────────────────────────────────────
async function loadCycles() {
  loadingCycles.value = true
  try {
    const res = await api.get('/review-360/cycles')
    cycles.value = res.data?.data || []
    if (cycles.value.length > 0 && !activeCycleId.value) {
      activeCycleId.value = cycles.value[0].id
      await selectCycle(activeCycleId.value)
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat cycles')
  } finally {
    loadingCycles.value = false
  }
}

async function selectCycle(id) {
  if (!id) return
  try {
    const res = await api.get(`/review-360/cycles/${id}`)
    selectedCycle.value = res.data?.data || null
    if (tab.value === 'aggregate') {
      loadAggregate(id)
    }
  } catch (e) {
    toast.error('Gagal memuat detail cycle')
  }
}

function openCreateCycle() {
  cycleForm.id = null
  cycleForm.name = ''
  cycleForm.description = ''
  cycleForm.year = new Date().getFullYear()
  cycleForm.start_date = ''
  cycleForm.end_date = ''
  cycleForm.status = 'DRAFT'
  showCycleModal.value = true
}

function openEditCycle(c) {
  cycleForm.id = c.id
  cycleForm.name = c.name
  cycleForm.description = c.description
  cycleForm.year = c.year
  cycleForm.start_date = c.start_date
  cycleForm.end_date = c.end_date
  cycleForm.status = c.status
  showCycleModal.value = true
}

async function saveCycle() {
  if (!cycleForm.name || !cycleForm.start_date || !cycleForm.end_date) {
    toast.warning('Nama, tanggal mulai, dan tanggal selesai wajib diisi')
    return
  }
  try {
    const payload = { ...cycleForm }
    if (cycleForm.id) {
      await api.put(`/review-360/cycles/${cycleForm.id}`, payload)
      toast.success('Cycle berhasil diperbarui')
    } else {
      await api.post('/review-360/cycles', payload)
      toast.success('Cycle baru berhasil dibuat')
    }
    showCycleModal.value = false
    await loadCycles()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan cycle')
  }
}

// ─── Actions: Nominations ──────────────────────────────────────────────
function openAddNomination() {
  if (!activeCycleId.value) {
    toast.warning('Pilih cycle terlebih dahulu')
    return
  }
  nominationForm.cycle_id = activeCycleId.value
  nominationForm.reviewee_id = null
  nominationForm.reviewer_id = null
  nominationForm.relationship = 'PEER'
  showNominationModal.value = true
}

async function addNomination() {
  if (!nominationForm.reviewee_id || !nominationForm.reviewer_id) {
    toast.warning('Reviewee dan Reviewer wajib dipilih')
    return
  }
  try {
    await api.post('/review-360/nominations', nominationForm)
    toast.success('Nominasi berhasil ditambahkan')
    showNominationModal.value = false
    await selectCycle(activeCycleId.value)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menambahkan nominasi')
  }
}

async function removeNomination(id) {
  if (!confirm('Apakah Anda yakin ingin menghapus nominasi ini?')) return
  try {
    await api.delete(`/review-360/nominations/${id}`)
    toast.success('Nominasi berhasil dihapus')
    await selectCycle(activeCycleId.value)
  } catch (e) {
    toast.error('Gagal menghapus nominasi')
  }
}

// ─── Actions: Feedback filling ──────────────────────────
async function loadMyFeedbacks() {
  if (!auth.user?.employee_id) return
  loadingFeedbacks.value = true
  try {
    // In actual implementation, we might filter nominations on backend for reviewer = me
    // Since the backend doesn't have a direct "mine" endpoint, we can load active cycles nominations
    // or simulate finding my nominations across cycles.
    // For a clean implementation, we can check all pending nominations in active cycles where reviewer is this employee.
    const activeCycles = cycles.value.filter(c => c.status === 'ACTIVE')
    const list = []
    for (const c of activeCycles) {
      const res = await api.get(`/review-360/cycles/${c.id}`)
      const nominations = res.data?.data?.nominations || []
      const mine = nominations.filter(n => Number(n.reviewer_id) === Number(auth.user.employee_id) && n.status === 'PENDING')
      mine.forEach(m => {
        m.cycle_name = c.name
        list.push(m)
      })
    }
    myFeedbacks.value = list
  } catch (e) {
    console.error('Error loading feedbacks to write:', e)
  } finally {
    loadingFeedbacks.value = false
  }
}

function startFillFeedback(nom) {
  activeFeedback.value = nom
  // Initialize scores and comments
  COMPETENCIES.forEach(c => {
    feedbackScores.value[c.id] = 3 // default score
    feedbackComments.value[c.id] = ''
  })
}

async function submitFeedback() {
  try {
    submittingFeedback.value = true
    const responses = COMPETENCIES.map(c => ({
      competency: c.id,
      score: feedbackScores.value[c.id],
      comment: feedbackComments.value[c.id]
    }))

    await api.post(`/review-360/nominations/${activeFeedback.value.id}/feedback`, {
      responses
    })
    toast.success('Feedback berhasil disubmit. Terima kasih!')
    activeFeedback.value = null
    await loadMyFeedbacks()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mengirim feedback')
  } finally {
    submittingFeedback.value = false
  }
}

// ─── Actions: Reports / Aggregate ────────────────────────────────────
async function loadAggregate(cycleId) {
  if (!cycleId) return
  loadingAggregate.value = true
  try {
    const res = await api.get(`/review-360/cycles/${cycleId}/aggregate`)
    // Group aggregate results by employee to display them clearly
    const raw = res.data?.data || []
    const grouped = {}
    raw.forEach(row => {
      const empId = row.reviewee_id
      if (!grouped[empId]) {
        grouped[empId] = {
          reviewee_id: empId,
          reviewee_name: row.reviewee_name,
          by_relationship: {},
          by_competency: {},
          scores: []
        }
      }
      // Avg by relationship
      if (!grouped[empId].by_relationship[row.relationship]) {
        grouped[empId].by_relationship[row.relationship] = []
      }
      grouped[empId].by_relationship[row.relationship].push(Number(row.avg_score))

      // Avg by competency
      if (!grouped[empId].by_competency[row.competency]) {
        grouped[empId].by_competency[row.competency] = []
      }
      grouped[empId].by_competency[row.competency].push(Number(row.avg_score))
      grouped[empId].scores.push(row)
    })

    // Compute averages
    aggregateData.value = Object.values(grouped).map(emp => {
      const relSummary = {}
      Object.keys(emp.by_relationship).forEach(rel => {
        const arr = emp.by_relationship[rel]
        relSummary[rel] = arr.reduce((a, b) => a + b, 0) / arr.length
      })

      const compSummary = {}
      Object.keys(emp.by_competency).forEach(comp => {
        const arr = emp.by_competency[comp]
        compSummary[comp] = arr.reduce((a, b) => a + b, 0) / arr.length
      })

      // Overall average
      const allScores = emp.scores.map(s => Number(s.avg_score))
      const overall = allScores.reduce((a, b) => a + b, 0) / allScores.length

      return {
        ...emp,
        relSummary,
        compSummary,
        overall
      }
    })
  } catch (e) {
    toast.error('Gagal memuat data laporan agregat')
  } finally {
    loadingAggregate.value = false
  }
}

function handleTabChange(t) {
  tab.value = t
  if (t === 'cycles') {
    loadCycles()
  } else if (t === 'my-feedback') {
    loadMyFeedbacks()
  } else if (t === 'aggregate' && activeCycleId.value) {
    loadAggregate(activeCycleId.value)
  }
}

onMounted(() => {
  loadCycles()
  loadMyFeedbacks()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="relative bg-gradient-to-r from-purple-900 to-indigo-800 text-white rounded-2xl p-6 shadow-lg overflow-hidden">
      <div class="absolute -right-16 -top-16 w-48 h-48 bg-purple-500 rounded-full mix-blend-multiply filter blur-xl opacity-30 animate-pulse"></div>
      <div class="absolute -left-16 -bottom-16 w-48 h-48 bg-indigo-500 rounded-full mix-blend-multiply filter blur-xl opacity-30 animate-pulse"></div>
      
      <div class="relative z-10">
        <h1 class="text-3xl font-extrabold flex items-center gap-3">
          <UserGroupIcon class="w-8 h-8 text-purple-300" />
          360-Degree Feedback
        </h1>
        <p class="text-purple-100 text-sm mt-2 max-w-2xl">
          Sistem penilaian komprehensif yang mengumpulkan feedback dari rekan sejawat, bawahan, atasan, dan penilaian mandiri untuk mengukur kompetensi secara objektif.
        </p>
      </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="flex border-b border-gray-200 bg-white p-1.5 rounded-xl shadow-sm gap-1">
      <button
        @click="handleTabChange('my-feedback')"
        :class="[
          'flex-1 py-2.5 text-sm font-semibold rounded-lg transition-all flex items-center justify-center gap-2',
          tab === 'my-feedback' ? 'bg-indigo-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-50'
        ]"
      >
        <StarIcon class="w-4.5 h-4.5" />
        Isi Feedback Karyawan
      </button>
      <button
        v-if="isHR"
        @click="handleTabChange('cycles')"
        :class="[
          'flex-1 py-2.5 text-sm font-semibold rounded-lg transition-all flex items-center justify-center gap-2',
          tab === 'cycles' ? 'bg-indigo-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-50'
        ]"
      >
        <CalendarIcon class="w-4.5 h-4.5" />
        Siklus Review (Admin)
      </button>
      <button
        v-if="isHR"
        @click="handleTabChange('aggregate')"
        :class="[
          'flex-1 py-2.5 text-sm font-semibold rounded-lg transition-all flex items-center justify-center gap-2',
          tab === 'aggregate' ? 'bg-indigo-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-50'
        ]"
      >
        <DocumentChartBarIcon class="w-4.5 h-4.5" />
        Laporan Agregat (Admin)
      </button>
    </div>

    <!-- ──────────────── TAB: FILL FEEDBACK ──────────────── -->
    <div v-if="tab === 'my-feedback'" class="space-y-4">
      <div v-if="activeFeedback" class="card">
        <div class="card-header bg-gradient-to-r from-indigo-50 to-purple-50">
          <div>
            <span class="text-xs font-bold text-indigo-600 uppercase tracking-wide">Formulir Pengisian Feedback 360</span>
            <h2 class="text-lg font-bold text-gray-900 mt-1">Reviewer untuk: {{ activeFeedback.reviewee_name }}</h2>
            <p class="text-xs text-gray-500 mt-0.5">Siklus: {{ activeFeedback.cycle_name }} | Hubungan: {{ activeFeedback.relationship }}</p>
          </div>
          <button @click="activeFeedback = null" class="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        
        <div class="card-body space-y-6">
          <div class="p-4 bg-indigo-50 rounded-xl border border-indigo-100 flex items-start gap-3 text-sm text-indigo-900">
            <ShieldCheckIcon class="w-5 h-5 text-indigo-600 flex-shrink-0 mt-0.5" />
            <div>
              <span class="font-bold">Keamanan Data:</span> Feedback yang Anda berikan dijamin kerahasiaannya dan akan digabungkan secara anonim. Penilaian jujur membantu perkembangan profesional rekan kerja Anda.
            </div>
          </div>

          <!-- Competencies Scoring -->
          <div v-for="comp in COMPETENCIES" :key="comp.id" class="p-5 border border-gray-100 rounded-xl shadow-sm space-y-4 bg-white hover:border-indigo-100 transition-colors">
            <div>
              <h3 class="font-bold text-gray-800 text-base">{{ comp.label }}</h3>
              <p class="text-xs text-gray-500 mt-0.5">{{ comp.desc }}</p>
            </div>

            <!-- Score Selector -->
            <div>
              <label class="block text-xs font-bold text-gray-700 mb-2">Pilih Skor (1-5):</label>
              <div class="flex gap-2">
                <button
                  v-for="s in 5"
                  :key="s"
                  type="button"
                  @click="feedbackScores[comp.id] = s"
                  :class="[
                    'flex-1 py-3 rounded-xl border-2 font-bold transition-all text-sm',
                    feedbackScores[comp.id] === s
                      ? 'bg-indigo-600 text-white border-indigo-700 shadow-md transform scale-105'
                      : 'border-gray-200 hover:border-indigo-200 hover:bg-indigo-50 text-gray-700'
                  ]"
                >
                  {{ s }}
                </button>
              </div>
              <div class="flex justify-between text-[10px] text-gray-400 px-1 mt-1.5">
                <span>Sangat Kurang</span>
                <span>Cukup</span>
                <span>Sangat Baik</span>
              </div>
            </div>

            <!-- Comments for each competency -->
            <div>
              <label class="block text-xs font-bold text-gray-700 mb-1">Komentar Konstruktif:</label>
              <textarea
                v-model="feedbackComments[comp.id]"
                rows="2"
                class="input"
                placeholder="Berikan contoh perilaku spesifik atau masukan perbaikan..."
              ></textarea>
            </div>
          </div>

          <div class="flex justify-end gap-3 pt-3 border-t">
            <button class="btn-secondary" @click="activeFeedback = null" :disabled="submittingFeedback">Batal</button>
            <button class="btn-primary bg-indigo-600 hover:bg-indigo-700" @click="submitFeedback" :disabled="submittingFeedback">
              <CheckIcon class="w-4 h-4" />
              {{ submittingFeedback ? 'Mengirim...' : 'Kirim Penilaian' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Feedbacks waiting list -->
      <div v-else class="card">
        <div class="card-header">
          <span class="font-bold text-gray-900">Daftar Permintaan Feedback untuk Anda</span>
          <button @click="loadMyFeedbacks" class="btn-secondary text-xs py-1.5 px-3">
            <ArrowPathIcon class="w-4 h-4" />
            Refresh
          </button>
        </div>

        <div class="card-body">
          <div v-if="loadingFeedbacks" class="text-center py-12 text-gray-400">
            Memuat daftar permintaan...
          </div>
          <div v-else-if="myFeedbacks.length === 0" class="text-center py-16 text-gray-400">
            <AcademicCapIcon class="w-12 h-12 mx-auto text-gray-300 mb-2" />
            <p class="font-medium text-gray-500">Tidak Ada Permintaan Feedback</p>
            <p class="text-xs text-gray-400 mt-1">Saat ini tidak ada penilaian 360 yang perlu Anda isi.</p>
          </div>
          <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div v-for="nom in myFeedbacks" :key="nom.id" class="p-4 border border-gray-200 rounded-xl hover:shadow-md transition-all bg-gradient-to-br from-white to-gray-50/50 flex flex-col justify-between">
              <div>
                <div class="flex justify-between items-start">
                  <div>
                    <h3 class="font-bold text-gray-800">{{ nom.reviewee_name }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Siklus: {{ nom.cycle_name }}</p>
                  </div>
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-700 uppercase">
                    {{ nom.relationship }}
                  </span>
                </div>
                <div class="mt-3 text-xs text-gray-600 bg-white p-2 rounded-lg border border-gray-100">
                  Anda terpilih untuk mengevaluasi kinerja dan kompetensi karyawan ini.
                </div>
              </div>
              
              <button class="btn-primary mt-4 bg-indigo-600 hover:bg-indigo-700 text-xs w-full flex justify-center items-center gap-1.5" @click="startFillFeedback(nom)">
                <StarIcon class="w-4 h-4" />
                Mulai Isi Penilaian
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ──────────────── TAB: CYCLES MANAGEMENT (ADMIN) ──────────────── -->
    <div v-if="tab === 'cycles' && isHR" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <!-- Left side: Cycles list -->
      <div class="lg:col-span-4 space-y-4">
        <div class="card">
          <div class="card-header bg-gray-50/80">
            <span class="font-bold text-gray-900">Siklus Feedback</span>
            <button class="btn-primary bg-indigo-600 hover:bg-indigo-700 text-xs" @click="openCreateCycle">
              <PlusIcon class="w-4 h-4" />
              Siklus Baru
            </button>
          </div>
          <div class="card-body p-2">
            <div v-if="loadingCycles" class="text-center py-6 text-gray-400 text-sm">Memuat...</div>
            <div v-else-if="cycles.length === 0" class="text-center py-10 text-gray-400 text-sm">Belum ada siklus feedback</div>
            <div v-else class="space-y-1">
              <button
                v-for="c in cycles"
                :key="c.id"
                @click="activeCycleId = c.id; selectCycle(c.id)"
                :class="[
                  'w-full text-left px-3 py-3 rounded-lg text-sm transition-all flex justify-between items-center',
                  activeCycleId === c.id ? 'bg-indigo-50 border-l-4 border-indigo-600 font-semibold text-indigo-900' : 'hover:bg-gray-50 text-gray-700'
                ]"
              >
                <div>
                  <div class="font-bold">{{ c.name }}</div>
                  <div class="text-[11px] text-gray-500 mt-0.5">Tahun: {{ c.year }} | Status: {{ c.status }}</div>
                </div>
                <span
                  class="px-2 py-0.5 text-[9px] rounded-full font-extrabold uppercase"
                  :class="{
                    'bg-green-100 text-green-800': c.status === 'ACTIVE',
                    'bg-gray-100 text-gray-800': c.status === 'DRAFT',
                    'bg-red-100 text-red-800': c.status === 'CLOSED'
                  }"
                >
                  {{ c.status }}
                </span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Right side: Selected cycle detail & nominations -->
      <div class="lg:col-span-8">
        <div v-if="selectedCycle" class="card">
          <div class="card-header flex justify-between items-center border-b">
            <div>
              <h2 class="text-lg font-bold text-gray-900">{{ selectedCycle.name }}</h2>
              <p class="text-xs text-gray-500 mt-0.5">Periode: {{ selectedCycle.start_date }} s/d {{ selectedCycle.end_date }}</p>
            </div>
            <div class="flex gap-2">
              <button class="btn-secondary text-xs" @click="openEditCycle(selectedCycle)">
                Edit Siklus
              </button>
              <button class="btn-primary bg-indigo-600 hover:bg-indigo-700 text-xs" @click="openAddNomination">
                + Tambah Nominasi
              </button>
            </div>
          </div>

          <div class="card-body space-y-4">
            <p v-if="selectedCycle.description" class="text-sm text-gray-600 bg-gray-50 p-3 rounded-lg border border-gray-100">
              {{ selectedCycle.description }}
            </p>

            <div>
              <h3 class="font-bold text-gray-800 text-sm mb-3">Daftar Nominasi Reviewer</h3>
              
              <div class="overflow-x-auto border rounded-xl">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Reviewee (Di-review)</th>
                      <th>Reviewer (Menilai)</th>
                      <th>Relationship</th>
                      <th>Status</th>
                      <th class="text-right">Aksi</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <tr v-if="!selectedCycle.nominations || selectedCycle.nominations.length === 0">
                      <td colspan="5" class="text-center py-8 text-gray-400 text-xs">Belum ada nominasi untuk siklus ini.</td>
                    </tr>
                    <tr v-for="nom in selectedCycle.nominations" :key="nom.id">
                      <td>
                        <div class="font-bold text-gray-900">{{ nom.reviewee_name }}</div>
                      </td>
                      <td>
                        <div class="font-medium text-gray-800">{{ nom.reviewer_name }}</div>
                      </td>
                      <td>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700">
                          {{ nom.relationship }}
                        </span>
                      </td>
                      <td>
                        <span
                          class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                          :class="nom.status === 'SUBMITTED' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                        >
                          {{ nom.status }}
                        </span>
                      </td>
                      <td class="text-right">
                        <button class="text-red-600 hover:text-red-800 p-1" @click="removeNomination(nom.id)">
                          <TrashIcon class="w-4 h-4" />
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="card py-24 text-center text-gray-400">
          Silakan pilih atau buat siklus feedback terlebih dahulu.
        </div>
      </div>
    </div>

    <!-- ──────────────── TAB: AGGREGATE REPORTS (ADMIN/HR) ──────────────── -->
    <div v-if="tab === 'aggregate' && isHR" class="card">
      <div class="card-header bg-gray-50 flex items-center justify-between">
        <span class="font-bold text-gray-900">Laporan Agregat Skor 360</span>
        <div class="flex items-center gap-2">
          <label class="text-xs text-gray-600">Pilih Siklus:</label>
          <select v-model="activeCycleId" @change="loadAggregate(activeCycleId)" class="input py-1 text-xs max-w-xs">
            <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
      </div>

      <div class="card-body">
        <div v-if="loadingAggregate" class="text-center py-12 text-gray-400">
          Memproses data laporan...
        </div>
        <div v-else-if="aggregateData.length === 0" class="text-center py-16 text-gray-400">
          <DocumentChartBarIcon class="w-12 h-12 mx-auto text-gray-300 mb-2" />
          <p class="font-medium text-gray-500">Belum Ada Feedback Masuk</p>
          <p class="text-xs text-gray-400 mt-1">Siklus ini belum memiliki feedback tersubmit untuk diagregasikan.</p>
        </div>
        <div v-else class="space-y-6">
          <div v-for="emp in aggregateData" :key="emp.reviewee_id" class="p-5 border border-gray-200 rounded-xl shadow-sm space-y-4">
            <div class="flex justify-between items-start border-b pb-3">
              <div>
                <h3 class="font-bold text-lg text-gray-900">{{ emp.reviewee_name }}</h3>
                <p class="text-xs text-gray-500">Hasil Penilaian Agregat 360 Karyawan</p>
              </div>
              <div class="text-right">
                <span class="text-xs text-gray-500 block">Skor Rata-rata</span>
                <span class="text-2xl font-black text-indigo-600">{{ emp.overall.toFixed(2) }} / 5.0</span>
              </div>
            </div>

            <!-- Breakdown by relationship and competency -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Relationship view -->
              <div class="space-y-3">
                <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider">Berdasarkan Perspektif Hubungan</h4>
                <div class="space-y-2.5">
                  <div v-for="(score, rel) in emp.relSummary" :key="rel" class="space-y-1">
                    <div class="flex justify-between text-xs font-medium">
                      <span class="text-gray-600">{{ rel }}</span>
                      <span class="font-bold text-gray-900">{{ score.toFixed(2) }}</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                      <div class="bg-indigo-600 h-full rounded-full" :style="{ width: `${(score / 5) * 100}%` }"></div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Competency view -->
              <div class="space-y-3">
                <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider">Berdasarkan Aspek Kompetensi</h4>
                <div class="space-y-2.5">
                  <div v-for="(score, comp) in emp.compSummary" :key="comp" class="space-y-1">
                    <div class="flex justify-between text-xs font-medium">
                      <span class="text-gray-600 capitalize">{{ comp.replace('_', ' ') }}</span>
                      <span class="font-bold text-gray-900">{{ score.toFixed(2) }}</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                      <div class="bg-purple-600 h-full rounded-full" :style="{ width: `${(score / 5) * 100}%` }"></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ──────────────── MODAL: CREATE / EDIT CYCLE ──────────────── -->
    <div v-if="showCycleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showCycleModal = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <div class="px-5 py-3 border-b flex items-center justify-between bg-gray-50">
          <h3 class="font-bold text-gray-900">{{ cycleForm.id ? 'Edit Siklus 360' : 'Siklus 360 Baru' }}</h3>
          <button @click="showCycleModal = false" class="text-gray-400 hover:text-gray-600"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <div class="p-5 space-y-3">
          <div>
            <label class="label">Nama Siklus *</label>
            <input v-model="cycleForm.name" type="text" class="input" placeholder="contoh: Q3 360 Feedback Review" />
          </div>
          <div>
            <label class="label">Deskripsi</label>
            <textarea v-model="cycleForm.description" class="input" rows="2" placeholder="Tuliskan petunjuk pengisian..."></textarea>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Tahun *</label>
              <input v-model.number="cycleForm.year" type="number" class="input" />
            </div>
            <div>
              <label class="label">Status *</label>
              <select v-model="cycleForm.status" class="input">
                <option value="DRAFT">Draft</option>
                <option value="ACTIVE">Active</option>
                <option value="CLOSED">Closed</option>
              </select>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Tanggal Mulai *</label>
              <AppDatePicker v-model="cycleForm.start_date"  class="input" />
            </div>
            <div>
              <label class="label">Tanggal Selesai *</label>
              <AppDatePicker v-model="cycleForm.end_date"  class="input" />
            </div>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2 bg-gray-50">
          <button class="btn-secondary text-xs" @click="showCycleModal = false">Batal</button>
          <button class="btn-primary bg-indigo-600 hover:bg-indigo-700 text-xs" @click="saveCycle">Simpan</button>
        </div>
      </div>
    </div>

    <!-- ──────────────── MODAL: ADD NOMINATION ──────────────── -->
    <div v-if="showNominationModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showNominationModal = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <div class="px-5 py-3 border-b flex items-center justify-between bg-gray-50">
          <h3 class="font-bold text-gray-900">Tambah Nominasi Reviewer</h3>
          <button @click="showNominationModal = false" class="text-gray-400 hover:text-gray-600"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <div class="p-5 space-y-4">
          <div>
            <label class="label">Reviewee (Karyawan yang Di-review)</label>
            <EmployeeSearchSelect v-model="nominationForm.reviewee_id" placeholder="Cari karyawan..." />
          </div>
          <div>
            <label class="label">Reviewer (Karyawan yang Menilai)</label>
            <EmployeeSearchSelect v-model="nominationForm.reviewer_id" placeholder="Cari karyawan..." />
          </div>
          <div>
            <label class="label">Hubungan Profesional (Perspektif)</label>
            <select v-model="nominationForm.relationship" class="input">
              <option value="SELF">Self (Penilaian Mandiri)</option>
              <option value="PEER">Peer (Rekan Kerja Sejajar)</option>
              <option value="SUPERIOR">Superior (Atasan Langsung)</option>
              <option value="SUBORDINATE">Subordinate (Bawahan Langsung)</option>
            </select>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2 bg-gray-50">
          <button class="btn-secondary text-xs" @click="showNominationModal = false">Batal</button>
          <button class="btn-primary bg-indigo-600 hover:bg-indigo-700 text-xs" @click="addNomination">Tambah</button>
        </div>
      </div>
    </div>
  </div>
</template>
