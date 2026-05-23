<script setup>
/**
 * Halaman ambil tes online untuk kandidat.
 *
 * Flow:
 *   1. Detail assignment (instructions, durasi, max attempts) → tombol Mulai.
 *   2. Setelah Mulai, panggil POST /tests/{id}/start → dapat list soal +
 *      attempt_id + expires_at. Lock layout fullscreen.
 *   3. Render satu soal per layar dengan timer countdown global.
 *   4. Auto-save ke backend setiap user pilih jawaban (debounced).
 *   5. Anti-cheat: detect tab switch / blur / fullscreen exit / paste,
 *      log via POST /attempts/{id}/event.
 *   6. Submit: POST /attempts/{id}/submit → redirect ke /result.
 *
 * Pakai candidate JWT (auto-handled oleh candidateApi).
 */
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { useCandidateAuthStore, candidateApi } from '@/stores/candidateAuth'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const store = useCandidateAuthStore()
const api = candidateApi()

const slug = computed(() => route.params.slug)
const assignmentId = computed(() => Number(route.params.id))

const loading = ref(true)
const stage = ref('LANDING') // LANDING | RUNNING | SUBMITTED | RESULT
const assignment = ref(null)
const test = ref(null)
const attempt = ref(null)
const questions = ref([])
const previousAttempts = ref([])

const currentIdx = ref(0)
const responses = ref({})
const timer = ref(0) // detik tersisa
let timerInterval = null
const submitting = ref(false)
const saveTimeouts = {}

// ─── Anti-cheat state ────────────────────────────────────────
const tabSwitches = ref(0)
const fullscreenExits = ref(0)

onMounted(async () => {
  if (! store.isAuthenticated) {
    return router.push({ name: 'candidate-login', params: { slug: slug.value } })
  }
  await fetchAssignment()
})

onBeforeUnmount(() => {
  cleanupAntiCheat()
  if (timerInterval) clearInterval(timerInterval)
})

