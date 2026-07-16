<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtDate, fmtCurrency } from '@/composables/useResource'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  CalculatorIcon, CheckBadgeIcon, CurrencyDollarIcon, ArrowLeftIcon,
  PencilSquareIcon, EyeSlashIcon, EyeIcon, UserPlusIcon,
} from '@heroicons/vue/24/outline'
import ExcelImporter from '@/components/ExcelImporter.vue'

/**
 * Bonus Run Detail (Sprint 19+)
 *
 * Tampilkan run + recipients table. Aksi:
 *   - Calculate / Re-calculate (DRAFT/CALCULATED)
 *   - Submit approval (CALCULATED → PENDING_APPROVAL, perlu permission bonus.manage)
 *   - Mark Paid (APPROVED → PAID, perlu permission bonus.approve)
 *   - Override / exclude recipient individual (DRAFT/CALCULATED only)
 */
const route = useRoute()
const toast = useToast()
const auth = useAuthStore()
const runId = computed(() => parseInt(route.params.id))

const run = ref(null)
const recipients = ref([])
const loading = ref(false)
const loadingAction = ref(false)

const showOverride = ref(false)
const overrideRow = ref(null)
const overrideForm = ref({ amount: 0, reason: '' })

async function loadRun() {
  loading.value = true
  try {
    const res = await api.get(`/bonus-runs/${runId.value}`)
    run.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat run')
  } finally {
    loading.value = false
  }
}

async function loadRecipients() {
  try {
    const res = await api.get(`/bonus-runs/${runId.value}/recipients`, { params: { per_page: 500 } })
    // Normalize boolean-ish columns. MySQL TINYINT sering dikirim sebagai
    // string "0"/"1" via JSON; kalau kita biarkan, expression seperti
    // `!r.is_excluded` untuk "0" akan return false (karena "0" truthy di JS).
    // Cast ke integer eksplisit supaya filter Vue konsisten.
    const rows = (res.data.data || []).map((r) => ({
      ...r,
      is_excluded:       Number(r.is_excluded || 0),
      amount_overridden: Number(r.amount_overridden || 0),
      amount_capped:     Number(r.amount_capped || 0),
      amount:            Number(r.amount || 0),
      monthly_salary:    Number(r.monthly_salary || 0),
      tenure_months:     Number(r.tenure_months || 0),
    }))
    recipients.value = rows
  } catch (e) {
    // run might not be calculated yet — silent
    recipients.value = []
  }
}

onMounted(async () => {
  await loadRun()
  await loadRecipients()
})

async function calculate() {
  const ok = await confirmDialog({
    title: 'Calculate bonus run?',
    message: run.value?.recipient_count > 0
      ? 'Recipient list lama akan diganti. Override yang sudah HR set akan hilang. Lanjut?'
      : 'Resolve eligibility + compute amount untuk semua karyawan eligible.',
    okText: 'Calculate',
    variant: 'primary',
  })
  if (! ok) return
  loadingAction.value = true
  try {
    await api.post(`/bonus-runs/${runId.value}/calculate`)
    toast.success('Bonus run dihitung')
    await loadRun()
    await loadRecipients()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal calculate')
  } finally {
    loadingAction.value = false
  }
}

async function submitApproval() {
  const ok = await confirmDialog({
    title: 'Ajukan approval bonus?',
    message: `Total ${fmtCurrency(run.value?.total_amount || 0)} untuk ${run.value?.recipient_count} karyawan akan dikunci dan masuk approval HR, Finance, serta Direksi sesuai threshold. Lanjut?`,
    okText: 'Ajukan',
    variant: 'warning',
  })
  if (! ok) return
  loadingAction.value = true
  try {
    await api.post(`/bonus-runs/${runId.value}/submit`)
    toast.success('Bonus run masuk antrean approval')
    await loadRun()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mengajukan approval')
  } finally {
    loadingAction.value = false
  }
}

