<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import {
  HeartIcon, PlusIcon, ArrowPathIcon, CheckCircleIcon,
  PaperAirplaneIcon, ChartBarIcon, FaceSmileIcon, FaceFrownIcon,
  ShieldCheckIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 19+ / H4.4 — Pulse Survey & Wellbeing.
 *
 * Tabs:
 *   1. My Surveys — survey aktif untuk karyawan respond (anonim)
 *   2. HR Manage — list survey + create + open
 *   3. Aggregates — eNPS + burnout dashboard (HR only, with privacy gate)
 *
 * Privacy: response disimpan dengan submission_hash, tanpa employee_id.
 * Aggregate suppressed kalau response_count < min threshold (default 5).
 *
 * Endpoints:
 *   GET  /pulse-surveys                   — list
 *   POST /pulse-surveys                   — create
 *   POST /pulse-surveys/:id/open          — buka untuk respond
 *   GET  /pulse-surveys/:id/questions     — fetch question list
 *   POST /pulse-surveys/:id/respond       — submit anonym
 *   GET  /pulse-surveys/:id/aggregates    — eNPS + burnout result
 *   POST /pulse-surveys/:id/refresh-aggregates
 */

const auth = useAuthStore()
const toast = useToast()

const tab = ref('respond')
const isHR = computed(() =>
  auth.can('pulse_survey.manage') || auth.can('settings.manage') || auth.isSuperAdmin
)

// ─── Surveys list ───────────────────────────────────
const surveys = ref([])
const loadingList = ref(false)

async function loadSurveys() {
  loadingList.value = true
  try {
    const res = await api.get('/pulse-surveys')
    surveys.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat surveys')
  } finally {
    loadingList.value = false
  }
}

// ─── Respond ────────────────────────────────────────
const respondSurvey = ref(null)
const respondQuestions = ref([])
const respondAnswers = reactive({})
const submitting = ref(false)

async function openRespond(s) {
  respondSurvey.value = s
  respondAnswers && Object.keys(respondAnswers).forEach(k => delete respondAnswers[k])
  try {
    const res = await api.get(`/pulse-surveys/${s.id}/questions`)
    respondQuestions.value = res.data.data || []
    // Init defaults: 0 untuk numeric, '' untuk text
    respondQuestions.value.forEach(q => {
      respondAnswers[q.id] = q.question_type === 'TEXT' ? '' : null
    })
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat pertanyaan')
  }
}

async function submitResponse() {
  // Validasi: semua pertanyaan required harus diisi
  const unanswered = respondQuestions.value.filter(q =>
    q.is_required && (respondAnswers[q.id] === null || respondAnswers[q.id] === '' || respondAnswers[q.id] === undefined)
  )
  if (unanswered.length > 0) {
    toast.warning(`Masih ada ${unanswered.length} pertanyaan wajib yang belum diisi`)
    return
  }
  submitting.value = true
  try {
    const answers = respondQuestions.value.map(q => ({
      question_id: q.id,
      value: respondAnswers[q.id],
    }))
    await api.post(`/pulse-surveys/${respondSurvey.value.id}/respond`, { answers })
    toast.success('Terima kasih! Respon kamu sudah tercatat (anonim).')
    respondSurvey.value = null
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal submit')
  } finally {
    submitting.value = false
  }
}

// ─── HR Create ──────────────────────────────────────
const showCreate = ref(false)
const createForm = reactive({
  title: '',
  description: '',
  type: 'PULSE',
  starts_at: '',
  ends_at: '',
})

async function createSurvey() {
  if (! createForm.title?.trim()) {
    toast.warning('Title wajib')
    return
  }
  try {
    await api.post('/pulse-surveys', createForm)
    toast.success('Survey dibuat (status DRAFT). Klik "Buka" untuk mulai menerima respon.')
    showCreate.value = false
    Object.assign(createForm, { title: '', description: '', type: 'PULSE', starts_at: '', ends_at: '' })
    loadSurveys()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal membuat')
  }
}

async function openSurvey(s) {
  if (! confirm(`Buka survey "${s.title}" dan mulai terima respon?`)) return
  try {
    await api.post(`/pulse-surveys/${s.id}/open`)
    toast.success('Survey dibuka')
    loadSurveys()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal buka')
  }
}

// ─── Aggregates ─────────────────────────────────────
const aggSurveyId = ref('')
const aggregates = ref(null)
const aggScope = ref('COMPANY')

async function loadAggregates() {
  if (! aggSurveyId.value) return
  try {
    const res = await api.get(`/pulse-surveys/${aggSurveyId.value}/aggregates`, {
      params: { scope: aggScope.value },
    })
    aggregates.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat aggregates')
  }
}

async function refreshAggregates() {
  if (! aggSurveyId.value) return
  try {
    await api.post(`/pulse-surveys/${aggSurveyId.value}/refresh-aggregates`)
    toast.success('Aggregates di-recompute')
    loadAggregates()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal refresh')
  }
}

function statusBadge(s) {
  const map = {
    DRAFT:   'bg-gray-100 text-gray-700',
    OPEN:    'bg-emerald-100 text-emerald-700',
    CLOSED:  'bg-blue-100 text-blue-700',
    ARCHIVED:'bg-gray-100 text-gray-500',
  }
  return map[s] || 'bg-gray-100 text-gray-700'
}

function npsColor(score) {
  const v = Number(score || 0)
  if (v >= 50) return 'text-emerald-600'
  if (v >= 0)  return 'text-blue-600'
  if (v >= -50) return 'text-amber-600'
  return 'text-red-600'
}

function burnoutColor(score) {
  const v = Number(score || 0)
  if (v < 30) return 'bg-emerald-500'
  if (v < 50) return 'bg-amber-500'
  if (v < 70) return 'bg-orange-500'
  return 'bg-red-500'
}

function switchTab(t) {
  tab.value = t
  if (t === 'respond' || t === 'manage') loadSurveys()
}

onMounted(loadSurveys)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <HeartIcon class="w-6 h-6 text-red-500" /> Pulse Survey & Wellbeing
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Ukur sentimen karyawan secara anonim. Aggregate ditampilkan dengan privacy gate
          (min response sebelum hasil dipublish).
        </p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-4 flex gap-1">
      <button v-for="t in [
        { id: 'respond', label: 'Survey Aktif', icon: PaperAirplaneIcon },
        ...(isHR ? [
          { id: 'manage', label: 'Manage', icon: PlusIcon },
          { id: 'agg', label: 'Aggregates', icon: ChartBarIcon },
        ] : []),
      ]" :key="t.id" @click="switchTab(t.id)"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors flex items-center gap-2',
          tab === t.id ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700'
        ]">
        <component :is="t.icon" class="w-4 h-4" />
        {{ t.label }}
      </button>
    </div>

    <!-- ───── Tab: Respond ───── -->
    <div v-if="tab === 'respond'">
      <div class="bg-blue-50 border border-blue-200 rounded p-3 text-sm text-blue-800 mb-4 flex items-start gap-2">
        <ShieldCheckIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
        <div>
          <strong>Anonim — guaranteed.</strong> Respon kamu disimpan dengan hash one-way,
          bukan dengan ID karyawan. HR hanya bisa lihat agregat (min 5 respon per scope).
        </div>
      </div>

      <div v-if="loadingList" class="card-body text-center py-12 text-gray-400 card">Memuat…</div>
      <div v-else-if="surveys.filter(s => s.status === 'OPEN').length === 0"
        class="card-body text-center py-12 text-gray-400 card">
        Tidak ada survey aktif saat ini
      </div>
      <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div v-for="s in surveys.filter(s => s.status === 'OPEN')" :key="s.id" class="card">
          <div class="card-body">
            <h3 class="font-semibold">{{ s.title }}</h3>
            <p class="text-sm text-gray-600 mt-1">{{ s.description }}</p>
            <div class="text-xs text-gray-500 mt-2">
              Tipe: {{ s.type }} • Berakhir: {{ s.ends_at?.slice(0, 10) || 'belum di-set' }}
            </div>
            <button class="btn-primary mt-3 w-full" @click="openRespond(s)">
              <PaperAirplaneIcon class="w-4 h-4" /> Isi Survey
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── Tab: HR Manage ───── -->
    <div v-if="tab === 'manage' && isHR">
      <div class="flex justify-end mb-3">
        <button class="btn-primary" @click="showCreate = true">
          <PlusIcon class="w-4 h-4" /> Buat Survey
        </button>
      </div>
      <div class="card">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Title</th>
                <th>Type</th>
                <th>Status</th>
                <th>Period</th>
                <th class="text-right">Responses</th>
                <th class="text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="surveys.length === 0">
                <td colspan="6" class="text-center py-8 text-gray-400">Belum ada survey</td>
              </tr>
              <tr v-for="s in surveys" :key="s.id">
                <td>
                  <div class="font-medium">{{ s.title }}</div>
                  <div class="text-xs text-gray-500">{{ s.description }}</div>
                </td>
                <td><span class="text-xs uppercase">{{ s.type }}</span></td>
                <td>
                  <span class="inline-block px-2 py-0.5 rounded text-xs" :class="statusBadge(s.status)">
                    {{ s.status }}
                  </span>
                </td>
                <td class="text-xs text-gray-600">
                  {{ s.starts_at?.slice(0, 10) || '—' }} → {{ s.ends_at?.slice(0, 10) || '—' }}
                </td>
                <td class="text-right">{{ s.response_count || 0 }}</td>
                <td class="text-right">
                  <button v-if="s.status === 'DRAFT'" class="btn-secondary text-xs" @click="openSurvey(s)">
                    Buka
                  </button>
                  <button v-if="s.status === 'OPEN' || s.status === 'CLOSED'"
                    class="btn-icon text-primary-600"
                    @click="aggSurveyId = s.id; tab = 'agg'; loadAggregates()"
                    title="Lihat aggregates">
                    <ChartBarIcon class="w-4 h-4" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ───── Tab: Aggregates ───── -->
    <div v-if="tab === 'agg' && isHR">
      <div class="card mb-3">
        <div class="card-body grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
          <div>
            <label class="label">Survey</label>
            <select v-model="aggSurveyId" class="input" @change="loadAggregates">
              <option value="">— Pilih survey —</option>
              <option v-for="s in surveys.filter(s => s.status !== 'DRAFT')" :key="s.id" :value="s.id">
                {{ s.title }}
              </option>
            </select>
          </div>
          <div>
            <label class="label">Scope</label>
            <select v-model="aggScope" class="input" @change="loadAggregates">
              <option value="COMPANY">Company</option>
              <option value="DEPARTMENT">By Department</option>
              <option value="BRANCH">By Branch</option>
              <option value="TENURE">By Tenure</option>
              <option value="GENDER">By Gender</option>
            </select>
          </div>
          <div class="text-right">
            <button class="btn-secondary" @click="refreshAggregates" :disabled="! aggSurveyId">
              <ArrowPathIcon class="w-4 h-4" /> Refresh
            </button>
          </div>
        </div>
      </div>

      <div v-if="! aggregates" class="card">
        <div class="card-body text-center py-12 text-gray-400">Pilih survey untuk melihat aggregates</div>
      </div>

      <div v-else>
        <!-- Privacy gate notice -->
        <div v-if="aggregates.suppressed"
          class="bg-amber-50 border border-amber-200 rounded p-3 mb-4 text-sm text-amber-800 flex items-start gap-2">
          <ShieldCheckIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
          <div>
            <strong>Privacy gate aktif.</strong> Sebagian segmen tidak menampilkan
            data karena response < threshold ({{ aggregates.min_threshold || 5 }}).
            Hasil hanya dipublish jika sample size cukup untuk menjaga anonimitas.
          </div>
        </div>

        <!-- eNPS card -->
        <div v-if="aggregates.enps !== undefined" class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
          <div class="card">
            <div class="card-body">
              <div class="text-xs text-gray-500">eNPS Score (-100 to +100)</div>
              <div class="text-4xl font-bold mt-1" :class="npsColor(aggregates.enps)">
                {{ aggregates.enps > 0 ? '+' : '' }}{{ Number(aggregates.enps).toFixed(0) }}
              </div>
              <div class="text-xs text-gray-500 mt-2">
                {{ aggregates.response_count }} responses
              </div>
            </div>
          </div>
          <div class="card md:col-span-2">
            <div class="card-body">
              <div class="text-xs text-gray-500 mb-2">Distribution</div>
              <div class="space-y-1.5 text-sm">
                <div class="flex items-center gap-2">
                  <FaceSmileIcon class="w-4 h-4 text-emerald-600" />
                  <span class="w-24">Promoters</span>
                  <div class="flex-1 bg-gray-100 rounded h-3 overflow-hidden">
                    <div class="bg-emerald-500 h-full" :style="{ width: (aggregates.promoter_pct || 0) + '%' }"></div>
                  </div>
                  <span class="w-16 text-right text-xs">{{ Number(aggregates.promoter_pct || 0).toFixed(0) }}%</span>
                </div>
                <div class="flex items-center gap-2">
                  <span class="w-4 h-4 inline-block bg-blue-300 rounded"></span>
                  <span class="w-24">Passives</span>
                  <div class="flex-1 bg-gray-100 rounded h-3 overflow-hidden">
                    <div class="bg-blue-400 h-full" :style="{ width: (aggregates.passive_pct || 0) + '%' }"></div>
                  </div>
                  <span class="w-16 text-right text-xs">{{ Number(aggregates.passive_pct || 0).toFixed(0) }}%</span>
                </div>
                <div class="flex items-center gap-2">
                  <FaceFrownIcon class="w-4 h-4 text-red-600" />
                  <span class="w-24">Detractors</span>
                  <div class="flex-1 bg-gray-100 rounded h-3 overflow-hidden">
                    <div class="bg-red-500 h-full" :style="{ width: (aggregates.detractor_pct || 0) + '%' }"></div>
                  </div>
                  <span class="w-16 text-right text-xs">{{ Number(aggregates.detractor_pct || 0).toFixed(0) }}%</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Burnout score -->
        <div v-if="aggregates.burnout_score !== undefined" class="card mb-4">
          <div class="card-header">
            <span class="font-medium">Burnout Risk Index (0-100, lower is better)</span>
          </div>
          <div class="p-5">
            <div class="flex items-center gap-4">
              <div class="text-4xl font-bold">{{ Number(aggregates.burnout_score).toFixed(0) }}</div>
              <div class="flex-1">
                <div class="w-full bg-gray-100 rounded-full h-4 overflow-hidden">
                  <div class="h-full transition-all" :class="burnoutColor(aggregates.burnout_score)"
                    :style="{ width: aggregates.burnout_score + '%' }"></div>
                </div>
                <div class="text-xs text-gray-500 mt-1">
                  <30 healthy, 30-50 caution, 50-70 elevated, ≥70 high risk
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Per scope breakdown -->
        <div v-if="aggregates.segments?.length" class="card">
          <div class="card-header">
            <span class="font-medium">Breakdown per {{ aggScope }}</span>
          </div>
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>{{ aggScope.charAt(0) + aggScope.slice(1).toLowerCase() }}</th>
                  <th class="text-right">Responses</th>
                  <th class="text-right">eNPS</th>
                  <th class="text-right">Burnout</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="seg in aggregates.segments" :key="seg.label">
                  <td class="font-medium">{{ seg.label }}</td>
                  <td class="text-right">
                    <span v-if="seg.suppressed" class="text-xs text-gray-400 italic">
                      < threshold
                    </span>
                    <span v-else>{{ seg.response_count }}</span>
                  </td>
                  <td class="text-right">
                    <span v-if="seg.suppressed" class="text-xs text-gray-400 italic">—</span>
                    <span v-else :class="npsColor(seg.enps)" class="font-medium">
                      {{ seg.enps > 0 ? '+' : '' }}{{ Number(seg.enps).toFixed(0) }}
                    </span>
                  </td>
                  <td class="text-right">
                    <span v-if="seg.suppressed" class="text-xs text-gray-400 italic">—</span>
                    <span v-else>{{ Number(seg.burnout_score).toFixed(0) }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: Respond -->
    <div v-if="respondSurvey" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="respondSurvey = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h3 class="font-semibold">{{ respondSurvey.title }}</h3>
          <button @click="respondSurvey = null"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <div class="p-5 space-y-4 overflow-y-auto">
          <div class="text-sm text-gray-600">{{ respondSurvey.description }}</div>
          <div class="bg-blue-50 border border-blue-200 rounded p-2 text-xs text-blue-800 flex items-center gap-2">
            <ShieldCheckIcon class="w-4 h-4 flex-shrink-0" />
            Respon dikirim anonim. Tidak ada ID karyawan yang disimpan bersama jawaban.
          </div>
          <div v-for="q in respondQuestions" :key="q.id" class="space-y-2">
            <label class="block">
              <span class="font-medium text-sm">
                {{ q.text }}
                <span v-if="q.is_required" class="text-red-500">*</span>
              </span>
              <span v-if="q.help_text" class="block text-xs text-gray-500 mt-0.5">{{ q.help_text }}</span>
            </label>
            <!-- LIKERT_5 / NPS_11 (numeric scale) -->
            <div v-if="q.question_type === 'LIKERT_5' || q.question_type === 'NPS_11'"
              class="flex flex-wrap gap-1.5">
              <button v-for="n in (q.question_type === 'NPS_11' ? Array.from({ length: 11 }, (_, i) => i)
                                                                : [1, 2, 3, 4, 5])" :key="n"
                @click="respondAnswers[q.id] = n"
                :class="[
                  'w-10 h-10 rounded border-2 font-medium transition',
                  respondAnswers[q.id] === n
                    ? 'bg-primary-600 text-white border-primary-700'
                    : 'border-gray-200 hover:border-primary-300'
                ]"
              >{{ n }}</button>
            </div>
            <!-- TEXT -->
            <textarea v-else-if="q.question_type === 'TEXT'"
              v-model="respondAnswers[q.id]" class="input" rows="3"
              :placeholder="q.placeholder || 'Tulis jawaban kamu…'"></textarea>
            <!-- YES_NO -->
            <div v-else-if="q.question_type === 'YES_NO'" class="flex gap-2">
              <button @click="respondAnswers[q.id] = 1"
                :class="['btn-secondary', respondAnswers[q.id] === 1 ? '!bg-emerald-600 !text-white' : '']">
                Ya
              </button>
              <button @click="respondAnswers[q.id] = 0"
                :class="['btn-secondary', respondAnswers[q.id] === 0 ? '!bg-red-600 !text-white' : '']">
                Tidak
              </button>
            </div>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="respondSurvey = null">Batal</button>
          <button class="btn-primary" @click="submitResponse" :disabled="submitting">
            <CheckCircleIcon class="w-4 h-4" />
            {{ submitting ? 'Mengirim…' : 'Kirim Anonim' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Modal: Create -->
    <div v-if="showCreate" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showCreate = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b">
          <h3 class="font-semibold">Buat Survey</h3>
        </div>
        <div class="p-5 space-y-3">
          <div>
            <label class="label">Title</label>
            <input v-model="createForm.title" class="input"
              placeholder="contoh: Pulse Q3 — Engagement & Wellbeing" />
          </div>
          <div>
            <label class="label">Deskripsi</label>
            <textarea v-model="createForm.description" class="input" rows="2"></textarea>
          </div>
          <div>
            <label class="label">Type</label>
            <select v-model="createForm.type" class="input">
              <option value="PULSE">Pulse (eNPS + 3-5 pertanyaan)</option>
              <option value="ENPS">eNPS only (1 pertanyaan utama)</option>
              <option value="BURNOUT">Burnout MBI shortcut</option>
              <option value="CUSTOM">Custom (HR build sendiri)</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Mulai</label>
              <input type="date" v-model="createForm.starts_at" class="input" />
            </div>
            <div>
              <label class="label">Berakhir</label>
              <input type="date" v-model="createForm.ends_at" class="input" />
            </div>
          </div>
          <p class="text-xs text-gray-500">
            Status awal DRAFT. Pertanyaan default akan di-seed sesuai tipe.
            Setelah review, klik "Buka" di list untuk menerima respon.
          </p>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showCreate = false">Batal</button>
          <button class="btn-primary" @click="createSurvey">Buat</button>
        </div>
      </div>
    </div>
  </div>
</template>