<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
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

const moduleOptions = [
  { value: '', label: 'Semua Modul' },
  { value: 'LEAVE', label: 'Cuti' },
  { value: 'OVERTIME', label: 'Lembur' },
  { value: 'ATTENDANCE_CHANGE', label: 'Pengajuan Perubahan Absensi' },
  { value: 'REIMBURSEMENT', label: 'Reimbursement' },
  { value: 'LOAN', label: 'Kasbon / Pinjaman' },
]

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
    toast.error(e.response?.data?.message || 'Gagal memuat delegasi')
  } finally {
    loading.value = false
  }
}

async function loadUsers() {
  try {
    const res = await api.get('/users', { params: { per_page: 500, is_active: 1 } })
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
    toast.warning('Pilih user pengganti')
    return
  }
  if (form.value.date_to < form.value.date_from) {
    toast.warning('Tanggal selesai harus >= tanggal mulai')
    return
  }
  try {
    const payload = { ...form.value }
    if (! payload.module) payload.module = null
    if (editing.value) {
      await api.put(`/approval-delegations/${payload.id}`, payload)
      toast.success('Delegasi diperbarui')
    } else {
      await api.post('/approval-delegations', payload)
      toast.success('Delegasi dibuat')
    }
    showEditor.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: 'Hapus Delegasi',
    message: 'Hapus delegasi ini? User pengganti akan kehilangan kewenangan approval.',
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/approval-delegations/${item.id}`)
    toast.success('Berhasil dihapus')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

async function toggle(item) {
  try {
    await api.put(`/approval-delegations/${item.id}`, {
      is_active: item.is_active ? 0 : 1,
    })
    toast.success(item.is_active ? 'Dinonaktifkan' : 'Diaktifkan')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mengubah status')
  }
}

function moduleLabel(v) {
  return moduleOptions.find(m => m.value === (v || ''))?.label || v
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
        <ArrowsRightLeftIcon class="w-6 h-6" /> Delegasi Approval
      </h1>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Buat Delegasi
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex border-b border-gray-200 mb-4">
      <button :class="['px-4 py-2 text-sm border-b-2',
        tab === 'mine' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500'
      ]" @click="tab = 'mine'; fetchData()">
        Saya Delegasikan
      </button>
      <button :class="['px-4 py-2 text-sm border-b-2',
        tab === 'as-delegate' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500'
      ]" @click="tab = 'as-delegate'; fetchData()">
        Mewakili Orang Lain
      </button>
      <button v-if="canManageOthers" :class="['px-4 py-2 text-sm border-b-2',
        tab === 'all' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-gray-500'
      ]" @click="tab = 'all'; fetchData()">
        Semua Delegasi
      </button>

      <div class="ml-auto py-1 pr-1 w-56">
        <SearchableSelect
          v-model="filterModule"
          :items="moduleOptions"
          value-key="value"
          label-key="label"
          placeholder="Semua Modul"
          size="sm"
          @change="fetchData"
        />
      </div>
    </div>

    <!-- Help -->
    <div v-if="tab === 'mine'" class="text-sm text-gray-500 mb-3">
      Delegasi memberikan kewenangan approval Anda ke user pengganti selama
      rentang tanggal tertentu. Anda tetap dapat approve sendiri saat kembali aktif.
    </div>
    <div v-else-if="tab === 'as-delegate'" class="text-sm text-gray-500 mb-3">
      Berikut delegasi di mana Anda menggantikan user lain. Inbox approval Anda
      akan otomatis menampilkan request dari user-user ini saat delegasi aktif.
    </div>

    <!-- List -->
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th v-if="tab !== 'mine'">Pendelegasi</th>
              <th>Pengganti</th>
              <th>Modul</th>
              <th>Berlaku</th>
              <th>Alasan</th>
              <th>Status</th>
              <th class="text-right w-32">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td :colspan="tab !== 'mine' ? 7 : 6" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="items.length === 0">
              <td :colspan="tab !== 'mine' ? 7 : 6" class="text-center py-12 text-gray-400">
                Belum ada delegasi
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
                  <CheckCircleIcon class="w-3.5 h-3.5" /> Aktif
                </span>
                <span v-else-if="Number(item.is_active) === 1" class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">
                  Terjadwal / Lewat
                </span>
                <span v-else class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">
                  Non-aktif
                </span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button v-if="Number(item.user_id) === myUserId || canManageOthers"
                    class="text-gray-500 hover:text-primary-600" @click="openEdit(item)" title="Edit">
                    <PencilIcon class="w-4 h-4" />
                  </button>
                  <button v-if="Number(item.user_id) === myUserId || canManageOthers"
                    class="text-gray-500 hover:text-amber-600" @click="toggle(item)"
                    :title="item.is_active ? 'Nonaktifkan' : 'Aktifkan'">
                    <ShieldCheckIcon class="w-4 h-4" />
                  </button>
                  <button v-if="Number(item.user_id) === myUserId || canManageOthers"
                    class="text-gray-500 hover:text-red-600" @click="remove(item)" title="Hapus">
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
          <h2 class="font-semibold">{{ editing ? 'Edit' : 'Buat' }} Delegasi</h2>
          <button class="text-gray-500" @click="showEditor = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <form @submit.prevent="save" class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div v-if="canManageOthers" class="md:col-span-2">
            <label class="label">Pendelegasi</label>
            <SearchableSelect
              v-model="form.user_id"
              :items="users"
              value-key="id"
              label-key="name"
              sub-label-key="email"
              :search-keys="['name','email']"
              :placeholder="`Saya sendiri (${auth.user?.name || ''})`"
            />
          </div>

          <div class="md:col-span-2">
            <label class="label">Pengganti *</label>
            <SearchableSelect
              v-model="form.delegate_to_user_id"
              :items="users"
              value-key="id"
              label-key="name"
              sub-label-key="email"
              :search-keys="['name','email']"
              placeholder="— Pilih user pengganti —"
            />
          </div>

          <div>
            <label class="label">Berlaku dari *</label>
            <input v-model="form.date_from" type="date" class="input" required />
          </div>
          <div>
            <label class="label">Berlaku sampai *</label>
            <input v-model="form.date_to" type="date" class="input" required />
          </div>

          <div class="md:col-span-2">
            <label class="label">Modul</label>
            <SearchableSelect
              v-model="form.module"
              :items="moduleOptions.filter(mo => mo.value)"
              value-key="value"
              label-key="label"
              placeholder="Semua modul"
            />
            <div class="text-xs text-gray-500 mt-1">
              Kosongkan untuk delegasi semua modul (LEAVE, OVERTIME, dll).
            </div>
          </div>

          <div class="md:col-span-2">
            <label class="label">Alasan</label>
            <textarea v-model="form.reason" rows="2" class="input" placeholder="Contoh: Cuti tahunan 5-12 Mei"></textarea>
          </div>

          <div class="md:col-span-2 flex items-center gap-2">
            <input v-model="form.is_active" type="checkbox" :true-value="1" :false-value="0" class="w-4 h-4" />
            <span class="text-sm">Aktif</span>
          </div>

          <div class="md:col-span-2 flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="showEditor = false">Batal</button>
            <button type="submit" class="btn-primary">
              {{ editing ? 'Simpan Perubahan' : 'Simpan Delegasi' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>