async function markPaid() {
  const ok = await confirmDialog({
    title: 'Mark bonus run sebagai PAID?',
    message: 'Status final tidak bisa di-rollback. Pakai ini setelah Finance benar-benar transfer.',
    okText: 'Mark Paid',
    variant: 'success',
  })
  if (! ok) return
  loadingAction.value = true
  try {
    await api.post(`/bonus-runs/${runId.value}/mark-paid`)
    toast.success('Bonus run ditandai PAID')
    await loadRun()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mark paid')
  } finally {
    loadingAction.value = false
  }
}

function openOverride(row) {
  overrideRow.value = row
  overrideForm.value = { amount: parseFloat(row.amount) || 0, reason: '' }
  showOverride.value = true
}

async function saveOverride() {
  if (overrideForm.value.amount < 0) {
    toast.error('Amount tidak boleh negatif')
    return
  }
  if (! overrideForm.value.reason || overrideForm.value.reason.length < 5) {
    toast.error('Alasan minimal 5 karakter (untuk audit)')
    return
  }
  loadingAction.value = true
  try {
    await api.put(`/bonus-recipients/${overrideRow.value.id}`, overrideForm.value)
    toast.success('Recipient di-override')
    showOverride.value = false
    await loadRun()
    await loadRecipients()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal override')
  } finally {
    loadingAction.value = false
  }
}

async function includeAll() {
  const ok = await confirmDialog({
    title: 'Include semua recipient?',
    message: `${excludedCount.value} recipient yang sebelumnya di-exclude akan di-include kembali. Total amount run akan dihitung ulang.`,
    okText: 'Include All',
    variant: 'primary',
  })
  if (! ok) return
  loadingAction.value = true
  try {
    const res = await api.post(`/bonus-runs/${runId.value}/recipients/include-all`)
    const affected = res.data?.data?.affected ?? 0
    if (affected > 0) {
      toast.success(`${affected} recipient di-include kembali`)
    } else {
      toast.info('Semua recipient sudah included')
    }
    await loadRun()
    await loadRecipients()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal include all')
  } finally {
    loadingAction.value = false
  }
}

