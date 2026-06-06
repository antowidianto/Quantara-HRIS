<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { useI18n } from '@/locales'
import {
  PlusIcon, CheckCircleIcon, XCircleIcon, BanknotesIcon, ClockIcon,
  ArrowPathIcon, InformationCircleIcon,
} from '@heroicons/vue/24/outline'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'

const { t } = useI18n()

/**
 * Halaman Kasbon / Pinjaman.
 *
 * Integrasi ke payroll:
 *  - Saat HR/Finance approve loan (PENDING → RUNNING), `installment_amount`
 *    otomatis dipotong dari gaji setiap kali HR jalankan calculatePeriod.
 *  - Saat periode payroll difinalisasi, `paid_installments` bertambah dan
 *    `remaining` berkurang per slip. Loan habis → status COMPLETED.
 *  - Loan dengan status PENDING / REJECTED / CANCELLED tidak ikut dipotong.
 *
 * UI ini menyatukan: pengajuan (untuk semua karyawan), persetujuan
 * (HR/Finance), dan tracking sisa cicilan dengan kolom yang menunjukkan
 * berapa periode tersisa supaya jelas terhubung ke payroll.
 */

const toast = useToast()
const auth = useAuthStore()

const items = ref([])
const meta = ref({ total: 0, page: 1, pages: 1 })
const loading = ref(false)
const filters = reactive({ page: 1, per_page: 20, status: '', mine: 0 })

const showForm = ref(false)
const employees = ref([])
const form = ref({ employee_id: null, principal: 0, installments: 6, reason: '' })

// Hak akses HR/Finance bisa approve atau create utk orang lain.
const canApprove = computed(() => auth.can('loan.approve') || auth.isSuperAdmin)
const canCreateForOthers = computed(() => auth.can('loan.create_for_others') || canApprove.value)

async function fetchData() {
  loading.value = true
  try {
    const params = { page: filters.page, per_page: filters.per_page }
    if (filters.status) params.status = filters.status
    if (filters.mine && auth.employee?.id) params.employee_id = auth.employee.id
    const res = await api.get('/loans', { params })
    items.value = res.data.data || []
    meta.value = res.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || t('loan.load_failed'))
  } finally {
    loading.value = false
  }
}

// Karyawan TIDAK lagi di-load semua sekaligus — picker pakai
// EmployeeSearchSelect server-side untuk dataset besar.
onMounted(() => { fetchData() })

function openForm() {
  form.value = {
    employee_id: canCreateForOthers.value ? null : (auth.employee?.id || null),
    principal: 0,
    installments: 6,
    reason: '',
  }
  showForm.value = true
}

const installmentPreview = computed(() => {
  const p = Number(form.value.principal) || 0
  const n = Number(form.value.installments) || 0
  if (p <= 0 || n <= 0) return 0
  return Math.round(p / n)
})

async function submit() {
  if (Number(form.value.principal) <= 0) { toast.warning(t('loan.invalid_amount')); return }
  if (Number(form.value.installments) <= 0 || Number(form.value.installments) > 24) {
    toast.warning(t('loan.invalid_installments')); return
  }
  try {
    const payload = { ...form.value }
    if (! canCreateForOthers.value) delete payload.employee_id
    await api.post('/loans', payload)
    toast.success(t('common.success'))
    showForm.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('common.error'))
  }
}

