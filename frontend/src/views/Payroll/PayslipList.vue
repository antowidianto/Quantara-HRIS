<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtCurrency } from '@/composables/useResource'

const items = ref([])
async function fetchData() {
  const res = await api.get('/payslips', { params: { per_page: 100 } })
  items.value = res.data.data
}
onMounted(fetchData)
</script>
<template>
  <div>
    <h1 class="text-2xl font-bold mb-4">Slip Gaji (semua karyawan)</h1>
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr><th>No Slip</th><th>NIP</th><th>Nama</th><th>Periode</th><th>Bruto</th><th>THP</th><th></th></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">Belum ada slip</td></tr>
            <tr v-for="s in items" :key="s.id">
              <td class="font-mono text-xs">{{ s.slip_number }}</td>
              <td>{{ s.employee_number }}</td>
              <td>{{ s.employee_name }}</td>
              <td>{{ s.period_name }}</td>
              <td>{{ fmtCurrency(s.gross_earning) }}</td>
              <td class="font-semibold">{{ fmtCurrency(s.take_home_pay) }}</td>
              <td><RouterLink :to="`/payslips/${s.id}`" class="text-primary-600 hover:underline text-sm">Detail</RouterLink></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>