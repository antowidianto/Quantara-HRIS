<template>
  <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">
    <!-- Page header -->
    <div class="sm:flex sm:justify-between sm:items-center mb-8">
      <div class="mb-4 sm:mb-0">
        <h1 class="text-2xl md:text-3xl text-slate-800 dark:text-slate-100 font-bold">Approval Kasbon</h1>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-slate-800 shadow-lg rounded-sm border border-slate-200 dark:border-slate-700 relative">
      <header class="px-5 py-4 flex justify-between items-center">
        <h2 class="font-semibold text-slate-800 dark:text-slate-100">Daftar Pengajuan Kasbon</h2>
      </header>
      <div class="p-3">
        <div class="overflow-x-auto">
          <table class="table-auto w-full dark:text-slate-300">
            <thead class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-900/20 border-t border-b border-slate-200 dark:border-slate-700">
              <tr>
                <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap"><div class="font-semibold text-left">No. Pinjaman</div></th>
                <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap"><div class="font-semibold text-left">Karyawan</div></th>
                <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap"><div class="font-semibold text-left">Nominal</div></th>
                <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap"><div class="font-semibold text-center">Tenor</div></th>
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
                  <div class="text-left font-medium">{{ loan.employee_name }}</div>
                  <div class="text-xs text-slate-500">{{ loan.employee_number }}</div>
                </td>
                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                  <div class="text-left">{{ formatRupiah(loan.principal) }}</div>
                </td>
                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                  <div class="text-center">{{ loan.installments }} bln</div>
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
                    <button class="text-indigo-500 hover:text-indigo-600 text-sm" @click="viewDetail(loan)">Detail / Proses</button>
                  </div>
                </td>
              </tr>
              <tr v-if="loans.length === 0">
                <td colspan="6" class="px-2 py-4 text-center text-slate-500">Belum ada data pengajuan kasbon.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Approve Modal Custom -->
    <div v-if="showApproveModal" class="fixed inset-0 z-50 flex items-center justify-center overflow-x-hidden overflow-y-auto outline-none focus:outline-none">
      <div class="relative w-auto max-w-lg mx-auto my-6">
        <div class="relative flex flex-col w-full bg-white border-0 rounded-lg shadow-lg outline-none focus:outline-none dark:bg-slate-800">
          <div class="flex items-start justify-between p-5 border-b border-solid rounded-t border-slate-200 dark:border-slate-700">
            <h3 class="text-xl font-semibold dark:text-slate-100">Proses Pengajuan Kasbon</h3>
            <button class="p-1 ml-auto bg-transparent border-0 text-black dark:text-white float-right text-3xl leading-none font-semibold outline-none focus:outline-none" @click="showApproveModal = false">
              <span class="text-black dark:text-white h-6 w-6 text-2xl block outline-none focus:outline-none">×</span>
            </button>
          </div>
          <div class="relative p-6 flex-auto" v-if="selectedLoan">
            <div class="bg-slate-50 dark:bg-slate-700 p-3 rounded border border-slate-200 dark:border-slate-600 mb-4 text-sm dark:text-slate-200">
              <div class="mb-2"><strong>Pemohon:</strong> {{ selectedLoan.employee_name }}</div>
              <div class="mb-2"><strong>Nominal:</strong> {{ formatRupiah(selectedLoan.principal) }}</div>
              <div class="mb-2"><strong>Alasan:</strong> {{ selectedLoan.reason }}</div>
            </div>

            <div v-if="selectedLoan.status === 'PENDING'">
              <label class="block text-sm font-medium mb-1 dark:text-slate-300">Mulai Potong Gaji Bulan (Jatuh Tempo Pertama) <span class="text-rose-500">*</span></label>
              <input v-model="firstInstallmentDate" type="date" class="form-input w-full dark:bg-slate-900 dark:border-slate-600" />
              <p class="text-xs text-slate-500 mt-1">Sistem akan menggenerate jadwal cicilan setiap bulan mulai tanggal ini.</p>
            </div>
          </div>
          
          <div class="flex items-center justify-between p-6 border-t border-solid rounded-b border-slate-200 dark:border-slate-700" v-if="selectedLoan?.status === 'PENDING'">
            <button class="btn-sm border-slate-200 hover:border-slate-300 text-slate-600 dark:text-slate-300 dark:border-slate-600 dark:hover:border-slate-500" @click="showApproveModal = false">Batal</button>
            <div class="space-x-2">
              <button class="btn-sm bg-rose-500 hover:bg-rose-600 text-white" @click="rejectLoan" :disabled="loading">
                {{ loading ? 'Memproses...' : 'Tolak' }}
              </button>
              <button class="btn-sm bg-emerald-500 hover:bg-emerald-600 text-white" @click="approveLoan" :disabled="loading">
                {{ loading ? 'Memproses...' : 'Setujui' }}
              </button>
            </div>
          </div>
          <div class="flex items-center justify-end p-6 border-t border-solid rounded-b border-slate-200 dark:border-slate-700" v-else>
            <button class="btn-sm border-slate-200 hover:border-slate-300 text-slate-600 dark:text-slate-300 dark:border-slate-600 dark:hover:border-slate-500" @click="showApproveModal = false">Tutup</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="showApproveModal" class="fixed inset-0 z-40 bg-black opacity-25"></div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import loanApi from '@/api/loan'
import { fmtCurrency as formatRupiah, fmtDate as formatDate } from '@/composables/useResource'
import { useToast } from 'vue-toastification'

const toast = useToast()
const loans = ref([])
const loading = ref(false)

const showApproveModal = ref(false)
const selectedLoan = ref(null)
const firstInstallmentDate = ref('')

const fetchLoans = async () => {
  try {
    const res = await loanApi.list({ per_page: 50 })
    loans.value = res.data.data
  } catch (e) {
    toast.error('Gagal memuat daftar kasbon')
  }
}

const viewDetail = (loan) => {
  selectedLoan.value = loan
  // Default first installment to 1st of next month
  const nextMonth = new Date()
  nextMonth.setMonth(nextMonth.getMonth() + 1)
  nextMonth.setDate(1)
  firstInstallmentDate.value = nextMonth.toISOString().split('T')[0]
  
  showApproveModal.value = true
}

const approveLoan = async () => {
  if (!firstInstallmentDate.value) {
    toast.warning('Pilih tanggal jatuh tempo pertama')
    return
  }
  
  loading.value = true
  try {
    await loanApi.approve(selectedLoan.value.id, firstInstallmentDate.value)
    toast.success('Pinjaman berhasil disetujui')
    showApproveModal.value = false
    fetchLoans()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyetujui pinjaman')
  } finally {
    loading.value = false
  }
}

const rejectLoan = async () => {
  if (!confirm('Tolak pengajuan kasbon ini?')) return
  
  loading.value = true
  try {
    await loanApi.reject(selectedLoan.value.id)
    toast.success('Pinjaman ditolak')
    showApproveModal.value = false
    fetchLoans()
  } catch (e) {
    toast.error('Gagal menolak pinjaman')
  } finally {
    loading.value = false
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
