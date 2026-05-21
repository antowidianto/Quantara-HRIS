<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import {
  ArrowLeftIcon, PlusIcon, ArrowRightIcon, XCircleIcon, CheckBadgeIcon,
  StarIcon, TrashIcon,
} from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const jobId = computed(() => Number(route.params.id))
const job = ref(null)
const candidates = ref([])
const loading = ref(false)

// Show modals
const showAddCand = ref(false)
const candForm = reactive({
  full_name: '', email: '', phone: '', current_position: '', current_company: '',
  years_experience: 0, expected_salary: 0, available_from: '', source: 'WEBSITE', notes: '',
})

const showCandDetail = ref(null)
const detailData = ref(null)
const moveStageInput = reactive({
  stage: '', feedback: '', scheduled_at: '', rating: null,
})
const showRejectModal = ref(null)
const rejectReason = ref('')
const showHireModal = ref(null)
const hireForm = reactive({
  basic_salary: 0, join_date: '',
})

const STAGES = [
  { code: 'NEW',            label: '🆕 Baru',         color: 'bg-gray-100' },
  { code: 'SCREENING',      label: '📋 Screening',    color: 'bg-blue-50' },
  { code: 'INTERVIEW_HR',   label: '🗣️ Interview HR',  color: 'bg-indigo-50' },
  { code: 'INTERVIEW_USER', label: '👥 Interview User',color: 'bg-purple-50' },
  { code: 'OFFER',          label: '💰 Offer',         color: 'bg-amber-50' },
  { code: 'HIRED',          label: '✅ Hired',         color: 'bg-emerald-50' },
]
const TERMINAL = ['HIRED','REJECTED','WITHDRAWN']
const SOURCES = ['WEBSITE','LINKEDIN','JOBSTREET','REFERRAL','WALK_IN','OTHER']

const canManage = computed(() => auth.can('recruitment.manage'))
const canHire = computed(() => auth.can('recruitment.hire'))

const candidatesByStage = computed(() => {
  const map = {}
  STAGES.forEach(s => map[s.code] = [])
  candidates.value.forEach(c => {
    if (! TERMINAL.includes(c.current_stage)) {
      if (map[c.current_stage]) map[c.current_stage].push(c)
    }
  })
  return map
})

const rejectedCount = computed(() => candidates.value.filter(c => c.current_stage === 'REJECTED').length)

