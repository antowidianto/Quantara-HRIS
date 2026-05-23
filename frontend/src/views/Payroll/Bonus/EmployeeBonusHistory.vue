<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { ArrowLeftIcon, GiftIcon, CalendarDaysIcon } from '@heroicons/vue/24/outline'

/**
 * Sprint 23+ — HR View: Bonus history per employee.
 *
 * Pakai endpoint backend Sprint 20+:
 *   GET /employees/{id}/bonus-history?include_pending=true
 *
 * Bedanya dengan MyBonuses.vue:
 *   - target employee dipilih oleh HR via route param (:id)
 *   - support include_pending toggle untuk lihat DRAFT/CALCULATED
 *   - dipakai untuk context merit review / promosi
 */
const route = useRoute()
const toast = useToast()

const loading = ref(false)
const employee = ref(null)
const history = ref([])
const stats = ref({ total_amount: 0, count: 0, by_kind: {} })
const includePending = ref(false)
// Hasil auto-probe ketika hasil utama kosong: berapa entry pending
// (DRAFT/CALCULATED) yang ada, supaya user tahu kalau cuma perlu
// toggle checkbox include_pending. Default null = belum di-probe.
const pendingCount = ref(null)

const employeeId = computed(() => parseInt(route.params.id || 0, 10))

const KIND_LABEL = {
  ANNUAL: 'Annual / Tahunan',
  KPI: 'KPI / Performance',
  MILESTONE: 'Milestone',
  SIGNON: 'Sign-on',
  RETENTION: 'Retention',
  DISCRETIONARY: 'Discretionary',
}
const KIND_COLOR = {
  ANNUAL: 'badge-blue',
  KPI: 'badge-green',
  MILESTONE: 'badge-purple',
  SIGNON: 'badge-yellow',
  RETENTION: 'badge-pink',
  DISCRETIONARY: 'badge-gray',
}
const STATUS_BADGE = {
  DRAFT: 'badge-gray',
  CALCULATED: 'badge-blue',
  APPROVED: 'badge-yellow',
  PAID: 'badge-green',
  CANCELLED: 'badge-red',
}

async function fetchEmployee() {
  try {
    const res = await api.get(`/employees/${employeeId.value}`)
    employee.value = res.data?.data || null
  } catch (_) {
    employee.value = null
  }
}

