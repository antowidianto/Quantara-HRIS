<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useI18n } from '@/locales'
import {
  PlusIcon, PencilIcon, EyeIcon, ShieldCheckIcon, LockClosedIcon, TrashIcon,
} from '@heroicons/vue/24/outline'

/**
 * Roles & Permissions admin page.
 *
 * Otorisasi (matches backend RoleController):
 *   - Semua user terautentikasi boleh lihat daftar (read-only).
 *   - HANYA super admin boleh create / edit / delete role.
 *
 * UI guard di sini hanya untuk pengalaman pengguna; backend juga
 * melakukan check yang sama (lihat ensureSuperAdmin di RoleController),
 * jadi ngakalin frontend pun tetap kena 403 di server.
 */

const toast = useToast()
const { t } = useI18n()
const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.isSuperAdmin)

const roles = ref([])
const grouped = ref({})
const loading = ref(false)
const showForm = ref(false)
const viewMode = ref(false)        // true = read-only view (untuk admin biasa)
const editing = ref(null)
const form = ref({ slug: '', name: '', description: '', permissions: [] })

async function fetchData() {
  loading.value = true
  try {
    const [r, p] = await Promise.all([api.get('/roles'), api.get('/permissions')])
    roles.value = r.data.data
    grouped.value = p.data.data.grouped
  } catch (e) {
    toast.error(e.response?.data?.message || t('roles.failed_load'))
  } finally {
    loading.value = false
  }
}
onMounted(fetchData)

async function open(role, modeView = false) {
  try {
    const res = await api.get(`/roles/${role.id}`)
    editing.value = role
    // Read-only kalau:
    //   - Caller minta view explicit (tombol mata), ATAU
    //   - User bukan super admin.
    // Super admin BOLEH edit semua role termasuk system role
    // (hr_admin, manager, finance, employee). Backend sudah dilonggarkan
    // sesuai perubahan terbaru di RoleController::update.
    viewMode.value = modeView || ! isSuperAdmin.value
    form.value = {
      slug: res.data.data.slug,
      name: res.data.data.name,
      description: res.data.data.description || '',
      permissions: res.data.data.permissions.map(p => p.id),
    }
    showForm.value = true
  } catch (e) {
    toast.error(e.response?.data?.message || t('roles.failed_load_detail'))
  }
}

function openCreate() {
  if (! isSuperAdmin.value) {
    toast.warning(t('roles.only_superadmin_add'))
    return
  }
  editing.value = null
  viewMode.value = false
  form.value = { slug: '', name: '', description: '', permissions: [] }
  showForm.value = true
}

