<script setup>
import { ref, reactive, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtCurrency } from '@/composables/useResource'
import {
  CalculatorIcon, BeakerIcon,
  ArrowsRightLeftIcon, InformationCircleIcon, PlayIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 13 / H2.7 — Payroll Simulasi & What-If.
 *
 * Tiga mode simulasi:
 *   1. Single  — hitung 1 skenario (gross, BPJS, PPh21, THP)
 *   2. Compare — bandingkan 2 skenario (current vs proposed) side-by-side
 *   3. Reverse — cari basic_salary dari target THP (bisection)
 *
 * Endpoint backend (lihat backend/app/Libraries/PayrollSimulationService.php):
 *   POST /payroll/simulate
 *   POST /payroll/simulate/compare       body: { current, proposed }
 *   POST /payroll/simulate/reverse       body: { target_net, base: {...} }
 *
 * Kontrak input backend:
 *   - basic_salary (number, > 0)
 *   - allowances (object<string, number>) → ['transport' => 500000, 'meal' => 200000]
 *   - taxable_extras (object<string, number>)
 *   - non_taxable_extras (object<string, number>)
 *   - ptkp_status, pph21_method, employment_status (string)
 *
 * Kontrak output backend (nested):
 *   {
 *     gross_total, taxable_gross, non_taxable,
 *     bpjs: { kes_employee, jht_employee, jp_employee,
 *             kes_company, jht_company, ..., employee_total, company_total },
 *     pph21: { monthly, method, rate, category, yearly },
 *     deductions_total, net_takehome, company_cost,
 *     inputs: {...},
 *   }
 *
 * Helper `flatten()` di bawah translate output nested → flat shape yang
 * gampang dipakai di template (gross, net, bpjs_employee, pph21,
 * employer_cost, dst). Sebelumnya UI baca key yang salah (mis.
 * `result.gross` padahal backend kirim `result.gross_total`) yang bikin
 * tampil "Rp NaN".
 */

const toast = useToast()

const mode = ref('single') // single | compare | reverse

// ─── Single mode ────────────────────────────────────
const single = reactive({
  basic_salary: 10000000,
  allowance_amount: 0,        // disatuin jadi satu key 'misc' saat kirim
  taxable_extras_amount: 0,
  non_taxable_amount: 0,
  ptkp_status: 'TK0',
  pph21_method: 'TER',
  employment_status: 'PKWTT',
})
const singleResult = ref(null)

// ─── Compare mode ───────────────────────────────────
const blankScenario = () => ({
  basic_salary: 10000000, allowance_amount: 0, taxable_extras_amount: 0,
  non_taxable_amount: 0, ptkp_status: 'TK0', pph21_method: 'TER',
  employment_status: 'PKWTT',
})
const compareForm = reactive({
  current:  { ...blankScenario() },
  proposed: { ...blankScenario(), basic_salary: 12000000 },
})
const compareResult = ref(null)

// ─── Reverse mode ───────────────────────────────────
const reverse = reactive({
  target_thp: 10000000,
  allowance_amount: 0,
  ptkp_status: 'TK0',
  pph21_method: 'TER',
  employment_status: 'PKWTT',
})
const reverseResult = ref(null)

const loading = ref(false)

const PTKP_OPTIONS = ['TK0', 'TK1', 'TK2', 'TK3', 'K0', 'K1', 'K2', 'K3', 'K/I/0', 'K/I/1', 'K/I/2', 'K/I/3']

/**
 * Bentuk payload sesuai kontrak backend: scalar di form di-bungkus ke
 * map { name: amount } supaya array_sum di service jalan. Kalau 0,
 * tetap kirim object kosong supaya service tidak fallback ke int.
 */
function toBackendPayload(form) {
  const out = {
    basic_salary:      Number(form.basic_salary) || 0,
    allowances:        Number(form.allowance_amount) > 0
      ? { allowance: Number(form.allowance_amount) }
      : {},
    taxable_extras:    Number(form.taxable_extras_amount) > 0
      ? { extras: Number(form.taxable_extras_amount) }
      : {},
    non_taxable_extras: Number(form.non_taxable_amount) > 0
      ? { non_taxable: Number(form.non_taxable_amount) }
      : {},
    ptkp_status:       form.ptkp_status,
    pph21_method:      form.pph21_method,
    employment_status: form.employment_status,
  }
  return out
}

/**
 * Translate nested output backend → flat shape yang dipakai template.
 * Tahan terhadap field yang null / hilang supaya tidak NaN di UI.
 */
function flatten(r) {
  if (! r) return null
  const num = (v) => (typeof v === 'number' && isFinite(v)) ? v : Number(v) || 0
  return {
    basic_salary:   num(r.inputs?.basic_salary),
    allowances:     num(r.inputs?.allowances?.allowance),
    taxable_extras: num(r.inputs?.taxable_extras?.extras),
    gross:          num(r.gross_total),
    taxable_gross:  num(r.taxable_gross),
    bpjs_employee:  num(r.bpjs?.employee_total),
    bpjs_company:   num(r.bpjs?.company_total),
    pph21:          num(r.pph21?.monthly),
    pph21_method:   r.pph21?.method ?? null,
    pph21_rate:     r.pph21?.rate ?? null,
    pph21_category: r.pph21?.category ?? null,
    deductions:     num(r.deductions_total),
    net:            num(r.net_takehome),
    employer_cost:  num(r.company_cost),
    raw:            r,
  }
}

async function simulateSingle() {
  loading.value = true
  try {
    const res = await api.post('/payroll/simulate', toBackendPayload(single))
    singleResult.value = flatten(res.data.data)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Simulasi gagal')
  } finally {
    loading.value = false
  }
}

async function simulateCompare() {
  loading.value = true
  try {
    const res = await api.post('/payroll/simulate/compare', {
      current:  toBackendPayload(compareForm.current),
      proposed: toBackendPayload(compareForm.proposed),
    })
    const d = res.data.data
    compareResult.value = {
      current:  flatten(d.current),
      proposed: flatten(d.proposed),
      delta:    d.delta,  // backend sudah hitung delta; gak perlu re-compute di client
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Compare gagal')
  } finally {
    loading.value = false
  }
}

async function simulateReverse() {
  loading.value = true
  try {
    // Endpoint backend: target_net + base (PayrollSimulationService::reverseFromNet).
    const base = toBackendPayload({ ...reverse, basic_salary: 1 }) // basic akan di-tune
    delete base.basic_salary
    const res = await api.post('/payroll/simulate/reverse', {
      target_net: Number(reverse.target_thp) || 0,
      base,
    })
    const flat = flatten(res.data.data)
    reverseResult.value = {
      basic_salary: flat.basic_salary,
      actual_thp:   flat.net,
      breakdown:    flat,
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Reverse calc gagal')
  } finally {
    loading.value = false
  }
}

const compareDelta = computed(() => {
  if (! compareResult.value) return null
  // Backend sudah kirim delta nested; map ke flat keys yang dipakai
  // template. Fallback ke compute di client kalau backend tidak ada
  // (mis. respons gagal dipotong middleware).
  const d = compareResult.value.delta || {}
  const c = compareResult.value.current
  const p = compareResult.value.proposed
  return {
    gross:         d.gross_total       ?? (p.gross - c.gross),
    bpjs:          d.bpjs_employee     ?? (p.bpjs_employee - c.bpjs_employee),
    pph:           d.pph21_monthly     ?? (p.pph21 - c.pph21),
    net:           d.net_takehome      ?? (p.net - c.net),
    employer_cost: d.company_cost      ?? (p.employer_cost - c.employer_cost),
  }
})

function deltaBadge(v) {
  if (! v) return 'text-gray-500'
  if (v > 0) return 'text-emerald-600'
  return 'text-red-600'
}
function deltaSign(v) {
  if (! v) return ''
  return v > 0 ? '+' : ''
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <CalculatorIcon class="w-6 h-6" /> Simulasi Payroll
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Hitung gross, BPJS, PPh21, dan THP tanpa menjalankan payroll period real.
          Re-use kalkulator yang dipakai di payroll run aktual.
        </p>
      </div>
    </div>

    <!-- Mode tabs -->
    <div class="border-b border-gray-200 mb-4 flex gap-1">
      <button v-for="t in [
        { id: 'single', label: 'Hitung Tunggal', icon: CalculatorIcon },
        { id: 'compare', label: 'Bandingkan 2 Skenario', icon: ArrowsRightLeftIcon },
        { id: 'reverse', label: 'Reverse: Target THP', icon: BeakerIcon },
      ]" :key="t.id"
        @click="mode = t.id"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors flex items-center gap-2',
          mode === t.id
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-gray-500 hover:text-gray-700'
        ]"
      >
        <component :is="t.icon" class="w-4 h-4" />
        {{ t.label }}
      </button>
    </div>

    <!-- ─────────── Single ─────────── -->
    <div v-if="mode === 'single'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <div class="card">
        <div class="card-header"><span class="font-medium">Input Skenario</span></div>
        <div class="card-body grid grid-cols-2 gap-3">
          <div class="col-span-2">
            <label class="label">Gaji Pokok</label>
            <input type="number" v-model.number="single.basic_salary" class="input" min="0" />
          </div>
          <div>
            <label class="label">Tunjangan (taxable)</label>
            <input type="number" v-model.number="single.allowance_amount" class="input" min="0" />
          </div>
          <div>
            <label class="label">Earnings Lain (taxable)</label>
            <input type="number" v-model.number="single.taxable_extras_amount" class="input" min="0" />
            <p class="text-xs text-gray-500 mt-1">contoh: bonus, OT, komisi</p>
          </div>
          <div>
            <label class="label">Non-Taxable Extras</label>
            <input type="number" v-model.number="single.non_taxable_amount" class="input" min="0" />
            <p class="text-xs text-gray-500 mt-1">mis. tunjangan medis</p>
          </div>
          <div>
            <label class="label">PTKP Status</label>
            <select v-model="single.ptkp_status" class="input">
              <option v-for="o in PTKP_OPTIONS" :key="o" :value="o">{{ o }}</option>
            </select>
          </div>
          <div>
            <label class="label">Metode PPh21</label>
            <select v-model="single.pph21_method" class="input">
              <option value="TER">TER (Jan-Nov)</option>
              <option value="PROGRESSIVE">Progressive (Desember reconciliation)</option>
            </select>
          </div>
          <div>
            <label class="label">Status Karyawan</label>
            <select v-model="single.employment_status" class="input">
              <option value="PKWTT">PKWTT</option>
              <option value="PKWT">PKWT</option>
              <option value="HARIAN">Harian</option>
            </select>
          </div>
          <div class="col-span-2">
            <button class="btn-primary w-full" @click="simulateSingle" :disabled="loading">
              <PlayIcon class="w-4 h-4" /> {{ loading ? 'Menghitung…' : 'Hitung Simulasi' }}
            </button>
          </div>
        </div>
      </div>

      <div class="card relative">
        <div class="card-header flex items-center justify-between">
          <span class="font-medium">Hasil Simulasi</span>
          <span class="text-xs text-amber-600 bg-amber-100 px-2 py-0.5 rounded-full">SIMULASI</span>
        </div>
        <div v-if="! singleResult" class="card-body text-center py-12 text-gray-400">
          Tekan "Hitung Simulasi" untuk melihat output
        </div>
        <div v-else class="card-body space-y-2">
          <div class="grid grid-cols-2 gap-3 mb-3">
            <div class="bg-blue-50 rounded p-3">
              <div class="text-xs text-blue-700">Gross</div>
              <div class="text-lg font-bold text-blue-900">{{ fmtCurrency(singleResult.gross) }}</div>
            </div>
            <div class="bg-emerald-50 rounded p-3">
              <div class="text-xs text-emerald-700">Take-Home Pay</div>
              <div class="text-lg font-bold text-emerald-900">{{ fmtCurrency(singleResult.net) }}</div>
            </div>
          </div>
          <div class="border-t pt-2 space-y-1.5 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-600">Gaji Pokok</span>
              <span>{{ fmtCurrency(singleResult.basic_salary) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Tunjangan (taxable)</span>
              <span>{{ fmtCurrency(singleResult.allowances) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Earnings Lain (taxable)</span>
              <span>{{ fmtCurrency(singleResult.taxable_extras) }}</span>
            </div>
            <div class="flex justify-between font-medium border-t pt-1.5">
              <span>Gross</span>
              <span>{{ fmtCurrency(singleResult.gross) }}</span>
            </div>
            <div class="flex justify-between text-red-700">
              <span>BPJS Karyawan</span>
              <span>-{{ fmtCurrency(singleResult.bpjs_employee) }}</span>
            </div>
            <div class="flex justify-between text-red-700">
              <span>PPh21 ({{ singleResult.pph21_method || single.pph21_method }})</span>
              <span>-{{ fmtCurrency(singleResult.pph21) }}</span>
            </div>
            <div class="flex justify-between font-bold border-t pt-1.5 text-lg">
              <span>Net (THP)</span>
              <span class="text-emerald-700">{{ fmtCurrency(singleResult.net) }}</span>
            </div>
          </div>
          <div v-if="singleResult.employer_cost" class="bg-amber-50 rounded p-2 text-xs text-amber-800 mt-2">
            <strong>Employer Cost:</strong> {{ fmtCurrency(singleResult.employer_cost) }}
            (gross + BPJS porsi perusahaan)
          </div>
        </div>
      </div>
    </div>

    <!-- ─────────── Compare ─────────── -->
    <div v-if="mode === 'compare'" class="space-y-4">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Current -->
        <div class="card">
          <div class="card-header bg-blue-50">
            <span class="font-medium text-blue-900">Skenario Current</span>
          </div>
          <div class="card-body grid grid-cols-2 gap-3">
            <div class="col-span-2">
              <label class="label">Gaji Pokok</label>
              <input type="number" v-model.number="compareForm.current.basic_salary" class="input" min="0" />
            </div>
            <div>
              <label class="label">Tunjangan</label>
              <input type="number" v-model.number="compareForm.current.allowance_amount" class="input" min="0" />
            </div>
            <div>
              <label class="label">PTKP</label>
              <select v-model="compareForm.current.ptkp_status" class="input">
                <option v-for="o in PTKP_OPTIONS" :key="o" :value="o">{{ o }}</option>
              </select>
            </div>
          </div>
        </div>
        <!-- Proposed -->
        <div class="card">
          <div class="card-header bg-emerald-50">
            <span class="font-medium text-emerald-900">Skenario Proposed</span>
          </div>
          <div class="card-body grid grid-cols-2 gap-3">
            <div class="col-span-2">
              <label class="label">Gaji Pokok</label>
              <input type="number" v-model.number="compareForm.proposed.basic_salary" class="input" min="0" />
            </div>
            <div>
              <label class="label">Tunjangan</label>
              <input type="number" v-model.number="compareForm.proposed.allowance_amount" class="input" min="0" />
            </div>
            <div>
              <label class="label">PTKP</label>
              <select v-model="compareForm.proposed.ptkp_status" class="input">
                <option v-for="o in PTKP_OPTIONS" :key="o" :value="o">{{ o }}</option>
              </select>
            </div>
          </div>
        </div>
      </div>
      <button class="btn-primary w-full" @click="simulateCompare" :disabled="loading">
        <ArrowsRightLeftIcon class="w-4 h-4" /> {{ loading ? 'Menghitung…' : 'Bandingkan' }}
      </button>

      <div v-if="compareResult" class="card">
        <div class="card-header flex items-center justify-between">
          <span class="font-medium">Perbandingan</span>
          <span class="text-xs text-amber-600 bg-amber-100 px-2 py-0.5 rounded-full">SIMULASI</span>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Komponen</th>
                <th class="text-right">Current</th>
                <th class="text-right">Proposed</th>
                <th class="text-right">Selisih</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr>
                <td>Gross</td>
                <td class="text-right">{{ fmtCurrency(compareResult.current.gross) }}</td>
                <td class="text-right">{{ fmtCurrency(compareResult.proposed.gross) }}</td>
                <td class="text-right font-medium" :class="deltaBadge(compareDelta.gross)">
                  {{ deltaSign(compareDelta.gross) }}{{ fmtCurrency(compareDelta.gross) }}
                </td>
              </tr>
              <tr>
                <td>BPJS Karyawan</td>
                <td class="text-right">{{ fmtCurrency(compareResult.current.bpjs_employee) }}</td>
                <td class="text-right">{{ fmtCurrency(compareResult.proposed.bpjs_employee) }}</td>
                <td class="text-right" :class="deltaBadge(-compareDelta.bpjs)">
                  {{ deltaSign(compareDelta.bpjs) }}{{ fmtCurrency(compareDelta.bpjs) }}
                </td>
              </tr>
              <tr>
                <td>PPh21</td>
                <td class="text-right">{{ fmtCurrency(compareResult.current.pph21) }}</td>
                <td class="text-right">{{ fmtCurrency(compareResult.proposed.pph21) }}</td>
                <td class="text-right" :class="deltaBadge(-compareDelta.pph)">
                  {{ deltaSign(compareDelta.pph) }}{{ fmtCurrency(compareDelta.pph) }}
                </td>
              </tr>
              <tr class="bg-emerald-50/50 font-bold">
                <td>Net (THP)</td>
                <td class="text-right">{{ fmtCurrency(compareResult.current.net) }}</td>
                <td class="text-right">{{ fmtCurrency(compareResult.proposed.net) }}</td>
                <td class="text-right text-lg" :class="deltaBadge(compareDelta.net)">
                  {{ deltaSign(compareDelta.net) }}{{ fmtCurrency(compareDelta.net) }}
                </td>
              </tr>
              <tr v-if="compareResult.current.employer_cost" class="bg-amber-50/50">
                <td>Employer Cost</td>
                <td class="text-right">{{ fmtCurrency(compareResult.current.employer_cost) }}</td>
                <td class="text-right">{{ fmtCurrency(compareResult.proposed.employer_cost) }}</td>
                <td class="text-right font-medium" :class="deltaBadge(compareDelta.employer_cost)">
                  {{ deltaSign(compareDelta.employer_cost) }}{{ fmtCurrency(compareDelta.employer_cost) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ─────────── Reverse ─────────── -->
    <div v-if="mode === 'reverse'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <div class="card">
        <div class="card-header"><span class="font-medium">Target Take-Home Pay</span></div>
        <div class="card-body grid grid-cols-2 gap-3">
          <div class="col-span-2">
            <label class="label">Target THP yang diinginkan</label>
            <input type="number" v-model.number="reverse.target_thp" class="input" min="0" />
            <p class="text-xs text-gray-500 mt-1">
              Sistem akan mencari basic_salary yang menghasilkan THP = target ini.
            </p>
          </div>
          <div>
            <label class="label">Tunjangan</label>
            <input type="number" v-model.number="reverse.allowance_amount" class="input" min="0" />
          </div>
          <div>
            <label class="label">PTKP</label>
            <select v-model="reverse.ptkp_status" class="input">
              <option v-for="o in PTKP_OPTIONS" :key="o" :value="o">{{ o }}</option>
            </select>
          </div>
          <div>
            <label class="label">Metode PPh21</label>
            <select v-model="reverse.pph21_method" class="input">
              <option value="TER">TER</option>
              <option value="PROGRESSIVE">Progressive</option>
            </select>
          </div>
          <div>
            <label class="label">Status</label>
            <select v-model="reverse.employment_status" class="input">
              <option value="PKWTT">PKWTT</option>
              <option value="PKWT">PKWT</option>
            </select>
          </div>
          <div class="col-span-2">
            <button class="btn-primary w-full" @click="simulateReverse" :disabled="loading">
              <BeakerIcon class="w-4 h-4" />
              {{ loading ? 'Mencari…' : 'Cari Basic Salary' }}
            </button>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-header flex items-center justify-between">
          <span class="font-medium">Hasil Reverse</span>
          <span class="text-xs text-amber-600 bg-amber-100 px-2 py-0.5 rounded-full">SIMULASI</span>
        </div>
        <div v-if="! reverseResult" class="card-body text-center py-12 text-gray-400">
          Tekan "Cari Basic Salary" untuk reverse calculation
        </div>
        <div v-else class="card-body space-y-3">
          <div class="bg-emerald-50 rounded p-4 text-center">
            <div class="text-xs text-emerald-700">Basic Salary yang dibutuhkan</div>
            <div class="text-3xl font-bold text-emerald-900 mt-1">
              {{ fmtCurrency(reverseResult.basic_salary) }}
            </div>
            <div class="text-xs text-emerald-700 mt-2">
              menghasilkan THP ≈ {{ fmtCurrency(reverseResult.actual_thp) }}
              (target {{ fmtCurrency(reverse.target_thp) }})
            </div>
          </div>
          <div v-if="reverseResult.breakdown" class="border-t pt-2 space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-600">Gross</span>
              <span>{{ fmtCurrency(reverseResult.breakdown.gross) }}</span>
            </div>
            <div class="flex justify-between text-red-700">
              <span>BPJS</span>
              <span>-{{ fmtCurrency(reverseResult.breakdown.bpjs_employee) }}</span>
            </div>
            <div class="flex justify-between text-red-700">
              <span>PPh21</span>
              <span>-{{ fmtCurrency(reverseResult.breakdown.pph21) }}</span>
            </div>
            <div class="flex justify-between font-bold border-t pt-1.5">
              <span>Net</span>
              <span class="text-emerald-700">{{ fmtCurrency(reverseResult.breakdown.net) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Help -->
    <div class="mt-4 flex items-start gap-2 text-sm text-gray-500 bg-gray-50 rounded p-3">
      <InformationCircleIcon class="w-5 h-5 flex-shrink-0 text-gray-400 mt-0.5" />
      <div>
        Hasil simulasi <strong>tidak persist</strong> ke tabel payslips dan tidak memengaruhi data real.
        Engine yang dipakai sama dengan payroll run aktual (BpjsCalculator + Pph21TerCalculator),
        sehingga angka konsisten. Tarif PTKP & TER mengikuti regulasi PMK 168/2023 + PP 58/2023.
      </div>
    </div>
  </div>
</template>