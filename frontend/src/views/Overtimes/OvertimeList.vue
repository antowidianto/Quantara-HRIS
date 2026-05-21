<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { fmtDate, fmtCurrency } from '@/composables/useResource'
import { confirm as confirmDialog, promptDialog } from '@/composables/useConfirm'
import { PlusIcon, CheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const route = useRoute()
const toast = useToast()
const items = ref([])
const filterStatus = ref(route.query.status || '')

async function fetchData() {
  const res = await api.get('/overtimes', { params: { status: filterStatus.value || undefined } })
  items.value = res.data.data
}

onMounted(fetchData)

async function approve(id) {
  const ok = await confirmDialog({
    title: 'Setujui pengajuan lembur?',
    message: 'Pengajuan lembur ini akan dimasukkan ke perhitungan payroll periode terkait.',
    okText: 'Setujui',
    variant: 'success',
  })
  if (! ok) return
  try {
    await api.post(`/overtimes/${id}/approve`)
    toast.success('Lembur disetujui')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyetujui')
  }
}

async function reject(id) {
  const reason = await promptDialog({
    title: 'Tolak pengajuan lembur',
    inputLabel: 'Alasan penolakan',
    inputType: 'textarea',
    inputRequired: true,
    okText: 'Tolak',
    variant: 'danger',
  })
  if (reason === null) return
  try {
    await api.post(`/overtimes/${id}/reject`, { reason })
    toast.success('Lembur ditolak')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menolak')
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Lembur</h1>
      <RouterLink to="/overtimes/new" class="btn-primary"><PlusIcon class="w-4 h-4" /> Ajukan Lembur</RouterLink>
    </div>

    <div class="card">
      <div class="card-header">
        <select v-model="filterStatus" @change="fetchData" class="input w-40">
          <option value="">Semua Status</option>
          <option value="PENDING">Pending</option>
          <option value="APPROVED">Disetujui</option>
          <option value="REJECTED">Ditolak</option>
        </select>
      </div>
      <table class="table">
        <thead>
          <tr>
            <th>No</th>
            <th>Karyawan</th>
            <th>Tanggal</th>
            <th>Jam</th>
            <th>Tipe Hari</th>
            <th>Estimasi</th>
            <th>Status</th>
            <th class="text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="items.length === 0"><td colspan="8" class="text-center py-8 text-gray-400">Belum ada data</td></tr>
          <tr v-for="o in items" :key="o.id">
            <td class="font-mono text-xs">{{ o.request_number }}</td>
            <td>{{ o.employee_name }}</td>
            <td>{{ fmtDate(o.work_date) }}</td>
            <td>{{ o.hours }} jam</td>
            <td><span class="badge-blue">{{ o.day_type }}</span></td>
            <td>{{ fmtCurrency(o.computed_amount) }}</td>
            <td>
              <span :class="{
                'badge-yellow': o.status === 'PENDING',
                'badge-green': o.status === 'APPROVED',
                'badge-red': o.status === 'REJECTED',
                'badge-gray': o.status === 'CANCELLED',
              }">{{ o.status }}</span>
            </td>
            <td class="text-right">
              <div v-if="o.status === 'PENDING' && auth.can('overtime.approve')" class="inline-flex gap-2">
                <button class="text-green-600 hover:bg-green-50 rounded p-1" @click="approve(o.id)"><CheckIcon class="w-4 h-4" /></button>
                <button class="text-red-600 hover:bg-red-50 rounded p-1" @click="reject(o.id)"><XMarkIcon class="w-4 h-4" /></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>