async function fetchAssignment() {
  try {
    const res = await api.get(`/careers/account/tests/${assignmentId.value}`)
    assignment.value = res.data.data.assignment
    test.value = res.data.data.test
    previousAttempts.value = res.data.data.attempts || []

    // Kalau sudah completed, langsung tunjukkan hasil
    if (assignment.value.status === 'COMPLETED' || assignment.value.status === 'EXPIRED') {
      stage.value = 'SUBMITTED'
    }
    // Kalau ada attempt IN_PROGRESS, auto-resume
    const ip = previousAttempts.value.find((a) => a.status === 'IN_PROGRESS')
    if (ip) {
      await beginAttempt(true)
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat tes')
  } finally {
    loading.value = false
  }
}

async function beginAttempt(resume = false) {
  try {
    const res = resume
      ? await api.get(`/careers/account/attempts/${previousAttempts.value.find((a) => a.status === 'IN_PROGRESS').id}`)
      : await api.post(`/careers/account/tests/${assignmentId.value}/start`)
    const d = res.data.data
    attempt.value = d.attempt
    questions.value = d.questions
    test.value = d.test || test.value

    // Pre-fill responses dari saved (kalau resume)
    questions.value.forEach((q) => {
      if (q.response !== null && q.response !== undefined) {
        responses.value[q.answer_id] = q.response
      }
    })

    timer.value = attempt.value.time_remaining_sec
    stage.value = 'RUNNING'
    setupTimer()
    setupAntiCheat()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memulai tes')
  }
}

function setupTimer() {
  if (timerInterval) clearInterval(timerInterval)
  timerInterval = setInterval(() => {
    timer.value = Math.max(0, timer.value - 1)
    if (timer.value === 0) {
      clearInterval(timerInterval)
      toast.warning('Waktu habis. Mengirim jawaban...')
      submit(true)
    }
  }, 1000)
}

const formattedTime = computed(() => {
  const m = Math.floor(timer.value / 60)
  const s = timer.value % 60
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
})

const currentQuestion = computed(() => questions.value[currentIdx.value] || null)
const totalQuestions = computed(() => questions.value.length)
const answeredCount = computed(() => Object.keys(responses.value).filter((k) => {
  const v = responses.value[k]
  if (v === null || v === undefined) return false
  if (Array.isArray(v) && v.length === 0) return false
  if (typeof v === 'string' && v.trim() === '') return false
  return true
}).length)

function setResponse(answerId, value) {
  responses.value[answerId] = value
  // Debounced save (300ms)
  if (saveTimeouts[answerId]) clearTimeout(saveTimeouts[answerId])
  saveTimeouts[answerId] = setTimeout(() => saveAnswer(answerId, value), 300)
}

async function saveAnswer(answerId, value) {
  if (! attempt.value) return
  try {
    await api.post(`/careers/account/attempts/${attempt.value.id}/answer`, {
      answer_id: answerId,
      response: value,
      time_spent_sec: 0,
    })
  } catch (e) {
    // Diam — auto-retry akan terjadi di submit
    console.warn('Save answer failed:', e.response?.data?.message)
  }
}

function next() {
  if (currentIdx.value < totalQuestions.value - 1) currentIdx.value++
}
function prev() {
  if (currentIdx.value > 0) currentIdx.value--
}
function goTo(idx) {
  currentIdx.value = idx
}

async function submit(force = false) {
  if (submitting.value) return
  if (! force && answeredCount.value < totalQuestions.value) {
    if (! confirm(`Anda baru menjawab ${answeredCount.value} dari ${totalQuestions.value} soal. Tetap submit?`)) {
      return
    }
  }
  submitting.value = true
  try {
    await api.post(`/careers/account/attempts/${attempt.value.id}/submit`)
    if (timerInterval) clearInterval(timerInterval)
    cleanupAntiCheat()
    toast.success('Jawaban berhasil dikirim')
    router.push({
      name: 'candidate-test-result',
      params: { slug: slug.value, attemptId: attempt.value.id },
    })
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal submit')
    submitting.value = false
  }
}

// ─── Anti-cheat (best effort) ────────────────────────────────

function setupAntiCheat() {
  document.addEventListener('visibilitychange', onVisibilityChange)
  document.addEventListener('fullscreenchange', onFullscreenChange)
  window.addEventListener('blur', onBlur)
  document.addEventListener('contextmenu', preventContext)
  document.addEventListener('copy', preventCopyPaste)
  document.addEventListener('paste', onPaste)
  // Try fullscreen
  const el = document.documentElement
  if (el.requestFullscreen) el.requestFullscreen().catch(() => {})
}

function cleanupAntiCheat() {
  document.removeEventListener('visibilitychange', onVisibilityChange)
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  window.removeEventListener('blur', onBlur)
  document.removeEventListener('contextmenu', preventContext)
  document.removeEventListener('copy', preventCopyPaste)
  document.removeEventListener('paste', onPaste)
  if (document.fullscreenElement) document.exitFullscreen().catch(() => {})
}

function logEvent(eventType, meta = null) {
  if (! attempt.value) return
  api.post(`/careers/account/attempts/${attempt.value.id}/event`, { event_type: eventType, meta })
    .catch(() => { /* best effort */ })
}

function onVisibilityChange() {
  if (document.hidden) {
    tabSwitches.value++
    logEvent('TAB_SWITCH')
    toast.warning('Anda terdeteksi keluar dari tab. Aksi ini tercatat.')
  }
}
function onBlur() {
  // Sebagian device mengirim blur instead of visibilitychange
  // Untuk mencegah double-count, hanya log kalau bukan visibility
  if (! document.hidden) logEvent('TAB_SWITCH')
}
function onFullscreenChange() {
  if (! document.fullscreenElement) {
    fullscreenExits.value++
    logEvent('FULLSCREEN_EXIT')
  }
}
function preventContext(e) {
  e.preventDefault()
  logEvent('RIGHT_CLICK')
}
function preventCopyPaste(e) {
  e.preventDefault()
  logEvent('COPY_ATTEMPT')
}
function onPaste(e) {
  e.preventDefault()
  logEvent('PASTE_ATTEMPT')
}

// Warning kalau user coba refresh / navigate away
function beforeUnloadHandler(e) {
  if (stage.value === 'RUNNING') {
    e.preventDefault()
    e.returnValue = ''
  }
}
watch(stage, (s) => {
  if (s === 'RUNNING') window.addEventListener('beforeunload', beforeUnloadHandler)
  else window.removeEventListener('beforeunload', beforeUnloadHandler)
})
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <div v-if="loading" class="flex items-center justify-center h-[60vh] text-slate-500">
      Memuat...
    </div>

    <!-- LANDING: Detail test sebelum mulai -->
    <div v-else-if="stage === 'LANDING'" class="max-w-3xl mx-auto py-8 px-4">
      <router-link :to="{ name: 'candidate-dashboard', params: { slug } }"
        class="text-sm text-slate-500 hover:text-indigo-600 mb-4 inline-block">
        ← Kembali ke Dashboard
      </router-link>

      <div class="bg-white rounded-2xl border border-slate-200 p-8">
        <h1 class="text-2xl font-bold text-slate-900">{{ test?.name }}</h1>
        <div class="mt-1 text-sm text-slate-500">
          <span class="px-2 py-0.5 bg-slate-100 rounded text-xs font-medium mr-2">
            {{ test?.category }}
          </span>
          Durasi: {{ test?.duration_minutes }} menit
          · Passing score: {{ test?.passing_score_pct }}%
        </div>

        <div v-if="test?.description" class="mt-4 text-sm text-slate-700">
          {{ test.description }}
        </div>

        <div v-if="test?.instructions_html" class="mt-4 prose max-w-none text-sm"
          v-html="test.instructions_html"></div>

        <div class="mt-6 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
          <p class="font-semibold mb-2">Sebelum mulai, perhatikan:</p>
          <ul class="list-disc list-inside space-y-1">
            <li>Test akan timer otomatis. Setelah waktu habis, jawaban tersimpan akan otomatis disubmit.</li>
            <li>Jangan refresh halaman atau pindah tab. Aksi ini akan tercatat dalam log anti-cheat.</li>
            <li>Pastikan koneksi internet stabil. Jawaban auto-save ketika Anda memilih.</li>
            <li>Anda akan masuk ke mode fullscreen. Keluar dari fullscreen akan tercatat.</li>
            <li>Sediakan waktu tenang & lokasi tidak terganggu sebelum mulai.</li>
          </ul>
        </div>

        <div v-if="previousAttempts.length" class="mt-6">
          <h3 class="text-sm font-semibold text-slate-700">Riwayat Percobaan</h3>
          <ul class="text-sm text-slate-600 mt-2 space-y-1">
            <li v-for="a in previousAttempts" :key="a.id">
              Percobaan #{{ a.attempt_number }} — {{ a.status }}
              <span v-if="a.percentage">· {{ a.percentage }}%</span>
              <span v-if="a.result === 'PASS'" class="text-emerald-600">· Lulus</span>
              <span v-else-if="a.result === 'FAIL'" class="text-rose-600">· Tidak Lulus</span>
            </li>
          </ul>
        </div>

        <div class="mt-6 flex justify-end">
          <button v-if="['PENDING','IN_PROGRESS'].includes(assignment?.status)"
            @click="beginAttempt(false)"
            class="px-6 py-3 bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700">
            Mulai Test
          </button>
          <span v-else class="text-slate-500">Test ini tidak tersedia.</span>
        </div>
      </div>
    </div>

    <!-- RUNNING: Render soal -->
    <div v-else-if="stage === 'RUNNING'" class="min-h-screen flex flex-col select-none">
      <!-- Top bar dengan timer + progress -->
      <div class="bg-white border-b border-slate-200 px-6 py-3 flex items-center justify-between sticky top-0 z-10">
        <div>
          <div class="text-sm font-semibold text-slate-700">{{ test?.name }}</div>
          <div class="text-xs text-slate-500">
            Soal {{ currentIdx + 1 }} / {{ totalQuestions }} ·
            {{ answeredCount }} terjawab
          </div>
        </div>
        <div class="flex items-center gap-3">
          <div class="text-2xl font-mono font-bold tabular-nums"
            :class="timer < 60 ? 'text-rose-600' : timer < 300 ? 'text-amber-600' : 'text-slate-900'">
            {{ formattedTime }}
          </div>
          <button @click="submit(false)" :disabled="submitting"
            class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 disabled:opacity-50">
            {{ submitting ? 'Mengirim...' : 'Submit' }}
          </button>
        </div>
      </div>

      <div class="flex-1 grid grid-cols-1 md:grid-cols-[1fr_240px] gap-4 p-4 max-w-7xl mx-auto w-full">
        <!-- Soal aktif -->
        <div class="bg-white rounded-xl border border-slate-200 p-6" v-if="currentQuestion">
          <div class="text-xs text-slate-400 mb-2">
            Soal {{ currentIdx + 1 }} · {{ currentQuestion.question_type }}
            <span v-if="currentQuestion.category">· {{ currentQuestion.category }}</span>
            <span v-if="currentQuestion.points">· {{ currentQuestion.points }} pts</span>
          </div>
          <div class="text-base text-slate-900 whitespace-pre-wrap mb-4">
            {{ currentQuestion.prompt }}
          </div>

          <!-- MCQ_SINGLE -->
          <div v-if="currentQuestion.question_type === 'MCQ_SINGLE'" class="space-y-2">
            <label v-for="c in currentQuestion.choices" :key="c.key"
              class="flex items-start gap-3 p-3 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50"
              :class="{ 'border-indigo-500 bg-indigo-50': responses[currentQuestion.answer_id] === c.key }">
              <input type="radio" :name="`q${currentQuestion.answer_id}`" :value="c.key"
                :checked="responses[currentQuestion.answer_id] === c.key"
                @change="setResponse(currentQuestion.answer_id, c.key)" class="mt-1" />
              <div class="flex-1">
                <span class="font-semibold mr-2">{{ c.key }}.</span>{{ c.text }}
              </div>
            </label>
          </div>

          <!-- MCQ_MULTI -->
          <div v-else-if="currentQuestion.question_type === 'MCQ_MULTI'" class="space-y-2">
            <p class="text-xs text-slate-500 mb-1">Pilih semua yang benar (boleh lebih dari satu)</p>
            <label v-for="c in currentQuestion.choices" :key="c.key"
              class="flex items-start gap-3 p-3 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50">
              <input type="checkbox" :value="c.key"
                :checked="(responses[currentQuestion.answer_id] || []).includes(c.key)"
                @change="(e) => {
                  const arr = [...(responses[currentQuestion.answer_id] || [])];
                  if (e.target.checked) arr.push(c.key);
                  else arr.splice(arr.indexOf(c.key), 1);
                  setResponse(currentQuestion.answer_id, arr);
                }" class="mt-1" />
              <div class="flex-1">
                <span class="font-semibold mr-2">{{ c.key }}.</span>{{ c.text }}
              </div>
            </label>
          </div>

          <!-- TRUE_FALSE -->
          <div v-else-if="currentQuestion.question_type === 'TRUE_FALSE'" class="flex gap-3">
            <button v-for="(label, val) in { true: 'Benar', false: 'Salah' }" :key="String(val)"
              @click="setResponse(currentQuestion.answer_id, val === 'true')"
              class="flex-1 py-3 border rounded-lg font-medium"
              :class="responses[currentQuestion.answer_id] === (val === 'true')
                ? 'bg-indigo-600 text-white border-indigo-600'
                : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50'">
              {{ label }}
            </button>
          </div>

          <!-- LIKERT_1_5 -->
          <div v-else-if="currentQuestion.question_type === 'LIKERT_1_5'" class="space-y-2">
            <p class="text-xs text-slate-500">1 = Sangat tidak setuju · 5 = Sangat setuju</p>
            <div class="flex gap-2 mt-2">
              <button v-for="n in 5" :key="n"
                @click="setResponse(currentQuestion.answer_id, n)"
                class="flex-1 py-3 border rounded-lg font-medium"
                :class="responses[currentQuestion.answer_id] === n
                  ? 'bg-indigo-600 text-white border-indigo-600'
                  : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50'">
                {{ n }}
              </button>
            </div>
          </div>

          <!-- NUMERIC -->
          <div v-else-if="currentQuestion.question_type === 'NUMERIC'">
            <input type="number" step="any"
              :value="responses[currentQuestion.answer_id] ?? ''"
              @input="(e) => setResponse(currentQuestion.answer_id, e.target.value === '' ? null : Number(e.target.value))"
              class="w-full px-4 py-3 border border-slate-300 rounded-lg text-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
              placeholder="Masukkan jawaban (angka)" />
          </div>

          <!-- SHORT_ANSWER -->
          <div v-else-if="currentQuestion.question_type === 'SHORT_ANSWER'">
            <textarea rows="6"
              :value="responses[currentQuestion.answer_id] ?? ''"
              @input="(e) => setResponse(currentQuestion.answer_id, e.target.value)"
              class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
              placeholder="Jawaban Anda..."></textarea>
            <p class="text-xs text-slate-400 mt-1">Akan dinilai manual oleh tim recruitment.</p>
          </div>

          <div class="flex justify-between mt-6">
            <button @click="prev" :disabled="currentIdx === 0"
              class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg disabled:opacity-50">
              ← Sebelumnya
            </button>
            <button @click="next" :disabled="currentIdx === totalQuestions - 1"
              class="px-4 py-2 bg-slate-700 text-white rounded-lg disabled:opacity-50">
              Berikutnya →
            </button>
          </div>
        </div>

        <!-- Sidebar: navigasi cepat ke soal -->
        <div class="bg-white rounded-xl border border-slate-200 p-4 h-fit md:sticky md:top-20">
          <div class="text-xs font-semibold text-slate-700 mb-2">Navigasi Soal</div>
          <div class="grid grid-cols-5 gap-1.5">
            <button v-for="(q, idx) in questions" :key="q.answer_id"
              @click="goTo(idx)"
              class="aspect-square rounded text-xs font-semibold border"
              :class="[
                idx === currentIdx
                  ? 'bg-indigo-600 text-white border-indigo-600'
                  : (responses[q.answer_id] !== undefined && responses[q.answer_id] !== null && responses[q.answer_id] !== '' && !(Array.isArray(responses[q.answer_id]) && responses[q.answer_id].length === 0))
                    ? 'bg-emerald-100 text-emerald-700 border-emerald-300'
                    : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'
              ]">
              {{ idx + 1 }}
            </button>
          </div>
          <div class="mt-3 text-xs text-slate-500 space-y-1">
            <div><span class="inline-block w-3 h-3 bg-emerald-100 border border-emerald-300 rounded mr-1"></span> Sudah dijawab</div>
            <div><span class="inline-block w-3 h-3 bg-white border border-slate-200 rounded mr-1"></span> Belum dijawab</div>
          </div>
          <div v-if="tabSwitches > 0 || fullscreenExits > 0"
            class="mt-3 p-2 bg-rose-50 border border-rose-200 rounded text-xs text-rose-700">
            ⚠ Anti-cheat: {{ tabSwitches }} pindah tab, {{ fullscreenExits }} keluar fullscreen
          </div>
        </div>
      </div>
    </div>

    <div v-else class="text-center py-20 text-slate-500">
      Mengarahkan ke halaman hasil...
    </div>
  </div>
</template>