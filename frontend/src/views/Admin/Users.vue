<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'

const toast = useToast()
const items = ref([])
const roles = ref([])
const showForm = ref(false)
const editing = ref(null)
const form = ref({ email: '', name: '', role_id: 4, password: '', is_active: 1 })

async function fetchData() {
  const [u, r] = await Promise.all([
    api.get('/users', { params: { per_page: 100 } }),
    api.get('/roles'),
  ])
  items.value = u.data.data
  roles.value = r.data.data
}
onMounted(fetchData)

function openCreate() { editing.value = null; form.value = { email: '', name: '', role_id: 4, password: '', is_active: 1 }; showForm.value = true }
function openEdit(u) { editing.value = u; form.value = { ...u, password: '' }; showForm.value = true }

async function save() {
  try {
    if (editing.value) {
      const payload = { ...form.value }
      if (! payload.password) delete payload.password
      await api.put(`/users/${editing.value.id}`, payload)
      toast.success('User diperbarui')
    } else {
      await api.post('/users', form.value)
      toast.success('User dibuat')
    }
    showForm.value = false
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}

async function remove(id) {
  const ok = await confirmDialog({
    title: 'Nonaktifkan user?',
    message: 'User ini tidak akan bisa login lagi sampai diaktifkan kembali.',
    okText: 'Nonaktifkan',
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/users/${id}`)
    toast.success('User dinonaktifkan')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menonaktifkan')
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Pengguna</h1>
      <button class="btn-primary" @click="openCreate">+ Tambah Pengguna</button>
    </div>
    <div class="card">
      <table class="table">
        <thead><tr><th>Email</th><th>Nama</th><th>Role</th><th>Status</th><th>Login Terakhir</th><th class="text-right">Aksi</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="u in items" :key="u.id">
            <td>{{ u.email }}</td>
            <td>{{ u.name }}</td>
            <td>{{ u.role_name }}</td>
            <td><span :class="u.is_active ? 'badge-green' : 'badge-gray'">{{ u.is_active ? 'Aktif' : 'Non-aktif' }}</span></td>
            <td>{{ u.last_login_at ? new Date(u.last_login_at).toLocaleString('id-ID') : '-' }}</td>
            <td class="text-right">
              <button class="text-primary-600 mr-2" @click="openEdit(u)">Edit</button>
              <button class="text-red-600" @click="remove(u.id)">Hapus</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <form @submit.prevent="save" class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 class="font-semibold">{{ editing ? 'Edit Pengguna' : 'Tambah Pengguna' }}</h2>
        <div><label class="label">Email</label><input v-model="form.email" type="email" class="input" required :disabled="editing" /></div>
        <div><label class="label">Nama</label><input v-model="form.name" class="input" required /></div>
        <div><label class="label">Role</label>
          <select v-model="form.role_id" class="input" required>
            <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </div>
        <div><label class="label">Password {{ editing ? '(kosongkan jika tidak diubah)' : '*' }}</label>
          <input v-model="form.password" type="password" class="input" :required="!editing" minlength="10" />
        </div>
        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.is_active" :true-value="1" :false-value="0" /><span class="text-sm">Aktif</span></label>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showForm = false">Batal</button>
          <button type="submit" class="btn-primary">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</template>