<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useI18n } from '@/locales'

const toast = useToast()
const { t } = useI18n()
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
      toast.success(t('users.saved_updated'))
    } else {
      await api.post('/users', form.value)
      toast.success(t('users.saved_created'))
    }
    showForm.value = false
    fetchData()
  } catch (e) { toast.error(e.response?.data?.message || t('users.failed')) }
}

async function remove(id) {
  const ok = await confirmDialog({
    title: t('users.deactivate_title'),
    message: t('users.deactivate_message'),
    okText: t('users.deactivate_btn'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/users/${id}`)
    toast.success(t('users.deactivated_success'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('users.deactivate_failed'))
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('users.title') }}</h1>
      <button class="btn-primary" @click="openCreate">+ {{ t('users.add_user') }}</button>
    </div>
    <div class="card">
      <p class="px-4 pt-3 text-xs text-gray-500">
        {{ t('users.info') }}
      </p>
      <table class="table">
        <thead>
          <tr>
            <th>{{ t('users.col_nik') }}</th>
            <th>{{ t('users.col_email') }}</th>
            <th>{{ t('users.col_name') }}</th>
            <th>{{ t('users.col_role') }}</th>
            <th>{{ t('users.col_status') }}</th>
            <th>{{ t('users.col_last_login') }}</th>
            <th class="text-right">{{ t('users.col_actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="u in items" :key="u.id">
            <td>
              <span v-if="u.employee_number" class="font-mono">{{ u.employee_number }}</span>
              <span v-else class="text-xs text-gray-400 italic">— {{ t('users.not_linked') }} —</span>
            </td>
            <td>{{ u.email }}</td>
            <td>{{ u.name }}</td>
            <td>{{ u.role_name }}</td>
            <td><span :class="u.is_active ? 'badge-green' : 'badge-gray'">{{ u.is_active ? t('users.active') : t('employees.list.inactive') }}</span></td>
            <td>{{ u.last_login_at ? new Date(u.last_login_at).toLocaleString('id-ID') : '-' }}</td>
            <td class="text-right">
              <button class="text-primary-600 mr-2" @click="openEdit(u)">{{ t('users.edit') }}</button>
              <button class="text-red-600" @click="remove(u.id)">{{ t('users.delete') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40">
      <form @submit.prevent="save" class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 class="font-semibold">{{ editing ? t('users.edit_title') : t('users.create_title') }}</h2>
        <div><label class="label">{{ t('users.email') }}</label><input v-model="form.email" type="email" class="input" required :disabled="editing" /></div>
        <div><label class="label">{{ t('users.name') }}</label><input v-model="form.name" class="input" required /></div>
        <div><label class="label">{{ t('users.role') }}</label>
          <select v-model="form.role_id" class="input" required>
            <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </div>
        <div><label class="label">{{ t('users.password') }} {{ editing ? t('users.password_hint') : '*' }}</label>
          <input v-model="form.password" type="password" class="input" :required="!editing" minlength="10" />
        </div>
        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.is_active" :true-value="1" :false-value="0" /><span class="text-sm">{{ t('users.active') }}</span></label>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showForm = false">{{ t('users.cancel') }}</button>
          <button type="submit" class="btn-primary">{{ t('users.save') }}</button>
        </div>
      </form>
    </div>
  </div>
</template>
