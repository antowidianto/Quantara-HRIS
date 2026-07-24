<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { fmtDate, fmtCurrency } from '@/composables/useResource'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon,
  CalculatorIcon,
  UserGroupIcon,
  XMarkIcon,
  ShieldCheckIcon,
} from '@heroicons/vue/24/outline'
import PayrollPeriodModal from '@/components/PayrollPeriodModal.vue'
import { useI18n } from '@/locales'

const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const { t } = useI18n()
const items = ref([])
const showModal = ref(false)
const showApprovalModal = ref(false)
const approvalLoading = ref(false)
const approvalDetail = ref(null)
const approvalReviewConfirmed = ref(false)
const approvalConfirmationCode = ref('')
const approvalSubmitting = ref(false)
const canConfirmApproval = computed(
  () =>
    approvalReviewConfirmed.value &&
    approvalConfirmationCode.value.trim() === approvalDetail.value?.period?.code
)

async function fetchData() {
  const res = await api.get('/payroll-periods', { params: { per_page: 50 } })
  items.value = res.data.data
}
onMounted(fetchData)

async function calculate(id) {
  const ok = await confirmDialog({
    title: t('payroll.period_list.calc_title'),
    message: t('payroll.period_list.calc_message'),
    okText: t('payroll.period_list.calc_ok'),
    variant: 'primary',
  })
  if (!ok) return
  toast.info(t('payroll.period_list.calc_progress'))
  try {
    await api.post(`/payroll-periods/${id}/calculate`)
    toast.success(t('payroll.period_list.calc_success'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('payroll.period_list.failed'))
  }
}

function openModal() {
  showModal.value = true
}

function closeModal() {
  showModal.value = false
}

function handlePeriodCreated(period) {
  fetchData()
  // Navigate ke detail periode yang baru dibuat
  router.push(`/payroll/periods/${period.id}`)
}

async function openApprovalDetail(period) {
  showApprovalModal.value = true
  approvalLoading.value = true
  approvalDetail.value = { period, checkers: [] }
  approvalReviewConfirmed.value = false
  approvalConfirmationCode.value = ''
  try {
    const response = await api.get(`/payroll-periods/${period.id}/approval-detail`)
    approvalDetail.value = response.data?.data
  } catch (e) {
    toast.error(e.response?.data?.message || t('payroll.period_list.approval_load_failed'))
    showApprovalModal.value = false
  } finally {
    approvalLoading.value = false
  }
}

function closeApprovalDetail() {
  if (approvalSubmitting.value) return
  showApprovalModal.value = false
  approvalDetail.value = null
}

async function approveFromTable() {
  if (!approvalDetail.value?.can_approve || !canConfirmApproval.value) return
  approvalSubmitting.value = true
  let approved = false
  try {
    await api.post(`/payroll-periods/${approvalDetail.value.period.id}/approve`, {
      review_confirmed: true,
      confirmation_code: approvalConfirmationCode.value.trim(),
    })
    toast.success(t('payroll.period_list.approval_success'))
    approved = true
  } catch (e) {
    toast.error(e.response?.data?.message || t('payroll.period_list.approval_failed'))
  } finally {
    approvalSubmitting.value = false
    if (approved) {
      closeApprovalDetail()
      await fetchData()
    } else if (showApprovalModal.value) {
      try {
        const response = await api.get(
          `/payroll-periods/${approvalDetail.value.period.id}/approval-detail`
        )
        approvalDetail.value = response.data?.data
      } catch {
        closeApprovalDetail()
      }
    }
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('payroll.period_list.title') }}</h1>
      <button v-if="auth.can('payroll.manage')" @click="openModal" class="btn-primary">
        <PlusIcon class="w-4 h-4" /> {{ t('payroll.period_list.new_period') }}
      </button>
    </div>
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('payroll.period_list.col_code') }}</th>
              <th>{{ t('payroll.period_list.col_name') }}</th>
              <th>{{ t('payroll.period_list.col_period') }}</th>
              <th>{{ t('payroll.period_list.col_pay_date') }}</th>
              <th>{{ t('payroll.period_list.col_total_net') }}</th>
              <th>{{ t('payroll.period_list.col_status') }}</th>
              <th class="text-right">{{ t('payroll.period_list.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="items.length === 0">
              <td colspan="7" class="text-center py-8 text-gray-400">
                {{ t('payroll.period_list.no_periods') }}
              </td>
            </tr>
            <tr v-for="p in items" :key="p.id">
              <td class="font-mono text-xs">{{ p.code }}</td>
              <td>
                <RouterLink
                  :to="`/payroll/periods/${p.id}`"
                  class="text-primary-600 hover:underline"
                  >{{ p.name }}</RouterLink
                >
              </td>
              <td>{{ fmtDate(p.start_date) }} → {{ fmtDate(p.end_date) }}</td>
              <td>{{ fmtDate(p.pay_date) }}</td>
              <td>{{ fmtCurrency(p.total_net) }}</td>
              <td>
                <span
                  :class="{
                    'badge-gray': p.status === 'DRAFT',
                    'badge-blue': p.status === 'CALCULATED',
                    'badge-green': ['FINALIZED', 'PAID', 'CLOSED'].includes(p.status),
                    'badge-yellow': p.status === 'APPROVED',
                  }"
                  >{{ p.status }}</span
                >
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button
                    class="btn-secondary"
                    @click="openApprovalDetail(p)"
                    :title="t('payroll.period_list.approval_detail')"
                  >
                    <UserGroupIcon class="w-4 h-4" />
                    {{ t('payroll.period_list.approval_detail') }}
                  </button>
                  <button
                    v-if="p.approval_action?.can_approve"
                    class="btn-success"
                    @click="openApprovalDetail(p)"
                    :title="p.approval_action.current_step_name"
                  >
                    <ShieldCheckIcon class="w-4 h-4" />
                    {{ t('payroll.period_list.approval_action') }}
                  </button>
                  <button
                    v-if="auth.can('payroll.calculate') && ['DRAFT', 'CALCULATED'].includes(p.status)"
                    class="btn-secondary"
                    @click="calculate(p.id)"
                    :title="t('payroll.period_list.calculate')"
                  >
                    <CalculatorIcon class="w-4 h-4" /> {{ t('payroll.period_list.calculate') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal -->
    <PayrollPeriodModal :show="showModal" @close="closeModal" @created="handlePeriodCreated" />

    <div
      v-if="showApprovalModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      @click.self="closeApprovalDetail"
    >
      <div class="w-full max-w-6xl rounded-xl bg-white shadow-xl">
        <div class="flex items-start justify-between border-b px-6 py-4">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">
              {{ t('payroll.period_list.approval_modal_title') }}
            </h2>
            <p class="mt-1 text-sm text-gray-500">
              {{ approvalDetail?.period?.code }} · {{ approvalDetail?.period?.name }}
            </p>
          </div>
          <button class="rounded p-1 text-gray-400 hover:bg-gray-100" @click="closeApprovalDetail">
            <XMarkIcon class="h-5 w-5" />
          </button>
        </div>

        <div class="max-h-[70vh] overflow-y-auto p-6">
          <div v-if="approvalLoading" class="py-12 text-center text-sm text-gray-500">
            {{ t('payroll.period_list.approval_loading') }}
          </div>

          <template v-else-if="approvalDetail">
            <div class="mb-5 grid gap-3 sm:grid-cols-3">
              <div class="rounded-lg bg-gray-50 p-3">
                <div class="text-xs uppercase tracking-wide text-gray-500">
                  {{ t('payroll.period_list.approval_preparer') }}
                </div>
                <div class="mt-1 font-medium text-gray-900">
                  {{ approvalDetail.preparer?.name || '-' }}
                </div>
                <div class="text-xs text-gray-500">
                  {{ approvalDetail.preparer?.role_name || '-' }}
                </div>
              </div>
              <div class="rounded-lg bg-gray-50 p-3">
                <div class="text-xs uppercase tracking-wide text-gray-500">
                  {{ t('payroll.period_list.approval_progress') }}
                </div>
                <div class="mt-1 font-medium text-gray-900">
                  {{ approvalDetail.approved_count }} / {{ approvalDetail.required_approvals }}
                </div>
              </div>
              <div class="rounded-lg bg-gray-50 p-3">
                <div class="text-xs uppercase tracking-wide text-gray-500">
                  {{ t('payroll.period_list.approval_submission') }}
                </div>
                <div class="mt-1 font-medium text-gray-900">
                  {{
                    approvalDetail.period?.submitted_at
                      ? fmtDate(approvalDetail.period.submitted_at)
                      : t('payroll.period_list.approval_not_submitted')
                  }}
                </div>
              </div>
            </div>

            <div
              class="mb-4 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900"
            >
              <span class="font-medium">{{ t('payroll.period_list.approval_chain') }}:</span>
              {{ approvalDetail.chain?.name || t('payroll.period_list.approval_no_chain') }}
            </div>

            <div class="mb-5 grid gap-3 sm:grid-cols-4">
              <div class="rounded-lg border p-3">
                <div class="text-xs text-gray-500">
                  {{ t('payroll.period_list.payroll_employees') }}
                </div>
                <div class="mt-1 font-semibold">
                  {{ approvalDetail.payroll_summary?.employee_count || 0 }}
                </div>
              </div>
              <div class="rounded-lg border p-3">
                <div class="text-xs text-gray-500">
                  {{ t('payroll.period_list.payroll_gross') }}
                </div>
                <div class="mt-1 font-semibold">
                  {{ fmtCurrency(approvalDetail.payroll_summary?.total_gross) }}
                </div>
              </div>
              <div class="rounded-lg border p-3">
                <div class="text-xs text-gray-500">
                  {{ t('payroll.period_list.payroll_deduction') }}
                </div>
                <div class="mt-1 font-semibold">
                  {{ fmtCurrency(approvalDetail.payroll_summary?.total_deduction) }}
                </div>
              </div>
              <div class="rounded-lg border p-3">
                <div class="text-xs text-gray-500">{{ t('payroll.period_list.payroll_net') }}</div>
                <div class="mt-1 font-semibold text-primary-700">
                  {{ fmtCurrency(approvalDetail.payroll_summary?.total_net) }}
                </div>
              </div>
            </div>

            <div
              v-if="approvalDetail.insufficient_checkers"
              class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            >
              {{ t('payroll.period_list.approval_insufficient') }}
            </div>

            <div class="mb-2 flex items-center justify-between">
              <h3 class="font-semibold text-gray-900">
                {{ t('payroll.period_list.approval_checker_list') }}
              </h3>
              <span class="text-sm text-gray-500">
                {{ approvalDetail.checkers.length }} {{ t('payroll.period_list.approval_checker') }}
              </span>
            </div>

            <div class="overflow-x-auto rounded-lg border">
              <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                  <tr>
                    <th class="px-4 py-3">{{ t('payroll.period_list.approval_step') }}</th>
                    <th class="px-4 py-3">{{ t('payroll.period_list.approval_name') }}</th>
                    <th class="px-4 py-3">{{ t('payroll.period_list.approval_role') }}</th>
                    <th class="px-4 py-3">{{ t('payroll.period_list.approval_email') }}</th>
                    <th class="px-4 py-3">{{ t('payroll.period_list.approval_notification') }}</th>
                    <th class="px-4 py-3">{{ t('payroll.period_list.approval_status') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                  <tr v-if="approvalDetail.checkers.length === 0">
                    <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                      {{ t('payroll.period_list.approval_no_checker') }}
                    </td>
                  </tr>
                  <tr
                    v-for="checker in approvalDetail.checkers"
                    :key="`${checker.step_order}-${checker.id || 'unassigned'}`"
                  >
                    <td class="px-4 py-3">
                      <div class="font-medium text-gray-900">{{ checker.step_order }}</div>
                      <div class="text-xs text-gray-500">{{ checker.step_name }}</div>
                    </td>
                    <td class="px-4 py-3 font-medium text-gray-900">
                      {{ checker.name || t('payroll.period_list.approval_unassigned') }}
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ checker.role_name }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ checker.email || '-' }}</td>
                    <td class="px-4 py-3">
                      <span v-if="checker.email_queued" class="badge-green">
                        {{ t('payroll.period_list.approval_notif_app_email') }}
                      </span>
                      <span v-else-if="checker.notified_in_app" class="badge-blue">
                        {{ t('payroll.period_list.approval_notif_app') }}
                      </span>
                      <span v-else class="badge-gray">
                        {{ t('payroll.period_list.approval_notif_pending') }}
                      </span>
                    </td>
                    <td class="px-4 py-3">
                      <span v-if="checker.decision === 'APPROVED'" class="badge-green">
                        {{ t('payroll.period_list.approval_approved') }}
                      </span>
                      <span v-else-if="checker.decision === 'REJECTED'" class="badge-red">
                        {{ t('payroll.period_list.approval_rejected') }}
                      </span>
                      <span v-else class="badge-yellow">
                        {{ t('payroll.period_list.approval_waiting') }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="mb-2 mt-6 flex items-center justify-between">
              <h3 class="font-semibold text-gray-900">
                {{ t('payroll.period_list.payroll_data_title') }}
              </h3>
              <span class="text-xs text-gray-500">
                {{ approvalDetail.payroll_rows?.length || 0 }}
                {{ t('payroll.period_list.payroll_rows') }}
              </span>
            </div>
            <div class="max-h-72 overflow-auto rounded-lg border">
              <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase text-gray-500">
                  <tr>
                    <th class="px-3 py-2">NIP</th>
                    <th class="px-3 py-2">{{ t('payroll.period_list.approval_name') }}</th>
                    <th class="px-3 py-2 text-right">
                      {{ t('payroll.period_list.payroll_basic') }}
                    </th>
                    <th class="px-3 py-2 text-right">
                      {{ t('payroll.period_list.payroll_gross') }}
                    </th>
                    <th class="px-3 py-2 text-right">PPh21</th>
                    <th class="px-3 py-2 text-right">BPJS</th>
                    <th class="px-3 py-2 text-right">THP</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="row in approvalDetail.payroll_rows || []" :key="row.id">
                    <td class="px-3 py-2 font-mono text-xs">{{ row.employee_number }}</td>
                    <td class="px-3 py-2">{{ row.employee_name }}</td>
                    <td class="px-3 py-2 text-right">{{ fmtCurrency(row.basic_salary) }}</td>
                    <td class="px-3 py-2 text-right">{{ fmtCurrency(row.gross_earning) }}</td>
                    <td class="px-3 py-2 text-right">{{ fmtCurrency(row.pph21_amount) }}</td>
                    <td class="px-3 py-2 text-right">
                      {{
                        fmtCurrency(
                          Number(row.bpjs_kes_employee) +
                            Number(row.bpjs_jht_employee) +
                            Number(row.bpjs_jp_employee)
                        )
                      }}
                    </td>
                    <td class="px-3 py-2 text-right font-semibold">
                      {{ fmtCurrency(row.take_home_pay) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div
              v-if="approvalDetail.can_approve"
              class="mt-6 rounded-lg border-2 border-emerald-200 bg-emerald-50 p-4"
            >
              <div class="flex items-center gap-2 font-semibold text-emerald-900">
                <ShieldCheckIcon class="h-5 w-5" />
                {{ t('payroll.period_list.double_validation_title') }}
              </div>
              <label class="mt-3 flex items-start gap-2 text-sm text-emerald-900">
                <input v-model="approvalReviewConfirmed" type="checkbox" class="mt-1" />
                <span>{{ t('payroll.period_list.review_confirmation') }}</span>
              </label>
              <label class="mt-3 block text-sm font-medium text-emerald-900">
                {{
                  t('payroll.period_list.type_period_code', { code: approvalDetail.period.code })
                }}
              </label>
              <input
                v-model="approvalConfirmationCode"
                class="input mt-1 max-w-sm"
                :placeholder="approvalDetail.period.code"
                autocomplete="off"
              />
            </div>
          </template>
        </div>

        <div class="flex justify-end border-t px-6 py-4">
          <button
            v-if="approvalDetail?.can_approve"
            class="btn-success mr-2"
            :disabled="!canConfirmApproval || approvalSubmitting"
            @click="approveFromTable"
          >
            {{
              approvalSubmitting
                ? t('payroll.period_list.approval_processing')
                : t('payroll.period_list.approval_confirm_button')
            }}
          </button>
          <button class="btn-secondary" @click="closeApprovalDetail">
            {{ t('payroll.period_list.approval_close') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
