<script setup>
/**
 * Severance / Pesangon — kalkulator hak karyawan PHK.
 *
 * UX yang penting: backend `/severance/calculate` sudah men-derive semua
 * variabel kalkulasi otomatis dari profil karyawan:
 *   - last_monthly_salary  ← employees.basic_salary + fixed_allowance
 *   - service_years        ← (termination_date - join_date) / 365.25
 *   - unused_leave_days    ← leave_balances.remaining (kategori ANNUAL)
 *
 * Jadi UX modal ini cukup: pilih karyawan → pilih tanggal & alasan PHK →
 * preview muncul otomatis. User boleh override field nominal kalau ada
 * kondisi khusus. Lihat docs/SEVERANCE.md untuk detail formula.
 */
import { ref, computed, onMounted, reactive, watch } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import {
  CalculatorIcon, DocumentArrowDownIcon, CheckCircleIcon, BanknotesIcon,
  NoSymbolIcon, PlusIcon, ArrowPathIcon, UserCircleIcon,
} from '@heroicons/vue/24/outline'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

const auth = useAuthStore()
const toast = useToast()

const records = ref([])
const total = ref(0)
const loading = ref(false)

// Master
const reasons = ref([])
const employees = ref([])

const filters = reactive({
  employee_id: '',
  status: '',
  page: 1,
  per_page: 20,
})

// ─── Modal calculator state ───────────────────────────────────────
const showCalc = ref(false)
const calcInput = reactive({
  employee_id: '',
  termination_date: new Date().toISOString().slice(0, 10),
  termination_reason: 'EFFICIENCY_LOSS',
  last_monthly_salary: '',
  service_years: '',
  unused_leave_days: '',
  uph_homecoming_amount: '',
  uph_other_amount: '',
  separation_pay: '',
  notes: '',
})
const previewResult = ref(null)
const previewBreakdown = ref('')
const previewing = ref(false)
const saving = ref(false)
// Snapshot karyawan dari response backend — dipakai untuk feedback visual
// "data ini diambil dari profil karyawan, bukan asumsi".
const employeeSnapshot = ref(null)
let previewDebounce = null

const canManage = computed(() => auth.can('severance.manage'))

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/severance', { params: filters })
    records.value = res.data.data
    total.value = res.data.meta?.total || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data')
  } finally {
    loading.value = false
  }
}

async function fetchMaster() {
  try {
    // Karyawan TIDAK lagi di-load semua sekaligus — picker pakai
    // EmployeeSearchSelect server-side untuk dataset besar.
    const r = await api.get('/severance/reasons')
    reasons.value = r.data.data || []
  } catch (_) { /* */ }
}

onMounted(() => { fetchMaster(); fetchData() })

function openCalc() {
  Object.assign(calcInput, {
    employee_id: '', termination_date: new Date().toISOString().slice(0, 10),
    termination_reason: 'EFFICIENCY_LOSS', last_monthly_salary: '', service_years: '',
    unused_leave_days: '', uph_homecoming_amount: '', uph_other_amount: '',
    separation_pay: '', notes: '',
  })
  previewResult.value = null
  previewBreakdown.value = ''
  employeeSnapshot.value = null
  showCalc.value = true
}

/**
 * Kirim payload ke /severance/calculate. Backend sudah bertanggung jawab
 * untuk auto-derive nilai default — kita cukup kirim apa yang user isi
 * (termasuk yang kosong → backend skip dan pakai default).
 *
 * autoFill = true  → overwrite form input dengan nilai backend.
 *                    Dipakai saat user pilih karyawan baru atau klik
 *                    tombol "Hitung Ulang" manual.
 * autoFill = false → cuma update preview, jangan ganggu input yang
 *                    sedang user ketik (override manual tetap dihormati).
 */
