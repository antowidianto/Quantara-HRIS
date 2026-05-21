<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { confirm as confirmDialog } from '@/composables/useConfirm'

const props = defineProps({
  /** ID karyawan yang dibuka. Null = mode tambah baru. */
  employeeId: { type: [Number, String, null], default: null },
  /** Mode awal: 'view' atau 'edit'. */
  mode: { type: String, default: 'view' },
})
const emit = defineEmits(['close', 'saved'])

const auth = useAuthStore()
const toast = useToast()

const internalMode = ref(props.mode)
const employee = ref(null)
const balances = ref([])
const contracts = ref([])
const allowances = ref([])
const loading = ref(false)
const saving = ref(false)

const departments = ref([])
const positions = ref([])
const branches = ref([])
const jobGrades = ref([])
const workLocations = ref([])
const employeesList = ref([])
const approvalChains = ref({ LEAVE: [], OVERTIME: [], ATTENDANCE_CHANGE: [] })
const generatedContracts = ref([])
const contractTemplates = ref([])
const showGenerateModal = ref(false)
const generating = ref(false)
const generateForm = ref({ template_id: null, employee_contract_id: null, subject: '' })
const previewHtml = ref('')
const previewLoading = ref(false)

// ─── Riwayat & Aset (Sprint 3 item H) ──────────────────────────
// Tab "Riwayat & Aset" lazy-loads 3 endpoint paralel saat di-aktifkan
// pertama kali. Cache di state lokal sampai modal ditutup. Lihat
// docs/AUDIT.md item H.
const historyAssets = ref([])
const historyDisciplinary = ref(null)
const historyTasks = ref([])
const historyLoaded = ref(false)
const historyLoading = ref(false)
const historyError = ref('')

async function loadHistoryTab() {
  if (! props.employeeId || historyLoaded.value || historyLoading.value) return
  historyLoading.value = true
  historyError.value = ''
  try {
    // Promise.allSettled supaya 1 endpoint gagal tidak blok yang lain
    const eid = props.employeeId
    const [aRes, dRes, tRes] = await Promise.allSettled([
      api.get(`/asset-assignments/employee/${eid}`),
      api.get(`/disciplinary-records/employee-summary/${eid}`),
      api.get(`/employee-tasks/employee/${eid}`),
    ])
    historyAssets.value      = aRes.status === 'fulfilled' ? (aRes.value.data?.data || []) : []
    historyDisciplinary.value= dRes.status === 'fulfilled' ? (dRes.value.data?.data || null) : null
    historyTasks.value       = tRes.status === 'fulfilled' ? (tRes.value.data?.data || []) : []
    historyLoaded.value = true
  } catch (e) {
    historyError.value = e?.message || 'Gagal memuat riwayat'
  } finally {
    historyLoading.value = false
  }
}

const isCreating = computed(() => ! props.employeeId)
const isEditing = computed(() => internalMode.value === 'edit' || isCreating.value)

const canEdit = computed(() =>
  auth.isSuperAdmin || auth.permissions.includes('employee.update') || auth.permissions.includes('employee.create')
)
const canDelete = computed(() =>
  auth.isSuperAdmin || auth.permissions.includes('employee.delete')
)

const blankForm = () => ({
  employee_number: '',
  first_name: '',
  last_name: '',
  full_name: '',
  gender: '',
  marital_status: 'TK0',
  ptkp_status: 'TK0',
  email: '',
  phone: '',
  date_of_birth: '',
  place_of_birth: '',
  address: '',
  province: '',
  city: '',
  postal_code: '',
  home_lat: '',
  home_lng: '',
  nik: '',
  npwp: '',
  bpjs_kesehatan_no: '',
  bpjs_tk_no: '',
  kk_number: '',
  mother_name: '',
  father_name: '',
  citizenship: 'WNI',
  ethnicity: '',
  education_level: '',
  marital_certificate_no: '',
  religion: '',
  blood_type: '',
  ktp_address: '',
  ktp_rt_rw: '',
  ktp_kelurahan: '',
  ktp_kecamatan: '',
  ktp_city: '',
  ktp_province: '',
  ktp_postal_code: '',
  bank_name: '',
  bank_account: '',
  bank_account_holder: '',
  branch_id: null,
  department_id: null,
  position_id: null,
  job_grade_id: null,
  manager_id: null,
  supervisor_id: null,
  work_location_id: null,
  approval_chain_leave_id: null,
  approval_chain_overtime_id: null,
  approval_chain_attendance_id: null,
  employment_status: 'PKWTT',
  employment_type: 'FULL_TIME',
  join_date: new Date().toISOString().slice(0, 10),
  probation_end_date: '',
  contract_end_date: '',
  basic_salary: 0,
  tax_method: 'GROSS',
  pph21_method: 'TER',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  emergency_contact_rel: '',
  is_active: 1,
  create_user_account: false,
})
const form = ref(blankForm())

async function loadMasters() {
  try {
    const [d, p, b, j, wl, emp, tpl, cl, co, ca] = await Promise.allSettled([
      api.get('/departments', { params: { per_page: 200 } }),
      api.get('/positions', { params: { per_page: 200 } }),
      api.get('/branches', { params: { per_page: 200 } }),
      api.get('/job-grades', { params: { per_page: 200 } }),
      api.get('/work-locations', { params: { per_page: 200 } }),
      api.get('/employees', { params: { per_page: 500, status: 'active' } }),
      api.get('/contract-templates'),
      api.get('/approval-chains', { params: { module: 'LEAVE' } }),
      api.get('/approval-chains', { params: { module: 'OVERTIME' } }),
      api.get('/approval-chains', { params: { module: 'ATTENDANCE_CHANGE' } }),
    ])
    if (d.status === 'fulfilled') departments.value = d.value.data.data
    if (p.status === 'fulfilled') positions.value = p.value.data.data
    if (b.status === 'fulfilled') branches.value = b.value.data.data
    if (j.status === 'fulfilled') jobGrades.value = j.value.data.data
    if (wl.status === 'fulfilled') workLocations.value = wl.value.data.data
    if (emp.status === 'fulfilled') employeesList.value = emp.value.data.data
    if (tpl.status === 'fulfilled') contractTemplates.value = tpl.value.data.data
    if (cl.status === 'fulfilled') approvalChains.value.LEAVE = cl.value.data.data
    if (co.status === 'fulfilled') approvalChains.value.OVERTIME = co.value.data.data
    if (ca.status === 'fulfilled') approvalChains.value.ATTENDANCE_CHANGE = ca.value.data.data
  } catch (e) { /* ignore */ }
}

