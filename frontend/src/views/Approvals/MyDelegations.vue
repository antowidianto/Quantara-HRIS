<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/locales'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, PencilIcon, TrashIcon, XMarkIcon, ShieldCheckIcon,
  ArrowsRightLeftIcon, CheckCircleIcon,
} from '@heroicons/vue/24/outline'
import SearchableSelect from '@/components/SearchableSelect.vue'

/**
 * Halaman delegasi approval — user mengatur siapa yang menggantikan dia
 * saat cuti, training, dll. Pegawai biasa hanya boleh kelola delegasinya
 * sendiri; HR/admin punya tab "Semua Delegasi".
 *
 * Tampilan:
 *  - Tab "Saya Delegasikan" (saya mendelegasikan ke orang lain)
 *  - Tab "Mewakili Orang Lain" (saya jadi delegate)
 *  - Tab "Semua" (hanya kalau punya delegation.view)
 */

const auth = useAuthStore()
const toast = useToast()
const { t } = useI18n()

const items = ref([])
const loading = ref(false)
const tab = ref('mine') // mine | as-delegate | all
const filterModule = ref('')

const showEditor = ref(false)
const editing = ref(false)
const form = ref(emptyForm())

const users = ref([])
const myUserId = computed(() => Number(auth.user?.id))
const canManageOthers = computed(
  () => auth.can('delegation.manage') || auth.isSuperAdmin
)

const moduleOptions = computed(() => [
  { value: '', label: t('delegation.modules.all') },
  { value: 'LEAVE', label: t('delegation.modules.leave') },
  { value: 'OVERTIME', label: t('delegation.modules.overtime') },
  { value: 'ATTENDANCE_CHANGE', label: t('delegation.modules.attendance_change') },
  { value: 'REIMBURSEMENT', label: t('delegation.modules.reimbursement') },
  { value: 'LOAN', label: t('delegation.modules.loan') },
  { value: 'BUSINESS_TRIP', label: 'Perjalanan Dinas / SPPD' },
  { value: 'BONUS', label: 'Bonus' },
  { value: 'PAYROLL_ADJUSTMENT', label: 'Koreksi Payroll' },
  { value: 'COMPENSATION_REVIEW', label: 'Perubahan Kompensasi' },
  { value: 'TRAINING_REGISTRATION', label: 'Registrasi Training' },
  { value: 'TIMESHEET', label: 'Timesheet' },
  { value: 'CANDIDATE_OFFER', label: 'Candidate Offer' },
  { value: 'GENERATED_CONTRACT', label: 'Kontrak Kerja' },
  { value: 'ASSET_TRANSACTION', label: 'Transaksi Asset' },
  { value: 'HIRE_CONVERSION', label: 'Konversi Kandidat' },
  { value: 'PERFORMANCE_GOAL', label: 'Performance Goal' },
  { value: 'IDP_ACTIVATION', label: 'Aktivasi IDP' },
  { value: 'EMPLOYEE_SENSITIVE_CHANGE', label: 'Perubahan Data Sensitif Employee' },
  { value: 'MASTER_RATE_CHANGE', label: 'Perubahan Master Rate Payroll' },
  { value: 'PRIVILEGED_ACTION', label: 'Aksi Administratif Berisiko' },
])

function emptyForm() {
  return {
    id: null,
    user_id: '',         // dibiarkan kosong → auto fill current user di submit
    delegate_to_user_id: '',
    module: '',
    date_from: new Date().toISOString().slice(0, 10),
    date_to: '',
    reason: '',
    is_active: 1,
  }
}

async function fetchData() {
  loading.value = true
  try {
    const params = {}
    if (tab.value === 'mine') params.mine = 1
    if (filterModule.value) params.module = filterModule.value
    const res = await api.get('/approval-delegations', { params })
    let rows = res.data.data || []
    if (tab.value === 'as-delegate') {
      rows = rows.filter(r => Number(r.delegate_to_user_id) === myUserId.value)
    }
    items.value = rows
  } catch (e) {
    toast.error(e.response?.data?.message || t('delegation.toast_load_failed'))
  } finally {
    loading.value = false
  }
}

async function loadUsers() {
  try {
    const res = await api.get('/approval-delegations/eligible-users')
    users.value = (res.data.data || []).filter(u => Number(u.id) !== myUserId.value)
  } catch (_) { /* silent */ }
}

