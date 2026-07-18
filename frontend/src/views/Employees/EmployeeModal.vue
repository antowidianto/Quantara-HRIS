<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/locales'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import SearchableSelect from '@/components/SearchableSelect.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import {
  IdentificationIcon, UserGroupIcon, BriefcaseIcon, ShieldCheckIcon,
  BanknotesIcon, MapPinIcon, GiftIcon, DocumentTextIcon, CalendarDaysIcon,
  ClockIcon, EnvelopeIcon, PhoneIcon, BuildingOffice2Icon, AcademicCapIcon,
  XMarkIcon, PencilSquareIcon, TrashIcon, EyeIcon, ArrowPathIcon,
  CheckCircleIcon, ClipboardDocumentListIcon,
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
  teamView: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'saved'])

const auth = useAuthStore()
const toast = useToast()
const { t, locale } = useI18n()

const internalMode = ref(props.mode)
const employee = ref(null)
const balances = ref([])
const balanceYear = ref(new Date().getFullYear())
const contracts = ref([])
const allowances = ref([])
const loading = ref(false)
const saving = ref(false)

// Leave history (riwayat pengajuan cuti, di-load saat tab dibuka)
const leaves = ref([])
const leavesLoaded = ref(false)
const leavesLoading = ref(false)
const leavesError = ref('')

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

async function loadLeaveHistory() {
  if (! props.employeeId || leavesLoaded.value || leavesLoading.value) return
  leavesLoading.value = true
  leavesError.value = ''
  try {
    const res = await api.get('/leaves', {
      params: { employee_id: props.employeeId, per_page: 50 },
    })
    leaves.value = res.data?.data || []
    leavesLoaded.value = true
  } catch (e) {
    leavesError.value = e.response?.data?.message || t('employee_modal.load_leave_failed')
  } finally {
    leavesLoading.value = false
  }
}

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
    historyError.value = e?.message || t('employee_modal.load_history_failed')
  } finally {
    historyLoading.value = false
  }
}

const isCreating = computed(() => ! props.employeeId)
const isEditing = computed(() => internalMode.value === 'edit' || isCreating.value)

const canEdit = computed(() =>
  !props.teamView && (auth.isSuperAdmin || auth.permissions.includes('employee.update') || auth.permissions.includes('employee.create'))
)
const canDelete = computed(() =>
  !props.teamView && (auth.isSuperAdmin || auth.permissions.includes('employee.delete'))
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
  if (props.teamView) {
    const [d, p, b] = await Promise.allSettled([
      api.get('/departments', { params: { per_page: 200 } }),
      api.get('/positions', { params: { per_page: 200 } }),
      api.get('/branches', { params: { per_page: 200 } }),
    ])
    if (d.status === 'fulfilled') departments.value = d.value.data.data
    if (p.status === 'fulfilled') positions.value = p.value.data.data
    if (b.status === 'fulfilled') branches.value = b.value.data.data
    return
  }
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
    if (props.teamView) {
      const emp = await api.get(`/employees/${props.employeeId}`, { params: { team_view: 1 } })
      employee.value = emp.data.data
      form.value = { ...blankForm(), ...employee.value }
      return
    }
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
    if (bal.status === 'fulfilled') {
      // LeaveController::balance return { employee_id, year, balances: [...], today }.
      // Sebelumnya kita salah baca data.data sebagai array — itu sebabnya saldo
      // muncul kosong meski endpoint sukses 200.
      const payload = bal.value.data?.data || {}
      balances.value = Array.isArray(payload.balances) ? payload.balances : []
      if (payload.year) balanceYear.value = payload.year
    }
    if (ctr.status === 'fulfilled') {
      const raw = ctr.value.data?.data
      contracts.value = Array.isArray(raw) ? raw.filter(Boolean) : []
    }
    if (alw.status === 'fulfilled') allowances.value = alw.value.data.data || []
    if (gen.status === 'fulfilled') {
      const raw = gen.value.data?.data
      generatedContracts.value = Array.isArray(raw) ? raw.filter(Boolean) : []
    }
    // Reset cache riwayat cuti supaya tab di-refetch saat dibuka.
    leavesLoaded.value = false
    leaves.value = []
  } catch (e) {
    toast.error(t('employee_modal.load_employee_failed'))
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
    contracts.value = Array.isArray(rawCtr) ? rawCtr.filter(Boolean) : []
    generatedContracts.value = Array.isArray(rawGen) ? rawGen.filter(Boolean) : []
  } catch (e) {
    toast.error(t('employee_modal.load_contracts_failed', { message: e.response?.data?.message || e.message }))
  }
}

function openGenerateContract() {
  if (! props.employeeId) {
    toast.warning(t('employee_modal.save_first'))
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
    toast.warning(t('employee_modal.select_template'))
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
    toast.error(e.response?.data?.message || t('employee_modal.preview_failed'))
  } finally {
    previewLoading.value = false
  }
}

async function submitGenerateContract() {
  if (! generateForm.value.template_id) {
    toast.warning(t('employee_modal.select_template'))
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
    toast.success(t('employee_modal.generated', { number: res.data.data?.contract_number || '' }))
    showGenerateModal.value = false
    await loadEmployee()
  } catch (e) {
    toast.error(e.response?.data?.message || t('employee_modal.generate_failed'))
  } finally {
    generating.value = false
  }
}

