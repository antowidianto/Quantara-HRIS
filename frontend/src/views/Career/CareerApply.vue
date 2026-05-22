<script setup>
/*
 * CareerApply — form lamaran pelamar (public, no auth).
 *
 * UX improvements:
 *   - Auto-save draft form ke localStorage per (slug,jobSlug). Pelamar
 *     bisa close tab dan kembali tanpa kehilangan data. Hari-hari
 *     mengisi 4 step bisa di-resume.
 *   - "Pulihkan draft?" prompt saat masuk lagi (kalau ada draft <7 hari).
 *   - Drag-and-drop file upload + progress visual.
 *   - Validasi ukuran file di FE (5 MB) sebelum upload — feedback instan.
 *   - Validasi field via `errors` reactive, scroll-to-error otomatis.
 *   - Toast ganti `alert()` (lebih halus + tidak block UI).
 *   - Top-bar progress per step (X dari 4).
 *   - Submit confirmation: konfirmasi sebelum kirim, tampilkan ringkasan.
 *   - Hapus draft setelah submit sukses.
 *   - Brand inject ke layout.
 */
import { ref, reactive, computed, onMounted, watch, inject, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import careerApi from '@/api/careerPublic'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const slug = computed(() => route.params.slug)
const jobSlug = computed(() => route.params.jobSlug)
const careerBrand = inject('careerBrand', null)

const loading = ref(true)
const submitting = ref(false)
const error = ref(null)
const company = ref(null)
const job = ref(null)
const extraQuestions = ref([])

// ─── Constants ──────────────────────────────────────────────────
const MAX_FILE_SIZE = 5 * 1024 * 1024 // 5 MB
const RELIGIONS = ['ISLAM','KRISTEN','KATOLIK','HINDU','BUDHA','KONGHUCU','LAINNYA']
const MARITAL = [
  ['BELUM_KAWIN', 'Belum Kawin'],
  ['KAWIN',       'Kawin'],
  ['CERAI_HIDUP', 'Cerai Hidup'],
  ['CERAI_MATI',  'Cerai Mati'],
]
const EDU_LEVELS = ['SMA','SMK','D1','D2','D3','D4','S1','S2','S3']
const GENDERS = [['M','Laki-laki'], ['F','Perempuan']]

const STEPS = [
  { key: 1, label: 'Profil Diri',     short: 'Profil' },
  { key: 2, label: 'Identitas',       short: 'KTP/NPWP' },
  { key: 3, label: 'Pendidikan',      short: 'Edu/Karir' },
  { key: 4, label: 'Dokumen & Kirim', short: 'Dokumen' },
]

// ─── Form state ─────────────────────────────────────────────────
const form = reactive({
  full_name: '', email: '', phone: '',
  birth_place: '', birth_date: '', gender: '',
  nationality: 'WNI', marital_status: '', religion: '',
  nik: '', npwp: '', kk_number: '',
  bpjs_kesehatan_no: '', bpjs_tk_no: '',
  address: '', city: '', province: '',
  last_education_level: '', last_education_institution: '',
  last_education_major: '', last_education_year: '', gpa: '',
  current_position: '', current_company: '',
  years_experience: '', expected_salary: '', available_from: '',
  linkedin_url: '', portfolio_url: '',
  cv_path: '', cv_name: '',
  ktp_path: '', ktp_name: '',
  ijazah_path: '', ijazah_name: '',
  photo_path: '', photo_name: '',
  cover_letter: '',
  extra_answers: {},
  consent: false,
})

const step = ref(1)
const errors = ref({})
const uploading = reactive({ cv: false, ktp: false, ijazah: false, photo: false })
const dragOver = reactive({ cv: false, ktp: false, ijazah: false, photo: false })
const showConfirmModal = ref(false)
const showRestoreBanner = ref(false)
const draftSavedAt = ref(null)

// ─── Draft persistence (localStorage) ───────────────────────────
function draftKey() {
  return `career_draft_${slug.value}_${jobSlug.value}`
}

function saveDraft() {
  try {
    const data = { ...form, _saved_at: Date.now(), _step: step.value }
    // Jangan simpan consent — itu harus eksplisit setiap kali
    delete data.consent
    localStorage.setItem(draftKey(), JSON.stringify(data))
    draftSavedAt.value = data._saved_at
  } catch (_) { /* quota / private mode — abaikan */ }
}

function loadDraft() {
  try {
    const raw = localStorage.getItem(draftKey())
    if (! raw) return null
    const parsed = JSON.parse(raw)
    const ageMs = Date.now() - (parsed._saved_at || 0)
    if (ageMs > 7 * 24 * 60 * 60 * 1000) {
      // Lebih dari 7 hari, anggap basi
      localStorage.removeItem(draftKey())
      return null
    }
    return parsed
  } catch (_) { return null }
}

function applyDraft(draft) {
  Object.keys(form).forEach(k => {
    if (k in draft && draft[k] !== undefined && k !== 'consent') {
      form[k] = draft[k]
    }
  })
  if (draft._step >= 1 && draft._step <= 4) step.value = draft._step
  draftSavedAt.value = draft._saved_at
  toast.success('Draft sebelumnya dipulihkan')
}

function clearDraft() {
  try { localStorage.removeItem(draftKey()) } catch (_) {}
  draftSavedAt.value = null
}

// Auto-save: debounce 500ms ketika form berubah
let saveTimer = null
watch(form, () => {
  if (loading.value) return
  if (saveTimer) clearTimeout(saveTimer)
  saveTimer = setTimeout(saveDraft, 500)
}, { deep: true })

watch(step, () => { if (! loading.value) saveDraft() })

// ─── Fetch ──────────────────────────────────────────────────────
async function fetchJob() {
  loading.value = true
  error.value = null
  try {
    const res = await careerApi.get(`/careers/${slug.value}/jobs/${jobSlug.value}`)
    const data = res.data.data
    company.value = data.company
    job.value = data.job
    extraQuestions.value = data.extra_questions || []
    const brandColor = company.value?.brand_color || '#0F62FE'
    document.documentElement.style.setProperty('--brand-color', brandColor)
    if (careerBrand) {
      careerBrand.value = {
        name:     company.value.name,
        logo_url: company.value.logo_url || '',
        color:    brandColor,
      }
    }
    document.title = `Lamar — ${job.value.title}`

    // Default available_from H+14
    if (! form.available_from) {
      const d = new Date(); d.setDate(d.getDate() + 14)
      form.available_from = d.toISOString().slice(0, 10)
    }

    // Cek draft
    const draft = loadDraft()
    if (draft && (draft.full_name || draft.email)) {
      showRestoreBanner.value = true
      // Sembunyikan banner setelah 30 detik kalau user tidak respon
      setTimeout(() => { showRestoreBanner.value = false }, 30000)
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Lowongan tidak ditemukan atau sudah ditutup'
  } finally {
    loading.value = false
  }
}

onMounted(fetchJob)
watch(jobSlug, fetchJob)

function restoreDraftAction() {
  const draft = loadDraft()
  if (draft) applyDraft(draft)
  showRestoreBanner.value = false
}

function discardDraftAction() {
  clearDraft()
  showRestoreBanner.value = false
}

// ─── File upload ────────────────────────────────────────────────
async function uploadFile(kind, file) {
  if (! file) return
  // Validasi ukuran sebelum upload — feedback instan, hemat bandwidth
  if (file.size > MAX_FILE_SIZE) {
    errors.value[`${kind}_path`] = `Ukuran file maks ${MAX_FILE_SIZE / 1024 / 1024} MB. File Anda ${(file.size / 1024 / 1024).toFixed(1)} MB.`
    return
  }
  delete errors.value[`${kind}_path`]
  uploading[kind] = true
  try {
    const fd = new FormData()
    fd.append('file', file)
    const res = await careerApi.post(`/careers/upload?kind=${kind}`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    const data = res.data.data
    form[`${kind}_path`] = data.path
    form[`${kind}_name`] = data.original_name || file.name
    toast.success(`${file.name} berhasil diunggah`)
  } catch (e) {
    const msg = e.response?.data?.message || 'Gagal mengunggah file. Coba lagi.'
    errors.value[`${kind}_path`] = msg
    toast.error(msg)
  } finally {
    uploading[kind] = false
  }
}

function handleFile(kind, ev) {
  const file = ev.target.files?.[0]
  if (file) uploadFile(kind, file)
}

function handleDrop(kind, ev) {
  ev.preventDefault()
  dragOver[kind] = false
  const file = ev.dataTransfer?.files?.[0]
  if (file) uploadFile(kind, file)
}

function clearFile(kind) {
  form[`${kind}_path`] = ''
  form[`${kind}_name`] = ''
  delete errors.value[`${kind}_path`]
}

// ─── Validation ─────────────────────────────────────────────────
function validateStep(s) {
  errors.value = {}
  if (s === 1) {
    if (! form.full_name.trim()) errors.value.full_name = 'Nama lengkap wajib diisi'
    if (! form.email.trim()) errors.value.email = 'Email wajib diisi'
    else if (! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(form.email)) errors.value.email = 'Format email tidak valid'
    if (! form.phone.trim()) errors.value.phone = 'Nomor HP wajib diisi'
    else if (! /^(\+?62|0)[\d\s-]{8,15}$/.test(form.phone.replace(/\s/g, ''))) {
      errors.value.phone = 'Format HP tidak valid (contoh: 0812-3456-7890 atau +6281234567890)'
    }
    if (! form.birth_date) errors.value.birth_date = 'Tanggal lahir wajib diisi'
    else {
      const age = (Date.now() - new Date(form.birth_date).getTime()) / (1000 * 60 * 60 * 24 * 365.25)
      if (age < 14 || age > 80) errors.value.birth_date = 'Tanggal lahir tidak valid'
    }
    if (! form.gender) errors.value.gender = 'Jenis kelamin wajib diisi'
  }
  if (s === 2) {
    if (form.nik && ! /^\d{16}$/.test(form.nik.replace(/\D/g, ''))) {
      errors.value.nik = 'NIK harus 16 digit angka'
    }
    if (form.kk_number && ! /^\d{16}$/.test(form.kk_number.replace(/\D/g, ''))) {
      errors.value.kk_number = 'No KK harus 16 digit angka'
    }
    if (! form.address.trim()) errors.value.address = 'Alamat wajib diisi'
    if (! form.city.trim()) errors.value.city = 'Kota wajib diisi'
  }
  if (s === 3) {
    if (! form.last_education_level) errors.value.last_education_level = 'Pendidikan terakhir wajib diisi'
    if (form.gpa !== '' && form.gpa !== null) {
      const g = parseFloat(form.gpa)
      if (Number.isNaN(g) || g < 0 || g > 4) errors.value.gpa = 'IPK 0.00 – 4.00'
    }
    if (job.value?.min_education && form.last_education_level) {
      const order = EDU_LEVELS.indexOf(form.last_education_level)
      const minOrder = EDU_LEVELS.indexOf(job.value.min_education)
      if (order >= 0 && minOrder >= 0 && order < minOrder) {
        errors.value.last_education_level = `Posisi ini butuh minimal: ${job.value.min_education}`
      }
    }
    if (form.last_education_year) {
      const y = parseInt(form.last_education_year, 10)
      const thisYear = new Date().getFullYear()
      if (y < 1980 || y > thisYear + 1) {
        errors.value.last_education_year = `Tahun harus antara 1980 – ${thisYear + 1}`
      }
    }
  }
  if (s === 4) {
    if (! form.cv_path) errors.value.cv_path = 'CV wajib diunggah'
    for (const q of extraQuestions.value) {
      if (q.required && ! form.extra_answers[q.key]) {
        errors.value[`xq_${q.key}`] = `${q.label} wajib diisi`
      }
    }
    if (! form.consent) errors.value.consent = 'Anda harus menyetujui penggunaan data'
  }
  return Object.keys(errors.value).length === 0
}

async function scrollToFirstError() {
  await nextTick()
  const el = document.querySelector('.cp-error-mark')
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

function nextStep() {
  if (validateStep(step.value)) {
    step.value++
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } else {
    scrollToFirstError()
    toast.warning('Lengkapi field yang wajib diisi')
  }
}

function prevStep() {
  step.value = Math.max(1, step.value - 1)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function goToStep(target) {
  if (target <= step.value) {
    step.value = target
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

// ─── Submit ─────────────────────────────────────────────────────
function openConfirm() {
  if (validateStep(4)) {
    showConfirmModal.value = true
  } else {
    scrollToFirstError()
    toast.warning('Lengkapi field yang wajib diisi')
  }
}

async function submit() {
  showConfirmModal.value = false
  submitting.value = true
  try {
    const payload = { ...form }
    delete payload.cv_name
    delete payload.ktp_name
    delete payload.ijazah_name
    delete payload.photo_name
    delete payload.consent
    const res = await careerApi.post(
      `/careers/${slug.value}/jobs/${jobSlug.value}/apply`,
      payload,
    )
    const data = res.data.data
    sessionStorage.setItem('career_track_token', data.token)
    clearDraft() // submit sukses → buang draft
    router.push(`/careers/${slug.value}/success?ref=${encodeURIComponent(data.job?.reference_number || '')}`)
  } catch (e) {
    const msg = e.response?.data?.message || 'Gagal mengirim lamaran. Coba lagi.'
    toast.error(msg, { timeout: 6000 })
  } finally {
    submitting.value = false
  }
}

function fmtSize(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
</script>

<template>
  <!-- ── Loading ──────────────────────────────────────────────── -->
  <div v-if="loading" class="max-w-3xl mx-auto px-4 py-16 text-center text-slate-400">
    <div class="animate-spin inline-block w-6 h-6 border-2 border-current border-t-transparent rounded-full"></div>
    <div class="mt-3 text-sm">Memuat formulir...</div>
  </div>

  <!-- ── Error ────────────────────────────────────────────────── -->
  <div v-else-if="error" class="max-w-3xl mx-auto px-4 py-16 text-center">
    <div class="inline-flex flex-col items-center gap-3 bg-red-50 border border-red-200 text-red-700 rounded-lg px-6 py-5 max-w-md">
      <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
      </svg>
      <div class="font-semibold">{{ error }}</div>
    </div>
  </div>

  <!-- ── Form ─────────────────────────────────────────────────── -->
  <template v-else>
    <div class="max-w-3xl mx-auto px-4 py-6 sm:py-8">
      <!-- Restore draft banner -->
      <div
        v-if="showRestoreBanner"
        class="mb-4 bg-blue-50 border border-blue-200 rounded-lg p-3 sm:p-4 flex items-start gap-3"
      >
        <span class="text-2xl shrink-0" aria-hidden="true">📝</span>
        <div class="flex-1">
          <div class="font-medium text-blue-900 text-sm">Anda punya draft tersimpan</div>
          <p class="text-xs text-blue-800 mt-0.5">
            Kami menyimpan otomatis form yang Anda isi di kunjungan sebelumnya. Lanjutkan dari sebelumnya?
          </p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button
              @click="restoreDraftAction"
              class="text-xs px-3 py-1.5 rounded-md bg-blue-600 text-white hover:bg-blue-700"
            >Ya, pulihkan</button>
            <button
              @click="discardDraftAction"
              class="text-xs px-3 py-1.5 rounded-md border border-blue-300 text-blue-700 hover:bg-blue-100"
            >Mulai dari awal</button>
          </div>
        </div>
        <button
          @click="showRestoreBanner = false"
          class="text-blue-400 hover:text-blue-600 shrink-0"
          aria-label="Tutup"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Header -->
      <div class="mb-5">
        <router-link :to="`/careers/${slug}/jobs/${jobSlug}`" class="text-sm text-slate-500 hover:underline inline-flex items-center gap-1">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
          </svg>
          Kembali ke detail
        </router-link>
        <h1 class="text-xl sm:text-2xl font-bold mt-2 text-slate-900">Lamar: {{ job.title }}</h1>
        <div class="text-sm text-slate-500">{{ company.name }} · <span class="font-mono">{{ job.reference_number }}</span></div>
      </div>

      <!-- Stepper -->
      <div class="bg-white rounded-xl border border-slate-200 p-3 sm:p-4 mb-5 sticky top-14 z-20">
        <!-- Progress text mobile -->
        <div class="sm:hidden text-xs text-slate-600 mb-2 flex items-center justify-between">
          <span>Langkah {{ step }} dari {{ STEPS.length }}</span>
          <span class="font-medium">{{ STEPS[step - 1]?.label }}</span>
        </div>
        <!-- Progress bar -->
        <div class="sm:hidden h-1.5 bg-slate-100 rounded-full overflow-hidden">
          <div
            class="h-full bg-[var(--brand-color,#0F62FE)] transition-all"
            :style="`width: ${(step / STEPS.length) * 100}%`"
          ></div>
        </div>
        <!-- Desktop stepper -->
        <div class="hidden sm:flex items-center justify-between gap-2">
          <button
            v-for="(s, idx) in STEPS"
            :key="s.key"
            type="button"
            @click="goToStep(s.key)"
            :disabled="s.key > step"
            class="flex items-center flex-1 disabled:cursor-not-allowed group"
          >
            <div :class="[
              'w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium shrink-0 transition-colors',
              step >= s.key ? 'bg-[var(--brand-color,#0F62FE)] text-white' : 'bg-slate-200 text-slate-500',
              s.key < step ? 'group-hover:opacity-80 cursor-pointer' : ''
            ]">
              <svg v-if="step > s.key" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
              </svg>
              <span v-else>{{ s.key }}</span>
            </div>
            <div class="text-xs ml-2 truncate text-left" :class="step >= s.key ? 'text-slate-900 font-medium' : 'text-slate-500'">{{ s.label }}</div>
            <div v-if="idx < STEPS.length - 1" class="flex-1 h-0.5 mx-2" :class="step > s.key ? 'bg-[var(--brand-color,#0F62FE)]' : 'bg-slate-200'"></div>
          </button>
        </div>
      </div>

      <!-- Form steps -->
      <div class="bg-white rounded-xl border border-slate-200 p-5 sm:p-6">
        <!-- STEP 1 ─────────────────────────────────────────── -->
        <section v-if="step === 1" class="space-y-4">
          <header>
            <h2 class="text-lg font-bold">Profil Diri</h2>
            <p class="text-sm text-slate-500 mt-0.5">Data dasar untuk kami menghubungi Anda.</p>
          </header>
          <div>
            <label class="cp-label">Nama Lengkap <span class="text-red-500">*</span></label>
            <input v-model="form.full_name" type="text" class="cp-input" :class="errors.full_name && 'cp-error-mark'" placeholder="Sesuai KTP" autocomplete="name" />
            <p v-if="errors.full_name" class="cp-err">{{ errors.full_name }}</p>
          </div>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="cp-label">Email <span class="text-red-500">*</span></label>
              <input v-model="form.email" type="email" class="cp-input" :class="errors.email && 'cp-error-mark'" placeholder="email@contoh.com" autocomplete="email" />
              <p v-if="errors.email" class="cp-err">{{ errors.email }}</p>
            </div>
            <div>
              <label class="cp-label">Nomor HP / WhatsApp <span class="text-red-500">*</span></label>
              <input v-model="form.phone" type="tel" class="cp-input" :class="errors.phone && 'cp-error-mark'" placeholder="0812 3456 7890" autocomplete="tel" />
              <p v-if="errors.phone" class="cp-err">{{ errors.phone }}</p>
            </div>
          </div>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="cp-label">Tempat Lahir</label>
              <input v-model="form.birth_place" type="text" class="cp-input" placeholder="mis. Jakarta" />
            </div>
            <div>
              <label class="cp-label">Tanggal Lahir <span class="text-red-500">*</span></label>
              <input v-model="form.birth_date" type="date" class="cp-input" :class="errors.birth_date && 'cp-error-mark'" />
              <p v-if="errors.birth_date" class="cp-err">{{ errors.birth_date }}</p>
            </div>
          </div>
          <div class="grid sm:grid-cols-3 gap-4">
            <div>
              <label class="cp-label">Jenis Kelamin <span class="text-red-500">*</span></label>
              <select v-model="form.gender" class="cp-input" :class="errors.gender && 'cp-error-mark'">
                <option value="">— Pilih —</option>
                <option v-for="[v, l] in GENDERS" :key="v" :value="v">{{ l }}</option>
              </select>
              <p v-if="errors.gender" class="cp-err">{{ errors.gender }}</p>
            </div>
            <div>
              <label class="cp-label">Status</label>
              <select v-model="form.marital_status" class="cp-input">
                <option value="">— Pilih —</option>
                <option v-for="[v, l] in MARITAL" :key="v" :value="v">{{ l }}</option>
              </select>
            </div>
            <div>
              <label class="cp-label">Agama</label>
              <select v-model="form.religion" class="cp-input">
                <option value="">— Pilih —</option>
                <option v-for="r in RELIGIONS" :key="r" :value="r">{{ r }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="cp-label">Kewarganegaraan</label>
            <select v-model="form.nationality" class="cp-input max-w-xs">
              <option value="WNI">WNI</option>
              <option value="WNA">WNA</option>
            </select>
          </div>
        </section>

        <!-- STEP 2 ─────────────────────────────────────────── -->
        <section v-if="step === 2" class="space-y-4">
          <header>
            <h2 class="text-lg font-bold">Identitas (KTP, NPWP, BPJS)</h2>
            <p class="text-sm text-slate-500 mt-0.5">
              Boleh kosong di tahap lamaran — akan dilengkapi saat onboarding bila Anda diterima.
            </p>
          </header>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="cp-label">NIK / Nomor KTP</label>
              <input v-model="form.nik" type="text" class="cp-input" :class="errors.nik && 'cp-error-mark'" placeholder="16 digit" maxlength="20" inputmode="numeric" />
              <p v-if="errors.nik" class="cp-err">{{ errors.nik }}</p>
            </div>
            <div>
              <label class="cp-label">NPWP</label>
              <input v-model="form.npwp" type="text" class="cp-input" placeholder="15-16 digit" />
            </div>
            <div>
              <label class="cp-label">No KK</label>
              <input v-model="form.kk_number" type="text" class="cp-input" :class="errors.kk_number && 'cp-error-mark'" placeholder="16 digit" maxlength="20" inputmode="numeric" />
              <p v-if="errors.kk_number" class="cp-err">{{ errors.kk_number }}</p>
            </div>
            <div>
              <label class="cp-label">No BPJS Kesehatan</label>
              <input v-model="form.bpjs_kesehatan_no" type="text" class="cp-input" placeholder="Opsional" />
            </div>
            <div>
              <label class="cp-label">No BPJS Ketenagakerjaan</label>
              <input v-model="form.bpjs_tk_no" type="text" class="cp-input" placeholder="Opsional" />
            </div>
          </div>
          <div>
            <label class="cp-label">Alamat Domisili <span class="text-red-500">*</span></label>
            <textarea v-model="form.address" rows="3" class="cp-input" :class="errors.address && 'cp-error-mark'" placeholder="Jalan, RT/RW, Kelurahan, Kecamatan"></textarea>
            <p v-if="errors.address" class="cp-err">{{ errors.address }}</p>
          </div>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="cp-label">Kota <span class="text-red-500">*</span></label>
              <input v-model="form.city" type="text" class="cp-input" :class="errors.city && 'cp-error-mark'" placeholder="mis. Jakarta Selatan" />
              <p v-if="errors.city" class="cp-err">{{ errors.city }}</p>
            </div>
            <div>
              <label class="cp-label">Provinsi</label>
              <input v-model="form.province" type="text" class="cp-input" placeholder="mis. DKI Jakarta" />
            </div>
          </div>
        </section>

        <!-- STEP 3 ─────────────────────────────────────────── -->
        <section v-if="step === 3" class="space-y-4">
          <header>
            <h2 class="text-lg font-bold">Pendidikan & Pengalaman</h2>
            <p v-if="job.min_education" class="text-sm text-slate-500 mt-0.5">
              Posisi ini butuh minimal jenjang
              <strong class="text-slate-700">{{ job.min_education }}</strong>.
            </p>
          </header>
          <div class="grid sm:grid-cols-3 gap-4">
            <div>
              <label class="cp-label">Jenjang Terakhir <span class="text-red-500">*</span></label>
              <select v-model="form.last_education_level" class="cp-input" :class="errors.last_education_level && 'cp-error-mark'">
                <option value="">— Pilih —</option>
                <option v-for="l in EDU_LEVELS" :key="l" :value="l">{{ l }}</option>
              </select>
              <p v-if="errors.last_education_level" class="cp-err">{{ errors.last_education_level }}</p>
            </div>
            <div class="sm:col-span-2">
              <label class="cp-label">Institusi</label>
              <input v-model="form.last_education_institution" type="text" class="cp-input" placeholder="mis. Universitas Indonesia" />
            </div>
          </div>
          <div class="grid sm:grid-cols-3 gap-4">
            <div class="sm:col-span-2">
              <label class="cp-label">Jurusan</label>
              <input v-model="form.last_education_major" type="text" class="cp-input" placeholder="mis. Teknik Informatika" />
            </div>
            <div>
              <label class="cp-label">Tahun Lulus</label>
              <input v-model="form.last_education_year" type="number" :min="1980" :max="new Date().getFullYear() + 1" class="cp-input" :class="errors.last_education_year && 'cp-error-mark'" placeholder="2020" />
              <p v-if="errors.last_education_year" class="cp-err">{{ errors.last_education_year }}</p>
            </div>
          </div>
          <div>
            <label class="cp-label">IPK</label>
            <input v-model="form.gpa" type="number" step="0.01" min="0" max="4" class="cp-input max-w-xs" :class="errors.gpa && 'cp-error-mark'" placeholder="0.00 – 4.00" />
            <p v-if="errors.gpa" class="cp-err">{{ errors.gpa }}</p>
          </div>

          <div class="border-t border-slate-100 pt-4 mt-4">
            <h3 class="font-semibold mb-3 text-slate-800">Pengalaman Kerja</h3>
            <div class="grid sm:grid-cols-2 gap-4">
              <div>
                <label class="cp-label">Jabatan Saat Ini</label>
                <input v-model="form.current_position" type="text" class="cp-input" placeholder="mis. Staff IT" />
              </div>
              <div>
                <label class="cp-label">Perusahaan</label>
                <input v-model="form.current_company" type="text" class="cp-input" />
              </div>
              <div>
                <label class="cp-label">Total Pengalaman (tahun)</label>
                <input v-model="form.years_experience" type="number" min="0" max="40" class="cp-input" placeholder="0 = fresh graduate" />
              </div>
              <div>
                <label class="cp-label">Ekspektasi Gaji (IDR/bulan)</label>
                <input v-model="form.expected_salary" type="number" min="0" step="100000" class="cp-input" placeholder="mis. 10000000" />
              </div>
              <div>
                <label class="cp-label">Tanggal Bisa Mulai</label>
                <input v-model="form.available_from" type="date" class="cp-input" />
              </div>
            </div>
            <div class="grid sm:grid-cols-2 gap-4 mt-4">
              <div>
                <label class="cp-label">LinkedIn</label>
                <input v-model="form.linkedin_url" type="text" class="cp-input" placeholder="linkedin.com/in/anda" />
              </div>
              <div>
                <label class="cp-label">Portfolio / Website</label>
                <input v-model="form.portfolio_url" type="text" class="cp-input" placeholder="github.com/anda" />
              </div>
            </div>
          </div>
        </section>

        <!-- STEP 4 ─────────────────────────────────────────── -->
        <section v-if="step === 4" class="space-y-4">
          <header>
            <h2 class="text-lg font-bold">Dokumen Lamaran</h2>
            <p class="text-sm text-slate-500 mt-0.5">
              PDF, JPG, PNG, atau DOC. Maks 5 MB per file. Anda bisa
              <strong>tarik & lepas</strong> file langsung ke kotak di bawah.
            </p>
          </header>

          <div
            v-for="slot in [
              { kind: 'cv',     label: 'CV / Resume',                accept: '.pdf,.doc,.docx,.jpg,.jpeg,.png', required: true,  hint: 'Maks 5 MB · PDF disarankan' },
              { kind: 'ktp',    label: 'Foto KTP',                   accept: '.pdf,.jpg,.jpeg,.png',            required: false, hint: 'Buram tidak masalah, asal angka NIK terbaca' },
              { kind: 'ijazah', label: 'Ijazah Terakhir',            accept: '.pdf,.jpg,.jpeg,.png',            required: false, hint: 'Boleh ijazah/SKL' },
              { kind: 'photo',  label: 'Pas Foto (3x4 atau 4x6)',    accept: 'image/*',                          required: false, hint: 'Foto formal, latar polos' },
            ]"
            :key="slot.kind"
            class="border border-slate-200 rounded-lg p-4"
            :class="errors[`${slot.kind}_path`] ? 'cp-error-mark border-red-300' : ''"
          >
            <div class="flex items-baseline justify-between gap-2 mb-1">
              <label class="cp-label !mb-0">
                {{ slot.label }}
                <span v-if="slot.required" class="text-red-500">*</span>
              </label>
              <span class="text-[11px] text-slate-400">{{ slot.hint }}</span>
            </div>

            <!-- Already uploaded -->
            <div v-if="form[`${slot.kind}_path`]" class="flex items-center justify-between gap-2 bg-emerald-50 border border-emerald-200 rounded-md px-3 py-2 text-sm mt-2">
              <span class="flex items-center gap-2 truncate min-w-0">
                <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="truncate">{{ form[`${slot.kind}_name`] || 'File terupload' }}</span>
              </span>
              <button type="button" @click="clearFile(slot.kind)" class="text-red-500 text-xs hover:underline shrink-0">Hapus</button>
            </div>

            <!-- Drop zone -->
            <label
              v-else
              class="block mt-2 border-2 border-dashed rounded-lg p-4 text-center cursor-pointer transition-colors"
              :class="[
                dragOver[slot.kind] ? 'border-[var(--brand-color,#0F62FE)] bg-[var(--brand-color,#0F62FE)]/5' : 'border-slate-300 hover:border-slate-400 hover:bg-slate-50',
                uploading[slot.kind] ? 'opacity-60 cursor-wait' : ''
              ]"
              @dragenter.prevent="dragOver[slot.kind] = true"
              @dragover.prevent="dragOver[slot.kind] = true"
              @dragleave.prevent="dragOver[slot.kind] = false"
              @drop="handleDrop(slot.kind, $event)"
            >
              <input
                type="file"
                :accept="slot.accept"
                :disabled="uploading[slot.kind]"
                @change="(e) => handleFile(slot.kind, e)"
                class="hidden"
              />
              <div v-if="uploading[slot.kind]" class="flex items-center justify-center gap-2 text-sm text-slate-600">
                <span class="animate-spin inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full"></span>
                Mengunggah...
              </div>
              <div v-else class="text-sm text-slate-600">
                <svg class="w-7 h-7 mx-auto text-slate-400 mb-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                </svg>
                <span class="font-medium text-[var(--brand-color,#0F62FE)]">Klik untuk pilih file</span>
                <span class="text-slate-500"> atau tarik ke sini</span>
              </div>
            </label>
            <p v-if="errors[`${slot.kind}_path`]" class="cp-err mt-2">{{ errors[`${slot.kind}_path`] }}</p>
          </div>

          <div>
            <label class="cp-label">Cover Letter (opsional)</label>
            <textarea v-model="form.cover_letter" rows="4" class="cp-input" placeholder="Ceritakan singkat mengapa Anda cocok untuk posisi ini..."></textarea>
            <p class="text-xs text-slate-400 mt-1">{{ form.cover_letter.length }} karakter</p>
          </div>

          <template v-if="extraQuestions.length">
            <div class="border-t border-slate-100 pt-4 mt-4">
              <h3 class="font-semibold mb-3 text-slate-800">Pertanyaan Tambahan</h3>
              <div v-for="q in extraQuestions" :key="q.key" class="mb-3">
                <label class="cp-label">
                  {{ q.label }}
                  <span v-if="q.required" class="text-red-500">*</span>
                </label>
                <textarea v-if="q.type === 'textarea'" v-model="form.extra_answers[q.key]" rows="3" class="cp-input" :class="errors[`xq_${q.key}`] && 'cp-error-mark'"></textarea>
                <select v-else-if="q.type === 'select'" v-model="form.extra_answers[q.key]" class="cp-input" :class="errors[`xq_${q.key}`] && 'cp-error-mark'">
                  <option value="">— Pilih —</option>
                  <option v-for="opt in q.options || []" :key="opt" :value="opt">{{ opt }}</option>
                </select>
                <input v-else :type="q.type === 'number' ? 'number' : (q.type === 'date' ? 'date' : (q.type === 'url' ? 'url' : 'text'))" v-model="form.extra_answers[q.key]" class="cp-input" :class="errors[`xq_${q.key}`] && 'cp-error-mark'" />
                <p v-if="errors[`xq_${q.key}`]" class="cp-err">{{ errors[`xq_${q.key}`] }}</p>
              </div>
            </div>
          </template>

          <div class="border-t border-slate-100 pt-4 mt-4 bg-slate-50 -mx-5 sm:-mx-6 px-5 sm:px-6 -mb-5 sm:-mb-6 pb-5 sm:pb-6 rounded-b-xl">
            <label class="flex items-start gap-3 cursor-pointer" :class="errors.consent && 'cp-error-mark'">
              <input v-model="form.consent" type="checkbox" class="mt-1 w-4 h-4 accent-[var(--brand-color,#0F62FE)]" />
              <span class="text-sm text-slate-700">
                Saya menyetujui data yang saya berikan diproses oleh
                <strong>{{ company.name }}</strong> untuk keperluan rekrutmen dan onboarding sesuai kebijakan privasi mereka.
                Data akan disimpan secara aman.
              </span>
            </label>
            <p v-if="errors.consent" class="cp-err mt-1">{{ errors.consent }}</p>
          </div>
        </section>

        <!-- Navigation -->
        <div class="flex items-center justify-between mt-6 pt-4 border-t border-slate-100">
          <button v-if="step > 1" @click="prevStep" type="button" class="px-4 py-2 rounded-md border border-slate-300 text-sm hover:bg-slate-50 inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
            </svg>
            Sebelumnya
          </button>
          <span v-else></span>
          <button v-if="step < 4" @click="nextStep" type="button" class="px-6 py-2 rounded-md bg-[var(--brand-color,#0F62FE)] text-white font-medium hover:opacity-90 transition inline-flex items-center gap-1">
            Lanjut
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
            </svg>
          </button>
          <button v-else @click="openConfirm" :disabled="submitting" type="button" class="px-6 py-2 rounded-md bg-emerald-600 text-white font-medium hover:bg-emerald-700 transition disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1">
            <span v-if="submitting" class="animate-spin inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full"></span>
            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
            {{ submitting ? 'Mengirim...' : 'Kirim Lamaran' }}
          </button>
        </div>
      </div>

      <!-- Footer hint with autosave indicator -->
      <p v-if="draftSavedAt" class="mt-4 text-center text-xs text-slate-400 inline-flex items-center justify-center gap-1 w-full">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        Draft tersimpan otomatis di perangkat Anda
      </p>
    </div>

    <!-- ── Confirm submit modal ─────────────────────────────── -->
    <div v-if="showConfirmModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showConfirmModal = false">
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
          <h3 class="font-bold">Konfirmasi Lamaran</h3>
          <button @click="showConfirmModal = false" class="text-slate-400 hover:text-slate-700">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div class="px-5 py-4 space-y-3 text-sm">
          <p class="text-slate-600">Periksa kembali sebelum mengirim:</p>
          <dl class="grid grid-cols-3 gap-y-2 gap-x-3">
            <dt class="text-slate-500">Posisi</dt>
            <dd class="col-span-2 font-medium">{{ job.title }}</dd>
            <dt class="text-slate-500">Nama</dt>
            <dd class="col-span-2 font-medium">{{ form.full_name }}</dd>
            <dt class="text-slate-500">Email</dt>
            <dd class="col-span-2 font-medium break-all">{{ form.email }}</dd>
            <dt class="text-slate-500">HP</dt>
            <dd class="col-span-2 font-medium">{{ form.phone }}</dd>
            <dt class="text-slate-500">Pendidikan</dt>
            <dd class="col-span-2 font-medium">{{ form.last_education_level }}<span v-if="form.last_education_major"> · {{ form.last_education_major }}</span></dd>
            <dt class="text-slate-500">CV</dt>
            <dd class="col-span-2 font-medium truncate">{{ form.cv_name }}</dd>
          </dl>
          <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
            Setelah Anda kirim, data tidak bisa diubah. Anda akan dapat kode pelacakan di halaman selanjutnya.
          </p>
        </div>
        <div class="px-5 py-3 border-t border-slate-200 flex justify-end gap-2">
          <button @click="showConfirmModal = false" class="px-4 py-2 rounded-md border border-slate-300 text-sm hover:bg-slate-50">Periksa lagi</button>
          <button @click="submit" :disabled="submitting" class="px-4 py-2 rounded-md bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 disabled:opacity-50">
            <span v-if="submitting">Mengirim...</span>
            <span v-else>Ya, kirim sekarang</span>
          </button>
        </div>
      </div>
    </div>
  </template>
</template>

<style scoped>
.cp-label {
  display: block;
  font-size: 0.875rem;
  font-weight: 500;
  color: rgb(51 65 85); /* slate-700 */
  margin-bottom: 0.375rem;
}
.cp-input {
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem;
  border: 1px solid rgb(203 213 225); /* slate-300 */
  border-radius: 0.375rem;
  font-size: 0.875rem;
  background: #fff;
  color: rgb(15 23 42); /* slate-900 */
  transition: border-color 0.15s, box-shadow 0.15s;
}
.cp-input::placeholder {
  color: rgb(148 163 184); /* slate-400 */
}
.cp-input:focus {
  outline: none;
  border-color: var(--brand-color, #0F62FE);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-color, #0F62FE) 20%, transparent);
}
.cp-input.cp-error-mark,
.cp-error-mark .cp-input {
  border-color: rgb(239 68 68); /* red-500 */
}
.cp-err {
  font-size: 0.75rem;
  color: rgb(220 38 38); /* red-600 */
  margin-top: 0.25rem;
}
</style>