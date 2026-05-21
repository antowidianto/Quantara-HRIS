<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtDate } from '@/composables/useResource'
import { confirm as confirmDialog, promptDialog } from '@/composables/useConfirm'
import { PlusIcon, CheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const route = useRoute()
const toast = useToast()
const items = ref([])
const meta = ref({ total: 0, page: 1, pages: 1 })
const filterStatus = ref(route.query.status || '')
const showMine = ref(! auth.can('leave.approve'))

async function fetchData() {
  const res = await api.get('/leaves', {
    params: { status: filterStatus.value || undefined, mine: showMine.value ? 1 : undefined, page: meta.value.page },
  })
  items.value = res.data.data
  meta.value = res.data.meta
}

onMounted(fetchData)

async function approve(id) {
  const ok = await confirmDialog({
    title: 'Setujui pengajuan cuti?',
    message: 'Saldo cuti karyawan akan dikurangi & absensi pada periode tersebut diset menjadi LEAVE.',
    okText: 'Setujui',
    variant: 'success',
  })
  if (! ok) return
  try {
    await api.post(`/leaves/${id}/approve`)
    toast.success('Cuti disetujui')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyetujui')
  }
}

async function reject(id) {
  const reason = await promptDialog({
    title: 'Tolak pengajuan cuti',
    message: 'Berikan alasan penolakan agar karyawan tahu kenapa pengajuannya ditolak.',
    inputLabel: 'Alasan penolakan',
    inputType: 'textarea',
    inputRequired: true,
    okText: 'Tolak',
    variant: 'danger',
  })
  if (reason === null) return
  try {
    await api.post(`/leaves/${id}/reject`, { reason })
    toast.success('Cuti ditolak')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menolak')
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ showMine ? 'Cuti Saya' : 'Manajemen Cuti' }}</h1>
      <RouterLink to="/leaves/new" class="btn-primary"><PlusIcon class="w-4 h-4" /> Ajukan Cuti</RouterLink>
    </div>

    <div class="card">
      <div class="card-header gap-3">
        <div class="flex gap-2 items-center">
          <select v-model="filterStatus" @change="fetchData" class="input w-40">
            <option value="">Semua Status</option>
            <option value="PENDING">Pending</option>
            <option value="APPROVED">Disetujui</option>
            <option value="REJECTED">Ditolak</option>
            <option value="CANCELLED">Dibatalkan</option>
          </select>
          <label v-if="auth.can('leave.approve')" class="flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="showMine" @change="fetchData" /> Hanya milik saya
          </label>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>No. Pengajuan</th>
              <th v-if="!showMine">Karyawan</th>
              <th>Tipe</th>
              <th>Periode</th>
              <th>Hari</th>
              <th>Status</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">Belum ada pengajuan</td></tr>
            <tr v-for="l in items" :key="l.id">
              <td class="font-mono text-xs">{{ l.request_number }}</td>
              <td v-if="!showMine">{{ l.employee_name }}</td>
              <td>{{ l.leave_name }}</td>
              <td>{{ fmtDate(l.start_date) }} - {{ fmtDate(l.end_date) }}</td>
              <td>{{ l.days }}</td>
              <td>
                <span :class="{
                  'badge-yellow': l.status === 'PENDING',
                  'badge-green': l.status === 'APPROVED',
                  'badge-red': l.status === 'REJECTED',
                  'badge-gray': l.status === 'CANCELLED',
                }">{{ l.status }}</span>
              </td>
              <td class="text-right">
                <div v-if="l.status === 'PENDING' && auth.can('leave.approve')" class="inline-flex gap-2">
                  <button class="text-green-600 hover:bg-green-50 rounded p-1" @click="approve(l.id)" title="Setujui"><CheckIcon class="w-4 h-4" /></button>
                  <button class="text-red-600 hover:bg-red-50 rounded p-1" @click="reject(l.id)" title="Tolak"><XMarkIcon class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>