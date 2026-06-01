<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { fmtDate, fmtCurrency } from '@/composables/useResource'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { PlusIcon, CalculatorIcon, CheckBadgeIcon } from '@heroicons/vue/24/outline'
import PayrollPeriodModal from '@/components/PayrollPeriodModal.vue'

const router = useRouter()
const toast = useToast()
const items = ref([])
const showModal = ref(false)

async function fetchData() {
  const res = await api.get('/payroll-periods', { params: { per_page: 50 } })
  items.value = res.data.data
}
onMounted(fetchData)

async function calculate(id) {
  const ok = await confirmDialog({
    title: 'Hitung payroll periode ini?',
    message: 'Semua slip yang sudah ada untuk periode ini akan dihitung ulang. Tunjangan jarak juga akan diperbarui.',
    okText: 'Hitung',
    variant: 'primary',
  })
  if (! ok) return
  toast.info('Menghitung payroll...')
  try {
    await api.post(`/payroll-periods/${id}/calculate`)
    toast.success('Payroll berhasil dihitung')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function finalize(id) {
  const ok = await confirmDialog({
    title: 'Finalisasi periode payroll?',
    message: 'Setelah final, slip dianggap sudah dibayar dan tidak bisa di-rollback. Notifikasi akan terkirim ke seluruh karyawan.',
    okText: 'Finalisasi',
    variant: 'warning',
  })
  if (! ok) return
  try {
    await api.post(`/payroll-periods/${id}/finalize`)
    toast.success('Periode difinalisasi & notifikasi terkirim')
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
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
      <h1 class="text-2xl font-bold">Periode Payroll</h1>
      <button @click="openModal" class="btn-primary">
        <PlusIcon class="w-4 h-4" /> Periode Baru
      </button>
    </div>
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Kode</th>
              <th>Nama</th>
              <th>Periode</th>
              <th>Pay Date</th>
              <th>Total Net</th>
              <th>Status</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">Belum ada periode</td></tr>
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
                  <button v-if="['DRAFT','CALCULATED'].includes(p.status)" class="btn-secondary" @click="calculate(p.id)" title="Hitung">
                    <CalculatorIcon class="w-4 h-4" /> Hitung
                  </button>
                  <button v-if="p.status === 'CALCULATED'" class="btn-success" @click="finalize(p.id)">
                    <CheckBadgeIcon class="w-4 h-4" /> Finalisasi
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
