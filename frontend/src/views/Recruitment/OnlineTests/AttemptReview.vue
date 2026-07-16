<script setup>
/**
 * AttemptReview — HR review hasil attempt kandidat.
 * Bisa dipakai untuk:
 *   - Lihat skor total, breakdown kategori, anti-cheat counters
 *   - Review jawaban kandidat
 *   - Manual grade SHORT_ANSWER / essay
 */
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import api from '@/api/client'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const attemptId = computed(() => Number(route.params.id))
const loading = ref(true)
const data = ref(null)
const grading = ref({})
const saving = ref({})
const actionLoading = ref(false)

onMounted(load)

async function load() {
  try {
    const res = await api.get(`/online-test-attempts/${attemptId.value}`)
    data.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat review attempt')
  } finally {
    loading.value = false
  }
}

function isEssay(a) {
  return a.question_type === 'SHORT_ANSWER'
}

async function saveGrade(answer) {
  saving.value[answer.answer_id] = true
  try {
    await api.post(`/online-test-attempts/${attemptId.value}/grade`, {
      answer_id: answer.answer_id,
      points_awarded: Number(grading.value[answer.answer_id]?.points ?? 0),
      feedback: grading.value[answer.answer_id]?.feedback ?? '',
    })
    toast.success('Nilai tersimpan')
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan nilai')
  } finally {
    saving.value[answer.answer_id] = false
  }
}

const resultLabel = (r) => ({
  PASS: 'Lulus',
  FAIL: 'Tidak Lulus',
  REVIEW: 'Perlu Review',
  PENDING: 'Pending',
}[r] || r)

const resultColor = (r) => ({
  PASS: 'bg-emerald-100 text-emerald-700',
  FAIL: 'bg-rose-100 text-rose-700',
  REVIEW: 'bg-amber-100 text-amber-700',
  PENDING: 'bg-slate-100 text-slate-700',
}[r] || 'bg-slate-100 text-slate-700')

const discMax = computed(() => {
  const d = data.value?.attempt?.disc_breakdown
  if (!d) return 1
  return Math.max(d.D || 0, d.I || 0, d.S || 0, d.C || 0, 1)
})

function formatResponse(a) {
  const r = a.response
  if (r === null || r === undefined || r === '') return '(tidak dijawab)'
  if (Array.isArray(r)) return r.join(', ')
  if (typeof r === 'boolean') return r ? 'Benar' : 'Salah'
  return String(r)
}
function formatCorrect(a) {
  const c = a.correct_answer
  if (c === null || c === undefined || c === '') return '-'
  if (Array.isArray(c)) return c.join(', ')
  if (typeof c === 'boolean') return c ? 'Benar' : 'Salah'
  return String(c)
}

const candidateId = computed(() => data.value?.candidate?.id || data.value?.assignment?.candidate_id || null)
const canHire = computed(() => auth.can('recruitment.hire'))
const canManage = computed(() => auth.can('recruitment.manage'))

const STAGE_ACTIONS = [
  { code: 'ONLINE_TEST',   label: 'Tes Online' },
  { code: 'INTERVIEW_HR',  label: 'Interview HR' },
  { code: 'INTERVIEW_USER',label: 'Interview User' },
  { code: 'OFFER',         label: 'Offering' },
]

function openPipeline() {
  const jobId = data.value?.assignment?.job_posting_id
  if (!jobId) return
  router.push(`/recruitment/jobs/${jobId}`)
}

async function moveCandidate(stage) {
  if (!candidateId.value) return
  actionLoading.value = true
  try {
    await api.post(`/candidates/${candidateId.value}/move-stage`, { stage })
    toast.success(`Kandidat dipindahkan ke ${stage}`)
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memindahkan stage kandidat')
  } finally {
    actionLoading.value = false
  }
}

async function rejectCandidate() {
  if (!candidateId.value) return
  const reason = window.prompt('Masukkan alasan reject kandidat:')
  if (!reason) return
  actionLoading.value = true
  try {
    await api.post(`/candidates/${candidateId.value}/reject`, { reason })
    toast.success('Kandidat berhasil di-reject')
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal reject kandidat')
  } finally {
    actionLoading.value = false
  }
}

async function hireCandidate() {
  if (!candidateId.value) return
  if (!window.confirm('Ajukan konversi kandidat ini menjadi employee?')) return
  actionLoading.value = true
  try {
    await api.post(`/candidates/${candidateId.value}/hire`, {})
    toast.success('Konversi kandidat diajukan untuk approval')
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal hire kandidat')
  } finally {
    actionLoading.value = false
  }
}
</script>

