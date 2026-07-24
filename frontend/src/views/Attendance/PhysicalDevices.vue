<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { PlusIcon, TrashIcon, PencilIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'

const toast = useToast()
const auth = useAuthStore()
const canManage = computed(() => auth.can('attendance.manage_devices'))
const devices = ref([])
const loading = ref(true)
const showForm = ref(false)
const formData = ref({ id: null, name: '', serial_number: '', ip_address: '' })

async function fetchDevices() {
  loading.value = true
  try {
    const res = await api.get('/physical-devices')
    devices.value = res.data.data || []
  } catch (e) {
    toast.error('Gagal memuat mesin absensi')
  } finally {
    loading.value = false
  }
}

onMounted(fetchDevices)

function openCreate() {
  if (!canManage.value) return
  formData.value = { id: null, name: '', serial_number: '', ip_address: '' }
  showForm.value = true
}

function openEdit(d) {
  if (!canManage.value) return
  formData.value = { ...d }
  showForm.value = true
}

async function save() {
  if (!canManage.value) return
  try {
    if (formData.value.id) {
      await api.put(`/physical-devices/${formData.value.id}`, formData.value)
      toast.success('Mesin diperbarui')
    } else {
      await api.post('/physical-devices', formData.value)
      toast.success('Mesin ditambahkan')
    }
    showForm.value = false
    fetchDevices()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

async function remove(id) {
  if (!canManage.value) return
  if (!confirm('Hapus mesin ini? Semua data histori sinkronisasi log di mesin tidak akan bisa dicocokkan lagi jika dihapus.')) return
  try {
    await api.delete(`/physical-devices/${id}`)
    toast.success('Dihapus')
    fetchDevices()
  } catch (e) {
    toast.error('Gagal menghapus')
  }
}

async function forceSync() {
  toast.info('Perintah sync manual dikirim ke middleware Node.js')
  // In real app, this would hit the middleware to trigger an active pull if the device supports it
  // Or it just provides instructions to reboot device to trigger push.
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold">Mesin Absensi Fisik (ADMS)</h1>
        <p class="text-gray-500 text-sm">Kelola mesin fingerprint/wajah ZKTeco/Solution</p>
      </div>
      <button v-if="canManage" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-5 h-5 mr-1" /> Tambah Mesin
      </button>
    </div>

    <div class="card overflow-hidden">
      <table class="table w-full">
        <thead>
          <tr>
            <th>Nama Mesin</th>
            <th>S/N (Serial Number)</th>
            <th>IP Address</th>
            <th>Status</th>
            <th>Sync Terakhir</th>
            <th class="w-24">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="loading"><td colspan="6" class="text-center py-6">Memuat...</td></tr>
          <tr v-else-if="devices.length === 0"><td colspan="6" class="text-center py-6 text-gray-400">Belum ada mesin yang didaftarkan.</td></tr>
          <tr v-for="d in devices" :key="d.id" class="hover:bg-gray-50 transition-colors">
            <td class="font-semibold">{{ d.name }}</td>
            <td class="font-mono text-sm">{{ d.serial_number }}</td>
            <td>{{ d.ip_address || '-' }}</td>
            <td>
              <span :class="{'bg-emerald-100 text-emerald-800': d.status === 'ONLINE', 'bg-rose-100 text-rose-800': d.status === 'OFFLINE'}" class="px-2 py-1 text-xs rounded-full font-medium">
                {{ d.status }}
              </span>
            </td>
            <td class="text-sm text-gray-500">
              <div class="flex items-center gap-2">
                {{ d.last_sync_at || 'Belum pernah' }}
                <button v-if="canManage" class="text-primary-600 hover:text-primary-800" title="Manual Sync" @click="forceSync">
                  <ArrowPathIcon class="w-4 h-4" />
                </button>
              </div>
            </td>
            <td>
              <div v-if="canManage" class="flex gap-2">
                <button class="text-gray-400 hover:text-primary-600" @click="openEdit(d)"><PencilIcon class="w-4 h-4" /></button>
                <button class="text-gray-400 hover:text-rose-600" @click="remove(d.id)"><TrashIcon class="w-4 h-4" /></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal Form -->
    <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
          <h3 class="font-bold text-lg">{{ formData.id ? 'Edit Mesin' : 'Tambah Mesin Baru' }}</h3>
          <button @click="showForm = false" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form @submit.prevent="save">
          <div class="p-6 space-y-4">
            <div>
              <label class="label">Nama Lokasi/Mesin</label>
              <input type="text" v-model="formData.name" class="input" placeholder="Lobby Utama" required />
            </div>
            <div>
              <label class="label">S/N (Serial Number)</label>
              <input type="text" v-model="formData.serial_number" class="input" placeholder="ABC123456" required />
              <p class="text-xs text-gray-500 mt-1">Harus sama persis dengan S/N di menu informasi perangkat.</p>
            </div>
            <div>
              <label class="label">IP Address (Opsional)</label>
              <input type="text" v-model="formData.ip_address" class="input" placeholder="192.168.1.100" />
            </div>
          </div>
          <div class="px-6 py-4 bg-gray-50 flex justify-end gap-2 rounded-b-xl">
            <button type="button" class="btn-secondary" @click="showForm = false">Batal</button>
            <button type="submit" class="btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