async function loadEmployee() {
  if (! props.employeeId) {
    employee.value = null
    form.value = blankForm()
    return
  }
  loading.value = true
  try {
    const [emp, bal, ctr, alw, gen] = await Promise.allSettled([
      api.get(`/employees/${props.employeeId}`),
      api.get(`/leave-balances/${props.employeeId}`),
      api.get(`/employees/${props.employeeId}/contracts`),
      api.get(`/employee-allowances/${props.employeeId}`),
      api.get('/generated-contracts', { params: { employee_id: props.employeeId } }),
    ])
    if (emp.status === 'fulfilled') {
      employee.value = emp.value.data.data
      form.value = { ...blankForm(), ...employee.value }
    }
    if (bal.status === 'fulfilled') balances.value = bal.value.data.data || []
    if (ctr.status === 'fulfilled') contracts.value = ctr.value.data.data || []
    if (alw.status === 'fulfilled') allowances.value = alw.value.data.data || []
    if (gen.status === 'fulfilled') generatedContracts.value = gen.value.data.data || []
  } catch (e) {
    toast.error('Gagal memuat data karyawan')
  } finally {
    loading.value = false
  }
}

function openGenerateContract() {
  if (! props.employeeId) {
    toast.warning('Simpan karyawan terlebih dahulu sebelum generate kontrak')
    return
  }
  generateForm.value = {
    template_id: contractTemplates.value[0]?.id || null,
    employee_contract_id: contracts.value[0]?.id || null,
    subject: '',
  }
  previewHtml.value = ''
  showGenerateModal.value = true
}

async function previewContract() {
  if (! generateForm.value.template_id) {
    toast.warning('Pilih template terlebih dahulu')
    return
  }
  previewLoading.value = true
  try {
    const res = await api.post(
      `/contract-templates/${generateForm.value.template_id}/preview`,
      {
        employee_id: props.employeeId,
        employee_contract_id: generateForm.value.employee_contract_id,
      },
    )
    previewHtml.value = res.data.data.html
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal preview kontrak')
  } finally {
    previewLoading.value = false
  }
}

async function submitGenerateContract() {
  if (! generateForm.value.template_id) {
    toast.warning('Pilih template terlebih dahulu')
    return
  }
  generating.value = true
  try {
    const res = await api.post(
      `/contract-templates/${generateForm.value.template_id}/generate`,
      {
        employee_id: props.employeeId,
        employee_contract_id: generateForm.value.employee_contract_id,
        subject: generateForm.value.subject || undefined,
      },
    )
    toast.success('Kontrak digenerate: ' + (res.data.data?.contract_number || ''))
    showGenerateModal.value = false
    await loadEmployee()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal generate kontrak')
  } finally {
    generating.value = false
  }
}

function openGeneratedContract(c) {
  api.get(`/generated-contracts/${c.id}/html`, { responseType: 'blob' }).then(res => {
    const url = URL.createObjectURL(res.data)
    window.open(url, '_blank')
  }).catch(e => {
    toast.error('Gagal membuka kontrak: ' + (e.response?.data?.message || e.message))
  })
}

onMounted(async () => {
  await loadMasters()
  await loadEmployee()
})

watch(() => props.employeeId, loadEmployee)
watch(() => props.mode, (m) => { internalMode.value = m })

function startEdit() {
  if (! canEdit.value) {
    toast.warning('Anda tidak punya izin mengedit karyawan')
    return
  }
  internalMode.value = 'edit'
}

function cancelEdit() {
  if (isCreating.value) {
    emit('close')
    return
  }
  form.value = { ...blankForm(), ...employee.value }
  internalMode.value = 'view'
}

async function save() {
  saving.value = true
  try {
    const payload = { ...form.value }
    if (isCreating.value) {
      const res = await api.post('/employees', payload)
      toast.success('Karyawan ditambahkan')
      emit('saved', res.data.data?.id || null)
    } else {
      await api.put(`/employees/${props.employeeId}`, payload)
      toast.success('Data karyawan diperbarui')
      await loadEmployee()
      internalMode.value = 'view'
      emit('saved', props.employeeId)
    }
  } catch (e) {
    const errs = e.response?.data?.errors
    if (errs) {
      toast.error('Validasi gagal: ' + Object.values(errs).flat().join(', '))
    } else {
      toast.error(e.response?.data?.message || 'Gagal menyimpan')
    }
  } finally {
    saving.value = false
  }
}

async function recomputeAllowance() {
  const ok = await confirmDialog({
    title: 'Hitung ulang tunjangan?',
    message: 'Snapshot tunjangan jarak akan diperbarui berdasarkan koordinat & cabang terbaru.',
    okText: 'Hitung Ulang',
  })
  if (! ok) return
  try {
    const res = await api.post(`/employee-allowances/${props.employeeId}/recompute`)
    toast.success(`Recompute selesai: ${res.data.data?.count || 0} entri`)
    await loadEmployee()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal recompute')
  }
}

