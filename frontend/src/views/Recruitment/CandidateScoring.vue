<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import {
  ChartBarSquareIcon, PlusIcon, TrashIcon, ArrowPathIcon,
  PlayIcon, InformationCircleIcon, StarIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 10 / H2.4 — Candidate Scoring Engine config.
 *
 * HR config scoring criteria per job posting + bulk recompute composite_score
 * untuk semua kandidat di posting tsb.
 *
 * Endpoints:
 *   GET  /job-postings                                    — list job
 *   GET  /job-postings/:id/scoring-criteria               — current config (jika ada)
 *   PUT  /job-postings/:id/scoring-criteria               — set config
 *   POST /candidates/:id/recalculate-score                — single
 *   POST /job-postings/:id/recalculate-scores             — bulk recompute
 *
 * Catatan: jika endpoint `/scoring-criteria` belum tersedia, fallback ke
 * direct write via PUT /job-postings/:id (controller backend akan extract
 * field `scoring_criteria` dari payload). Sama pola dengan H2.7 simulate.
 */

const toast = useToast()

const jobs = ref([])
const jobId = ref('')
const criteria = ref([])
const loading = ref(false)
const saving = ref(false)
const recomputing = ref(false)

const CRITERION_TYPES = [
  { id: 'online_test',       label: 'Online Test Score',     desc: 'Hasil test (TPA/English/Technical) — % sesuai passing grade' },
  { id: 'salary_fit',         label: 'Salary Fit',            desc: 'Kesesuaian ekspektasi gaji dgn range MPP' },
  { id: 'experience',        label: 'Years of Experience',   desc: 'Selisih experience kandidat vs target_value (tahun)' },
  { id: 'available_date',    label: 'Available Date',        desc: 'Selisih hari available_from vs target_value' },
  { id: 'education_level',   label: 'Education Level',       desc: 'Tingkat pendidikan vs minimum requirement' },
  { id: 'manual_override',   label: 'Manual Override',       desc: 'Bobot khusus jika recruiter set manual_score_override' },
]

async function loadJobs() {
  try {
    const res = await api.get('/job-postings', { params: { per_page: 200 } })
    jobs.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat job postings')
  }
}

async function loadCriteria() {
  if (! jobId.value) {
    criteria.value = []
    return
  }
  loading.value = true
  try {
    // Coba endpoint dedicated; fallback ke job posting detail kalau 404
    let cfg = []
    try {
      const res = await api.get(`/job-postings/${jobId.value}/scoring-criteria`)
      cfg = res.data.data || []
    } catch (_) {
      const res = await api.get(`/job-postings/${jobId.value}`)
      cfg = (res.data.data?.scoring_criteria) || []
    }
    criteria.value = Array.isArray(cfg) ? cfg : []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat scoring criteria')
  } finally {
    loading.value = false
  }
}

function addCriterion() {
  // Tambah criterion baru: pilih tipe pertama yang belum dipakai
  const used = new Set(criteria.value.map(c => c.criterion_type))
  const available = CRITERION_TYPES.find(t => ! used.has(t.id))
  if (! available) {
    toast.warning('Semua tipe criterion sudah ditambahkan')
    return
  }
  criteria.value.push({
    criterion_type: available.id,
    weight: 25,
    target_value: '',
  })
}

function removeCriterion(idx) {
  criteria.value.splice(idx, 1)
}

const totalWeight = computed(() =>
  criteria.value.reduce((sum, c) => sum + (Number(c.weight) || 0), 0)
)

async function saveCriteria() {
  if (criteria.value.length === 0) {
    toast.warning('Tambahkan minimal 1 criterion')
    return
  }
  if (totalWeight.value <= 0) {
    toast.warning('Total bobot harus > 0')
    return
  }
  saving.value = true
  try {
    const payload = { scoring_criteria: criteria.value }
    try {
      await api.put(`/job-postings/${jobId.value}/scoring-criteria`, payload)
    } catch (_) {
      // Fallback: PUT job posting full (backend akan ambil field scoring_criteria)
      await api.put(`/job-postings/${jobId.value}`, payload)
    }
    toast.success('Scoring criteria tersimpan')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  } finally {
    saving.value = false
  }
}

async function recomputeAll() {
  if (! jobId.value) return
  if (! confirm('Recompute composite score untuk semua kandidat di posting ini?')) return
  recomputing.value = true
  try {
    const res = await api.post(`/job-postings/${jobId.value}/recalculate-scores`)
    const data = res.data.data || {}
    toast.success(`Recompute selesai: ${data.updated || 0} kandidat di-update`)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal recompute')
  } finally {
    recomputing.value = false
  }
}

function criterionLabel(id) {
  return CRITERION_TYPES.find(t => t.id === id)?.label || id
}

function criterionDesc(id) {
  return CRITERION_TYPES.find(t => t.id === id)?.desc || ''
}

onMounted(loadJobs)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ChartBarSquareIcon class="w-6 h-6" /> Candidate Scoring Config
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Definisikan kriteria & bobot untuk composite score otomatis tiap kandidat di posting tsb.
          Bobot tidak harus jumlahnya 100% — engine pakai sum sebagai denominator.
        </p>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="label">Job Posting</label>
          <select v-model="jobId" class="input" @change="loadCriteria">
            <option value="">— Pilih posting —</option>
            <option v-for="j in jobs" :key="j.id" :value="j.id">
              {{ j.title }}{{ j.position_name ? ` — ${j.position_name}` : '' }}
              ({{ j.status }})
            </option>
          </select>
        </div>
        <div class="text-right">
          <button class="btn-secondary" @click="recomputeAll"
            :disabled="! jobId || recomputing">
            <PlayIcon class="w-4 h-4" />
            {{ recomputing ? 'Recomputing…' : 'Recompute Semua Kandidat' }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="! jobId" class="card">
      <div class="card-body text-center py-12 text-gray-400">
        Pilih job posting untuk konfigurasi scoring
      </div>
    </div>

    <div v-else class="card">
      <div class="card-header flex items-center justify-between">
        <span class="font-medium">Kriteria Scoring</span>
        <div class="flex items-center gap-2">
          <span class="text-xs text-gray-500">
            Total bobot: <strong :class="totalWeight > 0 ? 'text-emerald-700' : 'text-red-600'">
              {{ totalWeight }}%
            </strong>
          </span>
          <button class="btn-secondary text-xs" @click="addCriterion"
            :disabled="criteria.length >= CRITERION_TYPES.length">
            <PlusIcon class="w-4 h-4" /> Tambah
          </button>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Tipe Criterion</th>
              <th class="text-center w-32">Bobot (%)</th>
              <th>Target / Reference Value</th>
              <th>Deskripsi</th>
              <th class="text-right w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading">
              <td colspan="5" class="text-center py-8 text-gray-400">Memuat…</td>
            </tr>
            <tr v-else-if="criteria.length === 0">
              <td colspan="5" class="text-center py-8 text-gray-400">
                Belum ada criterion — klik "Tambah" untuk konfigurasi
              </td>
            </tr>
            <tr v-for="(c, idx) in criteria" :key="idx">
              <td>
                <select v-model="c.criterion_type" class="input">
                  <option v-for="t in CRITERION_TYPES" :key="t.id" :value="t.id">
                    {{ t.label }}
                  </option>
                </select>
              </td>
              <td class="text-center">
                <input type="number" min="0" max="100" step="5"
                  v-model.number="c.weight" class="input w-24 text-center mx-auto" />
              </td>
              <td>
                <input v-model="c.target_value" class="input"
                  :placeholder="c.criterion_type === 'experience' ? 'mis. 3 tahun'
                  : c.criterion_type === 'available_date' ? 'mis. 30 hari'
                  : c.criterion_type === 'salary_fit' ? 'mis. 10000000 (target gaji)'
                  : 'opsional'" />
              </td>
              <td class="text-xs text-gray-600">
                {{ criterionDesc(c.criterion_type) }}
              </td>
              <td class="text-right">
                <button class="btn-icon text-red-600" @click="removeCriterion(idx)"
                  title="Hapus">
                  <TrashIcon class="w-4 h-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="px-5 py-3 border-t flex justify-end gap-2">
        <button class="btn-primary" @click="saveCriteria"
          :disabled="saving || criteria.length === 0">
          {{ saving ? 'Menyimpan…' : 'Simpan Konfigurasi' }}
        </button>
      </div>
    </div>

    <!-- Help -->
    <div v-if="jobId" class="mt-4 flex items-start gap-2 text-sm text-gray-500 bg-gray-50 rounded p-3">
      <InformationCircleIcon class="w-5 h-5 flex-shrink-0 text-gray-400 mt-0.5" />
      <div>
        <strong>Cara kerja:</strong> Composite score = sum(weight × per-criterion-score) ÷ sum(weight).
        Per-criterion-score selalu 0-100. Setelah simpan, composite_score di-update otomatis
        saat ada perubahan data kandidat (online test result, salary expectation, available_from)
        — atau pakai tombol "Recompute Semua Kandidat" untuk batch refresh manual.
        Recruiter tetap bisa override via field <code>manual_score_override</code> di detail kandidat.
      </div>
    </div>
  </div>
</template>