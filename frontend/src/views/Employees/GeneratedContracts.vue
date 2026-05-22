<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  DocumentTextIcon, EyeIcon, TrashIcon, PaperAirplaneIcon,
  CheckBadgeIcon, NoSymbolIcon,
} from '@heroicons/vue/24/outline'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

/**
 * Halaman daftar kontrak yang sudah digenerate.
 *
 * Menampilkan kontrak yang dihasilkan dari template dengan snapshot HTML —
 * dari sini HR bisa preview, ubah status (DRAFT → SENT → SIGNED), atau hapus.
 */

const toast = useToast()

const items = ref([])
const meta = ref({ total: 0, page: 1, pages: 1 })
const loading = ref(false)
const params = reactive({ page: 1, per_page: 20, status: '', employee_id: '' })

const employees = ref([])

const statusBadge = {
  DRAFT:   { class: 'bg-gray-100 text-gray-700',   label: 'Draft' },
  SENT:    { class: 'bg-blue-50 text-blue-700',    label: 'Terkirim' },
  SIGNED:  { class: 'bg-green-50 text-green-700',  label: 'Ditandatangani' },
  REVOKED: { class: 'bg-red-50 text-red-700',      label: 'Dicabut' },
}

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/generated-contracts', { params })
    items.value = res.data.data || []
    meta.value = res.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data')
  } finally {
    loading.value = false
  }
}

// Karyawan tidak lagi di-load semua sekaligus — picker pakai
// EmployeeSearchSelect server-side untuk dataset besar.
onMounted(() => {
  fetchData()
})

watch(() => [params.status, params.employee_id], () => {
  params.page = 1
  fetchData()
})

function go(page) { params.page = page; fetchData() }

function openHtml(item) {
  // Buka HTML kontrak di tab baru. Endpoint butuh JWT, jadi kita ambil
  // dulu via api lalu buka object URL.
  api.get(`/generated-contracts/${item.id}/html`, { responseType: 'text' })
    .then(res => {
      const blob = new Blob([res.data], { type: 'text/html' })
      const url = URL.createObjectURL(blob)
      const w = window.open(url, '_blank')
      if (! w) toast.warning('Pop-up diblokir browser')
    })
    .catch(e => toast.error(e.response?.data?.message || 'Gagal membuka kontrak'))
}

async function changeStatus(item, newStatus) {
  const labels = {
    SENT: 'mengirim ke karyawan',
    SIGNED: 'menandai sebagai ditandatangani',
    REVOKED: 'mencabut',
  }
  const ok = await confirmDialog({
    title: 'Ubah Status',
    message: `Yakin ${labels[newStatus] || 'mengubah status'} kontrak ${item.contract_number}?`,
    okText: 'Ya', variant: newStatus === 'REVOKED' ? 'danger' : 'primary',
  })
  if (! ok) return
  try {
    await api.put(`/generated-contracts/${item.id}/status`, { status: newStatus })
    toast.success('Status diperbarui')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memperbarui status')
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: 'Hapus Kontrak',
    message: `Hapus kontrak ${item.contract_number}? Tindakan ini tidak dapat dibatalkan.`,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/generated-contracts/${item.id}`)
    toast.success('Berhasil dihapus')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

function fmtDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <DocumentTextIcon class="w-6 h-6" /> Kontrak Karyawan
      </h1>
    </div>

    <div class="card">
      <div class="card-header flex flex-wrap gap-3 items-center">
        <select v-model="params.status" class="input w-full md:w-48">
          <option value="">— Semua Status —</option>
          <option value="DRAFT">Draft</option>
          <option value="SENT">Terkirim</option>
          <option value="SIGNED">Ditandatangani</option>
          <option value="REVOKED">Dicabut</option>
        </select>
        <div class="w-full md:w-72">
          <EmployeeSearchSelect
            v-model="params.employee_id"
            placeholder="— Semua Karyawan —"
            :only-active="false"
          />
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Nomor Kontrak</th>
              <th>Karyawan</th>
              <th>Subjek</th>
              <th>Template</th>
              <th>Status</th>
              <th>Dibuat</th>
              <th>Ditandatangani</th>
              <th class="text-right w-44">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="8" class="text-center py-8 text-gray-400">Memuat...</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="8" class="text-center py-8 text-gray-400">Belum ada kontrak</td></tr>
            <tr v-for="item in items" :key="item.id">
              <td class="font-mono text-sm">{{ item.contract_number }}</td>
              <td>
                <div class="font-medium">{{ item.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ item.employee_number }}</div>
              </td>
              <td class="max-w-[320px] truncate" :title="item.subject">{{ item.subject }}</td>
              <td>
                <div class="text-sm">{{ item.template_name }}</div>
                <div class="text-xs text-gray-500">{{ item.template_type }}</div>
              </td>
              <td>
                <span :class="['px-2 py-0.5 rounded-full text-xs', statusBadge[item.status]?.class]">
                  {{ statusBadge[item.status]?.label || item.status }}
                </span>
              </td>
              <td class="text-xs text-gray-500">{{ fmtDate(item.created_at) }}</td>
              <td class="text-xs text-gray-500">{{ fmtDate(item.signed_at) }}</td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button class="text-gray-500 hover:text-primary-600" @click="openHtml(item)" title="Lihat / Cetak">
                    <EyeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="item.status === 'DRAFT'" class="text-blue-600 hover:text-blue-800"
                    @click="changeStatus(item, 'SENT')" title="Tandai Terkirim">
                    <PaperAirplaneIcon class="w-4 h-4" />
                  </button>
                  <button v-if="item.status === 'SENT'" class="text-green-600 hover:text-green-800"
                    @click="changeStatus(item, 'SIGNED')" title="Tandai Ditandatangani">
                    <CheckBadgeIcon class="w-4 h-4" />
                  </button>
                  <button v-if="['DRAFT','SENT','SIGNED'].includes(item.status)"
                    class="text-amber-600 hover:text-amber-800"
                    @click="changeStatus(item, 'REVOKED')" title="Cabut">
                    <NoSymbolIcon class="w-4 h-4" />
                  </button>
                  <button class="text-gray-500 hover:text-red-600" @click="remove(item)" title="Hapus">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card-body flex items-center justify-between">
        <div class="text-sm text-gray-500">Total: {{ meta.total }}</div>
        <div class="flex gap-2">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="go(meta.page - 1)">Sebelumnya</button>
          <span class="px-3 py-2 text-sm">{{ meta.page }} / {{ meta.pages || 1 }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="go(meta.page + 1)">Berikutnya</button>
        </div>
      </div>
    </div>
  </div>
</template>