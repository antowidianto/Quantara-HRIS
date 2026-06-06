<template>
  <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">
    <!-- Page header -->
    <div class="sm:flex sm:justify-between sm:items-center mb-8">
      <div class="mb-4 sm:mb-0">
        <h1 class="text-2xl md:text-3xl text-slate-800 dark:text-slate-100 font-bold">Kasbon & Pinjaman</h1>
      </div>
      <div class="grid grid-flow-col sm:auto-cols-max justify-start sm:justify-end gap-2">
        <button class="btn bg-indigo-500 hover:bg-indigo-600 text-white" @click="showApplyModal = true">
          <svg class="w-4 h-4 fill-current opacity-50 shrink-0" viewBox="0 0 16 16">
            <path d="M15 7H9V1c0-.6-.4-1-1-1S7 .4 7 1v6H1c-.6 0-1 .4-1 1s.4 1 1 1h6v6c0 .6.4 1 1 1s1-.4 1-1V9h6c.6 0 1-.4 1-1s-.4-1-1-1z" />
          </svg>
          <span class="hidden xs:block ml-2">Ajukan Kasbon</span>
        </button>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-slate-800 shadow-lg rounded-sm border border-slate-200 dark:border-slate-700 relative">
      <header class="px-5 py-4">
        <h2 class="font-semibold text-slate-800 dark:text-slate-100">Daftar Pinjaman <span class="text-slate-400 dark:text-slate-500 font-medium">{{ total }}</span></h2>
      </header>
      <div class="p-3">
        <div class="overflow-x-auto">
          <table class="table-auto w-full dark:text-slate-300">
            <thead class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/20 border-t border-b border-slate-200 dark:border-slate-700">
              <tr>
                <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap"><div class="font-semibold text-left">No. Pinjaman</div></th>
                <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap"><div class="font-semibold text-left">Nominal</div></th>
                <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap"><div class="font-semibold text-center">Tenor (Bulan)</div></th>
                <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap"><div class="font-semibold text-right">Cicilan/Bulan</div></th>
                <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap"><div class="font-semibold text-center">Status</div></th>
                <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap"><div class="font-semibold text-center">Aksi</div></th>
              </tr>
            </thead>
            <tbody class="text-sm divide-y divide-slate-200 dark:divide-slate-700">
              <tr v-for="loan in loans" :key="loan.id">
                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                  <div class="text-left font-medium text-sky-500">{{ loan.loan_number }}</div>
                  <div class="text-xs text-slate-500">{{ formatDate(loan.created_at) }}</div>
                </td>
                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                  <div class="text-left">{{ formatRupiah(loan.principal) }}</div>
                </td>
                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                  <div class="text-center">{{ loan.installments }} bln</div>
                </td>
                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                  <div class="text-right">{{ formatRupiah(loan.installment_amount) }}</div>
                </td>
                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                  <div class="text-center">
                    <span class="inline-flex font-medium rounded-full text-center px-2.5 py-0.5" :class="statusColor(loan.status)">
                      {{ loan.status }}
                    </span>
                  </div>
                </td>
                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                  <div class="text-center">
                    <button class="text-indigo-500 hover:text-indigo-600 text-sm" @click="viewDetail(loan)">Detail</button>
                    <button v-if="loan.status === 'PENDING'" class="text-rose-500 hover:text-rose-600 text-sm ml-3" @click="cancelLoan(loan.id)">Batalkan</button>
                  </div>
                </td>
              </tr>
              <tr v-if="loans.length === 0">
                <td colspan="6" class="px-2 py-4 text-center text-slate-500">Belum ada data pinjaman.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Apply Modal -->
    <ModalBasic :isOpen="showApplyModal" @close="showApplyModal = false" title="Ajukan Kasbon Baru">
      <div class="px-5 py-4">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Nominal (Rp) <span class="text-rose-500">*</span></label>
            <input v-model="form.principal" type="number" class="form-input w-full" placeholder="Contoh: 1000000" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Tenor Cicilan (Bulan) <span class="text-rose-500">*</span></label>
            <select v-model="form.installments" class="form-select w-full">
              <option value="1">1 Bulan</option>
              <option value="2">2 Bulan</option>
              <option value="3">3 Bulan</option>
              <option value="6">6 Bulan</option>
              <option value="12">12 Bulan</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Alasan Pengajuan <span class="text-rose-500">*</span></label>
            <textarea v-model="form.reason" class="form-textarea w-full" rows="3" placeholder="Jelaskan kebutuhan secara singkat..."></textarea>
          </div>
          
          <div class="bg-amber-50 dark:bg-amber-400/10 text-amber-600 dark:text-amber-400 p-3 rounded text-sm mt-4">
            <strong>Catatan:</strong> Bunga pinjaman flat dikenakan sesuai ketentuan perusahaan. Nominal cicilan dapat dilihat setelah pengajuan atau dengan menghubungi HR.
          </div>
        </div>
      </div>
      <div class="px-5 py-4 border-t border-slate-200 dark:border-slate-700 flex flex-wrap justify-end space-x-2">
        <button class="btn-sm border-slate-200 hover:border-slate-300 text-slate-600" @click="showApplyModal = false">Batal</button>
        <button class="btn-sm bg-indigo-500 hover:bg-indigo-600 text-white" @click="submitLoan" :disabled="loading">
          {{ loading ? 'Mengajukan...' : 'Ajukan' }}
        </button>
      </div>
    </ModalBasic>

    <!-- Detail Modal -->
    <ModalBasic :isOpen="showDetailModal" @close="showDetailModal = false" title="Detail Pinjaman">
      <div class="px-5 py-4" v-if="selectedLoan">
        <div class="grid grid-cols-2 gap-4 mb-4 text-sm">
          <div>
            <span class="block text-slate-500">Nomor Pinjaman:</span>
            <span class="font-medium">{{ selectedLoan.loan_number }}</span>
          </div>
          <div>
            <span class="block text-slate-500">Status:</span>
            <span class="font-medium" :class="statusColor(selectedLoan.status)">{{ selectedLoan.status }}</span>
          </div>
          <div>
            <span class="block text-slate-500">Plafon Pinjaman:</span>
            <span class="font-medium">{{ formatRupiah(selectedLoan.principal) }}</span>
          </div>
          <div>
            <span class="block text-slate-500">Total + Bunga:</span>
            <span class="font-medium">{{ formatRupiah(selectedLoan.total_amount) }}</span>
          </div>
          <div>
            <span class="block text-slate-500">Cicilan per Bulan:</span>
            <span class="font-medium">{{ formatRupiah(selectedLoan.installment_amount) }} ({{ selectedLoan.installments }}x)</span>
          </div>
          <div>
            <span class="block text-slate-500">Sisa Pembayaran:</span>
            <span class="font-medium text-rose-500">{{ formatRupiah(selectedLoan.remaining) }}</span>
          </div>
        </div>
        
        <h3 class="font-semibold text-slate-800 dark:text-slate-100 mb-2 mt-6">Jadwal Cicilan</h3>
        <div class="overflow-x-auto border border-slate-200 dark:border-slate-700 rounded">
          <table class="table-auto w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-900/20 text-slate-500 dark:text-slate-400">
              <tr>
                <th class="px-2 py-2 text-left">Bulan Ke</th>
                <th class="px-2 py-2 text-left">Jatuh Tempo</th>
                <th class="px-2 py-2 text-right">Nominal</th>
                <th class="px-2 py-2 text-center">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
              <tr v-for="inst in installments" :key="inst.id">
                <td class="px-2 py-2">{{ inst.installment_number }}</td>
                <td class="px-2 py-2">{{ formatDate(inst.due_date) }}</td>
                <td class="px-2 py-2 text-right">{{ formatRupiah(inst.amount) }}</td>
                <td class="px-2 py-2 text-center">
                  <span v-if="inst.status === 'PAID'" class="text-emerald-500">Lunas</span>
                  <span v-else class="text-slate-500">Belum Dibayar</span>
                </td>
              </tr>
              <tr v-if="installments.length === 0">
                <td colspan="4" class="px-2 py-2 text-center text-slate-500">Jadwal belum tersedia (menunggu approval)</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </ModalBasic>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import loanApi from '@/api/loan'