async function fetchAll() {
  loading.value = true
  try {
    const [j, c] = await Promise.all([
      api.get(`/job-postings/${jobId.value}`),
      api.get('/candidates', { params: { job_posting_id: jobId.value, per_page: 200 } }),
    ])
    job.value = j.data.data
    candidates.value = c.data.data || []
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
  finally { loading.value = false }
}

onMounted(fetchAll)

function openAddCandidate() {
  Object.assign(candForm, {
    full_name: '', email: '', phone: '', current_position: '', current_company: '',
    years_experience: 0, expected_salary: 0, available_from: '', source: 'WEBSITE', notes: '',
  })
  showAddCand.value = true
}

async function addCandidate() {
  if (! candForm.full_name) { toast.error('Nama wajib'); return }
  try {
    await api.post('/candidates', { ...candForm, job_posting_id: jobId.value })
    toast.success('Kandidat ditambahkan')
    showAddCand.value = false
    fetchAll()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function openCandidateDetail(c) {
  showCandDetail.value = c
  try {
    const res = await api.get(`/candidates/${c.id}`)
    detailData.value = res.data.data
  } catch (e) { toast.error('Gagal'); showCandDetail.value = null }
}

async function moveStage(c, newStage) {
  if (newStage === 'HIRED') return openHireModal(c)
  if (newStage === 'REJECTED') return openRejectModal(c)

  try {
    await api.post(`/candidates/${c.id}/move-stage`, { stage: newStage })
    toast.success(`Pindah ke ${newStage}`)
    fetchAll()
    if (showCandDetail.value && showCandDetail.value.id === c.id) {
      const res = await api.get(`/candidates/${c.id}`)
      detailData.value = res.data.data
      showCandDetail.value = res.data.data
    }
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

function openRejectModal(c) {
  showRejectModal.value = c
  rejectReason.value = ''
}

async function doReject() {
  if (rejectReason.value.length < 5) { toast.error('Alasan min 5 karakter'); return }
  try {
    await api.post(`/candidates/${showRejectModal.value.id}/reject`, { reason: rejectReason.value })
    toast.success('Kandidat ditolak')
    showRejectModal.value = null
    fetchAll()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

function openHireModal(c) {
  showHireModal.value = c
  Object.assign(hireForm, {
    basic_salary: c.expected_salary || 0,
    join_date: c.available_from || '',
  })
}

async function doHire() {
  if (! canHire.value) { toast.error('Butuh permission recruitment.hire'); return }
  try {
    // Move to HIRED dulu kalau belum
    if (showHireModal.value.current_stage !== 'HIRED') {
      await api.post(`/candidates/${showHireModal.value.id}/move-stage`, { stage: 'HIRED' })
    }
    const res = await api.post(`/candidates/${showHireModal.value.id}/hire`, hireForm)
    toast.success(`Kandidat di-hire sebagai employee #${res.data.data.employee_id}. Onboarding tasks otomatis dibuat.`)
    showHireModal.value = null
    fetchAll()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function deleteCandidate(c) {
  const ok = await confirmDialog({
    title: 'Hapus kandidat?', message: c.full_name,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/candidates/${c.id}`)
    fetchAll()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

function nextStage(currentStage) {
  const idx = STAGES.findIndex(s => s.code === currentStage)
  if (idx === -1 || idx === STAGES.length - 1) return null
  return STAGES[idx + 1].code
}
</script>

<template>
  <div>
    <button class="text-sm text-gray-600 hover:text-primary-600 flex items-center gap-1 mb-3" @click="router.push('/recruitment/jobs')">
      <ArrowLeftIcon class="w-4 h-4" /> Kembali ke list posting
    </button>

    <div v-if="job" class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold">{{ job.title }}</h1>
        <p class="text-sm text-gray-500">
          {{ job.reference_number }} · {{ job.employment_type }} · Headcount {{ job.headcount }} ·
          <span class="font-mono">{{ candidates.length }} kandidat</span>
          <span v-if="rejectedCount > 0" class="text-red-600">({{ rejectedCount }} ditolak)</span>
        </p>
      </div>
      <button v-if="canManage" class="btn-primary" @click="openAddCandidate">
        <PlusIcon class="w-4 h-4" /> Tambah Kandidat
      </button>
    </div>

    <!-- Kanban board -->
    <div v-if="loading" class="text-center py-12 text-gray-400">Memuat…</div>
    <div v-else class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
      <div v-for="stage in STAGES" :key="stage.code"
        :class="['rounded-lg p-3 min-h-64', stage.color]">
        <div class="font-semibold text-sm mb-3 flex items-center justify-between">
          <span>{{ stage.label }}</span>
          <span class="px-2 py-0.5 bg-white rounded text-xs">{{ candidatesByStage[stage.code]?.length || 0 }}</span>
        </div>
        <div class="space-y-2">
          <div v-for="c in candidatesByStage[stage.code]" :key="c.id"
            class="bg-white rounded-md shadow-sm p-3 cursor-pointer hover:shadow-md transition"
            @click="openCandidateDetail(c)">
            <div class="font-medium text-sm">{{ c.full_name }}</div>
            <div class="text-xs text-gray-500">{{ c.current_position || '-' }}</div>
            <div v-if="c.expected_salary" class="text-xs text-gray-500 mt-1 font-mono">
              {{ fmtCurrency(c.expected_salary) }}
            </div>
            <div v-if="c.rating" class="flex items-center gap-0.5 mt-1">
              <StarIcon v-for="n in c.rating" :key="n" class="w-3 h-3 text-amber-400 fill-amber-400" />
            </div>
            <div v-if="canManage" class="flex gap-1 mt-2 pt-2 border-t border-gray-100" @click.stop>
              <button v-if="nextStage(c.current_stage)"
                class="text-xs text-blue-600 hover:bg-blue-50 px-2 py-1 rounded flex items-center gap-1"
                :title="`Pindah ke ${nextStage(c.current_stage)}`"
                @click="moveStage(c, nextStage(c.current_stage))">
                <ArrowRightIcon class="w-3 h-3" /> {{ nextStage(c.current_stage) }}
              </button>
              <button class="text-xs text-red-600 hover:bg-red-50 px-2 py-1 rounded ml-auto"
                title="Reject" @click="openRejectModal(c)">
                <XCircleIcon class="w-3 h-3" />
              </button>
            </div>
          </div>
          <div v-if="!candidatesByStage[stage.code]?.length"
            class="text-xs text-center text-gray-400 py-4">— kosong —</div>
        </div>
      </div>
    </div>

    <!-- Add candidate modal -->
    <div v-if="showAddCand" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Tambah Kandidat</h2>
          <button class="text-gray-500" @click="showAddCand = false">&times;</button>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="md:col-span-2">
            <label class="label">Nama Lengkap *</label>
            <input v-model="candForm.full_name" class="input" />
          </div>
          <div><label class="label">Email</label><input v-model="candForm.email" class="input" /></div>
          <div><label class="label">Telepon</label><input v-model="candForm.phone" class="input" /></div>
          <div><label class="label">Posisi Saat Ini</label><input v-model="candForm.current_position" class="input" /></div>
          <div><label class="label">Perusahaan Saat Ini</label><input v-model="candForm.current_company" class="input" /></div>
          <div><label class="label">Pengalaman (tahun)</label><input type="number" v-model="candForm.years_experience" class="input" /></div>
          <div><label class="label">Expected Salary (Rp)</label><input type="number" v-model="candForm.expected_salary" class="input" /></div>
          <div><label class="label">Available From</label><input type="date" v-model="candForm.available_from" class="input" /></div>
          <div><label class="label">Sumber</label>
            <select v-model="candForm.source" class="input">
              <option v-for="s in SOURCES" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <div class="md:col-span-2"><label class="label">Catatan</label>
            <textarea v-model="candForm.notes" class="input" rows="2"></textarea>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showAddCand = false">Batal</button>
          <button class="btn-primary" @click="addCandidate">Simpan</button>
        </div>
      </div>
    </div>

    <!-- Candidate detail modal -->
    <div v-if="showCandDetail && detailData" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <div>
            <h2 class="font-semibold">{{ detailData.full_name }}</h2>
            <div class="text-xs text-gray-500">
              Stage: <strong>{{ detailData.current_stage }}</strong>
              <span v-if="detailData.converted_employee_id" class="ml-2 px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded text-xs">
                Sudah jadi Employee #{{ detailData.converted_employee_id }}
              </span>
            </div>
          </div>
          <button class="text-gray-500" @click="showCandDetail = null">&times;</button>
        </div>

        <div class="p-6 space-y-4">
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div><div class="text-xs text-gray-500">Email</div>{{ detailData.email || '-' }}</div>
            <div><div class="text-xs text-gray-500">Telepon</div>{{ detailData.phone || '-' }}</div>
            <div><div class="text-xs text-gray-500">Posisi Saat Ini</div>{{ detailData.current_position || '-' }}</div>
            <div><div class="text-xs text-gray-500">Pengalaman</div>{{ detailData.years_experience || 0 }} tahun</div>
            <div><div class="text-xs text-gray-500">Expected Salary</div>{{ fmtCurrency(detailData.expected_salary) }}</div>
            <div><div class="text-xs text-gray-500">Sumber</div>{{ detailData.source || '-' }}</div>
            <div v-if="detailData.available_from"><div class="text-xs text-gray-500">Available From</div>{{ fmtDate(detailData.available_from) }}</div>
          </div>

          <div>
            <h3 class="font-semibold mb-2 text-sm">Riwayat Stage</h3>
            <div class="space-y-1">
              <div v-for="s in detailData.stages" :key="s.id" class="flex items-center justify-between text-xs border-l-2 pl-3 py-1"
                :class="s.result === 'PASSED' ? 'border-emerald-500' : s.result === 'FAILED' ? 'border-red-500' : 'border-gray-300'">
                <div>
                  <span class="font-medium">{{ s.stage }}</span>
                  <span class="text-gray-500"> · {{ s.result }}</span>
                </div>
                <div class="text-gray-500">
                  {{ s.completed_at ? fmtDate(s.completed_at) : (s.scheduled_at ? 'Dijadwalkan: ' + fmtDate(s.scheduled_at) : 'Pending') }}
                </div>
              </div>
            </div>
          </div>

          <div v-if="canManage && ! TERMINAL.includes(detailData.current_stage)">
            <h3 class="font-semibold mb-2 text-sm">Aksi</h3>
            <div class="flex flex-wrap gap-2">
              <button v-for="s in STAGES" :key="s.code"
                v-show="s.code !== detailData.current_stage"
                class="text-xs px-3 py-1 rounded border border-gray-300 hover:bg-gray-50"
                @click="moveStage(detailData, s.code)">
                {{ s.label }}
              </button>
              <button class="text-xs px-3 py-1 rounded border border-red-300 text-red-600 hover:bg-red-50"
                @click="openRejectModal(detailData)">
                ❌ Tolak
              </button>
              <button v-if="canHire && detailData.current_stage === 'OFFER'"
                class="text-xs px-3 py-1 rounded bg-emerald-600 text-white hover:bg-emerald-700"
                @click="openHireModal(detailData)">
                ✅ Hire (Convert ke Employee)
              </button>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button v-if="canManage && ! detailData.converted_employee_id"
            class="btn-secondary text-red-600" @click="async () => { await deleteCandidate(detailData); showCandDetail = null }">
            Hapus
          </button>
          <button class="btn-secondary" @click="showCandDetail = null">Tutup</button>
        </div>
      </div>
    </div>

    <!-- Reject modal -->
    <div v-if="showRejectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Tolak Kandidat: {{ showRejectModal.full_name }}</h2>
          <button class="text-gray-500" @click="showRejectModal = null">&times;</button>
        </div>
        <div class="p-6">
          <label class="label">Alasan penolakan *</label>
          <textarea v-model="rejectReason" class="input" rows="3"></textarea>
          <p class="text-xs text-gray-500 mt-1">Min 5 karakter. Tercatat di history stages.</p>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showRejectModal = null">Batal</button>
          <button class="btn-danger" @click="doReject">Tolak</button>
        </div>
      </div>
    </div>

    <!-- Hire modal -->
    <div v-if="showHireModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-3 border-b flex items-center justify-between">
          <h2 class="font-semibold">Hire {{ showHireModal.full_name }}</h2>
          <button class="text-gray-500" @click="showHireModal = null">&times;</button>
        </div>
        <div class="p-6 space-y-3">
          <p class="text-sm text-gray-600">
            Kandidat akan di-convert ke <strong>employee</strong> dengan auto-generate employee_number.
            Onboarding tasks (12 task default) otomatis dibuat & assigned ke HR/Supervisor/IT/Karyawan.
          </p>
          <div>
            <label class="label">Gaji Pokok (Rp) *</label>
            <input type="number" v-model="hireForm.basic_salary" class="input" />
          </div>
          <div>
            <label class="label">Tanggal Bergabung *</label>
            <input type="date" v-model="hireForm.join_date" class="input" />
            <p class="text-xs text-gray-500 mt-1">Anchor untuk due date task onboarding.</p>
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showHireModal = null">Batal</button>
          <button class="btn-primary" @click="doHire">
            <CheckBadgeIcon class="w-4 h-4" /> Konfirmasi Hire
          </button>
        </div>
      </div>
    </div>
  </div>
</template>