<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api/client'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import {
  ArrowLeftIcon, UserCircleIcon, BriefcaseIcon, BanknotesIcon,
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
</script>

<template>
  <div>
    <button class="text-sm text-gray-600 hover:text-primary-600 flex items-center gap-1 mb-3"
      @click="router.push('/employees')">
      <ArrowLeftIcon class="w-4 h-4" /> Kembali ke list karyawan
    </button>

    <div v-if="loading" class="text-center py-16 text-gray-400">Memuat profile…</div>
    <div v-else-if="!data" class="text-center py-16 text-rose-600">
      Data tidak ditemukan / akses ditolak.
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
                ● Aktif
              </span>
              <span v-else class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">
                Non-aktif
              </span>
              <span class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-700">
                {{ employment.employment_status }}
              </span>
              <span v-if="employment.tenure_label" class="text-xs text-gray-500">
                · Masa kerja: {{ employment.tenure_label }}
              </span>
            </div>
          </div>
          <div class="text-right">
            <p class="text-xs text-gray-500">Last update</p>
            <p class="text-xs font-mono">{{ data.generated_at }}</p>
          </div>
        </div>
      </div>

      <!-- Active alerts -->
      <div v-if="lifecycle.active_resignation || lifecycle.active_termination"
        class="bg-rose-50 border border-rose-300 rounded-lg p-4 text-sm">
        <div class="flex items-start gap-2">
          <ExclamationTriangleIcon class="w-5 h-5 text-rose-600 mt-0.5 flex-shrink-0" />
          <div>
            <strong class="text-rose-900">Karyawan dalam proses keluar.</strong>
            <div v-if="lifecycle.active_resignation" class="text-rose-800 mt-0.5">
              Resignation aktif (status {{ lifecycle.active_resignation.status }}) —
              LWD: {{ fmtDate(lifecycle.active_resignation.last_working_date) }}
            </div>
            <div v-if="lifecycle.active_termination" class="text-rose-800 mt-0.5">
              Termination aktif #{{ lifecycle.active_termination.reference_number }}
              ({{ lifecycle.active_termination.status }}) —
              {{ fmtDate(lifecycle.active_termination.planned_termination_date) }}
            </div>
          </div>
        </div>
      </div>

      <!-- 2-col grid -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Employment -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <BriefcaseIcon class="w-4 h-4 text-blue-600" /> Kepegawaian
          </h3>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between"><dt class="text-gray-500">Tanggal Bergabung</dt><dd>{{ fmtDate(employment.join_date) }}</dd></div>
            <div v-if="employment.probation_end_date" class="flex justify-between">
              <dt class="text-gray-500">Probation</dt>
              <dd>
                {{ fmtDate(employment.probation_end_date) }}
                <span v-if="employment.probation_status"
                  :class="['ml-1 px-1.5 py-0.5 rounded text-xs',
                    employment.probation_status === 'PASSED' ? 'bg-emerald-100 text-emerald-700' :
                    employment.probation_status === 'FAILED' ? 'bg-rose-100 text-rose-700' :
                    'bg-amber-100 text-amber-700']">
                  {{ employment.probation_status }}
                </span>
              </dd>
            </div>
            <div v-if="employment.supervisor_name" class="flex justify-between">
              <dt class="text-gray-500">Supervisor</dt><dd>{{ employment.supervisor_name }}</dd>
            </div>
            <div v-if="employment.work_location_name" class="flex justify-between">
              <dt class="text-gray-500">Lokasi Kerja</dt><dd>{{ employment.work_location_name }}</dd>
            </div>
            <div v-if="employment.active_contract" class="pt-2 border-t border-gray-100 mt-2">
              <p class="text-xs text-gray-500 mb-1">Kontrak Aktif</p>
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
            <BanknotesIcon class="w-4 h-4 text-emerald-600" /> Kompensasi
          </h3>
          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between">
              <dt class="text-gray-500">Gaji Pokok</dt>
              <dd class="font-semibold">{{ fmtCurrency(compensation.basic_salary || 0) }}</dd>
            </div>
            <div v-if="compensation.fixed_allowance" class="flex justify-between">
              <dt class="text-gray-500">Tunjangan Tetap</dt>
              <dd>{{ fmtCurrency(compensation.fixed_allowance) }}</dd>
            </div>
            <div class="flex justify-between"><dt class="text-gray-500">PTKP</dt><dd>{{ compensation.ptkp_status || '-' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">NPWP</dt><dd class="font-mono text-xs">{{ compensation.npwp_masked || '-' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Bank</dt><dd>{{ compensation.bank_name || '-' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">No. Rek</dt><dd class="font-mono text-xs">{{ compensation.bank_account_masked || '-' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">BPJS Kes</dt><dd class="font-mono text-xs">{{ compensation.bpjs_kes_number || '-' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">BPJS TK</dt><dd class="font-mono text-xs">{{ compensation.bpjs_tk_number || '-' }}</dd></div>
          </dl>
        </section>

        <!-- Payroll YTD -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <BanknotesIcon class="w-4 h-4 text-amber-600" /> Payroll YTD ({{ payrollYtd.period_year }})
          </h3>
          <div class="grid grid-cols-2 gap-2 text-sm">
            <div>
              <div class="text-xs text-gray-500">Gross Total</div>
              <div class="font-semibold">{{ fmtCurrency(payrollYtd.gross_total || 0) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Net Total</div>
              <div class="font-semibold text-emerald-700">{{ fmtCurrency(payrollYtd.net_total || 0) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">PPh21</div>
              <div>{{ fmtCurrency(payrollYtd.pph21_total || 0) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">BPJS</div>
              <div>{{ fmtCurrency(payrollYtd.bpjs_total || 0) }}</div>
            </div>
          </div>
          <p class="text-xs text-gray-400 mt-2">{{ payrollYtd.payslip_count || 0 }} payslip</p>
        </section>

        <!-- Attendance MTD -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <ClockIcon class="w-4 h-4 text-cyan-600" /> Attendance MTD
          </h3>
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div class="bg-emerald-50 rounded p-2 text-center">
              <div class="text-xl font-bold text-emerald-700">{{ attendance.present_count || 0 }}</div>
              <div class="text-xs">Hadir</div>
            </div>
            <div class="bg-amber-50 rounded p-2 text-center">
              <div class="text-xl font-bold text-amber-700">{{ attendance.late_count || 0 }}</div>
              <div class="text-xs">Terlambat</div>
            </div>
            <div class="bg-rose-50 rounded p-2 text-center">
              <div class="text-xl font-bold text-rose-700">{{ attendance.absent_count || 0 }}</div>
              <div class="text-xs">Absent</div>
            </div>
            <div class="bg-blue-50 rounded p-2 text-center">
              <div class="text-xl font-bold text-blue-700">{{ attendance.overtime_hours || 0 }}</div>
              <div class="text-xs">OT (jam)</div>
            </div>
          </div>
        </section>

        <!-- Leave -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <CalendarDaysIcon class="w-4 h-4 text-indigo-600" /> Saldo Cuti {{ leaveS.period_year }}
          </h3>
          <div v-if="(leaveS.balances || []).length === 0" class="text-sm text-gray-400 italic">
            Belum ada data cuti.
          </div>
          <div v-else class="space-y-1 text-sm">
            <div v-for="b in leaveS.balances" :key="b.id" class="flex justify-between">
              <span>{{ b.leave_type }}</span>
              <span class="font-semibold">{{ b.remaining }} / {{ b.entitled }} hari</span>
            </div>
          </div>
        </section>

        <!-- Performance -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <ChartBarIcon class="w-4 h-4 text-purple-600" /> Performance
          </h3>
          <div v-if="performance.last_review" class="text-sm">
            <div class="text-xs text-gray-500">Last Review ({{ performance.last_review.cycle_name }})</div>
            <div class="text-xl font-bold mt-0.5">{{ performance.last_review.overall_score || '-' }}/100</div>
            <div v-if="performance.last_review.recommendation" class="text-xs mt-1">
              Rekomendasi: <strong>{{ performance.last_review.recommendation }}</strong>
            </div>
          </div>
          <div class="text-xs text-gray-500 mt-2">
            Active goals: <strong>{{ performance.active_goals || 0 }}</strong>
          </div>
        </section>

        <!-- Training -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <AcademicCapIcon class="w-4 h-4 text-blue-600" /> Training
          </h3>
          <div class="grid grid-cols-2 gap-2 text-sm">
            <div>
              <div class="text-xs text-gray-500">Selesai 1 thn</div>
              <div class="text-xl font-bold">{{ training.completed_last_year || 0 }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Aktif</div>
              <div class="text-xl font-bold">{{ training.active_enrollments || 0 }}</div>
            </div>
          </div>
          <div v-if="(training.recent_completions || []).length > 0" class="mt-2 pt-2 border-t border-gray-100">
            <p class="text-xs text-gray-500 mb-1">Recent</p>
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
            <BuildingOffice2Icon class="w-4 h-4 text-orange-600" /> Aset
          </h3>
          <div class="text-sm">
            <div class="text-xs text-gray-500">Sedang dipinjam: <strong>{{ (assets.assigned || []).length }}</strong></div>
            <ul v-if="(assets.assigned || []).length > 0" class="mt-1 text-xs space-y-0.5">
              <li v-for="a in assets.assigned" :key="a.id">
                <span class="font-mono">{{ a.asset_number }}</span> · {{ a.name }}
                <span class="text-gray-500">({{ a.category }})</span>
              </li>
            </ul>
            <p class="text-xs text-gray-400 mt-2">Total history: {{ assets.history_count || 0 }}</p>
          </div>
        </section>

        <!-- Disciplinary -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <ExclamationTriangleIcon class="w-4 h-4 text-rose-600" /> Surat Peringatan
          </h3>
          <div v-if="disciplinary.active_count > 0" class="bg-rose-50 border border-rose-200 rounded p-2 mb-2 text-xs">
            <strong>{{ disciplinary.active_count }} SP aktif</strong>
            <span v-if="disciplinary.highest_active"> · Tertinggi: {{ disciplinary.highest_active }}</span>
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
          <p v-else class="text-xs text-gray-400 italic">Tidak ada SP. Bagus.</p>
        </section>

        <!-- Documents -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <DocumentTextIcon class="w-4 h-4 text-gray-600" /> Dokumen
          </h3>
          <div class="grid grid-cols-2 gap-2 text-sm">
            <div>
              <div class="text-xs text-gray-500">Kontrak</div>
              <div class="text-xl font-bold">{{ documents.contract_count || 0 }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Dokumen lain</div>
              <div class="text-xl font-bold">{{ documents.document_count || 0 }}</div>
            </div>
          </div>
        </section>

        <!-- Lifecycle -->
        <section class="bg-white rounded-lg border border-gray-200 p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <ClipboardDocumentListIcon class="w-4 h-4 text-indigo-600" /> Lifecycle Tasks
          </h3>
          <div v-if="lifecycle.onboarding_progress" class="mb-3">
            <div class="text-xs text-gray-500 mb-1">Onboarding</div>
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
                · {{ lifecycle.onboarding_progress.overdue }} overdue
              </span>
            </p>
          </div>
          <div v-if="lifecycle.offboarding_progress">
            <div class="text-xs text-gray-500 mb-1">Offboarding</div>
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
            Tidak ada task aktif.
          </p>
        </section>
      </div>
    </div>
  </div>
</template>