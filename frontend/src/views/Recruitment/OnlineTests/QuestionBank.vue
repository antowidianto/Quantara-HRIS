<script setup>
/**
 * QuestionBank — bank soal reusable untuk HR.
 * Bisa dipakai lintas test: TPA, English, DISC, Technical, General.
 */
import { ref, onMounted } from 'vue'
import { useToast } from 'vue-toastification'
import api from '@/api/client'

const toast = useToast()
const loading = ref(true)
const categories = ref({})
const items = ref([])
const filters = ref({
  q: '',
  category: '',
  difficulty: '',
  question_type: '',
})
const showCreate = ref(false)
const saving = ref(false)
const form = ref({
  category: 'GENERAL',
  difficulty: 'MEDIUM',
  question_type: 'MCQ_SINGLE',
  prompt: '',
  choices: [{ key: 'A', text: '' }, { key: 'B', text: '' }, { key: 'C', text: '' }, { key: 'D', text: '' }],
  correct_answer: '',
  disc_weight: '',
  explanation: '',
  tags: [],
})

const QUESTION_TYPES = {
  MCQ_SINGLE: 'MCQ Pilihan Tunggal',
  MCQ_MULTI: 'MCQ Multi-Pilih',
  TRUE_FALSE: 'Benar/Salah',
  LIKERT_1_5: 'Likert DISC',
  NUMERIC: 'Numerik',
  SHORT_ANSWER: 'Essay',
}

const DIFFICULTIES = ['EASY', 'MEDIUM', 'HARD']
const DISC_DIMS = ['D', 'I', 'S', 'C']

onMounted(async () => {
  await Promise.all([loadMeta(), load()])
  loading.value = false
})

async function loadMeta() {
  try {
    const res = await api.get('/online-test-questions-bank/categories')
    categories.value = res.data.data.question_categories || {}
  } catch (_) {}
}

async function load() {
  try {
    const res = await api.get('/online-test-questions-bank', { params: filters.value })
    items.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat bank soal')
  }
}

async function save() {
  saving.value = true
  try {
    const payload = { ...form.value, tags: normalizeTags(form.value.tags) }
    if (payload.question_type === 'TRUE_FALSE') payload.correct_answer = payload.correct_answer === 'true'
    if (payload.question_type === 'NUMERIC') payload.correct_answer = Number(payload.correct_answer)
    await api.post('/online-test-questions-bank', payload)
    toast.success('Soal bank berhasil ditambahkan')
    showCreate.value = false
    resetForm()
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menambah bank soal')
  } finally {
    saving.value = false
  }
}

