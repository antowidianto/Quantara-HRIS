<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtCurrency } from '@/composables/useResource'
import { useI18n } from '@/locales'

const { t } = useI18n()
const items = ref([])
onMounted(async () => {
  const res = await api.get('/payslips/my')
  items.value = res.data.data
})
</script>
<template>
  <div>
    <h1 class="text-2xl font-bold mb-4">{{ t('payroll.my.title') }}</h1>
    <div class="card">
      <table class="table">
        <thead><tr><th>{{ t('payroll.my.col_period') }}</th><th>{{ t('payroll.my.col_slip_no') }}</th><th>{{ t('payroll.my.col_gross') }}</th><th>{{ t('payroll.my.col_thp') }}</th><th></th></tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="items.length === 0"><td colspan="5" class="text-center py-8 text-gray-400">{{ t('payroll.my.no_payslips') }}</td></tr>
          <tr v-for="s in items" :key="s.id">
            <td>{{ s.period_name }}</td>
            <td class="font-mono text-xs">{{ s.slip_number }}</td>
            <td>{{ fmtCurrency(s.gross_earning) }}</td>
            <td class="font-semibold text-primary-600">{{ fmtCurrency(s.take_home_pay) }}</td>
            <td><RouterLink :to="`/payslips/${s.id}`" class="text-primary-600 hover:underline text-sm">{{ t('payroll.my.view') }}</RouterLink></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>