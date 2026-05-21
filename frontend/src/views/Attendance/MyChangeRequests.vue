<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtDate, fmtDateTime } from '@/composables/useResource'
import { confirm as confirmDialog } from '@/composables/useConfirm'

const toast = useToast()
const items = ref([])
const meta = ref({ total: 0, page: 1, per_page: 20, pages: 1 })
const loading = ref(false)
const filters = ref({ status: '', start_date: '', end_date: '' })

async function fetchData(page = 1) {
  loading.value = true
  try {
    const res = await api.get('/attendance/change-requests', {
      params: { mine: 1, page, per_page: 20, ...filters.value },
    })
    items.value = res.data.data
    meta.value = res.data.meta
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat pengajuan')
  } finally {
    loading.value = false
  }
}

async function cancelRequest(row) {
  const ok = await confirmDialog({
    title: 'Batalkan pengajuan?',
    message: `Pengajuan ${row.request_number} akan ditandai CANCELLED.`,
    okText: 'Batalkan',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.post(`/attendance/change-requests/${row.id}/cancel`)
    toast.success('Pengajuan dibatalkan')
    fetchData(meta.value.page)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal membatalkan')
  }
}

const statusBadge = {
  PENDING:   'bg-amber-100 text-amber-800',
  APPROVED:  'bg-green-100 text-green-800',
  REJECTED:  'bg-red-100 text-red-800',
  CANCELLED: 'bg-gray-100 text-gray-700',
}

const typeLabel = {
  CLOCK_IN: 'Perbaiki Clock-In', CLOCK_OUT: 'Perbaiki Clock-Out',
  BOTH: 'Clock-In & Out', STATUS: 'Ubah Status', MISSED: 'Lupa Absen',
}

onMounted(() => fetchData())
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold">Pengajuan Perubahan Absensi Saya</h1>
        <p class="text-sm text-gray-500">Daftar semua pengajuan yang Anda buat dan statusnya.</p>
      </div>
      <RouterLink to="/attendance/my" class="btn-secondary">‹ Kembali ke Kalender</RouterLink>
    </div>

    <div class="card">
      <div class="card-header gap-3 flex-wrap">
        <select v-model="filters.status" class="input w-44">
          <option value="">Semua status</option>
          <option value="PENDING">Pending</option>
          <option value="APPROVED">Approved</option>
          <option value="REJECTED">Rejected</option>
          <option value="CANCELLED">Cancelled</option>
        </select>
        <input v-model="filters.start_date" type="date" class="input w-40" />
        <input v-model="filters.end_date" type="date" class="input w-40" />
        <button class="btn-primary" @click="fetchData(1)" :disabled="loading">Filter</button>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>No. Pengajuan</th>
              <th>Tanggal Kerja</th>
              <th>Tipe</th>
              <th>Permintaan</th>
              <th>Alasan</th>
              <th>Status</th>
              <th>Reviewer</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="!loading && items.length === 0">
              <td colspan="8" class="text-center py-8 text-gray-400">Belum ada pengajuan</td>
            </tr>
            <tr v-for="row in items" :key="row.id">
              <td class="font-mono text-xs">{{ row.request_number }}</td>
              <td>{{ fmtDate(row.work_date) }}</td>
              <td>{{ typeLabel[row.change_type] || row.change_type }}</td>
              <td class="text-xs">
                <div v-if="row.requested_clock_in">In: {{ row.requested_clock_in.slice(11, 16) }}</div>
                <div v-if="row.requested_clock_out">Out: {{ row.requested_clock_out.slice(11, 16) }}</div>
                <div v-if="row.requested_status">Status: {{ row.requested_status }}</div>
              </td>
              <td class="max-w-xs truncate" :title="row.reason">{{ row.reason }}</td>
              <td>
                <span :class="['px-2 py-0.5 rounded text-xs font-medium', statusBadge[row.status]]">
                  {{ row.status }}
                </span>
                <div v-if="row.review_note" class="text-xs text-gray-500 mt-1">{{ row.review_note }}</div>
              </td>
              <td class="text-xs">
                <div v-if="row.reviewer_name">{{ row.reviewer_name }}</div>
                <div v-if="row.reviewed_at" class="text-gray-500">{{ fmtDateTime(row.reviewed_at) }}</div>
              </td>
              <td>
                <button v-if="row.status === 'PENDING'" class="text-red-600 text-sm hover:underline"
                  @click="cancelRequest(row)">Batalkan</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="meta.pages > 1" class="px-4 py-3 flex items-center justify-between border-t border-gray-100 text-sm">
        <span class="text-gray-500">Total: {{ meta.total }}</span>
        <div class="flex gap-1">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="fetchData(meta.page - 1)">‹</button>
          <span class="px-3 py-1">Hal {{ meta.page }} / {{ meta.pages }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="fetchData(meta.page + 1)">›</button>
        </div>
      </div>
    </div>
  </div>
</template>