<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtCurrency, fmtDate } from '@/composables/useResource'

const route = useRoute()
const employee = ref(null)
const balances = ref([])
const contracts = ref([])

onMounted(async () => {
  const id = route.params.id
  const [emp, bal, ctr] = await Promise.allSettled([
    api.get(`/employees/${id}`),
    api.get(`/leave-balances/${id}`),
    api.get(`/employees/${id}/contracts`),
  ])
  if (emp.status === 'fulfilled') employee.value = emp.value.data.data
  if (bal.status === 'fulfilled') balances.value = bal.value.data.data
  if (ctr.status === 'fulfilled') contracts.value = ctr.value.data.data
})
</script>

<template>
  <div v-if="employee">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold">{{ employee.full_name }}</h1>
        <div class="text-gray-500 text-sm">{{ employee.employee_number }} · {{ employee.email || '-' }}</div>
      </div>
      <RouterLink :to="`/employees/${employee.id}/edit`" class="btn-primary">Edit</RouterLink>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="card lg:col-span-2">
        <div class="card-header"><span class="font-semibold">Detail Karyawan</span></div>
        <div class="card-body grid grid-cols-2 gap-4 text-sm">
          <div><div class="text-gray-500">Status</div><div>{{ employee.employment_status }} · {{ employee.is_active ? 'Aktif' : 'Non-aktif' }}</div></div>
          <div><div class="text-gray-500">Bergabung</div><div>{{ fmtDate(employee.join_date) }}</div></div>
          <div><div class="text-gray-500">Telepon</div><div>{{ employee.phone || '-' }}</div></div>
          <div><div class="text-gray-500">PTKP</div><div>{{ employee.ptkp_status }}</div></div>
          <div><div class="text-gray-500">Gaji Pokok</div><div>{{ fmtCurrency(employee.basic_salary) }}</div></div>
          <div><div class="text-gray-500">NPWP</div><div>{{ employee.npwp || '-' }}</div></div>
          <div><div class="text-gray-500">Bank</div><div>{{ employee.bank_name || '-' }} {{ employee.bank_account ? '· ' + employee.bank_account : '' }}</div></div>
          <div><div class="text-gray-500">BPJS Kes</div><div>{{ employee.bpjs_kesehatan_no || '-' }}</div></div>
          <div class="col-span-2"><div class="text-gray-500">Alamat</div><div>{{ employee.address || '-' }}</div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><span class="font-semibold">Saldo Cuti</span></div>
        <div class="card-body">
          <div v-if="balances.length === 0" class="text-sm text-gray-400">Belum ada saldo</div>
          <ul v-else class="text-sm divide-y divide-gray-100">
            <li v-for="b in balances" :key="b.id" class="py-2 flex justify-between">
              <span>{{ b.name }}</span>
              <span class="font-medium">{{ b.remaining }}/{{ b.allocated }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div class="card mt-6">
      <div class="card-header"><span class="font-semibold">Riwayat Kontrak</span></div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead><tr><th>No</th><th>Tipe</th><th>Mulai</th><th>Berakhir</th><th>Gaji</th></tr></thead>
          <tbody>
            <tr v-if="contracts.length === 0"><td colspan="5" class="text-center py-4 text-gray-400">Belum ada kontrak</td></tr>
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
  </div>
  <div v-else class="text-center py-12 text-gray-400">Memuat...</div>
</template>