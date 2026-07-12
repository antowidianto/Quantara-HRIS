<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { useI18n } from '@/locales'

const route = useRoute()
const toast = useToast()
const { t } = useI18n()
const slip = ref(null)
const downloading = ref(false)
const downloadingBp = ref(false)

onMounted(async () => {
  const res = await api.get(`/payslips/${route.params.id}`)
  slip.value = res.data.data
})

const earnings = computed(() => (slip.value?.details || []).filter(d => d.type === 'EARNING'))
const deductions = computed(() => (slip.value?.details || []).filter(d => d.type === 'DEDUCTION'))

/**
 * Sprint 20+ — Pisahkan earning kategori BONUS supaya bisa di-render
 * di section terpisah dengan tax indicator. Bonus Engine (Sprint 16+)
 * auto-merge ke payslip via PayrollService::calculatePeriod (Sprint 17+).
 */
const earningsNonBonus = computed(() => earnings.value.filter(d => d.category !== 'BONUS'))
const earningsBonus = computed(() => earnings.value.filter(d => d.category === 'BONUS'))
const hasBonus = computed(() => earningsBonus.value.length > 0 || (slip.value?.bonus_amount || 0) > 0)

// H2.10 — Penanda kalau slip mengandung komponen natura/kenikmatan.
// Karyawan perlu tahu bahwa natura masuk Penghasilan Bruto (basis pajak)
// tetapi tidak dibayar tunai → menjelaskan kenapa Total Earnings > THP.
const hasNatura = computed(() =>
  earnings.value.some(d => Number(d.is_natura) === 1)
)
const naturaTotal = computed(() =>
  earnings.value
    .filter(d => Number(d.is_natura) === 1)
    .reduce((sum, d) => sum + Number(d.amount || 0), 0)
)

/**
 * Download PDF lewat axios supaya header Authorization (JWT) ikut.
 * `window.open` ke endpoint protected akan kena 401 karena browser tidak
 * meneruskan token. Caranya: minta blob, buat object URL, lalu klik
 * <a download>.
 */
async function downloadPdf() {
  if (downloading.value) return
  downloading.value = true
  await downloadBlob(`/payslips/${route.params.id}/pdf`,
    (slip.value?.slip_number || `payslip-${route.params.id}`) + '.pdf',
    'application/pdf')
  downloading.value = false
}

async function downloadBuktiPotong() {
  if (downloadingBp.value) return
  downloadingBp.value = true
  await downloadBlob(`/payslips/${route.params.id}/bukti-potong`,
    `BuktiPotong-${slip.value?.employee_number || route.params.id}.pdf`,
    'application/pdf')
  downloadingBp.value = false
}

async function downloadBlob(url, filename, expectedMime) {
  try {
    const res = await api.get(url, { responseType: 'blob' })
    const ct = res.headers['content-type'] || res.headers['Content-Type'] || ''
    if (! ct.includes(expectedMime.split('/')[1])) {
      // Fallback: kemungkinan error JSON dibungkus blob
      const txt = await res.data.text()
      try {
        const j = JSON.parse(txt)
        throw new Error(j.message || t('payroll.slip_detail.load_failed'))
      } catch (_) {
        throw new Error(t('payroll.slip_detail.load_failed'))
      }
    }
    const blob = new Blob([res.data], { type: expectedMime })
    const objUrl = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = objUrl
    a.download = filename
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(objUrl), 1000)
    toast.success(t('payroll.period_detail.download_success', { filename }))
  } catch (e) {
    toast.error(e.message || e.response?.data?.message || t('payroll.slip_detail.load_failed'))
  }
}
</script>