import ModalBasic from '@/components/ModalBasic.vue'
import { formatRupiah, formatDate } from '@/utils/formatter'
import { useToast } from 'vue-toastification'

const toast = useToast()
const loans = ref([])
const total = ref(0)
const loading = ref(false)

const showApplyModal = ref(false)
const showDetailModal = ref(false)
const selectedLoan = ref(null)
const installments = ref([])

const form = ref({
  principal: '',
  installments: '3',
  reason: ''
})

const fetchLoans = async () => {
  try {
    const res = await loanApi.list({ per_page: 50 })
    loans.value = res.data.data
    total.value = res.data.meta?.total || loans.value.length
  } catch (e) {
    toast.error('Gagal memuat daftar pinjaman')
  }
}

const submitLoan = async () => {
  if (!form.value.principal || !form.value.reason) {
    toast.warning('Harap lengkapi semua field')
    return
  }
  
  loading.value = true
  try {
    await loanApi.apply({
      principal: form.value.principal,
      installments: form.value.installments,
      reason: form.value.reason
    })
    toast.success('Pengajuan kasbon berhasil dikirim')
    showApplyModal.value = false
    form.value = { principal: '', installments: '3', reason: '' }
    fetchLoans()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mengajukan kasbon')
  } finally {
    loading.value = false
  }
}

const viewDetail = async (loan) => {
  selectedLoan.value = loan
  installments.value = []
  showDetailModal.value = true
  
  if (loan.status === 'RUNNING' || loan.status === 'COMPLETED') {
    try {
      const res = await loanApi.getInstallments(loan.id)
      installments.value = res.data.data
    } catch (e) {
      console.error(e)
    }
  }
}

const cancelLoan = async (id) => {
  if (confirm('Apakah Anda yakin ingin membatalkan pengajuan ini?')) {
    try {
      await loanApi.cancel(id)
      toast.success('Pengajuan berhasil dibatalkan')
      fetchLoans()
    } catch (e) {
      toast.error('Gagal membatalkan pengajuan')
    }
  }
}

const statusColor = (status) => {
  switch (status) {
    case 'PENDING': return 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400'
    case 'APPROVED': return 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400'
    case 'RUNNING': return 'bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-400'
    case 'COMPLETED': return 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400'
    case 'REJECTED': 
    case 'CANCELLED': return 'bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-400'
    default: return 'bg-slate-100 text-slate-600 dark:bg-slate-500/20 dark:text-slate-400'
  }
}

onMounted(() => {
  fetchLoans()
})
</script>
