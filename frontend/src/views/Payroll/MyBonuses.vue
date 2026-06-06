<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { useI18n } from '@/locales'
import { GiftIcon, CalendarDaysIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'

/**
 * Sprint 21+ — Bonus History per karyawan (self-service).
 *
 * Tampilkan riwayat bonus yang diterima karyawan login. Data dari
 * `GET /employees/{id}/bonus-history` (Sprint 20+).
 *
 * Default hanya tampilkan run yang sudah APPROVED/PAID — DRAFT/CALCULATED
 * confidential. HR yang butuh melihat data pending harus pakai admin
 * view di profile karyawan (separate page, defer Sprint 22+).
 *
 * Pattern mirip `MyTransfers.vue` & `MyPayslips.vue` — self-access ke
 * data milik diri sendiri tanpa perlu permission khusus.
 */
const auth = useAuthStore()
const toast = useToast()
const { t } = useI18n()
const loading = ref(false)
const history = ref([])
const stats = ref({ total_amount: 0, count: 0, by_kind: {} })

const kindLabel = (kind) => {
  const k = (kind || '').toLowerCase()
  const key = `payroll.my_bonuses.kinds.${k}`
  const trans = t(key)
  return trans === key ? kind : trans
}

const KIND_COLOR = {
  ANNUAL:        'badge-blue',
  KPI:           'badge-green',
  MILESTONE:     'badge-purple',
  SIGNON:        'badge-yellow',
  RETENTION:     'badge-pink',
  DISCRETIONARY: 'badge-gray',
}
const STATUS_BADGE = {
  APPROVED: 'badge-yellow',
  PAID:     'badge-green',
}

const employeeId = computed(() => auth.employee?.id || auth.user?.employee_id)
const noEmployeeLink = ref(false)
const employeeName = computed(() => auth.employee?.full_name || auth.user?.name || 'Anda')
const includePending = ref(false)            // Toggle di empty state untuk lihat pending
const lastFetchedAt = ref(null)              // Timestamp terakhir fetch berhasil
// Diagnostic dari backend (Sprint 23+) — dipakai panel troubleshooting
// kalau hasil utama kosong supaya user bisa tahu cause langsung dari UI.
const diagnostic = ref(null)

async function fetchData() {
  noEmployeeLink.value = false
  if (! employeeId.value) {
    // Super admin / IT admin / role tanpa employee link → tampilkan
    // diagnostic message, jangan toast error (bukan bug, by design).
    noEmployeeLink.value = true
    return
  }
  loading.value = true
  try {
    const params = {}
    if (includePending.value) params.include_pending = 1
    const res = await api.get(`/employees/${employeeId.value}/bonus-history`, { params })
    history.value = res.data?.data?.history || []
    stats.value = res.data?.data?.stats || { total_amount: 0, count: 0, by_kind: {} }
    diagnostic.value = res.data?.data?.diagnostic || null
    lastFetchedAt.value = new Date()
  } catch (e) {
    if (e.response?.status === 403) {
      toast.error(t('payroll.my_bonuses.no_access'))
    } else {
      toast.error(e.response?.data?.message || t('payroll.my_bonuses.load_failed'))
    }
    console.error('[MyBonuses] fetch failed', e.response?.status, e.response?.data)
  } finally {
    loading.value = false
  }
}

/**
 * Interpret diagnostic dari backend ke pesan actionable. Return null
 * kalau tidak ada anomali (data normal, cuma karena belum ada bonus
 * di-approve).
 */
const diagnosticMessage = computed(() => {
  const d = diagnostic.value
  if (! d) return null
  // Cross-company: user login di company X, tapi employee record di company Y
  if (d.cross_company) {
    return {
      level: 'error',
      title: t('payroll.my_bonuses.diagnostics.cross_company_title'),
      detail: t('payroll.my_bonuses.diagnostics.cross_company_detail', {
        userTenant: d.user_tenant_company_id,
        employeeId: d.employee_id,
        employeeCompany: d.employee_company_id,
      }),
    }
  }
  // Ada banyak recipient di status APPROVED/PAID tapi semuanya di-exclude
  const approvedish = (d.recipients_by_status?.APPROVED || 0) + (d.recipients_by_status?.PAID || 0)
  if (approvedish > 0 && d.excluded_count >= approvedish) {
    return {
      level: 'error',
      title: t('payroll.my_bonuses.diagnostics.excluded_title'),
      detail: t('payroll.my_bonuses.diagnostics.excluded_detail', {
        approvedish,
        excludedCount: d.excluded_count,
        totalRecipients: d.total_recipients_db,
      }),
    }
  }
  // Ada recipient pending tapi belum di-approve
  const pending = (d.recipients_by_status?.DRAFT || 0) + (d.recipients_by_status?.CALCULATED || 0)
  if (approvedish === 0 && pending > 0) {
    const statuses = [
      d.recipients_by_status.DRAFT > 0 ? 'DRAFT' : '',
      d.recipients_by_status.CALCULATED > 0 ? 'CALCULATED' : '',
    ].filter(Boolean).join(' / ')
    return {
      level: 'info',
      title: t('payroll.my_bonuses.diagnostics.pending_title', { pending }),
      detail: t('payroll.my_bonuses.diagnostics.pending_detail', { statuses }),
    }
  }
  // Run cancelled
  if ((d.recipients_by_status?.CANCELLED || 0) > 0
      && approvedish === 0 && pending === 0) {
    return {
      level: 'warning',
      title: t('payroll.my_bonuses.diagnostics.cancelled_title'),
      detail: t('payroll.my_bonuses.diagnostics.cancelled_detail', { count: d.recipients_by_status.CANCELLED }),
    }
  }
  // Tidak ada recipient sama sekali
  if (d.total_recipients_db === 0) {
    return {
      level: 'info',
      title: t('payroll.my_bonuses.diagnostics.no_recipient_title'),
      detail: t('payroll.my_bonuses.diagnostics.no_recipient_detail'),
    }
  }
  return null
})

async function togglePending() {
  includePending.value = ! includePending.value
  await fetchData()
}

onMounted(fetchData)

/**
 * Group history by year (period_year) untuk timeline display.
 * Format: { 2027: [...], 2026: [...] } — terbaru dulu.
 */
const historyByYear = computed(() => {
  const grouped = {}
  for (const item of history.value) {
    const year = item.period_year || new Date(item.pay_date).getFullYear()
    if (! grouped[year]) grouped[year] = []
    grouped[year].push(item)
  }
  // Sort years descending
  return Object.keys(grouped)
    .sort((a, b) => Number(b) - Number(a))
    .map(year => ({ year: Number(year), items: grouped[year] }))
})

const taxLabel = (treatment) => {
  switch ((treatment || '').toUpperCase()) {
    case 'NORMAL':
    case 'GROSS':       return { text: t('payroll.my_bonuses.tax.taxable'), cls: 'bg-blue-100 text-blue-700' }
    case 'NON_TAXABLE': return { text: t('payroll.my_bonuses.tax.non_taxable'), cls: 'bg-green-100 text-green-700' }
    case 'GROSS_UP':    return { text: t('payroll.my_bonuses.tax.gross_up'), cls: 'bg-amber-100 text-amber-700' }
    case 'NET':         return { text: t('payroll.my_bonuses.tax.net'), cls: 'bg-amber-100 text-amber-700' }
    default:            return { text: t('payroll.my_bonuses.tax.taxable'), cls: 'bg-gray-100 text-gray-700' }
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <GiftIcon class="w-7 h-7 text-amber-600" />
          {{ t('payroll.my_bonuses.title') }}
        </h1>
        <p class="text-sm text-gray-500">
          {{ t('payroll.my_bonuses.subtitle') }}
        </p>
      </div>
      <div class="flex items-center gap-2">
        <span v-if="lastFetchedAt" class="text-xs text-gray-400">
          {{ t('payroll.my_bonuses.updated_at', { time: lastFetchedAt.toLocaleTimeString() }) }}
        </span>
        <button @click="fetchData" :disabled="loading" class="btn-secondary" :title="t('payroll.my_bonuses.refresh')">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
          {{ t('payroll.my_bonuses.refresh') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="card p-12 text-center text-gray-400">
      {{ t('payroll.my_bonuses.loading') }}
    </div>

    <!-- User account tidak punya employee_id (mis. super admin / IT admin) -->
    <template v-else-if="noEmployeeLink">
      <div class="card p-12 text-center">
        <GiftIcon class="w-16 h-16 mx-auto text-gray-300 mb-3" />
        <h3 class="text-lg font-medium text-gray-700 mb-1">{{ t('payroll.my_bonuses.no_emp_title') }}</h3>
        <p class="text-sm text-gray-500 max-w-lg mx-auto">
          {{ t('payroll.my_bonuses.no_emp_desc', { name: employeeName }) }}
        </p>
        <p class="text-xs text-gray-400 mt-3" v-html="t('payroll.my_bonuses.no_emp_hint')">
        </p>
      </div>
    </template>

    <template v-else-if="history.length === 0">
      <div class="card p-12 text-center">
        <GiftIcon class="w-16 h-16 mx-auto text-gray-300 mb-3" />
        <h3 class="text-lg font-medium text-gray-700 mb-1">
          {{ includePending ? t('payroll.my_bonuses.no_data_pending') : t('payroll.my_bonuses.no_data') }}
        </h3>
        <p class="text-sm text-gray-500 max-w-lg mx-auto">
          {{ t('payroll.my_bonuses.no_data_desc') }}
        </p>
        <!-- Diagnostic dari backend — actionable message berdasarkan
             kondisi data sebenarnya. -->
        <div v-if="diagnosticMessage"
             :class="[
               'mt-4 inline-flex flex-col gap-1 text-left p-3 rounded-lg max-w-md text-xs',
               diagnosticMessage.level === 'error' ? 'bg-red-50 border border-red-200 text-red-900' :
               diagnosticMessage.level === 'warning' ? 'bg-amber-50 border border-amber-200 text-amber-900' :
                                                       'bg-blue-50 border border-blue-200 text-blue-900',
             ]">
          <p class="font-semibold">{{ diagnosticMessage.title }}</p>
          <p>{{ diagnosticMessage.detail }}</p>
        </div>

        <div class="mt-4 inline-flex flex-col gap-2 text-left text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-lg p-3 max-w-md">
          <p class="font-semibold text-gray-700">{{ t('payroll.my_bonuses.status_flow_title') }}</p>
          <ol class="list-decimal list-inside space-y-1">
            <li><span class="font-mono text-gray-600">DRAFT</span> — {{ t('payroll.my_bonuses.status_draft_desc') }}</li>
            <li><span class="font-mono text-gray-600">CALCULATED</span> — {{ t('payroll.my_bonuses.status_calculated_desc') }}</li>
            <li><span class="font-mono text-amber-700">APPROVED</span> — {{ t('payroll.my_bonuses.status_approved_desc') }}</li>
            <li><span class="font-mono text-green-700">PAID</span> — {{ t('payroll.my_bonuses.status_paid_desc') }}</li>
          </ol>
          <p class="text-gray-400 mt-1">
            {{ t('payroll.my_bonuses.status_explanation_timeline') }}
          </p>
        </div>

        <!-- Diagnostic info — kalau HR sudah approve tapi karyawan tidak melihat,
             panel ini bantu identifikasi penyebab (multi-tenant scope, employee_id
             salah, dll). -->
        <details class="mt-4 inline-block max-w-md text-left">
          <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">
            {{ t('payroll.my_bonuses.timeline_help_title') }}
          </summary>
          <div class="mt-2 p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-900 space-y-1.5">
            <ol class="list-decimal list-inside space-y-1">
              <li>{{ t('payroll.my_bonuses.timeline_help_step1') }}</li>
              <li>{{ t('payroll.my_bonuses.timeline_help_step2') }}</li>
              <li>{{ t('payroll.my_bonuses.timeline_help_step3') }}</li>
              <li>{{ t('payroll.my_bonuses.timeline_help_step4') }}</li>
              <li>{{ t('payroll.my_bonuses.timeline_help_step5') }}</li>
            </ol>
            <p class="pt-1 border-t border-amber-200 mt-2">
              <strong>Debug info:</strong> {{ t('payroll.my_bonuses.debug_info', { id: employeeId, filter: includePending ? 'APPROVED + PAID + pending' : 'APPROVED + PAID' }) }}
            </p>
            <details v-if="diagnostic" class="pt-1">
              <summary class="cursor-pointer text-xs text-gray-500">
                {{ t('payroll.my_bonuses.backend_diagnostic') }}
              </summary>
              <pre class="mt-1 p-2 bg-white rounded border border-gray-200 overflow-x-auto text-[10px] font-mono">{{ JSON.stringify(diagnostic, null, 2) }}</pre>
            </details>
          </div>
        </details>

        <div class="mt-4">
          <button @click="togglePending" class="btn-secondary text-xs">
            {{ includePending ? t('payroll.my_bonuses.btn_pending_hide') : t('payroll.my_bonuses.btn_pending_show') }}
          </button>
        </div>
      </div>
    </template>

    <template v-else>
      <!-- Stats summary -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="card p-4">
          <div class="text-xs text-gray-500 mb-1">{{ t('payroll.my_bonuses.total_career') }}</div>
          <div class="text-2xl font-bold text-primary-700">{{ fmtCurrency(stats.total_amount) }}</div>
          <div class="text-xs text-gray-400 mt-1">
            {{ t('payroll.my_bonuses.bonus_received', { count: stats.count }) }}
          </div>
        </div>
        <div class="card p-4 md:col-span-2">
          <div class="text-xs text-gray-500 mb-2">{{ t('payroll.my_bonuses.breakdown_kind') }}</div>
          <div class="flex flex-wrap gap-2">
            <div v-for="(amount, kind) in stats.by_kind"
                 :key="kind"
                 class="flex items-center gap-2 px-3 py-1.5 rounded-md border border-gray-200 bg-gray-50">
              <span :class="KIND_COLOR[kind] || 'badge-gray'">
                {{ kindLabel(kind) }}
              </span>
              <span class="text-sm font-semibold tabular-nums">{{ fmtCurrency(amount) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Timeline by year -->
      <div v-for="group in historyByYear" :key="group.year" class="mb-6">
        <h2 class="text-lg font-semibold text-gray-700 mb-3 flex items-center gap-2">
          <CalendarDaysIcon class="w-5 h-5 text-gray-400" />
          {{ group.year }}
        </h2>
        <div class="card overflow-hidden">
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>{{ t('payroll.my_bonuses.col_bonus') }}</th>
                  <th>{{ t('payroll.my_bonuses.col_kind') }}</th>
                  <th>{{ t('payroll.my_bonuses.col_pay_date') }}</th>
                  <th>{{ t('payroll.my_bonuses.col_tax') }}</th>
                  <th class="text-right">{{ t('payroll.my_bonuses.col_amount') }}</th>
                  <th>{{ t('payroll.my_bonuses.col_status') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="item in group.items" :key="item.id">
                  <td>
                    <div class="font-medium text-sm">{{ item.run_name || item.scheme_name }}</div>
                    <div v-if="item.scheme_code" class="text-xs text-gray-500 font-mono">
                      {{ item.scheme_code }}
                    </div>
                  </td>
                  <td>
                    <span :class="KIND_COLOR[item.scheme_kind] || 'badge-gray'">
                      {{ kindLabel(item.scheme_kind) }}
                    </span>
                  </td>
                  <td class="text-sm">{{ fmtDate(item.pay_date) }}</td>
                  <td>
                    <span :class="['text-xs px-1.5 py-0.5 rounded', taxLabel(item.tax_treatment).cls]">
                      {{ taxLabel(item.tax_treatment).text }}
                    </span>
                  </td>
                  <td class="text-right tabular-nums font-semibold">
                    {{ fmtCurrency(item.amount) }}
                  </td>
                  <td>
                    <span :class="STATUS_BADGE[item.run_status] || 'badge-gray'">
                      {{ item.run_status === 'APPROVED' ? t('payroll.my_bonuses.status_approved_hint') : (item.run_status === 'PAID' ? t('common.paid') : item.run_status) }}
                    </span>
                    <div v-if="item.payroll_period_id" class="text-xs text-gray-400 mt-0.5">
                      {{ t('payroll.my_bonuses.payslip_link') }}
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <p class="text-xs text-gray-400 mt-3">
        {{ t('payroll.my_bonuses.footer_note') }}
      </p>
    </template>
  </div>
</template>