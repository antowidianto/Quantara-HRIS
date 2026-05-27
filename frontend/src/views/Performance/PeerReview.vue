<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import {
  UserGroupIcon, ArrowPathIcon, PlusIcon, CheckIcon,
  ShieldCheckIcon, StarIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 11 / H2.3 — 360° Peer Feedback.
 *
 * Tabs:
 *   1. Saya — review yang harus saya kasih ke peer (SELF + assigned PEER)
 *   2. Nominasi — supervisor/HR nominasi peer reviewer
 *   3. Hasil 360° — review aggregate untuk reviewee (anonim untuk peer)
 *
 * Endpoints:
 *   GET  /performance-reviews?reviewer_id=me
 *   PUT  /performance-reviews/:id           — submit review
 *   POST /performance-reviews/:id/submit
 *   POST /performance/cycles/:id/peer-nominations
 *   GET  /performance/cycles/:id/peer-rating?reviewee_id=
 */

const auth = useAuthStore()
const toast = useToast()

const tab = ref('mine')
const isHRorSupervisor = computed(() =>
  auth.can('performance.manage') || auth.can('performance.nominate_peer') || auth.isSuperAdmin
)

// Helper text input untuk Peer Reviewer IDs (parsed ke array di nominateForm)
const reviewerIdsInput = ref('')

// ─── Tab: Mine (review yang harus saya isi) ─────────
const myReviews = ref([])
const loadingMine = ref(false)

async function loadMyReviews() {
  loadingMine.value = true
  try {
    // Filter di backend sudah ada (review_type = PEER + reviewer_id = me)
    const res = await api.get('/performance-reviews', { params: { mine: 1 } })
    myReviews.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat review')
  } finally {
    loadingMine.value = false
  }
}

// ─── Form review ────────────────────────────────────
const editingReview = ref(null)
const reviewForm = reactive({
  rating: 3,
  competency_ratings: {},
  strengths: '',
  improvements: '',
  comments: '',
})

const COMPETENCIES = [
  { id: 'communication', label: 'Komunikasi' },
  { id: 'collaboration', label: 'Kolaborasi' },
  { id: 'reliability',   label: 'Keandalan' },
  { id: 'expertise',     label: 'Keahlian Teknis' },
  { id: 'leadership',    label: 'Kepemimpinan' },
]

function openReview(r) {
  editingReview.value = r
  reviewForm.rating = r.overall_rating || 3
  reviewForm.competency_ratings = { ...(r.competency_ratings || {}) }
  reviewForm.strengths = r.strengths || ''
  reviewForm.improvements = r.improvements || ''
  reviewForm.comments = r.comments || ''
  COMPETENCIES.forEach(c => {
    if (reviewForm.competency_ratings[c.id] === undefined) {
      reviewForm.competency_ratings[c.id] = 3
    }
  })
}

const submitting = ref(false)

async function submitReview() {
  if (! reviewForm.strengths?.trim()) {
    toast.warning('Tolong isi kekuatan/strengths reviewee')
    return
  }
  if (! reviewForm.improvements?.trim()) {
    toast.warning('Tolong isi area pengembangan/improvements')
    return
  }
  submitting.value = true
  try {
    await api.put(`/performance-reviews/${editingReview.value.id}`, {
      overall_rating: reviewForm.rating,
      competency_ratings: reviewForm.competency_ratings,
      strengths: reviewForm.strengths,
      improvements: reviewForm.improvements,
      comments: reviewForm.comments,
    })
    await api.post(`/performance-reviews/${editingReview.value.id}/submit`)
    toast.success('Peer review terkirim — terima kasih!')
    editingReview.value = null
    loadMyReviews()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal submit')
  } finally {
    submitting.value = false
  }
}

// ─── Tab: Nominasi (supervisor/HR) ──────────────────
const cycles = ref([])
const cycleId = ref('')
const nominateForm = reactive({
  reviewee_id: '',
  reviewer_ids: [],
})

async function loadCycles() {
  try {
    const res = await api.get('/performance-cycles', { params: { status: 'OPEN' } })
    cycles.value = res.data.data || []
  } catch (_) {}
}

function parseReviewerIds() {
  nominateForm.reviewer_ids = reviewerIdsInput.value
    .split(',')
    .map(s => parseInt(s.trim()))
    .filter(Boolean)
}

async function nominate() {
  if (! cycleId.value) {
    toast.warning('Pilih cycle dulu')
    return
  }
  if (! nominateForm.reviewee_id) {
    toast.warning('Pilih reviewee')
    return
  }
  if (nominateForm.reviewer_ids.length === 0) {
    toast.warning('Pilih minimal 1 peer reviewer')
    return
  }
  try {
    await api.post(`/performance/cycles/${cycleId.value}/peer-nominations`, {
      reviewee_id: nominateForm.reviewee_id,
      reviewer_ids: nominateForm.reviewer_ids,
    })
    toast.success(`${nominateForm.reviewer_ids.length} peer dinominasikan — review request terkirim`)
    nominateForm.reviewee_id = ''
    nominateForm.reviewer_ids = []
    reviewerIdsInput.value = ''
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal nominate')
  }
}

// ─── Tab: Result (lihat hasil 360 untuk reviewee) ───
const resultRevieweeId = ref('')
const result360 = ref(null)

async function load360Result() {
  if (! cycleId.value || ! resultRevieweeId.value) return
  try {
    const res = await api.get(`/performance/cycles/${cycleId.value}/peer-rating`, {
      params: { reviewee_id: resultRevieweeId.value },
    })
    result360.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat hasil')
  }
}

function statusBadge(s) {
  const map = {
    DRAFT:     'bg-gray-100 text-gray-700',
    SUBMITTED: 'bg-emerald-100 text-emerald-700',
    FINALIZED: 'bg-blue-100 text-blue-700',
  }
  return map[s] || 'bg-gray-100 text-gray-700'
}

function reviewTypeLabel(t) {
  const map = {
    SELF: 'Self Review',
    SUPERVISOR: 'Supervisor → Bawahan',
    PEER: 'Peer Review',
    SUBORDINATE: 'Subordinate → Atasan',
  }
  return map[t] || t
}

function reviewTypeBadge(t) {
  const map = {
    SELF: 'bg-blue-100 text-blue-700',
    SUPERVISOR: 'bg-purple-100 text-purple-700',
    PEER: 'bg-emerald-100 text-emerald-700',
    SUBORDINATE: 'bg-amber-100 text-amber-700',
  }
  return map[t] || 'bg-gray-100 text-gray-700'
}

function switchTab(t) {
  tab.value = t
  if (t === 'mine') loadMyReviews()
  else if ((t === 'nominate' || t === 'result') && isHRorSupervisor.value) loadCycles()
}

onMounted(loadMyReviews)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <UserGroupIcon class="w-6 h-6" /> 360° Peer Review
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Berikan feedback ke rekan kerja. Hasil agregat anonim — minimal 3 reviewer per orang
          untuk menjaga konfidensialitas.
        </p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-4 flex gap-1 flex-wrap">
      <button v-for="t in [
        { id: 'mine', label: 'Review Saya Berikan' },
        ...(isHRorSupervisor ? [
          { id: 'nominate', label: 'Nominasi Peer (Supervisor/HR)' },
          { id: 'result', label: 'Hasil 360° Reviewee' },
        ] : []),
      ]" :key="t.id" @click="switchTab(t.id)"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
          tab === t.id ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700'
        ]">
        {{ t.label }}
      </button>
    </div>

    <!-- ───── Tab: Mine ───── -->
    <div v-if="tab === 'mine'">
      <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-800 mb-4 flex items-start gap-2">
        <ShieldCheckIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
        <div>
          Review yang kamu tulis akan diaggregate anonim bersama review peer lain.
          Reviewee tidak bisa tahu siapa yang memberi feedback apa.
        </div>
      </div>

      <div v-if="loadingMine" class="card-body text-center py-12 text-gray-400 card">Memuat…</div>
      <div v-else-if="myReviews.length === 0" class="card-body text-center py-12 text-gray-400 card">
        Tidak ada review yang perlu kamu isi saat ini
      </div>
      <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div v-for="r in myReviews" :key="r.id" class="card">
          <div class="card-body">
            <div class="flex items-start justify-between gap-2">
              <div class="flex-1 min-w-0">
                <h3 class="font-semibold">{{ r.reviewee_name }}</h3>
                <div class="text-xs text-gray-500 mt-0.5">
                  {{ r.reviewee_position || '—' }}
                  <span v-if="r.reviewee_department"> • {{ r.reviewee_department }}</span>
                </div>
              </div>
              <span class="inline-block px-2 py-0.5 rounded text-xs" :class="reviewTypeBadge(r.review_type)">
                {{ reviewTypeLabel(r.review_type) }}
              </span>
            </div>
            <div class="mt-2 text-xs text-gray-500">
              Cycle: {{ r.cycle_name }} — Deadline: {{ r.cycle_review_end_date || '—' }}
            </div>
            <div class="mt-3 flex items-center justify-between">
              <span class="inline-block px-2 py-0.5 rounded text-xs" :class="statusBadge(r.status)">
                {{ r.status }}
              </span>
              <button
                class="btn-primary text-xs"
                :disabled="r.status === 'SUBMITTED' || r.status === 'FINALIZED'"
                @click="openReview(r)"
              >
                {{ r.status === 'DRAFT' ? 'Isi Review' : 'Lihat' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── Tab: Nominate ───── -->
    <div v-if="tab === 'nominate' && isHRorSupervisor">
      <div class="card mb-3">
        <div class="card-body grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
          <div>
            <label class="label">Cycle Aktif</label>
            <select v-model="cycleId" class="input">
              <option value="">— Pilih cycle —</option>
              <option v-for="c in cycles" :key="c.id" :value="c.id">
                {{ c.name }} ({{ c.status }})
              </option>
            </select>
          </div>
        </div>
      </div>

      <div v-if="cycleId" class="card">
        <div class="card-header">
          <span class="font-medium">Nominate Peer Reviewers</span>
        </div>
        <div class="card-body space-y-3">
          <div>
            <label class="label">Reviewee (yang akan di-review)</label>
            <input type="number" v-model.number="nominateForm.reviewee_id" class="input"
              placeholder="Employee ID karyawan" />
          </div>
          <div>
            <label class="label">Peer Reviewer IDs (pisahkan koma)</label>
            <input v-model="reviewerIdsInput" class="input"
              placeholder="contoh: 12, 18, 23"
              @input="parseReviewerIds" />
            <p class="text-xs text-gray-500 mt-1">
              Idealnya nominasi 5-7 peer untuk dapat 3+ submission yang akan dipublish.
            </p>
          </div>
          <div class="bg-amber-50 border border-amber-200 rounded p-2 text-xs text-amber-800">
            Jumlah reviewer terpilih: <strong>{{ nominateForm.reviewer_ids.length }}</strong>
            (minimum 3 untuk hasil dipublish anonim)
          </div>
          <button class="btn-primary w-full" @click="nominate"
            :disabled="! nominateForm.reviewee_id || nominateForm.reviewer_ids.length === 0">
            <PlusIcon class="w-4 h-4" /> Nominasi & Kirim Request
          </button>
        </div>
      </div>
    </div>

    <!-- ───── Tab: Result ───── -->
    <div v-if="tab === 'result' && isHRorSupervisor">
      <div class="card mb-3">
        <div class="card-body grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
          <div>
            <label class="label">Cycle</label>
            <select v-model="cycleId" class="input">
              <option value="">— Pilih cycle —</option>
              <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="label">Reviewee Employee ID</label>
            <input type="number" v-model.number="resultRevieweeId" class="input"
              @change="load360Result" />
          </div>
        </div>
      </div>

      <div v-if="! result360" class="card">
        <div class="card-body text-center py-12 text-gray-400">
          Pilih cycle dan reviewee untuk melihat hasil 360°
        </div>
      </div>

      <div v-else>
        <div v-if="result360.suppressed"
          class="bg-amber-50 border border-amber-200 rounded p-3 mb-4 text-sm text-amber-800 flex items-start gap-2">
          <ShieldCheckIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
          <div>
            Hasil peer review belum dipublish: response baru
            <strong>{{ result360.peer_count || 0 }}</strong>, minimum
            <strong>{{ result360.peer_min_reviewers || 3 }}</strong> untuk menjaga anonimitas.
          </div>
        </div>

        <div v-else>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
            <div class="card-body bg-blue-50 rounded-lg border border-blue-100">
              <div class="text-xs text-blue-700">Self Rating</div>
              <div class="text-3xl font-bold text-blue-900">
                {{ Number(result360.self_rating || 0).toFixed(1) }} / 5
              </div>
            </div>
            <div class="card-body bg-purple-50 rounded-lg border border-purple-100">
              <div class="text-xs text-purple-700">Supervisor Rating</div>
              <div class="text-3xl font-bold text-purple-900">
                {{ Number(result360.supervisor_rating || 0).toFixed(1) }} / 5
              </div>
            </div>
            <div class="card-body bg-emerald-50 rounded-lg border border-emerald-100">
              <div class="text-xs text-emerald-700">Peer Rating (avg of {{ result360.peer_count }})</div>
              <div class="text-3xl font-bold text-emerald-900">
                {{ Number(result360.peer_rating || 0).toFixed(1) }} / 5
              </div>
            </div>
          </div>

          <div class="card mb-4">
            <div class="card-header">
              <span class="font-medium">Final Weighted Rating</span>
            </div>
            <div class="card-body">
              <div class="text-4xl font-bold text-center text-gray-800 my-3">
                {{ Number(result360.final_rating || 0).toFixed(2) }} / 5
              </div>
              <div class="text-xs text-gray-500 text-center">
                Weight: Self {{ Math.round((result360.self_weight || 0) * 100) }}% ·
                Supervisor {{ Math.round((result360.supervisor_weight || 0) * 100) }}% ·
                Peer {{ Math.round((result360.peer_weight || 0) * 100) }}%
              </div>
            </div>
          </div>

          <div v-if="result360.competency_ratings" class="card">
            <div class="card-header">
              <span class="font-medium">Per Competency (rata-rata semua reviewer)</span>
            </div>
            <div class="card-body space-y-2">
              <div v-for="(rating, comp) in result360.competency_ratings" :key="comp"
                class="flex items-center gap-3">
                <span class="w-44 text-sm">{{ comp }}</span>
                <div class="flex-1 bg-gray-100 rounded h-3 overflow-hidden">
                  <div class="bg-emerald-500 h-full transition-all"
                    :style="{ width: (Number(rating) / 5 * 100) + '%' }"></div>
                </div>
                <span class="w-12 text-right font-mono text-sm">
                  {{ Number(rating).toFixed(1) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: Edit Review -->
    <div v-if="editingReview" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="editingReview = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <div>
            <h3 class="font-semibold">{{ reviewTypeLabel(editingReview.review_type) }}</h3>
            <div class="text-xs text-gray-500">untuk: {{ editingReview.reviewee_name }}</div>
          </div>
          <button @click="editingReview = null"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <div class="p-5 space-y-4 overflow-y-auto">
          <div>
            <label class="label">Overall Rating (1-5)</label>
            <div class="flex gap-2">
              <button v-for="n in [1,2,3,4,5]" :key="n"
                @click="reviewForm.rating = n"
                :class="[
                  'flex-1 py-3 rounded border-2 font-semibold transition',
                  reviewForm.rating === n
                    ? 'bg-primary-600 text-white border-primary-700'
                    : 'border-gray-200 hover:border-primary-300'
                ]">
                <StarIcon class="w-5 h-5 mx-auto mb-1" />
                {{ n }}
              </button>
            </div>
            <p class="text-xs text-gray-500 mt-1 text-center">
              1 = Sangat di bawah ekspektasi · 5 = Jauh di atas ekspektasi
            </p>
          </div>

          <div>
            <label class="label">Rating per Kompetensi</label>
            <div class="space-y-2">
              <div v-for="c in COMPETENCIES" :key="c.id" class="flex items-center gap-3">
                <span class="w-44 text-sm">{{ c.label }}</span>
                <div class="flex gap-1">
                  <button v-for="n in [1,2,3,4,5]" :key="n"
                    @click="reviewForm.competency_ratings[c.id] = n"
                    :class="[
                      'w-8 h-8 rounded border text-sm font-medium',
                      reviewForm.competency_ratings[c.id] === n
                        ? 'bg-emerald-600 text-white border-emerald-700'
                        : 'border-gray-200 hover:border-emerald-300'
                    ]"
                  >{{ n }}</button>
                </div>
              </div>
            </div>
          </div>

          <div>
            <label class="label">Strengths / Kekuatan <span class="text-red-500">*</span></label>
            <textarea v-model="reviewForm.strengths" class="input" rows="3"
              placeholder="Apa yang dia lakukan dengan baik? Beri contoh konkret."></textarea>
          </div>

          <div>
            <label class="label">Areas for Improvement / Pengembangan <span class="text-red-500">*</span></label>
            <textarea v-model="reviewForm.improvements" class="input" rows="3"
              placeholder="Apa yang bisa dia tingkatkan? Sebutkan dengan konstruktif."></textarea>
          </div>

          <div>
            <label class="label">Komentar Lain (opsional)</label>
            <textarea v-model="reviewForm.comments" class="input" rows="2"></textarea>
          </div>

          <div class="bg-blue-50 border border-blue-200 rounded p-2 text-xs text-blue-800">
            Setelah submit, review tidak bisa diedit. Reviewee hanya akan lihat
            agregat dari semua peer (tidak per individu).
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="editingReview = null">Tutup</button>
          <button class="btn-primary" @click="submitReview" :disabled="submitting">
            <CheckIcon class="w-4 h-4" />
            {{ submitting ? 'Mengirim…' : 'Submit Review' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>