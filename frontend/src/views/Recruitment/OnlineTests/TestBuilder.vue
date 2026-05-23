<script setup>
/**
 * TestBuilder — HR admin page untuk kelola soal-soal satu test.
 * Fitur:
 *   - Edit setting test (nama, durasi, passing score, dll)
 *   - CRUD soal (MCQ, TF, Likert, Numeric, Short Answer)
 *   - Seed template built-in (TPA / English / DISC)
 *   - Import soal dari Question Bank
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import api from '@/api/client'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const testId = computed(() => Number(route.params.id))

const test = ref(null)
const questions = ref([])
const loading = ref(true)
const saving = ref(false)
const seeding = ref(false)

const showAddQ = ref(false)
const editingTest = ref(false)
const newQ = ref({ question_type: 'MCQ_SINGLE', category: '', prompt: '', choices: [{ key: 'A', text: '' }, { key: 'B', text: '' }, { key: 'C', text: '' }, { key: 'D', text: '' }], correct_answer: '', points: 1.0, disc_weight: '', explanation: '' })
const editSettings = ref({})

const TYPES = { MCQ_SINGLE: 'MCQ Pilihan Tunggal', MCQ_MULTI: 'MCQ Multi-Pilih', TRUE_FALSE: 'Benar/Salah', LIKERT_1_5: 'Likert (DISC)', NUMERIC: 'Numerik', SHORT_ANSWER: 'Essay/Short Answer' }
const CATEGORIES = { TPA_VERBAL: 'TPA Verbal', TPA_NUMERIK: 'TPA Numerik', TPA_LOGIKA: 'TPA Logika', ENGLISH: 'Bahasa Inggris', DISC: 'DISC', TECHNICAL: 'Teknis', GENERAL: 'Umum' }
const DISC_DIMS = ['D', 'I', 'S', 'C']

onMounted(load)

async function load() {
  try {
    const [tRes, qRes] = await Promise.all([
      api.get(`/online-tests/${testId.value}`),
      api.get(`/online-tests/${testId.value}/questions`),
    ])
    test.value = tRes.data.data
    questions.value = qRes.data.data || []
    editSettings.value = { ...test.value }
  } catch (e) {
    toast.error('Gagal memuat test')
  } finally {
    loading.value = false
  }
}

async function saveSettings() {
  saving.value = true
  try {
    await api.put(`/online-tests/${testId.value}`, editSettings.value)
    test.value = { ...test.value, ...editSettings.value }
    toast.success('Setting tersimpan')
    editingTest.value = false
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  } finally {
    saving.value = false
  }
}

async function seedTemplate() {
  if (questions.value.length > 0) {
    if (! confirm('Test sudah punya soal. Seed template akan menghapus soal yang ada terlebih dahulu. Lanjutkan?')) return
  }
  seeding.value = true
  try {
    const res = await api.post(`/online-tests/${testId.value}/seed-template`, { template: test.value.category })
    toast.success(`${res.data.data.seeded} soal template berhasil di-seed`)
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal seed template')
  } finally {
    seeding.value = false
  }
}

async function addQuestion() {
  try {
    const payload = { ...newQ.value }
    if (payload.question_type === 'TRUE_FALSE') {
      payload.correct_answer = payload.correct_answer === 'true'
    }
    if (payload.question_type === 'NUMERIC') {
      payload.correct_answer = Number(payload.correct_answer)
    }
    await api.post(`/online-tests/${testId.value}/questions`, payload)
    toast.success('Soal ditambahkan')
    showAddQ.value = false
    resetNewQ()
    await loadQuestions()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menambah soal')
  }
}

async function deleteQuestion(id) {
  if (! confirm('Hapus soal ini?')) return
  try {
    await api.delete(`/online-test-questions/${id}`)
    questions.value = questions.value.filter((q) => q.id !== id)
    toast.success('Soal dihapus')
  } catch (e) {
    toast.error('Gagal menghapus')
  }
}

async function loadQuestions() {
  const res = await api.get(`/online-tests/${testId.value}/questions`)
  questions.value = res.data.data || []
}

function resetNewQ() {
  newQ.value = { question_type: 'MCQ_SINGLE', category: '', prompt: '', choices: [{ key: 'A', text: '' }, { key: 'B', text: '' }, { key: 'C', text: '' }, { key: 'D', text: '' }], correct_answer: '', points: 1.0, disc_weight: '', explanation: '' }
}
</script>

<template>
  <div class="max-w-5xl mx-auto py-6 px-4">
    <div class="flex items-center gap-3 mb-6">
      <router-link to="/recruitment/online-tests" class="text-sm text-slate-500 hover:text-indigo-600">← Daftar Test</router-link>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-400">Memuat...</div>

    <template v-else-if="test">
      <!-- Test settings -->
      <div class="bg-white border border-slate-200 rounded-xl p-6 mb-6">
        <div class="flex items-start justify-between">
          <div>
            <h1 class="text-xl font-bold text-slate-900">{{ test.name }}</h1>
            <div class="text-sm text-slate-500 mt-0.5">
              {{ test.category }} · {{ test.duration_minutes }} menit · Passing {{ test.passing_score_pct }}%
              · {{ questions.length }} soal
            </div>
          </div>
          <div class="flex gap-2">
            <button @click="seeding || seedTemplate()" :disabled="seeding"
              class="px-3 py-1.5 border border-slate-300 rounded-md text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50">
              {{ seeding ? '...' : '🌱 Seed Template' }}
            </button>
            <button @click="editingTest = !editingTest"
              class="px-3 py-1.5 border border-indigo-300 text-indigo-700 rounded-md text-sm hover:bg-indigo-50">
              ⚙ Setting
            </button>
          </div>
        </div>

        <div v-if="editingTest" class="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4">
          <div class="col-span-2">
            <label class="block text-xs font-medium mb-1">Nama Test</label>
            <input v-model="editSettings.name" class="w-full px-3 py-2 border rounded text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium mb-1">Durasi (menit)</label>
            <input v-model.number="editSettings.duration_minutes" type="number" min="1" class="w-full px-3 py-2 border rounded text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium mb-1">Passing Score (%)</label>
            <input v-model.number="editSettings.passing_score_pct" type="number" min="0" max="100" class="w-full px-3 py-2 border rounded text-sm" />
          </div>
          <div class="col-span-2">
            <label class="block text-xs font-medium mb-1">Petunjuk (HTML)</label>
            <textarea v-model="editSettings.instructions_html" rows="3" class="w-full px-3 py-2 border rounded text-sm" placeholder="<p>Petunjuk...</p>"></textarea>
          </div>
          <div class="flex items-center gap-4 col-span-2">
            <label class="flex items-center gap-1.5 text-sm">
              <input type="checkbox" v-model="editSettings.shuffle_questions" :true-value="1" :false-value="0" /> Acak urutan soal
            </label>
            <label class="flex items-center gap-1.5 text-sm">
              <input type="checkbox" v-model="editSettings.show_result_to_candidate" :true-value="1" :false-value="0" /> Tampilkan hasil ke kandidat
            </label>
          </div>
          <div class="col-span-2 flex justify-end gap-2">
            <button @click="editingTest = false" class="px-3 py-1.5 border rounded text-sm">Batal</button>
            <button @click="saveSettings" :disabled="saving" class="px-4 py-1.5 bg-indigo-600 text-white rounded text-sm disabled:opacity-50">
              {{ saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Questions list -->
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-base font-semibold text-slate-900">Soal ({{ questions.length }})</h2>
        <button @click="showAddQ = true"
          class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700">
          + Tambah Soal
        </button>
      </div>

      <div class="space-y-3">
        <div v-for="(q, idx) in questions" :key="q.id" class="bg-white border border-slate-200 rounded-xl p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
              <div class="text-xs text-slate-400 mb-1">
                {{ idx + 1 }}. {{ TYPES[q.question_type] || q.question_type }}
                <span v-if="q.category" class="ml-1 px-1.5 py-0.5 bg-slate-100 rounded">{{ q.category }}</span>
                <span v-if="q.disc_weight" class="ml-1 text-purple-600">DISC:{{ q.disc_weight }}</span>
                <span class="ml-2">{{ q.points }} pts</span>
              </div>
              <div class="text-sm text-slate-900 whitespace-pre-wrap">{{ q.prompt }}</div>
              <div v-if="q.choices && q.choices.length" class="mt-1 space-y-0.5">
                <div v-for="c in q.choices" :key="c.key" class="text-xs text-slate-600 flex gap-1.5">
                  <span class="font-semibold"
                    :class="(q.question_type === 'MCQ_SINGLE' ? q.correct_answer === c.key : (Array.isArray(q.correct_answer) && q.correct_answer.includes(c.key))) ? 'text-emerald-600' : ''">
                    {{ c.key }}.
                  </span>
                  {{ c.text }}
                </div>
              </div>
              <div v-if="q.question_type === 'TRUE_FALSE'" class="text-xs mt-1 text-emerald-600">
                Jawaban: {{ q.correct_answer ? 'Benar' : 'Salah' }}
              </div>
              <div v-if="q.question_type === 'NUMERIC'" class="text-xs mt-1 text-emerald-600">
                Jawaban: {{ q.correct_answer }}
              </div>
              <div v-if="q.explanation" class="text-xs mt-1 text-slate-500 italic">{{ q.explanation }}</div>
            </div>
            <button @click="deleteQuestion(q.id)" class="text-rose-500 text-xs hover:text-rose-700">Hapus</button>
          </div>
        </div>
        <div v-if="questions.length === 0" class="text-center py-8 text-slate-400 bg-slate-50 rounded-xl border-2 border-dashed border-slate-200">
          Belum ada soal. Klik "Tambah Soal" atau "Seed Template".
        </div>
      </div>

      <!-- Add question modal -->
      <div v-if="showAddQ" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto" @click.self="showAddQ = false">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl my-4 p-6">
          <h2 class="text-lg font-bold mb-4">Tambah Soal Baru</h2>
          <div class="space-y-3 text-sm">
            <div class="grid grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-medium mb-1">Tipe Soal</label>
                <select v-model="newQ.question_type" class="w-full px-3 py-2 border rounded">
                  <option v-for="(label, key) in TYPES" :key="key" :value="key">{{ label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium mb-1">Kategori</label>
                <select v-model="newQ.category" class="w-full px-3 py-2 border rounded">
                  <option value="">— Umum —</option>
                  <option v-for="(label, key) in CATEGORIES" :key="key" :value="key">{{ label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium mb-1">Poin</label>
                <input v-model.number="newQ.points" type="number" step="0.5" min="0.5" class="w-full px-3 py-2 border rounded" />
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium mb-1">Soal / Pertanyaan</label>
              <textarea v-model="newQ.prompt" rows="3" class="w-full px-3 py-2 border rounded" placeholder="Tulis soal di sini..."></textarea>
            </div>

            <!-- MCQ choices -->
            <div v-if="['MCQ_SINGLE','MCQ_MULTI'].includes(newQ.question_type)">
              <label class="block text-xs font-medium mb-1">Pilihan Jawaban</label>
              <div v-for="c in newQ.choices" :key="c.key" class="flex items-center gap-2 mb-1">
                <span class="w-6 font-bold text-slate-600">{{ c.key }}</span>
                <input v-model="c.text" class="flex-1 px-2 py-1.5 border rounded text-sm" :placeholder="`Pilihan ${c.key}`" />
              </div>
              <div class="mt-2">
                <label class="text-xs font-medium">Kunci Jawaban</label>
                <div v-if="newQ.question_type === 'MCQ_SINGLE'" class="flex gap-2 mt-1">
                  <label v-for="c in newQ.choices" :key="c.key" class="flex items-center gap-1">
                    <input type="radio" :value="c.key" v-model="newQ.correct_answer" />
                    {{ c.key }}
                  </label>
                </div>
                <div v-else class="flex gap-2 mt-1 flex-wrap">
                  <label v-for="c in newQ.choices" :key="c.key" class="flex items-center gap-1">
                    <input type="checkbox" :value="c.key"
                      :checked="Array.isArray(newQ.correct_answer) && newQ.correct_answer.includes(c.key)"
                      @change="(e) => {
                        if (!Array.isArray(newQ.correct_answer)) newQ.correct_answer = []
                        if (e.target.checked) newQ.correct_answer.push(c.key)
                        else newQ.correct_answer = newQ.correct_answer.filter(x => x !== c.key)
                      }" />
                    {{ c.key }}
                  </label>
                </div>
              </div>
            </div>

            <!-- TRUE_FALSE -->
            <div v-else-if="newQ.question_type === 'TRUE_FALSE'">
              <label class="text-xs font-medium block mb-1">Jawaban Benar</label>
              <select v-model="newQ.correct_answer" class="px-3 py-2 border rounded text-sm">
                <option value="true">Benar</option>
                <option value="false">Salah</option>
              </select>
            </div>

            <!-- NUMERIC -->
            <div v-else-if="newQ.question_type === 'NUMERIC'">
              <label class="text-xs font-medium block mb-1">Jawaban Benar (angka)</label>
              <input v-model="newQ.correct_answer" type="number" step="any" class="px-3 py-2 border rounded text-sm" />
            </div>

            <!-- LIKERT DISC weight -->
            <div v-if="newQ.question_type === 'LIKERT_1_5'">
              <label class="text-xs font-medium block mb-1">DISC Dimension</label>
              <div class="flex gap-2">
                <label v-for="d in DISC_DIMS" :key="d">
                  <input type="radio" :value="d" v-model="newQ.disc_weight" /> {{ d }}
                </label>
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium mb-1">Pembahasan (opsional)</label>
              <input v-model="newQ.explanation" class="w-full px-3 py-2 border rounded text-sm" placeholder="Penjelasan jawaban yang benar" />
            </div>
          </div>
          <div class="flex justify-end gap-2 mt-4">
            <button @click="showAddQ = false; resetNewQ()" class="px-4 py-2 border rounded text-sm">Batal</button>
            <button @click="addQuestion" :disabled="!newQ.prompt"
              class="px-4 py-2 bg-emerald-600 text-white rounded text-sm font-medium disabled:opacity-50">
              Tambah Soal
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>