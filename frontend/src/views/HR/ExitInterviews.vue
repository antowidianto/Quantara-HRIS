<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate } from '@/composables/useResource'
import {
  ChatBubbleLeftRightIcon, ArrowPathIcon, ChartBarIcon,
  CheckCircleIcon, NoSymbolIcon, UserIcon,
} from '@heroicons/vue/24/outline'

/**
 * ExitInterviews — HR menu untuk wawancara terstruktur saat
 * resign / PHK (Sprint 4 / OFF-1).
 *
 * Tab:
 *   - List: semua interview dengan filter
 *   - Analytics: aggregate dashboard (would_rehire, primary_reason, eNPS, dll)
 */

const auth = useAuthStore()
const toast = useToast()

const tab = ref('list')
const list = ref([])
const loading = ref(false)
const options = ref({ modes: [], statuses: [], rehire_options: [], primary_reasons: [] })
const analytics = ref(null)
const showDetailModal = ref(null)
const detailData = ref(null)
const editForm = reactive({})

const STATUS_BADGE = {
  DRAFT:     { label: 'Draft',    color: 'bg-amber-100 text-amber-700' },
  COMPLETED: { label: 'Selesai',  color: 'bg-emerald-100 text-emerald-700' },
  SKIPPED:   { label: 'Skipped',  color: 'bg-gray-100 text-gray-500' },
}
const REHIRE_BADGE = {
  YES:         'bg-emerald-100 text-emerald-700',
  NO:          'bg-rose-100 text-rose-700',
  CONDITIONAL: 'bg-amber-100 text-amber-700',
  UNKNOWN:     'bg-gray-100 text-gray-500',
}

const canManage = computed(() => auth.isSuperAdmin || auth.can('exit_interview.manage'))

async function fetchAll() {
  loading.value = true
  try {
    const [l, o] = await Promise.all([
      api.get('/exit-interviews', { params: { per_page: 100 } }),
      api.get('/exit-interviews/options'),
    ])
    list.value = l.data.data || []
    options.value = o.data.data || options.value
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat')
  } finally {
    loading.value = false
  }
}

async function fetchAnalytics() {
  try {
    const res = await api.get('/exit-interviews/analytics')
    analytics.value = res.data.data
  } catch (e) {
    toast.error('Gagal memuat analytics')
  }
}

onMounted(async () => {
  await fetchAll()
  fetchAnalytics()
})

async function openDetail(interview) {
  showDetailModal.value = interview
  try {
    const res = await api.get(`/exit-interviews/${interview.id}`)
    detailData.value = res.data.data
    Object.assign(editForm, {
      mode: detailData.value.mode || 'IN_PERSON',
      would_rehire: detailData.value.would_rehire || 'UNKNOWN',
      primary_reason: detailData.value.primary_reason || '',
      satisfaction_score: detailData.value.satisfaction_score || null,
      recommend_score: detailData.value.recommend_score || null,
      score_compensation: detailData.value.score_compensation || null,
      score_management: detailData.value.score_management || null,
      score_work_environment: detailData.value.score_work_environment || null,
      score_career_growth: detailData.value.score_career_growth || null,
      score_work_life_balance: detailData.value.score_work_life_balance || null,
      score_team_culture: detailData.value.score_team_culture || null,
      most_liked: detailData.value.most_liked || '',
      most_disliked: detailData.value.most_disliked || '',
      suggestions: detailData.value.suggestions || '',
      additional_notes: detailData.value.additional_notes || '',
    })
  } catch (e) {
    toast.error('Gagal memuat')
    showDetailModal.value = null
  }
}

async function saveForm() {
  if (! showDetailModal.value) return
  try {
    await api.put(`/exit-interviews/${showDetailModal.value.id}`, editForm)
    toast.success('Disimpan')
    openDetail(showDetailModal.value)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal save')
  }
}

async function complete(id) {
  await saveForm() // simpan form dulu sebelum complete
  const ok = await confirmDialog({
    title: 'Mark as completed?',
    message: 'Wawancara akan di-lock — tidak bisa diedit lagi setelah complete. Pastikan data lengkap.',
    okText: 'Complete',
  })
  if (! ok) return
  try {
    await api.post(`/exit-interviews/${id}/complete`)
    toast.success('Completed')
    fetchAll()
    fetchAnalytics()
    showDetailModal.value = null
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal complete')
  }
}

