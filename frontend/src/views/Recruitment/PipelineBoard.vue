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
  StarIcon, TrashIcon, ClipboardDocumentIcon, CheckIcon, KeyIcon,
  DocumentIcon, EyeIcon, ArrowDownTrayIcon, PhotoIcon, DocumentTextIcon,
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

// Credential modal — tampil sekali setelah hire berhasil bila auto-create
// user akun untuk karyawan baru. HR wajib catat / kirim ke karyawan; setelah
// modal ditutup credential hilang dari client (tidak disimpan).
const credentialResult = ref(null)
const copiedField = ref(null)
async function copyToClipboard(text, field) {
  try {
    await navigator.clipboard.writeText(text)
    copiedField.value = field
    setTimeout(() => { if (copiedField.value === field) copiedField.value = null }, 1500)
  } catch (e) {
    toast.error('Gagal copy ke clipboard')
  }
}
function copyAllCredentials() {
  if (! credentialResult.value) return
  const r = credentialResult.value
  const text = `Akun HRIS — ${r.employee_name}\nEmail: ${r.user_email}\nPassword sementara: ${r.temp_password}\nLink login: ${window.location.origin}/login\n(Wajib ganti password saat login pertama)`
  copyToClipboard(text, 'all')
}

const STAGES = [
  { code: 'NEW',            label: '🆕 Baru',         color: 'bg-gray-100' },
  { code: 'SCREENING',      label: '📋 Screening',    color: 'bg-blue-50' },
  { code: 'ONLINE_TEST',    label: '📝 Tes Online',    color: 'bg-cyan-50' },
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
  // Distribusikan ke kolom sesuai current_stage. REJECTED & WITHDRAWN
  // otomatis terpisah karena bukan key valid di map (lihat counter
  // rejectedCount untuk badge "X ditolak"). HIRED tetap muncul di
  // kolomnya supaya HR bisa lihat siapa saja yang sudah jadi karyawan.
  candidates.value.forEach(c => {
    if (map[c.current_stage]) map[c.current_stage].push(c)
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
    const candidateName = showHireModal.value.full_name
    // Move to HIRED dulu kalau belum
    if (showHireModal.value.current_stage !== 'HIRED') {
      await api.post(`/candidates/${showHireModal.value.id}/move-stage`, { stage: 'HIRED' })
    }
    const res = await api.post(`/candidates/${showHireModal.value.id}/hire`, hireForm)
    const data = res.data.data || {}
    showHireModal.value = null
    // Kalau auto-create user account berhasil, tampilkan credential modal
    // (one-time view) sebelum data hilang dari memori.
    if (data.user_id && data.temp_password) {
      credentialResult.value = {
        employee_id: data.employee_id,
        employee_name: candidateName,
        user_id: data.user_id,
        user_email: data.user_email,
        temp_password: data.temp_password,
        auto_completed_tasks: data.auto_completed_tasks || 0,
      }
    } else {
      toast.success(`Kandidat di-hire sebagai employee #${data.employee_id}. Onboarding tasks otomatis dibuat.`)
    }
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

// Gabungan dokumen: prioritas `documents` dari candidate_documents,
// fallback ke `legacy_documents` (cv_path/photo_path/dst di candidates).
// Backend sudah include signed URL untuk semua-nya.
const allDocuments = computed(() => {
  if (! detailData.value) return []
  const docs = []
  for (const d of detailData.value.documents || []) {
    docs.push({
      type: d.document_type || 'OTHER',
      label: docTypeLabel(d.document_type),
      file_name: d.file_name || d.file_path?.split('/').pop() || 'document',
      file_path: d.file_path,
      view_url: d.view_url,
      mime_hint: guessMimeFromPath(d.file_path || ''),
    })
  }
  // Hanya tambahkan legacy kalau tipe-nya belum ada di documents (avoid duplicate)
  const seenTypes = new Set(docs.map(d => d.type))
  for (const d of detailData.value.legacy_documents || []) {
    if (seenTypes.has(d.document_type)) continue
    docs.push({
      type: d.document_type,
      label: d.label || docTypeLabel(d.document_type),
      file_name: d.file_name || 'document',
      file_path: d.file_path,
      view_url: d.view_url,
      mime_hint: guessMimeFromPath(d.file_path || ''),
    })
  }
  return docs
})

function docTypeLabel(type) {
  const map = {
    CV: 'CV / Resume',
    KTP: 'KTP',
    IJAZAH: 'Ijazah',
    TRANSKRIP: 'Transkrip Nilai',
    SERTIFIKAT: 'Sertifikat',
    PORTFOLIO: 'Portfolio',
    OTHER: 'Dokumen Lain',
  }
  return map[type] || type
}
function guessMimeFromPath(path) {
  const ext = (path.split('.').pop() || '').toLowerCase()
  if (['jpg','jpeg','png','webp','gif'].includes(ext)) return 'image'
  if (ext === 'pdf') return 'pdf'
  if (['doc','docx'].includes(ext)) return 'doc'
  return 'other'
}
function downloadDoc(doc) {
  if (! doc.view_url) {
    toast.error('URL tidak tersedia. Coba refresh halaman.')
    return
  }
  // Force-download via temporary <a download>. Browser akan tetap bisa
  // pakai signed URL yang sama untuk PDF/image preview.
  const a = document.createElement('a')
  a.href = doc.view_url
  a.download = doc.file_name || 'document'
  a.target = '_blank'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
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
    </div>

    <!-- Kanban board -->
    <div v-if="loading" class="text-center py-12 text-gray-400">Memuat…</div>
    <div v-else class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-7 gap-3">
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

          <!-- Dokumen pelamar (CV, KTP, ijazah, foto, dst) -->
          <div v-if="allDocuments.length > 0">
            <h3 class="font-semibold mb-2 text-sm flex items-center gap-1.5">
              <DocumentIcon class="w-4 h-4" />
              Dokumen Pelamar ({{ allDocuments.length }})
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <div v-for="(doc, idx) in allDocuments" :key="idx"
                class="flex items-center gap-2 border border-gray-200 rounded-md p-2 hover:bg-gray-50">
                <div class="w-9 h-9 rounded bg-blue-50 flex items-center justify-center flex-shrink-0">
                  <PhotoIcon v-if="doc.mime_hint === 'image'" class="w-5 h-5 text-blue-600" />
                  <DocumentTextIcon v-else class="w-5 h-5 text-blue-600" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-xs font-medium text-gray-700 truncate" :title="doc.file_name">
                    {{ doc.label }}
                  </div>
                  <div class="text-[11px] text-gray-500 truncate" :title="doc.file_name">
                    {{ doc.file_name }}
                  </div>
                </div>
                <div class="flex items-center gap-1 flex-shrink-0">
                  <a v-if="doc.view_url" :href="doc.view_url" target="_blank" rel="noopener"
                    class="p-1.5 text-gray-500 hover:text-primary-600 hover:bg-blue-50 rounded"
                    title="Buka di tab baru">
                    <EyeIcon class="w-4 h-4" />
                  </a>
                  <button v-if="doc.view_url" type="button" @click="downloadDoc(doc)"
                    class="p-1.5 text-gray-500 hover:text-primary-600 hover:bg-blue-50 rounded"
                    title="Unduh file">
                    <ArrowDownTrayIcon class="w-4 h-4" />
                  </button>
                </div>
              </div>
            </div>
            <p class="text-[11px] text-gray-400 mt-1.5">
              Link berlaku 5 menit untuk keamanan; tutup-buka modal untuk refresh.
            </p>
          </div>

          <!-- Cover letter & extra answers (kalau via Career Portal) -->
          <div v-if="detailData.cover_letter">
            <h3 class="font-semibold mb-2 text-sm">Cover Letter</h3>
            <div class="text-sm text-gray-700 bg-gray-50 rounded p-3 whitespace-pre-line border border-gray-200">
              {{ detailData.cover_letter }}
            </div>
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

    <!-- Credential one-time view modal (setelah hire) -->
    <div v-if="credentialResult" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-6 py-3 border-b flex items-center justify-between bg-emerald-50">
          <h2 class="font-semibold text-emerald-800 flex items-center gap-2">
            <CheckBadgeIcon class="w-5 h-5" /> Hire berhasil — Credential HRIS
          </h2>
        </div>
        <div class="p-6 space-y-4">
          <div class="bg-emerald-50 border border-emerald-200 rounded p-3 text-sm text-emerald-900">
            <div class="flex items-start gap-2">
              <CheckBadgeIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
              <div>
                <strong>Email otomatis dikirim ke <span class="font-mono">{{ credentialResult.user_email }}</span>.</strong>
                Karyawan akan menerima credential lengkap dengan link login langsung di kotak masuk email.
              </div>
            </div>
          </div>

          <div class="bg-amber-50 border border-amber-200 rounded p-3 text-sm text-amber-900">
            <div class="flex items-start gap-2">
              <KeyIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
              <div>
                <strong>Backup credential:</strong> kalau email tidak sampai (mis. masuk spam atau karyawan belum cek),
                Anda juga bisa berikan password sementara di bawah secara manual.
                Password ini <strong>hanya tampil sekali</strong>. Kalau hilang sebelum karyawan login, reset password manual via Admin → Users.
              </div>
            </div>
          </div>

          <div>
            <div class="text-xs text-gray-500 mb-1">Karyawan</div>
            <div class="font-medium">{{ credentialResult.employee_name }} (Employee #{{ credentialResult.employee_id }})</div>
          </div>

          <div>
            <div class="text-xs text-gray-500 mb-1">Email login</div>
            <div class="flex items-center gap-2 bg-gray-50 rounded border border-gray-200 px-3 py-2 font-mono text-sm">
              <span class="flex-1 break-all">{{ credentialResult.user_email }}</span>
              <button class="text-gray-500 hover:text-primary-600 flex-shrink-0"
                :title="copiedField === 'email' ? 'Tersalin!' : 'Salin email'"
                @click="copyToClipboard(credentialResult.user_email, 'email')">
                <CheckIcon v-if="copiedField === 'email'" class="w-4 h-4 text-emerald-600" />
                <ClipboardDocumentIcon v-else class="w-4 h-4" />
              </button>
            </div>
          </div>

          <div>
            <div class="text-xs text-gray-500 mb-1">Password sementara</div>
            <div class="flex items-center gap-2 bg-gray-50 rounded border border-gray-200 px-3 py-2 font-mono text-sm">
              <span class="flex-1 break-all tracking-wider select-all">{{ credentialResult.temp_password }}</span>
              <button class="text-gray-500 hover:text-primary-600 flex-shrink-0"
                :title="copiedField === 'password' ? 'Tersalin!' : 'Salin password'"
                @click="copyToClipboard(credentialResult.temp_password, 'password')">
                <CheckIcon v-if="copiedField === 'password'" class="w-4 h-4 text-emerald-600" />
                <ClipboardDocumentIcon v-else class="w-4 h-4" />
              </button>
            </div>
            <p class="text-xs text-gray-500 mt-1">Karyawan wajib ganti password saat login pertama (must_change_password=1).</p>
          </div>

          <div v-if="credentialResult.auto_completed_tasks > 0"
            class="text-sm bg-blue-50 border border-blue-200 rounded p-3 text-blue-900">
            <strong>{{ credentialResult.auto_completed_tasks }} onboarding task auto-completed</strong>
            karena datanya sudah dikumpulkan saat lamaran (dokumen, NPWP, BPJS, akun HRIS).
            Cek HR → Onboarding untuk lihat task yang masih perlu dikerjakan manual (kontrak, induksi, dst).
          </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-between gap-2 bg-gray-50">
          <button class="text-sm text-gray-600 hover:text-primary-600 flex items-center gap-1"
            @click="copyAllCredentials">
            <CheckIcon v-if="copiedField === 'all'" class="w-4 h-4 text-emerald-600" />
            <ClipboardDocumentIcon v-else class="w-4 h-4" />
            {{ copiedField === 'all' ? 'Tersalin!' : 'Salin semua' }}
          </button>
          <button class="btn-primary" @click="credentialResult = null">
            Saya sudah catat — Tutup
          </button>
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