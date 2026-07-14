<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api/client'
import { useI18n } from '@/locales'
import {
  ArrowLeftIcon, BriefcaseIcon, BanknotesIcon,
  CalendarDaysIcon, ClockIcon, ChartBarIcon, AcademicCapIcon,
  BuildingOffice2Icon, ExclamationTriangleIcon, DocumentTextIcon,
  ClipboardDocumentListIcon,
} from '@heroicons/vue/24/outline'

/**
 * Employee360 — aggregator profile karyawan untuk overview lengkap
 * (Sprint 4 / CC-4). Pakai endpoint GET /employees/{id}/360 yang
 * return 12 section sekaligus.
 */

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const employeeId = computed(() => Number(route.params.id))
const data = ref(null)
const loading = ref(false)

async function fetchView() {
  loading.value = true
  try {
    const res = await api.get(`/employees/${employeeId.value}/360`)
    data.value = res.data.data
  } catch (e) {
    data.value = null
  } finally {
    loading.value = false
  }
}

onMounted(fetchView)
watch(employeeId, fetchView)

const profile     = computed(() => data.value?.sections?.profile     || {})
const employment  = computed(() => data.value?.sections?.employment  || {})
const compensation= computed(() => data.value?.sections?.compensation|| {})
const leaveS      = computed(() => data.value?.sections?.leave_summary || {})
const attendance  = computed(() => data.value?.sections?.attendance  || {})
const payrollYtd  = computed(() => data.value?.sections?.payroll_ytd || {})
const performance = computed(() => data.value?.sections?.performance || {})
const training    = computed(() => data.value?.sections?.training    || {})
const assets      = computed(() => data.value?.sections?.assets      || {})
const disciplinary= computed(() => data.value?.sections?.disciplinary|| {})
const documents   = computed(() => data.value?.sections?.documents   || {})
const lifecycle   = computed(() => data.value?.sections?.lifecycle   || {})

function fmtCurrency(value) {
  if (value === null || value === undefined || value === '') return '-'
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'id-ID', {
    style: 'currency', currency: 'IDR', maximumFractionDigits: 0,
  }).format(Number(value))
}

function fmtDate(value) {
  if (!value) return '-'
  const date = new Date(value.length <= 10 ? `${value}T00:00:00` : value)
  return date.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
  })
}

