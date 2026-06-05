<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { fmtDate, fmtCurrency } from '@/composables/useResource'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { PlusIcon, CalculatorIcon, CheckBadgeIcon } from '@heroicons/vue/24/outline'
import PayrollPeriodModal from '@/components/PayrollPeriodModal.vue'
import { useI18n } from '@/locales'

const router = useRouter()
const toast = useToast()
const { t } = useI18n()
const items = ref([])
const showModal = ref(false)

async function fetchData() {
  const res = await api.get('/payroll-periods', { params: { per_page: 50 } })
  items.value = res.data.data
}
onMounted(fetchData)

async function calculate(id) {
  const ok = await confirmDialog({
    title: t('payroll.period_list.calc_title'),
    message: t('payroll.period_list.calc_message'),
    okText: t('payroll.period_list.calc_ok'),
    variant: 'primary',
  })
  if (! ok) return
  toast.info(t('payroll.period_list.calc_progress'))
  try {
    await api.post(`/payroll-periods/${id}/calculate`)
    toast.success(t('payroll.period_list.calc_success'))
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || t('payroll.period_list.failed')) }
}

async function finalize(id) {
  const ok = await confirmDialog({
    title: t('payroll.period_list.finalize_title'),
    message: t('payroll.period_list.finalize_message'),
    okText: t('payroll.period_list.finalize_ok'),
    variant: 'warning',
  })
  if (! ok) return
  try {
    await api.post(`/payroll-periods/${id}/finalize`)
    toast.success(t('payroll.period_list.finalize_success'))
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || t('payroll.period_list.failed')) }
}

function openModal() {
  showModal.value = true
}

function closeModal() {
  showModal.value = false
}

function handlePeriodCreated(period) {
  fetchData()
  // Navigate ke detail periode yang baru dibuat
  router.push(`/payroll/periods/${period.id}`)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('payroll.period_list.title') }}</h1>
      <button @click="openModal" class="btn-primary">
        <PlusIcon class="w-4 h-4" /> {{ t('payroll.period_list.new_period') }}
      </button>
    </div>
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('payroll.period_list.col_code') }}</th>
              <th>{{ t('payroll.period_list.col_name') }}</th>
              <th>{{ t('payroll.period_list.col_period') }}</th>
              <th>{{ t('payroll.period_list.col_pay_date') }}</th>
              <th>{{ t('payroll.period_list.col_total_net') }}</th>
              <th>{{ t('payroll.period_list.col_status') }}</th>
              <th class="text-right">{{ t('payroll.period_list.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">{{ t('payroll.period_list.no_periods') }}</td></tr>
            <tr v-for="p in items" :key="p.id">
              <td class="font-mono text-xs">{{ p.code }}</td>
              <td><RouterLink :to="`/payroll/periods/${p.id}`" class="text-primary-600 hover:underline">{{ p.name }}</RouterLink></td>
              <td>{{ fmtDate(p.start_date) }} → {{ fmtDate(p.end_date) }}</td>
              <td>{{ fmtDate(p.pay_date) }}</td>
              <td>{{ fmtCurrency(p.total_net) }}</td>
              <td>
                <span :class="{
                  'badge-gray': p.status === 'DRAFT',
                  'badge-blue': p.status === 'CALCULATED',
                  'badge-green': p.status === 'PAID' || p.status === 'CLOSED',
                  'badge-yellow': p.status === 'APPROVED',
                }">{{ p.status }}</span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button v-if="['DRAFT','CALCULATED'].includes(p.status)" class="btn-secondary" @click="calculate(p.id)" :title="t('payroll.period_list.calculate')">
                    <CalculatorIcon class="w-4 h-4" /> {{ t('payroll.period_list.calculate') }}
                  </button>
                  <button v-if="p.status === 'CALCULATED'" class="btn-success" @click="finalize(p.id)">
                    <CheckBadgeIcon class="w-4 h-4" /> {{ t('payroll.period_list.finalize') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal -->
    <PayrollPeriodModal 
      :show="showModal" 
      @close="closeModal"
      @created="handlePeriodCreated"
    />
  </div>
</template>
