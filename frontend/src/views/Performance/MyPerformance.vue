<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Goal & Review Saya</h1>
      <p class="text-sm text-gray-600">KPI/OKR aktif dan review performa Anda.</p>
    </div>

    <!-- Goals -->
    <div class="bg-white rounded-lg border border-gray-200">
      <div class="px-4 py-3 border-b border-gray-200">
        <h2 class="font-bold text-gray-900">Goals Saya ({{ data.goals?.length || 0 }})</h2>
      </div>
      <div v-if="data.goals?.length" class="divide-y divide-gray-100">
        <div v-for="g in data.goals" :key="g.id" class="p-4 hover:bg-gray-50">
          <div class="flex items-start justify-between gap-3">
            <div class="flex-1 min-w-0">
              <div class="font-medium text-gray-900">{{ g.title }}</div>
              <div class="text-xs text-gray-500 mt-0.5">
                {{ g.cycle_name }} · {{ g.goal_type }} · Bobot {{ g.weight }}%
              </div>
              <div v-if="g.description" class="text-xs text-gray-600 mt-1">{{ g.description }}</div>
            </div>
            <span class="px-2 py-0.5 rounded text-xs flex-shrink-0" :class="goalStatusClass(g.status)">{{ g.status }}</span>
          </div>
          <div class="mt-3 flex items-center gap-4">
            <div class="flex-1">
              <div class="flex items-center justify-between text-xs text-gray-600 mb-1">
                <span>{{ g.current_value }} / {{ g.target_value }}<span v-if="g.unit"> {{ g.unit }}</span></span>
                <span class="font-medium">{{ g.progress_percent }}%</span>
              </div>
              <div class="bg-gray-200 rounded-full h-2 overflow-hidden">
                <div class="h-full transition-all" :class="progressColor(g.progress_percent)"
                  :style="{ width: Math.min(100, g.progress_percent) + '%' }"></div>
              </div>
            </div>
            <button v-if="['APPROVED','IN_PROGRESS'].includes(g.status)" @click="openCheckIn(g)"
              class="px-3 py-1.5 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 text-xs font-medium">
              Check-in
            </button>
          </div>
        </div>
      </div>
      <div v-else class="px-4 py-12 text-center text-gray-500 text-sm">
        Belum ada goal aktif. Tunggu cycle dibuka oleh HR.
      </div>
    </div>

    <!-- Reviews -->
    <div class="bg-white rounded-lg border border-gray-200">
      <div class="px-4 py-3 border-b border-gray-200">
        <h2 class="font-bold text-gray-900">Review Saya ({{ data.reviews?.length || 0 }})</h2>
      </div>
      <div v-if="data.reviews?.length" class="divide-y divide-gray-100">
        <div v-for="r in data.reviews" :key="r.id" class="p-4 hover:bg-gray-50">
          <div class="flex items-start justify-between gap-3">
            <div class="flex-1 min-w-0">
              <div class="font-medium text-gray-900">{{ r.cycle_name }}</div>
              <div class="text-xs text-gray-500 mt-0.5">{{ r.review_type }} review</div>
              <div v-if="r.overall_score !== null" class="text-sm mt-2">
                Skor: <span class="font-bold text-gray-900">{{ r.overall_score }}</span>
                <span v-if="r.rating" class="ml-2 px-2 py-0.5 rounded text-xs" :class="ratingClass(r.rating)">{{ r.rating }}</span>
              </div>
            </div>
            <div class="flex flex-col items-end gap-2">
              <span class="px-2 py-0.5 rounded text-xs" :class="reviewStatusClass(r.status)">{{ r.status }}</span>
              <router-link :to="`/performance/reviews/${r.id}`"
                class="px-3 py-1 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 text-xs font-medium">
                Lihat
              </router-link>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="px-4 py-12 text-center text-gray-500 text-sm">
        Belum ada review.
      </div>
    </div>

    <!-- Modal Check-In -->
    <div v-if="checkInModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="checkInModal.show = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b border-gray-200 flex justify-between items-center">
          <h2 class="font-bold text-gray-900">Check-In Progress</h2>
          <button @click="checkInModal.show = false" class="text-gray-400 hover:text-gray-600 text-xl">×</button>
        </div>
        <form @submit.prevent="submitCheckIn" class="p-5 space-y-3">
          <div class="text-sm text-gray-700">
            <div class="font-medium">{{ checkInModal.goal?.title }}</div>
            <div class="text-xs text-gray-500 mt-1">Target: {{ checkInModal.goal?.target_value }} {{ checkInModal.goal?.unit }}</div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nilai Saat Ini</label>
            <input v-model.number="checkInModal.progress_value" type="number" step="0.01" required
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <div class="flex gap-2">
              <button type="button" v-for="s in ['GREEN','YELLOW','RED']" :key="s" @click="checkInModal.status_color = s"
                class="px-3 py-2 rounded text-xs font-medium border-2 flex-1"
                :class="checkInModal.status_color === s ? statusColorActive(s) : 'border-gray-200 text-gray-500 hover:bg-gray-50'">
                {{ s === 'GREEN' ? '🟢 On Track' : s === 'YELLOW' ? '🟡 At Risk' : '🔴 Off Track' }}
              </button>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Komentar</label>
            <textarea v-model="checkInModal.comment" rows="3"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
              placeholder="Progress, blocker, kebutuhan support..."></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="checkInModal.show = false"
              class="px-4 py-2 rounded bg-gray-200 text-gray-700 hover:bg-gray-300">Batal</button>
            <button type="submit" :disabled="checkInModal.saving"
              class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white disabled:opacity-50">
              {{ checkInModal.saving ? 'Menyimpan...' : 'Submit' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'

const data = ref({ goals: [], reviews: [] })
const checkInModal = ref({ show: false, goal: null, progress_value: 0, status_color: 'GREEN', comment: '', saving: false })

async function fetch() {
  const r = await api.get('/performance/my')
  data.value = r.data?.data || { goals: [], reviews: [] }
}

function openCheckIn(g) {
  checkInModal.value = {
    show: true, goal: g,
    progress_value: g.current_value || 0,
    status_color: 'GREEN', comment: '', saving: false,
  }
}

async function submitCheckIn() {
  checkInModal.value.saving = true
  try {
    await api.post(`/goals/${checkInModal.value.goal.id}/check-in`, {
      progress_value: checkInModal.value.progress_value,
      status_color: checkInModal.value.status_color,
      comment: checkInModal.value.comment,
    })
    checkInModal.value.show = false
    await fetch()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal check-in')
  } finally { checkInModal.value.saving = false }
}

function goalStatusClass(s) {
  return {
    DRAFT: 'bg-gray-100 text-gray-700',
    APPROVED: 'bg-blue-100 text-blue-700',
    IN_PROGRESS: 'bg-amber-100 text-amber-700',
    ACHIEVED: 'bg-emerald-100 text-emerald-700',
    PARTIAL: 'bg-orange-100 text-orange-700',
    MISSED: 'bg-red-100 text-red-700',
    CANCELLED: 'bg-gray-100 text-gray-500',
  }[s] || 'bg-gray-100 text-gray-700'
}

function reviewStatusClass(s) {
  return {
    DRAFT: 'bg-gray-100 text-gray-700',
    SUBMITTED: 'bg-blue-100 text-blue-700',
    ACKNOWLEDGED: 'bg-purple-100 text-purple-700',
    FINALIZED: 'bg-emerald-100 text-emerald-700',
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

function statusColorActive(s) {
  return {
    GREEN: 'border-emerald-500 bg-emerald-50 text-emerald-700',
    YELLOW: 'border-amber-500 bg-amber-50 text-amber-700',
    RED: 'border-red-500 bg-red-50 text-red-700',
  }[s]
}

onMounted(fetch)
</script>