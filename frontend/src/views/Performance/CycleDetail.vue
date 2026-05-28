<template>
  <div class="space-y-4">
    <router-link to="/performance" class="text-primary-600 hover:underline text-sm">← Kembali ke daftar cycle</router-link>

    <div v-if="cycle" class="bg-white rounded-lg p-5 border border-gray-200">
      <div class="flex items-start justify-between mb-3">
        <div>
          <div class="font-mono text-xs text-gray-500">{{ cycle.code }}</div>
          <h1 class="text-2xl font-bold text-gray-900">{{ cycle.name }}</h1>
          <div class="text-sm text-gray-600 mt-1">
            {{ formatDate(cycle.start_date) }} – {{ formatDate(cycle.end_date) }}
            <span class="mx-2">·</span>{{ cycle.cycle_type }}
          </div>
        </div>
        <span class="px-3 py-1 rounded text-xs font-medium" :class="statusClass(cycle.status)">{{ cycle.status }}</span>
      </div>

      <div v-if="cycle.stats" class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
        <div class="bg-gray-50 rounded p-3 border border-gray-200">
          <div class="text-xs text-gray-500">Total Goals</div>
          <div class="text-2xl font-bold text-gray-900">{{ cycle.stats.goals.total }}</div>
        </div>
        <div class="bg-gray-50 rounded p-3 border border-gray-200">
          <div class="text-xs text-gray-500">Karyawan</div>
          <div class="text-2xl font-bold text-blue-600">{{ cycle.stats.goals.unique_employees }}</div>
        </div>
        <div class="bg-gray-50 rounded p-3 border border-gray-200">
          <div class="text-xs text-gray-500">Avg Progress</div>
          <div class="text-2xl font-bold text-emerald-600">{{ cycle.stats.goals.avg_progress }}%</div>
        </div>
        <div class="bg-gray-50 rounded p-3 border border-gray-200">
          <div class="text-xs text-gray-500">Reviews Done</div>
          <div class="text-2xl font-bold text-purple-600">{{ cycle.stats.reviews.total }}</div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 border-b border-gray-200">
      <button @click="tab = 'goals'" class="px-4 py-2 text-sm font-medium border-b-2 transition-colors"
        :class="tab === 'goals' ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700'">
        Goals ({{ goals.length }})
      </button>
      <button @click="tab = 'reviews'" class="px-4 py-2 text-sm font-medium border-b-2 transition-colors"
        :class="tab === 'reviews' ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700'">
        Reviews ({{ reviews.length }})
      </button>
    </div>

    <!-- Goals tab -->
    <div v-if="tab === 'goals'" class="space-y-3">
      <div class="flex items-center justify-between">
        <div class="w-64">
          <EmployeeSearchSelect
            v-model="goalFilter.employee_id"
            placeholder="Semua Karyawan"
            :only-active="false"
            @change="fetchGoals"
          />
        </div>
        <button v-if="canManage" @click="openGoalCreate"
          class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium">
          + Goal
        </button>
        <ExcelImporter
          v-if="auth.can('goals.bulk_import')"
          resource="goals"
          button-label="Import Goals"
          @committed="fetchGoals()"
        />
      </div>

      <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Goal</th>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Karyawan</th>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Bobot</th>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Target / Saat ini</th>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Progress</th>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Status</th>
              <th class="px-3 py-2 text-right font-medium text-gray-700">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="g in goals" :key="g.id" class="hover:bg-gray-50">
              <td class="px-3 py-2">
                <div class="font-medium text-gray-900">{{ g.title }}</div>
                <div class="text-xs text-gray-500">{{ g.goal_type }} · {{ g.measurement_type }}</div>
              </td>
              <td class="px-3 py-2 text-xs text-gray-700">{{ g.employee_name }}</td>
              <td class="px-3 py-2 text-xs"><span class="font-medium">{{ g.weight }}%</span></td>
              <td class="px-3 py-2 text-xs text-gray-700">
                {{ g.current_value }} / {{ g.target_value }}<span v-if="g.unit"> {{ g.unit }}</span>
              </td>
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
                <span class="px-2 py-0.5 rounded text-xs" :class="goalStatusClass(g.status)">{{ g.status }}</span>
              </td>
              <td class="px-3 py-2 text-right space-x-1 whitespace-nowrap">
                <button v-if="canApprove && g.status === 'DRAFT'" @click="approveGoal(g)"
                  class="px-2 py-1 rounded bg-emerald-100 hover:bg-emerald-200 text-emerald-700 text-xs">
                  Approve
                </button>
                <button v-if="['APPROVED','IN_PROGRESS'].includes(g.status)" @click="openCheckIn(g)"
                  class="px-2 py-1 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 text-xs">
                  Check-in
                </button>
              </td>
            </tr>
            <tr v-if="!goals.length">
              <td colspan="7" class="px-3 py-8 text-center text-gray-500">Belum ada goal.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Reviews tab -->
    <div v-if="tab === 'reviews'" class="space-y-3">
      <div class="flex items-center justify-between">
        <div></div>
        <button v-if="canConduct" @click="openReviewCreate"
          class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium">
          + Review
        </button>
      </div>
      <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Karyawan</th>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Reviewer</th>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Tipe</th>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Skor</th>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Rating</th>
              <th class="px-3 py-2 text-left font-medium text-gray-700">Status</th>
              <th class="px-3 py-2 text-right font-medium text-gray-700">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in reviews" :key="r.id" class="hover:bg-gray-50">
              <td class="px-3 py-2">
                <div class="font-medium text-gray-900">{{ r.employee_name }}</div>
                <div class="text-xs text-gray-500">{{ r.employee_number }}</div>
              </td>
              <td class="px-3 py-2 text-xs text-gray-700">{{ r.reviewer_name || '-' }}</td>
              <td class="px-3 py-2 text-xs text-gray-700">{{ r.review_type }}</td>
              <td class="px-3 py-2 text-xs text-gray-700">{{ r.overall_score !== null ? r.overall_score : '-' }}</td>
              <td class="px-3 py-2">
                <span v-if="r.rating" class="px-2 py-0.5 rounded text-xs" :class="ratingClass(r.rating)">{{ r.rating }}</span>
                <span v-else class="text-gray-400 text-xs">-</span>
              </td>
              <td class="px-3 py-2">
                <span class="px-2 py-0.5 rounded text-xs" :class="reviewStatusClass(r.status)">{{ r.status }}</span>
              </td>
              <td class="px-3 py-2 text-right">
                <router-link :to="`/performance/reviews/${r.id}`"
                  class="px-2 py-1 rounded bg-blue-100 hover:bg-blue-200 text-blue-700 text-xs">
                  Detail
                </router-link>
              </td>
            </tr>
            <tr v-if="!reviews.length">
              <td colspan="7" class="px-3 py-8 text-center text-gray-500">Belum ada review.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Goal Create -->
    <div v-if="goalModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="goalModal.show = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-xl max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b border-gray-200 flex justify-between items-center">
          <h2 class="font-bold text-gray-900">Goal Baru</h2>
          <button @click="goalModal.show = false" class="text-gray-400 hover:text-gray-600 text-xl">×</button>
        </div>
        <form @submit.prevent="saveGoal" class="p-5 space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Karyawan *</label>
            <EmployeeSearchSelect
              v-model="goalModal.form.employee_id"
              placeholder="— pilih karyawan —"
              :only-active="true"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Judul Goal *</label>
            <input v-model="goalModal.form.title" required maxlength="255"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tipe</label>
              <select v-model="goalModal.form.goal_type"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
                <option value="KPI">KPI</option>
                <option value="OKR_OBJECTIVE">OKR Objective</option>
                <option value="OKR_KEY_RESULT">OKR Key Result</option>
                <option value="DEVELOPMENT">Development</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Measurement</label>
              <select v-model="goalModal.form.measurement_type"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
                <option value="PERCENT">Persentase</option>
                <option value="NUMBER">Angka</option>
                <option value="CURRENCY">Rupiah</option>
                <option value="BINARY">Yes/No</option>
                <option value="MILESTONE">Milestone</option>
              </select>
            </div>
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Target</label>
              <input v-model.number="goalModal.form.target_value" type="number" step="0.01"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
              <input v-model="goalModal.form.unit" maxlength="20"
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                placeholder="%, IDR, pcs, hari" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Bobot (%) *</label>
              <input v-model.number="goalModal.form.weight" type="number" min="0" max="100" step="0.01" required
                class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Deskripsi</label>
            <textarea v-model="goalModal.form.description" rows="3"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="goalModal.show = false"
              class="px-4 py-2 rounded bg-gray-200 text-gray-700 hover:bg-gray-300">Batal</button>
            <button type="submit" :disabled="goalModal.saving"
              class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white disabled:opacity-50">
              {{ goalModal.saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </form>
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
            <span class="font-medium">{{ checkInModal.goal?.title }}</span>
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
                class="px-3 py-2 rounded text-xs font-medium border-2"
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

    <!-- Modal Review Create -->
    <div v-if="reviewModal.show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="reviewModal.show = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b border-gray-200 flex justify-between items-center">
          <h2 class="font-bold text-gray-900">Buat Review</h2>
          <button @click="reviewModal.show = false" class="text-gray-400 hover:text-gray-600 text-xl">×</button>
        </div>
        <form @submit.prevent="saveReview" class="p-5 space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Karyawan *</label>
            <EmployeeSearchSelect
              v-model="reviewModal.employee_id"
              placeholder="— pilih karyawan —"
              :only-active="true"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipe</label>
            <select v-model="reviewModal.review_type"
              class="w-full px-3 py-2 rounded border border-gray-300 focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
              <option value="MANAGER">Manager</option>
              <option value="SELF">Self</option>
              <option value="PEER">Peer</option>
              <option value="SUBORDINATE">Subordinate (360)</option>
            </select>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="reviewModal.show = false"
              class="px-4 py-2 rounded bg-gray-200 text-gray-700 hover:bg-gray-300">Batal</button>
            <button type="submit" :disabled="reviewModal.saving"
              class="px-4 py-2 rounded bg-primary-600 hover:bg-primary-700 text-white disabled:opacity-50">
              {{ reviewModal.saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import EmployeeSearchSelect from '@/components/EmployeeSearchSelect.vue'
import ExcelImporter from '@/components/ExcelImporter.vue'

const route = useRoute()
const auth = useAuthStore()
const cycleId = computed(() => Number(route.params.id))
const canManage = computed(() => auth.can('performance.manage'))
const canApprove = computed(() => auth.can('goal.approve'))
const canConduct = computed(() => auth.can('review.conduct'))

const cycle = ref(null)
const goals = ref([])
const reviews = ref([])
const employees = ref([])
const tab = ref('goals')

const goalFilter = ref({ employee_id: '' })

const goalModal = ref({ show: false, form: blankGoalForm(), saving: false })
const checkInModal = ref({ show: false, goal: null, progress_value: 0, status_color: 'GREEN', comment: '', saving: false })
const reviewModal = ref({ show: false, employee_id: '', review_type: 'MANAGER', saving: false })

function blankGoalForm() {
  return {
    employee_id: '', title: '', goal_type: 'KPI', measurement_type: 'PERCENT',
    target_value: null, unit: '', weight: null, description: '',
  }
}

async function fetchCycle() {
  const r = await api.get(`/performance-cycles/${cycleId.value}`)
  cycle.value = r.data?.data
}

async function fetchGoals() {
  const params = { cycle_id: cycleId.value, per_page: 100 }
  if (goalFilter.value.employee_id) params.employee_id = goalFilter.value.employee_id
  const r = await api.get('/goals', { params })
  goals.value = r.data?.data || []
}

async function fetchReviews() {
  const r = await api.get('/performance-reviews', { params: { cycle_id: cycleId.value, per_page: 100 } })
  reviews.value = r.data?.data || []
}

// Karyawan tidak lagi di-load semua sekaligus — picker pakai
// EmployeeSearchSelect server-side. `employees` dipertahankan kosong agar
// kompatibel kalau ada code lain yang masih merefer-nya.
async function fetchEmployees() { /* deprecated: server-side search */ }

function openGoalCreate() {
  goalModal.value.form = blankGoalForm()
  goalModal.value.show = true
}

async function saveGoal() {
  goalModal.value.saving = true
  try {
    await api.post('/goals', {
      cycle_id: cycleId.value,
      ...goalModal.value.form,
    })
    goalModal.value.show = false
    await fetchGoals()
    await fetchCycle()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal menyimpan')
  } finally { goalModal.value.saving = false }
}

async function approveGoal(g) {
  if (! confirm(`Approve goal "${g.title}"?`)) return
  try {
    await api.post(`/goals/${g.id}/approve`)
    await fetchGoals()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal approve')
  }
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
    await fetchGoals()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal check-in')
  } finally { checkInModal.value.saving = false }
}

function openReviewCreate() {
  reviewModal.value = { show: true, employee_id: '', review_type: 'MANAGER', saving: false }
}

async function saveReview() {
  reviewModal.value.saving = true
  try {
    await api.post('/performance-reviews', {
      cycle_id: cycleId.value,
      employee_id: reviewModal.value.employee_id,
      review_type: reviewModal.value.review_type,
    })
    reviewModal.value.show = false
    await fetchReviews()
    await fetchCycle()
  } catch (e) {
    alert(e.response?.data?.message || 'Gagal')
  } finally { reviewModal.value.saving = false }
}

function formatDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function statusClass(s) {
  return {
    DRAFT: 'bg-gray-100 text-gray-700',
    OPEN: 'bg-blue-100 text-blue-700',
    GOAL_SETTING: 'bg-purple-100 text-purple-700',
    IN_PROGRESS: 'bg-amber-100 text-amber-700',
    REVIEW: 'bg-orange-100 text-orange-700',
    CLOSED: 'bg-emerald-100 text-emerald-700',
    CANCELLED: 'bg-red-100 text-red-700',
  }[s] || 'bg-gray-100 text-gray-700'
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

function statusColorActive(s) {
  return {
    GREEN: 'border-emerald-500 bg-emerald-50 text-emerald-700',
    YELLOW: 'border-amber-500 bg-amber-50 text-amber-700',
    RED: 'border-red-500 bg-red-50 text-red-700',
  }[s]
}

onMounted(async () => {
  await fetchCycle()
  await fetchGoals()
  await fetchReviews()
  await fetchEmployees()
})
</script>