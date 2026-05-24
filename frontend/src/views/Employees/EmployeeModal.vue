<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import SearchableSelect from '@/components/SearchableSelect.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import {
  IdentificationIcon, UserGroupIcon, BriefcaseIcon, ShieldCheckIcon,
  BanknotesIcon, MapPinIcon, GiftIcon, DocumentTextIcon, CalendarDaysIcon,
  ClockIcon, EnvelopeIcon, PhoneIcon, BuildingOffice2Icon, AcademicCapIcon,
  XMarkIcon, PencilSquareIcon, TrashIcon, EyeIcon, ArrowPathIcon,
  CheckCircleIcon,
} from '@heroicons/vue/24/outline'

/**
 * EmployeeModal — redesigned (Sprint 24+).
 *
 * Layout:
 *   - Header: avatar + nama + summary singkat (NIP, dept, jabatan, status)
 *   - Sidebar tabs (kiri) dengan ikon — lebih scan-able dari horizontal scroll
 *   - Body: card-based sections dengan visual hierarchy
 *   - View mode: definition-list style (label + value pairs) yang lebih clean
 *   - Edit mode: form fields tetap, tapi dengan spacing yang lebih nyaman
 *
 * Tabs di-group ke 3 cluster:
 *   1. Personal: Identitas, KTP & Keluarga, Alamat
 *   2. Pekerjaan: Kepegawaian, Approval, Tunjangan, Kontrak, Saldo Cuti
 *   3. Finansial: Gaji & Pajak
 *   + Riwayat & Aset (kalau editing existing employee)
 */

const props = defineProps({
  employeeId: { type: [Number, String, null], default: null },
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

// History tab state (lazy-loaded)
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
  employee_number: '', first_name: '', last_name: '', full_name: '',
  gender: '', marital_status: 'TK0', ptkp_status: 'TK0',
  email: '', phone: '', date_of_birth: '', place_of_birth: '',
  address: '', province: '', city: '', postal_code: '',
  home_lat: '', home_lng: '',
  nik: '', npwp: '', bpjs_kesehatan_no: '', bpjs_tk_no: '',
  kk_number: '', mother_name: '', father_name: '',
  citizenship: 'WNI', ethnicity: '', education_level: '',
  marital_certificate_no: '', religion: '', blood_type: '',
  ktp_address: '', ktp_rt_rw: '', ktp_kelurahan: '', ktp_kecamatan: '',
  ktp_city: '', ktp_province: '', ktp_postal_code: '',
  bank_name: '', bank_account: '', bank_account_holder: '',
  branch_id: null, department_id: null, position_id: null, job_grade_id: null,
  manager_id: null, supervisor_id: null, work_location_id: null,
  approval_chain_leave_id: null, approval_chain_overtime_id: null,
  approval_chain_attendance_id: null,
  employment_status: 'PKWTT', employment_type: 'FULL_TIME',
  join_date: new Date().toISOString().slice(0, 10),
  probation_end_date: '', contract_end_date: '',
  basic_salary: 0, tax_method: 'GROSS', pph21_method: 'TER',
  emergency_contact_name: '', emergency_contact_phone: '', emergency_contact_rel: '',
  is_active: 1, create_user_account: false,
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
    if (ctr.status === 'fulfilled') {
      const raw = ctr.value.data?.data
      contracts.value = Array.isArray(raw) ? raw.filter(Boolean) : []
    } else { console.warn('Gagal load employee_contracts:', ctr.reason?.response?.data || ctr.reason) }
    if (alw.status === 'fulfilled') allowances.value = alw.value.data.data || []
    if (gen.status === 'fulfilled') {
      const raw = gen.value.data?.data
      generatedContracts.value = Array.isArray(raw) ? raw.filter(Boolean) : []
    } else { console.warn('Gagal load generated_contracts:', gen.reason?.response?.data || gen.reason) }
  } catch (e) {
    toast.error('Gagal memuat data karyawan')
  } finally {
    loading.value = false
  }
}