<template>
  <div v-if="slip">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">{{ slip.slip_number }}</h1>
        <div class="text-gray-500 text-sm">{{ slip.employee_name }} ({{ slip.employee_number }})</div>
      </div>
      <div class="flex gap-2 flex-wrap">
        <button class="btn-secondary" :disabled="downloadingBp" @click="downloadBuktiPotong">
          {{ downloadingBp ? t('payroll.slip_detail.downloading') : t('payroll.slip_detail.download_bp') }}
        </button>
        <button class="btn-primary" :disabled="downloading" @click="downloadPdf">
          {{ downloading ? t('payroll.slip_detail.downloading') : t('payroll.slip_detail.download_pdf') }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="card lg:col-span-2">
        <div class="card-header"><span class="font-semibold">{{ t('payroll.slip_detail.detail_title') }}</span></div>
        <div class="card-body">
          <h3 class="font-semibold mb-2 text-sm text-gray-700">{{ t('payroll.slip_detail.earnings') }}</h3>
          <table class="table mb-4">
            <tbody class="divide-y divide-gray-100">
              <tr v-for="d in earningsNonBonus" :key="d.id">
                <td>
                  <span>{{ d.name }}</span>
                  <span v-if="d.is_natura"
                        class="ml-2 text-[10px] px-1.5 py-0.5 rounded border border-purple-200 bg-purple-50 text-purple-700"
                        :title="t('payroll.slip_detail.natura_hint')">
                    Natura
                  </span>
                </td>
                <td class="text-right">{{ fmtCurrency(d.amount) }}</td>
              </tr>
              <tr class="font-semibold bg-gray-50"><td>{{ t('payroll.slip_detail.total_earnings') }}</td><td class="text-right">{{ fmtCurrency(slip.gross_earning) }}</td></tr>
            </tbody>
          </table>

          <!-- Sprint 20+ — Bonus section (auto-merge dari Bonus Engine) -->
          <div v-if="hasBonus" class="mb-4 border border-amber-200 bg-amber-50/50 rounded-lg overflow-hidden">
            <div class="px-3 py-2 bg-amber-100/60 border-b border-amber-200 flex items-center gap-2">
              <span class="text-sm font-semibold text-amber-900">{{ t('payroll.slip_detail.bonus_title') }}</span>
              <span class="text-xs text-amber-700 ml-auto">
                {{ t('payroll.slip_detail.bonus_desc') }}
              </span>
            </div>
            <table class="table mb-0">
              <tbody class="divide-y divide-amber-100">
                <tr v-for="d in earningsBonus" :key="d.id">
                  <td class="pl-3">
                    <span>{{ d.name }}</span>
                    <span v-if="d.code === 'BONUS_NT'"
                          class="ml-2 text-xs px-1.5 py-0.5 rounded bg-green-100 text-green-700"
                          :title="t('payroll.slip_detail.non_taxable')">
                      {{ t('payroll.slip_detail.non_taxable') }}
                    </span>
                    <span v-else
                          class="ml-2 text-xs px-1.5 py-0.5 rounded bg-blue-100 text-blue-700"
                          :title="t('payroll.slip_detail.taxable')">
                      {{ t('payroll.slip_detail.taxable') }}
                    </span>
                  </td>
                  <td class="text-right pr-3">{{ fmtCurrency(d.amount) }}</td>
                </tr>
                <tr v-if="slip.bonus_amount > 0" class="bg-amber-100/40 text-xs">
                  <td class="pl-3 text-amber-900">{{ t('payroll.slip_detail.total_bonus') }}</td>
                  <td class="text-right pr-3 text-amber-900 font-semibold">
                    {{ fmtCurrency(slip.bonus_amount) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <h3 class="font-semibold mb-2 text-sm text-gray-700">{{ t('payroll.slip_detail.deductions') }}</h3>
          <table class="table mb-4">
            <tbody class="divide-y divide-gray-100">
              <tr v-for="d in deductions" :key="d.id">
                <td>{{ d.name }}</td>
                <td class="text-right">{{ fmtCurrency(d.amount) }}</td>
              </tr>
              <tr class="font-semibold bg-gray-50"><td>{{ t('payroll.slip_detail.total_deductions') }}</td><td class="text-right">{{ fmtCurrency(slip.total_deduction) }}</td></tr>
            </tbody>
          </table>

          <!-- H2.10 — Disclaimer natura PMK 66/2023 -->
          <div v-if="hasNatura"
               class="mb-4 rounded-md border border-purple-200 bg-purple-50 p-3 text-xs text-purple-900">
            <div class="font-semibold mb-1">{{ t('payroll.slip_detail.natura_title') }}</div>
            <p>{{ t('payroll.slip_detail.natura_description', { amount: fmtCurrency(naturaTotal) }) }}</p>
          </div>

          <div class="bg-primary-50 p-4 rounded flex items-center justify-between">
            <span class="font-semibold">{{ t('payroll.slip_detail.thp') }}</span>
            <span class="text-2xl font-bold text-primary-700">{{ fmtCurrency(slip.take_home_pay) }}</span>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><span class="font-semibold">{{ t('payroll.slip_detail.summary') }}</span></div>
        <div class="card-body text-sm space-y-2">
          <div class="flex justify-between"><span class="text-gray-500">{{ t('payroll.slip_detail.ptkp') }}</span><span>{{ slip.ptkp_status }}</span></div>
          <div class="flex justify-between"><span class="text-gray-500">{{ t('payroll.slip_detail.work_days') }}</span><span>{{ slip.work_days }}</span></div>
          <div class="flex justify-between"><span class="text-gray-500">{{ t('payroll.slip_detail.present') }}</span><span>{{ slip.present_days }}</span></div>
          <div class="flex justify-between"><span class="text-gray-500">{{ t('payroll.slip_detail.late') }}</span><span>{{ t('payroll.slip_detail.late_val', { minutes: slip.late_minutes }) }}</span></div>
          <div class="flex justify-between"><span class="text-gray-500">{{ t('payroll.slip_detail.overtime') }}</span><span>{{ t('payroll.slip_detail.overtime_val', { hours: slip.overtime_hours }) }}</span></div>
          <hr>
          <div class="flex justify-between"><span class="text-gray-500">{{ t('payroll.slip_detail.basic_salary') }}</span><span>{{ fmtCurrency(slip.basic_salary) }}</span></div>
          <div class="flex justify-between"><span class="text-gray-500">{{ t('payroll.slip_detail.pph21', { rate: (slip.ter_rate * 100).toFixed(2) }) }}</span><span>{{ fmtCurrency(slip.pph21_amount) }}</span></div>
          <div class="flex justify-between"><span class="text-gray-500">{{ t('payroll.slip_detail.bpjs_kes') }}</span><span>{{ fmtCurrency(slip.bpjs_kes_employee) }}</span></div>
          <div class="flex justify-between"><span class="text-gray-500">{{ t('payroll.slip_detail.bpjs_jht') }}</span><span>{{ fmtCurrency(slip.bpjs_jht_employee) }}</span></div>
          <div class="flex justify-between"><span class="text-gray-500">{{ t('payroll.slip_detail.bpjs_jp') }}</span><span>{{ fmtCurrency(slip.bpjs_jp_employee) }}</span></div>
        </div>
      </div>
    </div>
  </div>
  <div v-else class="text-center py-12 text-gray-400">{{ t('payroll.slip_detail.loading') }}</div>
</template>