async function save() {
  if (! isSuperAdmin.value) {
    toast.error(t('roles.only_superadmin_save'))
    return
  }
  try {
    if (editing.value) {
      await api.put(`/roles/${editing.value.id}`, form.value)
    } else {
      await api.post('/roles', form.value)
    }
    toast.success(t('roles.saved'))
    showForm.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

async function remove(role) {
  if (! isSuperAdmin.value) return
  if (role.is_system) {
    toast.warning(t('roles.system_role_no_delete'))
    return
  }
  const ok = await confirmDialog({
    title: t('roles.delete_title'),
    message: t('roles.delete_message', { name: role.name }),
    okText: t('roles.delete_btn'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/roles/${role.id}`)
    toast.success(t('roles.deleted_success'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('roles.delete_failed'))
  }
}

const totalSelected = computed(() => form.value.permissions.length)
const totalAvailable = computed(() => Object.values(grouped.value).reduce((sum, arr) => sum + arr.length, 0))

function toggleGroup(perms) {
  if (viewMode.value) return
  const ids = perms.map(p => p.id)
  const allSelected = ids.every(id => form.value.permissions.includes(id))
  if (allSelected) {
    form.value.permissions = form.value.permissions.filter(id => ! ids.includes(id))
  } else {
    form.value.permissions = [...new Set([...form.value.permissions, ...ids])]
  }
}

function groupSelectedCount(perms) {
  return perms.filter(p => form.value.permissions.includes(p.id)).length
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ShieldCheckIcon class="w-7 h-7 text-primary-600" />
          {{ t('roles.title') }}
        </h1>
        <p class="text-sm text-gray-500">
          {{ t('nav.items.roles') }}
        </p>
      </div>
      <button v-if="isSuperAdmin" class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> {{ t('roles.add_role') }}
      </button>
    </div>

    <!-- Banner read-only untuk non-super-admin -->
    <div v-if="!isSuperAdmin"
      class="mb-4 rounded-md p-3 flex items-start gap-2 bg-amber-50 border border-amber-200 text-amber-900 text-sm">
      <LockClosedIcon class="w-5 h-5 mt-0.5 flex-shrink-0" />
      <div>
        <div class="font-medium">{{ t('role.read_only_banner') }}</div>
        <div class="text-xs">
          {{ t('role.read_only_note') }}
        </div>
      </div>
    </div>

    <div class="card">
      <table class="table">
        <thead>
          <tr>
            <th>{{ t('roles.slug') }}</th>
            <th>{{ t('roles.name') }}</th>
            <th>{{ t('users.role') }}</th>
            <th class="text-right">{{ t('roles.col_actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="loading">
            <td colspan="4" class="text-center py-8 text-gray-400">{{ t('roles.loading') }}</td>
          </tr>
          <tr v-else-if="roles.length === 0">
            <td colspan="4" class="text-center py-8 text-gray-400">{{ t('common.no_data') }}</td>
          </tr>
          <tr v-for="r in roles" :key="r.id" class="hover:bg-gray-50">
            <td class="font-mono text-xs">{{ r.slug }}</td>
            <td class="font-medium">{{ r.name }}</td>
            <td>
              <span class="px-2 py-0.5 rounded text-xs"
                :class="r.is_system ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700'">
                {{ r.is_system ? t('role.system_role') : t('role.custom_role') }}
              </span>
            </td>
            <td class="text-right">
              <div class="inline-flex gap-2">
                <!-- Lihat tersedia untuk semua user terautentikasi -->
                <button class="text-gray-600 hover:text-primary-600" :title="t('roles.view')"
                  @click="open(r, true)">
                  <EyeIcon class="w-4 h-4" />
                </button>
                <!-- Edit untuk super admin (termasuk system role; backend
                     hanya mencegah pengosongan permission super_admin) -->
                <button v-if="isSuperAdmin"
                  class="text-primary-600 hover:text-primary-800"
                  :title="t('roles.edit')"
                  @click="open(r)">
                  <PencilIcon class="w-4 h-4" />
                </button>
                <!-- Delete: super admin saja, dan TIDAK untuk role sistem -->
                <button v-if="isSuperAdmin && ! r.is_system"
                  class="text-red-600 hover:text-red-800" :title="t('roles.delete')"
                  @click="remove(r)">
                  <TrashIcon class="w-4 h-4" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal: edit / create / view -->
    <div v-if="showForm" class="fixed inset-0 z-40 flex items-center justify-center p-4 bg-black/40"
      @click.self="showForm = false">
      <form @submit.prevent="save" class="bg-white rounded-lg shadow-xl w-full max-w-6xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b font-semibold flex items-center justify-between">
          <span class="flex items-center gap-2">
            <ShieldCheckIcon class="w-5 h-5 text-primary-600" />
            <span v-if="viewMode">{{ t('role.detail') }}</span>
            <span v-else>{{ editing ? t('roles.edit_title') : t('roles.create_title') }}</span>
            <span v-if="viewMode"
              class="ml-2 inline-flex items-center gap-1 text-xs font-normal text-amber-700 bg-amber-100 px-2 py-0.5 rounded">
              <LockClosedIcon class="w-3 h-3" /> Read-only
            </span>
          </span>
          <button type="button" class="text-gray-500" @click="showForm = false">&times;</button>
        </div>

        <div class="p-6 space-y-4">
          <!-- Banner peringatan saat super admin edit role sistem -->
          <div v-if="!viewMode && editing && editing.is_system"
            class="rounded-md p-3 bg-amber-50 border border-amber-200 text-amber-900 text-sm flex items-start gap-2">
            <LockClosedIcon class="w-5 h-5 mt-0.5 flex-shrink-0" />
            <div>
              <div class="font-medium">Anda sedang mengedit role sistem</div>
              <div class="text-xs">
                Role <span class="font-mono">{{ editing.slug }}</span> adalah role bawaan.
                Slug tidak bisa diubah, dan jangan kosongkan permission role <span class="font-mono">super_admin</span> karena
                akan menutup akses Anda sendiri.
              </div>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">{{ t('roles.slug') }}</label>
              <input v-model="form.slug" class="input" required
                :disabled="!!editing || viewMode" />
              <p v-if="editing" class="text-xs text-gray-500 mt-1">
                Slug tidak dapat diubah setelah dibuat.
              </p>
            </div>
            <div>
              <label class="label">{{ t('roles.name') }}</label>
              <input v-model="form.name" class="input" required :disabled="viewMode" />
            </div>
          </div>
          <div>
            <label class="label">{{ t('roles.description') }}</label>
            <textarea v-model="form.description" rows="2" class="input" :disabled="viewMode"></textarea>
          </div>

          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="label mb-0">{{ t('roles.permissions') }}</label>
              <span class="text-xs text-gray-500">
                {{ t('roles.permissions_selected', { selected: totalSelected, total: totalAvailable }) }}
              </span>
            </div>

            <div v-for="(perms, group) in grouped" :key="group" class="mb-3 border border-gray-100 rounded p-3">
              <div class="flex items-center justify-between mb-2">
                <div class="text-xs font-semibold text-gray-700 uppercase">{{ group }}</div>
                <div class="flex items-center gap-2 text-xs">
                  <span class="text-gray-500">
                    {{ groupSelectedCount(perms) }} / {{ perms.length }}
                  </span>
                  <button v-if="!viewMode" type="button" class="text-primary-600 hover:underline"
                    @click="toggleGroup(perms)">
                    {{ t('role.toggle_all') }}
                  </button>
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-1">
                <label v-for="p in perms" :key="p.id"
                  class="flex items-center gap-2 text-sm py-0.5"
                  :class="viewMode ? 'cursor-default' : 'cursor-pointer hover:bg-gray-50 rounded px-1'">
                  <input type="checkbox" :value="p.id" v-model="form.permissions"
                    :disabled="viewMode" class="w-4 h-4" />
                  <span class="font-mono text-xs text-gray-500">{{ p.slug }}</span>
                  <span class="text-gray-700">— {{ p.name }}</span>
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="px-6 py-4 border-t flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="showForm = false">
            {{ viewMode ? t('common.close') : t('common.cancel') }}
          </button>
          <button v-if="!viewMode" type="submit" class="btn-primary">{{ t('common.save') }}</button>
        </div>
      </form>
    </div>
  </div>
</template>
