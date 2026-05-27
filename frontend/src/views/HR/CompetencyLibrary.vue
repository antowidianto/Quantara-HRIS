<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import {
  AcademicCapIcon, PlusIcon, ArrowPathIcon, MagnifyingGlassIcon,
  UserIcon, ChartBarIcon, ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 14 / H3.1 — Competency Library + Gap Analysis.
 *
 * Tabs:
 *   1. Master Competencies — CRUD library kompetensi (HR)
 *   2. Position Profile — set target level competency per posisi
 *   3. Gap Analysis — visualisasi radar chart (sederhana via bar) employee vs profile
 *
 * Endpoints:
 *   GET/POST /competencies
 *   GET/PUT /positions/:id/competency-profile
 *   GET /employees/:id/competency-gap
 *   GET /employees/:id/training-recommendations
 */

const auth = useAuthStore()
const toast = useToast()

const tab = ref('library')
const isHR = computed(() =>
  auth.can('competency.manage') || auth.can('employee.update') || auth.isSuperAdmin
)

// ─── Library ────────────────────────────────────────
const competencies = ref([])
const filterCategory = ref('')
const showAddComp = ref(false)
const compForm = reactive({
  name: '', code: '', description: '', category: 'TECHNICAL', is_active: 1,
})

async function loadCompetencies() {
  try {
    const params = {}
    if (filterCategory.value) params.category = filterCategory.value
    const res = await api.get('/competencies', { params })
    competencies.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat competency')
  }
}

async function addCompetency() {
  if (! compForm.name?.trim()) {
    toast.warning('Nama kompetensi wajib')
    return
  }
  try {
    await api.post('/competencies', compForm)
    toast.success('Competency ditambahkan')
    showAddComp.value = false
    Object.assign(compForm, { name: '', code: '', description: '', category: 'TECHNICAL', is_active: 1 })
    loadCompetencies()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menambah')
  }
}

// ─── Position profile ───────────────────────────────
const positions = ref([])
const positionId = ref('')
const profile = ref([])

async function loadPositions() {
  try {
    const res = await api.get('/positions', { params: { per_page: 200 } })
    positions.value = res.data.data || []
  } catch (_) {}
}

async function loadProfile() {
  if (! positionId.value) {
    profile.value = []
    return
  }
  try {
    const res = await api.get(`/positions/${positionId.value}/competency-profile`)
    profile.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat profile')
  }
}

async function saveProfile() {
  // Build payload dari competencies (semua yang aktif) — hanya target_level yang
  // > 0 yang dianggap sebagai bagian dari profile. is_critical dari toggle UI.
  const items = profile.value
    .filter(p => p.target_level > 0)
    .map(p => ({
      competency_id: p.competency_id,
      target_level: p.target_level,
      is_critical: p.is_critical ? 1 : 0,
    }))
  if (items.length === 0) {
    toast.warning('Tambahkan minimal 1 kompetensi ke profile')
    return
  }
  try {
    await api.put(`/positions/${positionId.value}/competency-profile`, { profile: items })
    toast.success('Profile tersimpan')
    loadProfile()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

function addToProfile() {
  // Tambah baris kosong baru dari competency yang belum ada di profile
  const usedIds = new Set(profile.value.map(p => p.competency_id))
  const unused = competencies.value.find(c => ! usedIds.has(c.id) && c.is_active)
  if (! unused) {
    toast.warning('Semua kompetensi sudah ada di profile')
    return
  }
  profile.value.push({
    competency_id: unused.id,
    competency_name: unused.name,
    code: unused.code,
    target_level: 3,
    is_critical: 0,
  })
}

function removeFromProfile(idx) {
  profile.value.splice(idx, 1)
}

// ─── Gap analysis ───────────────────────────────────
const gapEmployeeId = ref('')
const gap = ref(null)
const trainingRecs = ref([])

async function loadGap() {
  if (! gapEmployeeId.value) {
    gap.value = null
    return
  }
  try {
    const [gapRes, trainRes] = await Promise.all([
      api.get(`/employees/${gapEmployeeId.value}/competency-gap`),
      api.get(`/employees/${gapEmployeeId.value}/training-recommendations`),
    ])
    gap.value = gapRes.data.data
    trainingRecs.value = trainRes.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat gap')
  }
}

function gapColor(actual, target) {
  const diff = (Number(actual) || 0) - Number(target)
  if (diff >= 0) return 'bg-emerald-500'
  if (diff >= -1) return 'bg-amber-500'
  return 'bg-red-500'
}

function switchTab(t) {
  tab.value = t
  if (t === 'library') loadCompetencies()
  else if (t === 'profile') {
    loadCompetencies()
    loadPositions()
  }
}

onMounted(() => {
  loadCompetencies()
  loadPositions()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <AcademicCapIcon class="w-6 h-6" /> Competency Library
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Master kompetensi, profil per jabatan, dan gap analysis terhadap profil ideal.
        </p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-4 flex gap-1">
      <button v-for="t in [
        { id: 'library', label: 'Master Competencies' },
        ...(isHR ? [{ id: 'profile', label: 'Position Profile' }] : []),
        { id: 'gap', label: 'Gap Analysis' },
      ]" :key="t.id" @click="switchTab(t.id)"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
          tab === t.id ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700'
        ]">
        {{ t.label }}
      </button>
    </div>

    <!-- ───── Tab: Library ───── -->
    <div v-if="tab === 'library'">
      <div class="flex justify-between items-center mb-3 gap-2 flex-wrap">
        <select v-model="filterCategory" class="input max-w-xs" @change="loadCompetencies">
          <option value="">Semua kategori</option>
          <option value="CORE">Core</option>
          <option value="LEADERSHIP">Leadership</option>
          <option value="FUNCTIONAL">Functional</option>
          <option value="TECHNICAL">Technical</option>
          <option value="BEHAVIORAL">Behavioral</option>
        </select>
        <button v-if="isHR" class="btn-primary" @click="showAddComp = true">
          <PlusIcon class="w-4 h-4" /> Tambah Competency
        </button>
      </div>
      <div class="card">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Nama</th>
                <th>Kategori</th>
                <th>Deskripsi</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="competencies.length === 0">
                <td colspan="5" class="text-center py-8 text-gray-400">Belum ada competency</td>
              </tr>
              <tr v-for="c in competencies" :key="c.id">
                <td class="font-mono text-xs">{{ c.code }}</td>
                <td class="font-medium">{{ c.name }}</td>
                <td>
                  <span class="inline-block px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-700">
                    {{ c.category }}
                  </span>
                </td>
                <td class="text-sm text-gray-600 max-w-md">{{ c.description }}</td>
                <td>
                  <span :class="c.is_active ? 'text-emerald-700' : 'text-gray-400'" class="text-xs">
                    {{ c.is_active ? 'Aktif' : 'Nonaktif' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ───── Tab: Position Profile ───── -->
    <div v-if="tab === 'profile'">
      <div class="card mb-3">
        <div class="card-body grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
          <div class="md:col-span-2">
            <label class="label">Pilih Jabatan</label>
            <select v-model="positionId" class="input" @change="loadProfile">
              <option value="">— Pilih jabatan —</option>
              <option v-for="p in positions" :key="p.id" :value="p.id">
                {{ p.name }}{{ p.department_name ? ` — ${p.department_name}` : '' }}
              </option>
            </select>
          </div>
          <div class="text-right">
            <button class="btn-secondary" @click="addToProfile" :disabled="! positionId">
              <PlusIcon class="w-4 h-4" /> Tambah Baris
            </button>
          </div>
        </div>
      </div>

      <div v-if="positionId" class="card">
        <div class="card-header flex items-center justify-between">
          <span class="font-medium">Target Level Kompetensi</span>
          <button class="btn-primary" @click="saveProfile" :disabled="profile.length === 0">
            Simpan Profile
          </button>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Kompetensi</th>
                <th class="text-center">Target Level (1-5)</th>
                <th class="text-center">Critical?</th>
                <th></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="profile.length === 0">
                <td colspan="4" class="text-center py-8 text-gray-400">
                  Belum ada profile — klik "Tambah Baris"
                </td>
              </tr>
              <tr v-for="(p, idx) in profile" :key="`${p.competency_id}-${idx}`">
                <td>
                  <select v-model="p.competency_id" class="input">
                    <option v-for="c in competencies" :key="c.id" :value="c.id">
                      {{ c.code }} — {{ c.name }}
                    </option>
                  </select>
                </td>
                <td class="text-center">
                  <input type="number" min="1" max="5" v-model.number="p.target_level"
                    class="input w-20 text-center mx-auto" />
                </td>
                <td class="text-center">
                  <input type="checkbox" v-model="p.is_critical" />
                </td>
                <td class="text-right">
                  <button class="btn-icon text-red-600" @click="removeFromProfile(idx)">×</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ───── Tab: Gap Analysis ───── -->
    <div v-if="tab === 'gap'">
      <div class="card mb-3">
        <div class="card-body">
          <label class="label">Employee ID</label>
          <input type="number" v-model.number="gapEmployeeId" class="input"
            placeholder="Masukkan employee ID untuk lihat gap"
            @change="loadGap" />
          <p class="text-xs text-gray-500 mt-1">
            Karyawan harus punya posisi aktif yang sudah di-set competency profile-nya.
          </p>
        </div>
      </div>

      <div v-if="! gap" class="card">
        <div class="card-body text-center py-12 text-gray-400">
          Pilih employee untuk melihat gap analysis
        </div>
      </div>

      <div v-else>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
          <div class="card-body bg-blue-50 rounded-lg border border-blue-100">
            <div class="text-xs text-blue-700">Total Competency</div>
            <div class="text-2xl font-bold text-blue-900">{{ gap.gaps?.length || 0 }}</div>
          </div>
          <div class="card-body bg-emerald-50 rounded-lg border border-emerald-100">
            <div class="text-xs text-emerald-700">Already Meeting Target</div>
            <div class="text-2xl font-bold text-emerald-900">
              {{ (gap.gaps || []).filter(g => Number(g.actual) >= Number(g.target)).length }}
            </div>
          </div>
          <div class="card-body bg-red-50 rounded-lg border border-red-100">
            <div class="text-xs text-red-700">Need Development</div>
            <div class="text-2xl font-bold text-red-900">
              {{ (gap.gaps || []).filter(g => Number(g.actual) < Number(g.target)).length }}
            </div>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-header flex items-center gap-2">
            <ChartBarIcon class="w-4 h-4" />
            <span class="font-medium">Gap Detail</span>
          </div>
          <div class="p-5 space-y-3">
            <div v-for="g in gap.gaps || []" :key="g.competency_id" class="space-y-1">
              <div class="flex items-center justify-between text-sm">
                <div>
                  <span class="font-medium">{{ g.competency_name }}</span>
                  <span v-if="g.is_critical"
                    class="ml-2 inline-block px-1.5 py-0.5 rounded text-xs bg-red-100 text-red-700">
                    Critical
                  </span>
                </div>
                <div class="text-xs text-gray-600">
                  Actual: <strong>{{ g.actual || 0 }}</strong> / Target: <strong>{{ g.target }}</strong>
                </div>
              </div>
              <div class="relative w-full bg-gray-100 rounded h-3 overflow-hidden">
                <div class="absolute left-0 top-0 h-full transition-all"
                  :class="gapColor(g.actual, g.target)"
                  :style="{ width: ((Number(g.actual) || 0) / 5 * 100) + '%' }"></div>
                <div class="absolute top-0 h-full border-r-2 border-gray-700 border-dashed"
                  :style="{ left: (Number(g.target) / 5 * 100) + '%' }"
                  :title="`Target: ${g.target}`"></div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="trainingRecs.length" class="card">
          <div class="card-header flex items-center gap-2">
            <AcademicCapIcon class="w-4 h-4" />
            <span class="font-medium">Rekomendasi Training</span>
          </div>
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Training</th>
                  <th>Untuk Competency</th>
                  <th class="text-right">Gap Score</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="t in trainingRecs" :key="t.training_id">
                  <td class="font-medium">{{ t.training_title }}</td>
                  <td>{{ t.competency_name }}</td>
                  <td class="text-right">
                    <span class="inline-block px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-700">
                      {{ Number(t.gap_score || 0).toFixed(1) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: Add competency -->
    <div v-if="showAddComp" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showAddComp = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b">
          <h3 class="font-semibold">Tambah Competency</h3>
        </div>
        <div class="p-5 space-y-3">
          <div>
            <label class="label">Nama</label>
            <input v-model="compForm.name" class="input" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Code (opsional)</label>
              <input v-model="compForm.code" class="input"
                placeholder="auto dari nama" />
            </div>
            <div>
              <label class="label">Kategori</label>
              <select v-model="compForm.category" class="input">
                <option value="CORE">Core</option>
                <option value="LEADERSHIP">Leadership</option>
                <option value="FUNCTIONAL">Functional</option>
                <option value="TECHNICAL">Technical</option>
                <option value="BEHAVIORAL">Behavioral</option>
              </select>
            </div>
          </div>
          <div>
            <label class="label">Deskripsi</label>
            <textarea v-model="compForm.description" class="input" rows="3"></textarea>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showAddComp = false">Batal</button>
          <button class="btn-primary" @click="addCompetency">Tambah</button>
        </div>
      </div>
    </div>
  </div>
</template>