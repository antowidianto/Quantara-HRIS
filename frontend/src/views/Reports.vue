<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { fmtCurrency } from '@/composables/useResource'
import { useI18n } from '@/locales'

const { t } = useI18n()
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
    <h1 class="text-2xl font-bold mb-4">{{ t('reports.title') }}</h1>

    <div class="card mb-6" v-if="headcount">
      <div class="card-header"><span class="font-semibold">{{ t('reports.headcount') }}</span></div>
      <div class="card-body grid grid-cols-2 md:grid-cols-4 gap-4">
        <div><div class="text-xs text-gray-500">{{ t('reports.active') }}</div><div class="text-2xl font-bold">{{ headcount.totals.active }}</div></div>
        <div><div class="text-xs text-gray-500">{{ t('reports.inactive') }}</div><div class="text-2xl font-bold">{{ headcount.totals.inactive }}</div></div>
        <div><div class="text-xs text-gray-500">{{ t('reports.total') }}</div><div class="text-2xl font-bold">{{ headcount.totals.total }}</div></div>
      </div>
    </div>

    <div class="card mb-6">
      <div class="card-header gap-3">
        <span class="font-semibold">{{ t('reports.attendance_summary') }}</span>
        <input v-model="month" type="month" class="input w-44" @change="loadAll" />
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('reports.nip') }}</th>
              <th>{{ t('reports.name') }}</th>
              <th>{{ t('reports.present') }}</th>
              <th>{{ t('reports.late') }}</th>
              <th>{{ t('reports.absent') }}</th>
              <th>{{ t('reports.leave') }}</th>
              <th>{{ t('reports.total_late_min') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="attendance.length === 0">
              <td colspan="7" class="text-center py-4 text-gray-400">{{ t('common.no_data') }}</td>
            </tr>
            <tr v-for="r in attendance" :key="r.employee_id">
              <td>{{ r.employee_number }}</td>
              <td>{{ r.full_name }}</td>
              <td>{{ r.present }}</td>
              <td>{{ r.late }}</td>
              <td>{{ r.absent }}</td>
              <td>{{ r.leave_days }}</td>
              <td>{{ r.total_late_minutes }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-6">
      <div class="card-header"><span class="font-semibold">{{ t('reports.payroll_summary') }}</span></div>
      <table class="table">
        <thead>
          <tr>
            <th>{{ t('reports.period') }}</th>
            <th>{{ t('reports.status') }}</th>
            <th>{{ t('reports.gross') }}</th>
            <th>{{ t('reports.deduction') }}</th>
            <th>{{ t('reports.net') }}</th>
            <th>{{ t('reports.slips') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="payrollSummary.length === 0">
            <td colspan="6" class="text-center py-4 text-gray-400">{{ t('common.no_data') }}</td>
          </tr>
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
      <div class="card-header"><span class="font-semibold">{{ t('reports.leave_summary') }}</span></div>
      <table class="table">
        <thead>
          <tr>
            <th>{{ t('reports.type') }}</th>
            <th>{{ t('reports.request_count') }}</th>
            <th>{{ t('reports.total_days_approved') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="leaveSummary.length === 0">
            <td colspan="3" class="text-center py-4 text-gray-400">{{ t('common.no_data') }}</td>
          </tr>
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
