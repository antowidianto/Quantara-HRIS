<template>
  <div class="space-y-4">
    <router-link to="/performance" class="text-primary-600 hover:underline text-sm">← Kembali ke daftar</router-link>

    <div v-if="review" class="bg-white rounded-lg p-5 border border-gray-200">
      <div class="flex items-start justify-between mb-3">
        <div>
          <div class="text-xs text-gray-500">{{ review.review_type }} Review</div>
          <h1 class="text-2xl font-bold text-gray-900">{{ review.employee_name }}</h1>
          <div class="text-sm text-gray-600 mt-1">
            {{ review.cycle_name }}
            <span v-if="review.reviewer_name" class="mx-2">·</span>
            <span v-if="review.reviewer_name">Reviewer: {{ review.reviewer_name }}</span>
          </div>
        </div>
        <div class="flex flex-col items-end gap-2">
          <span class="px-3 py-1 rounded text-xs font-medium" :class="reviewStatusClass(review.status)">{{ review.status }}</span>
          <div v-if="review.overall_score !== null" class="text-right">
            <div class="text-3xl font-bold text-gray-900">{{ review.overall_score }}</div>
            <span v-if="review.rating" class="px-2 py-0.5 rounded text-xs" :class="ratingClass(review.rating)">{{ review.rating }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Goals scoring (editable when DRAFT) -->
    <div class="bg-white rounded-lg border border-gray-200">
      <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
        <h2 class="font-bold text-gray-900">Skor Goal ({{ review?.goals?.length || 0 }})</h2>
        <div v-if="canEdit" class="text-xs text-gray-500">
          Total bobot: <span class="font-medium">{{ totalWeight }}%</span>
        </div>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Goal</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Bobot</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Progress</th>
            <th class="px-3 py-2 text-left font-medium text-gray-700">Skor (0-100)</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="g in review?.goals || []" :key="g.id" class="hover:bg-gray-50">
            <td class="px-3 py-2">
              <div class="font-medium text-gray-900">{{ g.title }}</div>
              <div class="text-xs text-gray-500">{{ g.target_value }} / {{ g.current_value }} {{ g.unit }}</div>
            </td>
            <td class="px-3 py-2 text-xs"><span class="font-medium">{{ g.weight }}%</span></td>
            <td class="px-3 py-2">
              <div class="w-24">
                <div class="bg-gray-200 rounded-full h-2 overflow-hidden">
                  <div class="h-full" :class="progressColor(g.progress_percent)"
                    :style="{ width: Math.min(100, g.progress_percent) + '%' }"></div>
                </div>
                <div class="text-xs text-gray-600 mt-0.5">{{ g.progress_percent }}%</div>
              </div>
            </td>
            <td class="px-3 py-2">
              <input v-if="canEdit" v-model.number="goalScores[g.id]" type="number" min="0" max="100" step="0.1"
                class="w-24 px-2 py-1 rounded border border-gray-300 text-sm focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
              <span v-else class="font-bold text-gray-900">{{ goalScores[g.id] ?? '-' }}</span>
            </td>
          </tr>
          <tr v-if="!review?.goals?.length">
            <td colspan="4" class="px-3 py-8 text-center text-gray-500">Karyawan belum punya goal di cycle ini.</td>
          </tr>
        </tbody>
      </table>
      <div v-if="canEdit && review?.goals?.length" class="px-4 py-3 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
        <div class="text-sm text-gray-600">
          Skor diberi 0-100 per goal. Sistem hitung weighted average otomatis saat submit.
        </div>
        <div class="text-sm">
          Preview skor: <span class="font-bold text-primary-700">{{ previewScore }}</span>
        </div>
      </div>
    </div>

    <!-- Comments -->
    <div class="bg-white rounded-lg border border-gray-200 p-5 space-y-4">
      <h2 class="font-bold text-gray-900">Catatan Reviewer</h2>
      <div v-if="canEdit">
        <label class="block text-sm font-medium text-gray-700 mb-1">Strengths</label>
        <textarea v-model="form.strengths" rows="2"
          class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
          placeholder="Hal-hal yang dilakukan dengan baik..."></textarea>
      </div>
      <div v-else-if="review?.strengths" class="text-sm">
        <div class="text-xs font-medium text-gray-500 mb-1">Strengths</div>
        <div class="text-gray-900 whitespace-pre-wrap">{{ review.strengths }}</div>
      </div>

      <div v-if="canEdit">
        <label class="block text-sm font-medium text-gray-700 mb-1">Improvements</label>
        <textarea v-model="form.improvements" rows="2"
          class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
          placeholder="Area yang perlu ditingkatkan..."></textarea>
      </div>
      <div v-else-if="review?.improvements" class="text-sm">
        <div class="text-xs font-medium text-gray-500 mb-1">Improvements</div>
        <div class="text-gray-900 whitespace-pre-wrap">{{ review.improvements }}</div>
      </div>

      <div v-if="canEdit">
        <label class="block text-sm font-medium text-gray-700 mb-1">Development Plan</label>
        <textarea v-model="form.development_plan" rows="2"
          class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
          placeholder="Training, mentoring, project..."></textarea>
      </div>
      <div v-else-if="review?.development_plan" class="text-sm">
        <div class="text-xs font-medium text-gray-500 mb-1">Development Plan</div>
        <div class="text-gray-900 whitespace-pre-wrap">{{ review.development_plan }}</div>
      </div>

      <div v-if="canEdit">
        <label class="block text-sm font-medium text-gray-700 mb-1">Komentar Umum</label>
        <textarea v-model="form.reviewer_comment" rows="3"
          class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"></textarea>
      </div>

      <div v-if="canEdit" class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Rekomendasi</label>
          <select v-model="form.recommendation"
            class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
            <option value="">— pilih —</option>
            <option value="PROMOTION">Promosi</option>
            <option value="RAISE">Kenaikan Gaji</option>
            <option value="KEEP">Pertahankan</option>
            <option value="PIP">PIP (Performance Improvement Plan)</option>
            <option value="TERMINATION">Terminasi</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Saran Naik Gaji (%)</label>
          <input v-model.number="form.recommended_increase_percent" type="number" min="0" max="100" step="0.1"
            class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
        </div>
      </div>
    </div>

    <!-- Employee comment (post-acknowledge) -->
    <div v-if="review && review.status !== 'DRAFT'" class="bg-white rounded-lg border border-gray-200 p-5">
      <h2 class="font-bold text-gray-900 mb-3">Tanggapan Karyawan</h2>
      <div v-if="review?.employee_comment" class="text-sm text-gray-900 whitespace-pre-wrap">{{ review.employee_comment }}</div>
      <div v-else-if="canAcknowledge">
        <textarea v-model="ackComment" rows="3"
          class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
          placeholder="Tanggapan Anda atas review ini..."></textarea>
      </div>
      <div v-else class="text-sm text-gray-500">Belum ada tanggapan.</div>
    </div>

    <!-- Actions -->
    <div class="flex flex-wrap gap-2 justify-end">
      <button v-if="canEdit" @click="saveDraft" :disabled="saving"
        class="px-4 py-2 rounded bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm disabled:opacity-50">
        Simpan Draft
      </button>
      <button v-if="canEdit" @click="submit" :disabled="saving"
        class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium disabled:opacity-50">
        Submit Review
      </button>
      <button v-if="canAcknowledge" @click="acknowledge" :disabled="saving"
        class="px-4 py-2 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium disabled:opacity-50">
        Acknowledge
      </button>
      <button v-if="canFinalize" @click="finalize" :disabled="saving"
        class="px-4 py-2 rounded bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium disabled:opacity-50">
        Finalize
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
const reviewId = computed(() => Number(route.params.id))

const review = ref(null)
const goalScores = ref({})
const form = ref({ strengths: '', improvements: '', development_plan: '', reviewer_comment: '', recommendation: '', recommended_increase_percent: null })
const ackComment = ref('')
const saving = ref(false)

const canEdit = computed(() => review.value?.status === 'DRAFT' && (
  Number(auth.user?.id) === Number(review.value?.reviewer_id) || auth.can('review.conduct')
))
const canAcknowledge = computed(() => review.value?.status === 'SUBMITTED' && (
  Number(auth.user?.employee_id || auth.user?.id) === Number(review.value?.employee_id)
))
const canFinalize = computed(() => ['SUBMITTED','ACKNOWLEDGED'].includes(review.value?.status) && auth.can('performance.manage'))

const totalWeight = computed(() => {
  return (review.value?.goals || []).reduce((sum, g) => sum + Number(g.weight || 0), 0)
})

const previewScore = computed(() => {
  let weighted = 0, totalW = 0
  for (const g of review.value?.goals || []) {
    const score = Number(goalScores.value[g.id])
    if (! isNaN(score)) {
      weighted += (score * Number(g.weight)) / 100
      totalW += Number(g.weight)
    }
  }
  return totalW > 0 ? Math.round(weighted * 100 / totalW * 100) / 100 : 0
})

async function fetch() {
  const r = await api.get(`/performance-reviews/${reviewId.value}`)
  review.value = r.data?.data
  goalScores.value = review.value?.goal_scores || {}
  form.value = {
    strengths: review.value?.strengths || '',
    improvements: review.value?.improvements || '',
    development_plan: review.value?.development_plan || '',
    reviewer_comment: review.value?.reviewer_comment || '',
    recommendation: review.value?.recommendation || '',
    recommended_increase_percent: review.value?.recommended_increase_percent,
  }
}

async function saveDraft() {
  saving.value = true
  try {
    await api.put(`/performance-reviews/${reviewId.value}`, {
      ...form.value,
      goal_scores: goalScores.value,
    })
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal menyimpan')
  } finally { saving.value = false }
}

async function submit() {
  if (! confirm('Submit review? Setelah submit, skor akan di-finalize berdasarkan input ini.')) return
  saving.value = true
  try {
    await api.post(`/performance-reviews/${reviewId.value}/submit`, {
      ...form.value,
      goal_scores: goalScores.value,
    })
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal submit')
  } finally { saving.value = false }
}

async function acknowledge() {
  saving.value = true
  try {
    await api.post(`/performance-reviews/${reviewId.value}/acknowledge`, {
      employee_comment: ackComment.value,
    })
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal acknowledge')
  } finally { saving.value = false }
}

async function finalize() {
  if (! confirm('Finalize review? Setelah ini tidak bisa diubah.')) return
  saving.value = true
  try {
    await api.post(`/performance-reviews/${reviewId.value}/finalize`)
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal finalize')
  } finally { saving.value = false }
}

function reviewStatusClass(s) {
  return {
    DRAFT: 'bg-gray-100 text-gray-700',
    SUBMITTED: 'bg-blue-100 text-blue-700',
    ACKNOWLEDGED: 'bg-purple-100 text-purple-700',
    FINALIZED: 'bg-emerald-100 text-emerald-700',
    CANCELLED: 'bg-red-100 text-red-700',
  }[s] || 'bg-gray-100 text-gray-700'
}

function ratingClass(r) {
  return {
    OUTSTANDING: 'bg-emerald-200 text-emerald-800 font-medium',
    EXCEEDS: 'bg-emerald-100 text-emerald-700 font-medium',
    MEETS: 'bg-blue-100 text-blue-700',
    NEEDS_IMPROVEMENT: 'bg-amber-100 text-amber-700',
    UNSATISFACTORY: 'bg-red-100 text-red-700',
  }[r] || 'bg-gray-100 text-gray-700'
}

function progressColor(p) {
  if (p >= 100) return 'bg-emerald-500'
  if (p >= 70) return 'bg-blue-500'
  if (p >= 40) return 'bg-amber-500'
  return 'bg-red-500'
}

onMounted(fetch)
</script>