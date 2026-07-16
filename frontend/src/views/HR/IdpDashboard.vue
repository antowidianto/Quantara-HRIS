<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate } from '@/composables/useResource'
import {
  AcademicCapIcon, PlusIcon, CheckCircleIcon, XCircleIcon,
  ArrowPathIcon, UserIcon, ChartBarIcon,
} from '@heroicons/vue/24/outline'

/**
 * IdpDashboard — HR menu untuk Individual Development Plan
 * (Sprint 3 / OD-2). Closing-the-loop performance review:
 * review.improvements text → IDP DRAFT structured → manager finalize →
 * employee track progress action_items → manager complete di akhir cycle.
 */

const auth = useAuthStore()
const toast = useToast()

const tab = ref('all')          // 'all' | 'draft' | 'active' | 'completed'
const list = ref([])
const loading = ref(false)
const showDetailModal = ref(null)
const detailData = ref(null)

const STATUS_BADGE = {
  DRAFT:     { label: 'Draft',     color: 'bg-gray-100 text-gray-700' },
  PENDING_APPROVAL: { label: 'Menunggu Approval', color: 'bg-amber-100 text-amber-700' },
  APPROVAL_REJECTED: { label: 'Approval Ditolak', color: 'bg-rose-100 text-rose-700' },
  ACTIVE:    { label: 'Aktif',     color: 'bg-blue-100 text-blue-700' },
  COMPLETED: { label: 'Selesai',   color: 'bg-emerald-100 text-emerald-700' },
  REVIEWED:  { label: 'Direview',  color: 'bg-purple-100 text-purple-700' },
  CANCELLED: { label: 'Dibatal',   color: 'bg-rose-100 text-rose-700' },
}

const canManage = computed(
  () => auth.isSuperAdmin || auth.can('idp.manage'),
)

const filteredList = computed(() => {
  if (tab.value === 'all') return list.value
  const upper = tab.value.toUpperCase()
  return list.value.filter(i => i.status === upper)
})

const stats = computed(() => ({
  total: list.value.length,
  draft: list.value.filter(i => i.status === 'DRAFT').length,
  active: list.value.filter(i => i.status === 'ACTIVE').length,
  completed: list.value.filter(i => i.status === 'COMPLETED').length,
}))

async function fetchAll() {
  loading.value = true
  try {
    const res = await api.get('/idp')
    list.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat IDP')
  } finally {
    loading.value = false
  }
}

onMounted(fetchAll)

async function openDetail(idp) {
  showDetailModal.value = idp
  try {
    const res = await api.get(`/idp/${idp.id}`)
    detailData.value = res.data.data
  } catch (e) {
    toast.error('Gagal memuat detail')
    showDetailModal.value = null
  }
}

async function finalize(id) {
  const ok = await confirmDialog({
    title: 'Ajukan aktivasi IDP?',
    message: 'IDP akan dikunci dan dikirim ke Approval Inbox. Status ACTIVE baru diberikan setelah checker menyetujui.',
    okText: 'Submit Approval',
  })
  if (! ok) return
  try {
    await api.post(`/idp/${id}/finalize`)
    toast.success('Aktivasi IDP diajukan untuk approval')
    fetchAll()
    if (showDetailModal.value?.id === id) openDetail(showDetailModal.value)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal finalize')
  }
}

async function complete(id) {
  const note = window.prompt('Catatan manager (opsional):', '') || null
  try {
    await api.post(`/idp/${id}/complete`, { manager_notes: note })
    toast.success('IDP completed')
    fetchAll()
    if (showDetailModal.value?.id === id) openDetail(showDetailModal.value)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal complete')
  }
}

async function cancelIdp(id) {
  const reason = window.prompt('Alasan cancel (min 5 char):')
  if (! reason || reason.trim().length < 5) {
    toast.error('Alasan min 5 karakter')
    return
  }
  try {
    await api.post(`/idp/${id}/cancel`, { reason: reason.trim() })
    toast.success('IDP dibatalkan')
    fetchAll()
    showDetailModal.value = null
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal cancel')
  }
}

