<script setup>
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useI18n } from '@/locales'
import { apiErrorMessage, apiValidationErrors } from '@/utils/apiError'
import { validatePayrollPeriod } from './payrollPeriodForm'

const router = useRouter()
const toast = useToast()
const { t, locale } = useI18n()
const saving = ref(false)
const fieldErrors = ref({})
const now = new Date()

const MONTH_NAMES_ID = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]
const MONTH_NAMES_EN = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

function pad(n) { return String(n).padStart(2, '0') }
function ymd(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}
function buildName(year, month, type) {
  const monthIdx = Math.min(12, Math.max(1, Number(month) || 1)) - 1
  const monthName = locale.value === 'id' ? MONTH_NAMES_ID[monthIdx] : MONTH_NAMES_EN[monthIdx]
  if (type === 'THR') return `THR ${year}`
  if (type === 'BONUS') return `Bonus ${monthName} ${year}`
  if (type === 'OFF_CYCLE') return `Off-cycle ${monthName} ${year}`
  return `${monthName} ${year}`
}

const initialYear = now.getFullYear()
const initialMonth = now.getMonth() + 1
const form = ref({
  name: buildName(initialYear, initialMonth, 'MONTHLY'),
  type: 'MONTHLY',
  period_year: initialYear,
  period_month: initialMonth,
  start_date: ymd(new Date(initialYear, initialMonth - 1, 1)),
  end_date: ymd(new Date(initialYear, initialMonth, 0)), // last day of month
  pay_date: ymd(new Date(initialYear, initialMonth, 5)), // 5th of next month
})

// Track whether user has manually edited the name; if so, stop auto-syncing.
const nameTouched = ref(false)
function onNameInput() { nameTouched.value = true }

// Auto-sync name + dates ketika user mengubah year/month/type.
// Tujuannya: tidak ada lagi mismatch "Agustus 2026" tapi month=5 yang
// menyebabkan user salah duga periode.
watch(
  () => [form.value.period_year, form.value.period_month, form.value.type],
  ([year, month, type]) => {
    const y = Number(year)
    const m = Number(month)
    if (!y || !m) return
    if (!nameTouched.value) {
      form.value.name = buildName(y, m, type)
    }
    // Hanya auto-update dates kalau user belum kustomisasi (heuristik:
    // start_date masih sama dengan tanggal 1 bulan sebelumnya). Kita simpler:
    // selalu update saat year/month berubah sebelum user simpan, supaya
    // konsisten dengan periode yang dipilih.
    form.value.start_date = ymd(new Date(y, m - 1, 1))
    form.value.end_date = ymd(new Date(y, m, 0))
    form.value.pay_date = ymd(new Date(y, m, 5))
  },
)

async function submit() {
  const validation = validatePayrollPeriod(form.value)
  if (Object.keys(validation).length > 0) {
    fieldErrors.value = Object.fromEntries(
      Object.entries(validation).map(([field, code]) => [field, t(`payroll.period_form.validation_${code}`)]),
    )
    toast.error(t('payroll.period_form.validation_failed'))
    return
  }

  saving.value = true
  fieldErrors.value = {}
  try {
    const res = await api.post('/payroll-periods', form.value)
    toast.success(t('payroll.period_form.created'))
    router.push(`/payroll/periods/${res.data.data.id}`)
  } catch (e) {
    fieldErrors.value = apiValidationErrors(e)
    toast.error(apiErrorMessage(e, {
      codes: { PERIOD_DUPLICATE: t('payroll.period_form.duplicate') },
      fallback: t('payroll.period_form.failed'),
    }))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl">
    <h1 class="text-2xl font-bold mb-4">{{ t('payroll.period_form.title') }}</h1>
    <form @submit.prevent="submit" class="card">
      <div v-if="Object.keys(fieldErrors).length" class="mx-4 mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
        <p class="font-medium">{{ t('payroll.period_form.validation_failed') }}</p>
        <ul class="list-disc pl-5">
          <li v-for="(message, field) in fieldErrors" :key="field">{{ message }}</li>
        </ul>
      </div>
      <div class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
          <label class="label">{{ t('payroll.period_form.name') }}</label>
          <input v-model="form.name" class="input" required @input="onNameInput" />
        </div>
        <div><label class="label">{{ t('payroll.period_form.type') }}</label>
          <select v-model="form.type" class="input">
            <option value="MONTHLY">{{ t('payroll.period_form.monthly') }}</option>
            <option value="THR">{{ t('payroll.period_form.thr') }}</option>
            <option value="BONUS">{{ t('payroll.period_form.bonus') }}</option>
            <option value="OFF_CYCLE">{{ t('payroll.period_form.off_cycle') }}</option>
          </select>
        </div>
        <div><label class="label">{{ t('payroll.period_form.year') }}</label><input v-model.number="form.period_year" type="number" class="input" required /></div>
        <div><label class="label">{{ t('payroll.period_form.month') }}</label><input v-model.number="form.period_month" type="number" min="1" max="12" class="input" required /></div>
        <div><label class="label">{{ t('payroll.period_form.cutoff') }}</label><input v-model="form.cutoff_attendance_date" type="date" class="input" /></div>
        <div><label class="label">{{ t('payroll.period_form.start_date') }}</label><input v-model="form.start_date" type="date" class="input" required /></div>
        <div><label class="label">{{ t('payroll.period_form.end_date') }}</label><input v-model="form.end_date" type="date" class="input" required /></div>
        <div class="md:col-span-2"><label class="label">{{ t('payroll.period_form.pay_date') }}</label><input v-model="form.pay_date" type="date" class="input" required /></div>
      </div>
      <div class="card-body border-t flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="$router.back()">{{ t('payroll.period_form.cancel') }}</button>
        <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? t('payroll.period_form.saving') : t('payroll.period_form.submit') }}</button>
      </div>
    </form>
  </div>
</template>
