<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import api from '@/api/client'

const router = useRouter()
const toast = useToast()
const loading = ref(true)
const tests = ref([])
const total = ref(0)
const page = ref(1)
const creating = ref(false)
const newTest = ref({ name: '', category: 'CUSTOM', duration_minutes: 30, passing_score_pct: 60 })
const showCreate = ref(false)

const CATEGORIES = { TPA: 'TPA', ENGLISH: 'Bahasa Inggris', DISC: 'Kepribadian DISC', TECHNICAL: 'Kompetensi Teknis', CUSTOM: 'Kustom' }

onMounted(load)

async function load() {
  try {
    const res = await api.get('/online-tests', { params: { page: page.value, per_page: 30 } })
    tests.value = res.data.data
    total.value = res.data.meta?.total || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat daftar test')
  } finally {
    loading.value = false
  }
}

async function create() {
  creating.value = true
  try {
    const res = await api.post('/online-tests', newTest.value)
    toast.success('Test dibuat')
    router.push({ name: 'online-test-detail', params: { id: res.data.data.id } })
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal membuat test')
  } finally {
    creating.value = false
  }
}

async function del(id) {
  if (! confirm('Yakin hapus test ini?')) return
  try {
    await api.delete(`/online-tests/${id}`)
    toast.success('Dihapus')
    tests.value = tests.value.filter((t) => t.id !== id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

const catColor = (c) => ({
  TPA: 'bg-blue-100 text-blue-700',
  ENGLISH: 'bg-emerald-100 text-emerald-700',
  DISC: 'bg-purple-100 text-purple-700',
  TECHNICAL: 'bg-amber-100 text-amber-700',
  CUSTOM: 'bg-slate-100 text-slate-700',
}[c] || 'bg-slate-100 text-slate-700')
</script>

<template>
  <div class="max-w-6xl mx-auto py-6 px-4">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Daftar Online Test</h1>
        <p class="text-sm text-slate-500 mt-0.5">{{ total }} test terdaftar</p>
      </div>
      <div class="flex gap-2">
        <router-link to="/recruitment/online-test-bank"
          class="px-4 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 hover:bg-slate-50">
          Bank Soal
        </router-link>
        <router-link to="/recruitment/online-test-assignments"
          class="px-4 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 hover:bg-slate-50">
          Penugasan
        </router-link>
        <button @click="showCreate = true"
          class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
          + Buat Test Baru
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-400">Memuat...</div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="t in tests" :key="t.id"
        class="bg-white border border-slate-200 rounded-xl p-4 hover:shadow-sm transition">
        <div class="flex items-start justify-between mb-2">
          <div>
            <div class="font-semibold text-slate-900">{{ t.name }}</div>
            <div class="text-xs text-slate-500 mt-0.5">{{ t.code }}</div>
          </div>
          <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="catColor(t.category)">
            {{ CATEGORIES[t.category] || t.category }}
          </span>
        </div>
        <div class="text-sm text-slate-600 space-x-3">
          <span>⏱ {{ t.duration_minutes }} menit</span>
          <span>Passing: {{ t.passing_score_pct }}%</span>
          <span v-if="!t.is_active" class="text-rose-600">Nonaktif</span>
        </div>
        <div class="mt-3 flex gap-2">
          <router-link :to="{ name: 'online-test-detail', params: { id: t.id } }"
            class="flex-1 text-center py-1.5 border border-slate-300 rounded-md text-sm hover:bg-slate-50">
            Edit & Soal
          </router-link>
          <button @click="del(t.id)"
            class="px-3 py-1.5 text-rose-600 border border-rose-200 rounded-md text-sm hover:bg-rose-50">
            Hapus
          </button>
        </div>
      </div>

      <div v-if="tests.length === 0"
        class="col-span-3 text-center py-12 text-slate-400">
        Belum ada test. Klik "Buat Test Baru" untuk memulai.
      </div>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showCreate = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
        <h2 class="text-lg font-bold mb-4">Buat Test Baru</h2>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium mb-1">Nama Test</label>
            <input v-model="newTest.name" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" required />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium mb-1">Kategori</label>
              <select v-model="newTest.category" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                <option v-for="(label, key) in CATEGORIES" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Durasi (menit)</label>
              <input v-model.number="newTest.duration_minutes" type="number" min="5" max="240" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Passing Score (%)</label>
              <input v-model.number="newTest.passing_score_pct" type="number" min="0" max="100" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" />
            </div>
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-4">
          <button @click="showCreate = false" class="px-4 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">Batal</button>
          <button @click="create" :disabled="creating || !newTest.name"
            class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
            {{ creating ? 'Menyimpan...' : 'Buat' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>