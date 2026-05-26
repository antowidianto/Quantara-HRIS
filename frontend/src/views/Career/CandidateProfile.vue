<script setup>
/*
 * CandidateProfile — "Profil Saya" untuk pelamar career portal.
 *
 * Pelamar bisa lihat & edit data lamarannya selama belum di stage final
 * (HIRED / REJECTED / WITHDRAWN). Tujuan: kalau pelamar sadar ada
 * data yang salah/missing setelah submit, dia bisa perbaiki sendiri
 * sampai HR mulai proses interview/offer.
 *
 * URL: /careers/:slug/profile/:id   (id = candidate id)
 *
 * Source of truth ada di backend GET /careers/account/applications/{id}.
 * Ownership sudah dijamin di server side (filter candidatejwt + check
 * candidate_account_id).
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { useCandidateAuthStore, candidateApi } from '@/stores/candidateAuth'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const store = useCandidateAuthStore()
const api = candidateApi()

const slug = computed(() => route.params.slug)
const candidateId = computed(() => Number(route.params.id))

const loading = ref(true)
const saving = ref(false)
const error = ref(null)
const meta = ref({ editable: true, lock_reason: null, job: null })

// ─── Reactive form (mirror backend candidates row) ──────────────
const form = reactive({
  // Profil
  full_name: '', email: '', phone: '',
  birth_place: '', birth_date: '', gender: '',
  nationality: 'WNI', marital_status: '', religion: '',
  blood_type: '',
  // Identitas
  nik: '', npwp: '', kk_number: '',
  bpjs_kesehatan_no: '', bpjs_tk_no: '', passport_number: '',
  address: '', city: '', province: '', postal_code: '',
  // Payroll & supporting
  ptkp_status: '', dependents_count: 0,
  bank_name: '', bank_account_number: '', bank_account_holder: '',
  emergency_contact_name: '', emergency_contact_phone: '', emergency_contact_relation: '',
  spouse_name: '', spouse_occupation: '', spouse_workplace: '',
  children_count: 0,
  disability_status: 'NONE', disability_notes: '',
  health_conditions: '',
  // Pendidikan & pengalaman
  last_education_level: '', last_education_institution: '',
  last_education_major: '', last_education_year: '', gpa: '',
  current_position: '', current_company: '',
  years_experience: '', expected_salary: '', available_from: '',
  linkedin_url: '', portfolio_url: '', github_url: '',
  languages: '', professional_skills: '', certifications: '',
  driving_license: [], vehicle_owned: [],
  reference_contacts: [],
  referral_source_detail: '',
})

const errors = ref({})

// ─── Constants (match CareerApply) ───────────────────────────────
const RELIGIONS = ['ISLAM','KRISTEN','KATOLIK','HINDU','BUDHA','KONGHUCU','LAINNYA']
const MARITAL = [
  ['BELUM_KAWIN', 'Belum Kawin'],
  ['KAWIN',       'Kawin'],
  ['CERAI_HIDUP', 'Cerai Hidup'],
  ['CERAI_MATI',  'Cerai Mati'],
]
const EDU_LEVELS = ['SMA','SMK','D1','D2','D3','D4','S1','S2','S3']
const GENDERS = [['M','Laki-laki'], ['F','Perempuan']]
const RELATIONS = [
  ['ORANG_TUA', 'Orang Tua'],
  ['PASANGAN',  'Suami / Istri'],
  ['SAUDARA',   'Saudara Kandung'],
  ['ANAK',      'Anak'],
  ['LAINNYA',   'Lainnya'],
]
const BLOOD_TYPES = ['A','A+','A-','B','B+','B-','AB','AB+','AB-','O','O+','O-']
const DRIVING_LICENSES = [
  ['A',  'SIM A — Mobil pribadi'],
  ['B1', 'SIM B1 — Mobil > 3.500 kg'],
  ['B2', 'SIM B2 — Trailer / kendaraan berat'],
  ['C',  'SIM C — Sepeda motor'],
  ['D',  'SIM D — Penyandang disabilitas'],
]
const VEHICLES = [
  ['MOTOR',  'Sepeda motor'],
  ['MOBIL',  'Mobil'],
  ['SEPEDA', 'Sepeda'],
]
const DISABILITIES = [
  ['NONE',         'Tidak ada disabilitas'],
  ['PHYSICAL',     'Fisik'],
  ['SENSORY',      'Sensorik'],
  ['INTELLECTUAL', 'Intelektual'],
  ['MENTAL',       'Mental'],
  ['MULTIPLE',     'Multiple'],
]
const PTKP_OPTIONS = [
  { value: 'TK0', label: 'TK/0 — Belum kawin, tanpa tanggungan' },
  { value: 'TK1', label: 'TK/1 — Belum kawin, 1 tanggungan' },
  { value: 'TK2', label: 'TK/2 — Belum kawin, 2 tanggungan' },
  { value: 'TK3', label: 'TK/3 — Belum kawin, 3 tanggungan' },
  { value: 'K0',  label: 'K/0 — Kawin, tanpa tanggungan' },
  { value: 'K1',  label: 'K/1 — Kawin, 1 tanggungan' },
  { value: 'K2',  label: 'K/2 — Kawin, 2 tanggungan' },
  { value: 'K3',  label: 'K/3 — Kawin, 3 tanggungan' },
  { value: 'HB0', label: 'HB/0' },
  { value: 'HB1', label: 'HB/1' },
  { value: 'HB2', label: 'HB/2' },
  { value: 'HB3', label: 'HB/3' },
]
const BANK_OPTIONS = ['BCA','MANDIRI','BNI','BRI','CIMB','PERMATA','DANAMON','BTN','BSI','OCBC','MAYBANK','PANIN','MEGA','BTPN','LAINNYA']

// Section anchors untuk sticky table-of-contents
const SECTIONS = [
  { id: 'profil',    label: 'Profil Diri' },
  { id: 'identitas', label: 'Identitas' },
  { id: 'payroll',   label: 'Data Payroll' },
  { id: 'keluarga',  label: 'Kontak & Keluarga' },
  { id: 'pendidikan',label: 'Pendidikan & Karir' },
  { id: 'skill',     label: 'Skill & Mobilitas' },
  { id: 'referensi', label: 'Referensi' },
]
const activeSection = ref('profil')

// ─── Fetch ───────────────────────────────────────────────────────
async function fetchData() {
  if (! store.isAuthenticated) {
    return router.replace({ name: 'candidate-login', params: { slug: slug.value } })
  }
  loading.value = true
  error.value = null
  try {
    const res = await api.get(`/careers/account/applications/${candidateId.value}`)
    const data = res.data.data
    meta.value = {
      editable: data.editable,
      lock_reason: data.lock_reason,
      job: data.job,
      stage: data.application?.current_stage,
      applied_at: data.application?.applied_at,
      reference_number: data.job?.reference_number,
    }
    // Map row → form (string null → empty string supaya v-model behavior konsisten)
    Object.keys(form).forEach(k => {
      if (k in data.application) {
        const v = data.application[k]
        if (Array.isArray(form[k])) {
          form[k] = Array.isArray(v) ? v : []
        } else if (typeof form[k] === 'number') {
          form[k] = v !== null && v !== undefined ? Number(v) : 0
        } else {
          form[k] = v ?? ''
        }
      }
    })
  } catch (e) {
    error.value = e.response?.data?.message || 'Gagal memuat profil lamaran'
  } finally {
    loading.value = false
  }
}

onMounted(fetchData)

// ─── Helpers: dynamic referensi ──────────────────────────────────
function addReference() {
  if (form.reference_contacts.length >= 5) {
    toast.warning('Maksimal 5 referensi')
    return
  }
  form.reference_contacts.push({
    name: '', position: '', company: '', phone: '', relation: '',
  })
}
function removeReference(idx) {
  form.reference_contacts.splice(idx, 1)
}

// ─── Validation ──────────────────────────────────────────────────
function validate() {
  errors.value = {}
  if (! form.full_name.trim()) errors.value.full_name = 'Nama wajib diisi'
  if (form.email && ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(form.email)) {
    errors.value.email = 'Format email tidak valid'
  }
  if (form.phone && ! /^(\+?62|0)[\d\s-]{8,15}$/.test(form.phone.replace(/\s/g, ''))) {
    errors.value.phone = 'Format HP tidak valid'
  }
  if (form.nik && ! /^\d{16}$/.test(form.nik.replace(/\D/g, ''))) {
    errors.value.nik = 'NIK harus 16 digit'
  }
  if (form.kk_number && ! /^\d{16}$/.test(form.kk_number.replace(/\D/g, ''))) {
    errors.value.kk_number = 'No KK harus 16 digit'
  }
  if (form.postal_code && ! /^\d{5}$/.test(form.postal_code.replace(/\D/g, ''))) {
    errors.value.postal_code = 'Kode pos 5 digit'
  }
  if (form.gpa !== '' && form.gpa !== null) {
    const g = parseFloat(form.gpa)
    if (Number.isNaN(g) || g < 0 || g > 4) errors.value.gpa = 'IPK 0–4'
  }
  if (form.bank_account_number && ! /^\d{8,20}$/.test(form.bank_account_number.replace(/\D/g, ''))) {
    errors.value.bank_account_number = 'No rekening 8–20 digit'
  }
  if (form.bank_name && ! form.bank_account_number) {
    errors.value.bank_account_number = 'Lengkapi no rekening'
  }
  if (form.bank_account_number && ! form.bank_name) {
    errors.value.bank_name = 'Pilih bank'
  }
  if (form.emergency_contact_phone
    && ! /^(\+?62|0)[\d\s-]{8,15}$/.test(form.emergency_contact_phone.replace(/\s/g, ''))) {
    errors.value.emergency_contact_phone = 'Format HP tidak valid'
  }
  return Object.keys(errors.value).length === 0
}

// ─── Save ────────────────────────────────────────────────────────
async function save() {
  if (! meta.value.editable) {
    toast.error('Lamaran sudah pada tahap final, tidak bisa diedit')
    return
  }
  if (! validate()) {
    toast.warning('Periksa kembali field yang ditandai merah')
    // Scroll ke error pertama
    setTimeout(() => {
      const el = document.querySelector('.cp-error-mark')
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }, 0)
    return
  }
  saving.value = true
  try {
    const payload = { ...form }
    // Filter referensi kosong
    payload.reference_contacts = form.reference_contacts.filter(
      r => (r.name || '').trim() || (r.phone || '').trim(),
    )
    await api.put(`/careers/account/applications/${candidateId.value}`, payload)
    toast.success('Profil berhasil diperbarui')
    // Re-fetch supaya nilai server (mis. normalisasi NPWP/HP) ke-sync
    fetchData()
  } catch (e) {
    const msg = e.response?.data?.message || 'Gagal menyimpan profil'
    if (e.response?.data?.code === 'APPLICATION_LOCKED') {
      meta.value.editable = false
      meta.value.lock_reason = msg
    }
    toast.error(msg, { timeout: 6000 })
  } finally {
    saving.value = false
  }
}

function backToDashboard() {
  router.push({ name: 'candidate-dashboard', params: { slug: slug.value } })
}

// Smooth scroll ke section
function scrollToSection(id) {
  const el = document.getElementById('section-' + id)
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'start' })
    activeSection.value = id
  }
}
</script>

<template>
  <div class="max-w-5xl mx-auto py-6 px-4">
    <!-- Header -->
    <div class="mb-5">
      <button type="button" @click="backToDashboard"
        class="text-sm text-slate-500 hover:underline inline-flex items-center gap-1 mb-2">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
        </svg>
        Kembali ke Dashboard
      </button>
      <h1 class="text-2xl font-bold text-slate-900">Profil Lamaran Saya</h1>
      <div v-if="meta.job" class="text-sm text-slate-500 mt-1">
        Lamaran untuk
        <strong class="text-slate-700">{{ meta.job.title }}</strong>
        <span class="font-mono ml-2">· {{ meta.reference_number }}</span>
      </div>
    </div>

    <!-- Loading / Error -->
    <div v-if="loading" class="text-center py-16 text-slate-400">
      <div class="animate-spin inline-block w-6 h-6 border-2 border-current border-t-transparent rounded-full"></div>
      <div class="mt-3 text-sm">Memuat data...</div>
    </div>
    <div v-else-if="error" class="bg-rose-50 border border-rose-200 text-rose-700 rounded-lg p-4 text-sm">
      {{ error }}
    </div>

    <template v-else>
      <!-- Lock notice — kandidat HIRED/REJECTED/WITHDRAWN -->
      <div v-if="! meta.editable"
        class="mb-5 bg-amber-50 border border-amber-300 rounded-lg p-4 text-sm">
        <div class="flex items-start gap-3">
          <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
          </svg>
          <div>
            <div class="font-semibold text-amber-900">Profil terkunci</div>
            <p class="text-amber-800 mt-1">
              {{ meta.lock_reason || 'Lamaran sudah pada tahap final.' }}
              Anda hanya bisa melihat data; perubahan harus dikoordinasikan dengan tim HR.
            </p>
          </div>
        </div>
      </div>

      <!-- Editable hint -->
      <div v-else class="mb-5 bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-900">
        <strong>Tips:</strong> data yang lengkap & akurat membantu proses rekrutmen lebih cepat.
        Kalau Anda diterima, data ini juga dipakai untuk setup payroll & onboarding di hari pertama bekerja.
        Anda bisa edit selama lamaran masih dalam proses review.
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-[200px_1fr] gap-6">
        <!-- Sticky TOC -->
        <aside class="lg:sticky lg:top-20 lg:self-start hidden lg:block">
          <nav class="bg-white border border-slate-200 rounded-lg p-3">
            <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Section</div>
            <ul class="space-y-1">
              <li v-for="s in SECTIONS" :key="s.id">
                <button type="button" @click="scrollToSection(s.id)"
                  class="w-full text-left text-sm px-2 py-1.5 rounded hover:bg-slate-50 transition-colors"
                  :class="activeSection === s.id ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600'">
                  {{ s.label }}
                </button>
              </li>
            </ul>
          </nav>
        </aside>

        <!-- Form -->
        <form @submit.prevent="save" class="space-y-5">
          <fieldset :disabled="! meta.editable" class="space-y-5">

            <!-- Section: Profil ─────────────────────────────── -->
            <section id="section-profil" class="bg-white border border-slate-200 rounded-xl p-5">
              <h2 class="font-bold text-slate-900 mb-4">Profil Diri</h2>
              <div class="grid sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                  <label class="cp-label">Nama Lengkap *</label>
                  <input v-model="form.full_name" type="text" class="cp-input"
                    :class="errors.full_name && 'cp-error-mark'" />
                  <p v-if="errors.full_name" class="cp-err">{{ errors.full_name }}</p>
                </div>
                <div>
                  <label class="cp-label">Email</label>
                  <input v-model="form.email" type="email" class="cp-input"
                    :class="errors.email && 'cp-error-mark'" />
                  <p v-if="errors.email" class="cp-err">{{ errors.email }}</p>
                </div>
                <div>
                  <label class="cp-label">Nomor HP / WhatsApp</label>
                  <input v-model="form.phone" type="tel" class="cp-input"
                    :class="errors.phone && 'cp-error-mark'" />
                  <p v-if="errors.phone" class="cp-err">{{ errors.phone }}</p>
                </div>
                <div>
                  <label class="cp-label">Tempat Lahir</label>
                  <input v-model="form.birth_place" type="text" class="cp-input" />
                </div>
                <div>
                  <label class="cp-label">Tanggal Lahir</label>
                  <input v-model="form.birth_date" type="date" class="cp-input" />
                </div>
                <div>
                  <label class="cp-label">Jenis Kelamin</label>
                  <select v-model="form.gender" class="cp-input">
                    <option value="">— Pilih —</option>
                    <option v-for="[v, l] in GENDERS" :key="v" :value="v">{{ l }}</option>
                  </select>
                </div>
                <div>
                  <label class="cp-label">Status Pernikahan</label>
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
                <div>
                  <label class="cp-label">Kewarganegaraan</label>
                  <select v-model="form.nationality" class="cp-input">
                    <option value="WNI">WNI</option>
                    <option value="WNA">WNA</option>
                  </select>
                </div>
                <div>
                  <label class="cp-label">Golongan Darah</label>
                  <select v-model="form.blood_type" class="cp-input">
                    <option value="">— Tidak diisi —</option>
                    <option v-for="b in BLOOD_TYPES" :key="b" :value="b">{{ b }}</option>
                  </select>
                </div>
                <div class="sm:col-span-2">
                  <label class="cp-label">Status Disabilitas</label>
                  <select v-model="form.disability_status" class="cp-input">
                    <option v-for="[v, l] in DISABILITIES" :key="v" :value="v">{{ l }}</option>
                  </select>
                </div>
                <div v-if="form.disability_status && form.disability_status !== 'NONE'" class="sm:col-span-2">
                  <label class="cp-label">Akomodasi yang dibutuhkan</label>
                  <input v-model="form.disability_notes" type="text" class="cp-input" />
                </div>
              </div>
            </section>

            <!-- Section: Identitas ──────────────────────────── -->
            <section id="section-identitas" class="bg-white border border-slate-200 rounded-xl p-5">
              <h2 class="font-bold text-slate-900 mb-4">Identitas (KTP, NPWP, BPJS, Paspor)</h2>
              <div class="grid sm:grid-cols-2 gap-4">
                <div>
                  <label class="cp-label">NIK</label>
                  <input v-model="form.nik" type="text" class="cp-input"
                    :class="errors.nik && 'cp-error-mark'" maxlength="20" inputmode="numeric" />
                  <p v-if="errors.nik" class="cp-err">{{ errors.nik }}</p>
                </div>
                <div>
                  <label class="cp-label">NPWP</label>
                  <input v-model="form.npwp" type="text" class="cp-input" />
                </div>
                <div>
                  <label class="cp-label">No KK</label>
                  <input v-model="form.kk_number" type="text" class="cp-input"
                    :class="errors.kk_number && 'cp-error-mark'" maxlength="20" />
                  <p v-if="errors.kk_number" class="cp-err">{{ errors.kk_number }}</p>
                </div>
                <div>
                  <label class="cp-label">No Paspor</label>
                  <input v-model="form.passport_number" type="text" class="cp-input" />
                </div>
                <div>
                  <label class="cp-label">No BPJS Kesehatan</label>
                  <input v-model="form.bpjs_kesehatan_no" type="text" class="cp-input" />
                </div>
                <div>
                  <label class="cp-label">No BPJS Ketenagakerjaan</label>
                  <input v-model="form.bpjs_tk_no" type="text" class="cp-input" />
                </div>
                <div class="sm:col-span-2">
                  <label class="cp-label">Alamat</label>
                  <textarea v-model="form.address" rows="3" class="cp-input"></textarea>
                </div>
                <div>
                  <label class="cp-label">Kota</label>
                  <input v-model="form.city" type="text" class="cp-input" />
                </div>
                <div>
                  <label class="cp-label">Provinsi</label>
                  <input v-model="form.province" type="text" class="cp-input" />
                </div>
                <div>
                  <label class="cp-label">Kode Pos</label>
                  <input v-model="form.postal_code" type="text" class="cp-input"
                    :class="errors.postal_code && 'cp-error-mark'" maxlength="5" />
                  <p v-if="errors.postal_code" class="cp-err">{{ errors.postal_code }}</p>
                </div>
              </div>
            </section>

            <!-- Section: Payroll ────────────────────────────── -->
            <section id="section-payroll" class="bg-white border border-slate-200 rounded-xl p-5">
              <h2 class="font-bold text-slate-900 mb-1">Data Payroll</h2>
              <p class="text-xs text-slate-500 mb-4">
                Disimpan terenkripsi. Hanya dipakai untuk setup payroll bila Anda diterima.
              </p>
              <div class="grid sm:grid-cols-2 gap-4">
                <div>
                  <label class="cp-label">Status PTKP</label>
                  <select v-model="form.ptkp_status" class="cp-input">
                    <option value="">— Otomatis —</option>
                    <option v-for="o in PTKP_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                  </select>
                </div>
                <div>
                  <label class="cp-label">Jumlah Tanggungan PTKP</label>
                  <select v-model.number="form.dependents_count" class="cp-input">
                    <option :value="0">0 — Tanpa tanggungan</option>
                    <option :value="1">1 tanggungan</option>
                    <option :value="2">2 tanggungan</option>
                    <option :value="3">3 tanggungan (max)</option>
                  </select>
                </div>
                <div>
                  <label class="cp-label">Bank</label>
                  <select v-model="form.bank_name" class="cp-input"
                    :class="errors.bank_name && 'cp-error-mark'">
                    <option value="">— Pilih bank —</option>
                    <option v-for="b in BANK_OPTIONS" :key="b" :value="b">{{ b }}</option>
                  </select>
                  <p v-if="errors.bank_name" class="cp-err">{{ errors.bank_name }}</p>
                </div>
                <div>
                  <label class="cp-label">No Rekening</label>
                  <input v-model="form.bank_account_number" type="text"
                    class="cp-input font-mono"
                    :class="errors.bank_account_number && 'cp-error-mark'" />
                  <p v-if="errors.bank_account_number" class="cp-err">{{ errors.bank_account_number }}</p>
                </div>
                <div class="sm:col-span-2">
                  <label class="cp-label">Nama Pemilik Rekening</label>
                  <input v-model="form.bank_account_holder" type="text" class="cp-input"
                    placeholder="Sesuai buku tabungan" />
                </div>
              </div>
            </section>

            <!-- Section: Kontak Darurat & Keluarga ──────────── -->
            <section id="section-keluarga" class="bg-white border border-slate-200 rounded-xl p-5">
              <h2 class="font-bold text-slate-900 mb-4">Kontak Darurat & Keluarga</h2>
              <div class="grid sm:grid-cols-2 gap-4">
                <div>
                  <label class="cp-label">Nama Kontak Darurat</label>
                  <input v-model="form.emergency_contact_name" type="text" class="cp-input" />
                </div>
                <div>
                  <label class="cp-label">Hubungan</label>
                  <select v-model="form.emergency_contact_relation" class="cp-input">
                    <option value="">— Pilih —</option>
                    <option v-for="[v, l] in RELATIONS" :key="v" :value="v">{{ l }}</option>
                  </select>
                </div>
                <div class="sm:col-span-2">
                  <label class="cp-label">Nomor HP Kontak Darurat</label>
                  <input v-model="form.emergency_contact_phone" type="tel" class="cp-input"
                    :class="errors.emergency_contact_phone && 'cp-error-mark'" />
                  <p v-if="errors.emergency_contact_phone" class="cp-err">{{ errors.emergency_contact_phone }}</p>
                </div>
              </div>

              <div v-if="form.marital_status === 'KAWIN'" class="mt-5 pt-5 border-t border-slate-100">
                <h3 class="font-semibold text-slate-800 mb-3">Data Pasangan & Anak</h3>
                <div class="grid sm:grid-cols-2 gap-4">
                  <div>
                    <label class="cp-label">Nama Pasangan</label>
                    <input v-model="form.spouse_name" type="text" class="cp-input" />
                  </div>
                  <div>
                    <label class="cp-label">Pekerjaan Pasangan</label>
                    <input v-model="form.spouse_occupation" type="text" class="cp-input" />
                  </div>
                  <div class="sm:col-span-2">
                    <label class="cp-label">Tempat Kerja Pasangan</label>
                    <input v-model="form.spouse_workplace" type="text" class="cp-input" />
                  </div>
                  <div>
                    <label class="cp-label">Jumlah Anak</label>
                    <input v-model.number="form.children_count" type="number" min="0" max="20" class="cp-input" />
                  </div>
                </div>
              </div>

              <div class="mt-5 pt-5 border-t border-slate-100">
                <h3 class="font-semibold text-slate-800 mb-1">Riwayat Kesehatan</h3>
                <p class="text-xs text-slate-500 mb-2">Opsional. Untuk situasi darurat di tempat kerja.</p>
                <textarea v-model="form.health_conditions" rows="3" class="cp-input"
                  placeholder="mis. Alergi penisilin, asma ringan terkontrol"></textarea>
              </div>
            </section>

            <!-- Section: Pendidikan & Pengalaman ────────────── -->
            <section id="section-pendidikan" class="bg-white border border-slate-200 rounded-xl p-5">
              <h2 class="font-bold text-slate-900 mb-4">Pendidikan & Pengalaman</h2>
              <div class="grid sm:grid-cols-3 gap-4">
                <div>
                  <label class="cp-label">Jenjang Terakhir</label>
                  <select v-model="form.last_education_level" class="cp-input">
                    <option value="">— Pilih —</option>
                    <option v-for="l in EDU_LEVELS" :key="l" :value="l">{{ l }}</option>
                  </select>
                </div>
                <div class="sm:col-span-2">
                  <label class="cp-label">Institusi</label>
                  <input v-model="form.last_education_institution" type="text" class="cp-input" />
                </div>
                <div class="sm:col-span-2">
                  <label class="cp-label">Jurusan</label>
                  <input v-model="form.last_education_major" type="text" class="cp-input" />
                </div>
                <div>
                  <label class="cp-label">Tahun Lulus</label>
                  <input v-model="form.last_education_year" type="number" :min="1980"
                    :max="new Date().getFullYear() + 1" class="cp-input" />
                </div>
                <div>
                  <label class="cp-label">IPK</label>
                  <input v-model="form.gpa" type="number" step="0.01" min="0" max="4"
                    class="cp-input" :class="errors.gpa && 'cp-error-mark'" />
                  <p v-if="errors.gpa" class="cp-err">{{ errors.gpa }}</p>
                </div>
              </div>

              <div class="mt-5 pt-5 border-t border-slate-100">
                <h3 class="font-semibold text-slate-800 mb-3">Pengalaman Kerja</h3>
                <div class="grid sm:grid-cols-2 gap-4">
                  <div>
                    <label class="cp-label">Jabatan Saat Ini</label>
                    <input v-model="form.current_position" type="text" class="cp-input" />
                  </div>
                  <div>
                    <label class="cp-label">Perusahaan</label>
                    <input v-model="form.current_company" type="text" class="cp-input" />
                  </div>
                  <div>
                    <label class="cp-label">Total Pengalaman (tahun)</label>
                    <input v-model="form.years_experience" type="number" min="0" max="40" class="cp-input" />
                  </div>
                  <div>
                    <label class="cp-label">Ekspektasi Gaji (IDR/bulan)</label>
                    <input v-model="form.expected_salary" type="number" min="0" step="100000" class="cp-input" />
                  </div>
                  <div>
                    <label class="cp-label">Tanggal Bisa Mulai</label>
                    <input v-model="form.available_from" type="date" class="cp-input" />
                  </div>
                </div>
                <div class="grid sm:grid-cols-3 gap-4 mt-4">
                  <div>
                    <label class="cp-label">LinkedIn</label>
                    <input v-model="form.linkedin_url" type="text" class="cp-input" />
                  </div>
                  <div>
                    <label class="cp-label">Portfolio / Website</label>
                    <input v-model="form.portfolio_url" type="text" class="cp-input" />
                  </div>
                  <div>
                    <label class="cp-label">GitHub</label>
                    <input v-model="form.github_url" type="text" class="cp-input" />
                  </div>
                </div>
              </div>
            </section>

            <!-- Section: Skill & Mobilitas ─────────────────── -->
            <section id="section-skill" class="bg-white border border-slate-200 rounded-xl p-5">
              <h2 class="font-bold text-slate-900 mb-4">Skill & Mobilitas</h2>
              <div class="space-y-4">
                <div>
                  <label class="cp-label">Bahasa yang Dikuasai</label>
                  <input v-model="form.languages" type="text" class="cp-input"
                    placeholder="mis. Indonesia (native), English (advanced)" />
                </div>
                <div>
                  <label class="cp-label">Keahlian / Skills</label>
                  <textarea v-model="form.professional_skills" rows="2" class="cp-input"></textarea>
                </div>
                <div>
                  <label class="cp-label">Sertifikasi Profesional</label>
                  <textarea v-model="form.certifications" rows="2" class="cp-input"></textarea>
                </div>
              </div>
              <div class="grid sm:grid-cols-2 gap-4 mt-4 pt-4 border-t border-slate-100">
                <div>
                  <label class="cp-label">SIM yang Dimiliki</label>
                  <div class="space-y-1.5">
                    <label v-for="[v, l] in DRIVING_LICENSES" :key="v"
                      class="flex items-start gap-2 text-sm cursor-pointer">
                      <input type="checkbox" v-model="form.driving_license" :value="v"
                        class="mt-0.5 w-4 h-4 accent-indigo-600" :disabled="! meta.editable" />
                      <span>{{ l }}</span>
                    </label>
                  </div>
                </div>
                <div>
                  <label class="cp-label">Kendaraan Pribadi</label>
                  <div class="space-y-1.5">
                    <label v-for="[v, l] in VEHICLES" :key="v"
                      class="flex items-start gap-2 text-sm cursor-pointer">
                      <input type="checkbox" v-model="form.vehicle_owned" :value="v"
                        class="mt-0.5 w-4 h-4 accent-indigo-600" :disabled="! meta.editable" />
                      <span>{{ l }}</span>
                    </label>
                  </div>
                </div>
              </div>
            </section>

            <!-- Section: Referensi ─────────────────────────── -->
            <section id="section-referensi" class="bg-white border border-slate-200 rounded-xl p-5">
              <div class="flex items-center justify-between mb-3">
                <h2 class="font-bold text-slate-900">Referensi Profesional</h2>
                <button v-if="meta.editable && form.reference_contacts.length < 5" type="button"
                  @click="addReference"
                  class="text-sm text-indigo-600 hover:underline inline-flex items-center gap-1">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                  </svg>
                  Tambah Referensi
                </button>
              </div>
              <p v-if="form.reference_contacts.length === 0"
                class="text-xs text-slate-500 italic mb-3">
                Belum ada referensi. Klik "Tambah Referensi" untuk mencantumkan rekan kerja, atasan, atau dosen.
              </p>
              <div v-for="(ref, idx) in form.reference_contacts" :key="idx"
                class="border border-slate-200 rounded-md p-3 mb-3 relative">
                <button v-if="meta.editable" type="button" @click="removeReference(idx)"
                  class="absolute top-2 right-2 text-slate-400 hover:text-red-600">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
                <div class="text-xs font-medium text-slate-600 mb-2">Referensi #{{ idx + 1 }}</div>
                <div class="grid sm:grid-cols-2 gap-3">
                  <input v-model="ref.name" type="text" class="cp-input" placeholder="Nama lengkap" />
                  <input v-model="ref.relation" type="text" class="cp-input" placeholder="Hubungan" />
                  <input v-model="ref.position" type="text" class="cp-input" placeholder="Jabatan" />
                  <input v-model="ref.company" type="text" class="cp-input" placeholder="Perusahaan" />
                  <input v-model="ref.phone" type="tel" class="cp-input sm:col-span-2 font-mono" placeholder="0812..." />
                </div>
              </div>

              <div class="mt-4">
                <label class="cp-label">Sumber Lamaran</label>
                <input v-model="form.referral_source_detail" type="text" class="cp-input"
                  placeholder="mis. Referral dari Andi Wijaya" />
              </div>
            </section>
          </fieldset>

          <!-- Save bar (sticky bottom on mobile) -->
          <div v-if="meta.editable"
            class="sticky bottom-0 bg-white border border-slate-200 rounded-xl p-4 shadow-lg flex items-center justify-between gap-3 z-10">
            <p class="text-xs text-slate-500 hidden sm:block">
              Perubahan akan langsung terupdate di sistem HR setelah Anda klik Simpan.
            </p>
            <div class="flex gap-2 ml-auto">
              <button type="button" @click="fetchData"
                class="px-4 py-2 rounded-md border border-slate-300 text-sm hover:bg-slate-50">
                Reset
              </button>
              <button type="submit" :disabled="saving"
                class="px-6 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 inline-flex items-center gap-1">
                <span v-if="saving" class="animate-spin inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full"></span>
                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
                {{ saving ? 'Menyimpan...' : 'Simpan Perubahan' }}
              </button>
            </div>
          </div>
        </form>
      </div>
    </template>
  </div>
</template>

<style scoped>
.cp-label {
  display: block;
  font-size: 0.875rem;
  font-weight: 500;
  color: rgb(51 65 85);
  margin-bottom: 0.375rem;
}
.cp-input {
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem;
  border: 1px solid rgb(203 213 225);
  border-radius: 0.375rem;
  font-size: 0.875rem;
  background: #fff;
  color: rgb(15 23 42);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.cp-input::placeholder { color: rgb(148 163 184); }
.cp-input:focus {
  outline: none;
  border-color: rgb(79 70 229); /* indigo-600 */
  box-shadow: 0 0 0 3px rgb(99 102 241 / 0.2);
}
.cp-input:disabled {
  background: rgb(248 250 252);
  color: rgb(100 116 139);
  cursor: not-allowed;
}
.cp-error-mark { border-color: rgb(239 68 68) !important; }
.cp-err {
  font-size: 0.75rem;
  color: rgb(220 38 38);
  margin-top: 0.25rem;
}
</style>