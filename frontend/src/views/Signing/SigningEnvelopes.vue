<template>
  <div class="p-6 space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">Digital Signing (PSrE)</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">
          Tanda tangan elektronik tersertifikasi UU ITE & Perpres 95/2018 untuk kontrak,
          offer letter, JIC, severance, transfer letter.
        </p>
      </div>
      <button class="px-4 py-2 rounded bg-blue-600 text-white text-sm" @click="openCreate">
        + Envelope Baru
      </button>
    </header>

    <div class="flex gap-2">
      <select v-model="statusFilter" class="border rounded px-2 py-1 text-sm" @change="load">
        <option value="">Semua status</option>
        <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
      </select>
      <select v-model="typeFilter" class="border rounded px-2 py-1 text-sm" @change="load">
        <option value="">Semua tipe</option>
        <option v-for="t in docTypes" :key="t" :value="t">{{ t }}</option>
      </select>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded shadow overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-left text-slate-600">
          <tr>
            <th class="px-4 py-2">ID</th>
            <th class="px-4 py-2">Subject</th>
            <th class="px-4 py-2">Type</th>
            <th class="px-4 py-2">Status</th>
            <th class="px-4 py-2">Sent</th>
            <th class="px-4 py-2">Completed</th>
            <th class="px-4 py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="env in envelopes" :key="env.id" class="border-t dark:border-slate-700">
            <td class="px-4 py-2 font-mono">#{{ env.id }}</td>
            <td class="px-4 py-2">{{ env.subject }}</td>
            <td class="px-4 py-2 text-xs">{{ env.document_type }}</td>
            <td class="px-4 py-2">
              <span class="text-xs px-2 py-0.5 rounded" :class="statusClass(env.status)">
                {{ env.status }}
              </span>
              <span v-if="env.fallback_wet_sign" class="ml-2 text-xs text-amber-600">wet-sign</span>
            </td>
            <td class="px-4 py-2 text-xs">{{ env.sent_at || '—' }}</td>
            <td class="px-4 py-2 text-xs">{{ env.completed_at || '—' }}</td>
            <td class="px-4 py-2 text-right">
              <button class="text-blue-600 text-sm" @click="viewAudit(env.id)">Audit</button>
            </td>
          </tr>
          <tr v-if="envelopes.length === 0">
            <td colspan="7" class="px-4 py-6 text-center text-slate-400">Belum ada envelope.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-slate-800 rounded shadow-lg p-6 w-[28rem]">
        <h3 class="font-medium mb-4">Buat Envelope Baru</h3>
        <form @submit.prevent="submitCreate" class="space-y-3">
          <div>
            <label class="block text-sm">Document Type</label>
            <select v-model="form.document_type" class="w-full border rounded px-2 py-1">
              <option v-for="t in docTypes" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm">Subject</label>
            <input v-model="form.subject" class="w-full border rounded px-2 py-1" required />
          </div>
          <div>
            <label class="block text-sm">Document Path</label>
            <input v-model="form.document_path" class="w-full border rounded px-2 py-1"
              placeholder="/storage/contracts/abc.pdf" required />
            <p class="text-xs text-slate-500 mt-1">
              Upload PDF dulu via /upload, lalu paste path di sini.
            </p>
          </div>
          <div>
            <label class="block text-sm">Expires At (opsional)</label>
            <input v-model="form.expires_at" type="date" class="w-full border rounded px-2 py-1" />
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-3 py-1 text-sm" @click="showCreate = false">Batal</button>
            <button class="px-3 py-1 bg-blue-600 text-white text-sm rounded">Buat</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Audit modal -->
    <div v-if="auditData" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-slate-800 rounded shadow-lg p-6 w-[40rem] max-h-[80vh] overflow-auto">
        <h3 class="font-medium mb-4">Audit Trail Envelope #{{ auditData.envelope?.id }}</h3>
        <h4 class="text-sm font-medium text-slate-600 mb-2">Signers</h4>
        <table class="w-full text-xs mb-4">
          <thead class="bg-slate-50">
            <tr>
              <th class="text-left px-2 py-1">Order</th>
              <th class="text-left px-2 py-1">Role</th>
              <th class="text-left px-2 py-1">Name</th>
              <th class="text-left px-2 py-1">Status</th>
              <th class="text-left px-2 py-1">Signed At</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in auditData.signers" :key="s.id" class="border-t">
              <td class="px-2 py-1">{{ s.sign_order }}</td>
              <td class="px-2 py-1">{{ s.signer_role }}</td>
              <td class="px-2 py-1">{{ s.full_name }}</td>
              <td class="px-2 py-1">{{ s.status }}</td>
              <td class="px-2 py-1">{{ s.signed_at || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <h4 class="text-sm font-medium text-slate-600 mb-2">Events</h4>
        <ul class="text-xs space-y-1">
          <li v-for="e in auditData.events" :key="e.id">
            <span class="font-mono text-slate-500">{{ e.event_at }}</span>
            <span class="ml-2 font-medium">{{ e.event_type }}</span>
            <span v-if="e.ip_address" class="ml-2 text-slate-500">from {{ e.ip_address }}</span>
          </li>
        </ul>
        <div class="text-right mt-4">
          <button class="px-3 py-1 text-sm" @click="auditData = null">Tutup</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/axios'

const statuses = ['DRAFT', 'SENT', 'PARTIALLY_SIGNED', 'COMPLETED', 'CANCELLED', 'EXPIRED', 'FAILED']
const docTypes = ['CONTRACT', 'CONTRACT_AMENDMENT', 'OFFER_LETTER', 'SEVERANCE', 'TRANSFER_LETTER', 'OTHER']

const envelopes    = ref([])
const statusFilter = ref('')
const typeFilter   = ref('')
const showCreate   = ref(false)
const auditData    = ref(null)
const form = ref({
  document_type: 'CONTRACT',
  subject: '',
  document_path: '',
  expires_at: '',
})

async function load() {
  const params = {}
  if (statusFilter.value) params.status = statusFilter.value
  if (typeFilter.value)   params.document_type = typeFilter.value
  const res = await api.get('/signing/envelopes', { params })
  envelopes.value = res.data?.data ?? []
}

function openCreate() {
  form.value = { document_type: 'CONTRACT', subject: '', document_path: '', expires_at: '' }
  showCreate.value = true
}

async function submitCreate() {
  try {
    await api.post('/signing/envelopes', form.value)
    showCreate.value = false
    await load()
  } catch (err) {
    alert('Gagal: ' + (err.response?.data?.message || err.message))
  }
}

async function viewAudit(id) {
  try {
    const res = await api.get(`/signing/envelopes/${id}/audit`)
    auditData.value = res.data?.data ?? null
  } catch (err) {
    alert('Audit fetch failed: ' + (err.response?.data?.message || err.message))
  }
}

function statusClass(s) {
  return {
    DRAFT:           'bg-slate-100 text-slate-700',
    SENT:            'bg-blue-100 text-blue-700',
    PARTIALLY_SIGNED:'bg-amber-100 text-amber-700',
    COMPLETED:       'bg-green-100 text-green-700',
    CANCELLED:       'bg-slate-100 text-slate-500',
    EXPIRED:         'bg-orange-100 text-orange-700',
    FAILED:          'bg-red-100 text-red-700',
  }[s] || 'bg-slate-100'
}

onMounted(load)
</script>