async function preview(autoFill = false) {
  if (! calcInput.employee_id || ! calcInput.termination_date || ! calcInput.termination_reason) {
    return
  }
  previewing.value = true
  try {
    const payload = { ...calcInput }
    Object.keys(payload).forEach(k => { if (payload[k] === '') delete payload[k] })
    const res = await api.post('/severance/calculate', payload)
    const d = res.data?.data || {}
    previewResult.value = d.result || null
    previewBreakdown.value = d.breakdown || ''
    employeeSnapshot.value = d.employee || null

    if (autoFill && previewResult.value) {
      if (previewResult.value.service_years != null) {
        calcInput.service_years = previewResult.value.service_years
      }
      if (previewResult.value.last_monthly_salary != null) {
        calcInput.last_monthly_salary = previewResult.value.last_monthly_salary
      }
      if (previewResult.value.unused_leave_days != null) {
        calcInput.unused_leave_days = previewResult.value.unused_leave_days
      }
    }
  } catch (e) {
    if (autoFill) {
      toast.error(e.response?.data?.message || 'Gagal menghitung')
    }
    previewResult.value = null
  } finally {
    previewing.value = false
  }
}

function manualRecalc() {
  if (! calcInput.employee_id) {
    toast.error('Pilih karyawan terlebih dahulu')
    return
  }
  preview(true)
}

async function save() {
  if (! previewResult.value) {
    toast.error('Hitung preview dulu sebelum simpan')
    return
  }
  saving.value = true
  try {
    const payload = { ...calcInput }
    Object.keys(payload).forEach(k => { if (payload[k] === '') delete payload[k] })
    await api.post('/severance', payload)
    toast.success('Tersimpan sebagai DRAFT')
    showCalc.value = false
    fetchData()
  } catch (e) {
    const msg = e.response?.data?.message || 'Gagal'
    const errs = e.response?.data?.errors
    toast.error(errs ? msg + ': ' + Object.values(errs).join(', ') : msg)
  } finally {
    saving.value = false
  }
}

