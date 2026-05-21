<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { fmtCurrency } from '@/composables/useResource'

const headcount = ref(null)
const month = ref(new Date().toISOString().slice(0, 7))
const attendance = ref([])
const payrollSummary = ref([])
const leaveSummary = ref([])

async function loadAll() {
  const [hc, att, pr, lv] = await Promise.allSettled([
    api.get('/reports/headcount'),
    api.get('/reports/attendance-summary', { params: { month: month.value } }),
    api.get('/reports/payroll-summary'),
    api.get('/reports/leave-summary'),
  ])
  if (hc.status === 'fulfilled') headcount.value = hc.value.data.data
  if (att.status === 'fulfilled') attendance.value = att.value.data.data.rows
  if (pr.status === 'fulfilled') payrollSummary.value = pr.value.data.data
  if (lv.status === 'fulfilled') leaveSummary.value = lv.value.data.data
}
onMounted(loadAll)
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold mb-4">Laporan</h1>

    <div class="card mb-6" v-if="headcount">
      <div class="card-header"><span class="font-semibold">Headcount</span></div>
      <div class="card-body grid grid-cols-2 md:grid-cols-4 gap-4">
        <div><div class="text-xs text-gray-500">Aktif</div><div class="text-2xl font-bold">{{ headcount.totals.active }}</div></div>
        <div><div class="text-xs text-gray-500">Non-aktif</div><div class="text-2xl font-bold">{{ headcount.totals.inactive }}</div></div>
        <div><div class="text-xs text-gray-500">Total</div><div class="text-2xl font-bold">{{ headcount.totals.total }}</div></div>
      </div>
    </div>

    <div class="card mb-6">
      <div class="card-header gap-3">
        <span class="font-semibold">Ringkasan Absensi</span>
        <input v-model="month" type="month" class="input w-44" @change="loadAll" />
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr><th>NIP</th><th>Nama</th><th>Hadir</th><th>Telat</th><th>Absen</th><th>Cuti</th><th>Total Telat (mnt)</th></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="attendance.length === 0"><td colspan="7" class="text-center py-4 text-gray-400">Tidak ada data</td></tr>
            <tr v-for="r in attendance" :key="r.employee_id">
              <td>{{ r.employee_number }}</td><td>{{ r.full_name }}</td>
              <td>{{ r.present }}</td><td>{{ r.late }}</td><td>{{ r.absent }}</td>
              <td>{{ r.leave_days }}</td><td>{{ r.total_late_minutes }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-6">
      <div class="card-header"><span class="font-semibold">Ringkasan Payroll</span></div>
      <table class="table">
        <thead><tr><th>Periode</th><th>Status</th><th>Bruto</th><th>Potongan</th><th>Net</th><th>Slip</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="payrollSummary.length === 0"><td colspan="6" class="text-center py-4 text-gray-400">Tidak ada data</td></tr>
          <tr v-for="p in payrollSummary" :key="p.id">
            <td>{{ p.name }}</td>
            <td><span class="badge-blue">{{ p.status }}</span></td>
            <td>{{ fmtCurrency(p.total_gross) }}</td>
            <td>{{ fmtCurrency(p.total_deduction) }}</td>
            <td>{{ fmtCurrency(p.total_net) }}</td>
            <td>{{ p.slip_count }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="card">
      <div class="card-header"><span class="font-semibold">Ringkasan Cuti</span></div>
      <table class="table">
        <thead><tr><th>Tipe</th><th>Jumlah Pengajuan</th><th>Total Hari (Disetujui)</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="leaveSummary.length === 0"><td colspan="3" class="text-center py-4 text-gray-400">Tidak ada data</td></tr>
          <tr v-for="l in leaveSummary" :key="l.code">
            <td>{{ l.name }} ({{ l.code }})</td>
            <td>{{ l.total_requests }}</td>
            <td>{{ l.total_days || 0 }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>