function progressColor(p) {
  if (p >= 75) return 'bg-emerald-500'
  if (p >= 40) return 'bg-blue-500'
  if (p >= 1)  return 'bg-amber-500'
  return 'bg-gray-300'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <AcademicCapIcon class="w-6 h-6 text-indigo-600" />
          Individual Development Plan
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
          Closing-the-loop performance review — gap analysis,
          target competencies, action items per karyawan.
        </p>
      </div>
      <button class="btn-secondary text-xs" @click="fetchAll">
        <ArrowPathIcon class="w-3.5 h-3.5" /> Refresh
      </button>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
      <div class="bg-white rounded-lg border p-4">
        <div class="text-xs text-gray-500">Total IDP</div>
        <div class="text-2xl font-bold">{{ stats.total }}</div>
      </div>
      <div class="bg-white rounded-lg border p-4">
        <div class="text-xs text-gray-500">DRAFT</div>
        <div class="text-2xl font-bold text-gray-700">{{ stats.draft }}</div>
      </div>
      <div class="bg-white rounded-lg border p-4">
        <div class="text-xs text-gray-500">ACTIVE</div>
        <div class="text-2xl font-bold text-blue-700">{{ stats.active }}</div>
      </div>
      <div class="bg-white rounded-lg border p-4">
        <div class="text-xs text-gray-500">COMPLETED</div>
        <div class="text-2xl font-bold text-emerald-700">{{ stats.completed }}</div>
      </div>
    </div>

    <!-- Filter tabs -->
    <div class="flex gap-2 mb-3 border-b border-gray-200 flex-wrap">
      <button v-for="t in ['all','draft','active','completed']" :key="t"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px capitalize',
          tab === t ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700',
        ]"
        @click="tab = t">
        {{ t === 'all' ? 'Semua' : t }}
      </button>
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-400">Memuat…</div>
    <div v-else-if="filteredList.length === 0" class="text-center py-12 text-gray-400 italic">
      Belum ada IDP. IDP DRAFT auto-create saat performance review di-finalize.
    </div>

    <div v-else class="space-y-2">
      <div v-for="idp in filteredList" :key="idp.id"
        class="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition cursor-pointer"
        @click="openDetail(idp)">
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <UserIcon class="w-4 h-4 text-gray-400" />
              <strong>{{ idp.employee_name }}</strong>
              <span class="text-xs font-mono text-gray-500">{{ idp.employee_number }}</span>
              <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', STATUS_BADGE[idp.status]?.color]">
                {{ STATUS_BADGE[idp.status]?.label }}
              </span>
              <span v-if="idp.cycle_name" class="text-xs text-gray-500">
                · Cycle: {{ idp.cycle_name }}
              </span>
            </div>
            <div class="text-xs text-gray-500 mt-1">
              Periode: {{ fmtDate(idp.plan_period_start) }} → {{ fmtDate(idp.plan_period_end) }}
            </div>

            <!-- Progress bar -->
            <div class="mt-2 flex items-center gap-2">
              <div class="flex-1 bg-gray-100 rounded-full h-2 overflow-hidden">
                <div :class="['h-full transition-all', progressColor(idp.progress_percent)]"
                  :style="{ width: (idp.progress_percent || 0) + '%' }"></div>
              </div>
              <span class="text-xs text-gray-600 font-mono w-12 text-right">{{ idp.progress_percent || 0 }}%</span>
            </div>
          </div>
          <div class="text-xs text-gray-400">
            #{{ idp.id }}
          </div>
        </div>
      </div>
    </div>

    <!-- Detail modal -->
    <div v-if="showDetailModal && detailData" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div class="px-6 py-3 border-b flex items-center justify-between sticky top-0 bg-white z-10">
          <div>
            <h2 class="font-semibold">IDP #{{ detailData.id }}</h2>
            <p class="text-xs text-gray-500">
              {{ detailData.employee_name }} · {{ detailData.cycle_name || 'Standalone' }}
              · <span :class="['inline-block px-2 py-0.5 rounded-full font-medium ml-1', STATUS_BADGE[detailData.status]?.color]">
                {{ STATUS_BADGE[detailData.status]?.label }}
              </span>
            </p>
          </div>
          <button class="text-gray-500" @click="showDetailModal = null">&times;</button>
        </div>

        <div class="p-6 space-y-4">
          <!-- Period -->
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div>
              <div class="text-xs text-gray-500">Periode Plan</div>
              <div class="font-medium">{{ fmtDate(detailData.plan_period_start) }} → {{ fmtDate(detailData.plan_period_end) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Progress</div>
              <div class="font-mono font-semibold">{{ detailData.progress_percent || 0 }}%</div>
            </div>
          </div>

          <!-- Gaps -->
          <section v-if="detailData.gaps?.length > 0">
            <h3 class="font-semibold text-sm mb-2 flex items-center gap-1">
              <ChartBarIcon class="w-4 h-4" /> Gap Analysis
            </h3>
            <ul class="space-y-1.5">
              <li v-for="(g, idx) in detailData.gaps" :key="idx"
                class="border border-gray-200 rounded px-3 py-2 text-sm">
                <div class="font-medium">{{ g.area }}</div>
                <div v-if="g.current_level || g.target_level" class="text-xs text-gray-500 mt-0.5">
                  Current: {{ g.current_level || '-' }} → Target: {{ g.target_level || '-' }}
                </div>
                <div v-if="g.evidence" class="text-xs text-gray-600 mt-0.5 italic">
                  Evidence: {{ g.evidence }}
                </div>
              </li>
            </ul>
          </section>

          <!-- Action items -->
          <section v-if="detailData.action_items?.length > 0">
            <h3 class="font-semibold text-sm mb-2">Action Items ({{ detailData.action_items.length }})</h3>
            <ul class="space-y-1.5">
              <li v-for="(a, idx) in detailData.action_items" :key="idx"
                class="border border-gray-200 rounded px-3 py-2 text-sm flex items-start gap-2">
                <CheckCircleIcon v-if="(a.status || 'PENDING').toUpperCase() === 'COMPLETED'"
                  class="w-4 h-4 text-emerald-600 mt-0.5 flex-shrink-0" />
                <XCircleIcon v-else class="w-4 h-4 text-gray-300 mt-0.5 flex-shrink-0" />
                <div class="flex-1 min-w-0">
                  <div :class="(a.status || 'PENDING').toUpperCase() === 'COMPLETED' ? 'line-through text-gray-500' : ''">
                    {{ a.action }}
                  </div>
                  <div class="text-xs text-gray-500 mt-0.5">
                    Owner: {{ a.owner || '-' }}
                    <span v-if="a.due_date"> · Due: {{ fmtDate(a.due_date) }}</span>
                    <span v-if="a.status"> · {{ a.status }}</span>
                  </div>
                </div>
              </li>
            </ul>
          </section>

          <!-- Recommended trainings -->
          <section v-if="detailData.recommended_trainings?.length > 0">
            <h3 class="font-semibold text-sm mb-2">Rekomendasi Training</h3>
            <ul class="list-disc list-inside text-sm space-y-0.5">
              <li v-for="(t, idx) in detailData.recommended_trainings" :key="idx">
                {{ t.title || t }}
                <span v-if="t.provider" class="text-xs text-gray-500">({{ t.provider }})</span>
              </li>
            </ul>
          </section>

          <!-- Notes -->
          <div v-if="detailData.manager_notes" class="bg-blue-50 border border-blue-200 rounded p-3 text-sm">
            <div class="text-xs font-semibold text-blue-700 mb-1">Manager Notes</div>
            <p class="whitespace-pre-line">{{ detailData.manager_notes }}</p>
          </div>
          <div v-if="detailData.employee_notes" class="bg-emerald-50 border border-emerald-200 rounded p-3 text-sm">
            <div class="text-xs font-semibold text-emerald-700 mb-1">Employee Notes</div>
            <p class="whitespace-pre-line">{{ detailData.employee_notes }}</p>
          </div>
        </div>

        <div class="px-6 py-3 border-t flex justify-end gap-2 sticky bottom-0 bg-white">
          <button v-if="canManage && ['DRAFT','APPROVAL_REJECTED'].includes(detailData.status)"
            class="btn-primary text-sm" @click="finalize(detailData.id)">
            Submit Activation Approval
          </button>
          <button v-if="canManage && detailData.status === 'ACTIVE'"
            class="btn-primary text-sm" @click="complete(detailData.id)">
            Mark Completed
          </button>
          <button v-if="canManage && ['DRAFT','APPROVAL_REJECTED','ACTIVE'].includes(detailData.status)"
            class="text-sm px-3 py-1.5 rounded border border-rose-300 text-rose-600 hover:bg-rose-50"
            @click="cancelIdp(detailData.id)">
            Cancel
          </button>
          <button class="btn-secondary" @click="showDetailModal = null">Tutup</button>
        </div>
      </div>
    </div>
  </div>
</template>