async function deactivateEmployee() {
  if (! canDelete.value || ! props.employeeId) return
  const ok = await confirmDialog({
    title: 'Nonaktifkan karyawan?',
    message: `${employee.value?.full_name || 'Karyawan'} akan ditandai non-aktif dan akun login-nya dimatikan. Data tidak terhapus.`,
    okText: 'Nonaktifkan',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/employees/${props.employeeId}`)
    toast.success('Karyawan dinonaktifkan')
    emit('saved', props.employeeId)
    emit('close')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menonaktifkan')
  }
}

const tabs = computed(() => {
  // Tab "Riwayat & Aset" hanya tampil saat editing existing employee.
  // Saat create baru (employeeId null), endpoint history belum ada datanya.
  const list = [
    { id: 'general',    label: 'Identitas' },
    { id: 'ktp',        label: 'KTP & Keluarga' },
    { id: 'employment', label: 'Kepegawaian' },
    { id: 'approval',   label: 'Approval' },
    { id: 'salary',     label: 'Gaji & Pajak' },
    { id: 'address',    label: 'Alamat & Lokasi' },
    { id: 'allowances', label: 'Tunjangan' },
    { id: 'contracts',  label: 'Kontrak' },
    { id: 'leave',      label: 'Saldo Cuti' },
  ]
  if (! isCreating.value) {
    list.push({ id: 'history', label: 'Riwayat & Aset' })
  }
  return list
})
const activeTab = ref('general')

// Lazy-load history saat user pertama kali klik tab itu.
watch(activeTab, (val) => {
  if (val === 'history') loadHistoryTab()
})

function fmtDateOrDash(v) {
  if (! v) return '—'
  return new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl max-h-[92vh] flex flex-col">
      <!-- Header -->
      <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
        <div>
          <div class="flex items-center gap-2">
            <h2 class="text-lg font-bold">
              <span v-if="isCreating">Tambah Karyawan Baru</span>
              <span v-else-if="employee">{{ employee.full_name }}</span>
              <span v-else>Memuat...</span>
            </h2>
            <span v-if="employee && !isCreating" class="text-xs text-gray-500 font-mono">{{ employee.employee_number }}</span>
            <span v-if="employee && !isCreating"
              :class="employee.is_active ? 'badge-green' : 'badge-gray'">
              {{ employee.is_active ? 'Aktif' : 'Non-aktif' }}
            </span>
            <span v-if="!isCreating && internalMode === 'edit'" class="badge-yellow">Mode Edit</span>
          </div>
          <p v-if="employee && !isCreating" class="text-xs text-gray-500 mt-0.5">
            {{ employee.email || '—' }} · {{ employee.phone || '—' }}
          </p>
        </div>
        <div class="flex items-center gap-2">
          <button
            v-if="!isCreating && internalMode === 'view' && canEdit"
            class="btn-primary text-sm" @click="startEdit"
          >Edit</button>
          <button
            v-if="!isCreating && internalMode === 'view' && canDelete && employee?.is_active"
            class="btn-danger text-sm" @click="deactivateEmployee"
          >Nonaktifkan</button>
          <button
            class="text-gray-400 hover:text-gray-600 text-2xl leading-none px-2"
            aria-label="Tutup" @click="emit('close')"
          >&times;</button>
        </div>
      </div>

      <!-- Tabs -->
      <div class="px-5 pt-3 border-b border-gray-100 flex gap-1 overflow-x-auto">
        <button
          v-for="t in tabs" :key="t.id"
          :class="[
            'px-3 py-2 text-sm border-b-2 -mb-px whitespace-nowrap',
            activeTab === t.id ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-600 hover:text-gray-800',
          ]"
          @click="activeTab = t.id"
        >{{ t.label }}</button>
      </div>

      <!-- Body -->
      <div class="flex-1 overflow-y-auto p-5 text-sm">
        <div v-if="loading" class="text-center py-12 text-gray-400">Memuat...</div>

        <!-- ── IDENTITAS ───────────────────────────────────────── -->
        <div v-else-if="activeTab === 'general'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="label">NIP</label>
            <input v-if="isEditing" v-model="form.employee_number" :disabled="!isCreating" class="input" />
            <div v-else class="font-mono">{{ employee?.employee_number || '-' }}</div>
          </div>
          <div></div>
          <div>
            <label class="label">Nama Depan</label>
            <input v-if="isEditing" v-model="form.first_name" class="input" required />
            <div v-else>{{ employee?.first_name || '-' }}</div>
          </div>
          <div>
            <label class="label">Nama Belakang</label>
            <input v-if="isEditing" v-model="form.last_name" class="input" />
            <div v-else>{{ employee?.last_name || '-' }}</div>
          </div>
          <div>
            <label class="label">Email</label>
            <input v-if="isEditing" v-model="form.email" type="email" class="input" />
            <div v-else>{{ employee?.email || '-' }}</div>
          </div>
          <div>
            <label class="label">Telepon</label>
            <input v-if="isEditing" v-model="form.phone" class="input" />
            <div v-else>{{ employee?.phone || '-' }}</div>
          </div>
          <div>
            <label class="label">Jenis Kelamin</label>
            <select v-if="isEditing" v-model="form.gender" class="input">
              <option value="">- Pilih -</option>
              <option value="M">Laki-laki</option>
              <option value="F">Perempuan</option>
            </select>
            <div v-else>{{ employee?.gender === 'M' ? 'Laki-laki' : (employee?.gender === 'F' ? 'Perempuan' : '-') }}</div>
          </div>
          <div>
            <label class="label">Tempat Lahir</label>
            <input v-if="isEditing" v-model="form.place_of_birth" class="input" />
            <div v-else>{{ employee?.place_of_birth || '-' }}</div>
          </div>
          <div>
            <label class="label">Tanggal Lahir</label>
            <input v-if="isEditing" v-model="form.date_of_birth" type="date" class="input" />
            <div v-else>{{ employee?.date_of_birth ? fmtDate(employee.date_of_birth) : '-' }}</div>
          </div>
          <div>
            <label class="label">NIK KTP <span v-if="isEditing" class="text-xs text-gray-500">(terenkripsi)</span></label>
            <input v-if="isEditing" v-model="form.nik" class="input" maxlength="16" />
            <div v-else class="font-mono">{{ employee?.nik || '-' }}</div>
          </div>
          <div>
            <label class="label">NPWP</label>
            <input v-if="isEditing" v-model="form.npwp" class="input" />
            <div v-else>{{ employee?.npwp || '-' }}</div>
          </div>
          <div>
            <label class="label">Status PTKP</label>
            <select v-if="isEditing" v-model="form.ptkp_status" class="input">
              <option v-for="c in ['TK0','TK1','TK2','TK3','K0','K1','K2','K3','HB0','HB1','HB2','HB3']" :key="c" :value="c">{{ c }}</option>
            </select>
            <div v-else>{{ employee?.ptkp_status || '-' }}</div>
          </div>
          <div>
            <label class="label">Status Pernikahan</label>
            <select v-if="isEditing" v-model="form.marital_status" class="input">
              <option v-for="c in ['TK0','TK1','TK2','TK3','K0','K1','K2','K3','HB0','HB1','HB2','HB3']" :key="c" :value="c">{{ c }}</option>
            </select>
            <div v-else>{{ employee?.marital_status || '-' }}</div>
          </div>
          <div>
            <label class="label">No. BPJS Kesehatan</label>
            <input v-if="isEditing" v-model="form.bpjs_kesehatan_no" class="input" />
            <div v-else>{{ employee?.bpjs_kesehatan_no || '-' }}</div>
          </div>
          <div>
            <label class="label">No. BPJS Ketenagakerjaan</label>
            <input v-if="isEditing" v-model="form.bpjs_tk_no" class="input" />
            <div v-else>{{ employee?.bpjs_tk_no || '-' }}</div>
          </div>
          <div>
            <label class="label">Agama</label>
            <select v-if="isEditing" v-model="form.religion" class="input">
              <option value="">- Pilih -</option>
              <option value="Islam">Islam</option>
              <option value="Kristen">Kristen Protestan</option>
              <option value="Katolik">Katolik</option>
              <option value="Hindu">Hindu</option>
              <option value="Buddha">Buddha</option>
              <option value="Konghucu">Konghucu</option>
              <option value="Lainnya">Lainnya</option>
            </select>
            <div v-else>{{ employee?.religion || '-' }}</div>
          </div>
          <div>
            <label class="label">Golongan Darah</label>
            <select v-if="isEditing" v-model="form.blood_type" class="input">
              <option value="">- Pilih -</option>
              <option v-for="b in ['A','B','AB','O','A+','A-','B+','B-','AB+','AB-','O+','O-']" :key="b" :value="b">{{ b }}</option>
            </select>
            <div v-else>{{ employee?.blood_type || '-' }}</div>
          </div>
          <div>
            <label class="label">Kewarganegaraan</label>
            <select v-if="isEditing" v-model="form.citizenship" class="input">
              <option value="WNI">WNI</option>
              <option value="WNA">WNA</option>
            </select>
            <div v-else>{{ employee?.citizenship || '-' }}</div>
          </div>
          <div>
            <label class="label">Suku (opsional)</label>
            <input v-if="isEditing" v-model="form.ethnicity" class="input" placeholder="Jawa / Sunda / Batak / dst." />
            <div v-else>{{ employee?.ethnicity || '-' }}</div>
          </div>
          <div>
            <label class="label">Pendidikan Terakhir</label>
            <select v-if="isEditing" v-model="form.education_level" class="input">
              <option value="">- Pilih -</option>
              <option v-for="e in ['SD','SMP','SMA','SMK','D1','D2','D3','D4','S1','S2','S3']" :key="e" :value="e">{{ e }}</option>
            </select>
            <div v-else>{{ employee?.education_level || '-' }}</div>
          </div>

          <div v-if="isCreating" class="md:col-span-2 mt-4 p-3 bg-blue-50 rounded">
            <label class="flex items-center gap-2 text-sm">
              <input type="checkbox" v-model="form.create_user_account" />
              <span>Buat akun login otomatis (gunakan email di atas; password sementara dikirim via email)</span>
            </label>
          </div>
        </div>

        <!-- ── KTP & KELUARGA ──────────────────────────────────── -->
        <div v-else-if="activeTab === 'ktp'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2 text-xs text-gray-600 bg-gray-50 p-2 rounded">
            Alamat sesuai KTP sering berbeda dengan alamat domisili. Bagian ini dipakai untuk dokumen kontrak & pendaftaran BPJS.
          </div>
          <div>
            <label class="label">No. Kartu Keluarga (KK)</label>
            <input v-if="isEditing" v-model="form.kk_number" class="input" maxlength="32" />
            <div v-else class="font-mono">{{ employee?.kk_number || '-' }}</div>
          </div>
          <div>
            <label class="label">No. Akta Nikah / Cerai</label>
            <input v-if="isEditing" v-model="form.marital_certificate_no" class="input" />
            <div v-else>{{ employee?.marital_certificate_no || '-' }}</div>
          </div>
          <div>
            <label class="label">Nama Ibu Kandung</label>
            <input v-if="isEditing" v-model="form.mother_name" class="input" placeholder="Untuk verifikasi BPJS / bank" />
            <div v-else>{{ employee?.mother_name || '-' }}</div>
          </div>
          <div>
            <label class="label">Nama Ayah</label>
            <input v-if="isEditing" v-model="form.father_name" class="input" />
            <div v-else>{{ employee?.father_name || '-' }}</div>
          </div>
          <div class="md:col-span-2">
            <label class="label">Alamat KTP</label>
            <textarea v-if="isEditing" v-model="form.ktp_address" class="input" rows="2" placeholder="Sesuai e-KTP"></textarea>
            <div v-else>{{ employee?.ktp_address || '-' }}</div>
          </div>
          <div>
            <label class="label">RT/RW</label>
            <input v-if="isEditing" v-model="form.ktp_rt_rw" class="input" placeholder="001/002" />
            <div v-else>{{ employee?.ktp_rt_rw || '-' }}</div>
          </div>
          <div>
            <label class="label">Kelurahan/Desa</label>
            <input v-if="isEditing" v-model="form.ktp_kelurahan" class="input" />
            <div v-else>{{ employee?.ktp_kelurahan || '-' }}</div>
          </div>
          <div>
            <label class="label">Kecamatan</label>
            <input v-if="isEditing" v-model="form.ktp_kecamatan" class="input" />
            <div v-else>{{ employee?.ktp_kecamatan || '-' }}</div>
          </div>
          <div>
            <label class="label">Kota/Kabupaten</label>
            <input v-if="isEditing" v-model="form.ktp_city" class="input" />
            <div v-else>{{ employee?.ktp_city || '-' }}</div>
          </div>
          <div>
            <label class="label">Provinsi</label>
            <input v-if="isEditing" v-model="form.ktp_province" class="input" />
            <div v-else>{{ employee?.ktp_province || '-' }}</div>
          </div>
          <div>
            <label class="label">Kode Pos KTP</label>
            <input v-if="isEditing" v-model="form.ktp_postal_code" class="input" maxlength="5" />
            <div v-else>{{ employee?.ktp_postal_code || '-' }}</div>
          </div>
          <div class="md:col-span-2 text-sm font-semibold text-gray-700 mt-2">Kontak Darurat</div>
          <div>
            <label class="label">Nama</label>
            <input v-if="isEditing" v-model="form.emergency_contact_name" class="input" />
            <div v-else>{{ employee?.emergency_contact_name || '-' }}</div>
          </div>
          <div>
            <label class="label">Telepon</label>
            <input v-if="isEditing" v-model="form.emergency_contact_phone" class="input" />
            <div v-else>{{ employee?.emergency_contact_phone || '-' }}</div>
          </div>
          <div>
            <label class="label">Hubungan</label>
            <input v-if="isEditing" v-model="form.emergency_contact_rel" class="input" placeholder="Suami/Istri/Orang Tua/Saudara" />
            <div v-else>{{ employee?.emergency_contact_rel || '-' }}</div>
          </div>
        </div>

        <!-- ── KEPEGAWAIAN ─────────────────────────────────────── -->
        <div v-else-if="activeTab === 'employment'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="label">Cabang</label>
            <select v-if="isEditing" v-model="form.branch_id" class="input">
              <option :value="null">- Pilih -</option>
              <option v-for="o in branches" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <div v-else>{{ branches.find(b => b.id === employee?.branch_id)?.name || '-' }}</div>
          </div>
          <div>
            <label class="label">Lokasi Kerja</label>
            <select v-if="isEditing" v-model="form.work_location_id" class="input">
              <option :value="null">- Pilih -</option>
              <option v-for="o in workLocations" :key="o.id" :value="o.id">
                {{ o.name }} ({{ o.type }})
              </option>
            </select>
            <div v-else>{{ workLocations.find(w => w.id === employee?.work_location_id)?.name || '-' }}</div>
          </div>
          <div>
            <label class="label">Departemen</label>
            <select v-if="isEditing" v-model="form.department_id" class="input">
              <option :value="null">- Pilih -</option>
              <option v-for="o in departments" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <div v-else>{{ departments.find(d => d.id === employee?.department_id)?.name || '-' }}</div>
          </div>
          <div>
            <label class="label">Jabatan</label>
            <select v-if="isEditing" v-model="form.position_id" class="input">
              <option :value="null">- Pilih -</option>
              <option v-for="o in positions" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <div v-else>{{ positions.find(p => p.id === employee?.position_id)?.name || '-' }}</div>
          </div>
          <div>
            <label class="label">Job Grade</label>
            <select v-if="isEditing" v-model="form.job_grade_id" class="input">
              <option :value="null">- Pilih -</option>
              <option v-for="o in jobGrades" :key="o.id" :value="o.id">{{ o.name }} (Lvl {{ o.level }})</option>
            </select>
            <div v-else>{{ jobGrades.find(g => g.id === employee?.job_grade_id)?.name || '-' }}</div>
          </div>
          <div>
            <label class="label">Atasan Langsung (untuk approval)</label>
            <select v-if="isEditing" v-model="form.supervisor_id" class="input">
              <option :value="null">- Pilih -</option>
              <option v-for="o in employeesList" :key="o.id" :value="o.id" :disabled="o.id === Number(props.employeeId)">
                {{ o.full_name }} ({{ o.employee_number }})
              </option>
            </select>
            <div v-else>{{ employeesList.find(e => e.id === employee?.supervisor_id)?.full_name || '-' }}</div>
          </div>
          <div>
            <label class="label">Manager</label>
            <select v-if="isEditing" v-model="form.manager_id" class="input">
              <option :value="null">- Pilih -</option>
              <option v-for="o in employeesList" :key="o.id" :value="o.id" :disabled="o.id === Number(props.employeeId)">
                {{ o.full_name }} ({{ o.employee_number }})
              </option>
            </select>
            <div v-else>{{ employeesList.find(e => e.id === employee?.manager_id)?.full_name || '-' }}</div>
          </div>
          <div>
            <label class="label">Status Kepegawaian</label>
            <select v-if="isEditing" v-model="form.employment_status" class="input">
              <option v-for="s in ['PKWT','PKWTT','PROBATION','HARIAN','MAGANG','OUTSOURCING']" :key="s" :value="s">{{ s }}</option>
            </select>
            <div v-else>{{ employee?.employment_status || '-' }}</div>
          </div>
          <div>
            <label class="label">Tipe Pekerja</label>
            <select v-if="isEditing" v-model="form.employment_type" class="input">
              <option v-for="s in ['FULL_TIME','PART_TIME','CONTRACT','INTERN']" :key="s" :value="s">{{ s }}</option>
            </select>
            <div v-else>{{ employee?.employment_type || '-' }}</div>
          </div>
          <div>
            <label class="label">Tanggal Bergabung</label>
            <input v-if="isEditing" v-model="form.join_date" type="date" class="input" required />
            <div v-else>{{ employee?.join_date ? fmtDate(employee.join_date) : '-' }}</div>
          </div>
          <div>
            <label class="label">Akhir Probation</label>
            <input v-if="isEditing" v-model="form.probation_end_date" type="date" class="input" />
            <div v-else>{{ employee?.probation_end_date ? fmtDate(employee.probation_end_date) : '-' }}</div>
          </div>
          <div>
            <label class="label">Akhir Kontrak</label>
            <input v-if="isEditing" v-model="form.contract_end_date" type="date" class="input" />
            <div v-else>{{ employee?.contract_end_date ? fmtDate(employee.contract_end_date) : '-' }}</div>
          </div>
        </div>

        <!-- ── APPROVAL ────────────────────────────────────────── -->
        <div v-else-if="activeTab === 'approval'" class="space-y-4">
          <p class="text-sm text-gray-600">
            Pola persetujuan default diatur per perusahaan di menu <em>Admin → Approval Chain</em>.
            Override di sini hanya jika karyawan ini perlu alur khusus.
          </p>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="label">Chain Cuti</label>
              <select v-if="isEditing" v-model="form.approval_chain_leave_id" class="input">
                <option :value="null">— Default per company —</option>
                <option v-for="c in approvalChains.LEAVE" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <div v-else>{{ approvalChains.LEAVE.find(c => c.id === employee?.approval_chain_leave_id)?.name || 'Default' }}</div>
            </div>
            <div>
              <label class="label">Chain Lembur</label>
              <select v-if="isEditing" v-model="form.approval_chain_overtime_id" class="input">
                <option :value="null">— Default per company —</option>
                <option v-for="c in approvalChains.OVERTIME" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <div v-else>{{ approvalChains.OVERTIME.find(c => c.id === employee?.approval_chain_overtime_id)?.name || 'Default' }}</div>
            </div>
            <div>
              <label class="label">Chain Perubahan Absen</label>
              <select v-if="isEditing" v-model="form.approval_chain_attendance_id" class="input">
                <option :value="null">— Default per company —</option>
                <option v-for="c in approvalChains.ATTENDANCE_CHANGE" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <div v-else>{{ approvalChains.ATTENDANCE_CHANGE.find(c => c.id === employee?.approval_chain_attendance_id)?.name || 'Default' }}</div>
            </div>
          </div>
          <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-900">
            <strong>Approver step pertama saat ini:</strong>
            atasan langsung yang dipilih di tab Kepegawaian
            <span class="font-medium">
              ({{ employeesList.find(e => e.id === form.supervisor_id)?.full_name || '— belum diset —' }})
            </span>.
            Pastikan supervisor sudah diset agar pengajuan tidak nyangkut.
          </div>
        </div>

        <!-- ── GAJI & PAJAK ────────────────────────────────────── -->
        <div v-else-if="activeTab === 'salary'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="label">Gaji Pokok</label>
            <input v-if="isEditing" v-model.number="form.basic_salary" type="number" min="0" class="input" />
            <div v-else>{{ fmtCurrency(employee?.basic_salary) }}</div>
          </div>
          <div>
            <label class="label">Metode Pajak</label>
            <select v-if="isEditing" v-model="form.tax_method" class="input">
              <option value="GROSS">Gross</option>
              <option value="NETT">Nett</option>
              <option value="GROSS_UP">Gross Up</option>
            </select>
            <div v-else>{{ employee?.tax_method || '-' }}</div>
          </div>
          <div>
            <label class="label">Bank</label>
            <input v-if="isEditing" v-model="form.bank_name" class="input" />
            <div v-else>{{ employee?.bank_name || '-' }}</div>
          </div>
          <div>
            <label class="label">No. Rekening <span v-if="isEditing" class="text-xs text-gray-500">(terenkripsi)</span></label>
            <input v-if="isEditing" v-model="form.bank_account" class="input" />
            <div v-else class="font-mono">{{ employee?.bank_account || '-' }}</div>
          </div>
          <div>
            <label class="label">Pemilik Rekening</label>
            <input v-if="isEditing" v-model="form.bank_account_holder" class="input" />
            <div v-else>{{ employee?.bank_account_holder || '-' }}</div>
          </div>
        </div>

        <!-- ── ALAMAT DOMISILI ─────────────────────────────────── -->
        <div v-else-if="activeTab === 'address'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="label">Alamat Domisili</label>
            <textarea v-if="isEditing" v-model="form.address" class="input" rows="2"></textarea>
            <div v-else>{{ employee?.address || '-' }}</div>
          </div>
          <div>
            <label class="label">Provinsi</label>
            <input v-if="isEditing" v-model="form.province" class="input" />
            <div v-else>{{ employee?.province || '-' }}</div>
          </div>
          <div>
            <label class="label">Kota</label>
            <input v-if="isEditing" v-model="form.city" class="input" />
            <div v-else>{{ employee?.city || '-' }}</div>
          </div>
          <div>
            <label class="label">Kode Pos</label>
            <input v-if="isEditing" v-model="form.postal_code" class="input" />
            <div v-else>{{ employee?.postal_code || '-' }}</div>
          </div>
          <div class="md:col-span-2 text-xs text-gray-500">
            Koordinat rumah dipakai untuk menghitung tunjangan jarak ke cabang. Isi salah satu jika perlu.
          </div>
          <div>
            <label class="label">Latitude rumah</label>
            <input v-if="isEditing" v-model="form.home_lat" type="number" step="0.0000001" class="input" placeholder="-6.2088" />
            <div v-else>{{ employee?.home_lat ?? '-' }}</div>
          </div>
          <div>
            <label class="label">Longitude rumah</label>
            <input v-if="isEditing" v-model="form.home_lng" type="number" step="0.0000001" class="input" placeholder="106.8456" />
            <div v-else>{{ employee?.home_lng ?? '-' }}</div>
          </div>
        </div>

        <!-- ── TUNJANGAN ───────────────────────────────────────── -->
        <div v-else-if="activeTab === 'allowances'">
          <div class="flex items-center justify-between mb-3">
            <p class="text-sm text-gray-600">
              Snapshot tunjangan dihitung otomatis dari jarak rumah ↔ cabang & job grade.
            </p>
            <button v-if="canEdit && !isCreating" class="btn-secondary text-sm" @click="recomputeAllowance">
              Hitung Ulang
            </button>
          </div>
          <table class="table">
            <thead>
              <tr>
                <th>Tipe</th><th>Cabang</th><th>Grade</th><th>Jarak</th>
                <th>Threshold</th><th>Eligible</th><th>Jumlah</th><th>Catatan</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="allowances.length === 0">
                <td colspan="8" class="text-center py-4 text-gray-400">Belum ada konfigurasi tunjangan</td>
              </tr>
              <tr v-for="a in allowances" :key="a.id">
                <td>
                  <div class="font-medium">{{ a.type_name }}</div>
                  <div class="text-xs text-gray-500">{{ a.type_code }}</div>
                </td>
                <td>{{ a.branch_name || '—' }}</td>
                <td>{{ a.grade_name || '—' }}</td>
                <td>{{ a.distance_km !== null ? a.distance_km + ' km' : '-' }}</td>
                <td>{{ a.threshold_km ? a.threshold_km + ' km' : '-' }}</td>
                <td><span :class="a.eligible ? 'badge-green' : 'badge-gray'">{{ a.eligible ? 'Ya' : 'Tidak' }}</span></td>
                <td class="font-semibold">{{ fmtCurrency(a.amount) }}</td>
                <td class="text-xs text-gray-600">{{ a.note || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- ── KONTRAK ─────────────────────────────────────────── -->
        <div v-else-if="activeTab === 'contracts'" class="space-y-6">
          <div>
            <h3 class="font-semibold mb-2">Kontrak Kerja (entri data)</h3>
            <table class="table">
              <thead>
                <tr><th>No</th><th>Tipe</th><th>Mulai</th><th>Berakhir</th><th>Gaji</th></tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-if="contracts.length === 0">
                  <td colspan="5" class="text-center py-4 text-gray-400">Belum ada kontrak</td>
                </tr>
                <tr v-for="c in contracts" :key="c.id">
                  <td class="font-mono">{{ c.contract_number }}</td>
                  <td><span class="badge-blue">{{ c.contract_type }}</span></td>
                  <td>{{ fmtDate(c.start_date) }}</td>
                  <td>{{ fmtDate(c.end_date) }}</td>
                  <td>{{ fmtCurrency(c.basic_salary) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div>
            <div class="flex items-center justify-between mb-2">
              <div>
                <h3 class="font-semibold">Dokumen Kontrak (digenerate dari template)</h3>
                <p class="text-xs text-gray-500">Snapshot HTML kontrak yang sudah diterbitkan / siap kirim.</p>
              </div>
              <button v-if="canEdit && !isCreating" class="btn-primary text-sm" @click="openGenerateContract">
                + Generate Kontrak
              </button>
            </div>
            <table class="table">
              <thead>
                <tr><th>No Kontrak</th><th>Subject</th><th>Template</th><th>Status</th><th>Dibuat</th><th></th></tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-if="generatedContracts.length === 0">
                  <td colspan="6" class="text-center py-4 text-gray-400">Belum ada kontrak digenerate</td>
                </tr>
                <tr v-for="g in generatedContracts" :key="g.id">
                  <td class="font-mono">{{ g.contract_number }}</td>
                  <td>{{ g.subject || '-' }}</td>
                  <td>{{ g.template_name || '-' }} <span class="text-xs text-gray-500">({{ g.template_type }})</span></td>
                  <td><span class="badge-blue">{{ g.status }}</span></td>
                  <td>{{ fmtDate(g.created_at) }}</td>
                  <td class="text-right">
                    <button class="btn-secondary text-xs" @click="openGeneratedContract(g)">Buka</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- ── SALDO CUTI ──────────────────────────────────────── -->
        <div v-else-if="activeTab === 'leave'">
          <table class="table">
            <thead><tr><th>Tipe</th><th>Alokasi</th><th>Terpakai</th><th>Sisa</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="balances.length === 0"><td colspan="4" class="text-center py-4 text-gray-400">Belum ada saldo cuti</td></tr>
              <tr v-for="b in balances" :key="b.id">
                <td>{{ b.name }}</td>
                <td>{{ b.allocated }}</td>
                <td>{{ b.used }}</td>
                <td class="font-semibold">{{ b.remaining }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- ── RIWAYAT & ASET (Sprint 3 item H) ─────────────────── -->
        <div v-else-if="activeTab === 'history'" class="space-y-6">
          <div v-if="historyLoading" class="text-center py-8 text-gray-400">Memuat riwayat…</div>
          <div v-else-if="historyError" class="rounded-md p-3 bg-red-50 text-red-700 text-sm">
            {{ historyError }}
            <button class="ml-2 underline" @click="historyLoaded = false; loadHistoryTab()">Coba lagi</button>
          </div>
          <template v-else>
            <!-- Aset Aktif -->
            <section>
              <div class="flex items-center justify-between mb-2">
                <h3 class="font-semibold">Aset Aktif & Histori</h3>
                <span class="text-xs text-gray-500">{{ historyAssets.length }} aset</span>
              </div>
              <table v-if="historyAssets.length" class="table">
                <thead>
                  <tr>
                    <th>Kode</th><th>Nama Aset</th><th>Kategori</th>
                    <th>Mulai</th><th>Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="a in historyAssets" :key="a.id">
                    <td class="font-mono">{{ a.asset_code || a.code || '-' }}</td>
                    <td>
                      <div class="font-medium">{{ a.asset_name || a.name || '-' }}</div>
                      <div v-if="a.serial_number" class="text-xs text-gray-500">SN: {{ a.serial_number }}</div>
                    </td>
                    <td>{{ a.category || '-' }}</td>
                    <td>{{ fmtDateOrDash(a.assigned_at || a.start_date) }}</td>
                    <td>
                      <span v-if="a.returned_at" class="badge-gray">Dikembalikan</span>
                      <span v-else class="badge-green">Aktif</span>
                    </td>
                  </tr>
                </tbody>
              </table>
              <p v-else class="text-sm text-gray-400 italic">Belum ada aset yang dipinjamkan ke karyawan ini.</p>
            </section>

            <!-- Surat Peringatan -->
            <section>
              <div class="flex items-center justify-between mb-2">
                <h3 class="font-semibold">Surat Peringatan / Disciplinary</h3>
                <span class="text-xs text-gray-500">
                  Aktif: {{ historyDisciplinary?.active_count ?? 0 }}
                  · Total: {{ historyDisciplinary?.total_count ?? 0 }}
                </span>
              </div>
              <div v-if="historyDisciplinary?.active_count > 0"
                class="mb-2 rounded-md p-2 bg-amber-50 text-amber-800 text-xs">
                <strong>Level tertinggi aktif:</strong>
                {{ historyDisciplinary?.highest_active_level || '-' }}
                · berlaku sampai {{ fmtDateOrDash(historyDisciplinary?.highest_active_until) }}
              </div>
              <table v-if="(historyDisciplinary?.records || []).length" class="table">
                <thead>
                  <tr>
                    <th>No SP</th><th>Level</th><th>Kategori</th>
                    <th>Tanggal</th><th>Berlaku s.d</th><th>Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="r in historyDisciplinary.records" :key="r.id">
                    <td class="font-mono">{{ r.record_number || r.number || '-' }}</td>
                    <td>
                      <span :class="r.level === 'SP3' || r.level === 'PHK'
                        ? 'badge-red' : (r.level === 'SP2' ? 'badge-yellow' : 'badge-blue')">
                        {{ r.level }}
                      </span>
                    </td>
                    <td>{{ r.category_name || r.category || '-' }}</td>
                    <td>{{ fmtDateOrDash(r.issue_date || r.created_at) }}</td>
                    <td>{{ fmtDateOrDash(r.valid_until || r.expires_at) }}</td>
                    <td>
                      <span v-if="r.status === 'ACTIVE'" class="badge-yellow">Aktif</span>
                      <span v-else-if="r.status === 'EXPIRED'" class="badge-gray">Kadaluarsa</span>
                      <span v-else-if="r.status === 'REVOKED'" class="badge-gray">Dicabut</span>
                      <span v-else class="badge-gray">{{ r.status || '-' }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
              <p v-else class="text-sm text-gray-400 italic">
                Karyawan belum pernah menerima surat peringatan.
              </p>
            </section>

            <!-- Onboarding / Offboarding Tasks -->
            <section>
              <div class="flex items-center justify-between mb-2">
                <h3 class="font-semibold">Progress Tugas (Onboarding / Offboarding)</h3>
                <span class="text-xs text-gray-500">{{ historyTasks.length }} task</span>
              </div>
              <table v-if="historyTasks.length" class="table">
                <thead>
                  <tr>
                    <th>Tipe</th><th>Tugas</th><th>PIC</th>
                    <th>Tenggat</th><th>Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="t in historyTasks" :key="t.id">
                    <td>
                      <span :class="t.task_type === 'OFFBOARD' ? 'badge-red' : 'badge-blue'">
                        {{ t.task_type || '-' }}
                      </span>
                    </td>
                    <td>{{ t.title || t.name || '-' }}</td>
                    <td>{{ t.assignee_name || '—' }}</td>
                    <td>{{ fmtDateOrDash(t.due_date) }}</td>
                    <td>
                      <span v-if="t.status === 'DONE' || t.completed_at" class="badge-green">Selesai</span>
                      <span v-else-if="t.status === 'IN_PROGRESS'" class="badge-blue">Berjalan</span>
                      <span v-else-if="t.due_date && new Date(t.due_date) < new Date()" class="badge-red">Overdue</span>
                      <span v-else class="badge-gray">{{ t.status || 'Pending' }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
              <p v-else class="text-sm text-gray-400 italic">
                Belum ada tugas onboarding/offboarding di-assign.
              </p>
            </section>
          </template>
        </div>
      </div>

      <!-- Footer -->
      <div class="px-5 py-3 border-t border-gray-200 flex justify-end gap-2 bg-gray-50">
        <button v-if="isEditing" type="button" class="btn-secondary" @click="cancelEdit" :disabled="saving">
          {{ isCreating ? 'Batal' : 'Batal Edit' }}
        </button>
        <button v-if="!isEditing" type="button" class="btn-secondary" @click="emit('close')">Tutup</button>
        <button v-if="isEditing" type="button" class="btn-primary" :disabled="saving" @click="save">
          {{ saving ? 'Menyimpan...' : (isCreating ? 'Simpan' : 'Simpan Perubahan') }}
        </button>
      </div>
    </div>

    <!-- ── GENERATE CONTRACT MODAL ───────────────────────────── -->
    <div v-if="showGenerateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[92vh] flex flex-col">
        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
          <h3 class="font-semibold">Generate Kontrak untuk {{ employee?.full_name }}</h3>
          <button class="text-gray-400 hover:text-gray-600 text-2xl leading-none px-2"
            @click="showGenerateModal = false">&times;</button>
        </div>
        <div class="flex-1 overflow-y-auto p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-3">
            <div>
              <label class="label">Template</label>
              <select v-model="generateForm.template_id" class="input">
                <option v-for="t in contractTemplates" :key="t.id" :value="t.id">
                  {{ t.name }} ({{ t.contract_type }})
                </option>
              </select>
            </div>
            <div>
              <label class="label">Kaitkan ke kontrak (opsional)</label>
              <select v-model="generateForm.employee_contract_id" class="input">
                <option :value="null">— Tidak terkait —</option>
                <option v-for="c in contracts" :key="c.id" :value="c.id">
                  {{ c.contract_number }} - {{ c.contract_type }}
                </option>
              </select>
            </div>
            <div>
              <label class="label">Subject (opsional)</label>
              <input v-model="generateForm.subject" class="input" placeholder="Mis. Kontrak Probation 3 bulan" />
            </div>
            <div class="flex gap-2">
              <button class="btn-secondary text-sm" @click="previewContract" :disabled="previewLoading">
                {{ previewLoading ? 'Memuat...' : 'Pratinjau' }}
              </button>
              <button class="btn-primary text-sm" @click="submitGenerateContract" :disabled="generating">
                {{ generating ? 'Generating...' : 'Generate & Simpan' }}
              </button>
            </div>
          </div>
          <div class="border border-gray-200 rounded p-3 bg-gray-50 overflow-y-auto max-h-[60vh]">
            <div v-if="!previewHtml" class="text-sm text-gray-500 text-center py-12">
              Klik "Pratinjau" untuk melihat hasil render template.
            </div>
            <div v-else class="prose prose-sm max-w-none" v-html="previewHtml"></div>
          </div>
        </div>
        <div class="px-5 py-3 border-t border-gray-200 flex justify-end gap-2 bg-gray-50">
          <button class="btn-secondary" @click="showGenerateModal = false">Tutup</button>
        </div>
      </div>
    </div>
  </div>
</template>