async function openGeneratedContract(c) {
  const previewWindow = window.open('', '_blank')
  if (!previewWindow) {
    toast.warning(t('employees.contracts.popup_blocked'))
    return
  }
  previewWindow.opener = null
  try {
    const res = await api.get(`/generated-contracts/${c.id}/html`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    if (previewWindow.closed) {
      URL.revokeObjectURL(url)
      return
    }
    previewWindow.location.href = url
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (e) {
    previewWindow.close()
    toast.error(t('employee_modal.open_contract_failed', { message: e.response?.data?.message || e.message }))
  }
}

onMounted(async () => {
  await loadMasters()
  await loadEmployee()
})

watch(() => props.employeeId, loadEmployee)
watch(() => props.mode, (m) => { internalMode.value = m })

function startEdit() {
  if (! canEdit.value) {
    toast.warning(t('employee_modal.no_edit_permission'))
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
      toast.success(t('employee_modal.employee_added'))
      emit('saved', res.data.data?.id || null)
    } else {
      const sensitiveFields = [
        'employee_number', 'nik', 'npwp', 'bank_name', 'bank_account', 'bank_account_holder',
        'bpjs_kesehatan_no', 'bpjs_tk_no', 'tax_method', 'ptkp_status', 'pph21_method',
      ]
      const normalize = value => value == null ? '' : String(value).trim()
      if (normalize(payload.basic_salary) !== normalize(employee.value?.basic_salary)) {
        toast.error('Perubahan gaji pokok wajib diajukan melalui Compensation Review agar effective date dan salary history tercatat.')
        return
      }
      const sensitivePayload = Object.fromEntries(
        sensitiveFields
          .filter(field => normalize(payload[field]) !== normalize(employee.value?.[field]))
          .map(field => [field, payload[field]])
      )
      for (const field of [...sensitiveFields, 'basic_salary']) delete payload[field]
      await api.put(`/employees/${props.employeeId}`, payload)
      if (Object.keys(sensitivePayload).length > 0) {
        await api.post(`/employees/${props.employeeId}/sensitive-change`, sensitivePayload)
        toast.success('Data umum diperbarui; perubahan data sensitif menunggu approval checker.')
      } else {
        toast.success(t('employee_modal.employee_updated'))
      }
      await loadEmployee()
      internalMode.value = 'view'
      emit('saved', props.employeeId)
    }
  } catch (e) {
    const errs = e.response?.data?.errors
    if (errs) {
      toast.error(t('employee_modal.validation_failed', { message: Object.values(errs).flat().join(', ') }))
    } else {
      toast.error(e.response?.data?.message || t('employee_modal.save_failed'))
    }
  } finally {
    saving.value = false
  }
}

async function recomputeAllowance() {
  const ok = await confirmDialog({
    title: t('employee_modal.recompute_title'),
    message: t('employee_modal.recompute_message'),
    okText: t('employee_modal.recompute_action'),
  })
  if (! ok) return
  try {
    const res = await api.post(`/employee-allowances/${props.employeeId}/recompute`)
    toast.success(t('employee_modal.recompute_success', { count: res.data.data?.count || 0 }))
    await loadEmployee()
  } catch (e) {
    toast.error(e.response?.data?.message || t('employee_modal.recompute_failed'))
  }
}

async function deactivateEmployee() {
  if (! canDelete.value || ! props.employeeId) return
  const ok = await confirmDialog({
    title: t('employee_modal.deactivate_title'),
    message: t('employee_modal.deactivate_message', {
      name: employee.value?.full_name || t('employee_modal.employee_fallback'),
    }),
    okText: t('employee_modal.deactivate_action'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/employees/${props.employeeId}`)
    toast.success(t('employee_modal.deactivated'))
    emit('saved', props.employeeId)
    emit('close')
  } catch (e) {
    toast.error(e.response?.data?.message || t('employee_modal.deactivate_failed'))
  }
}

// ─── Tab structure: grouped + iconified ─────────────────────────
const tabGroups = computed(() => {
  if (props.teamView) {
    return [
      {
        label: t('employee_modal.group_profile'),
        tabs: [
          { id: 'general', label: t('employee_modal.tab_identity'), icon: IdentificationIcon },
          { id: 'address', label: t('employee_modal.tab_address'), icon: MapPinIcon },
          { id: 'employment', label: t('employee_modal.tab_employment'), icon: BriefcaseIcon },
        ],
      },
    ]
  }
  const groups = [
    {
      label: t('employee_modal.group_personal'),
      tabs: [
        { id: 'general', label: t('employee_modal.tab_identity'), icon: IdentificationIcon },
        { id: 'ktp', label: t('employee_modal.tab_ktp_family'), icon: UserGroupIcon },
        { id: 'address', label: t('employee_modal.tab_address'), icon: MapPinIcon },
      ],
    },
    {
      label: t('employee_modal.group_work'),
      tabs: [
        { id: 'employment', label: t('employee_modal.tab_employment'), icon: BriefcaseIcon },
        { id: 'approval', label: t('employee_modal.tab_approval'), icon: ShieldCheckIcon },
        { id: 'allowances', label: t('employee_modal.tab_allowances'), icon: GiftIcon },
        { id: 'contracts', label: t('employee_modal.tab_contracts'), icon: DocumentTextIcon },
        { id: 'leave', label: t('employee_modal.tab_leave'), icon: CalendarDaysIcon },
        { id: 'leave-history', label: t('employee_modal.tab_leave_history'), icon: ClipboardDocumentListIcon },
      ],
    },
    {
      label: t('employee_modal.group_financial'),
      tabs: [
        { id: 'salary', label: t('employee_modal.tab_salary'), icon: BanknotesIcon },
      ],
    },
  ]
  if (! isCreating.value) {
    groups.push({
      label: t('employee_modal.group_history'),
      tabs: [
        { id: 'history', label: t('employee_modal.tab_history'), icon: ClockIcon },
      ],
    })
  }
  return groups
})

const activeTab = ref('general')
watch(activeTab, (val) => {
  if (val === 'history') loadHistoryTab()
  if (val === 'contracts') loadContractData()
  if (val === 'leave-history') loadLeaveHistory()
})

const LEAVE_STATUS_BADGE = {
  PENDING: 'badge-yellow',
  APPROVED: 'badge-green',
  REJECTED: 'badge-red',
  CANCELLED: 'badge-gray',
}

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
  return new Date(v).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
  })
}

function fmtDate(v) {
  if (!v) return '-'
  return new Date(v).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
  })
}

function fmtCurrency(v) {
  if (v === null || v === undefined || v === '') return '-'
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'id-ID', {
    style: 'currency', currency: 'IDR', maximumFractionDigits: 0,
  }).format(Number(v))
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

function employmentStatusLabel(status) {
  const supported = ['PKWTT', 'PKWT', 'PROBATION', 'HARIAN', 'MAGANG', 'OUTSOURCING']
  return supported.includes(status) ? t(`employee_form.status_${status.toLowerCase()}`) : status
}

function religionLabel(religion) {
  const supported = ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Konghucu', 'Lainnya']
  return supported.includes(religion) ? t(`employee_modal.religion_${religion.toLowerCase()}`) : religion
}

function employmentTypeLabel(type) {
  const supported = ['FULL_TIME', 'PART_TIME', 'CONTRACT', 'INTERN']
  return supported.includes(type) ? t(`employee_form.type_${type.toLowerCase()}`) : type
}

function probationStatusLabel(status) {
  const supported = ['ONGOING', 'PASSED', 'FAILED', 'EXTENDED', 'NA']
  return supported.includes(status) ? t(`employee_360.probation_${status.toLowerCase()}`) : status
}

function citizenshipLabel(citizenship) {
  const supported = ['WNI', 'WNA']
  return supported.includes(citizenship) ? t(`employee_modal.citizen_${citizenship.toLowerCase()}`) : citizenship
}

function taxMethodLabel(method) {
  const supported = ['GROSS', 'NETT', 'GROSS_UP']
  return supported.includes(method) ? t(`employee_modal.tax_${method.toLowerCase()}`) : method
}

function leaveStatusLabel(status) {
  const supported = ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED']
  return supported.includes(status) ? t(`common.${status.toLowerCase()}`) : status
}

function generatedContractStatusLabel(status) {
  const supported = ['DRAFT', 'SENT', 'SIGNED', 'REVOKED']
  return supported.includes(status) ? t(`employees.contracts.st_${status.toLowerCase()}`) : status
}

function lifecycleTaskTypeLabel(type) {
  const supported = ['ONBOARD', 'OFFBOARD']
  return supported.includes(type) ? t(`employee_modal.task_${type.toLowerCase()}`) : type
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-center justify-center p-3 sm:p-6 bg-black/60 backdrop-blur-sm"
       @click.self="emit('close')">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-6xl h-[86vh] flex flex-col overflow-hidden"
         role="dialog" aria-modal="true"
         v-bind="{ 'aria-label': isCreating ? t('employee_modal.add_employee') : (employee?.full_name || t('employee_modal.employee_fallback')) }">
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
                  <span v-if="isCreating">{{ t('employee_modal.add_employee') }}</span>
                  <span v-else-if="employee">{{ employee.full_name }}</span>
                  <span v-else class="text-gray-400">{{ t('employee_modal.loading') }}</span>
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
                  {{ employee.is_active ? `● ${t('employee_modal.active')}` : `○ ${t('employee_modal.inactive')}` }}
                </span>
                <span v-if="employee && !isCreating"
                      :class="['text-xs px-2 py-0.5 rounded-full border font-medium',
                        statusBadgeClass(employee.employment_status)]">
                  {{ employmentStatusLabel(employee.employment_status) }}
                </span>
                <span v-if="!isCreating && internalMode === 'edit'"
                      class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 border border-amber-200 font-medium">
                  ✎ {{ t('employee_modal.edit_mode') }}
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
                  <CalendarDaysIcon class="w-3.5 h-3.5" /> {{ t('employee_modal.joined', { date: fmtDate(employee.join_date) }) }}
                </span>
              </div>
              <p v-else-if="isCreating" class="mt-1.5 text-xs text-gray-500">
                {{ t('employee_modal.create_help') }}
              </p>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-2 shrink-0">
            <button v-if="!isCreating && internalMode === 'view' && canEdit"
                    @click="startEdit" class="btn-primary text-sm inline-flex items-center gap-1">
              <PencilSquareIcon class="w-4 h-4" /> {{ t('employee_modal.edit') }}
            </button>
            <button v-if="!isCreating && internalMode === 'view' && canDelete && employee?.is_active"
                    @click="deactivateEmployee"
                    class="btn-secondary text-sm inline-flex items-center gap-1 text-red-600 hover:bg-red-50 hover:border-red-200">
              <TrashIcon class="w-4 h-4" /> {{ t('employee_modal.deactivate') }}
            </button>
            <button type="button" @click="emit('close')"
                    class="p-2 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition"
                    v-bind="{ 'aria-label': t('employee_modal.close'), title: t('employee_modal.close') }">
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
              {{ t('employee_modal.loading') }}
            </div>

            <!-- ── IDENTITAS ─────────────────────────────────── -->
            <div v-else-if="activeTab === 'general'" class="space-y-5">
              <!-- NIK / NIP highlighted -->
              <div class="rounded-lg border border-gray-200 bg-gradient-to-r from-primary-50/50 to-transparent p-4">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                  <div class="flex-1 min-w-0">
                    <label class="text-xs font-semibold text-gray-600 uppercase tracking-wide">
                      {{ t('employee_modal.employee_number') }} <span class="text-gray-400 font-normal normal-case">{{ t('employee_modal.login_hint') }}</span>
                    </label>
                    <input v-if="isEditing" v-model="form.employee_number"
                           :disabled="!isCreating && !auth.isSuperAdmin"
                           class="input mt-1 font-mono" maxlength="32"
                           v-bind="{ placeholder: t('employee_modal.employee_number_ph') }" />
                    <div v-else class="mt-1 font-mono text-lg font-semibold text-gray-900">
                      {{ employee?.employee_number || '—' }}
                    </div>
                  </div>
                </div>
                <p v-if="isEditing && !isCreating && !auth.isSuperAdmin" class="text-xs text-gray-500 mt-2">
                  ℹ {{ t('employee_modal.nik_superadmin_only') }}
                </p>
                <p v-else-if="isEditing && !isCreating && auth.isSuperAdmin" class="text-xs text-amber-700 mt-2">
                  ⚠ {{ t('employee_modal.nik_change_warning') }}
                </p>
              </div>

              <!-- Section: Data diri -->
              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.personal_data') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">{{ t('employee_modal.first_name') }}</label>
                    <input v-if="isEditing" v-model="form.first_name" class="input" required />
                    <div v-else class="value">{{ employee?.first_name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.last_name') }}</label>
                    <input v-if="isEditing" v-model="form.last_name" class="input" />
                    <div v-else class="value">{{ employee?.last_name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.email') }}</label>
                    <input v-if="isEditing" v-model="form.email" type="email" class="input" />
                    <div v-else class="value">{{ employee?.email || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.phone') }}</label>
                    <input v-if="isEditing" v-model="form.phone" class="input" />
                    <div v-else class="value">{{ employee?.phone || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.gender') }}</label>
                    <select v-if="isEditing" v-model="form.gender" class="input">
                      <option value="">{{ t('employee_modal.select') }}</option>
                      <option value="M">{{ t('employee_modal.male') }}</option>
                      <option value="F">{{ t('employee_modal.female') }}</option>
                    </select>
                    <div v-else class="value">
                      {{ employee?.gender === 'M' ? t('employee_modal.male') : (employee?.gender === 'F' ? t('employee_modal.female') : '—') }}
                    </div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.birth_place') }}</label>
                    <input v-if="isEditing" v-model="form.place_of_birth" class="input" />
                    <div v-else class="value">{{ employee?.place_of_birth || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.birth_date') }}</label>
                    <AppDatePicker v-if="isEditing" v-model="form.date_of_birth"  class="input" />
                    <div v-else class="value">{{ employee?.date_of_birth ? fmtDate(employee.date_of_birth) : '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.religion') }}</label>
                    <select v-if="isEditing" v-model="form.religion" class="input">
                      <option value="">{{ t('employee_modal.select') }}</option>
                      <option v-for="r in ['Islam','Kristen','Katolik','Hindu','Buddha','Konghucu','Lainnya']"
                              :key="r" :value="r">{{ religionLabel(r) }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.religion ? religionLabel(employee.religion) : '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.blood_type') }}</label>
                    <select v-if="isEditing" v-model="form.blood_type" class="input">
                      <option value="">{{ t('employee_modal.select') }}</option>
                      <option v-for="b in ['A','B','AB','O','A+','A-','B+','B-','AB+','AB-','O+','O-']"
                              :key="b" :value="b">{{ b }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.blood_type || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.citizenship') }}</label>
                    <select v-if="isEditing" v-model="form.citizenship" class="input">
                      <option value="WNI">{{ t('employee_modal.citizen_wni') }}</option>
                      <option value="WNA">{{ t('employee_modal.citizen_wna') }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.citizenship ? citizenshipLabel(employee.citizenship) : '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.ethnicity') }} <span class="text-gray-400">{{ t('employee_modal.optional') }}</span></label>
                    <input v-if="isEditing" v-model="form.ethnicity" class="input"
                           v-bind="{ placeholder: t('employee_modal.ethnicity_ph') }" />
                    <div v-else class="value">{{ employee?.ethnicity || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.education') }}</label>
                    <select v-if="isEditing" v-model="form.education_level" class="input">
                      <option value="">{{ t('employee_modal.select') }}</option>
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
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.tax_insurance') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">{{ t('employee_modal.nik') }} <span v-if="isEditing" class="text-gray-400">{{ t('employee_modal.encrypted') }}</span></label>
                    <input v-if="isEditing" v-model="form.nik" class="input font-mono" maxlength="16" />
                    <div v-else class="value font-mono">{{ employee?.nik || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.npwp') }}</label>
                    <input v-if="isEditing" v-model="form.npwp" class="input font-mono" />
                    <div v-else class="value font-mono">{{ employee?.npwp || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.ptkp') }}</label>
                    <select v-if="isEditing" v-model="form.ptkp_status" class="input">
                      <option v-for="c in ['TK0','TK1','TK2','TK3','K0','K1','K2','K3','HB0','HB1','HB2','HB3']"
                              :key="c" :value="c">{{ c }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.ptkp_status || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.marital_status') }}</label>
                    <select v-if="isEditing" v-model="form.marital_status" class="input">
                      <option v-for="c in ['TK0','TK1','TK2','TK3','K0','K1','K2','K3','HB0','HB1','HB2','HB3']"
                              :key="c" :value="c">{{ c }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.marital_status || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.bpjs_health') }}</label>
                    <input v-if="isEditing" v-model="form.bpjs_kesehatan_no" class="input font-mono" />
                    <div v-else class="value font-mono">{{ employee?.bpjs_kesehatan_no || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.bpjs_employment') }}</label>
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
                    <div class="font-medium">{{ t('employee_modal.create_login') }}</div>
                    <div class="text-xs text-blue-700 mt-0.5">
                      {{ t('employee_modal.create_login_help') }}
                    </div>
                  </div>
                </label>
              </div>
            </div>

            <!-- ── KTP & KELUARGA ────────────────────────────── -->
            <div v-else-if="activeTab === 'ktp'" class="space-y-5">
              <div class="rounded-md p-3 bg-gray-50 border border-gray-200 text-xs text-gray-600">
                ℹ {{ t('employee_modal.ktp_info') }}
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.family_documents') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">{{ t('employee_modal.family_card') }}</label>
                    <input v-if="isEditing" v-model="form.kk_number" class="input font-mono" maxlength="32" />
                    <div v-else class="value font-mono">{{ employee?.kk_number || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.marital_certificate') }}</label>
                    <input v-if="isEditing" v-model="form.marital_certificate_no" class="input" />
                    <div v-else class="value">{{ employee?.marital_certificate_no || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.mother_name') }}</label>
                    <input v-if="isEditing" v-model="form.mother_name" class="input"
                           v-bind="{ placeholder: t('employee_modal.mother_name_ph') }" />
                    <div v-else class="value">{{ employee?.mother_name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.father_name') }}</label>
                    <input v-if="isEditing" v-model="form.father_name" class="input" />
                    <div v-else class="value">{{ employee?.father_name || '—' }}</div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.ktp_address') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field md:col-span-2">
                    <label class="label">{{ t('employee_modal.full_address') }}</label>
                    <textarea v-if="isEditing" v-model="form.ktp_address" class="input" rows="2"
                              v-bind="{ placeholder: t('employee_modal.ktp_address_ph') }"></textarea>
                    <div v-else class="value whitespace-pre-line">{{ employee?.ktp_address || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.rt_rw') }}</label>
                    <input v-if="isEditing" v-model="form.ktp_rt_rw" class="input" placeholder="001/002" />
                    <div v-else class="value">{{ employee?.ktp_rt_rw || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.village') }}</label>
                    <input v-if="isEditing" v-model="form.ktp_kelurahan" class="input" />
                    <div v-else class="value">{{ employee?.ktp_kelurahan || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.district') }}</label>
                    <input v-if="isEditing" v-model="form.ktp_kecamatan" class="input" />
                    <div v-else class="value">{{ employee?.ktp_kecamatan || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.city_regency') }}</label>
                    <input v-if="isEditing" v-model="form.ktp_city" class="input" />
                    <div v-else class="value">{{ employee?.ktp_city || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.province') }}</label>
                    <input v-if="isEditing" v-model="form.ktp_province" class="input" />
                    <div v-else class="value">{{ employee?.ktp_province || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.postal_code') }}</label>
                    <input v-if="isEditing" v-model="form.ktp_postal_code" class="input" maxlength="5" />
                    <div v-else class="value">{{ employee?.ktp_postal_code || '—' }}</div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.emergency_contact') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">{{ t('employee_modal.name') }}</label>
                    <input v-if="isEditing" v-model="form.emergency_contact_name" class="input" />
                    <div v-else class="value">{{ employee?.emergency_contact_name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.phone') }}</label>
                    <input v-if="isEditing" v-model="form.emergency_contact_phone" class="input" />
                    <div v-else class="value">{{ employee?.emergency_contact_phone || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.relationship') }}</label>
                    <input v-if="isEditing" v-model="form.emergency_contact_rel" class="input"
                           v-bind="{ placeholder: t('employee_modal.relationship_ph') }" />
                    <div v-else class="value">{{ employee?.emergency_contact_rel || '—' }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── KEPEGAWAIAN ───────────────────────────────── -->
            <div v-else-if="activeTab === 'employment'" class="space-y-5">
              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.placement') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">{{ t('employee_modal.branch') }}</label>
                    <SearchableSelect v-if="isEditing" v-model="form.branch_id" :items="branches"
                                      value-key="id" label-key="name"
                                      :search-keys="['name','code','city']" v-bind="{ placeholder: t('employee_modal.select') }" />
                    <div v-else class="value">{{ branches.find(b => b.id === employee?.branch_id)?.name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.work_location') }}</label>
                    <SearchableSelect v-if="isEditing" v-model="form.work_location_id" :items="workLocations"
                                      value-key="id" label-key="name" sub-label-key="type"
                                      :search-keys="['name','type','address']" v-bind="{ placeholder: t('employee_modal.select') }">
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
                    <label class="label">{{ t('employee_modal.department') }}</label>
                    <SearchableSelect v-if="isEditing" v-model="form.department_id" :items="departments"
                                      value-key="id" label-key="name"
                                      :search-keys="['name','code']" v-bind="{ placeholder: t('employee_modal.select') }" />
                    <div v-else class="value">{{ departments.find(d => d.id === employee?.department_id)?.name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.position') }}</label>
                    <SearchableSelect v-if="isEditing" v-model="form.position_id" :items="positions"
                                      value-key="id" label-key="name" sub-label-key="department_name"
                                      :search-keys="['name','code','tier','department_name']" v-bind="{ placeholder: t('employee_modal.select') }" />
                    <div v-else class="value">{{ positions.find(p => p.id === employee?.position_id)?.name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.job_grade') }}</label>
                    <SearchableSelect v-if="isEditing" v-model="form.job_grade_id" :items="jobGrades"
                                      value-key="id" label-key="name"
                                      :search-keys="['name','code']" v-bind="{ placeholder: t('employee_modal.select') }">
                      <template #item="{ item }">
                        <span class="block text-sm truncate">{{ item.name }}
                          <span class="text-xs text-gray-500">({{ t('employee_modal.level', { level: item.level }) }})</span></span>
                      </template>
                      <template #selected="{ item }">
                        <span class="block truncate font-medium">{{ item.name }}
                          <span class="text-xs text-gray-500">({{ t('employee_modal.level', { level: item.level }) }})</span></span>
                      </template>
                    </SearchableSelect>
                    <div v-else class="value">{{ jobGrades.find(g => g.id === employee?.job_grade_id)?.name || '—' }}</div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.hierarchy') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">{{ t('employee_modal.direct_supervisor') }} <span class="text-gray-400">{{ t('employee_modal.approval_hint') }}</span></label>
                    <EmployeeSearchSelect v-if="isEditing" v-model="form.supervisor_id"
                                          v-bind="{ placeholder: t('employee_modal.select_supervisor') }" :only-active="true" />
                    <div v-else class="value">
                      {{ employeesList.find(e => e.id === employee?.supervisor_id)?.full_name || '—' }}
                    </div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.manager') }}</label>
                    <EmployeeSearchSelect v-if="isEditing" v-model="form.manager_id"
                                          v-bind="{ placeholder: t('employee_modal.select_manager') }" :only-active="true" />
                    <div v-else class="value">
                      {{ employeesList.find(e => e.id === employee?.manager_id)?.full_name || '—' }}
                    </div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.status_dates') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">{{ t('employee_modal.employment_status') }}</label>
                    <select v-if="isEditing" v-model="form.employment_status" class="input">
                      <option v-for="s in ['PKWT','PKWTT','PROBATION','HARIAN','MAGANG','OUTSOURCING']"
                              :key="s" :value="s">{{ employmentStatusLabel(s) }}</option>
                    </select>
                    <div v-else>
                      <span :class="['text-xs px-2 py-1 rounded-full border font-medium',
                                     statusBadgeClass(employee?.employment_status)]">
                        {{ employee?.employment_status ? employmentStatusLabel(employee.employment_status) : '—' }}
                      </span>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.worker_type') }}</label>
                    <select v-if="isEditing" v-model="form.employment_type" class="input">
                      <option v-for="s in ['FULL_TIME','PART_TIME','CONTRACT','INTERN']" :key="s" :value="s">{{ employmentTypeLabel(s) }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.employment_type ? employmentTypeLabel(employee.employment_type) : '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.join_date') }}</label>
                    <AppDatePicker v-if="isEditing" v-model="form.join_date"  class="input" required />
                    <div v-else class="value">{{ employee?.join_date ? fmtDate(employee.join_date) : '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.probation_end') }}</label>
                    <AppDatePicker v-if="isEditing" v-model="form.probation_end_date"  class="input" />
                    <div v-else>
                      <div class="value">{{ employee?.probation_end_date ? fmtDate(employee.probation_end_date) : '—' }}</div>
                      <!-- Sprint 2 / HIRE-1: probation status badge & quick link -->
                      <div v-if="employee?.probation_end_date && employee?.probation_status"
                        class="mt-1 flex items-center gap-2 flex-wrap text-xs">
                        <span :class="[
                          'px-2 py-0.5 rounded-full font-medium',
                          employee.probation_status === 'PASSED' ? 'bg-emerald-100 text-emerald-700' : '',
                          employee.probation_status === 'FAILED' ? 'bg-rose-100 text-rose-700' : '',
                          employee.probation_status === 'EXTENDED' ? 'bg-amber-100 text-amber-700' : '',
                          employee.probation_status === 'ONGOING' ? 'bg-blue-100 text-blue-700' : '',
                        ]">
                          {{ probationStatusLabel(employee.probation_status) }}
                        </span>
                        <router-link v-if="employee.probation_status === 'ONGOING'"
                          to="/hr/probation"
                          class="text-blue-600 hover:underline">
                          {{ t('employee_modal.probation_tracker') }}
                        </router-link>
                      </div>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.contract_end') }}</label>
                    <AppDatePicker v-if="isEditing" v-model="form.contract_end_date"  class="input" />
                    <div v-else class="value">{{ employee?.contract_end_date ? fmtDate(employee.contract_end_date) : '—' }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── APPROVAL CHAIN ────────────────────────────── -->
            <div v-else-if="activeTab === 'approval'" class="space-y-5">
              <div class="rounded-md p-3 bg-gray-50 border border-gray-200 text-xs text-gray-600">
                ℹ {{ t('employee_modal.approval_info') }}
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                <div class="field">
                  <label class="label">{{ t('employee_modal.leave_chain') }}</label>
                  <select v-if="isEditing" v-model="form.approval_chain_leave_id" class="input">
                    <option :value="null">{{ t('employee_modal.company_default') }}</option>
                    <option v-for="c in approvalChains.LEAVE" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                  <div v-else class="value">{{ approvalChains.LEAVE.find(c => c.id === employee?.approval_chain_leave_id)?.name || t('employee_modal.default_value') }}</div>
                </div>
                <div class="field">
                  <label class="label">{{ t('employee_modal.overtime_chain') }}</label>
                  <select v-if="isEditing" v-model="form.approval_chain_overtime_id" class="input">
                    <option :value="null">{{ t('employee_modal.company_default') }}</option>
                    <option v-for="c in approvalChains.OVERTIME" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                  <div v-else class="value">{{ approvalChains.OVERTIME.find(c => c.id === employee?.approval_chain_overtime_id)?.name || t('employee_modal.default_value') }}</div>
                </div>
                <div class="field md:col-span-2">
                  <label class="label">{{ t('employee_modal.attendance_chain') }}</label>
                  <select v-if="isEditing" v-model="form.approval_chain_attendance_id" class="input">
                    <option :value="null">{{ t('employee_modal.company_default') }}</option>
                    <option v-for="c in approvalChains.ATTENDANCE_CHANGE" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                  <div v-else class="value">{{ approvalChains.ATTENDANCE_CHANGE.find(c => c.id === employee?.approval_chain_attendance_id)?.name || t('employee_modal.default_value') }}</div>
                </div>
              </div>

              <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                <div class="flex items-start gap-2">
                  <CheckCircleIcon class="w-5 h-5 shrink-0 mt-0.5" />
                  <div>
                    <p class="font-medium">{{ t('employee_modal.current_first_approver') }}</p>
                    <p class="mt-0.5">
                      {{ t('employee_modal.direct_supervisor_from_tab') }}
                      <span class="font-semibold">
                        {{ employeesList.find(e => e.id === form.supervisor_id)?.full_name || t('employee_modal.supervisor_unset') }}
                      </span>
                    </p>
                    <p class="text-xs text-blue-700 mt-1">
                      {{ t('employee_modal.supervisor_help') }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── GAJI & PAJAK ──────────────────────────────── -->
            <div v-else-if="activeTab === 'salary'" class="space-y-5">
              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.basic_salary_section') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">{{ t('employee_modal.basic_salary') }}</label>
                    <input v-if="isEditing" v-model.number="form.basic_salary" type="number" min="0" class="input bg-gray-50" disabled />
                    <div v-else class="value text-lg font-semibold text-gray-900">
                      {{ fmtCurrency(employee?.basic_salary) }}
                    </div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.tax_method') }}</label>
                    <select v-if="isEditing" v-model="form.tax_method" class="input">
                      <option value="GROSS">{{ t('employee_modal.tax_gross') }}</option>
                      <option value="NETT">{{ t('employee_modal.tax_nett') }}</option>
                      <option value="GROSS_UP">{{ t('employee_modal.tax_gross_up') }}</option>
                    </select>
                    <div v-else class="value">{{ employee?.tax_method ? taxMethodLabel(employee.tax_method) : '—' }}</div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.bank_account_section') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">{{ t('employee_modal.bank') }}</label>
                    <input v-if="isEditing" v-model="form.bank_name" class="input" />
                    <div v-else class="value">{{ employee?.bank_name || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.account_number') }} <span v-if="isEditing" class="text-gray-400">{{ t('employee_modal.encrypted') }}</span></label>
                    <input v-if="isEditing" v-model="form.bank_account" class="input font-mono" />
                    <div v-else class="value font-mono">{{ employee?.bank_account || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.account_holder') }}</label>
                    <input v-if="isEditing" v-model="form.bank_account_holder" class="input" />
                    <div v-else class="value">{{ employee?.bank_account_holder || '—' }}</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── ALAMAT ────────────────────────────────────── -->
            <div v-else-if="activeTab === 'address'" class="space-y-5">
              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.domicile_address') }}</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field md:col-span-2">
                    <label class="label">{{ t('employee_modal.full_address') }}</label>
                    <textarea v-if="isEditing" v-model="form.address" class="input" rows="2"></textarea>
                    <div v-else class="value whitespace-pre-line">{{ employee?.address || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.province') }}</label>
                    <input v-if="isEditing" v-model="form.province" class="input" />
                    <div v-else class="value">{{ employee?.province || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_form.city') }}</label>
                    <input v-if="isEditing" v-model="form.city" class="input" />
                    <div v-else class="value">{{ employee?.city || '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.postal_code') }}</label>
                    <input v-if="isEditing" v-model="form.postal_code" class="input" />
                    <div v-else class="value">{{ employee?.postal_code || '—' }}</div>
                  </div>
                </div>
              </div>

              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3 flex items-center gap-2">
                  <MapPinIcon class="w-4 h-4" /> {{ t('employee_modal.home_coordinates') }}
                </h4>
                <p class="text-xs text-gray-500 mb-3">
                  {{ t('employee_modal.coordinates_help') }}
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                  <div class="field">
                    <label class="label">{{ t('employee_modal.latitude') }}</label>
                    <input v-if="isEditing" v-model="form.home_lat" type="number" step="0.0000001"
                           class="input font-mono" placeholder="-6.2088" />
                    <div v-else class="value font-mono">{{ employee?.home_lat ?? '—' }}</div>
                  </div>
                  <div class="field">
                    <label class="label">{{ t('employee_modal.longitude') }}</label>
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
                  {{ t('employee_modal.allowances_help') }}
                </p>
                <button v-if="canEdit && !isCreating" class="btn-secondary text-xs inline-flex items-center gap-1"
                        @click="recomputeAllowance">
                  <ArrowPathIcon class="w-4 h-4" /> {{ t('employee_modal.recompute_action') }}
                </button>
              </div>
              <div class="rounded-lg border border-gray-200 overflow-hidden">
                <table class="table">
                  <thead class="bg-gray-50">
                    <tr>
                      <th>{{ t('employee_modal.allowance_type') }}</th><th>{{ t('employee_modal.allowance_branch') }}</th><th>{{ t('employee_modal.allowance_grade') }}</th><th>{{ t('employee_modal.allowance_distance') }}</th>
                      <th>{{ t('employee_modal.allowance_threshold') }}</th><th>{{ t('employee_modal.allowance_eligible') }}</th><th class="text-right">{{ t('employee_modal.allowance_amount') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <tr v-if="allowances.length === 0">
                      <td colspan="7" class="text-center py-6 text-gray-400 text-sm">
                        {{ t('employee_modal.no_allowances') }}
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
                          {{ a.eligible ? t('employee_modal.yes') : t('employee_modal.no') }}
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
              <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ t('employee_modal.employment_contracts') }}</h4>
                <div class="rounded-lg border border-gray-200 overflow-hidden">
                  <table class="table">
                    <thead class="bg-gray-50">
                      <tr><th>{{ t('employee_modal.col_no') }}</th><th>{{ t('employee_modal.col_type') }}</th><th>{{ t('employee_modal.col_start') }}</th><th>{{ t('employee_modal.col_end') }}</th><th class="text-right">{{ t('employee_modal.col_salary') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                      <tr v-if="!contracts || contracts.length === 0">
                        <td colspan="5" class="text-center py-6 text-gray-400 text-sm">{{ t('employee_modal.no_contracts') }}</td>
                      </tr>
                      <tr v-for="(c, ci) in contracts" :key="c?.id ?? ci">
                        <td class="font-mono text-sm">{{ c?.contract_number }}</td>
                        <td><span class="badge-blue">{{ employmentStatusLabel(c?.contract_type) }}</span></td>
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
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ t('employee_modal.generated_contracts') }}</h4>
                    <p class="text-xs text-gray-500 mt-0.5">{{ t('employee_modal.generated_help') }}</p>
                  </div>
                  <button v-if="canEdit && !isCreating" type="button" class="btn-primary text-xs"
                          @click="openGenerateContract">
                    + {{ t('employee_modal.generate_contract') }}
                  </button>
                </div>
                <div class="rounded-lg border border-gray-200 overflow-hidden">
                  <table class="table">
                    <thead class="bg-gray-50">
                      <tr><th>{{ t('employee_modal.col_contract_no') }}</th><th>{{ t('employee_modal.col_subject') }}</th><th>{{ t('employee_modal.col_template') }}</th><th>{{ t('employee_modal.col_status') }}</th><th>{{ t('employee_modal.col_created') }}</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                      <tr v-if="!generatedContracts || generatedContracts.length === 0">
                        <td colspan="6" class="text-center py-6 text-gray-400 text-sm">{{ t('employee_modal.no_generated') }}</td>
                      </tr>
                      <tr v-for="(g, gi) in generatedContracts" :key="g?.id ?? gi">
                        <td class="font-mono text-sm">{{ g?.contract_number }}</td>
                        <td class="text-sm">{{ g?.subject || '—' }}</td>
                        <td class="text-sm">
                          {{ g?.template_name || '—' }}
                          <span class="text-xs text-gray-500">({{ g?.template_type }})</span>
                        </td>
                        <td><span class="badge-blue">{{ generatedContractStatusLabel(g?.status) }}</span></td>
                        <td class="text-sm">{{ fmtDate(g?.created_at) }}</td>
                        <td class="text-right">
                          <button type="button" class="btn-secondary text-xs" @click="openGeneratedContract(g)">
                            <EyeIcon class="w-3.5 h-3.5" /> {{ t('employee_modal.open') }}
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
              <p class="text-xs text-gray-500 mb-3">
                {{ t('employee_modal.leave_info', { year: balanceYear }) }}
              </p>
              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <div v-if="balances.length === 0" class="md:col-span-3 rounded-lg border border-dashed border-gray-300 p-8 text-center text-gray-400 text-sm">
                  {{ t('employee_modal.no_leave_balance') }}
                </div>
                <div v-for="b in balances" :key="b.leave_type_id"
                     class="rounded-lg border border-gray-200 bg-white p-4 hover:shadow-sm transition">
                  <div class="flex items-start justify-between gap-2 mb-2">
                    <div class="text-sm font-semibold text-gray-700 truncate">{{ b.name }}</div>
                    <span class="text-[10px] font-mono text-gray-400 shrink-0">{{ b.code }}</span>
                  </div>
                  <div class="flex items-baseline gap-2 mb-2">
                    <span class="text-2xl font-bold text-primary-700 tabular-nums">{{ b.remaining }}</span>
                    <span class="text-sm text-gray-500">/ {{ b.allocated }} {{ t('employees.detail.col_days').toLowerCase() }}</span>
                  </div>
                  <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                    <div class="bg-primary-600 h-full rounded-full transition-all"
                         :style="{ width: b.allocated ? Math.min(100, (b.remaining / b.allocated) * 100) + '%' : '0%' }"></div>
                  </div>
                  <div class="flex flex-wrap gap-x-2 gap-y-0.5 text-xs text-gray-500 mt-2">
                    <span>{{ t('employee_modal.leave_used', { count: b.used }) }}</span>
                    <span v-if="b.is_prorated && b.prorate_factor < 1">
                      · {{ t('employee_modal.leave_prorate', { months: b.months_worked }) }}
                    </span>
                    <span v-if="b.carried_over > 0">
                      · {{ t('employee_modal.leave_carryover', { count: b.carried_over }) }}
                    </span>
                    <span v-if="b.is_expired" class="text-red-600 font-medium">
                      · {{ t('employee_modal.leave_expired') }}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── RIWAYAT CUTI ──────────────────────────────── -->
            <div v-else-if="activeTab === 'leave-history'" class="space-y-4">
              <div v-if="leavesLoading" class="text-center py-8 text-gray-400 text-sm">
                <ArrowPathIcon class="w-5 h-5 mx-auto mb-2 animate-spin" />
                {{ t('employee_modal.load_leave_history') }}
              </div>
              <div v-else-if="leavesError"
                   class="rounded-md p-3 bg-red-50 border border-red-200 text-red-700 text-sm">
                {{ leavesError }}
                <button class="ml-2 underline" @click="leavesLoaded = false; loadLeaveHistory()">
                  {{ t('employee_modal.retry') }}
                </button>
              </div>
              <template v-else>
                <p class="text-xs text-gray-500">
                  {{ t('employee_modal.leave_history_help') }}
                </p>
                <div class="rounded-lg border border-gray-200 overflow-hidden">
                  <table class="table">
                    <thead class="bg-gray-50">
                      <tr>
                        <th>{{ t('employee_modal.col_request_no') }}</th>
                        <th>{{ t('employee_modal.col_leave_type') }}</th>
                        <th>{{ t('employee_modal.col_start') }}</th>
                        <th>{{ t('employee_modal.col_end') }}</th>
                        <th class="text-right">{{ t('employee_modal.col_days') }}</th>
                        <th>{{ t('employee_modal.col_status') }}</th>
                        <th>{{ t('employee_modal.col_reason') }}</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                      <tr v-if="leaves.length === 0">
                        <td colspan="7" class="text-center py-6 text-gray-400 text-sm">
                          {{ t('employee_modal.no_leave_history') }}
                        </td>
                      </tr>
                      <tr v-for="l in leaves" :key="l.id">
                        <td class="font-mono text-xs">{{ l.request_number }}</td>
                        <td class="text-sm">
                          <div class="font-medium">{{ l.leave_name || '—' }}</div>
                          <div v-if="l.leave_code" class="text-xs text-gray-500 font-mono">{{ l.leave_code }}</div>
                        </td>
                        <td class="text-sm">{{ fmtDateOrDash(l.start_date) }}</td>
                        <td class="text-sm">{{ fmtDateOrDash(l.end_date) }}</td>
                        <td class="text-right tabular-nums font-medium">{{ l.days }}</td>
                        <td>
                          <span :class="LEAVE_STATUS_BADGE[l.status] || 'badge-gray'">{{ leaveStatusLabel(l.status) }}</span>
                        </td>
                        <td class="text-sm text-gray-600 max-w-xs truncate" :title="l.reason">
                          {{ l.reason || '—' }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <p class="text-xs text-gray-400 text-right">{{ t('employee_modal.entries', { count: leaves.length }) }}</p>
              </template>
            </div>

            <!-- ── RIWAYAT & ASET ────────────────────────────── -->
            <div v-else-if="activeTab === 'history'" class="space-y-6">
              <div v-if="historyLoading" class="text-center py-8 text-gray-400 text-sm">
                <ArrowPathIcon class="w-5 h-5 mx-auto mb-2 animate-spin" />
                {{ t('employee_modal.load_history') }}
              </div>
              <div v-else-if="historyError"
                   class="rounded-md p-3 bg-red-50 border border-red-200 text-red-700 text-sm">
                {{ historyError }}
                <button class="ml-2 underline" @click="historyLoaded = false; loadHistoryTab()">{{ t('employee_modal.retry') }}</button>
              </div>
              <template v-else>
                <!-- Aset -->
                <section>
                  <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ t('employee_modal.assets_history') }}</h4>
                    <span class="text-xs text-gray-400">{{ t('employee_modal.asset_count', { count: historyAssets.length }) }}</span>
                  </div>
                  <div v-if="historyAssets.length" class="rounded-lg border border-gray-200 overflow-hidden">
                    <table class="table">
                      <thead class="bg-gray-50">
                        <tr><th>{{ t('employee_modal.col_code') }}</th><th>{{ t('employee_modal.col_asset_name') }}</th><th>{{ t('employee_modal.col_category') }}</th><th>{{ t('employee_modal.col_start') }}</th><th>{{ t('employee_modal.col_status') }}</th></tr>
                      </thead>
                      <tbody class="divide-y divide-gray-100">
                        <tr v-for="a in historyAssets" :key="a.id">
                          <td class="font-mono text-sm">{{ a.asset_code || a.code || '—' }}</td>
                          <td>
                            <div class="font-medium">{{ a.asset_name || a.name || '—' }}</div>
                            <div v-if="a.serial_number" class="text-xs text-gray-500">{{ t('employee_modal.serial_number', { number: a.serial_number }) }}</div>
                          </td>
                          <td class="text-sm">{{ a.category || '—' }}</td>
                          <td class="text-sm">{{ fmtDateOrDash(a.assigned_at || a.start_date) }}</td>
                          <td>
                            <span v-if="a.returned_at" class="badge-gray">{{ t('employee_modal.asset_returned') }}</span>
                            <span v-else class="badge-green">{{ t('employee_modal.active') }}</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <p v-else class="text-sm text-gray-400 italic py-2">
                    {{ t('employee_modal.no_assets') }}
                  </p>
                </section>

                <!-- Disciplinary -->
                <section>
                  <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ t('employee_modal.warning_letters') }}</h4>
                    <span class="text-xs text-gray-400">
                      {{ t('employee_modal.active_total', { active: historyDisciplinary?.active_count ?? 0, total: historyDisciplinary?.total_count ?? 0 }) }}
                    </span>
                  </div>
                  <div v-if="historyDisciplinary?.active_count > 0"
                       class="mb-3 rounded-md p-3 bg-amber-50 border border-amber-200 text-amber-800 text-xs">
                    <strong>{{ t('employee_modal.highest_active_level') }}</strong>
                    {{ historyDisciplinary?.highest_active_level || '—' }}
                    · {{ t('employee_modal.valid_until', { date: fmtDateOrDash(historyDisciplinary?.highest_active_until) }) }}
                  </div>
                  <div v-if="(historyDisciplinary?.records || []).length"
                       class="rounded-lg border border-gray-200 overflow-hidden">
                    <table class="table">
                      <thead class="bg-gray-50">
                        <tr><th>{{ t('employee_modal.col_warning_no') }}</th><th>{{ t('employee_modal.col_level') }}</th><th>{{ t('employee_modal.col_category') }}</th><th>{{ t('employee_modal.col_date') }}</th><th>{{ t('employee_modal.col_valid_until') }}</th><th>{{ t('employee_modal.col_status') }}</th></tr>
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
                            <span v-if="r.status === 'ACTIVE'" class="badge-yellow">{{ t('employee_modal.active') }}</span>
                            <span v-else-if="r.status === 'EXPIRED'" class="badge-gray">{{ t('employee_modal.status_expired') }}</span>
                            <span v-else-if="r.status === 'REVOKED'" class="badge-gray">{{ t('employee_modal.status_revoked') }}</span>
                            <span v-else class="badge-gray">{{ r.status || '—' }}</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <p v-else class="text-sm text-gray-400 italic py-2">
                    {{ t('employee_modal.no_warnings') }}
                  </p>
                </section>

                <!-- Tasks -->
                <section>
                  <div class="flex items-center justify-between mb-3">
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                      {{ t('employee_modal.lifecycle_tasks') }}
                    </h4>
                    <span class="text-xs text-gray-400">{{ t('employee_modal.task_count', { count: historyTasks.length }) }}</span>
                  </div>
                  <div v-if="historyTasks.length" class="rounded-lg border border-gray-200 overflow-hidden">
                    <table class="table">
                      <thead class="bg-gray-50">
                        <tr><th>{{ t('employee_modal.col_type') }}</th><th>{{ t('employee_modal.col_task') }}</th><th>{{ t('employee_modal.col_pic') }}</th><th>{{ t('employee_modal.col_due') }}</th><th>{{ t('employee_modal.col_status') }}</th></tr>
                      </thead>
                      <tbody class="divide-y divide-gray-100">
                        <tr v-for="task in historyTasks" :key="task.id">
                          <td>
                            <span :class="task.task_type === 'OFFBOARD' ? 'badge-red' : 'badge-blue'">
                              {{ task.task_type ? lifecycleTaskTypeLabel(task.task_type) : '—' }}
                            </span>
                          </td>
                          <td>{{ task.title || task.name || '—' }}</td>
                          <td class="text-sm">{{ task.assignee_name || '—' }}</td>
                          <td class="text-sm">{{ fmtDateOrDash(task.due_date) }}</td>
                          <td>
                            <span v-if="task.status === 'DONE' || task.completed_at" class="badge-green">{{ t('employee_modal.status_done') }}</span>
                            <span v-else-if="task.status === 'IN_PROGRESS'" class="badge-blue">{{ t('employee_modal.status_in_progress') }}</span>
                            <span v-else-if="task.due_date && new Date(task.due_date) < new Date()" class="badge-red">{{ t('employee_modal.status_overdue') }}</span>
                            <span v-else class="badge-gray">{{ task.status || t('employee_modal.status_pending') }}</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <p v-else class="text-sm text-gray-400 italic py-2">
                    {{ t('employee_modal.no_tasks') }}
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
          {{ isCreating ? t('employee_modal.cancel') : t('employee_modal.cancel_edit') }}
        </button>
        <button v-if="!isEditing" type="button" class="btn-secondary" @click="emit('close')">{{ t('employee_modal.close') }}</button>
        <button v-if="isEditing" type="button" class="btn-primary"
                :disabled="saving" @click="save">
          {{ saving ? t('employee_modal.saving') : (isCreating ? t('employee_modal.save') : t('employee_modal.save_changes')) }}
        </button>
      </div>
    </div>

    <!-- ─── Generate Contract Modal ────────────────────────────── -->
    <div v-if="showGenerateModal"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
      @click.self="showGenerateModal = false">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl h-[80vh] flex flex-col overflow-hidden"
         role="dialog" aria-modal="true"
         v-bind="{ 'aria-label': t('employee_modal.generate_title', { name: employee?.full_name || t('employee_modal.employee_fallback') }) }">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <h3 class="font-semibold">{{ t('employee_modal.generate_title', { name: employee?.full_name }) }}</h3>
          <button type="button" class="p-2 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-700"
                  v-bind="{ 'aria-label': t('employee_modal.close'), title: t('employee_modal.close') }"
                  @click="showGenerateModal = false">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>
        <div class="flex-1 overflow-y-auto p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-3">
            <div class="field">
              <label class="label">{{ t('employee_modal.template') }}</label>
              <select v-model="generateForm.template_id" class="input">
                <option v-for="t in contractTemplates" :key="t.id" :value="t.id">
                  {{ t.name }} ({{ t.contract_type }})
                </option>
              </select>
            </div>
            <div class="field">
              <label class="label">{{ t('employee_modal.link_contract') }} <span class="text-gray-400">{{ t('employee_modal.optional') }}</span></label>
              <select v-model="generateForm.employee_contract_id" class="input">
                <option :value="null">{{ t('employee_modal.not_linked') }}</option>
                <option v-for="c in contracts" :key="c.id" :value="c.id">
                  {{ c.contract_number }} - {{ c.contract_type }}
                </option>
              </select>
            </div>
            <div class="field">
              <label class="label">{{ t('employee_modal.subject') }} <span class="text-gray-400">{{ t('employee_modal.optional') }}</span></label>
              <input v-model="generateForm.subject" class="input"
                     v-bind="{ placeholder: t('employee_modal.subject_ph') }" />
            </div>
            <div class="flex gap-2 pt-2">
              <button type="button" class="btn-secondary text-sm" @click="previewContract" :disabled="previewLoading">
                {{ previewLoading ? t('employee_modal.previewing') : t('employee_modal.preview') }}
              </button>
              <button type="button" class="btn-primary text-sm" @click="submitGenerateContract" :disabled="generating">
                {{ generating ? t('employee_modal.generating') : t('employee_modal.generate_save') }}
              </button>
            </div>
          </div>
          <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 overflow-y-auto max-h-[60vh]">
            <div v-if="!previewHtml" class="text-sm text-gray-500 text-center py-12">
              {{ t('employee_modal.preview_empty') }}
            </div>
            <div v-else class="prose prose-sm max-w-none" v-html="previewHtml"></div>
          </div>
        </div>
        <div class="px-5 py-3 border-t border-gray-200 flex justify-end gap-2 bg-gray-50">
          <button type="button" class="btn-secondary" @click="showGenerateModal = false">{{ t('employee_modal.close') }}</button>
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
