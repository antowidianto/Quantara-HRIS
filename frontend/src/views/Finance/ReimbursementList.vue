<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { useToast } from 'vue-toastification'

const toast = useToast()
const items = ref([])
const balances = ref([])
const showForm = ref(false)
const form = ref({ category: 'TRANSPORT', expense_date: '', amount: 0, description: '' })

async function fetchData() {
  const res = await api.get('/reimbursements', { params: { per_page: 50 } })
  items.value = res.data.data

  // Fetch benefit balances
  try {
    const bRes = await api.get('/my-benefit-balances')
    balances.value = bRes.data.data || []
    if (balances.value.length > 0 && form.value.category === 'TRANSPORT') {
        form.value.category = balances.value[0].name
    }
  } catch(e) {}
}
onMounted(fetchData)

async function submit() {
  try {
    await api.post('/reimbursements', form.value)
    toast.success('Pengajuan reimbursement dikirim')
    showForm.value = false
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}
</script>
<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Reimbursement</h1>
      <button class="btn-primary" @click="showForm = true">+ Ajukan</button>
    </div>

    <!-- Sisa Plafon Widget -->
    <div v-if="balances.length > 0" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
      <div v-for="b in balances" :key="b.id" class="card bg-gradient-to-br from-indigo-50 to-white border border-indigo-100 p-4">
        <h3 class="font-medium text-gray-500 text-sm mb-1">Sisa Plafon: {{ b.name }}</h3>
        <p class="text-2xl font-bold text-indigo-700">{{ fmtCurrency(b.available_amount) }}</p>
        <p class="text-xs text-gray-400 mt-2">Terpakai: {{ fmtCurrency(b.used_amount) }} dari {{ fmtCurrency(b.limit_amount) }} ({{ b.reset_type }})</p>
      </div>
    </div>

    <div class="card">
      <table class="table">
        <thead><tr><th>No</th><th>Tanggal</th><th>Kategori</th><th>Jumlah</th><th>Deskripsi</th><th>Status</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="items.length === 0"><td colspan="6" class="text-center py-8 text-gray-400">Belum ada data</td></tr>
          <tr v-for="r in items" :key="r.id">
            <td class="font-mono text-xs">{{ r.request_number }}</td>
            <td>{{ fmtDate(r.expense_date) }}</td>
            <td>{{ r.category }}</td>
            <td>{{ fmtCurrency(r.amount) }}</td>
            <td>{{ r.description }}</td>
            <td><span :class="{
              'badge-yellow': r.status === 'PENDING',
              'badge-green': r.status === 'APPROVED' || r.status === 'PAID',
              'badge-red': r.status === 'REJECTED',
            }">{{ r.status }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40" @click.self="showForm = false">
      <form @submit.prevent="submit" class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 class="font-semibold">Ajukan Reimbursement</h2>
        <div><label class="label">Kategori</label>
          <select v-model="form.category" class="input">
            <template v-if="balances.length > 0">
              <option v-for="b in balances" :key="b.id" :value="b.name">{{ b.name }}</option>
            </template>
            <template v-else>
              <option>TRANSPORT</option><option>MEDICAL</option><option>MEAL</option><option>OFFICE</option><option>OTHER</option>
            </template>
          </select>
        </div>
        <div><label class="label">Tanggal Pengeluaran</label><input v-model="form.expense_date" type="date" class="input" required /></div>
        <div><label class="label">Jumlah (Rp)</label><input v-model.number="form.amount" type="number" min="1" class="input" required /></div>
        <div><label class="label">Deskripsi</label><textarea v-model="form.description" rows="3" class="input" required></textarea></div>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showForm = false">Batal</button>
          <button type="submit" class="btn-primary">Kirim</button>
        </div>
      </form>
    </div>
  </div>
</template>