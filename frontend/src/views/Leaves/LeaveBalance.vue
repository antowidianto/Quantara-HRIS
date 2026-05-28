<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import {
  CalendarDaysIcon, ArrowPathIcon,
  InformationCircleIcon, ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'

/**
 * Halaman saldo cuti karyawan dengan breakdown:
 *  - Allocated (quota tahun ini, sudah ter-prorate jika join < akhir tahun)
 *  - Carried over (sisa tahun lalu, dengan tanggal expiry)
 *  - Used
 *  - Remaining
 *
 * Pegawai biasa otomatis lihat saldo dirinya. HR/super admin bisa pilih
 * employee_id via query string `?employee_id=`.
 */

const auth = useAuthStore()
const route = useRoute()
const toast = useToast()

const employees = ref([])
const filters = reactive({
  employee_id: '',
  year: new Date().getFullYear(),
})
const data = ref(null)
const loading = ref(false)

const isHr = computed(() => auth.can('leave_balance.view') || auth.can('leave.view') || auth.isSuperAdmin)

// Karyawan tidak lagi di-load semua sekaligus — picker pakai
// EmployeeSearchSelect server-side untuk dataset besar.
async function loadEmployees() { /* deprecated: server-side search */ }

async function fetchBalance() {
  const empId = filters.employee_id || auth.user?.employee_id
  if (! empId) {
    toast.warning('Karyawan tidak ditemukan')
    return
  }
  loading.value = true
  try {
    const res = await api.get(`/leave-balances/${empId}`, { params: { year: filters.year } })
    data.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat saldo')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadEmployees()
  // Jika HR pilih employee dari query atau dari list employee
  filters.employee_id = route.query.employee_id || auth.user?.employee_id || ''
  fetchBalance()
})

function fmtDays(n) {
  return Number(n || 0).toFixed(1).replace(/\.0$/, '')
}

function badgeColor(b) {
  if (b.is_expired) return 'bg-red-50 text-red-700 border-red-200'
  if (b.remaining <= 0) return 'bg-gray-50 text-gray-600 border-gray-200'
  if (b.remaining <= 3) return 'bg-amber-50 text-amber-700 border-amber-200'
  return 'bg-emerald-50 text-emerald-700 border-emerald-200'
}

/**
 * Sprint 7 / H1.2 — Carry-over cap warning.
 *
 * Hitung berapa hari yang akan hangus saat year-end carry-over berjalan
 * (1 Januari tahun depan), berdasarkan `remaining` saat ini vs cap di
 * config `carry_over_max_days`. Hanya relevan saat:
 *   - is_carry_over_allowed = true
 *   - carry_over_max_days di-set (NULL = unlimited, tidak perlu warning)
 *   - remaining > cap
 *
 * Return 0 kalau tidak ada risiko hangus.
 */
function atRiskDays(b) {
  if (! b.config?.is_carry_over_allowed) return 0
  const cap = b.config.carry_over_max_days
  if (cap === null || cap === undefined) return 0
  const diff = (b.remaining || 0) - Number(cap)
  return diff > 0 ? Math.round(diff * 10) / 10 : 0
}

/**
 * Total hari berisiko hangus untuk semua leave types karyawan ini.
 * Dipakai untuk banner peringatan di atas tabel.
 */
const totalAtRisk = computed(() => {
  if (! data.value?.balances) return 0
  return data.value.balances.reduce((sum, b) => sum + atRiskDays(b), 0)
})

function categoryLabel(c) {
  const map = {
    ANNUAL: 'Tahunan', SICK: 'Sakit', MATERNITY: 'Melahirkan',
    PATERNITY: 'Pendamping Melahirkan', MENSTRUAL: 'Haid',
    MARRIAGE: 'Menikah', BEREAVEMENT: 'Duka Cita', HAJJ: 'Haji',
    UNPAID: 'Tanpa Bayar', SPECIAL: 'Khusus',
  }
  return map[c] || c
}

const totals = computed(() => {
  if (! data.value?.balances) return { allocated: 0, carried: 0, used: 0, remaining: 0 }
  return data.value.balances.reduce((acc, b) => ({
    allocated: acc.allocated + (b.allocated || 0),
    carried:   acc.carried   + (b.carried_over || 0),
    used:      acc.used      + (b.used || 0),
    remaining: acc.remaining + (b.remaining || 0),
  }), { allocated: 0, carried: 0, used: 0, remaining: 0 })
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <CalendarDaysIcon class="w-6 h-6" /> Saldo Cuti
      </h1>
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Sprint 3 — bulk import saldo cuti awal dari xlsx -->
        <ExcelImporter
          v-if="auth.can('leave_balances.bulk_import')"
          resource="leave_balances"
          button-label="Import Saldo Cuti"
          @committed="fetchBalance()"
        />
        <button class="btn-secondary" @click="fetchBalance" :disabled="loading">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> Refresh
        </button>
      </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
      <div class="card-body grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div v-if="isHr" class="md:col-span-2">
          <label class="label">Karyawan</label>
          <EmployeeSearchSelect
            v-model="filters.employee_id"
            placeholder="— Saya sendiri —"
            :only-active="false"
            @change="fetchBalance"
          />
        </div>
        <div>
          <label class="label">Tahun</label>
          <select v-model.number="filters.year" class="input" @change="fetchBalance">
            <option v-for="y in [new Date().getFullYear() - 2, new Date().getFullYear() - 1, new Date().getFullYear(), new Date().getFullYear() + 1]" :key="y" :value="y">
              {{ y }}
            </option>
          </select>
        </div>
      </div>
    </div>

    <!-- Sprint 7 / H1.2 — Banner peringatan carry-over cap -->
    <div v-if="totalAtRisk > 0"
      class="mb-4 flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-4">
      <ExclamationTriangleIcon class="w-6 h-6 text-amber-600 flex-shrink-0 mt-0.5" />
      <div class="flex-1">
        <div class="font-semibold text-amber-900">
          {{ fmtDays(totalAtRisk) }} hari cuti akan hangus 1 Januari {{ filters.year + 1 }}
        </div>
        <div class="text-sm text-amber-800 mt-1">
          Saldo tahun ini melebihi batas yang bisa di-carry-over ke tahun depan.
          Silakan ajukan cuti sebelum akhir tahun supaya saldo tidak hangus.
          Detail per tipe cuti ada di tabel di bawah.
        </div>
      </div>
    </div>

    <!-- Total cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="card-body bg-blue-50 rounded-lg border border-blue-100">
        <div class="text-xs text-blue-700 font-medium">Quota Tahun Ini</div>
        <div class="text-2xl font-bold text-blue-900">{{ fmtDays(totals.allocated) }} <span class="text-sm font-normal">hari</span></div>
      </div>
      <div class="card-body bg-purple-50 rounded-lg border border-purple-100">
        <div class="text-xs text-purple-700 font-medium">Carry-over</div>
        <div class="text-2xl font-bold text-purple-900">{{ fmtDays(totals.carried) }} <span class="text-sm font-normal">hari</span></div>
      </div>
      <div class="card-body bg-amber-50 rounded-lg border border-amber-100">
        <div class="text-xs text-amber-700 font-medium">Sudah Dipakai</div>
        <div class="text-2xl font-bold text-amber-900">{{ fmtDays(totals.used) }} <span class="text-sm font-normal">hari</span></div>
      </div>
      <div class="card-body bg-emerald-50 rounded-lg border border-emerald-100">
        <div class="text-xs text-emerald-700 font-medium">Sisa Saldo</div>
        <div class="text-2xl font-bold text-emerald-900">{{ fmtDays(totals.remaining) }} <span class="text-sm font-normal">hari</span></div>
      </div>
    </div>

    <!-- Per-type breakdown -->
    <div class="card">
      <div class="card-header"><span class="font-medium">Detail per Tipe Cuti</span></div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Tipe Cuti</th>
              <th>Kategori</th>
              <th class="text-right">Quota</th>
              <th class="text-right">Carry-over</th>
              <th class="text-right">Dipakai</th>
              <th class="text-right">Sisa</th>
              <th>Catatan</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="7" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="! data || data.balances.length === 0">
              <td colspan="7" class="text-center py-8 text-gray-400">Belum ada saldo untuk tahun ini</td>
            </tr>
            <tr v-for="b in data?.balances || []" :key="b.leave_type_id">
              <td>
                <div class="font-medium">{{ b.name }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ b.code }}</div>
              </td>
              <td>{{ categoryLabel(b.category) }}</td>
              <td class="text-right">
                {{ fmtDays(b.allocated) }}
                <span v-if="b.is_prorated && b.prorate_factor < 1" class="text-xs text-gray-500 block">
                  ({{ b.months_worked }}/12 bln)
                </span>
              </td>
              <td class="text-right">
                <span v-if="b.carried_over > 0" class="font-medium text-purple-700">+{{ fmtDays(b.carried_over) }}</span>
                <span v-else class="text-gray-400">—</span>
              </td>
              <td class="text-right text-amber-700">{{ fmtDays(b.used) }}</td>
              <td class="text-right">
                <span :class="['inline-block px-2 py-0.5 rounded-full text-xs border', badgeColor(b)]">
                  {{ fmtDays(b.remaining) }} hari
                </span>
              </td>
              <td class="text-xs">
                <!-- Risiko hangus karena melebihi cap (Sprint 7 / H1.2) -->
                <div v-if="atRiskDays(b) > 0"
                  class="flex items-center gap-1 text-amber-700 font-medium mb-1"
                  :title="`Sisa ${fmtDays(b.remaining)} hari, cap carry-over ${b.config.carry_over_max_days} hari`">
                  <ExclamationTriangleIcon class="w-3.5 h-3.5" />
                  {{ fmtDays(atRiskDays(b)) }} hari akan hangus 1 Jan {{ filters.year + 1 }}
                </div>
                <div v-if="b.expires_at" class="flex items-center gap-1"
                  :class="b.is_expired ? 'text-red-600' : 'text-gray-500'">
                  <ExclamationTriangleIcon v-if="b.is_expired" class="w-3.5 h-3.5" />
                  Carry-over berakhir {{ b.expires_at }}
                  <span v-if="b.is_expired"> (kadaluarsa)</span>
                </div>
                <div v-else-if="b.config.is_carry_over_allowed" class="text-gray-500">
                  Bisa di-carry max {{ b.config.carry_over_max_days || '∞' }} hari
                </div>
                <div v-else class="text-gray-400">Reset tiap tahun</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Help text -->
    <div class="mt-4 flex items-start gap-2 text-sm text-gray-500 bg-gray-50 rounded p-3">
      <InformationCircleIcon class="w-5 h-5 flex-shrink-0 text-gray-400 mt-0.5" />
      <div>
        Quota di-prorate proporsional jika Anda bergabung di pertengahan tahun.
        Carry-over mengacu UU 13/2003 Pasal 79: cuti yang tidak terpakai dalam
        6 bulan setelah masa berlakunya dapat dianggap gugur.
      </div>
    </div>
  </div>
</template>