async function toggleExclude(row) {
  const willExclude = ! row.is_excluded
  const ok = await confirmDialog({
    title: willExclude ? 'Exclude recipient?' : 'Include kembali?',
    message: willExclude
      ? `${row.employee_name} akan di-skip dari run ini. Total amount akan disesuaikan otomatis.`
      : `${row.employee_name} akan kembali masuk ke run ini.`,
    okText: willExclude ? 'Exclude' : 'Include',
    variant: willExclude ? 'warning' : 'primary',
  })
  if (! ok) return
  try {
    await api.put(`/bonus-recipients/${row.id}`, {
      is_excluded: willExclude ? 1 : 0,
      reason: willExclude ? 'Excluded by HR' : 'Re-included by HR',
    })
    toast.success(willExclude ? 'Excluded' : 'Included')
    await loadRun()
    await loadRecipients()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

const STATUS_BADGE = {
  DRAFT:      'badge-gray',
  CALCULATED: 'badge-blue',
  PENDING_APPROVAL: 'badge-purple',
  APPROVED:   'badge-yellow',
  REJECTED:   'badge-red',
  PAID:       'badge-green',
  CANCELLED:  'badge-red',
}

const canCalculate    = computed(() => run.value && ['DRAFT', 'CALCULATED', 'REJECTED'].includes(run.value.status))
const canSubmit       = computed(() => run.value?.status === 'CALCULATED')
const canMarkPaid     = computed(() => run.value?.status === 'APPROVED')
const canEditRecipient= computed(() => run.value && ['DRAFT', 'CALCULATED', 'REJECTED'].includes(run.value.status))

// Stats
const includedCount = computed(() => recipients.value.filter(r => ! r.is_excluded).length)
const excludedCount = computed(() => recipients.value.filter(r =>   r.is_excluded).length)
const overridesCount = computed(() => recipients.value.filter(r => r.amount_overridden).length)
</script>

<template>
  <div>
    <RouterLink to="/payroll/bonus/runs" class="text-sm text-gray-500 hover:text-primary-600 inline-flex items-center gap-1 mb-3">
      <ArrowLeftIcon class="w-4 h-4" /> Bonus Runs
    </RouterLink>

    <div v-if="loading" class="card p-8 text-center text-gray-400">Memuat detail...</div>

    <template v-else-if="run">
      <div class="card mb-4">
        <div class="px-6 py-5 flex flex-wrap items-start justify-between gap-4">
          <div>
            <div class="flex items-center gap-3 mb-1">
              <h1 class="text-2xl font-bold">{{ run.name }}</h1>
              <span :class="STATUS_BADGE[run.status] || 'badge-gray'">{{ run.status }}</span>
            </div>
            <div class="text-sm text-gray-500 space-x-3">
              <span>Scheme: <strong>{{ run.scheme_name || '#' + run.bonus_scheme_id }}</strong></span>
              <span>·</span>
              <span>Reference: {{ fmtDate(run.reference_date) }}</span>
              <span>·</span>
              <span>Pay date: {{ fmtDate(run.pay_date) }}</span>
              <span v-if="run.payroll_period_id">
                <span>·</span>
                <span class="text-primary-600 font-medium">→ Payroll Period #{{ run.payroll_period_id }} (auto-merge)</span>
              </span>
            </div>
            <p v-if="run.notes" class="text-sm text-gray-600 mt-2 italic">{{ run.notes }}</p>
          </div>
          <div class="text-right">
            <div class="text-sm text-gray-500">Total Amount</div>
            <div class="text-2xl font-bold text-primary-600">{{ fmtCurrency(run.total_amount || 0) }}</div>
            <div class="text-xs text-gray-400 mt-1">
              {{ run.recipient_count || 0 }} recipient{{ run.recipient_count > 1 ? 's' : '' }}
            </div>
          </div>
        </div>

        <!-- Status hint untuk HR — supaya jelas next-step apa & visibility ke
             karyawan kapan -->
        <div v-if="run.status === 'DRAFT'"
             class="mx-6 mt-3 p-3 rounded-md bg-gray-50 border border-gray-200 text-xs text-gray-600">
          <strong>Next step:</strong> Klik <em>Calculate</em> untuk menghitung nominal
          per karyawan eligible. Bonus belum terlihat oleh karyawan.
        </div>
        <div v-else-if="run.status === 'CALCULATED'"
             class="mx-6 mt-3 p-3 rounded-md bg-blue-50 border border-blue-200 text-xs text-blue-800">
          <strong>Status: CALCULATED.</strong> Recipient sudah dihitung tapi
          <span class="font-semibold">belum terlihat oleh karyawan</span>. Review
          recipient list (override / exclude bila perlu), lalu ajukan approval bertingkat.
        </div>
        <div v-else-if="run.status === 'PENDING_APPROVAL'"
             class="mx-6 mt-3 p-3 rounded-md bg-purple-50 border border-purple-200 text-xs text-purple-800">
          <strong>Status: PENDING APPROVAL.</strong> Nominal dan recipient dikunci.
          Keputusan dilakukan melalui Inbox Approval sesuai chain yang tersnapshot.
        </div>
        <div v-else-if="run.status === 'REJECTED'"
             class="mx-6 mt-3 p-3 rounded-md bg-red-50 border border-red-200 text-xs text-red-800">
          <strong>Status: REJECTED.</strong> {{ run.rejection_reason || 'Periksa audit keputusan.' }}
          Hitung ulang untuk memperbaiki data sebelum diajukan kembali.
        </div>
        <div v-else-if="run.status === 'APPROVED'"
             class="mx-6 mt-3 p-3 rounded-md bg-amber-50 border border-amber-200 text-xs text-amber-800">
          <strong>Status: APPROVED.</strong> Bonus sudah <span class="font-semibold">terlihat oleh
          karyawan</span> di "Riwayat Bonus". Klik <em>Mark Paid</em> setelah Finance benar-benar
          mentransfer.
        </div>
        <div v-else-if="run.status === 'PAID'"
             class="mx-6 mt-3 p-3 rounded-md bg-green-50 border border-green-200 text-xs text-green-800">
          <strong>Status: PAID.</strong> Run final dan tidak bisa diubah. Karyawan
          melihat bonus ini sebagai sudah dibayarkan.
        </div>
        <div v-else-if="run.status === 'CANCELLED'"
             class="mx-6 mt-3 p-3 rounded-md bg-red-50 border border-red-200 text-xs text-red-700">
          <strong>Status: CANCELLED.</strong> Run dibatalkan. Recipient tidak akan
          tampil di Riwayat Bonus karyawan.
        </div>

        <!-- Action bar -->
        <div class="px-6 py-3 mt-3 border-t border-gray-100 bg-gray-50 flex flex-wrap gap-2">
          <button v-if="canCalculate" @click="calculate" :disabled="loadingAction" class="btn-primary">
            <CalculatorIcon class="w-4 h-4" />
            {{ run.status === 'DRAFT' ? 'Calculate' : 'Re-calculate' }}
          </button>
          <button v-if="canSubmit" @click="submitApproval" :disabled="loadingAction" class="btn-warning">
            <CheckBadgeIcon class="w-4 h-4" /> Ajukan Approval
          </button>
          <button v-if="canMarkPaid" @click="markPaid" :disabled="loadingAction" class="btn-success">
            <CurrencyDollarIcon class="w-4 h-4" /> Mark Paid
          </button>
          <button
            v-if="canEditRecipient && excludedCount > 0"
            @click="includeAll"
            :disabled="loadingAction"
            class="btn-secondary"
            :title="`Include kembali ${excludedCount} recipient yang di-exclude`"
          >
            <UserPlusIcon class="w-4 h-4" /> Include All ({{ excludedCount }})
          </button>
          <!-- Sprint 4 — bulk import bonus recipients dari xlsx -->
          <ExcelImporter
            v-if="auth.can('bonus_recipients.bulk_import')"
            resource="bonus_recipients"
            button-label="Import Recipient"
            @committed="loadRecipients()"
          />
          <div class="ml-auto flex items-center gap-4 text-xs text-gray-600">
            <span><strong class="text-green-700">{{ includedCount }}</strong> included</span>
            <span v-if="excludedCount > 0">
              <strong class="text-gray-500">{{ excludedCount }}</strong> excluded
            </span>
            <span v-if="overridesCount > 0">
              <strong class="text-blue-700">{{ overridesCount }}</strong> overridden
            </span>
          </div>
        </div>

        <!-- Warning kalau semua recipient di-exclude (situasi yang menyebabkan
             "bonus tidak muncul ke karyawan" walaupun status APPROVED). -->
        <div v-if="canEditRecipient && includedCount === 0 && excludedCount > 0"
             class="mx-6 mt-2 p-3 rounded-md bg-red-50 border border-red-200 text-xs text-red-800">
          <strong>⚠ Semua recipient di-exclude.</strong> Run ini tidak bisa di-approve
          dan bonus tidak akan terlihat oleh karyawan. Klik <em>Include All</em> di atas
          untuk reset, atau pilih per-recipient lewat ikon mata di tabel di bawah.
        </div>

        <!-- Audit timeline -->
        <div v-if="run.calculated_at || run.approved_at || run.paid_at || run.cancelled_at"
             class="px-6 py-3 border-t border-gray-100 text-xs text-gray-500 space-y-0.5">
          <div v-if="run.calculated_at">
            ✓ Calculated by user #{{ run.calculated_by }} pada {{ run.calculated_at }}
          </div>
          <div v-if="run.approved_at">
            ✓ Approved by user #{{ run.approved_by }} pada {{ run.approved_at }}
          </div>
          <div v-if="run.paid_at">
            ✓ Marked PAID by user #{{ run.paid_by }} pada {{ run.paid_at }}
          </div>
          <div v-if="run.cancelled_at">
            ✗ Cancelled by user #{{ run.cancelled_by }} pada {{ run.cancelled_at }}
          </div>
        </div>
      </div>

      <!-- Recipients table -->
      <div class="card">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
          <h3 class="font-semibold">Recipients ({{ recipients.length }})</h3>
          <p v-if="recipients.length === 0 && run.status === 'DRAFT'"
             class="text-sm text-gray-500">
            Belum ada recipient. Klik <strong>Calculate</strong> untuk resolve eligibility.
          </p>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Tenure</th>
                <th>Monthly Salary</th>
                <th class="text-right">Amount</th>
                <th>Status</th>
                <th v-if="canEditRecipient" class="text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="r in recipients" :key="r.id"
                  :class="r.is_excluded ? 'opacity-50 bg-gray-50' : ''">
                <td>
                  <div class="text-sm font-medium">{{ r.employee_name }}</div>
                  <div class="text-xs text-gray-500 font-mono">{{ r.employee_number }}</div>
                </td>
                <td class="text-sm">{{ r.department_name || '—' }}</td>
                <td class="text-sm">{{ r.tenure_months }} bln</td>
                <td class="text-right tabular-nums text-sm">{{ fmtCurrency(r.monthly_salary || 0) }}</td>
                <td class="text-right tabular-nums">
                  <span :class="r.amount_overridden ? 'text-blue-700 font-semibold' : ''">
                    {{ fmtCurrency(r.amount) }}
                  </span>
                  <div v-if="r.amount_overridden" class="text-xs text-gray-400">overridden</div>
                  <div v-else-if="r.amount_capped" class="text-xs text-yellow-700">capped</div>
                </td>
                <td>
                  <span v-if="r.is_excluded" class="badge-gray">Excluded</span>
                  <span v-else class="badge-green">Included</span>
                </td>
                <td v-if="canEditRecipient" class="text-right">
                  <div class="inline-flex gap-1">
                    <button class="btn-secondary text-xs" @click="openOverride(r)" title="Override amount">
                      <PencilSquareIcon class="w-3.5 h-3.5" />
                    </button>
                    <button class="btn-secondary text-xs" @click="toggleExclude(r)"
                            :title="r.is_excluded ? 'Include kembali' : 'Exclude'">
                      <EyeSlashIcon v-if="!r.is_excluded" class="w-3.5 h-3.5" />
                      <EyeIcon v-else class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- Override Modal -->
    <div v-if="showOverride" class="fixed inset-0 z-50 bg-black/40 flex items-start justify-center p-4 overflow-y-auto"
         @click.self="showOverride = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md my-8">
        <div class="px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold">Override Amount</h2>
          <p class="text-sm text-gray-500">{{ overrideRow?.employee_name }}</p>
        </div>
        <div class="px-6 py-4 space-y-3">
          <div>
            <label class="label">Amount baru</label>
            <input v-model.number="overrideForm.amount" type="number" step="0.01" class="input" />
            <p class="text-xs text-gray-500 mt-1">
              Original: {{ fmtCurrency(parseFloat(overrideRow?.amount) || 0) }}
              <span v-if="overrideRow?.amount_capped" class="text-yellow-700"> (sebelumnya capped)</span>
            </p>
          </div>
          <div>
            <label class="label">Alasan (untuk audit, min. 5 karakter)</label>
            <textarea v-model="overrideForm.reason" class="input" rows="2"
                      placeholder="Q4 performance adjustment / dst"></textarea>
          </div>
        </div>
        <div class="px-6 py-3 border-t border-gray-200 bg-gray-50 flex justify-end gap-2">
          <button @click="showOverride = false" class="btn-secondary">Batal</button>
          <button @click="saveOverride" :disabled="loadingAction" class="btn-primary">Simpan</button>
        </div>
      </div>
    </div>
  </div>
</template>