function fmtDateTime(value) {
  if (!value) return '-'
  return new Date(value).toLocaleString(locale.value === 'en' ? 'en-US' : 'id-ID', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

function employmentStatusLabel(value) {
  const supported = ['PKWT', 'PKWTT', 'PROBATION', 'HARIAN', 'MAGANG', 'OUTSOURCING']
  return supported.includes(value) ? t(`employee_form.status_${value.toLowerCase()}`) : value
}

function probationStatusLabel(value) {
  const supported = ['ONGOING', 'PASSED', 'FAILED', 'EXTENDED', 'NA']
  return supported.includes(value) ? t(`employee_360.probation_${value.toLowerCase()}`) : value
}
</script>

<template>
  <div>
    <button class="text-sm text-gray-600 hover:text-primary-600 flex items-center gap-1 mb-3"
      @click="router.push('/employees')">
      <ArrowLeftIcon class="w-4 h-4" /> {{ t('employee_360.back') }}
    </button>

    <div v-if="loading" class="text-center py-16 text-gray-400">{{ t('employee_360.loading') }}</div>
    <div v-else-if="!data" class="text-center py-16 text-rose-600">
      {{ t('employee_360.unavailable') }}
    </div>

    <div v-else class="space-y-4">
      <!-- HEADER profile -->
      <div class="bg-white rounded-lg border border-gray-200 p-5">
        <div class="flex items-start gap-4 flex-wrap">
          <div class="w-20 h-20 rounded-full bg-gradient-to-br from-primary-500 to-primary-700 text-white flex items-center justify-center text-2xl font-bold flex-shrink-0">
            {{ (profile.full_name || '?').split(/\s+/).slice(0, 2).map(s => s[0]).join('').toUpperCase() }}
          </div>
          <div class="flex-1 min-w-0">
            <h1 class="text-2xl font-bold">{{ profile.full_name }}</h1>
            <p class="text-sm text-gray-500 mt-0.5">
              {{ employment.position_name }}
              <span v-if="employment.department_name"> · {{ employment.department_name }}</span>
              <span v-if="employment.branch_name"> · {{ employment.branch_name }}</span>
            </p>
            <div class="flex items-center gap-2 flex-wrap mt-2">
              <span class="text-xs font-mono px-2 py-0.5 rounded bg-gray-100 text-gray-700">
                {{ profile.employee_number }}
              </span>
              <span v-if="profile.is_active" class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">
                ● {{ t('employee_360.active') }}
              </span>
              <span v-else class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">
                {{ t('employee_360.inactive') }}
              </span>
              <span class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-700">
                {{ employmentStatusLabel(employment.employment_status) }}
              </span>
              <span v-if="employment.tenure_label" class="text-xs text-gray-500">
                · {{ t('employee_360.tenure', { value: employment.tenure_label }) }}
              </span>
            </div>
          </div>
          <div class="text-right">
            <p class="text-xs text-gray-500">{{ t('employee_360.last_update') }}</p>
            <p class="text-xs font-mono">{{ fmtDateTime(data.generated_at) }}</p>
          </div>
        </div>
      </div>

      <!-- Active alerts -->
      <div v-if="lifecycle.active_resignation || lifecycle.active_termination"
        class="bg-rose-50 border border-rose-300 rounded-lg p-4 text-sm">
        <div class="flex items-start gap-2">
          <ExclamationTriangleIcon class="w-5 h-5 text-rose-600 mt-0.5 flex-shrink-0" />
          <div>
            <strong class="text-rose-900">{{ t('employee_360.exit_alert') }}</strong>
            <div v-if="lifecycle.active_resignation" class="text-rose-800 mt-0.5">
              {{ t('employee_360.resignation_active', {
                status: lifecycle.active_resignation.status,
                date: fmtDate(lifecycle.active_resignation.last_working_date),
              }) }}
            </div>
            <div v-if="lifecycle.active_termination" class="text-rose-800 mt-0.5">
              {{ t('employee_360.termination_active', {
                reference: lifecycle.active_termination.reference_number,
                status: lifecycle.active_termination.status,
                date: fmtDate(lifecycle.active_termination.planned_termination_date),
              }) }}
            </div>
          </div>
        </div>
      </div>

      <!-- 2-col grid -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Employment -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <BriefcaseIcon class="w-4 h-4 text-blue-600" /> {{ t('employee_360.employment') }}
          </h3>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between"><dt class="text-gray-500">{{ t('employee_360.join_date') }}</dt><dd>{{ fmtDate(employment.join_date) }}</dd></div>
            <div v-if="employment.probation_end_date" class="flex justify-between">
              <dt class="text-gray-500">{{ t('employee_360.probation') }}</dt>
              <dd>
                {{ fmtDate(employment.probation_end_date) }}
                <span v-if="employment.probation_status"
                  :class="['ml-1 px-1.5 py-0.5 rounded text-xs',
                    employment.probation_status === 'PASSED' ? 'bg-emerald-100 text-emerald-700' :
                    employment.probation_status === 'FAILED' ? 'bg-rose-100 text-rose-700' :
                    'bg-amber-100 text-amber-700']">
                  {{ probationStatusLabel(employment.probation_status) }}
                </span>
              </dd>
            </div>
            <div v-if="employment.supervisor_name" class="flex justify-between">
              <dt class="text-gray-500">{{ t('employee_360.supervisor') }}</dt><dd>{{ employment.supervisor_name }}</dd>
            </div>
            <div v-if="employment.work_location_name" class="flex justify-between">
              <dt class="text-gray-500">{{ t('employee_360.work_location') }}</dt><dd>{{ employment.work_location_name }}</dd>
            </div>
            <div v-if="employment.active_contract" class="pt-2 border-t border-gray-100 mt-2">
              <p class="text-xs text-gray-500 mb-1">{{ t('employee_360.active_contract') }}</p>
              <div class="text-xs">
                <span class="font-mono">{{ employment.active_contract.contract_type }}</span>
                · {{ fmtDate(employment.active_contract.start_date) }}
                <span v-if="employment.active_contract.end_date"> → {{ fmtDate(employment.active_contract.end_date) }}</span>
              </div>
            </div>
          </dl>
        </section>

        <!-- Compensation -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <BanknotesIcon class="w-4 h-4 text-emerald-600" /> {{ t('employee_360.compensation') }}
          </h3>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between">
              <dt class="text-gray-500">{{ t('employee_360.basic_salary') }}</dt>
              <dd class="font-semibold">{{ fmtCurrency(compensation.basic_salary || 0) }}</dd>
            </div>
            <div v-if="compensation.fixed_allowance" class="flex justify-between">
              <dt class="text-gray-500">{{ t('employee_360.fixed_allowance') }}</dt>
              <dd>{{ fmtCurrency(compensation.fixed_allowance) }}</dd>
            </div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ t('employee_360.ptkp') }}</dt><dd>{{ compensation.ptkp_status || '-' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ t('employee_360.npwp') }}</dt><dd class="font-mono text-xs">{{ compensation.npwp_masked || '-' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ t('employee_360.bank') }}</dt><dd>{{ compensation.bank_name || '-' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ t('employee_360.bank_account') }}</dt><dd class="font-mono text-xs">{{ compensation.bank_account_masked || '-' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ t('employee_360.bpjs_health') }}</dt><dd class="font-mono text-xs">{{ compensation.bpjs_kes_number || '-' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ t('employee_360.bpjs_employment') }}</dt><dd class="font-mono text-xs">{{ compensation.bpjs_tk_number || '-' }}</dd></div>
          </dl>
        </section>

        <!-- Payroll YTD -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <BanknotesIcon class="w-4 h-4 text-amber-600" /> {{ t('employee_360.payroll_ytd', { year: payrollYtd.period_year }) }}
          </h3>
          <div class="grid grid-cols-2 gap-2 text-sm">
            <div>
              <div class="text-xs text-gray-500">{{ t('employee_360.gross_total') }}</div>
              <div class="font-semibold">{{ fmtCurrency(payrollYtd.gross_total || 0) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('employee_360.net_total') }}</div>
              <div class="font-semibold text-emerald-700">{{ fmtCurrency(payrollYtd.net_total || 0) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('employee_360.pph21') }}</div>
              <div>{{ fmtCurrency(payrollYtd.pph21_total || 0) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('employee_360.bpjs') }}</div>
              <div>{{ fmtCurrency(payrollYtd.bpjs_total || 0) }}</div>
            </div>
          </div>
          <p class="text-xs text-gray-400 mt-2">{{ t('employee_360.payslips', { count: payrollYtd.payslip_count || 0 }) }}</p>
        </section>

        <!-- Attendance MTD -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <ClockIcon class="w-4 h-4 text-cyan-600" /> {{ t('employee_360.attendance_mtd') }}
          </h3>
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div class="bg-emerald-50 rounded p-2 text-center">
              <div class="text-xl font-bold text-emerald-700">{{ attendance.present_count || 0 }}</div>
              <div class="text-xs">{{ t('employee_360.present') }}</div>
            </div>
            <div class="bg-amber-50 rounded p-2 text-center">
              <div class="text-xl font-bold text-amber-700">{{ attendance.late_count || 0 }}</div>
              <div class="text-xs">{{ t('employee_360.late') }}</div>
            </div>
            <div class="bg-rose-50 rounded p-2 text-center">
              <div class="text-xl font-bold text-rose-700">{{ attendance.absent_count || 0 }}</div>
              <div class="text-xs">{{ t('employee_360.absent') }}</div>
            </div>
            <div class="bg-blue-50 rounded p-2 text-center">
              <div class="text-xl font-bold text-blue-700">{{ attendance.overtime_hours || 0 }}</div>
              <div class="text-xs">{{ t('employee_360.overtime_hours') }}</div>
            </div>
          </div>
        </section>

        <!-- Leave -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <CalendarDaysIcon class="w-4 h-4 text-indigo-600" /> {{ t('employee_360.leave_balance', { year: leaveS.period_year }) }}
          </h3>
          <div v-if="(leaveS.balances || []).length === 0" class="text-sm text-gray-400 italic">
            {{ t('employee_360.no_leave') }}
          </div>
          <div v-else class="space-y-1 text-sm">
            <div v-for="b in leaveS.balances" :key="b.id" class="flex justify-between">
              <span>{{ b.leave_type }}</span>
              <span class="font-semibold">{{ t('employee_360.leave_days', { remaining: b.remaining, entitled: b.entitled }) }}</span>
            </div>
          </div>
        </section>

        <!-- Performance -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <ChartBarIcon class="w-4 h-4 text-purple-600" /> {{ t('employee_360.performance') }}
          </h3>
          <div v-if="performance.last_review" class="text-sm">
            <div class="text-xs text-gray-500">{{ t('employee_360.last_review', { cycle: performance.last_review.cycle_name }) }}</div>
            <div class="text-xl font-bold mt-0.5">{{ performance.last_review.overall_score || '-' }}/100</div>
            <div v-if="performance.last_review.recommendation" class="text-xs mt-1">
              {{ t('employee_360.recommendation') }} <strong>{{ performance.last_review.recommendation }}</strong>
            </div>
          </div>
          <div class="text-xs text-gray-500 mt-2">
            {{ t('employee_360.active_goals') }} <strong>{{ performance.active_goals || 0 }}</strong>
          </div>
        </section>

        <!-- Training -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <AcademicCapIcon class="w-4 h-4 text-blue-600" /> {{ t('employee_360.training') }}
          </h3>
          <div class="grid grid-cols-2 gap-2 text-sm">
            <div>
              <div class="text-xs text-gray-500">{{ t('employee_360.completed_last_year') }}</div>
              <div class="text-xl font-bold">{{ training.completed_last_year || 0 }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('employee_360.training_active') }}</div>
              <div class="text-xl font-bold">{{ training.active_enrollments || 0 }}</div>
            </div>
          </div>
          <div v-if="(training.recent_completions || []).length > 0" class="mt-2 pt-2 border-t border-gray-100">
            <p class="text-xs text-gray-500 mb-1">{{ t('employee_360.recent') }}</p>
            <ul class="text-xs space-y-0.5">
              <li v-for="t in training.recent_completions.slice(0, 3)" :key="t.id">
                ✓ {{ t.training_title }}
              </li>
            </ul>
          </div>
        </section>

        <!-- Assets -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <BuildingOffice2Icon class="w-4 h-4 text-orange-600" /> {{ t('employee_360.assets') }}
          </h3>
          <div class="text-sm">
            <div class="text-xs text-gray-500">{{ t('employee_360.assigned_assets') }} <strong>{{ (assets.assigned || []).length }}</strong></div>
            <ul v-if="(assets.assigned || []).length > 0" class="mt-1 text-xs space-y-0.5">
              <li v-for="a in assets.assigned" :key="a.id">
                <span class="font-mono">{{ a.asset_number }}</span> · {{ a.name }}
                <span class="text-gray-500">({{ a.category }})</span>
              </li>
            </ul>
            <p class="text-xs text-gray-400 mt-2">{{ t('employee_360.asset_history', { count: assets.history_count || 0 }) }}</p>
          </div>
        </section>

        <!-- Disciplinary -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <ExclamationTriangleIcon class="w-4 h-4 text-rose-600" /> {{ t('employee_360.disciplinary') }}
          </h3>
          <div v-if="disciplinary.active_count > 0" class="bg-rose-50 border border-rose-200 rounded p-2 mb-2 text-xs">
            <strong>{{ t('employee_360.active_warnings', { count: disciplinary.active_count }) }}</strong>
            <span v-if="disciplinary.highest_active"> · {{ t('employee_360.highest_warning', { level: disciplinary.highest_active }) }}</span>
          </div>
          <div v-if="(disciplinary.recent || []).length > 0" class="text-xs space-y-1">
            <div v-for="r in disciplinary.recent.slice(0, 3)" :key="r.id"
              class="flex items-center gap-2">
              <span :class="['px-1.5 py-0.5 rounded text-xs',
                ['SP3','SUSPEND','TERMINATION'].includes(r.level) ? 'bg-rose-200 text-rose-800' :
                r.level === 'SP2' ? 'bg-amber-100 text-amber-700' :
                'bg-blue-100 text-blue-700']">
                {{ r.level }}
              </span>
              <span class="text-gray-600 truncate">{{ r.subject }}</span>
            </div>
          </div>
          <p v-else class="text-xs text-gray-400 italic">{{ t('employee_360.no_warning') }}</p>
        </section>

        <!-- Documents -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <DocumentTextIcon class="w-4 h-4 text-gray-600" /> {{ t('employee_360.documents') }}
          </h3>
          <div class="grid grid-cols-2 gap-2 text-sm">
            <div>
              <div class="text-xs text-gray-500">{{ t('employee_360.contracts') }}</div>
              <div class="text-xl font-bold">{{ documents.contract_count || 0 }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">{{ t('employee_360.other_documents') }}</div>
              <div class="text-xl font-bold">{{ documents.document_count || 0 }}</div>
            </div>
          </div>
        </section>

        <!-- Lifecycle -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <ClipboardDocumentListIcon class="w-4 h-4 text-indigo-600" /> {{ t('employee_360.lifecycle_tasks') }}
          </h3>
          <div v-if="lifecycle.onboarding_progress" class="mb-3">
            <div class="text-xs text-gray-500 mb-1">{{ t('employee_360.onboarding') }}</div>
            <div class="flex items-center gap-2">
              <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="bg-emerald-500 h-full"
                  :style="{ width: lifecycle.onboarding_progress.percent + '%' }"></div>
              </div>
              <span class="text-xs font-mono">{{ lifecycle.onboarding_progress.percent }}%</span>
            </div>
            <p class="text-xs text-gray-500 mt-0.5">
              {{ lifecycle.onboarding_progress.completed }}/{{ lifecycle.onboarding_progress.total }}
              <span v-if="lifecycle.onboarding_progress.overdue > 0" class="text-rose-600">
                · {{ t('employee_360.overdue', { count: lifecycle.onboarding_progress.overdue }) }}
              </span>
            </p>
          </div>
          <div v-if="lifecycle.offboarding_progress">
            <div class="text-xs text-gray-500 mb-1">{{ t('employee_360.offboarding') }}</div>
            <div class="flex items-center gap-2">
              <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="bg-amber-500 h-full"
                  :style="{ width: lifecycle.offboarding_progress.percent + '%' }"></div>
              </div>
              <span class="text-xs font-mono">{{ lifecycle.offboarding_progress.percent }}%</span>
            </div>
            <p class="text-xs text-gray-500 mt-0.5">
              {{ lifecycle.offboarding_progress.completed }}/{{ lifecycle.offboarding_progress.total }}
            </p>
          </div>
          <p v-if="!lifecycle.onboarding_progress && !lifecycle.offboarding_progress"
            class="text-xs text-gray-400 italic">
            {{ t('employee_360.no_active_tasks') }}
          </p>
        </section>
      </div>
    </div>
  </div>
</template>
