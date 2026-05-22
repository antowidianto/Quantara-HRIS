<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Sertifikat</h1>
        <p class="text-sm text-gray-600">Sertifikat training internal & eksternal dengan pelacakan masa berlaku.</p>
      </div>
      <div class="flex gap-2">
        <button @click="loadExpiring"
          class="px-3 py-2 rounded bg-amber-100 hover:bg-amber-200 text-amber-700 text-sm">
          ⏰ Akan Habis ({{ expiringCount }})
        </button>
        <button v-if="canManage" @click="openCreate"
          class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium">
          + Upload Sertifikat
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg p-3 border border-gray-200 flex flex-wrap gap-2 items-center">
      <label class="text-sm flex items-center gap-2 text-gray-700">
        <input type="checkbox" v-model="filters.mine" @change="fetch" />
        Hanya milik saya
      </label>
      <select v-model="filters.status" @change="fetch"
        class="px-3 py-2 rounded border border-gray-300 text-sm focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
        <option value="">Semua Status</option>
        <option value="ACTIVE">Aktif</option>
        <option value="EXPIRED">Kedaluwarsa</option>
        <option value="REVOKED">Dicabut</option>
      </select>
    </div>

    <!-- List -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Nomor / Judul</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Karyawan</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Issuer</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Terbit</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Berlaku Sampai</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="c in items" :key="c.id" class="hover:bg-gray-50"
            :class="{ 'bg-amber-50/60': isExpiringSoon(c) }">
            <td class="px-3 py-2">
              <div class="font-mono text-xs text-gray-500">{{ c.certificate_number }}</div>
              <div class="font-medium text-gray-900">{{ c.title }}</div>
              <div class="text-xs text-gray-500">{{ c.category }}</div>
            </td>
            <td class="px-3 py-2">
              <div class="text-gray-900">{{ c.employee_name }}</div>
              <div class="text-xs text-gray-500">{{ c.employee_number }}</div>
            </td>
            <td class="px-3 py-2 text-xs text-gray-700">{{ c.issuer || '-' }}</td>
            <td class="px-3 py-2 text-xs text-gray-700">{{ formatDate(c.issued_date) }}</td>
            <td class="px-3 py-2 text-xs text-gray-700">
              <span v-if="c.expires_date">
                {{ formatDate(c.expires_date) }}
                <div v-if="isExpiringSoon(c)" class="text-amber-700 font-medium text-xs">
                  ⚠ {{ daysLeft(c) }} hari lagi
                </div>
              </span>
              <span v-else class="text-gray-400">Lifetime</span>
            </td>
            <td class="px-3 py-2">
              <span class="px-2 py-0.5 rounded text-xs" :class="statusClass(c.status)">{{ statusLabel(c.status) }}</span>
            </td>
          </tr>
          <tr v-if="!items.length && !loading">
            <td colspan="6" class="px-3 py-12 text-center text-gray-500">Belum ada sertifikat.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal Upload -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showModal = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b border-gray-200 flex justify-between items-center">
          <h2 class="font-bold text-gray-900">Upload Sertifikat Eksternal</h2>
          <button @click="showModal = false" class="text-gray-400 hover:text-gray-600 text-xl">×</button>
        </div>
        <form @submit.prevent="save" class="p-5 space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Karyawan *</label>
            <EmployeeSearchSelect
              v-model="form.employee_id"
              placeholder="— pilih karyawan —"
              :only-active="true"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nama Sertifikat *</label>
            <input v-model="form.title" required maxlength="200"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
              placeholder="mis. ISO 9001 Lead Auditor" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Kategori</label>
              <select v-model="form.category"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
                <option value="PROFESSIONAL">Professional</option>
                <option value="COMPLIANCE">Compliance</option>
                <option value="LICENSE">License (SIM/Surat Izin)</option>
                <option value="TRAINING">Training</option>
                <option value="OTHER">Other</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nomor Sertifikat</label>
              <input v-model="form.certificate_number"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                placeholder="auto-generate kalau kosong" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Issuer / Penerbit</label>
            <input v-model="form.issuer"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
              placeholder="mis. BNSP, BSI, dll" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal Terbit *</label>
              <input v-model="form.issued_date" type="date" required
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Berlaku Sampai</label>
              <input v-model="form.expires_date" type="date"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                placeholder="kosong = lifetime" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">File Sertifikat (opsional)</label>
            <FileUploader
              v-model="form.file_path"
              context="training"
              accept=".pdf,.jpg,.jpeg,.png"
              :max-size-mb="8"
              hint="PDF/JPG/PNG sertifikat. Max 8 MB."
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Catatan</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="showModal = false" class="px-4 py-2 rounded bg-gray-200 text-gray-700 hover:bg-gray-300">Batal</button>
            <button type="submit" :disabled="saving"
              class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white disabled:opacity-50">
              {{ saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import FileUploader from '@/components/FileUploader.vue'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'

const auth = useAuthStore()
const canManage = computed(() => auth.can('certificate.manage'))

const items = ref([])
const employees = ref([])
const loading = ref(false)
const filters = ref({ mine: false, status: '' })
const expiringCount = ref(0)

const showModal = ref(false)
const saving = ref(false)
const form = ref(blankForm())

function blankForm() {
  return {
    employee_id: '', title: '', certificate_number: '',
    category: 'PROFESSIONAL', issuer: '',
    issued_date: '', expires_date: '',
    file_path: '', notes: '',
  }
}

async function fetch() {
  loading.value = true
  try {
    const params = { per_page: 100 }
    if (filters.value.mine) params.mine = 1
    if (filters.value.status) params.status = filters.value.status
    const r = await api.get('/certificates', { params })
    items.value = r.data?.data || []
  } finally { loading.value = false }
}

async function loadExpiring() {
  try {
    const r = await api.get('/certificates/expiring', { params: { days: 30 } })
    items.value = r.data?.data || []
    expiringCount.value = items.value.length
  } catch (e) {}
}

async function fetchExpiringCount() {
  try {
    const r = await api.get('/certificates/expiring', { params: { days: 30 } })
    expiringCount.value = (r.data?.data || []).length
  } catch (e) { expiringCount.value = 0 }
}

// Karyawan tidak lagi di-load semua sekaligus — picker pakai
// EmployeeSearchSelect server-side untuk dataset besar.
async function fetchEmployees() { /* deprecated: server-side search */ }

function openCreate() {
  form.value = blankForm()
  form.value.issued_date = new Date().toISOString().slice(0, 10)
  showModal.value = true
}

async function save() {
  saving.value = true
  try {
    await api.post('/certificates', form.value)
    showModal.value = false
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal menyimpan')
  } finally { saving.value = false }
}

function isExpiringSoon(c) {
  if (! c.expires_date || c.status !== 'ACTIVE') return false
  const days = (new Date(c.expires_date) - new Date()) / 86400000
  return days >= 0 && days <= 30
}

function daysLeft(c) {
  if (! c.expires_date) return null
  return Math.max(0, Math.floor((new Date(c.expires_date) - new Date()) / 86400000))
}

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function statusLabel(s) {
  return { ACTIVE: 'Aktif', EXPIRED: 'Kedaluwarsa', REVOKED: 'Dicabut' }[s] || s
}

function statusClass(s) {
  return {
    ACTIVE: 'bg-emerald-100 text-emerald-700',
    EXPIRED: 'bg-red-100 text-red-700',
    REVOKED: 'bg-gray-100 text-gray-700',
  }[s] || 'bg-gray-100 text-gray-700'
}

onMounted(() => {
  fetch()
  fetchExpiringCount()
  if (canManage.value) fetchEmployees()
})
</script>