async function skip(id) {
  const reason = window.prompt('Alasan skip (min 5 char):')
  if (! reason || reason.trim().length < 5) {
    toast.error('Alasan min 5 karakter')
    return
  }
  try {
    await api.post(`/exit-interviews/${id}/skip`, { reason: reason.trim() })
    toast.success('Skipped')
    fetchAll()
    showDetailModal.value = null
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal skip')
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ChatBubbleLeftRightIcon class="w-6 h-6 text-purple-600" />
          Exit Interviews
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
          Wawancara terstruktur saat karyawan resign / PHK + analytics turnover.
        </p>
      </div>
      <button class="btn-secondary text-xs" @click="fetchAll(); fetchAnalytics()">
        <ArrowPathIcon class="w-3.5 h-3.5" /> Refresh
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-3 border-b border-gray-200">
      <button :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px',
        tab === 'list' ? 'border-purple-500 text-purple-700' : 'border-transparent text-gray-500']"
        @click="tab = 'list'">
        Daftar Interview ({{ list.length }})
      </button>
      <button :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px',
        tab === 'analytics' ? 'border-purple-500 text-purple-700' : 'border-transparent text-gray-500']"
        @click="tab = 'analytics'; fetchAnalytics()">
        Analytics Dashboard
      </button>
    </div>

    <!-- LIST -->
    <div v-if="tab === 'list'">
      <div v-if="loading" class="text-center py-12 text-gray-400">Memuat…</div>
      <div v-else-if="list.length === 0" class="text-center py-12 text-gray-400 italic">
        Belum ada exit interview.
      </div>
      <div v-else class="space-y-2">
        <div v-for="i in list" :key="i.id"
          class="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition cursor-pointer"
          @click="openDetail(i)">
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <UserIcon class="w-4 h-4 text-gray-400" />
                <strong>{{ i.employee_name }}</strong>
                <span class="text-xs font-mono text-gray-500">{{ i.employee_number }}</span>
                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', STATUS_BADGE[i.status]?.color]">
                  {{ STATUS_BADGE[i.status]?.label }}
                </span>
                <span class="text-xs text-gray-500">· {{ i.mode }}</span>
              </div>
              <div class="text-xs text-gray-500 mt-1">
                <span v-if="i.resignation_id">Resignation #{{ i.resignation_id }}</span>
                <span v-else-if="i.termination_id">Termination #{{ i.termination_id }}</span>
                <span v-if="i.completed_at"> · Completed: {{ fmtDate(i.completed_at) }}</span>
              </div>
              <div v-if="i.would_rehire && i.would_rehire !== 'UNKNOWN'" class="mt-1.5 flex items-center gap-2 text-xs">
                <span :class="['px-2 py-0.5 rounded-full font-medium', REHIRE_BADGE[i.would_rehire]]">
                  Rehire: {{ i.would_rehire }}
                </span>
                <span v-if="i.primary_reason" class="text-gray-500">{{ i.primary_reason }}</span>
                <span v-if="i.satisfaction_score" class="text-gray-500">· Sat: {{ i.satisfaction_score }}/10</span>
              </div>
            </div>
            <div class="text-xs text-gray-400">#{{ i.id }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ANALYTICS -->
    <div v-else-if="tab === 'analytics'">
      <div v-if="!analytics" class="text-center py-12 text-gray-400">Memuat…</div>
      <div v-else-if="analytics.total_completed === 0" class="text-center py-12 text-gray-400 italic">
        Belum ada exit interview yang completed. Tidak ada data analytics.
      </div>
      <div v-else class="space-y-4">
        <!-- Top stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
          <div class="bg-white rounded-lg border p-4">
            <div class="text-xs text-gray-500">Total Completed</div>
            <div class="text-2xl font-bold">{{ analytics.total_completed }}</div>
          </div>
          <div class="bg-white rounded-lg border p-4">
            <div class="text-xs text-gray-500">eNPS</div>
            <div class="text-2xl font-bold"
              :class="(analytics.enps ?? 0) >= 30 ? 'text-emerald-700'
                : (analytics.enps ?? 0) >= 0 ? 'text-amber-700' : 'text-rose-700'">
              {{ analytics.enps ?? '-' }}
            </div>
            <div class="text-[11px] text-gray-400">Promoter (9-10) - Detractor (0-6)</div>
          </div>
          <div class="bg-white rounded-lg border p-4">
            <div class="text-xs text-gray-500">Avg Satisfaction</div>
            <div class="text-2xl font-bold text-blue-700">{{ analytics.avg_satisfaction || 0 }} / 10</div>
          </div>
          <div class="bg-white rounded-lg border p-4">
            <div class="text-xs text-gray-500">Avg Recommend</div>
            <div class="text-2xl font-bold text-blue-700">{{ analytics.avg_recommend || 0 }} / 10</div>
          </div>
        </div>

        <!-- Would rehire breakdown -->
        <section class="bg-white rounded-lg border p-4">
          <h3 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <ChartBarIcon class="w-4 h-4" /> Would Rehire?
          </h3>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div v-for="(count, key) in analytics.would_rehire_breakdown" :key="key"
              :class="['rounded p-3 text-center', REHIRE_BADGE[key]]">
              <div class="text-xl font-bold">{{ count }}</div>
              <div class="text-xs">{{ key }}</div>
            </div>
          </div>
        </section>

        <!-- Primary reasons -->
        <section class="bg-white rounded-lg border p-4">
          <h3 class="font-semibold text-sm mb-3">Top Primary Reasons</h3>
          <div class="space-y-1.5">
            <div v-for="(count, reason) in analytics.primary_reasons" :key="reason"
              class="flex items-center gap-2 text-sm">
              <span class="font-medium w-44 truncate" :title="reason">{{ reason }}</span>
              <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="bg-purple-500 h-full"
                  :style="{ width: ((count / analytics.total_completed) * 100) + '%' }"></div>
              </div>
              <span class="text-xs text-gray-600 font-mono w-8 text-right">{{ count }}</span>
            </div>
          </div>
        </section>

        <!-- By department -->
        <section v-if="Object.keys(analytics.by_department || {}).length > 0"
          class="bg-white rounded-lg border p-4">
          <h3 class="font-semibold text-sm mb-3">Turnover per Department</h3>
          <div class="space-y-1.5">
            <div v-for="(count, dept) in analytics.by_department" :key="dept"
              class="flex items-center gap-2 text-sm">
              <span class="font-medium w-44 truncate">{{ dept }}</span>
              <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden">
                <div class="bg-rose-500 h-full"
                  :style="{ width: ((count / analytics.total_completed) * 100) + '%' }"></div>
              </div>
              <span class="text-xs text-gray-600 font-mono w-8 text-right">{{ count }}</span>
            </div>
          </div>
        </section>

        <!-- Likert avg -->
        <section v-if="analytics.avg_likerts" class="bg-white rounded-lg border p-4">
          <h3 class="font-semibold text-sm mb-3">Average Likert Scores (1-5)</h3>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
            <div v-for="(score, key) in analytics.avg_likerts" :key="key" class="text-sm">
              <div class="text-xs text-gray-500 capitalize">{{ key.replace('score_', '').replace(/_/g, ' ') }}</div>
              <div class="text-lg font-bold" :class="(score ?? 0) >= 4 ? 'text-emerald-700'
                : (score ?? 0) >= 3 ? 'text-amber-700' : 'text-rose-700'">
                {{ score ?? '-' }} / 5
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>

    <!-- Detail/Edit modal -->
    <div v-if="showDetailModal && detailData" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between sticky top-0 bg-white z-10">
          <div>
            <h2 class="font-semibold">Exit Interview — {{ detailData.employee_name }}</h2>
            <p class="text-xs text-gray-500">
              <span :class="['inline-block px-2 py-0.5 rounded-full font-medium ml-1', STATUS_BADGE[detailData.status]?.color]">
                {{ STATUS_BADGE[detailData.status]?.label }}
              </span>
              · {{ detailData.mode }}
            </p>
          </div>
          <button class="text-gray-500" @click="showDetailModal = null">&times;</button>
        </div>

        <div class="p-6 space-y-4">
          <fieldset :disabled="detailData.status !== 'DRAFT'" class="space-y-4 disabled:opacity-70">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label class="label">Mode</label>
                <select v-model="editForm.mode" class="input">
                  <option v-for="m in (options.modes || [])" :key="m" :value="m">{{ m }}</option>
                </select>
              </div>
              <div>
                <label class="label">Would Rehire? *</label>
                <select v-model="editForm.would_rehire" class="input">
                  <option v-for="r in (options.rehire_options || [])" :key="r" :value="r">{{ r }}</option>
                </select>
              </div>
              <div class="md:col-span-2">
                <label class="label">Primary Reason *</label>
                <select v-model="editForm.primary_reason" class="input">
                  <option value="">— Pilih —</option>
                  <option v-for="r in (options.primary_reasons || [])" :key="r.code" :value="r.code">{{ r.label }}</option>
                </select>
              </div>
              <div>
                <label class="label">Satisfaction Score (1-10)</label>
                <input type="number" v-model.number="editForm.satisfaction_score" class="input" min="1" max="10" />
              </div>
              <div>
                <label class="label">Recommend / NPS Score (1-10)</label>
                <input type="number" v-model.number="editForm.recommend_score" class="input" min="1" max="10" />
              </div>
            </div>

            <h3 class="font-semibold text-sm border-b pb-1">Detailed Likert (1-5)</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
              <div>
                <label class="label">Compensation</label>
                <input type="number" v-model.number="editForm.score_compensation" class="input" min="1" max="5" />
              </div>
              <div>
                <label class="label">Management</label>
                <input type="number" v-model.number="editForm.score_management" class="input" min="1" max="5" />
              </div>
              <div>
                <label class="label">Work Environment</label>
                <input type="number" v-model.number="editForm.score_work_environment" class="input" min="1" max="5" />
              </div>
              <div>
                <label class="label">Career Growth</label>
                <input type="number" v-model.number="editForm.score_career_growth" class="input" min="1" max="5" />
              </div>
              <div>
                <label class="label">Work-Life Balance</label>
                <input type="number" v-model.number="editForm.score_work_life_balance" class="input" min="1" max="5" />
              </div>
              <div>
                <label class="label">Team Culture</label>
                <input type="number" v-model.number="editForm.score_team_culture" class="input" min="1" max="5" />
              </div>
            </div>

            <h3 class="font-semibold text-sm border-b pb-1">Open Ended</h3>
            <div class="space-y-3">
              <div>
                <label class="label">Most Liked</label>
                <textarea v-model="editForm.most_liked" class="input" rows="2"></textarea>
              </div>
              <div>
                <label class="label">Most Disliked</label>
                <textarea v-model="editForm.most_disliked" class="input" rows="2"></textarea>
              </div>
              <div>
                <label class="label">Suggestions for Company</label>
                <textarea v-model="editForm.suggestions" class="input" rows="2"></textarea>
              </div>
              <div>
                <label class="label">Additional Notes</label>
                <textarea v-model="editForm.additional_notes" class="input" rows="2"></textarea>
              </div>
            </div>
          </fieldset>

          <div v-if="detailData.status !== 'DRAFT'"
            class="bg-gray-50 border border-gray-200 rounded p-3 text-xs text-gray-600">
            Interview ini sudah {{ detailData.status }} — tidak bisa diedit lagi.
          </div>
        </div>

        <div class="px-6 py-3 border-t flex justify-end gap-2 sticky bottom-0 bg-white">
          <button v-if="canManage && detailData.status === 'DRAFT'"
            class="btn-secondary text-sm" @click="saveForm">
            Save Draft
          </button>
          <button v-if="canManage && detailData.status === 'DRAFT'"
            class="btn-primary text-sm bg-emerald-600 hover:bg-emerald-700"
            @click="complete(detailData.id)">
            <CheckCircleIcon class="w-4 h-4" /> Complete
          </button>
          <button v-if="canManage && detailData.status === 'DRAFT'"
            class="text-sm px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"
            @click="skip(detailData.id)">
            <NoSymbolIcon class="w-4 h-4 inline" /> Skip
          </button>
          <button class="btn-secondary" @click="showDetailModal = null">Tutup</button>
        </div>
      </div>
    </div>
  </div>
</template>