async function finalize(row) {
  const ok = await confirmDialog({
    title: 'Finalisasi perhitungan?',
    message: `Total ${fmtCurrency(row.total_amount)} akan dikunci dan dianggap kewajiban perusahaan.`,
    okText: 'Finalisasi', variant: 'warning',
  })
  if (! ok) return
  try {
    await api.post(`/severance/${row.id}/finalize`)
    toast.success('Difinalisasi')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function markPaid(row) {
  const ok = await confirmDialog({
    title: 'Tandai sudah dibayar?',
    message: `Konfirmasi bahwa ${fmtCurrency(row.total_amount)} sudah ditransfer ke karyawan.`,
    okText: 'Sudah Dibayar', variant: 'success',
  })
  if (! ok) return
  try {
    await api.post(`/severance/${row.id}/mark-paid`)
    toast.success('Tercatat sebagai PAID')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function cancel(row) {
  const reason = prompt('Alasan pembatalan (min 10 karakter)?')
  if (! reason || reason.trim().length < 10) {
    if (reason !== null) toast.error('Alasan minimal 10 karakter')
    return
  }
  try {
    await api.post(`/severance/${row.id}/cancel`, { reason: reason.trim() })
    toast.success('Dibatalkan')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function downloadBreakdown(row) {
  try {
    const res = await api.get(`/severance/${row.id}/breakdown`, { responseType: 'blob' })
    const blob = new Blob([res.data], { type: 'application/pdf' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `Severance-${row.employee_number}-${row.termination_date}.pdf`
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (e) { toast.error('Gagal mengunduh') }
}

const STATUS_BADGE = {
  DRAFT:     { label: 'Draft',      cls: 'bg-gray-100 text-gray-700' },
  FINALIZED: { label: 'Final',      cls: 'bg-amber-100 text-amber-800' },
  PAID:      { label: 'Sudah Dibayar', cls: 'bg-emerald-100 text-emerald-800' },
  CANCELLED: { label: 'Dibatalkan', cls: 'bg-red-100 text-red-700' },
}

const selectedReason = computed(() =>
  reasons.value.find(r => r.value === calcInput.termination_reason))

// ─── Watchers reaktif untuk live preview ───────────────────────────
// Saat ganti karyawan: fetch profil + auto-fill SEMUA nominal.
watch(() => calcInput.employee_id, (newId, oldId) => {
  if (! newId) {
    employeeSnapshot.value = null
    previewResult.value = null
    return
  }
  if (newId !== oldId) {
    // Reset agar nilai dari karyawan sebelumnya tidak nyangkut.
    calcInput.last_monthly_salary = ''
    calcInput.service_years       = ''
    calcInput.unused_leave_days   = ''
  }
  preview(true)
})

// Saat ganti tanggal/alasan: re-preview tanpa overwrite input lain.
watch(() => [calcInput.termination_date, calcInput.termination_reason], () => {
  if (! calcInput.employee_id) return
  clearTimeout(previewDebounce)
  previewDebounce = setTimeout(() => preview(false), 250)
})

// Saat user edit nominal manual: re-preview untuk update breakdown.
// Debounce supaya tidak spam request setiap keystroke.
watch(() => [
  calcInput.last_monthly_salary, calcInput.service_years, calcInput.unused_leave_days,
  calcInput.uph_homecoming_amount, calcInput.uph_other_amount, calcInput.separation_pay,
], () => {
  if (! calcInput.employee_id || ! previewResult.value) return
  clearTimeout(previewDebounce)
  previewDebounce = setTimeout(() => preview(false), 400)
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <CalculatorIcon class="w-6 h-6 text-primary-600" />
          Pesangon & UPMK / UPH
        </h1>
        <p class="text-sm text-gray-500">Kalkulator hak karyawan PHK sesuai UU 6/2023 & PP 35/2021.</p>
      </div>
      <button v-if="canManage" class="btn-primary" @click="openCalc">
        <PlusIcon class="w-4 h-4" /> Hitung Severance Baru
      </button>
    </div>

    <!-- Filters -->
    <div class="card card-body mb-4">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label class="label">Karyawan</label>
          <EmployeeSearchSelect
            v-model="filters.employee_id"
            placeholder="— Semua —"
            :only-active="false"
          />
        </div>
        <div>
          <label class="label">Status</label>
          <select v-model="filters.status" class="input">
            <option value="">— Semua —</option>
            <option v-for="(b, k) in STATUS_BADGE" :key="k" :value="k">{{ b.label }}</option>
          </select>
        </div>
        <div class="flex items-end">
          <button class="btn-secondary w-full" @click="filters.page = 1; fetchData()">Filter</button>
        </div>
      </div>
    </div>

    <!-- Records table -->
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Karyawan</th>
              <th>Alasan PHK</th>
              <th>Tgl PHK</th>
              <th>Masa Kerja</th>
              <th class="text-right">Pesangon</th>
              <th class="text-right">UPMK</th>
              <th class="text-right">UPH</th>
              <th class="text-right">Total</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="10" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="records.length === 0">
              <td colspan="10" class="text-center py-12 text-gray-400">Belum ada perhitungan severance.</td>
            </tr>
            <tr v-for="r in records" :key="r.id">
              <td>
                <div class="font-medium">{{ r.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ r.employee_number }} · {{ r.department_name }}</div>
              </td>
              <td class="text-xs">{{ r.reason_label }}</td>
              <td class="text-xs">{{ fmtDate(r.termination_date) }}</td>
              <td class="text-xs">{{ Number(r.service_years).toFixed(2) }} thn</td>
              <td class="text-right text-xs">{{ fmtCurrency(r.pesangon_amount) }}</td>
              <td class="text-right text-xs">{{ fmtCurrency(r.upmk_amount) }}</td>
              <td class="text-right text-xs">{{ fmtCurrency(r.uph_total) }}</td>
              <td class="text-right font-semibold">{{ fmtCurrency(r.total_amount) }}</td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE[r.status]?.cls">
                  {{ STATUS_BADGE[r.status]?.label || r.status }}
                </span>
              </td>
              <td class="text-right">
                <div class="flex items-center justify-end gap-1">
                  <button class="text-gray-600 hover:text-primary-600 p-1" title="Download PDF" @click="downloadBreakdown(r)">
                    <DocumentArrowDownIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && r.status === 'DRAFT'" class="text-amber-600 hover:text-amber-800 p-1" title="Finalize" @click="finalize(r)">
                    <CheckCircleIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && r.status === 'FINALIZED'" class="text-emerald-600 hover:text-emerald-800 p-1" title="Mark Paid" @click="markPaid(r)">
                    <BanknotesIcon class="w-4 h-4" />
                  </button>
                  <button v-if="canManage && ['DRAFT','FINALIZED'].includes(r.status)" class="text-red-600 hover:text-red-800 p-1" title="Cancel" @click="cancel(r)">
                    <NoSymbolIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Calculator modal -->
    <div v-if="showCalc" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl max-h-[92vh] flex flex-col">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold flex items-center gap-2">
            <CalculatorIcon class="w-5 h-5" /> Kalkulator Severance / Pesangon
          </h2>
          <button class="text-gray-500" @click="showCalc = false">&times;</button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 p-6 overflow-y-auto">
          <!-- Left: form input -->
          <div class="space-y-3">
            <div>
              <label class="label">Karyawan *</label>
              <EmployeeSearchSelect
                v-model="calcInput.employee_id"
                placeholder="— Pilih karyawan —"
                :only-active="true"
              />
              <p v-if="! calcInput.employee_id" class="text-xs text-gray-500 mt-1">
                Setelah pilih karyawan, data gaji, masa kerja, dan sisa cuti akan dimuat otomatis.
              </p>
            </div>

            <!-- Snapshot data karyawan terpilih — feedback visual bahwa
                 backend sudah men-load profil + saldo cuti + masa kerja. -->
            <div v-if="employeeSnapshot"
              class="border border-emerald-200 bg-emerald-50 rounded p-3 text-xs space-y-1.5">
              <div class="flex items-center gap-2 text-emerald-800 font-medium">
                <UserCircleIcon class="w-4 h-4" />
                Data Karyawan Ter-load Otomatis
              </div>
              <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-emerald-900">
                <div>
                  <span class="text-emerald-600">Posisi:</span>
                  {{ employeeSnapshot.position_name || '—' }}
                </div>
                <div>
                  <span class="text-emerald-600">Departemen:</span>
                  {{ employeeSnapshot.department_name || '—' }}
                </div>
                <div>
                  <span class="text-emerald-600">Bergabung:</span>
                  {{ fmtDate(employeeSnapshot.join_date) }}
                </div>
                <div>
                  <span class="text-emerald-600">Gaji Pokok:</span>
                  <strong>{{ fmtCurrency(employeeSnapshot.basic_salary || 0) }}</strong>
                </div>
              </div>
              <div class="text-emerald-700 italic">
                Field di bawah sudah di-auto-fill dari profil + saldo cuti. Edit kalau ada penyesuaian.
              </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="label">Tanggal PHK *</label>
                <input type="date" v-model="calcInput.termination_date" class="input" />
              </div>
              <div>
                <label class="label">Alasan PHK *</label>
                <select v-model="calcInput.termination_reason" class="input">
                  <option v-for="r in reasons" :key="r.value" :value="r.value">{{ r.label }}</option>
                </select>
              </div>
            </div>

            <div v-if="selectedReason" class="text-xs bg-blue-50 border border-blue-100 rounded p-2 text-blue-800">
              <div>Multiplier Pesangon: <strong>{{ selectedReason.pesangon_mul }}×</strong></div>
              <div>Multiplier UPMK: <strong>{{ selectedReason.upmk_mul }}×</strong></div>
              <div v-if="selectedReason.requires_separation" class="mt-1 text-amber-700">
                ⚠ Alasan ini hanya UPH + uang pisah (tidak ada pesangon/UPMK)
              </div>
              <div v-if="selectedReason.is_pkwt" class="mt-1 text-amber-700">
                ⚠ PKWT: kompensasi proporsional 1 bulan upah per 12 bulan masa kerja
              </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="label">Upah bulanan (auto)</label>
                <input type="number" v-model="calcInput.last_monthly_salary" class="input" placeholder="Auto dari profile" />
              </div>
              <div>
                <label class="label">Masa kerja (tahun, auto)</label>
                <input type="number" step="0.01" v-model="calcInput.service_years" class="input" placeholder="Auto dari join_date" />
              </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
              <div>
                <label class="label">Sisa cuti (hari)</label>
                <input type="number" step="0.5" v-model="calcInput.unused_leave_days" class="input" placeholder="Auto dari saldo" />
              </div>
              <div>
                <label class="label">Biaya pulang</label>
                <input type="number" v-model="calcInput.uph_homecoming_amount" class="input" />
              </div>
              <div>
                <label class="label">Hak lain (PKB/PP)</label>
                <input type="number" v-model="calcInput.uph_other_amount" class="input" />
              </div>
            </div>

            <div v-if="selectedReason?.requires_separation">
              <label class="label">Uang pisah (sesuai PKB/PP)</label>
              <input type="number" v-model="calcInput.separation_pay" class="input" />
            </div>

            <div>
              <label class="label">Catatan HR</label>
              <textarea v-model="calcInput.notes" class="input" rows="2" placeholder="Kasus khusus, kesepakatan tambahan..."></textarea>
            </div>

            <div class="flex gap-2 pt-2">
              <button class="btn-secondary flex-1" :disabled="previewing || ! calcInput.employee_id" @click="manualRecalc">
                <ArrowPathIcon class="w-4 h-4" /> {{ previewing ? 'Menghitung...' : 'Hitung Ulang' }}
              </button>
              <button class="btn-primary flex-1" :disabled="saving || ! previewResult" @click="save">
                {{ saving ? 'Menyimpan...' : 'Simpan sebagai DRAFT' }}
              </button>
            </div>
          </div>

          <!-- Right: preview -->
          <div class="border border-gray-200 rounded">
            <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 font-medium text-sm flex items-center justify-between">
              <span>Preview Perhitungan</span>
              <span v-if="previewing" class="text-xs text-blue-600 inline-flex items-center gap-1">
                <ArrowPathIcon class="w-3 h-3 animate-spin" /> Menghitung...
              </span>
            </div>
            <div v-if="! previewResult" class="p-12 text-center text-gray-400 text-sm">
              <div v-if="! calcInput.employee_id">
                Pilih karyawan untuk memulai. Sistem akan otomatis menghitung berdasarkan profil dan masa kerja.
              </div>
              <div v-else>
                Memuat perhitungan...
              </div>
            </div>
            <div v-else class="p-4 text-sm">
              <table class="w-full">
                <tbody class="divide-y divide-gray-100">
                  <tr><td class="py-1.5 text-gray-500">Pesangon ({{ previewResult.pesangon_months }}× × {{ previewResult.pesangon_multiplier }})</td>
                    <td class="text-right">{{ fmtCurrency(previewResult.pesangon_amount) }}</td></tr>
                  <tr v-if="previewResult.upmk_amount > 0"><td class="py-1.5 text-gray-500">UPMK ({{ previewResult.upmk_months }}× × {{ previewResult.upmk_multiplier }})</td>
                    <td class="text-right">{{ fmtCurrency(previewResult.upmk_amount) }}</td></tr>
                  <tr><td class="py-1.5 text-gray-500">UPH cuti ({{ Number(previewResult.unused_leave_days).toFixed(1) }} hari)</td>
                    <td class="text-right">{{ fmtCurrency(previewResult.uph_unused_leave_amount) }}</td></tr>
                  <tr v-if="previewResult.uph_homecoming_amount > 0"><td class="py-1.5 text-gray-500">UPH biaya pulang</td>
                    <td class="text-right">{{ fmtCurrency(previewResult.uph_homecoming_amount) }}</td></tr>
                  <tr v-if="previewResult.uph_other_amount > 0"><td class="py-1.5 text-gray-500">UPH hak lain</td>
                    <td class="text-right">{{ fmtCurrency(previewResult.uph_other_amount) }}</td></tr>
                  <tr v-if="previewResult.separation_pay > 0"><td class="py-1.5 text-gray-500">Uang pisah</td>
                    <td class="text-right">{{ fmtCurrency(previewResult.separation_pay) }}</td></tr>
                  <tr class="font-semibold bg-amber-50">
                    <td class="py-3">TOTAL HAK</td>
                    <td class="text-right text-lg text-amber-900">{{ fmtCurrency(previewResult.total_amount) }}</td>
                  </tr>
                </tbody>
              </table>
              <div class="mt-3 text-xs text-gray-500">
                Berdasarkan: {{ previewResult.reason_label }}<br>
                Masa kerja: {{ Number(previewResult.service_years).toFixed(2) }} tahun ({{ previewResult.service_months }} bulan)<br>
                Upah dasar: {{ fmtCurrency(previewResult.last_monthly_salary) }} / bulan
              </div>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2">
          <button class="btn-secondary" @click="showCalc = false">Tutup</button>
        </div>
      </div>
    </div>
  </div>
</template>