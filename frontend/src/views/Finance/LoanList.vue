<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { fmtCurrency } from '@/composables/useResource'
import { useToast } from 'vue-toastification'

const toast = useToast()
const items = ref([])
const showForm = ref(false)
const form = ref({ principal: 0, installments: 6, reason: '' })

async function fetchData() {
  const res = await api.get('/loans', { params: { per_page: 50 } })
  items.value = res.data.data
}
onMounted(fetchData)

async function submit() {
  try {
    await api.post('/loans', form.value)
    toast.success('Pengajuan kasbon dikirim')
    showForm.value = false
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}
</script>
<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Kasbon / Pinjaman</h1>
      <button class="btn-primary" @click="showForm = true">+ Ajukan Kasbon</button>
    </div>
    <div class="card">
      <table class="table">
        <thead><tr><th>No</th><th>Karyawan</th><th>Pokok</th><th>Cicilan</th><th>Sisa</th><th>Status</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="items.length === 0"><td colspan="6" class="text-center py-8 text-gray-400">Belum ada data</td></tr>
          <tr v-for="l in items" :key="l.id">
            <td class="font-mono text-xs">{{ l.loan_number }}</td>
            <td>{{ l.employee_name }}</td>
            <td>{{ fmtCurrency(l.principal) }}</td>
            <td>{{ fmtCurrency(l.installment_amount) }} × {{ l.paid_installments }}/{{ l.installments }}</td>
            <td>{{ fmtCurrency(l.remaining) }}</td>
            <td><span :class="{
              'badge-yellow': l.status === 'PENDING',
              'badge-blue': l.status === 'RUNNING',
              'badge-green': l.status === 'COMPLETED',
              'badge-red': l.status === 'REJECTED' || l.status === 'CANCELLED',
            }">{{ l.status }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40" @click.self="showForm = false">
      <form @submit.prevent="submit" class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 class="font-semibold">Ajukan Kasbon</h2>
        <div><label class="label">Jumlah (Rp)</label><input v-model.number="form.principal" type="number" min="1" class="input" required /></div>
        <div><label class="label">Jumlah Cicilan</label><input v-model.number="form.installments" type="number" min="1" max="24" class="input" required /></div>
        <div><label class="label">Alasan</label><textarea v-model="form.reason" rows="3" class="input" required></textarea></div>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showForm = false">Batal</button>
          <button type="submit" class="btn-primary">Kirim</button>
        </div>
      </form>
    </div>
  </div>
</template>