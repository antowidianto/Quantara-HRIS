<template>
  <div class="p-6 space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">API Keys & Developer Portal</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">
          Kelola API key untuk integrasi enterprise. Plain key hanya tampil sekali saat
          create / rotate — simpan baik-baik.
        </p>
      </div>
      <button class="px-4 py-2 rounded bg-blue-600 text-white text-sm" @click="openCreate">
        + Buat API Key
      </button>
    </header>

    <div class="bg-white dark:bg-slate-800 rounded shadow overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-700 text-left text-slate-600">
          <tr>
            <th class="px-4 py-2">Name</th>
            <th class="px-4 py-2">Prefix</th>
            <th class="px-4 py-2">Tier</th>
            <th class="px-4 py-2">Scopes</th>
            <th class="px-4 py-2">Last Used</th>
            <th class="px-4 py-2">Status</th>
            <th class="px-4 py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="k in keys" :key="k.id" class="border-t dark:border-slate-700">
            <td class="px-4 py-2 font-medium">
              {{ k.name }}
              <span v-if="k.is_sandbox" class="ml-1 text-xs text-amber-600">[sandbox]</span>
            </td>
            <td class="px-4 py-2 font-mono text-xs">{{ k.key_prefix }}…</td>
            <td class="px-4 py-2">
              <span class="text-xs px-2 py-0.5 rounded bg-slate-100">{{ k.tier }}</span>
              <span class="text-xs text-slate-500 ml-1">{{ k.rate_limit_per_minute }}/min</span>
            </td>
            <td class="px-4 py-2 text-xs">
              <span v-for="s in k.scopes" :key="s" class="inline-block bg-blue-50 text-blue-700 px-1 rounded mr-1">
                {{ s }}
              </span>
            </td>
            <td class="px-4 py-2 text-xs">{{ k.last_used_at || 'Never' }}</td>
            <td class="px-4 py-2">
              <span :class="k.revoked_at ? 'text-red-600' : 'text-green-600'">
                {{ k.revoked_at ? 'Revoked' : 'Active' }}
              </span>
            </td>
            <td class="px-4 py-2 text-right space-x-2 whitespace-nowrap">
              <button class="text-slate-600 text-sm" @click="loadUsage(k.id)">Usage</button>
              <button v-if="!k.revoked_at" class="text-blue-600 text-sm" @click="rotate(k.id)">Rotate</button>
              <button v-if="!k.revoked_at" class="text-red-600 text-sm" @click="revoke(k.id)">Revoke</button>
            </td>
          </tr>
          <tr v-if="keys.length === 0">
            <td colspan="7" class="px-4 py-6 text-center text-slate-400">Belum ada API key.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-slate-800 rounded shadow-lg p-6 w-[28rem] max-h-[80vh] overflow-auto">
        <h3 class="font-medium mb-4">Buat API Key Baru</h3>
        <form @submit.prevent="submitCreate" class="space-y-3">
          <div>
            <label class="block text-sm">Name</label>
            <input v-model="form.name" class="w-full border rounded px-2 py-1" required />
          </div>
          <div>
            <label class="block text-sm">Tier</label>
            <select v-model="form.tier" class="w-full border rounded px-2 py-1">
              <option value="BASIC">BASIC (60/min)</option>
              <option value="PRO">PRO (300/min)</option>
              <option value="ENTERPRISE">ENTERPRISE (1200/min)</option>
            </select>
          </div>
          <div>
            <label class="flex items-center text-sm">
              <input v-model="form.is_sandbox" type="checkbox" class="mr-2" />
              Sandbox key (akses ke test data)
            </label>
          </div>
          <div>
            <label class="block text-sm mb-1">Scopes</label>
            <div class="border rounded p-2 max-h-48 overflow-auto space-y-1">
              <label v-for="s in scopes" :key="s.scope" class="flex items-start text-xs">
                <input type="checkbox" :value="s.scope" v-model="form.scopes" class="mr-2 mt-0.5" />
                <span>
                  <span class="font-mono">{{ s.scope }}</span>
                  <span v-if="s.is_dangerous" class="ml-1 text-red-600">⚠</span>
                  <span class="block text-slate-500">{{ s.description }}</span>
                </span>
              </label>
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-3 py-1 text-sm" @click="showCreate = false">Batal</button>
            <button class="px-3 py-1 bg-blue-600 text-white text-sm rounded">Generate</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Plain key reveal modal -->
    <div v-if="newKey" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-slate-800 rounded shadow-lg p-6 w-[32rem]">
        <h3 class="font-medium mb-2 text-amber-700">Plain Key — Hanya Sekali Tampil</h3>
        <p class="text-sm text-slate-600 mb-3">
          Salin key ini sekarang. Setelah dialog ditutup, kamu tidak bisa melihatnya lagi
          (hanya hash yang tersimpan).
        </p>
        <div class="bg-slate-100 p-3 rounded font-mono text-sm break-all border">
          {{ newKey.plain_key }}
        </div>
        <div class="text-right mt-4">
          <button class="px-3 py-1 bg-blue-600 text-white text-sm rounded" @click="dismissNewKey">
            Saya Sudah Salin
          </button>
        </div>
      </div>
    </div>

    <!-- Usage modal -->
    <div v-if="usage" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-slate-800 rounded shadow-lg p-6 w-[36rem]">
        <h3 class="font-medium mb-3">API Usage (since {{ usage.since }})</h3>
        <div class="grid grid-cols-2 gap-3 mb-4">
          <div class="bg-slate-50 p-3 rounded">
            <div class="text-2xl font-semibold">{{ usage.total }}</div>
            <div class="text-xs text-slate-500">Total Requests</div>
          </div>
          <div class="bg-amber-50 p-3 rounded">
            <div class="text-2xl font-semibold text-amber-700">{{ usage.rate_limited }}</div>
            <div class="text-xs text-slate-500">Rate Limited</div>
          </div>
        </div>
        <h4 class="text-sm font-medium mb-2">Top Endpoints</h4>
        <table class="w-full text-xs mb-3">
          <thead class="bg-slate-50">
            <tr><th class="text-left px-2 py-1">Endpoint</th><th class="text-right px-2 py-1">Hits</th><th class="text-right px-2 py-1">Avg ms</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in usage.by_endpoint" :key="r.endpoint" class="border-t">
              <td class="px-2 py-1 font-mono">{{ r.endpoint }}</td>
              <td class="px-2 py-1 text-right">{{ r.hits }}</td>
              <td class="px-2 py-1 text-right">{{ Math.round(r.avg_ms) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="text-right">
          <button class="px-3 py-1 text-sm" @click="usage = null">Tutup</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'

const keys   = ref([])
const scopes = ref([])
const usage  = ref(null)
const newKey = ref(null)
const showCreate = ref(false)
const form = ref({ name: '', tier: 'BASIC', is_sandbox: false, scopes: [] })

async function load() {
  const [k, s] = await Promise.all([api.get('/api-keys'), api.get('/api-scopes')])
  keys.value   = k.data?.data ?? []
  scopes.value = s.data?.data ?? []
}

function openCreate() {
  form.value = { name: '', tier: 'BASIC', is_sandbox: false, scopes: [] }
  showCreate.value = true
}

async function submitCreate() {
  if (form.value.scopes.length === 0) {
    alert('Pilih minimal 1 scope')
    return
  }
  try {
    const res = await api.post('/api-keys', form.value)
    newKey.value = res.data?.data
    showCreate.value = false
    await load()
  } catch (err) {
    alert('Gagal: ' + (err.response?.data?.message || err.message))
  }
}

function dismissNewKey() {
  newKey.value = null
}

async function rotate(id) {
  if (! confirm('Rotate akan revoke key lama dan generate baru. Lanjut?')) return
  try {
    const res = await api.post(`/api-keys/${id}/rotate`, { reason: 'Manual rotation via UI' })
    newKey.value = res.data?.data
    await load()
  } catch (err) {
    alert('Rotate failed: ' + (err.response?.data?.message || err.message))
  }
}

async function revoke(id) {
  const reason = prompt('Alasan revoke?')
  if (!reason) return
  try {
    await api.post(`/api-keys/${id}/revoke`, { reason })
    await load()
  } catch (err) {
    alert('Revoke failed: ' + (err.response?.data?.message || err.message))
  }
}

async function loadUsage(id) {
  try {
    const res = await api.get(`/api-keys/${id}/usage`)
    usage.value = res.data?.data
  } catch (err) {
    alert('Usage fetch failed: ' + (err.response?.data?.message || err.message))
  }
}

onMounted(load)
</script>