async function approve(loan) {
  const ok = await confirmDialog({
    title: t('loan.approve_title'),
    message: t('loan.approve_message', {
      number: loan.loan_number,
      principal: fmtCurrency(loan.principal),
      installment: fmtCurrency(loan.installment_amount),
      months: loan.installments
    }),
    okText: t('common.approve'),
  })
  if (! ok) return
  try {
    await api.put(`/loans/${loan.id}`, { action: 'approve' })
    toast.success(t('loan.approve_success'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('loan.approve_failed'))
  }
}

async function reject(loan) {
  const ok = await confirmDialog({
    title: t('loan.reject_title'),
    message: t('loan.reject_message', { number: loan.loan_number }),
    okText: t('common.reject'),
    variant: 'warning',
  })
  if (! ok) return
  try {
    await api.put(`/loans/${loan.id}`, { action: 'reject' })
    toast.success(t('loan.reject_success'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('loan.reject_failed'))
  }
}

async function cancel(loan) {
  const ok = await confirmDialog({
    title: t('loan.cancel_title'),
    message: t('loan.cancel_message', { number: loan.loan_number }),
    okText: t('common.cancel_action'),
    variant: 'warning',
  })
  if (! ok) return
  try {
    await api.delete(`/loans/${loan.id}`)
    toast.success(t('loan.cancel_success'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('loan.cancel_failed'))
  }
}

const statusBadge = (s) => ({
  PENDING: 'bg-amber-100 text-amber-800',
  RUNNING: 'bg-blue-100 text-blue-800',
  COMPLETED: 'bg-emerald-100 text-emerald-800',
  REJECTED: 'bg-red-100 text-red-800',
  CANCELLED: 'bg-gray-100 text-gray-700',
}[s] || 'bg-gray-100 text-gray-700')

// Sisa periode = ceil(remaining / installment_amount). Membantu user lihat
// berapa kali lagi loan ini akan muncul sebagai potongan di payroll.
function remainingPeriods(loan) {
  const inst = Number(loan.installment_amount) || 0
  const rem = Number(loan.remaining) || 0
  if (inst <= 0 || rem <= 0) return 0
  return Math.ceil(rem / inst)
}

function go(page) { filters.page = page; fetchData() }
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <BanknotesIcon class="w-7 h-7 text-emerald-600" />
          {{ t('loan.title') }}
        </h1>
        <p class="text-sm text-gray-500">
          {{ t('loan.subtitle') }}
        </p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <ExcelImporter
          v-if="auth.can('loans.bulk_import')"
          resource="loans"
          :button-label="t('loan.import')"
          @committed="fetchData()"
        />
        <button class="btn-primary" @click="openForm">
          <PlusIcon class="w-4 h-4" /> {{ t('loan.apply') }}
        </button>
      </div>
    </div>

    <!-- Info banner integrasi payroll -->
    <div class="rounded-md p-3 flex items-start gap-2 bg-blue-50 text-blue-900 text-sm border border-blue-200">
      <InformationCircleIcon class="w-5 h-5 mt-0.5 flex-shrink-0" />
      <div>
        <div class="font-medium">{{ t('loan.info_title') }}</div>
        <ol class="list-decimal list-inside text-xs mt-1 space-y-0.5">
          <li>{{ t('loan.info_step_1') }}</li>
          <li>{{ t('loan.info_step_2') }}</li>
          <li>{{ t('loan.info_step_3') }}</li>
          <li>{{ t('loan.info_step_4') }}</li>
        </ol>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="card card-body flex flex-wrap items-center gap-3">
      <div>
        <label class="block text-xs text-gray-500 mb-0.5">{{ t('loan.filter_status') }}</label>
        <select v-model="filters.status" @change="go(1)" class="input text-sm">
          <option value="">{{ t('loan.all_status') }}</option>
          <option value="PENDING">{{ t('loan.status_pending') }}</option>
          <option value="RUNNING">{{ t('loan.status_running') }}</option>
          <option value="COMPLETED">{{ t('loan.status_completed') }}</option>
          <option value="REJECTED">{{ t('loan.status_rejected') }}</option>
          <option value="CANCELLED">{{ t('loan.status_cancelled') }}</option>
        </select>
      </div>
      <div v-if="canApprove" class="flex items-center gap-2 mt-5">
        <input type="checkbox" v-model="filters.mine" :true-value="1" :false-value="0"
          @change="go(1)" class="w-4 h-4" id="mine" />
        <label for="mine" class="text-sm text-gray-700">{{ t('loan.only_me') }}</label>
      </div>
      <button class="btn-secondary text-sm ml-auto mt-5" @click="fetchData">
        <ArrowPathIcon class="w-4 h-4" /> {{ t('loan.refresh') }}
      </button>
    </div>

    <div class="card overflow-x-auto">
      <table class="table">
        <thead>
          <tr>
            <th>{{ t('loan.col_number') }}</th>
            <th>{{ t('loan.col_employee') }}</th>
            <th>{{ t('loan.col_principal') }}</th>
            <th>{{ t('loan.col_installment') }}</th>
            <th>{{ t('loan.col_progress') }}</th>
            <th>{{ t('loan.col_remaining') }}</th>
            <th>{{ t('loan.col_periods_left') }}</th>
            <th>{{ t('loan.col_status') }}</th>
            <th class="text-right">{{ t('loan.col_actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="loading">
            <td colspan="9" class="text-center py-8 text-gray-400">{{ t('loan.loading') }}</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="9" class="text-center py-8 text-gray-400">
              {{ t('loan.no_loans') }}
            </td>
          </tr>
          <tr v-for="l in items" :key="l.id" class="hover:bg-gray-50">
            <td class="font-mono text-xs">{{ l.loan_number }}</td>
            <td>
              <div class="font-medium">{{ l.employee_name }}</div>
              <div class="text-xs text-gray-500">{{ l.employee_number }}</div>
            </td>
            <td class="font-medium">{{ fmtCurrency(l.principal) }}</td>
            <td>
              <div>{{ fmtCurrency(l.installment_amount) }}</div>
              <div class="text-xs text-gray-500">× {{ l.installments }} bulan</div>
            </td>
            <td>
              <div class="w-28">
                <div class="bg-gray-200 rounded-full h-2 overflow-hidden">
                  <div class="bg-emerald-500 h-full transition-all"
                    :style="{ width: ((Number(l.paid_installments) / Number(l.installments)) * 100) + '%' }"></div>
                </div>
                <div class="text-xs text-gray-600 mt-0.5">
                  {{ t('loan.installment_count', { paid: l.paid_installments, total: l.installments }) }}
                </div>
              </div>
            </td>
            <td class="font-medium">{{ fmtCurrency(l.remaining) }}</td>
            <td>
              <span v-if="l.status === 'RUNNING'" class="inline-flex items-center gap-1 text-xs">
                <ClockIcon class="w-3.5 h-3.5 text-blue-500" />
                {{ t('loan.periods_unit', { n: remainingPeriods(l) }) }}
              </span>
              <span v-else class="text-xs text-gray-400">—</span>
            </td>
            <td>
              <span class="px-2 py-0.5 rounded text-xs font-medium" :class="statusBadge(l.status)">
                {{ l.status }}
              </span>
            </td>
            <td class="text-right">
              <div class="inline-flex gap-1">
                <button v-if="canApprove && l.status === 'PENDING'"
                  class="text-emerald-600 hover:text-emerald-800" :title="t('loan.approve_title')"
                  @click="approve(l)">
                  <CheckCircleIcon class="w-5 h-5" />
                </button>
                <button v-if="canApprove && l.status === 'PENDING'"
                  class="text-red-600 hover:text-red-800" :title="t('loan.reject_title')"
                  @click="reject(l)">
                  <XCircleIcon class="w-5 h-5" />
                </button>
                <button v-if="l.status === 'PENDING' && Number(auth.employee?.id) === Number(l.employee_id)"
                  class="text-gray-500 hover:text-red-600 text-xs" :title="t('loan.cancel_title')"
                  @click="cancel(l)">
                  {{ t('common.cancel') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="card-body flex items-center justify-between">
        <div class="text-sm text-gray-500">{{ t('common.total') }}: {{ meta.total }}</div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-500">{{ t('common.per_page') }}:</label>
          <select v-model.number="filters.per_page" class="input py-1 w-20 text-sm" @change="go(1)">
            <option :value="10">10</option>
            <option :value="20">20</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
          <button class="btn-secondary text-sm" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('common.previous') }}</button>
          <span class="px-3 py-2 text-sm">{{ t('common.page_of', { page: meta.page, pages: meta.pages || 1 }) }}</span>
          <button class="btn-secondary text-sm" :disabled="meta.page >= meta.pages" @click="go(meta.page + 1)">{{ t('common.next') }}</button>
        </div>
      </div>
    </div>

    <!-- Form modal -->
    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40"
      @click.self="showForm = false">
      <form @submit.prevent="submit"
        class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 class="font-semibold text-lg">{{ t('loan.apply') }}</h2>

        <div v-if="canCreateForOthers">
          <label class="label">{{ t('loan.employee') }}</label>
          <EmployeeSearchSelect
            v-model="form.employee_id"
            :placeholder="t('loan.employee_ph')"
            :only-active="true"
          />
        </div>

        <div>
          <label class="label">{{ t('loan.amount_label') }}</label>
          <input v-model.number="form.principal" type="number" min="1" step="50000" class="input" required />
        </div>

        <div>
          <label class="label">{{ t('loan.installments_label') }}</label>
          <input v-model.number="form.installments" type="number" min="1" max="24" class="input" required />
          <div class="flex flex-wrap gap-1 mt-1">
            <button v-for="n in [3, 6, 12, 18, 24]" :key="n" type="button"
              class="text-xs px-2 py-0.5 rounded-full"
              :class="form.installments === n ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-primary-100'"
              @click="form.installments = n">
              {{ n }}×
            </button>
          </div>
        </div>

        <div v-if="installmentPreview > 0"
          class="rounded-md p-3 bg-emerald-50 border border-emerald-200 text-sm">
          <div class="text-emerald-900">
            {{ t('loan.installment_per_month', { amount: fmtCurrency(installmentPreview) }) }}
          </div>
          <div class="text-xs text-emerald-700 mt-0.5">
            {{ t('loan.installment_note', { months: form.installments }) }}
          </div>
        </div>

        <div>
          <label class="label">{{ t('loan.reason') }}</label>
          <textarea v-model="form.reason" rows="3" class="input"
            :placeholder="t('loan.reason_ph')" required></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="showForm = false">{{ t('common.cancel') }}</button>
          <button type="submit" class="btn-primary">{{ t('loan.send_request') }}</button>
        </div>
      </form>
    </div>
  </div>
</template>