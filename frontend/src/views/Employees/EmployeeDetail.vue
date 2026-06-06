<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { EyeIcon } from '@heroicons/vue/24/outline'
import { useI18n } from '@/locales'

const { t } = useI18n()
const route = useRoute()
const employee = ref(null)
const balances = ref([])
const balanceYear = ref(new Date().getFullYear())
const contracts = ref([])
const generatedContracts = ref([])
const leaves = ref([])
const leavesLoading = ref(false)

const STATUS_BADGE = {
  PENDING: 'badge-yellow',
  APPROVED: 'badge-green',
  REJECTED: 'badge-red',
  CANCELLED: 'badge-gray',
}

async function fetchAll(id) {
  const [emp, bal, ctr, gen, lv] = await Promise.allSettled([
    api.get(`/employees/${id}`),
    api.get(`/leave-balances/${id}`),
    api.get(`/employees/${id}/contracts`),
    api.get('/generated-contracts', { params: { employee_id: id } }),
    api.get('/leaves', { params: { employee_id: id, per_page: 50 } }),
  ])
  if (emp.status === 'fulfilled') employee.value = emp.value.data.data
  if (bal.status === 'fulfilled') {
    const payload = bal.value.data?.data || {}
    balances.value = Array.isArray(payload.balances) ? payload.balances : []
    if (payload.year) balanceYear.value = payload.year
  }
  if (ctr.status === 'fulfilled') contracts.value = ctr.value.data.data || []
  if (gen.status === 'fulfilled') generatedContracts.value = gen.value.data.data || []
  if (lv.status === 'fulfilled') leaves.value = lv.value.data.data || []
  leavesLoading.value = false
}

onMounted(async () => {
  leavesLoading.value = true
  await fetchAll(route.params.id)
})

function openGeneratedContract(c) {
  api.get(`/generated-contracts/${c.id}/html`, { responseType: 'blob' }).then(res => {
    const url = URL.createObjectURL(res.data)
    window.open(url, '_blank')
  }).catch(e => {
    console.error(t('employees.detail.open_failed') + ':', e)
  })
}

const totalAllocated = computed(() =>
  balances.value.reduce((sum, b) => sum + Number(b.allocated || 0), 0)
)
const totalRemaining = computed(() =>
  balances.value.reduce((sum, b) => sum + Number(b.remaining || 0), 0)
)
</script>