async function remove(id) {
  if (!confirm('Hapus soal bank ini?')) return
  try {
    await api.delete(`/online-test-questions-bank/${id}`)
    toast.success('Soal bank dihapus')
    items.value = items.value.filter((i) => i.id !== id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

function resetForm() {
  form.value = {
    category: 'GENERAL',
    difficulty: 'MEDIUM',
    question_type: 'MCQ_SINGLE',
    prompt: '',
    choices: [{ key: 'A', text: '' }, { key: 'B', text: '' }, { key: 'C', text: '' }, { key: 'D', text: '' }],
    correct_answer: '',
    disc_weight: '',
    explanation: '',
    tags: [],
  }
}

function normalizeTags(tags) {
  if (Array.isArray(tags)) return tags.filter(Boolean)
  return String(tags || '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
}
</script>

<template>
  <div class="max-w-6xl mx-auto py-6 px-4">
    <div class="flex items-center justify-between mb-5">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Bank Soal Online Test</h1>
        <p class="text-sm text-slate-500">Reusable lintas TPA, English, DISC, dan test teknis.</p>
      </div>
      <button @click="showCreate = true"
        class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
        + Tambah Soal Bank
      </button>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl p-4 mb-4 grid grid-cols-1 md:grid-cols-4 gap-3">
      <input v-model="filters.q" @keyup.enter="load" placeholder="Cari prompt..."
        class="px-3 py-2 border border-slate-300 rounded-lg text-sm" />
      <select v-model="filters.category" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Semua kategori</option>
        <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
      </select>
      <select v-model="filters.difficulty" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
        <option value="">Semua level</option>
        <option v-for="d in DIFFICULTIES" :key="d" :value="d">{{ d }}</option>
      </select>
      <div class="flex gap-2">
        <select v-model="filters.question_type" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm">
          <option value="">Semua tipe</option>
          <option v-for="(label, key) in QUESTION_TYPES" :key="key" :value="key">{{ label }}</option>
        </select>
        <button @click="load" class="px-4 py-2 border border-slate-300 rounded-lg text-sm hover:bg-slate-50">Filter</button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-400">Memuat...</div>
    <div v-else class="space-y-3">
      <div v-for="q in items" :key="q.id" class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="flex justify-between gap-3">
          <div class="flex-1">
            <div class="text-xs text-slate-400 mb-1">
              {{ q.category }} · {{ q.difficulty }} · {{ QUESTION_TYPES[q.question_type] || q.question_type }}
              <span v-if="q.disc_weight" class="text-purple-600"> · DISC:{{ q.disc_weight }}</span>
            </div>
            <div class="text-sm text-slate-900 whitespace-pre-wrap">{{ q.prompt }}</div>
            <div v-if="q.choices?.length" class="mt-2 text-xs space-y-1">
              <div v-for="c in q.choices" :key="c.key">
                <span class="font-semibold">{{ c.key }}.</span> {{ c.text }}
              </div>
            </div>
            <div v-if="q.tags?.length" class="mt-2 flex flex-wrap gap-1">
              <span v-for="tag in q.tags" :key="tag" class="px-2 py-0.5 bg-slate-100 rounded text-[11px] text-slate-600">{{ tag }}</span>
            </div>
          </div>
          <button @click="remove(q.id)" class="text-rose-500 text-xs hover:text-rose-700">Hapus</button>
        </div>
      </div>

      <div v-if="items.length === 0" class="text-center py-12 text-slate-400 bg-slate-50 rounded-xl border-2 border-dashed border-slate-200">
        Belum ada soal di bank.
      </div>
    </div>

    <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto" @click.self="showCreate = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl my-4 p-6">
        <h2 class="text-lg font-bold mb-4">Tambah Soal ke Bank</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
          <div>
            <label class="block text-xs font-medium mb-1">Kategori</label>
            <select v-model="form.category" class="w-full px-3 py-2 border rounded">
              <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium mb-1">Level</label>
            <select v-model="form.difficulty" class="w-full px-3 py-2 border rounded">
              <option v-for="d in DIFFICULTIES" :key="d" :value="d">{{ d }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium mb-1">Tipe</label>
            <select v-model="form.question_type" class="w-full px-3 py-2 border rounded">
              <option v-for="(label, key) in QUESTION_TYPES" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>
          <div class="md:col-span-3">
            <label class="block text-xs font-medium mb-1">Prompt</label>
            <textarea v-model="form.prompt" rows="3" class="w-full px-3 py-2 border rounded" />
          </div>

          <template v-if="['MCQ_SINGLE', 'MCQ_MULTI'].includes(form.question_type)">
            <div class="md:col-span-3">
              <label class="block text-xs font-medium mb-1">Pilihan Jawaban</label>
              <div v-for="c in form.choices" :key="c.key" class="flex gap-2 mb-2">
                <span class="w-6 text-sm font-bold">{{ c.key }}</span>
                <input v-model="c.text" class="flex-1 px-3 py-2 border rounded text-sm" />
              </div>
            </div>
          </template>

          <div v-if="form.question_type === 'LIKERT_1_5'" class="md:col-span-3">
            <label class="block text-xs font-medium mb-1">DISC Dimension</label>
            <div class="flex gap-4">
              <label v-for="d in DISC_DIMS" :key="d"><input type="radio" :value="d" v-model="form.disc_weight" /> {{ d }}</label>
            </div>
          </div>

          <div class="md:col-span-3">
            <label class="block text-xs font-medium mb-1">Kunci Jawaban / Nilai Referensi</label>
            <input v-model="form.correct_answer" class="w-full px-3 py-2 border rounded text-sm" placeholder="Untuk MCQ: A / A,C · TRUE_FALSE: true/false · NUMERIC: 42" />
          </div>

          <div class="md:col-span-3">
            <label class="block text-xs font-medium mb-1">Tags (pisahkan koma)</label>
            <input v-model="form.tags" class="w-full px-3 py-2 border rounded text-sm" placeholder="verbal, sinonim, fresh-grad" />
          </div>

          <div class="md:col-span-3">
            <label class="block text-xs font-medium mb-1">Pembahasan</label>
            <textarea v-model="form.explanation" rows="2" class="w-full px-3 py-2 border rounded text-sm" />
          </div>
        </div>

        <div class="flex justify-end gap-2 mt-4">
          <button @click="showCreate = false; resetForm()" class="px-4 py-2 border rounded text-sm">Batal</button>
          <button @click="save" :disabled="saving || !form.prompt"
            class="px-4 py-2 bg-indigo-600 text-white rounded text-sm disabled:opacity-50">
            {{ saving ? 'Menyimpan...' : 'Simpan ke Bank' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>