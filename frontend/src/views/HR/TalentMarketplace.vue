<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import {
  UserGroupIcon, BriefcaseIcon, SparklesIcon, ChartBarIcon,
  PlusIcon, ArrowPathIcon, CheckBadgeIcon, MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 17 / H3.6 — Talent Marketplace.
 *
 * Tabs:
 *   1. My Skills — karyawan declare/lihat skill, lihat status validasi
 *   2. Internal Job Board — list posisi internal, apply, lihat status
 *   3. Skill Validation — supervisor/HR review skill yang menunggu validasi
 *   4. 9-Box Grid — HRBP map performance × potential (HR-only)
 *
 * Backend endpoints:
 *   GET/POST /employees/:id/skills
 *   POST /employee-skills/:id/validate
 *   GET/POST /internal-job-postings
 *   POST /internal-job-postings/:id/apply
 *   GET /internal-job-postings/:id/recommended-candidates
 *   GET /talent-pool/9-box
 */

const auth = useAuthStore()
const toast = useToast()

const tab = ref('skills')

const isHR = computed(() =>
  auth.can('talent_pool.manage') || auth.can('employee.update') || auth.isSuperAdmin
)

// ─── Skills ─────────────────────────────────────────
const mySkills = ref([])
const showAddSkill = ref(false)
const skillForm = reactive({ name: '', category: 'TECHNICAL', level: 3, evidence: '' })

async function loadMySkills() {
  if (! auth.user?.employee_id) return
  try {
    const res = await api.get(`/employees/${auth.user.employee_id}/skills`)
    mySkills.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat skill')
  }
}

async function addSkill() {
  if (! skillForm.name?.trim() || skillForm.name.length < 2) {
    toast.warning('Nama skill minimal 2 karakter')
    return
  }
  try {
    await api.post(`/employees/${auth.user.employee_id}/skills`, skillForm)
    toast.success('Skill ditambahkan')
    showAddSkill.value = false
    Object.assign(skillForm, { name: '', category: 'TECHNICAL', level: 3, evidence: '' })
    loadMySkills()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menambah skill')
  }
}

// ─── Internal job board ─────────────────────────────
const internalPostings = ref([])
const search = ref('')

async function loadInternalPostings() {
  try {
    const params = {}
    if (search.value) params.q = search.value
    const res = await api.get('/internal-job-postings', { params })
    internalPostings.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat internal job board')
  }
}

async function applyInternal(p) {
  try {
    await api.post(`/internal-job-postings/${p.id}/apply`, {
      cover_letter: prompt('Cover letter / motivasi singkat:') || '',
    })
    toast.success('Lamaran internal terkirim')
    loadInternalPostings()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal apply')
  }
}

// ─── 9-box grid (HR only) ───────────────────────────
const nineBox = ref(null)

async function load9Box() {
  try {
    const res = await api.get('/talent-pool/9-box')
    nineBox.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat 9-box')
  }
}

const grid9Box = computed(() => {
  if (! nineBox.value?.cells) return []
  // Cells expected as 3x3 matrix indexed by performance (low/mid/high) × potential (low/mid/high)
  // Map ke [{ row, col, label, employees: [] }]
  const cells = []
  const labels = [
    ['Detractor', 'Effective', 'Star'],
    ['Inconsistent Player', 'Solid Performer', 'High Potential'],
    ['Risk', 'Underperformer', 'Up-or-Out'],
  ]
  // row 0 = performance HIGH (top); col 0 = potential LOW (left)
  for (let r = 0; r < 3; r++) {
    for (let c = 0; c < 3; c++) {
      const key = `${r}_${c}`
      cells.push({
        row: r, col: c,
        label: labels[r][c],
        employees: nineBox.value.cells[key] || [],
      })
    }
  }
  return cells
})

function levelColor(lvl) {
  const map = {
    1: 'bg-red-100 text-red-700',
    2: 'bg-amber-100 text-amber-700',
    3: 'bg-blue-100 text-blue-700',
    4: 'bg-emerald-100 text-emerald-700',
    5: 'bg-purple-100 text-purple-700',
  }
  return map[lvl] || 'bg-gray-100 text-gray-700'
}

function cellColor(row, col) {
  // 3x3: top-right = best, bottom-left = worst
  if (row === 0 && col === 2) return 'bg-emerald-50 border-emerald-300'
  if (row === 0 || col === 2) return 'bg-emerald-50/50 border-emerald-200'
  if (row === 1 && col === 1) return 'bg-blue-50 border-blue-200'
  if (row === 2 && col === 0) return 'bg-red-50 border-red-300'
  if (row === 2 || col === 0) return 'bg-amber-50 border-amber-200'
  return 'bg-gray-50 border-gray-200'
}

function switchTab(t) {
  tab.value = t
  if (t === 'skills') loadMySkills()
  else if (t === 'jobs') loadInternalPostings()
  else if (t === 'ninebox' && isHR.value) load9Box()
}

onMounted(loadMySkills)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <SparklesIcon class="w-6 h-6" /> Talent Marketplace
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Kelola skill, jelajahi lowongan internal, dan lihat talent map perusahaan.
        </p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-4 flex gap-1">
      <button v-for="t in [
        { id: 'skills', label: 'Skill Saya', icon: SparklesIcon },
        { id: 'jobs', label: 'Internal Job Board', icon: BriefcaseIcon },
        ...(isHR ? [{ id: 'ninebox', label: '9-Box Grid', icon: ChartBarIcon }] : []),
      ]" :key="t.id"
        @click="switchTab(t.id)"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors flex items-center gap-2',
          tab === t.id
            ? 'border-primary-600 text-primary-700'
            : 'border-transparent text-gray-500 hover:text-gray-700'
        ]"
      >
        <component :is="t.icon" class="w-4 h-4" />
        {{ t.label }}
      </button>
    </div>

    <!-- ───── Tab: Skills ───── -->
    <div v-if="tab === 'skills'">
      <div class="flex justify-between items-center mb-3">
        <p class="text-sm text-gray-600">
          Declare skill kamu di sini. Skill akan muncul di talent pool dan jadi referensi
          matching saat ada internal posting.
        </p>
        <button class="btn-primary" @click="showAddSkill = true">
          <PlusIcon class="w-4 h-4" /> Tambah Skill
        </button>
      </div>
      <div class="card">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Skill</th>
                <th>Kategori</th>
                <th>Level (1-5)</th>
                <th>Status</th>
                <th>Validator</th>
                <th>Update Terakhir</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="mySkills.length === 0">
                <td colspan="6" class="text-center py-8 text-gray-400">
                  Belum ada skill — klik "Tambah Skill" untuk mulai
                </td>
              </tr>
              <tr v-for="s in mySkills" :key="s.id">
                <td>
                  <div class="font-medium">{{ s.name }}</div>
                  <div v-if="s.evidence" class="text-xs text-gray-500 mt-0.5">
                    {{ s.evidence }}
                  </div>
                </td>
                <td>
                  <span class="text-xs uppercase tracking-wide text-gray-500">{{ s.category }}</span>
                </td>
                <td>
                  <span class="inline-block px-2 py-0.5 rounded text-xs font-medium" :class="levelColor(s.level)">
                    Level {{ s.level }}
                  </span>
                </td>
                <td>
                  <span v-if="s.is_validated" class="inline-flex items-center gap-1 text-emerald-600 text-xs font-medium">
                    <CheckBadgeIcon class="w-4 h-4" /> Validated
                  </span>
                  <span v-else class="text-xs text-amber-600">Pending validation</span>
                </td>
                <td class="text-xs text-gray-600">{{ s.validator_name || '—' }}</td>
                <td class="text-xs text-gray-500">{{ s.updated_at?.slice(0, 16) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ───── Tab: Internal Jobs ───── -->
    <div v-if="tab === 'jobs'">
      <div class="card mb-3">
        <div class="card-body flex items-center gap-3">
          <MagnifyingGlassIcon class="w-5 h-5 text-gray-400" />
          <input
            v-model="search"
            class="input flex-1 border-0 focus:ring-0"
            placeholder="Cari posisi, department, lokasi…"
            @input="loadInternalPostings"
          />
          <button class="btn-secondary" @click="loadInternalPostings">
            <ArrowPathIcon class="w-4 h-4" /> Refresh
          </button>
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div v-if="internalPostings.length === 0"
          class="md:col-span-2 card-body text-center py-12 text-gray-400 card">
          Belum ada internal posting yang aktif
        </div>
        <div v-for="p in internalPostings" :key="p.id" class="card">
          <div class="card-body">
            <div class="flex items-start justify-between gap-2">
              <div class="flex-1 min-w-0">
                <h3 class="font-semibold text-gray-900">{{ p.title }}</h3>
                <div class="text-xs text-gray-500 mt-1 flex flex-wrap gap-x-2">
                  <span v-if="p.department_name">{{ p.department_name }}</span>
                  <span v-if="p.position_name">• {{ p.position_name }}</span>
                  <span v-if="p.work_location">• {{ p.work_location }}</span>
                </div>
              </div>
              <span v-if="p.urgency"
                class="inline-block px-2 py-0.5 rounded-full text-xs"
                :class="p.urgency === 'HIGH' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'">
                {{ p.urgency }}
              </span>
            </div>
            <p class="text-sm text-gray-600 mt-2 line-clamp-3">{{ p.description }}</p>
            <div v-if="p.required_skills?.length" class="mt-2 flex flex-wrap gap-1">
              <span v-for="s in p.required_skills" :key="s"
                class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">
                {{ s }}
              </span>
            </div>
            <div class="mt-3 flex items-center justify-between text-xs text-gray-500">
              <span>Posted {{ p.created_at?.slice(0, 10) }}</span>
              <button
                v-if="! p.has_applied"
                class="btn-primary"
                @click="applyInternal(p)"
              >Apply</button>
              <span v-else class="text-emerald-600 font-medium">
                Sudah dilamar — status: {{ p.application_status }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ───── Tab: 9-Box (HR only) ───── -->
    <div v-if="tab === 'ninebox' && isHR">
      <div class="flex items-center justify-between mb-3">
        <p class="text-sm text-gray-600">
          Talent map: performance × potential. Cell kanan-atas = top talent (Star),
          cell kiri-bawah = perlu intervensi (Risk).
        </p>
        <button class="btn-secondary" @click="load9Box">
          <ArrowPathIcon class="w-4 h-4" /> Refresh
        </button>
      </div>

      <!-- Axis labels + grid -->
      <div class="grid grid-cols-12 gap-2">
        <div class="col-span-1 flex flex-col justify-around text-xs text-gray-600 font-medium pt-8 pb-2">
          <div class="text-center transform -rotate-90 whitespace-nowrap">PERFORMANCE</div>
        </div>
        <div class="col-span-11">
          <div class="grid grid-cols-3 gap-2 mb-2">
            <div class="text-center text-xs text-gray-500">Low Potential</div>
            <div class="text-center text-xs text-gray-500">Mid Potential</div>
            <div class="text-center text-xs text-gray-500 font-medium">High Potential →</div>
          </div>
          <div class="grid grid-cols-3 gap-2">
            <div v-for="cell in grid9Box" :key="`${cell.row}-${cell.col}`"
              class="border-2 rounded-lg p-3 min-h-[140px] transition-colors"
              :class="cellColor(cell.row, cell.col)">
              <div class="text-xs font-semibold text-gray-700 mb-2">{{ cell.label }}</div>
              <div class="space-y-1">
                <div v-for="emp in cell.employees.slice(0, 6)" :key="emp.employee_id"
                  class="text-xs px-2 py-1 bg-white rounded shadow-sm truncate" :title="emp.full_name">
                  {{ emp.full_name }}
                </div>
                <div v-if="cell.employees.length > 6" class="text-xs text-gray-500 italic px-2">
                  +{{ cell.employees.length - 6 }} lainnya
                </div>
                <div v-if="cell.employees.length === 0" class="text-xs text-gray-400 italic px-2 py-1">
                  Kosong
                </div>
              </div>
            </div>
          </div>
          <div class="text-center text-xs text-gray-500 mt-2">↑ High Performance / ↓ Low Performance</div>
        </div>
      </div>

      <div v-if="nineBox?.summary" class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="card-body bg-emerald-50 rounded-lg border border-emerald-100">
          <div class="text-xs text-emerald-700">High Potential + High Performance</div>
          <div class="text-2xl font-bold text-emerald-900">{{ nineBox.summary.stars || 0 }}</div>
        </div>
        <div class="card-body bg-blue-50 rounded-lg border border-blue-100">
          <div class="text-xs text-blue-700">Solid Core (mid x mid)</div>
          <div class="text-2xl font-bold text-blue-900">{{ nineBox.summary.solid || 0 }}</div>
        </div>
        <div class="card-body bg-amber-50 rounded-lg border border-amber-100">
          <div class="text-xs text-amber-700">At Risk</div>
          <div class="text-2xl font-bold text-amber-900">{{ nineBox.summary.at_risk || 0 }}</div>
        </div>
        <div class="card-body bg-red-50 rounded-lg border border-red-100">
          <div class="text-xs text-red-700">Up-or-Out</div>
          <div class="text-2xl font-bold text-red-900">{{ nineBox.summary.up_or_out || 0 }}</div>
        </div>
      </div>
    </div>

    <!-- Modal: Add skill -->
    <div v-if="showAddSkill" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showAddSkill = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b">
          <h3 class="font-semibold">Tambah Skill</h3>
        </div>
        <div class="p-5 space-y-3">
          <div>
            <label class="label">Nama Skill</label>
            <input v-model="skillForm.name" class="input" placeholder="contoh: PHP, Public Speaking, Excel" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Kategori</label>
              <select v-model="skillForm.category" class="input">
                <option value="TECHNICAL">Technical</option>
                <option value="FUNCTIONAL">Functional</option>
                <option value="LEADERSHIP">Leadership</option>
                <option value="LANGUAGE">Language</option>
                <option value="SOFT_SKILL">Soft Skill</option>
              </select>
            </div>
            <div>
              <label class="label">Level (1-5)</label>
              <input type="number" min="1" max="5" v-model.number="skillForm.level" class="input" />
            </div>
          </div>
          <div>
            <label class="label">Bukti / Sertifikasi (opsional)</label>
            <textarea v-model="skillForm.evidence" class="input" rows="2"
              placeholder="contoh: sertifikat AWS, project portfolio, dst"></textarea>
          </div>
          <p class="text-xs text-gray-500">
            Skill yang baru ditambahkan berstatus pending sampai supervisor/HR memvalidasi.
          </p>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showAddSkill = false">Batal</button>
          <button class="btn-primary" @click="addSkill">Tambah</button>
        </div>
      </div>
    </div>
  </div>
</template>