<template>
  <div class="max-w-5xl mx-auto py-6 px-4">
    <div class="mb-5">
      <router-link to="/recruitment/online-test-assignments" class="text-sm text-slate-500 hover:text-indigo-600">
        ← Kembali ke Penugasan
      </router-link>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-400">Memuat...</div>

    <template v-else-if="data">
      <!-- Summary -->
      <div class="bg-white border border-slate-200 rounded-xl p-6 mb-5">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h1 class="text-xl font-bold text-slate-900">{{ data.test?.name }}</h1>
            <div class="text-sm text-slate-500 mt-1">
              Kandidat:
              <strong class="text-slate-800">{{ data.candidate?.full_name }}</strong>
              <span v-if="data.candidate?.email">· {{ data.candidate.email }}</span>
            </div>
            <div class="text-xs text-slate-500 mt-1">
              Attempt #{{ data.attempt?.attempt_number }} ·
              Started: {{ data.attempt?.started_at || '-' }} ·
              Submitted: {{ data.attempt?.submitted_at || '-' }}
            </div>
          </div>
          <span class="px-3 py-1 rounded-full text-sm font-medium" :class="resultColor(data.attempt?.result)">
            {{ resultLabel(data.attempt?.result) }}
          </span>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-5">
          <div class="bg-slate-50 rounded-lg p-3 text-center">
            <div class="text-xs text-slate-500">Skor</div>
            <div class="text-lg font-bold text-slate-900">{{ data.attempt?.total_score }}/{{ data.attempt?.max_score }}</div>
          </div>
          <div class="bg-slate-50 rounded-lg p-3 text-center">
            <div class="text-xs text-slate-500">Persentase</div>
            <div class="text-lg font-bold text-indigo-600">{{ data.attempt?.percentage }}%</div>
          </div>
          <div class="bg-slate-50 rounded-lg p-3 text-center">
            <div class="text-xs text-slate-500">Passing</div>
            <div class="text-lg font-bold text-slate-700">{{ data.test?.passing_score_pct }}%</div>
          </div>
          <div class="bg-slate-50 rounded-lg p-3 text-center">
            <div class="text-xs text-slate-500">Tab Switch</div>
            <div class="text-lg font-bold text-amber-600">{{ data.attempt?.tab_switch_count ?? 0 }}</div>
          </div>
          <div class="bg-slate-50 rounded-lg p-3 text-center">
            <div class="text-xs text-slate-500">Exit Fullscreen</div>
            <div class="text-lg font-bold text-rose-600">{{ data.attempt?.fullscreen_exit_count ?? 0 }}</div>
          </div>
        </div>

        <div class="mt-5 pt-4 border-t border-slate-200 flex items-center justify-between flex-wrap gap-3">
          <div class="flex items-center gap-3">
            <button
              v-if="data.assignment?.job_posting_id"
              @click="openPipeline"
              class="text-sm text-indigo-600 hover:text-indigo-800 underline underline-offset-2">
              Buka Pipeline Kandidat →
            </button>
          </div>
          <div class="flex items-center gap-2">
            <button
              v-if="canManage"
              @click="rejectCandidate"
              :disabled="actionLoading"
              class="px-3 py-1.5 border border-rose-200 text-rose-700 rounded-lg text-sm hover:bg-rose-50 disabled:opacity-50">
              Tolak
            </button>
          </div>
        </div>
      </div>

      <!-- Stage actions -->
      <div v-if="canManage" class="bg-white border border-slate-200 rounded-xl p-4 mb-5">
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">Lanjutkan Kandidat ke Tahap</div>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="act in STAGE_ACTIONS"
            :key="act.code"
            @click="moveCandidate(act.code)"
            :disabled="actionLoading"
            class="px-4 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 hover:bg-slate-50 hover:border-slate-400 disabled:opacity-50">
            {{ act.label }}
          </button>
          <button
            v-if="canHire"
            @click="hireCandidate"
            :disabled="actionLoading"
            class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 disabled:opacity-50">
            Hire Kandidat
          </button>
        </div>
      </div>

      <!-- Category scores -->
      <div v-if="data.attempt?.category_scores" class="bg-white border border-slate-200 rounded-xl p-6 mb-5">
        <h2 class="text-base font-semibold text-slate-900 mb-3">Skor per Kategori</h2>
        <div class="space-y-2">
          <div v-for="(s, cat) in data.attempt.category_scores" :key="cat" class="flex items-center gap-3">
            <div class="w-36 text-sm text-slate-700">{{ cat }}</div>
            <div class="flex-1 h-3 bg-slate-100 rounded-full overflow-hidden">
              <div class="h-full bg-indigo-500" :style="{ width: s.max > 0 ? (s.score / s.max * 100) + '%' : '0%' }"></div>
            </div>
            <div class="w-24 text-right text-sm text-slate-600">{{ s.score }}/{{ s.max }}</div>
          </div>
        </div>
      </div>

      <!-- DISC -->
      <div v-if="data.attempt?.disc_breakdown" class="bg-white border border-slate-200 rounded-xl p-6 mb-5">
        <h2 class="text-base font-semibold text-slate-900 mb-3">DISC Breakdown</h2>
        <div class="space-y-3">
          <div v-for="dim in ['D','I','S','C']" :key="dim" class="flex items-center gap-3">
            <div class="w-8 text-center font-bold">{{ dim }}</div>
            <div class="flex-1 h-4 bg-slate-100 rounded-full overflow-hidden">
              <div class="h-full bg-indigo-500" :style="{ width: ((data.attempt.disc_breakdown[dim] || 0) / discMax * 100) + '%' }"></div>
            </div>
            <div class="w-12 text-right text-sm">{{ data.attempt.disc_breakdown[dim] || 0 }}</div>
          </div>
        </div>
      </div>

      <!-- Answers -->
      <div class="bg-white border border-slate-200 rounded-xl p-6">
        <h2 class="text-base font-semibold text-slate-900 mb-4">Jawaban Kandidat</h2>
        <div class="space-y-5">
          <div v-for="(a, idx) in data.answers" :key="a.answer_id" class="border-b border-slate-100 pb-4 last:border-0">
            <div class="text-xs text-slate-400 mb-1">
              Soal {{ idx + 1 }} · {{ a.question_type }}
              <span v-if="a.category">· {{ a.category }}</span>
              <span class="ml-1">· {{ a.points_max }} pts</span>
            </div>
            <div class="text-sm text-slate-900 whitespace-pre-wrap">{{ a.prompt }}</div>

            <div v-if="a.choices?.length" class="mt-2 space-y-1">
              <div v-for="c in a.choices" :key="c.key" class="text-xs text-slate-600">
                <span class="font-semibold">{{ c.key }}.</span> {{ c.text }}
              </div>
            </div>

            <div class="mt-2 text-sm">
              <span class="text-slate-500">Jawaban kandidat:</span>
              <span class="font-medium text-slate-800"> {{ formatResponse(a) }}</span>
            </div>

            <div v-if="!isEssay(a)" class="mt-1 text-xs">
              <span v-if="a.is_correct === true" class="text-emerald-700">✓ Benar</span>
              <span v-else-if="a.is_correct === false" class="text-rose-700">
                ✗ Salah · Kunci: {{ formatCorrect(a) }}
              </span>
              <span v-else class="text-amber-700">⏳ Belum dinilai</span>
            </div>

            <div v-if="a.explanation" class="mt-2 text-xs text-slate-600 bg-slate-50 rounded p-2">
              <strong>Pembahasan:</strong> {{ a.explanation }}
            </div>

            <!-- Essay grading -->
            <div v-if="isEssay(a)" class="mt-3 bg-amber-50 border border-amber-200 rounded-lg p-3">
              <div class="text-xs font-medium text-amber-800 mb-2">Penilaian Manual Recruiter</div>
              <div class="grid grid-cols-1 md:grid-cols-[120px_1fr] gap-2 items-start">
                <div>
                  <label class="block text-xs text-slate-600 mb-1">Poin</label>
                  <input v-model="grading[a.answer_id].points" type="number" :max="a.points_max" min="0" step="0.5"
                    class="w-full px-3 py-2 border border-slate-300 rounded text-sm bg-white"
                    @focus="!grading[a.answer_id] && (grading[a.answer_id] = { points: a.points_awarded || 0, feedback: a.manual_feedback || '' })" />
                </div>
                <div>
                  <label class="block text-xs text-slate-600 mb-1">Feedback</label>
                  <textarea v-model="grading[a.answer_id].feedback" rows="2"
                    class="w-full px-3 py-2 border border-slate-300 rounded text-sm bg-white"
                    @focus="!grading[a.answer_id] && (grading[a.answer_id] = { points: a.points_awarded || 0, feedback: a.manual_feedback || '' })"></textarea>
                </div>
              </div>

              <div v-if="a.manual_feedback" class="mt-2 text-xs text-slate-700 bg-white rounded p-2 border border-slate-200">
                <strong>Feedback tersimpan:</strong> {{ a.manual_feedback }}
              </div>

              <div class="flex justify-end mt-3">
                <button
                  @click="() => {
                    if (!grading[a.answer_id]) grading[a.answer_id] = { points: a.points_awarded || 0, feedback: a.manual_feedback || '' }
                    saveGrade(a)
                  }"
                  :disabled="saving[a.answer_id]"
                  class="px-4 py-2 bg-indigo-600 text-white rounded text-sm font-medium disabled:opacity-50">
                  {{ saving[a.answer_id] ? 'Menyimpan...' : 'Simpan Nilai' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