onMounted(() => {
  loadUsers()
  fetchData()
})

function openCreate() {
  form.value = emptyForm()
  editing.value = false
  showEditor.value = true
}

function openEdit(item) {
  form.value = {
    ...emptyForm(),
    ...item,
    module: item.module ?? '',
  }
  editing.value = true
  showEditor.value = true
}

async function save() {
  if (! form.value.delegate_to_user_id) {
    toast.warning(t('delegation.toast_choose_delegate'))
    return
  }
  if (form.value.date_to < form.value.date_from) {
    toast.warning(t('delegation.toast_date_mismatch'))
    return
  }
  try {
    const payload = { ...form.value }
    if (! payload.module) payload.module = null
    if (editing.value) {
      await api.put(`/approval-delegations/${payload.id}`, payload)
      toast.success(t('delegation.toast_updated'))
    } else {
      await api.post('/approval-delegations', payload)
      toast.success(t('delegation.toast_created'))
    }
    showEditor.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('delegation.toast_save_failed'))
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: t('delegation.confirm_delete_title'),
    message: t('delegation.confirm_delete_message'),
    okText: t('common.delete'), variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/approval-delegations/${item.id}`)
    toast.success(t('delegation.toast_deleted'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('delegation.toast_delete_failed'))
  }
}

async function toggle(item) {
  try {
    await api.put(`/approval-delegations/${item.id}`, {
      is_active: item.is_active ? 0 : 1,
    })
    toast.success(item.is_active ? t('delegation.toast_deactivated') : t('delegation.toast_activated'))
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || t('delegation.toast_status_failed'))
  }
}

function moduleLabel(v) {
  return moduleOptions.value.find(m => m.value === (v || ''))?.label || v
}

function isCurrentlyActive(d) {
  if (! d.is_active) return false
  const today = new Date().toISOString().slice(0, 10)
  return d.date_from <= today && d.date_to >= today
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <ArrowsRightLeftIcon class="w-6 h-6" /> {{ t('delegation.title') }}
      </h1>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> {{ t('delegation.btn_create') }}
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex border-b border-gray-200 mb-4">
      <button :class="['px-4 py-2 text-sm border-b-2',
        tab === 'mine' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500'
      ]" @click="tab = 'mine'; fetchData()">
        {{ t('delegation.tab_mine') }}
      </button>
      <button :class="['px-4 py-2 text-sm border-b-2',
        tab === 'as-delegate' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500'
      ]" @click="tab = 'as-delegate'; fetchData()">
        {{ t('delegation.tab_as_delegate') }}
      </button>
      <button v-if="canManageOthers" :class="['px-4 py-2 text-sm border-b-2',
        tab === 'all' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500'
      ]" @click="tab = 'all'; fetchData()">
        {{ t('delegation.tab_all') }}
      </button>

      <div class="ml-auto py-1 pr-1 w-56">
        <SearchableSelect
          v-model="filterModule"
          :items="moduleOptions"
          value-key="value"
          label-key="label"
          :placeholder="t('delegation.filter_module')"
          size="sm"
          @change="fetchData"
        />
      </div>
    </div>

    <!-- Help -->
    <div v-if="tab === 'mine'" class="text-sm text-gray-500 mb-3">
      {{ t('delegation.help_mine') }}
    </div>
    <div v-else-if="tab === 'as-delegate'" class="text-sm text-gray-500 mb-3">
      {{ t('delegation.help_delegate') }}
    </div>

    <!-- List -->
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th v-if="tab !== 'mine'">{{ t('delegation.col_delegator') }}</th>
              <th>{{ t('delegation.col_delegate') }}</th>
              <th>{{ t('delegation.col_module') }}</th>
              <th>{{ t('delegation.col_valid') }}</th>
              <th>{{ t('delegation.col_reason') }}</th>
              <th>{{ t('delegation.col_status') }}</th>
              <th class="text-right w-32">{{ t('delegation.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td :colspan="tab !== 'mine' ? 7 : 6" class="text-center py-8 text-gray-400">{{ t('common.loading') }}</td></tr>
            <tr v-else-if="items.length === 0">
              <td :colspan="tab !== 'mine' ? 7 : 6" class="text-center py-12 text-gray-400">
                {{ t('delegation.no_data') }}
              </td>
            </tr>
            <tr v-for="item in items" :key="item.id">
              <td v-if="tab !== 'mine'">
                <div class="font-medium">{{ item.user_name }}</div>
                <div class="text-xs text-gray-500">{{ item.user_email }}</div>
              </td>
              <td>
                <div class="font-medium">{{ item.delegate_name }}</div>
                <div class="text-xs text-gray-500">{{ item.delegate_email }}</div>
              </td>
              <td>{{ moduleLabel(item.module) }}</td>
              <td>
                <div class="text-sm">{{ item.date_from }} → {{ item.date_to }}</div>
              </td>
              <td class="text-sm text-gray-600 max-w-xs truncate" :title="item.reason">{{ item.reason || '-' }}</td>
              <td>
                <span v-if="isCurrentlyActive(item)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-emerald-50 text-emerald-700">
                  <CheckCircleIcon class="w-3.5 h-3.5" /> {{ t('delegation.status_active') }}
                </span>
                <span v-else-if="Number(item.is_active) === 1" class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">
                  {{ t('delegation.status_scheduled') }}
                </span>
                <span v-else class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">
                  {{ t('delegation.status_inactive') }}
                </span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button v-if="Number(item.user_id) === myUserId || canManageOthers"
                    class="text-gray-500 hover:text-primary-600" @click="openEdit(item)" :title="t('common.edit')">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="Number(item.user_id) === myUserId || canManageOthers"
                    class="text-gray-500 hover:text-amber-600" @click="toggle(item)"
                    :title="item.is_active ? t('delegation.toast_deactivated') : t('delegation.toast_activated')">
                    <ShieldCheckIcon class="w-4 h-4" />
                  </button>
                  <button v-if="Number(item.user_id) === myUserId || canManageOthers"
                    class="text-gray-500 hover:text-red-600" @click="remove(item)" :title="t('common.delete')">
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Editor Modal -->
    <div v-if="showEditor" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-xl">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editing ? t('delegation.edit_title') : t('delegation.create_title') }}</h2>
          <button class="text-gray-500" @click="showEditor = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <form @submit.prevent="save" class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div v-if="canManageOthers" class="md:col-span-2">
            <label class="label">{{ t('delegation.delegator_label') }}</label>
            <SearchableSelect
              v-model="form.user_id"
              :items="users"
              value-key="id"
              label-key="name"
              sub-label-key="email"
              :search-keys="['name','email']"
              :placeholder="t('delegation.myself_placeholder', { name: auth.user?.name || '' })"
            />
          </div>

          <div class="md:col-span-2">
            <label class="label">{{ t('delegation.delegate_label') }}</label>
            <SearchableSelect
              v-model="form.delegate_to_user_id"
              :items="users"
              value-key="id"
              label-key="name"
              sub-label-key="email"
              :search-keys="['name','email']"
              :placeholder="t('delegation.delegate_placeholder')"
            />
          </div>

          <div>
            <label class="label">{{ t('delegation.valid_from') }}</label>
            <input v-model="form.date_from" type="date" class="input" required />
          </div>
          <div>
            <label class="label">{{ t('delegation.valid_to') }}</label>
            <input v-model="form.date_to" type="date" class="input" required />
          </div>

          <div class="md:col-span-2">
            <label class="label">{{ t('delegation.module_label') }}</label>
            <SearchableSelect
              v-model="form.module"
              :items="moduleOptions.filter(mo => mo.value)"
              value-key="value"
              label-key="label"
              :placeholder="t('delegation.modules.all')"
            />
            <div class="text-xs text-gray-500 mt-1">
              {{ t('delegation.module_hint') }}
            </div>
          </div>

          <div class="md:col-span-2">
            <label class="label">{{ t('delegation.reason_label') }}</label>
            <textarea v-model="form.reason" rows="2" class="input" :placeholder="t('delegation.reason_placeholder')"></textarea>
          </div>

          <div class="md:col-span-2 flex items-center gap-2">
            <input v-model="form.is_active" type="checkbox" :true-value="1" :false-value="0" class="w-4 h-4" />
            <span class="text-sm">{{ t('delegation.active_label') }}</span>
          </div>

          <div class="md:col-span-2 flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showEditor = false">{{ t('common.cancel') }}</button>
            <button type="submit" class="btn-primary">
              {{ editing ? t('delegation.edit_title') : t('delegation.create_title') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
