<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'

const toast = useToast()
const roles = ref([])
const grouped = ref({})
const showForm = ref(false)
const editing = ref(null)
const form = ref({ slug: '', name: '', description: '', permissions: [] })

async function fetchData() {
  const [r, p] = await Promise.all([api.get('/roles'), api.get('/permissions')])
  roles.value = r.data.data
  grouped.value = p.data.data.grouped
}
onMounted(fetchData)

async function openEdit(role) {
  const res = await api.get(`/roles/${role.id}`)
  editing.value = role
  form.value = {
    slug: res.data.data.slug,
    name: res.data.data.name,
    description: res.data.data.description || '',
    permissions: res.data.data.permissions.map(p => p.id),
  }
  showForm.value = true
}

function openCreate() {
  editing.value = null
  form.value = { slug: '', name: '', description: '', permissions: [] }
  showForm.value = true
}

async function save() {
  try {
    if (editing.value) {
      await api.put(`/roles/${editing.value.id}`, form.value)
    } else {
      await api.post('/roles', form.value)
    }
    toast.success('Tersimpan')
    showForm.value = false
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal') }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Role & Permission</h1>
      <button class="btn-primary" @click="openCreate">+ Tambah Role</button>
    </div>
    <div class="card">
      <table class="table">
        <thead><tr><th>Slug</th><th>Nama</th><th>Tipe</th><th class="text-right">Aksi</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="r in roles" :key="r.id">
            <td class="font-mono text-xs">{{ r.slug }}</td>
            <td>{{ r.name }}</td>
            <td><span :class="r.is_system ? 'badge-blue' : 'badge-gray'">{{ r.is_system ? 'System' : 'Custom' }}</span></td>
            <td class="text-right"><button class="text-primary-600" @click="openEdit(r)">Edit</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40" @click.self="showForm = false">
      <form @submit.prevent="save" class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b font-semibold">{{ editing ? 'Edit Role' : 'Tambah Role' }}</div>
        <div class="p-6 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div><label class="label">Slug</label><input v-model="form.slug" class="input" required :disabled="editing" /></div>
            <div><label class="label">Nama</label><input v-model="form.name" class="input" required /></div>
          </div>
          <div><label class="label">Deskripsi</label><textarea v-model="form.description" rows="2" class="input"></textarea></div>
          <div>
            <label class="label">Permission</label>
            <div v-for="(perms, group) in grouped" :key="group" class="mb-3">
              <div class="text-xs font-semibold text-gray-500 uppercase mb-1">{{ group }}</div>
              <div class="grid grid-cols-2 gap-1">
                <label v-for="p in perms" :key="p.id" class="flex items-center gap-2 text-sm">
                  <input type="checkbox" :value="p.id" v-model="form.permissions" />
                  <span>{{ p.name }}</span>
                </label>
              </div>
            </div>
          </div>
        </div>
        <div class="px-6 py-4 border-t flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showForm = false">Batal</button>
          <button type="submit" class="btn-primary">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</template>