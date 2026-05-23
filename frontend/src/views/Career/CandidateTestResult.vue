<script setup>
/**
 * Halaman hasil tes online untuk kandidat.
 *
 * Tampilkan:
 *   - Skor total + persentase
 *   - Status PASS/FAIL/REVIEW
 *   - Breakdown per kategori (untuk multi-section TPA)
 *   - DISC tally bar chart sederhana (kalau test category=DISC)
 *   - Review jawaban (kalau test.show_result_to_candidate=1)
 *
 * Kalau backend hide hasil (show_result_to_candidate=0), kita tampilkan
 * pesan "Hasil sedang ditinjau, akan dikirim via email".
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { useCandidateAuthStore, candidateApi } from '@/stores/candidateAuth'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const store = useCandidateAuthStore()
const api = candidateApi()

const slug = computed(() => route.params.slug)
const attemptId = computed(() => Number(route.params.attemptId))
const loading = ref(true)
const result = ref(null)

onMounted(async () => {
  if (! store.isAuthenticated) {
    return router.push({ name: 'candidate-login', params: { slug: slug.value } })
  }
  try {
    const res = await api.get(`/careers/account/attempts/${attemptId.value}/result`)
    result.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat hasil')
  } finally {
    loading.value = false
  }
})

const resultLabel = computed(() => ({
  PASS: 'Lulus',
  FAIL: 'Tidak Lulus',
  REVIEW: 'Sedang Ditinjau',
  PENDING: 'Sedang Diproses',
}[result.value?.attempt?.result] || result.value?.attempt?.result))

const resultColor = computed(() => ({
  PASS: 'bg-emerald-100 text-emerald-800 border-emerald-300',
  FAIL: 'bg-rose-100 text-rose-800 border-rose-300',
  REVIEW: 'bg-amber-100 text-amber-800 border-amber-300',
  PENDING: 'bg-slate-100 text-slate-700 border-slate-300',
}[result.value?.attempt?.result] || 'bg-slate-100 text-slate-700 border-slate-300'))

const showDetails = computed(() => result.value?.test?.show_result_to_candidate === true)

const discMax = computed(() => {
  const d = result.value?.attempt?.disc_breakdown
  if (! d) return 0
  return Math.max(d.D || 0, d.I || 0, d.S || 0, d.C || 0, 1)
})

const dominantDisc = computed(() => {
  const d = result.value?.attempt?.disc_breakdown
  if (! d) return null
  const arr = [{ k: 'D', v: d.D || 0 }, { k: 'I', v: d.I || 0 }, { k: 'S', v: d.S || 0 }, { k: 'C', v: d.C || 0 }]
  arr.sort((a, b) => b.v - a.v)
  return arr[0]
})

const discDescription = (key) => ({
  D: 'Dominance — tegas, berorientasi hasil, suka tantangan.',
  I: 'Influence — komunikatif, persuasif, suka berinteraksi.',
  S: 'Steadiness — sabar, suportif, menyukai stabilitas.',
  C: 'Conscientiousness — analitis, teliti, mengutamakan akurasi.',
}[key] || '')

function fmtPct(v) {
  if (v === null || v === undefined) return '-'
  return Number(v).toFixed(1) + '%'
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 py-8 px-4">
    <div class="max-w-3xl mx-auto">
      <router-link :to="{ name: 'candidate-dashboard', params: { slug } }"
        class="text-sm text-slate-500 hover:text-indigo-600 mb-4 inline-block">
        ← Kembali ke Dashboard
      </router-link>

      <div v-if="loading" class="text-center py-12 text-slate-500">Memuat...</div>

      <div v-else-if="result" class="space-y-4">
        <!-- Hero summary -->
        <div class="bg-white rounded-2xl border border-slate-200 p-8">
          <div class="text-sm text-slate-500">{{ result.test?.name }}</div>
          <h1 class="text-2xl font-bold text-slate-900 mt-1">Hasil Tes Anda</h1>

          <div class="mt-4 inline-block px-4 py-2 rounded-lg border font-semibold"
            :class="resultColor">
            {{ resultLabel }}
          </div>

          <div v-if="showDetails && result.attempt.result !== 'REVIEW'" class="mt-6 grid grid-cols-3 gap-4">
            <div class="bg-slate-50 rounded-lg p-4 text-center">
              <div class="text-xs text-slate-500">Skor</div>
              <div class="text-2xl font-bold text-slate-900 mt-1">
                {{ result.attempt.total_score }}/{{ result.attempt.max_score }}
              </div>
            </div>
            <div class="bg-slate-50 rounded-lg p-4 text-center">
              <div class="text-xs text-slate-500">Persentase</div>
              <div class="text-2xl font-bold text-indigo-600 mt-1">
                {{ fmtPct(result.attempt.percentage) }}
              </div>
            </div>
            <div class="bg-slate-50 rounded-lg p-4 text-center">
              <div class="text-xs text-slate-500">Passing Score</div>
              <div class="text-2xl font-bold text-slate-700 mt-1">
                {{ result.test?.passing_score_pct }}%
              </div>
            </div>
          </div>

          <div v-else-if="result.attempt.result === 'REVIEW'"
            class="mt-4 text-sm text-slate-600 bg-amber-50 border border-amber-200 rounded-lg p-3">
            Tes Anda berisi soal essay. Tim recruitment akan menilai jawaban Anda dan hasil
            akan dikirim via email.
          </div>

          <div v-else class="mt-4 text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg p-3">
            Hasil tes tidak ditampilkan langsung kepada kandidat. Tim recruitment akan
            menghubungi Anda via email.
          </div>
        </div>

        <!-- Category breakdown -->
        <div v-if="showDetails && result.attempt?.category_scores"
          class="bg-white rounded-2xl border border-slate-200 p-6">
          <h2 class="text-base font-semibold text-slate-900 mb-3">Skor per Kategori</h2>
          <div class="space-y-2">
            <div v-for="(s, cat) in result.attempt.category_scores" :key="cat" class="flex items-center gap-3">
              <div class="w-32 text-sm text-slate-700">{{ cat }}</div>
              <div class="flex-1 bg-slate-100 rounded-full h-3 overflow-hidden">
                <div class="bg-indigo-500 h-full" :style="{ width: s.max > 0 ? (s.score / s.max * 100) + '%' : '0%' }"></div>
              </div>
              <div class="w-24 text-right text-sm text-slate-600 tabular-nums">
                {{ s.score }}/{{ s.max }}
              </div>
            </div>
          </div>
        </div>

        <!-- DISC breakdown -->
        <div v-if="showDetails && result.attempt?.disc_breakdown"
          class="bg-white rounded-2xl border border-slate-200 p-6">
          <h2 class="text-base font-semibold text-slate-900 mb-2">Profil Kepribadian DISC</h2>
          <p v-if="dominantDisc" class="text-sm text-slate-600 mb-4">
            Dimensi dominan: <span class="font-semibold text-indigo-600">{{ dominantDisc.k }}</span> —
            {{ discDescription(dominantDisc.k) }}
          </p>
          <div class="space-y-3">
            <div v-for="dim in ['D', 'I', 'S', 'C']" :key="dim" class="flex items-center gap-3">
              <div class="w-8 text-center font-bold text-lg" :class="{
                'text-rose-600': dim === 'D',
                'text-amber-600': dim === 'I',
                'text-emerald-600': dim === 'S',
                'text-blue-600': dim === 'C',
              }">{{ dim }}</div>
              <div class="flex-1 bg-slate-100 rounded-full h-4 overflow-hidden">
                <div class="h-full"
                  :class="{
                    'bg-rose-500': dim === 'D',
                    'bg-amber-500': dim === 'I',
                    'bg-emerald-500': dim === 'S',
                    'bg-blue-500': dim === 'C',
                  }"
                  :style="{ width: ((result.attempt.disc_breakdown[dim] || 0) / discMax * 100) + '%' }"></div>
              </div>
              <div class="w-12 text-right text-sm tabular-nums">
                {{ result.attempt.disc_breakdown[dim] || 0 }}
              </div>
            </div>
          </div>
        </div>

        <!-- Review jawaban -->
        <div v-if="showDetails && result.answers"
          class="bg-white rounded-2xl border border-slate-200 p-6">
          <h2 class="text-base font-semibold text-slate-900 mb-4">Tinjauan Jawaban</h2>
          <div class="space-y-4">
            <div v-for="(a, i) in result.answers" :key="a.answer_id"
              class="border-b border-slate-100 pb-3 last:border-0">
              <div class="flex items-start gap-2">
                <div class="font-mono text-xs text-slate-400 mt-0.5">{{ i + 1 }}.</div>
                <div class="flex-1">
                  <div class="text-sm text-slate-800 whitespace-pre-wrap">{{ a.prompt }}</div>
                  <div class="text-xs mt-2">
                    <span class="text-slate-500">Jawaban Anda: </span>
                    <span class="font-medium">{{ formatResponse(a) }}</span>
                  </div>
                  <div v-if="a.is_correct === true" class="text-xs text-emerald-700 mt-1">✓ Benar (+{{ a.points_awarded }} pts)</div>
                  <div v-else-if="a.is_correct === false" class="text-xs text-rose-700 mt-1">
                    ✗ Salah
                    <span v-if="a.correct_answer !== null"> · Jawaban benar: {{ formatCorrect(a) }}</span>
                  </div>
                  <div v-else class="text-xs text-amber-700 mt-1">⏳ Menunggu review</div>
                  <div v-if="a.explanation" class="mt-2 text-xs text-slate-600 bg-slate-50 p-2 rounded">
                    <strong>Pembahasan:</strong> {{ a.explanation }}
                  </div>
                  <div v-if="a.manual_feedback" class="mt-2 text-xs text-slate-700 bg-blue-50 p-2 rounded">
                    <strong>Catatan dari recruiter:</strong> {{ a.manual_feedback }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
// Helpers untuk format response (di luar setup karena tidak butuh reaktif)
export default {
  methods: {
    formatResponse(a) {
      const r = a.response
      if (r === null || r === undefined || r === '') return '(tidak dijawab)'
      if (Array.isArray(r)) return r.join(', ')
      if (typeof r === 'boolean') return r ? 'Benar' : 'Salah'
      return String(r)
    },
    formatCorrect(a) {
      const c = a.correct_answer
      if (Array.isArray(c)) return c.join(', ')
      if (typeof c === 'boolean') return c ? 'Benar' : 'Salah'
      return String(c)
    },
  },
}
</script>