async function fetchData() {
  if (!employeeId.value) {
    toast.error('Employee ID tidak valid')
    return
  }
  loading.value = true
  pendingCount.value = null
  try {
    const res = await api.get(`/employees/${employeeId.value}/bonus-history`, {
      params: {
        include_pending: includePending.value ? 1 : 0,
        limit: 200,
      },
    })
    history.value = res.data?.data?.history || []
    stats.value = res.data?.data?.stats || { total_amount: 0, count: 0, by_kind: {} }

    // Auto-probe: kalau hasil utama kosong DAN belum include_pending,
    // cek diam-diam apakah ada data pending. Kasi tahu user supaya bisa
    // centang toggle. Mengurangi kebingungan "padahal sudah calculate
    // tapi kok kosong".
    if (history.value.length === 0 && !includePending.value) {
      try {
        const probe = await api.get(`/employees/${employeeId.value}/bonus-history`, {
          params: { include_pending: 1, limit: 1 },
        })
        pendingCount.value = probe.data?.data?.stats?.count ?? 0
      } catch (_) {
        pendingCount.value = 0
      }
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat riwayat bonus')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await fetchEmployee()
  await fetchData()
})

const historyByYear = computed(() => {
  const grouped = {}
  for (const item of history.value) {
    const year = item.period_year || new Date(item.pay_date).getFullYear()
    if (!grouped[year]) grouped[year] = []
    grouped[year].push(item)
  }

  return Object.keys(grouped)
    .sort((a, b) => Number(b) - Number(a))
    .map((year) => ({ year: Number(year), items: grouped[year] }))
})

const taxLabel = (treatment) => {
  switch ((treatment || '').toUpperCase()) {
    case 'NORMAL':
    case 'GROSS':
      return { text: 'Taxable', cls: 'bg-blue-100 text-blue-700' }
    case 'NON_TAXABLE':
      return { text: 'Non-Pajak', cls: 'bg-green-100 text-green-700' }
    case 'GROSS_UP':
      return { text: 'Gross-Up', cls: 'bg-amber-100 text-amber-700' }
    case 'NET':
      return { text: 'Net', cls: 'bg-amber-100 text-amber-700' }
    default:
      return { text: '-', cls: 'bg-gray-100 text-gray-600' }
  }
}
</script>

<template>
  <div>
    <RouterLink to="/employees" class="text-sm text-gray-500 hover:text-primary-600 inline-flex items-center gap-1 mb-3">
      <ArrowLeftIcon class="w-4 h-4" /> Kembali ke daftar karyawan
    </RouterLink>

    <div class="flex items-start justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <GiftIcon class="w-7 h-7 text-amber-600" />
          Bonus History — {{ employee?.full_name || ('Employee #' + employeeId) }}
        </h1>
        <p class="text-sm text-gray-500">
          Riwayat bonus lintas-run untuk evaluasi HR (promosi/merit review).
        </p>
      </div>
      <label class="inline-flex items-center gap-2 text-sm">
        <input v-model="includePending" type="checkbox" @change="fetchData" />
        Include pending (DRAFT/CALCULATED)
      </label>
    </div>

    <div v-if="loading" class="card p-10 text-center text-gray-400">
      Memuat riwayat bonus...
    </div>

    <template v-else-if="history.length === 0">
      <div class="card p-10 text-center">
        <GiftIcon class="w-12 h-12 mx-auto text-gray-300 mb-3" />
        <p class="text-gray-500">Belum ada riwayat bonus yang APPROVED/PAID</p>

        <!-- Auto-probe result: ada data pending tapi default filter exclude -->
        <div v-if="pendingCount > 0"
             class="mt-4 inline-flex flex-col gap-2 max-w-md text-left p-3 bg-blue-50 border border-blue-200 rounded-lg text-xs text-blue-900">
          <div class="flex items-start gap-2">
            <span class="font-semibold">💡 Ditemukan {{ pendingCount }} bonus pending</span>
          </div>
          <p>
            Karyawan ini punya {{ pendingCount }} entry bonus yang masih di status
            <strong>DRAFT</strong> atau <strong>CALCULATED</strong> — belum di-approve HR.
            Centang <em>"Include pending"</em> di kanan atas untuk menampilkannya.
          </p>
          <button
            type="button"
            @click="includePending = true; fetchData()"
            class="btn-primary text-xs self-start mt-1"
          >
            Tampilkan {{ pendingCount }} bonus pending
          </button>
        </div>

        <p v-else-if="pendingCount === 0" class="text-xs text-gray-400 mt-3">
          Tidak ada bonus dalam status apapun untuk karyawan ini.
        </p>
      </div>
    </template>

    <template v-else>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="card p-4">
          <div class="text-xs text-gray-500 mb-1">Total Bonus</div>
          <div class="text-2xl font-bold text-primary-700">{{ fmtCurrency(stats.total_amount) }}</div>
          <div class="text-xs text-gray-400 mt-1">{{ stats.count }} entry</div>
        </div>
        <div class="card p-4 md:col-span-2">
          <div class="text-xs text-gray-500 mb-2">Breakdown by Kind</div>
          <div class="flex flex-wrap gap-2">
            <div
              v-for="(amount, kind) in stats.by_kind"
              :key="kind"
              class="flex items-center gap-2 px-3 py-1.5 rounded-md border border-gray-200 bg-gray-50"
            >
              <span :class="KIND_COLOR[kind] || 'badge-gray'">{{ KIND_LABEL[kind] || kind }}</span>
              <span class="text-sm font-semibold tabular-nums">{{ fmtCurrency(amount) }}</span>
            </div>
          </div>
        </div>
      </div>

      <div v-for="group in historyByYear" :key="group.year" class="mb-6">
        <h2 class="text-lg font-semibold text-gray-700 mb-3 flex items-center gap-2">
          <CalendarDaysIcon class="w-5 h-5 text-gray-400" /> {{ group.year }}
        </h2>
        <div class="card overflow-hidden">
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Run</th>
                  <th>Kind</th>
                  <th>Pay Date</th>
                  <th>Tax</th>
                  <th class="text-right">Amount</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="item in group.items" :key="item.id">
                  <td>
                    <div class="font-medium text-sm">{{ item.run_name || item.scheme_name }}</div>
                    <div class="text-xs text-gray-500 font-mono">{{ item.scheme_code || '-' }}</div>
                  </td>
                  <td>
                    <span :class="KIND_COLOR[item.scheme_kind] || 'badge-gray'">
                      {{ KIND_LABEL[item.scheme_kind] || item.scheme_kind }}
                    </span>
                  </td>
                  <td class="text-sm">{{ fmtDate(item.pay_date) }}</td>
                  <td>
                    <span :class="['text-xs px-1.5 py-0.5 rounded', taxLabel(item.tax_treatment).cls]">
                      {{ taxLabel(item.tax_treatment).text }}
                    </span>
                  </td>
                  <td class="text-right tabular-nums font-semibold">{{ fmtCurrency(item.amount) }}</td>
                  <td>
                    <span :class="STATUS_BADGE[item.run_status] || 'badge-gray'">{{ item.run_status }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>