async function loadContractData() {
  if (! props.employeeId) return
  try {
    const [ctr, gen] = await Promise.all([
      api.get(`/employees/${props.employeeId}/contracts`),
      api.get('/generated-contracts', { params: { employee_id: props.employeeId } }),
    ])
    const rawCtr = ctr.data?.data
    const rawGen = gen.data?.data
    console.log('DEBUG contracts API:', rawCtr, 'type:', typeof rawCtr, 'isArray:', Array.isArray(rawCtr))
    console.log('DEBUG generated API:', rawGen, 'type:', typeof rawGen, 'isArray:', Array.isArray(rawGen))
    contracts.value = Array.isArray(rawCtr) ? rawCtr.filter(Boolean) : []
    generatedContracts.value = Array.isArray(rawGen) ? rawGen.filter(Boolean) : []
  } catch (e) {
    toast.error('Gagal memuat data kontrak: ' + (e.response?.data?.message || e.message))
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

// ─── Tab structure: grouped + iconified ─────────────────────────
const tabGroups = computed(() => {
  const groups = [
    {
      label: 'Personal',
      tabs: [
        { id: 'general',    label: 'Identitas',       icon: IdentificationIcon },
        { id: 'ktp',        label: 'KTP & Keluarga',  icon: UserGroupIcon },
        { id: 'address',    label: 'Alamat',          icon: MapPinIcon },
      ],
    },
    {
      label: 'Pekerjaan',
      tabs: [
        { id: 'employment', label: 'Kepegawaian',     icon: BriefcaseIcon },
        { id: 'approval',   label: 'Approval Chain',  icon: ShieldCheckIcon },
        { id: 'allowances', label: 'Tunjangan',       icon: GiftIcon },
        { id: 'contracts',  label: 'Kontrak',         icon: DocumentTextIcon },
        { id: 'leave',      label: 'Saldo Cuti',      icon: CalendarDaysIcon },
      ],
    },
    {
      label: 'Finansial',
      tabs: [
        { id: 'salary',     label: 'Gaji & Pajak',    icon: BanknotesIcon },
      ],
    },
  ]
  if (! isCreating.value) {
    groups.push({
      label: 'Riwayat',
      tabs: [
        { id: 'history',    label: 'Aset & SP',       icon: ClockIcon },
      ],
    })
  }
  return groups
})

const activeTab = ref('general')
watch(activeTab, (val) => {
  if (val === 'history') loadHistoryTab()
  if (val === 'contracts') loadContractData()
})

const allTabs = computed(() => tabGroups.value.flatMap(g => g.tabs))
const currentTabLabel = computed(() => allTabs.value.find(t => t.id === activeTab.value)?.label || '')

// Display helpers
const initials = computed(() => {
  const name = employee.value?.full_name || ''
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map(s => s[0]).join('').toUpperCase()
})
const departmentName = computed(() => departments.value.find(d => d.id === employee.value?.department_id)?.name || '—')
const positionName = computed(() => positions.value.find(p => p.id === employee.value?.position_id)?.name || '—')
const branchName = computed(() => branches.value.find(b => b.id === employee.value?.branch_id)?.name || '—')

function fmtDateOrDash(v) {
  if (! v) return '—'
  return new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function statusBadgeClass(s) {
  switch (s) {
    case 'PKWTT':      return 'bg-emerald-100 text-emerald-700 border-emerald-200'
    case 'PKWT':       return 'bg-blue-100 text-blue-700 border-blue-200'
    case 'PROBATION':  return 'bg-amber-100 text-amber-700 border-amber-200'
    case 'HARIAN':     return 'bg-slate-100 text-slate-700 border-slate-200'
    case 'MAGANG':     return 'bg-purple-100 text-purple-700 border-purple-200'
    case 'OUTSOURCING':return 'bg-pink-100 text-pink-700 border-pink-200'
    default:           return 'bg-gray-100 text-gray-700 border-gray-200'
  }
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-center justify-center p-3 sm:p-6 bg-black/60 backdrop-blur-sm"
       @click.self="emit('close')">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-6xl h-[86vh] flex flex-col overflow-hidden">
      <!-- ─── Header ──────────────────────────────────────────── -->
      <div class="border-b border-gray-200 bg-gradient-to-r from-primary-50/40 via-white to-white">
        <div class="px-6 py-4 flex items-start justify-between gap-4">
          <div class="flex items-start gap-4 min-w-0 flex-1">
            <!-- Avatar circle -->
            <div class="shrink-0 w-14 h-14 rounded-full bg-gradient-to-br from-primary-500 to-primary-700 text-white flex items-center justify-center text-lg font-bold shadow-md">
              <span v-if="isCreating">+</span>
              <span v-else-if="employee?.avatar_url">
                <img :src="employee.avatar_url" :alt="employee.full_name" class="w-14 h-14 rounded-full object-cover" />
              </span>
              <span v-else>{{ initials || '?' }}</span>
            </div>

            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2 flex-wrap">
                <h2 class="text-xl font-bold text-gray-900 truncate">
                  <span v-if="isCreating">Tambah Karyawan Baru</span>
                  <span v-else-if="employee">{{ employee.full_name }}</span>
                  <span v-else class="text-gray-400">Memuat…</span>
                </h2>
                <span v-if="employee && !isCreating"
                      class="text-xs text-gray-500 font-mono px-2 py-0.5 rounded bg-gray-100">
                  {{ employee.employee_number }}
                </span>
                <span v-if="employee && !isCreating"
                      :class="['text-xs px-2 py-0.5 rounded-full border font-medium',
                        employee.is_active
                          ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                          : 'bg-gray-100 text-gray-500 border-gray-200']">
                  {{ employee.is_active ? '● Aktif' : '○ Non-aktif' }}
                </span>
                <span v-if="employee && !isCreating"
                      :class="['text-xs px-2 py-0.5 rounded-full border font-medium',
                        statusBadgeClass(employee.employment_status)]">
                  {{ employee.employment_status }}
                </span>
                <span v-if="!isCreating && internalMode === 'edit'"
                      class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 border border-amber-200 font-medium">
                  ✎ Mode Edit
                </span>
              </div>

              <!-- Subtitle row — kontak + jabatan -->
              <div v-if="employee && !isCreating" class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                <span v-if="employee.email" class="inline-flex items-center gap-1">
                  <EnvelopeIcon class="w-3.5 h-3.5" /> {{ employee.email }}
                </span>
                <span v-if="employee.phone" class="inline-flex items-center gap-1">
                  <PhoneIcon class="w-3.5 h-3.5" /> {{ employee.phone }}
                </span>
                <span class="inline-flex items-center gap-1">
                  <BuildingOffice2Icon class="w-3.5 h-3.5" /> {{ branchName }}
                </span>
                <span class="inline-flex items-center gap-1">
                  <BriefcaseIcon class="w-3.5 h-3.5" /> {{ positionName }}
                  <span v-if="departmentName !== '—'" class="text-gray-400">· {{ departmentName }}</span>
                </span>
                <span v-if="employee.join_date" class="inline-flex items-center gap-1">
                  <CalendarDaysIcon class="w-3.5 h-3.5" /> Bergabung {{ fmtDate(employee.join_date) }}
                </span>
              </div>
              <p v-else-if="isCreating" class="mt-1.5 text-xs text-gray-500">
                Lengkapi data identitas, kepegawaian, dan gaji di bawah, lalu klik <em>Simpan</em>.
              </p>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-2 shrink-0">
            <button v-if="!isCreating && internalMode === 'view' && canEdit"
                    @click="startEdit" class="btn-primary text-sm inline-flex items-center gap-1">
              <PencilSquareIcon class="w-4 h-4" /> Edit
            </button>
            <button v-if="!isCreating && internalMode === 'view' && canDelete && employee?.is_active"
                    @click="deactivateEmployee"
                    class="btn-secondary text-sm inline-flex items-center gap-1 text-red-600 hover:bg-red-50 hover:border-red-200">
              <TrashIcon class="w-4 h-4" /> Nonaktifkan
            </button>
            <button @click="emit('close')"
                    class="p-2 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition"
                    aria-label="Tutup">
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>
        </div>
      </div>

      <!-- ─── Body: sidebar + content ─────────────────────────── -->
      <div class="flex-1 flex min-h-0">
        <!-- Sidebar tab navigation (kiri) -->
        <aside class="hidden md:block w-56 shrink-0 border-r border-gray-200 bg-gray-50/60 overflow-y-auto py-3">
          <nav class="px-2 space-y-4">
            <div v-for="group in tabGroups" :key="group.label">
              <p class="px-2 text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1">
                {{ group.label }}
              </p>
              <button v-for="t in group.tabs" :key="t.id"
                      @click="activeTab = t.id"
                      :class="[
                        'w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm transition mb-0.5',
                        activeTab === t.id
                          ? 'bg-primary-100 text-primary-800 font-medium shadow-sm'
                          : 'text-gray-700 hover:bg-white hover:text-gray-900',
                      ]">
                <component :is="t.icon" class="w-4 h-4 shrink-0" />
                <span class="truncate">{{ t.label }}</span>
              </button>
            </div>
          </nav>
        </aside>

        <!-- Mobile tab dropdown (terlihat hanya di < md) -->
        <div class="md:hidden border-b border-gray-200 px-4 py-2 bg-white">
          <select v-model="activeTab" class="input text-sm">
            <optgroup v-for="group in tabGroups" :key="group.label" :label="group.label">
              <option v-for="t in group.tabs" :key="t.id" :value="t.id">{{ t.label }}</option>
            </optgroup>
          </select>
        </div>

        <!-- Tab content -->
        <div class="flex-1 overflow-y-auto bg-white">
          <div class="px-6 py-5">
            <!-- Section title — terlihat di semua viewport -->
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-100">
              <component v-if="allTabs.find(t => t.id === activeTab)?.icon"
                         :is="allTabs.find(t => t.id === activeTab).icon"
                         class="w-5 h-5 text-primary-600" />
              <h3 class="text-base font-semibold text-gray-800">{{ currentTabLabel }}</h3>
            </div>

            <div v-if="loading" class="text-center py-16 text-gray-400 text-sm">
              <ArrowPathIcon class="w-6 h-6 mx-auto mb-2 animate-spin" />
              Memuat data karyawan…
            </div>

            <!-- ── IDENTITAS ─────────────────────────────────── -->
            <div v-else-if="activeTab === 'general'" class="space-y-5">
              <!-- NIK / NIP highlighted -->
              <div class="rounded-lg border border-gray-200 bg-gradient-to-r from-primary-50/50 to-transparent p-4">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                  <div class="flex-1 min-w-0">
                    <label class="text-xs font-semibold text-gray-600 uppercase tracking-wide">
                      NIK / NIP <span class="text-gray-400 font-normal normal-case">(dipakai untuk login)</span>
                    </label>
                    <input v-if="isEditing" v-model="form.employee_number"
                           :disabled="!isCreating && !auth.isSuperAdmin"
                           class="input mt-1 font-mono" maxlength="32"
                           placeholder="Contoh: EMP-0001" />
                    <div v-else class="mt-1 font-mono text-lg font-semibold text-gray-900">
                      {{ employee?.employee_number || '—' }}
                    </div>
                  </div>
                </div>
                <p v-if="isEditing && !isCreating && !auth.isSuperAdmin" class="text-xs text-gray-500 mt-2">
                  ℹ Hanya Super Admin yang dapat mengubah NIK setelah karyawan dibuat.
                </p>
                <p v-else-if="isEditing && !isCreating && auth.isSuperAdmin" class="text-xs text-amber-700 mt-2">
                  ⚠ Mengubah NIK akan mengubah cara karyawan login. Pastikan NIK baru sudah dikomunikasikan.
                </p>
              </div>

              <!-- Section: Data diri -->
              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Data Diri</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">Nama Depan</label>
                    <input v-if="isEditing" v-model="form.first_name" class="input" required />
                    <div v-else class="value">{{ employee?.first_name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Nama Belakang</label>
                    <input v-if="isEditing" v-model="form.last_name" class="input" />
                    <div v-else class="value">{{ employee?.last_name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Email</label>
                    <input v-if="isEditing" v-model="form.email" type="email" class="input" />
                    <div v-else class="value">{{ employee?.email || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Telepon</label>
                    <input v-if="isEditing" v-model="form.phone" class="input" />
                    <div v-else class="value">{{ employee?.phone || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Jenis Kelamin</label>
                    <select v-if="isEditing" v-model="form.gender" class="input">
                      <option value="">— Pilih —</option>
                      <option value="M">Laki-laki</option>
                      <option value="F">Perempuan</option>
                    </select>
                    <div v-else class="value">
                      {{ employee?.gender === 'M' ? 'Laki-laki' : (employee?.gender === 'F' ? 'Perempuan' : '—') }}
                    </div>
                  </div>
                  <div class="field">
                    <label class="label">Tempat Lahir</label>
                    <input v-if="isEditing" v-model="form.place_of_birth" class="input" />
                    <div v-else class="value">{{ employee?.place_of_birth || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Tanggal Lahir</label>
                    <input v-if="isEditing" v-model="form.date_of_birth" type="date" class="input" />
                    <div v-else class="value">{{ employee?.date_of_birth ? fmtDate(employee.date_of_birth) : '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Agama</label>
                    <select v-if="isEditing" v-model="form.religion" class="input">
                      <option value="">— Pilih —</option>
                      <option v-for="r in ['Islam','Kristen','Katolik','Hindu','Buddha','Konghucu','Lainnya']"
                              :key="r" :value="r">{{ r }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.religion || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Golongan Darah</label>
                    <select v-if="isEditing" v-model="form.blood_type" class="input">
                      <option value="">— Pilih —</option>
                      <option v-for="b in ['A','B','AB','O','A+','A-','B+','B-','AB+','AB-','O+','O-']"
                              :key="b" :value="b">{{ b }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.blood_type || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Kewarganegaraan</label>
                    <select v-if="isEditing" v-model="form.citizenship" class="input">
                      <option value="WNI">WNI</option>
                      <option value="WNA">WNA</option>
                    </select>
                    <div v-else class="value">{{ employee?.citizenship || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Suku <span class="text-gray-400">(opsional)</span></label>
                    <input v-if="isEditing" v-model="form.ethnicity" class="input"
                           placeholder="Jawa / Sunda / Batak / dst." />
                    <div v-else class="value">{{ employee?.ethnicity || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Pendidikan Terakhir</label>
                    <select v-if="isEditing" v-model="form.education_level" class="input">
                      <option value="">— Pilih —</option>
                      <option v-for="e in ['SD','SMP','SMA','SMK','D1','D2','D3','D4','S1','S2','S3']"
                              :key="e" :value="e">{{ e }}</option>
                    </select>
                    <div v-else class="value">
                      <span v-if="employee?.education_level" class="inline-flex items-center gap-1">
                        <AcademicCapIcon class="w-4 h-4 text-gray-400" />
                        {{ employee.education_level }}
                      </span>
                      <span v-else>—</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Section: Pajak & Asuransi -->
              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Pajak & Asuransi</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">NIK KTP <span v-if="isEditing" class="text-gray-400">(terenkripsi)</span></label>
                    <input v-if="isEditing" v-model="form.nik" class="input font-mono" maxlength="16" />
                    <div v-else class="value font-mono">{{ employee?.nik || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">NPWP</label>
                    <input v-if="isEditing" v-model="form.npwp" class="input font-mono" />
                    <div v-else class="value font-mono">{{ employee?.npwp || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Status PTKP</label>
                    <select v-if="isEditing" v-model="form.ptkp_status" class="input">
                      <option v-for="c in ['TK0','TK1','TK2','TK3','K0','K1','K2','K3','HB0','HB1','HB2','HB3']"
                              :key="c" :value="c">{{ c }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.ptkp_status || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Status Pernikahan</label>
                    <select v-if="isEditing" v-model="form.marital_status" class="input">
                      <option v-for="c in ['TK0','TK1','TK2','TK3','K0','K1','K2','K3','HB0','HB1','HB2','HB3']"
                              :key="c" :value="c">{{ c }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.marital_status || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">No. BPJS Kesehatan</label>
                    <input v-if="isEditing" v-model="form.bpjs_kesehatan_no" class="input font-mono" />
                    <div v-else class="value font-mono">{{ employee?.bpjs_kesehatan_no || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">No. BPJS Ketenagakerjaan</label>
                    <input v-if="isEditing" v-model="form.bpjs_tk_no" class="input font-mono" />
                    <div v-else class="value font-mono">{{ employee?.bpjs_tk_no || '—' }}</div>
                  </div>
                </div>
              </div>

              <!-- Buat akun login otomatis (saat create) -->
              <div v-if="isCreating" class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                <label class="flex items-start gap-3 cursor-pointer">
                  <input type="checkbox" v-model="form.create_user_account" class="mt-0.5" />
                  <div class="text-sm text-blue-900">
                    <div class="font-medium">Buat akun login otomatis</div>
                    <div class="text-xs text-blue-700 mt-0.5">
                      Email di atas akan dipakai sebagai username login. Password sementara akan dikirim via email.
                    </div>
                  </div>
                </label>
              </div>
            </div>

            <!-- ── KTP & KELUARGA ────────────────────────────── -->
            <div v-else-if="activeTab === 'ktp'" class="space-y-5">
              <div class="rounded-md p-3 bg-gray-50 border border-gray-200 text-xs text-gray-600">
                ℹ Alamat sesuai KTP sering berbeda dengan alamat domisili. Bagian ini dipakai untuk dokumen kontrak & pendaftaran BPJS.
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Dokumen Keluarga</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">No. Kartu Keluarga (KK)</label>
                    <input v-if="isEditing" v-model="form.kk_number" class="input font-mono" maxlength="32" />
                    <div v-else class="value font-mono">{{ employee?.kk_number || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">No. Akta Nikah / Cerai</label>
                    <input v-if="isEditing" v-model="form.marital_certificate_no" class="input" />
                    <div v-else class="value">{{ employee?.marital_certificate_no || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Nama Ibu Kandung</label>
                    <input v-if="isEditing" v-model="form.mother_name" class="input"
                           placeholder="Untuk verifikasi BPJS / bank" />
                    <div v-else class="value">{{ employee?.mother_name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Nama Ayah</label>
                    <input v-if="isEditing" v-model="form.father_name" class="input" />
                    <div v-else class="value">{{ employee?.father_name || '—' }}</div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Alamat KTP</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field md:col-span-2">
                    <label class="label">Alamat Lengkap</label>
                    <textarea v-if="isEditing" v-model="form.ktp_address" class="input" rows="2"
                              placeholder="Sesuai e-KTP"></textarea>
                    <div v-else class="value whitespace-pre-line">{{ employee?.ktp_address || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">RT/RW</label>
                    <input v-if="isEditing" v-model="form.ktp_rt_rw" class="input" placeholder="001/002" />
                    <div v-else class="value">{{ employee?.ktp_rt_rw || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Kelurahan/Desa</label>
                    <input v-if="isEditing" v-model="form.ktp_kelurahan" class="input" />
                    <div v-else class="value">{{ employee?.ktp_kelurahan || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Kecamatan</label>
                    <input v-if="isEditing" v-model="form.ktp_kecamatan" class="input" />
                    <div v-else class="value">{{ employee?.ktp_kecamatan || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Kota / Kabupaten</label>
                    <input v-if="isEditing" v-model="form.ktp_city" class="input" />
                    <div v-else class="value">{{ employee?.ktp_city || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Provinsi</label>
                    <input v-if="isEditing" v-model="form.ktp_province" class="input" />
                    <div v-else class="value">{{ employee?.ktp_province || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Kode Pos</label>
                    <input v-if="isEditing" v-model="form.ktp_postal_code" class="input" maxlength="5" />
                    <div v-else class="value">{{ employee?.ktp_postal_code || '—' }}</div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Kontak Darurat</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">Nama</label>
                    <input v-if="isEditing" v-model="form.emergency_contact_name" class="input" />
                    <div v-else class="value">{{ employee?.emergency_contact_name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Telepon</label>
                    <input v-if="isEditing" v-model="form.emergency_contact_phone" class="input" />
                    <div v-else class="value">{{ employee?.emergency_contact_phone || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Hubungan</label>
                    <input v-if="isEditing" v-model="form.emergency_contact_rel" class="input"
                           placeholder="Suami / Istri / Orang Tua" />
                    <div v-else class="value">{{ employee?.emergency_contact_rel || '—' }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── KEPEGAWAIAN ───────────────────────────────── -->
            <div v-else-if="activeTab === 'employment'" class="space-y-5">
              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Penempatan</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">Cabang</label>
                    <SearchableSelect v-if="isEditing" v-model="form.branch_id" :items="branches"
                                      value-key="id" label-key="name"
                                      :search-keys="['name','code','city']" placeholder="— Pilih —" />
                    <div v-else class="value">{{ branches.find(b => b.id === employee?.branch_id)?.name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Lokasi Kerja</label>
                    <SearchableSelect v-if="isEditing" v-model="form.work_location_id" :items="workLocations"
                                      value-key="id" label-key="name" sub-label-key="type"
                                      :search-keys="['name','type','address']" placeholder="— Pilih —">
                      <template #item="{ item }">
                        <span class="block text-sm truncate">{{ item.name }} ({{ item.type }})</span>
                      </template>
                      <template #selected="{ item }">
                        <span class="block truncate font-medium">{{ item.name }} ({{ item.type }})</span>
                      </template>
                    </SearchableSelect>
                    <div v-else class="value">{{ workLocations.find(w => w.id === employee?.work_location_id)?.name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Departemen</label>
                    <SearchableSelect v-if="isEditing" v-model="form.department_id" :items="departments"
                                      value-key="id" label-key="name"
                                      :search-keys="['name','code']" placeholder="— Pilih —" />
                    <div v-else class="value">{{ departments.find(d => d.id === employee?.department_id)?.name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Jabatan</label>
                    <SearchableSelect v-if="isEditing" v-model="form.position_id" :items="positions"
                                      value-key="id" label-key="name" sub-label-key="department_name"
                                      :search-keys="['name','code','tier','department_name']" placeholder="— Pilih —" />
                    <div v-else class="value">{{ positions.find(p => p.id === employee?.position_id)?.name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Job Grade</label>
                    <SearchableSelect v-if="isEditing" v-model="form.job_grade_id" :items="jobGrades"
                                      value-key="id" label-key="name"
                                      :search-keys="['name','code']" placeholder="— Pilih —">
                      <template #item="{ item }">
                        <span class="block text-sm truncate">{{ item.name }}
                          <span class="text-xs text-gray-500">(Lvl {{ item.level }})</span></span>
                      </template>
                      <template #selected="{ item }">
                        <span class="block truncate font-medium">{{ item.name }}
                          <span class="text-xs text-gray-500">(Lvl {{ item.level }})</span></span>
                      </template>
                    </SearchableSelect>
                    <div v-else class="value">{{ jobGrades.find(g => g.id === employee?.job_grade_id)?.name || '—' }}</div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Hierarki & Atasan</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">Atasan Langsung <span class="text-gray-400">(untuk approval)</span></label>
                    <EmployeeSearchSelect v-if="isEditing" v-model="form.supervisor_id"
                                          placeholder="— Pilih atasan —" :only-active="true" />
                    <div v-else class="value">
                      {{ employeesList.find(e => e.id === employee?.supervisor_id)?.full_name || '—' }}
                    </div>
                  </div>
                  <div class="field">
                    <label class="label">Manager</label>
                    <EmployeeSearchSelect v-if="isEditing" v-model="form.manager_id"
                                          placeholder="— Pilih manager —" :only-active="true" />
                    <div v-else class="value">
                      {{ employeesList.find(e => e.id === employee?.manager_id)?.full_name || '—' }}
                    </div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Status & Tanggal</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">Status Kepegawaian</label>
                    <select v-if="isEditing" v-model="form.employment_status" class="input">
                      <option v-for="s in ['PKWT','PKWTT','PROBATION','HARIAN','MAGANG','OUTSOURCING']"
                              :key="s" :value="s">{{ s }}</option>
                    </select>
                    <div v-else>
                      <span :class="['text-xs px-2 py-1 rounded-full border font-medium',
                                     statusBadgeClass(employee?.employment_status)]">
                        {{ employee?.employment_status || '—' }}
                      </span>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label">Tipe Pekerja</label>
                    <select v-if="isEditing" v-model="form.employment_type" class="input">
                      <option v-for="s in ['FULL_TIME','PART_TIME','CONTRACT','INTERN']" :key="s" :value="s">{{ s }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.employment_type || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Tanggal Bergabung</label>
                    <input v-if="isEditing" v-model="form.join_date" type="date" class="input" required />
                    <div v-else class="value">{{ employee?.join_date ? fmtDate(employee.join_date) : '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Akhir Probation</label>
                    <input v-if="isEditing" v-model="form.probation_end_date" type="date" class="input" />
                    <div v-else class="value">{{ employee?.probation_end_date ? fmtDate(employee.probation_end_date) : '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Akhir Kontrak</label>
                    <input v-if="isEditing" v-model="form.contract_end_date" type="date" class="input" />
                    <div v-else class="value">{{ employee?.contract_end_date ? fmtDate(employee.contract_end_date) : '—' }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── APPROVAL CHAIN ────────────────────────────── -->
            <div v-else-if="activeTab === 'approval'" class="space-y-5">
              <div class="rounded-md p-3 bg-gray-50 border border-gray-200 text-xs text-gray-600">
                ℹ Pola persetujuan default diatur per perusahaan di menu <em>Admin → Approval Chain</em>.
                Override di sini hanya jika karyawan ini perlu alur khusus.
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                <div class="field">
                  <label class="label">Chain Cuti</label>
                  <select v-if="isEditing" v-model="form.approval_chain_leave_id" class="input">
                    <option :value="null">— Default per company —</option>
                    <option v-for="c in approvalChains.LEAVE" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                  <div v-else class="value">{{ approvalChains.LEAVE.find(c => c.id === employee?.approval_chain_leave_id)?.name || 'Default' }}</div>
                </div>
                <div class="field">
                  <label class="label">Chain Lembur</label>
                  <select v-if="isEditing" v-model="form.approval_chain_overtime_id" class="input">
                    <option :value="null">— Default per company —</option>
                    <option v-for="c in approvalChains.OVERTIME" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                  <div v-else class="value">{{ approvalChains.OVERTIME.find(c => c.id === employee?.approval_chain_overtime_id)?.name || 'Default' }}</div>
                </div>
                <div class="field md:col-span-2">
                  <label class="label">Chain Perubahan Absensi</label>
                  <select v-if="isEditing" v-model="form.approval_chain_attendance_id" class="input">
                    <option :value="null">— Default per company —</option>
                    <option v-for="c in approvalChains.ATTENDANCE_CHANGE" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                  <div v-else class="value">{{ approvalChains.ATTENDANCE_CHANGE.find(c => c.id === employee?.approval_chain_attendance_id)?.name || 'Default' }}</div>
                </div>
              </div>

              <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                <div class="flex items-start gap-2">
                  <CheckCircleIcon class="w-5 h-5 shrink-0 mt-0.5" />
                  <div>
                    <p class="font-medium">Approver step pertama saat ini</p>
                    <p class="mt-0.5">
                      Atasan langsung (dari tab Kepegawaian):
                      <span class="font-semibold">
                        {{ employeesList.find(e => e.id === form.supervisor_id)?.full_name || '— belum diset —' }}
                      </span>
                    </p>
                    <p class="text-xs text-blue-700 mt-1">
                      Pastikan supervisor sudah diset agar pengajuan tidak nyangkut.
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── GAJI & PAJAK ──────────────────────────────── -->
            <div v-else-if="activeTab === 'salary'" class="space-y-5">
              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Gaji Pokok</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">Gaji Pokok (per bulan)</label>
                    <input v-if="isEditing" v-model.number="form.basic_salary" type="number" min="0" class="input" />
                    <div v-else class="value text-lg font-semibold text-gray-900">
                      {{ fmtCurrency(employee?.basic_salary) }}
                    </div>
                  </div>
                  <div class="field">
                    <label class="label">Metode Pajak</label>
                    <select v-if="isEditing" v-model="form.tax_method" class="input">
                      <option value="GROSS">Gross</option>
                      <option value="NETT">Nett</option>
                      <option value="GROSS_UP">Gross Up</option>
                    </select>
                    <div v-else class="value">{{ employee?.tax_method || '—' }}</div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Rekening Bank</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">Bank</label>
                    <input v-if="isEditing" v-model="form.bank_name" class="input" />
                    <div v-else class="value">{{ employee?.bank_name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">No. Rekening <span v-if="isEditing" class="text-gray-400">(terenkripsi)</span></label>
                    <input v-if="isEditing" v-model="form.bank_account" class="input font-mono" />
                    <div v-else class="value font-mono">{{ employee?.bank_account || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Pemilik Rekening</label>
                    <input v-if="isEditing" v-model="form.bank_account_holder" class="input" />
                    <div v-else class="value">{{ employee?.bank_account_holder || '—' }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── ALAMAT ────────────────────────────────────── -->
            <div v-else-if="activeTab === 'address'" class="space-y-5">
              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Alamat Domisili</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field md:col-span-2">
                    <label class="label">Alamat Lengkap</label>
                    <textarea v-if="isEditing" v-model="form.address" class="input" rows="2"></textarea>
                    <div v-else class="value whitespace-pre-line">{{ employee?.address || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Provinsi</label>
                    <input v-if="isEditing" v-model="form.province" class="input" />
                    <div v-else class="value">{{ employee?.province || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Kota</label>
                    <input v-if="isEditing" v-model="form.city" class="input" />
                    <div v-else class="value">{{ employee?.city || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Kode Pos</label>
                    <input v-if="isEditing" v-model="form.postal_code" class="input" />
                    <div v-else class="value">{{ employee?.postal_code || '—' }}</div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3 flex items-center gap-2">
                  <MapPinIcon class="w-4 h-4" /> Koordinat Rumah
                </h4>
                <p class="text-xs text-gray-500 mb-3">
                  Dipakai untuk menghitung tunjangan jarak ke cabang. Kosongkan kalau tidak relevan.
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">Latitude</label>
                    <input v-if="isEditing" v-model="form.home_lat" type="number" step="0.0000001"
                           class="input font-mono" placeholder="-6.2088" />
                    <div v-else class="value font-mono">{{ employee?.home_lat ?? '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">Longitude</label>
                    <input v-if="isEditing" v-model="form.home_lng" type="number" step="0.0000001"
                           class="input font-mono" placeholder="106.8456" />
                    <div v-else class="value font-mono">{{ employee?.home_lng ?? '—' }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── TUNJANGAN ─────────────────────────────────── -->
            <div v-else-if="activeTab === 'allowances'" class="space-y-4">
              <div class="flex items-center justify-between">
                <p class="text-sm text-gray-600">
                  Snapshot tunjangan dihitung otomatis dari jarak rumah ↔ cabang & job grade.
                </p>
                <button v-if="canEdit && !isCreating" class="btn-secondary text-xs inline-flex items-center gap-1"
                        @click="recomputeAllowance">
                  <ArrowPathIcon class="w-4 h-4" /> Hitung Ulang
                </button>
              </div>
              <div class="rounded-lg border border-gray-200 overflow-hidden">
                <table class="table">
                  <thead class="bg-gray-50">
                    <tr>
                      <th>Tipe</th><th>Cabang</th><th>Grade</th><th>Jarak</th>
                      <th>Threshold</th><th>Eligible</th><th class="text-right">Jumlah</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <tr v-if="allowances.length === 0">
                      <td colspan="7" class="text-center py-6 text-gray-400 text-sm">
                        Belum ada konfigurasi tunjangan
                      </td>
                    </tr>
                    <tr v-for="a in allowances" :key="a.id">
                      <td>
                        <div class="font-medium">{{ a.type_name }}</div>
                        <div class="text-xs text-gray-500 font-mono">{{ a.type_code }}</div>
                      </td>
                      <td class="text-sm">{{ a.branch_name || '—' }}</td>
                      <td class="text-sm">{{ a.grade_name || '—' }}</td>
                      <td class="text-sm">{{ a.distance_km !== null ? a.distance_km + ' km' : '—' }}</td>
                      <td class="text-sm">{{ a.threshold_km ? a.threshold_km + ' km' : '—' }}</td>
                      <td>
                        <span :class="a.eligible ? 'badge-green' : 'badge-gray'">
                          {{ a.eligible ? 'Ya' : 'Tidak' }}
                        </span>
                      </td>
                      <td class="text-right font-semibold tabular-nums">{{ fmtCurrency(a.amount) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- ── KONTRAK ───────────────────────────────────── -->
            <div v-else-if="activeTab === 'contracts'" class="space-y-6">
              <!-- Debug: tampilkan jumlah data -->
              <div class="text-xs text-gray-400 space-y-1 mb-2 p-2 bg-gray-50 rounded">
                <div>contracts length: {{ contracts?.length ?? 'N/A' }}</div>
                <div>generatedContracts length: {{ generatedContracts?.length ?? 'N/A' }}</div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Kontrak Kerja</h4>
                <div class="rounded-lg border border-gray-200 overflow-hidden">
                  <table class="table">
                    <thead class="bg-gray-50">
                      <tr><th>No</th><th>Tipe</th><th>Mulai</th><th>Berakhir</th><th class="text-right">Gaji</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                      <tr v-if="!contracts || contracts.length === 0">
                        <td colspan="5" class="text-center py-6 text-gray-400 text-sm">Belum ada kontrak</td>
                      </tr>
                      <tr v-for="(c, ci) in contracts" :key="c?.id ?? ci">
                        <td class="font-mono text-sm">{{ c?.contract_number }}</td>
                        <td><span class="badge-blue">{{ c?.contract_type }}</span></td>
                        <td class="text-sm">{{ fmtDate(c?.start_date) }}</td>
                        <td class="text-sm">{{ fmtDate(c?.end_date) }}</td>
                        <td class="text-right font-medium tabular-nums">{{ fmtCurrency(c?.basic_salary) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div>
                <div class="flex items-center justify-between mb-3">
                  <div>
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Dokumen Kontrak Generated</h4>
                    <p class="text-xs text-gray-500 mt-0.5">Snapshot HTML kontrak yang sudah diterbitkan / siap kirim.</p>
                  </div>
                  <button v-if="canEdit && !isCreating" class="btn-primary text-xs"
                          @click="openGenerateContract">
                    + Generate Kontrak
                  </button>
                </div>
                <div class="rounded-lg border border-gray-200 overflow-hidden">
                  <table class="table">
                    <thead class="bg-gray-50">
                      <tr><th>No Kontrak</th><th>Subject</th><th>Template</th><th>Status</th><th>Dibuat</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                      <tr v-if="!generatedContracts || generatedContracts.length === 0">
                        <td colspan="6" class="text-center py-6 text-gray-400 text-sm">Belum ada kontrak digenerate</td>
                      </tr>
                      <tr v-for="(g, gi) in generatedContracts" :key="g?.id ?? gi">
                        <td class="font-mono text-sm">{{ g?.contract_number }}</td>
                        <td class="text-sm">{{ g?.subject || '—' }}</td>
                        <td class="text-sm">
                          {{ g?.template_name || '—' }}
                          <span class="text-xs text-gray-500">({{ g?.template_type }})</span>
                        </td>
                        <td><span class="badge-blue">{{ g?.status }}</span></td>
                        <td class="text-sm">{{ fmtDate(g?.created_at) }}</td>
                        <td class="text-right">
                          <button class="btn-secondary text-xs" @click="openGeneratedContract(g)">
                            <EyeIcon class="w-3.5 h-3.5" /> Buka
                          </button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- ── SALDO CUTI ────────────────────────────────── -->
            <div v-else-if="activeTab === 'leave'">
              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <div v-if="balances.length === 0" class="md:col-span-3 rounded-lg border border-dashed border-gray-300 p-8 text-center text-gray-400 text-sm">
                  Belum ada saldo cuti
                </div>
                <div v-for="b in balances" :key="b.id"
                     class="rounded-lg border border-gray-200 bg-white p-4 hover:shadow-sm transition">
                  <div class="text-sm font-semibold text-gray-700 mb-2">{{ b.name }}</div>
                  <div class="flex items-baseline gap-2 mb-2">
                    <span class="text-2xl font-bold text-primary-700 tabular-nums">{{ b.remaining }}</span>
                    <span class="text-sm text-gray-500">/ {{ b.allocated }} hari</span>
                  </div>
                  <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                    <div class="bg-primary-600 h-full rounded-full transition-all"
                         :style="{ width: b.allocated ? Math.min(100, (b.remaining / b.allocated) * 100) + '%' : '0%' }"></div>
                  </div>
                  <div class="text-xs text-gray-500 mt-2">{{ b.used }} hari terpakai</div>
                </div>
              </div>
            </div>

            <!-- ── RIWAYAT & ASET ────────────────────────────── -->
            <div v-else-if="activeTab === 'history'" class="space-y-6">
              <div v-if="historyLoading" class="text-center py-8 text-gray-400 text-sm">
                <ArrowPathIcon class="w-5 h-5 mx-auto mb-2 animate-spin" />
                Memuat riwayat…
              </div>
              <div v-else-if="historyError"
                   class="rounded-md p-3 bg-red-50 border border-red-200 text-red-700 text-sm">
                {{ historyError }}
                <button class="ml-2 underline" @click="historyLoaded = false; loadHistoryTab()">Coba lagi</button>
              </div>
              <template v-else>
                <!-- Aset -->
                <section>
                  <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Aset Aktif & Histori</h4>
                    <span class="text-xs text-gray-400">{{ historyAssets.length }} aset</span>
                  </div>
                  <div v-if="historyAssets.length" class="rounded-lg border border-gray-200 overflow-hidden">
                    <table class="table">
                      <thead class="bg-gray-50">
                        <tr><th>Kode</th><th>Nama Aset</th><th>Kategori</th><th>Mulai</th><th>Status</th></tr>
                      </thead>
                      <tbody class="divide-y divide-gray-100">
                        <tr v-for="a in historyAssets" :key="a.id">
                          <td class="font-mono text-sm">{{ a.asset_code || a.code || '—' }}</td>
                          <td>
                            <div class="font-medium">{{ a.asset_name || a.name || '—' }}</div>
                            <div v-if="a.serial_number" class="text-xs text-gray-500">SN: {{ a.serial_number }}</div>
                          </td>
                          <td class="text-sm">{{ a.category || '—' }}</td>
                          <td class="text-sm">{{ fmtDateOrDash(a.assigned_at || a.start_date) }}</td>
                          <td>
                            <span v-if="a.returned_at" class="badge-gray">Dikembalikan</span>
                            <span v-else class="badge-green">Aktif</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <p v-else class="text-sm text-gray-400 italic py-2">
                    Belum ada aset yang dipinjamkan ke karyawan ini.
                  </p>
                </section>

                <!-- Disciplinary -->
                <section>
                  <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Surat Peringatan</h4>
                    <span class="text-xs text-gray-400">
                      Aktif: <strong>{{ historyDisciplinary?.active_count ?? 0 }}</strong>
                      · Total: {{ historyDisciplinary?.total_count ?? 0 }}
                    </span>
                  </div>
                  <div v-if="historyDisciplinary?.active_count > 0"
                       class="mb-3 rounded-md p-3 bg-amber-50 border border-amber-200 text-amber-800 text-xs">
                    <strong>Level tertinggi aktif:</strong>
                    {{ historyDisciplinary?.highest_active_level || '—' }}
                    · berlaku sampai {{ fmtDateOrDash(historyDisciplinary?.highest_active_until) }}
                  </div>
                  <div v-if="(historyDisciplinary?.records || []).length"
                       class="rounded-lg border border-gray-200 overflow-hidden">
                    <table class="table">
                      <thead class="bg-gray-50">
                        <tr><th>No SP</th><th>Level</th><th>Kategori</th><th>Tanggal</th><th>Berlaku s.d</th><th>Status</th></tr>
                      </thead>
                      <tbody class="divide-y divide-gray-100">
                        <tr v-for="r in historyDisciplinary.records" :key="r.id">
                          <td class="font-mono text-sm">{{ r.record_number || r.number || '—' }}</td>
                          <td>
                            <span :class="r.level === 'SP3' || r.level === 'PHK'
                                  ? 'badge-red' : (r.level === 'SP2' ? 'badge-yellow' : 'badge-blue')">
                              {{ r.level }}
                            </span>
                          </td>
                          <td class="text-sm">{{ r.category_name || r.category || '—' }}</td>
                          <td class="text-sm">{{ fmtDateOrDash(r.issue_date || r.created_at) }}</td>
                          <td class="text-sm">{{ fmtDateOrDash(r.valid_until || r.expires_at) }}</td>
                          <td>
                            <span v-if="r.status === 'ACTIVE'" class="badge-yellow">Aktif</span>
                            <span v-else-if="r.status === 'EXPIRED'" class="badge-gray">Kadaluarsa</span>
                            <span v-else-if="r.status === 'REVOKED'" class="badge-gray">Dicabut</span>
                            <span v-else class="badge-gray">{{ r.status || '—' }}</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <p v-else class="text-sm text-gray-400 italic py-2">
                    Karyawan belum pernah menerima surat peringatan.
                  </p>
                </section>

                <!-- Tasks -->
                <section>
                  <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                      Progress Onboarding / Offboarding
                    </h4>
                    <span class="text-xs text-gray-400">{{ historyTasks.length }} task</span>
                  </div>
                  <div v-if="historyTasks.length" class="rounded-lg border border-gray-200 overflow-hidden">
                    <table class="table">
                      <thead class="bg-gray-50">
                        <tr><th>Tipe</th><th>Tugas</th><th>PIC</th><th>Tenggat</th><th>Status</th></tr>
                      </thead>
                      <tbody class="divide-y divide-gray-100">
                        <tr v-for="t in historyTasks" :key="t.id">
                          <td>
                            <span :class="t.task_type === 'OFFBOARD' ? 'badge-red' : 'badge-blue'">
                              {{ t.task_type || '—' }}
                            </span>
                          </td>
                          <td>{{ t.title || t.name || '—' }}</td>
                          <td class="text-sm">{{ t.assignee_name || '—' }}</td>
                          <td class="text-sm">{{ fmtDateOrDash(t.due_date) }}</td>
                          <td>
                            <span v-if="t.status === 'DONE' || t.completed_at" class="badge-green">Selesai</span>
                            <span v-else-if="t.status === 'IN_PROGRESS'" class="badge-blue">Berjalan</span>
                            <span v-else-if="t.due_date && new Date(t.due_date) < new Date()" class="badge-red">Overdue</span>
                            <span v-else class="badge-gray">{{ t.status || 'Pending' }}</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <p v-else class="text-sm text-gray-400 italic py-2">
                    Belum ada tugas onboarding/offboarding di-assign.
                  </p>
                </section>
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- ─── Footer ──────────────────────────────────────────── -->
      <div class="px-6 py-3 border-t border-gray-200 bg-gray-50 flex justify-end gap-2">
        <button v-if="isEditing" type="button" class="btn-secondary"
                @click="cancelEdit" :disabled="saving">
          {{ isCreating ? 'Batal' : 'Batal Edit' }}
        </button>
        <button v-if="!isEditing" type="button" class="btn-secondary" @click="emit('close')">Tutup</button>
        <button v-if="isEditing" type="button" class="btn-primary"
                :disabled="saving" @click="save">
          {{ saving ? 'Menyimpan…' : (isCreating ? 'Simpan' : 'Simpan Perubahan') }}
        </button>
      </div>
    </div>

    <!-- ─── Generate Contract Modal ────────────────────────────── -->
    <div v-if="showGenerateModal"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
      @click.self="showGenerateModal = false">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl h-[80vh] flex flex-col overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <h3 class="font-semibold">Generate Kontrak — {{ employee?.full_name }}</h3>
          <button class="p-2 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-700"
                  @click="showGenerateModal = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <div class="flex-1 overflow-y-auto p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-3">
            <div class="field">
              <label class="label">Template</label>
              <select v-model="generateForm.template_id" class="input">
                <option v-for="t in contractTemplates" :key="t.id" :value="t.id">
                  {{ t.name }} ({{ t.contract_type }})
                </option>
              </select>
            </div>
            <div class="field">
              <label class="label">Kaitkan ke kontrak <span class="text-gray-400">(opsional)</span></label>
              <select v-model="generateForm.employee_contract_id" class="input">
                <option :value="null">— Tidak terkait —</option>
                <option v-for="c in contracts" :key="c.id" :value="c.id">
                  {{ c.contract_number }} - {{ c.contract_type }}
                </option>
              </select>
            </div>
            <div class="field">
              <label class="label">Subject <span class="text-gray-400">(opsional)</span></label>
              <input v-model="generateForm.subject" class="input"
                     placeholder="Mis. Kontrak Probation 3 bulan" />
            </div>
            <div class="flex gap-2 pt-2">
              <button class="btn-secondary text-sm" @click="previewContract" :disabled="previewLoading">
                {{ previewLoading ? 'Memuat…' : 'Pratinjau' }}
              </button>
              <button class="btn-primary text-sm" @click="submitGenerateContract" :disabled="generating">
                {{ generating ? 'Generating…' : 'Generate & Simpan' }}
              </button>
            </div>
          </div>
          <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 overflow-y-auto max-h-[60vh]">
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

<style scoped>
/**
 * Definition-list style untuk view mode — label kecil di atas, value
 * dengan font lebih tegas. Membantu bedakan kosong vs ada data lewat
 * em-dash placeholder yang konsisten.
 */
.field { @apply min-w-0; }
.label { @apply text-xs font-medium text-gray-500 mb-1 block; }
.value { @apply text-sm text-gray-900 min-h-[20px]; }
.value:empty::before,
.value:has(:empty)::before { content: '—'; @apply text-gray-400; }
</style>