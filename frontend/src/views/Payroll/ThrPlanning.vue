<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtCurrency } from '@/composables/useResource'
import {
  GiftIcon, ArrowPathIcon, ArrowDownTrayIcon, UsersIcon,
  BuildingOffice2Icon, BanknotesIcon, ChartBarIcon, InformationCircleIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 9 / H1.8 — THR Planning Dashboard.
 *
 * HR/Finance dapat memproyeksikan total THR per tahun sebelum payment date.
 * Berdasarkan PMK 6/2016: tenure ≥ 12 bulan → 1× gaji; < 12 bulan → proporsional.
 *
 * Endpoint: GET /thr/projection?year=YYYY&pay_date=YYYY-MM-DD
 *
 * Output backend:
 *   - count: total karyawan eligible
 *   - total_gross: grand total proyeksi
 *   - by_department[]: breakdown per dept
 *   - by_cost_center[]: breakdown per cost center
 *   - top_employees[]: 10 karyawan dengan THR terbesar
 */

const toast = useToast()

const filters = reactive({
  year: new Date().getFullYear(),
  pay_date: '',
})

const data = ref(null)
const loading = ref(false)

async function fetchProjection() {
  loading.value = true
  try {
    const params = { year: filters.year }
    if (filters.pay_date) params.pay_date = filters.pay_date
    const res = await api.get('/thr/projection', { params })
    data.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat proyeksi THR')
  } finally {
    loading.value = false
  }
}

function exportCsv() {
  // Generate CSV dari top_employees + breakdown supaya finance bisa
  // edit di Excel sebelum forecast budget.
  if (! data.value) return
  const lines = [
    `THR Projection ${filters.year}`,
    `Generated: ${new Date().toISOString()}`,
    '',
    `Total Karyawan,${data.value.count}`,
    `Total Gross,${data.value.total_gross}`,
    '',
    '== Breakdown per Department ==',
    'Department,Count,Total',
    ...(data.value.by_department || []).map(r =>
      `"${r.department_name || '—'}",${r.count},${r.total}`
    ),
    '',
    '== Breakdown per Cost Center ==',
    'Cost Center,Count,Total',
    ...(data.value.by_cost_center || []).map(r =>
      `"${r.cost_center_name || '—'}",${r.count},${r.total}`
    ),
    '',
    '== Top 10 Karyawan ==',
    'Nama,Department,Tenure (bulan),THR Amount',
    ...(data.value.top_employees || []).map(r =>
      `"${r.full_name}","${r.department_name || ''}",${r.tenure_months},${r.amount}`
    ),
  ]
  const csv = lines.join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `thr-projection-${filters.year}.csv`
  document.body.appendChild(a)
  a.click()
  setTimeout(() => {
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }, 0)
  toast.success('CSV diunduh')
}

const avgPerEmployee = computed(() => {
  if (! data.value || data.value.count === 0) return 0
  return data.value.total_gross / data.value.count
})

// Tahun-tahun yang masuk akal: tahun lalu, sekarang, depan
const yearOptions = (() => {
  const y = new Date().getFullYear()
  return [y - 1, y, y + 1]
})()

onMounted(fetchProjection)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <GiftIcon class="w-6 h-6" /> Proyeksi THR
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Dasar perhitungan: PMK 6/2016 — tenure ≥ 12 bulan dapat 1× gaji, kurang dari 12 bulan proporsional.
        </p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary" @click="fetchProjection" :disabled="loading">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> Hitung Ulang
        </button>
        <button class="btn-primary" @click="exportCsv" :disabled="! data || loading">
          <ArrowDownTrayIcon class="w-4 h-4" /> Export CSV
        </button>
      </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
      <div class="card-body grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div>
          <label class="label">Tahun</label>
          <select v-model.number="filters.year" class="input" @change="fetchProjection">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div>
          <label class="label">Pay Date (opsional)</label>
          <input type="date" v-model="filters.pay_date" class="input" @change="fetchProjection" />
          <p class="text-xs text-gray-500 mt-1">Default: H-7 sebelum Idul Fitri tahun {{ filters.year }}</p>
        </div>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
      <div class="card-body bg-blue-50 rounded-lg border border-blue-100">
        <div class="flex items-center gap-2 text-blue-700">
          <UsersIcon class="w-4 h-4" />
          <div class="text-xs font-medium">Karyawan Eligible</div>
        </div>
        <div class="text-2xl font-bold text-blue-900 mt-1">
          {{ data?.count?.toLocaleString('id-ID') || 0 }}
        </div>
      </div>
      <div class="card-body bg-emerald-50 rounded-lg border border-emerald-100 md:col-span-2">
        <div class="flex items-center gap-2 text-emerald-700">
          <BanknotesIcon class="w-4 h-4" />
          <div class="text-xs font-medium">Total Proyeksi THR</div>
        </div>
        <div class="text-2xl font-bold text-emerald-900 mt-1">
          {{ fmtCurrency(data?.total_gross || 0) }}
        </div>
      </div>
      <div class="card-body bg-purple-50 rounded-lg border border-purple-100">
        <div class="flex items-center gap-2 text-purple-700">
          <ChartBarIcon class="w-4 h-4" />
          <div class="text-xs font-medium">Rata-rata / Karyawan</div>
        </div>
        <div class="text-2xl font-bold text-purple-900 mt-1">
          {{ fmtCurrency(avgPerEmployee) }}
        </div>
      </div>
    </div>

    <div v-if="loading" class="card">
      <div class="card-body text-center py-12 text-gray-400">Menghitung proyeksi…</div>
    </div>

    <div v-else-if="! data" class="card">
      <div class="card-body text-center py-12 text-gray-400">Belum ada data</div>
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Per Department -->
      <div class="card">
        <div class="card-header flex items-center gap-2">
          <BuildingOffice2Icon class="w-4 h-4" />
          <span class="font-medium">Breakdown per Department</span>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Department</th>
                <th class="text-right">Karyawan</th>
                <th class="text-right">Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="r in data.by_department || []" :key="`d${r.department_id || 0}`">
                <td>{{ r.department_name || '— Tanpa Dept —' }}</td>
                <td class="text-right">{{ r.count }}</td>
                <td class="text-right font-medium">{{ fmtCurrency(r.total) }}</td>
              </tr>
              <tr v-if="(data.by_department || []).length === 0">
                <td colspan="3" class="text-center py-6 text-gray-400">Tidak ada data</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Per Cost Center -->
      <div class="card">
        <div class="card-header flex items-center gap-2">
          <BanknotesIcon class="w-4 h-4" />
          <span class="font-medium">Breakdown per Cost Center</span>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Cost Center</th>
                <th class="text-right">Karyawan</th>
                <th class="text-right">Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="r in data.by_cost_center || []" :key="`cc${r.cost_center_id || 0}`">
                <td>{{ r.cost_center_name || '— Belum Mapping —' }}</td>
                <td class="text-right">{{ r.count }}</td>
                <td class="text-right font-medium">{{ fmtCurrency(r.total) }}</td>
              </tr>
              <tr v-if="(data.by_cost_center || []).length === 0">
                <td colspan="3" class="text-center py-6 text-gray-400">Tidak ada data</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Top employees -->
    <div v-if="data?.top_employees?.length" class="card mt-4">
      <div class="card-header">
        <span class="font-medium">Top 10 Karyawan dengan THR Terbesar</span>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Karyawan</th>
              <th>Department</th>
              <th class="text-right">Tenure</th>
              <th class="text-right">THR Amount</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(r, i) in data.top_employees" :key="r.employee_id">
              <td>{{ i + 1 }}</td>
              <td>
                <div class="font-medium">{{ r.full_name }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ r.employee_number }}</div>
              </td>
              <td>{{ r.department_name || '—' }}</td>
              <td class="text-right">{{ r.tenure_months }} bln</td>
              <td class="text-right font-medium">{{ fmtCurrency(r.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Help -->
    <div class="mt-4 flex items-start gap-2 text-sm text-gray-500 bg-gray-50 rounded p-3">
      <InformationCircleIcon class="w-5 h-5 flex-shrink-0 text-gray-400 mt-0.5" />
      <div>
        Proyeksi termasuk karyawan yang masih aktif <strong>per pay_date</strong>.
        Karyawan yang resign sebelum Hari Raya tetap berhak THR proporsional jika masa
        kerja minimal 1 bulan dan resign kurang dari 30 hari sebelum Hari Raya (PMK 6/2016 Pasal 7).
        Angka di sini adalah <em>proyeksi gross</em> — pemotongan PPh21 dilakukan saat run aktual.
      </div>
    </div>
  </div>
</template>