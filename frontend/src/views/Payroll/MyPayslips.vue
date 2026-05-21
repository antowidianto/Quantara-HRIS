<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtCurrency } from '@/composables/useResource'

const items = ref([])
onMounted(async () => {
  const res = await api.get('/payslips/my')
  items.value = res.data.data
})
</script>
<template>
  <div>
    <h1 class="text-2xl font-bold mb-4">Slip Gaji Saya</h1>
    <div class="card">
      <table class="table">
        <thead><tr><th>Periode</th><th>No Slip</th><th>Bruto</th><th>Take Home Pay</th><th></th></tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="items.length === 0"><td colspan="5" class="text-center py-8 text-gray-400">Belum ada slip gaji</td></tr>
          <tr v-for="s in items" :key="s.id">
            <td>{{ s.period_name }}</td>
            <td class="font-mono text-xs">{{ s.slip_number }}</td>
            <td>{{ fmtCurrency(s.gross_earning) }}</td>
            <td class="font-semibold text-primary-600">{{ fmtCurrency(s.take_home_pay) }}</td>
            <td><RouterLink :to="`/payslips/${s.id}`" class="text-primary-600 hover:underline text-sm">Lihat</RouterLink></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>