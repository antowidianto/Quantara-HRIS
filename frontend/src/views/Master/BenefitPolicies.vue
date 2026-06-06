<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtCurrency } from '@/composables/useResource'
import { PlusIcon, TrashIcon, PencilIcon } from '@heroicons/vue/24/outline'

const toast = useToast()
const policies = ref([])
const loading = ref(true)
const showForm = ref(false)
const formData = ref({ id: null, name: '', description: '', limit_amount: 0, reset_type: 'YEARLY' })

async function fetchPolicies() {
  loading.value = true
  try {
    const res = await api.get('/benefit-policies')
    policies.value = res.data.data || []
  } catch (e) {
    toast.error('Gagal memuat benefit policies')
  } finally {
    loading.value = false
  }
}

onMounted(fetchPolicies)

function openCreate() {
  formData.value = { id: null, name: '', description: '', limit_amount: 0, reset_type: 'YEARLY' }
  showForm.value = true
}

function openEdit(d) {
  formData.value = { ...d }
  showForm.value = true
}

async function save() {
  try {
    if (formData.value.id) {
      await api.put(`/benefit-policies/${formData.value.id}`, formData.value)
      toast.success('Plafon diperbarui')
    } else {
      await api.post('/benefit-policies', formData.value)
      toast.success('Plafon ditambahkan')
    }
    showForm.value = false
    fetchPolicies()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

async function remove(id) {
  if (!confirm('Hapus plafon ini? Histori pemakaian karyawan untuk tipe ini tidak akan bisa terhubung ke nama plafon lagi.')) return
  try {
    await api.delete(`/benefit-policies/${id}`)
    toast.success('Dihapus')
    fetchPolicies()
  } catch (e) {
    toast.error('Gagal menghapus')
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold">Benefit Policies (Plafon Medis)</h1>
        <p class="text-gray-500 text-sm">Kelola batas plafon reimbursement tahunan/bulanan per karyawan.</p>
      </div>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-5 h-5 mr-1" /> Tambah Plafon
      </button>
    </div>

    <div class="card overflow-hidden">
      <table class="table w-full">
        <thead>
          <tr>
            <th>Kategori Reimburse</th>
            <th>Deskripsi</th>
            <th>Plafon / Limit</th>
            <th>Tipe Reset</th>
            <th class="w-24">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="loading"><td colspan="5" class="text-center py-6">Memuat...</td></tr>
          <tr v-else-if="policies.length === 0"><td colspan="5" class="text-center py-6 text-gray-400">Belum ada kebijakan plafon.</td></tr>
          <tr v-for="d in policies" :key="d.id" class="hover:bg-gray-50 transition-colors">
            <td class="font-semibold">{{ d.name }}</td>
            <td class="text-sm text-gray-500">{{ d.description || '-' }}</td>
            <td class="font-bold text-emerald-600">{{ fmtCurrency(d.limit_amount) }}</td>
            <td>
              <span class="bg-blue-100 text-blue-800 px-2 py-1 text-xs rounded-full font-medium">
                {{ d.reset_type }}
              </span>
            </td>
            <td>
              <div class="flex gap-2">
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
          <h3 class="font-bold text-lg">{{ formData.id ? 'Edit Plafon' : 'Tambah Plafon Baru' }}</h3>
          <button @click="showForm = false" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form @submit.prevent="save">
          <div class="p-6 space-y-4">
            <div>
              <label class="label">Kategori (Sesuai Form Reimburse)</label>
              <input type="text" v-model="formData.name" class="input" placeholder="Kacamata, Rawat Jalan..." required />
            </div>
            <div>
              <label class="label">Plafon / Limit</label>
              <div class="relative">
                <span class="absolute left-3 top-2.5 text-gray-500">Rp</span>
                <input type="number" v-model="formData.limit_amount" class="input pl-10" required />
              </div>
            </div>
            <div>
              <label class="label">Tipe Reset</label>
              <select v-model="formData.reset_type" class="input">
                <option value="YEARLY">Tahunan (Reset tiap 1 Januari)</option>
                <option value="MONTHLY">Bulanan (Reset tiap awal bulan)</option>
              </select>
            </div>
            <div>
              <label class="label">Deskripsi</label>
              <textarea v-model="formData.description" class="input" rows="2"></textarea>
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