<template>
  <div v-if="employee">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">{{ employee.full_name }}</h1>
        <div class="text-gray-500 text-sm">{{ employee.employee_number }} · {{ employee.email || '-' }}</div>
      </div>
      <RouterLink :to="`/employees/${employee.id}/edit`" class="btn-primary">{{ t('employees.detail.edit_btn') }}</RouterLink>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="card lg:col-span-2">
        <div class="card-header"><span class="font-semibold">{{ t('employees.detail.detail_section') }}</span></div>
        <div class="card-body grid grid-cols-2 gap-4 text-sm">
          <div><div class="text-gray-500">{{ t('employees.detail.status') }}</div><div>{{ employee.employment_status }} · {{ employee.is_active ? t('employees.detail.active') : t('employees.detail.inactive') }}</div></div>
          <div><div class="text-gray-500">{{ t('employees.detail.joined') }}</div><div>{{ fmtDate(employee.join_date) }}</div></div>
          <div><div class="text-gray-500">{{ t('employees.detail.phone') }}</div><div>{{ employee.phone || '-' }}</div></div>
          <div><div class="text-gray-500">{{ t('employees.detail.gender') }}</div><div>{{ employee.gender === 'F' ? t('employees.detail.female') : (employee.gender === 'M' ? t('employees.detail.male') : '-') }}</div></div>
          <div><div class="text-gray-500">{{ t('employees.detail.ptkp') }}</div><div>{{ employee.ptkp_status }}</div></div>
          <div><div class="text-gray-500">{{ t('employees.detail.basic_salary') }}</div><div>{{ fmtCurrency(employee.basic_salary) }}</div></div>
          <div><div class="text-gray-500">{{ t('employees.detail.npwp') }}</div><div>{{ employee.npwp || '-' }}</div></div>
          <div><div class="text-gray-500">{{ t('employees.detail.bank') }}</div><div>{{ employee.bank_name || '-' }} {{ employee.bank_account ? '· ' + employee.bank_account : '' }}</div></div>
          <div><div class="text-gray-500">{{ t('employees.detail.bpjs_kes') }}</div><div>{{ employee.bpjs_kesehatan_no || '-' }}</div></div>
          <div class="col-span-2"><div class="text-gray-500">{{ t('employees.detail.address') }}</div><div>{{ employee.address || '-' }}</div></div>
          <div v-if="employee.contract_end_date"><div class="text-gray-500">{{ t('employees.detail.contract_end') }}</div><div>{{ fmtDate(employee.contract_end_date) }}</div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header flex items-center justify-between">
          <span class="font-semibold">{{ t('employees.detail.leave_balance', { year: balanceYear }) }}</span>
          <span v-if="balances.length" class="text-xs text-gray-500">
            {{ t('employees.detail.leave_remaining', { remaining: totalRemaining, allocated: totalAllocated }) }}
          </span>
        </div>
        <div class="card-body">
          <div v-if="balances.length === 0" class="text-sm text-gray-400">
            {{ t('employees.detail.no_balance_msg') }}
          </div>
          <ul v-else class="text-sm divide-y divide-gray-100">
            <li v-for="b in balances" :key="b.leave_type_id" class="py-2">
              <div class="flex justify-between items-baseline">
                <span class="font-medium">{{ b.name }}</span>
                <span class="font-semibold tabular-nums">
                  {{ b.remaining }}<span class="text-gray-400">/{{ b.allocated }}</span>
                </span>
              </div>
              <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-500">
                <span class="font-mono">{{ b.code }}</span>
                <span>·</span>
                <span>{{ t('employees.detail.used', { n: b.used }) }}</span>
                <span v-if="b.is_prorated && b.prorate_factor < 1">
                  · {{ t('employees.detail.prorate', { months: b.months_worked }) }}
                </span>
                <span v-if="b.carried_over > 0">
                  · {{ t('employees.detail.carryover', { n: b.carried_over }) }}
                </span>
                <span v-if="b.is_expired" class="text-red-600 font-medium">
                  · {{ t('employees.detail.expired') }}
                </span>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div class="card mt-6">
      <div class="card-header flex items-center justify-between">
        <span class="font-semibold">{{ t('employees.detail.leave_history') }}</span>
        <span class="text-xs text-gray-500">{{ t('employees.detail.entries', { n: leaves.length }) }}</span>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('employees.detail.col_request_no') }}</th>
              <th>{{ t('employees.detail.col_type') }}</th>
              <th>{{ t('employees.detail.col_start') }}</th>
              <th>{{ t('employees.detail.col_end') }}</th>
              <th class="text-right">{{ t('employees.detail.col_days') }}</th>
              <th>{{ t('employees.detail.col_status') }}</th>
              <th>{{ t('employees.detail.col_reason') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="leavesLoading">
              <td colspan="7" class="text-center py-4 text-gray-400">{{ t('employees.detail.loading') }}</td>
            </tr>
            <tr v-else-if="leaves.length === 0">
              <td colspan="7" class="text-center py-4 text-gray-400">{{ t('employees.detail.no_leaves') }}</td>
            </tr>
            <tr v-for="l in leaves" :key="l.id">
              <td class="font-mono text-xs">{{ l.request_number }}</td>
              <td class="text-sm">{{ l.leave_name || l.leave_code || '-' }}</td>
              <td class="text-sm">{{ fmtDate(l.start_date) }}</td>
              <td class="text-sm">{{ fmtDate(l.end_date) }}</td>
              <td class="text-right tabular-nums">{{ l.days }}</td>
              <td>
                <span :class="STATUS_BADGE[l.status] || 'badge-gray'">{{ l.status }}</span>
              </td>
              <td class="text-sm text-gray-600 max-w-md truncate" :title="l.reason">
                {{ l.reason || '-' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-6">
      <div class="card-header"><span class="font-semibold">{{ t('employees.detail.contract_history') }}</span></div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr>
            <th>{{ t('employees.detail.col_no') }}</th>
            <th>{{ t('employees.detail.col_contract_type') }}</th>
            <th>{{ t('employees.detail.col_start') }}</th>
            <th>{{ t('employees.detail.col_end_date') }}</th>
            <th>{{ t('employees.detail.col_salary') }}</th>
          </tr></thead>
          <tbody>
            <tr v-if="contracts.length === 0"><td colspan="5" class="text-center py-4 text-gray-400">{{ t('employees.detail.no_contracts') }}</td></tr>
            <tr v-for="c in contracts" :key="c.id">
              <td>{{ c.contract_number }}</td>
              <td><span class="badge-blue">{{ c.contract_type }}</span></td>
              <td>{{ fmtDate(c.start_date) }}</td>
              <td>{{ fmtDate(c.end_date) }}</td>
              <td>{{ fmtCurrency(c.basic_salary) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-6">
      <div class="card-header"><span class="font-semibold">{{ t('employees.detail.generated_docs') }}</span></div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr>
            <th>{{ t('employees.detail.col_contract_no') }}</th>
            <th>{{ t('employees.detail.col_subject') }}</th>
            <th>{{ t('employees.detail.col_status') }}</th>
            <th>{{ t('employees.detail.col_created') }}</th>
            <th></th>
          </tr></thead>
          <tbody>
            <tr v-if="generatedContracts.length === 0"><td colspan="5" class="text-center py-4 text-gray-400">{{ t('employees.detail.no_generated') }}</td></tr>
            <tr v-for="g in generatedContracts" :key="g.id">
              <td class="font-mono text-sm">{{ g.contract_number }}</td>
              <td class="text-sm">{{ g.subject || '—' }}</td>
              <td><span class="badge-blue">{{ g.status }}</span></td>
              <td class="text-sm">{{ fmtDate(g.created_at) }}</td>
              <td class="text-right">
                <button class="btn-secondary text-xs" @click="openGeneratedContract(g)">
                  <EyeIcon class="w-3.5 h-3.5" /> {{ t('employees.detail.open_btn') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div v-else class="text-center py-12 text-gray-400">{{ t('employees.detail.title_loading') }}</div>
</template>