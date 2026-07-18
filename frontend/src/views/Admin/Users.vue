<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useI18n } from '@/locales'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

const toast = useToast()
const { t } = useI18n()
const placeholderAttribute = 'placeholder'
const searchPlaceholder = computed(() => t('users.search_placeholder'))
const items = ref([])
const roles = ref([])
const loading = ref(false)
const meta = ref({ total: 0, page: 1, pages: 1, per_page: 20 })
const filters = reactive({ search: '', role_id: '', is_active: '', linked: '' })
const showForm = ref(false)
const editing = ref(null)
const form = ref({ email: '', name: '', role_id: 4, password: '', is_active: 1 })

async function fetchData(page = 1) {
  loading.value = true
  try {
    const params = { page, per_page: meta.value.per_page }
    for (const [key, value] of Object.entries(filters)) {
      if (value !== '') params[key] = value
    }
    const response = await api.get('/users', { params })
    items.value = response.data.data || []
    meta.value = response.data.meta || meta.value
  } catch (e) {
    toast.error(e.response?.data?.message || t('users.load_failed'))
  } finally {
    loading.value = false
  }
}

async function fetchRoles() {
  try {
    const response = await api.get('/roles')
    roles.value = response.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || t('users.load_failed'))
  }
}

onMounted(() => {
  fetchRoles()
  fetchData()
})

function applyFilters() { fetchData(1) }

function resetFilters() {
  Object.assign(filters, { search: '', role_id: '', is_active: '', linked: '' })
  fetchData(1)
}

function go(page) {
  if (page < 1 || page > (meta.value.pages || 1)) return
  fetchData(page)
}

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
    fetchData(meta.value.page)
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
    const targetPage = items.value.length === 1 && meta.value.page > 1
      ? meta.value.page - 1
      : meta.value.page
    fetchData(targetPage)
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
      <form class="p-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3" @submit.prevent="applyFilters">
        <div class="relative sm:col-span-2 xl:col-span-1">
          <MagnifyingGlassIcon class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input v-model.trim="filters.search" class="input pl-9" v-bind:[placeholderAttribute]="searchPlaceholder" />
        </div>
        <select v-model="filters.role_id" class="input">
          <option value="">{{ t('users.all_roles') }}</option>
          <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
        <select v-model="filters.is_active" class="input">
          <option value="">{{ t('users.all_statuses') }}</option>
          <option value="1">{{ t('users.active') }}</option>
          <option value="0">{{ t('users.inactive') }}</option>
        </select>
        <select v-model="filters.linked" class="input">
          <option value="">{{ t('users.all_link_statuses') }}</option>
          <option value="linked">{{ t('users.linked') }}</option>
          <option value="unlinked">{{ t('users.unlinked') }}</option>
        </select>
        <div class="flex gap-2">
          <button type="submit" class="btn-primary flex-1">{{ t('users.filter') }}</button>
          <button type="button" class="btn-secondary" @click="resetFilters">{{ t('users.reset') }}</button>
        </div>
      </form>

      <div class="overflow-x-auto">
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
            <tr v-if="loading">
              <td colspan="7" class="text-center py-8 text-gray-400">{{ t('users.loading') }}</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="7" class="text-center py-8 text-gray-400">{{ t('users.no_results') }}</td>
            </tr>
            <template v-else>
              <tr v-for="u in items" :key="u.id">
                <td>
                  <span v-if="u.employee_number" class="font-mono">{{ u.employee_number }}</span>
                  <span v-else class="text-xs text-gray-400 italic">— {{ t('users.not_linked') }} —</span>
                </td>
                <td>{{ u.email }}</td>
                <td>{{ u.name }}</td>
                <td>{{ u.role_name }}</td>
                <td><span :class="u.is_active ? 'badge-green' : 'badge-gray'">{{ u.is_active ? t('users.active') : t('users.inactive') }}</span></td>
                <td>{{ u.last_login_at ? new Date(u.last_login_at).toLocaleString('id-ID') : '-' }}</td>
                <td class="text-right">
                  <button class="text-primary-600 mr-2" @click="openEdit(u)">{{ t('users.edit') }}</button>
                  <button class="text-red-600" @click="remove(u.id)">{{ t('users.delete') }}</button>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3 flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-gray-100">
        <div class="text-sm text-gray-500">{{ t('users.total', { count: meta.total }) }}</div>
        <div class="flex items-center gap-2">
          <select v-model.number="meta.per_page" class="input py-1 w-20 text-sm" @change="fetchData(1)">
            <option :value="10">10</option>
            <option :value="20">20</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
          <button class="btn-secondary" :disabled="loading || meta.page <= 1" @click="go(meta.page - 1)">{{ t('users.previous') }}</button>
          <span class="px-2 text-sm">{{ t('users.page_of', { current: meta.page, total: meta.pages || 1 }) }}</span>
          <button class="btn-secondary" :disabled="loading || meta.page >= meta.pages" @click="go(meta.page + 1)">{{ t('users.next') }}</button>
        </div>
      </div>
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
