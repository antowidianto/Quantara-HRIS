<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtCurrency } from '@/composables/useResource'
import {
  PresentationChartLineIcon, PlusIcon, ArrowPathIcon, PlayIcon,
  ArrowsRightLeftIcon, InformationCircleIcon, BeakerIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 19+ / H4.3 — Workforce Planning & Scenario Modeling.
 *
 * UI untuk:
 *   1. List scenarios (yang sudah pernah dibuat)
 *   2. Create new scenario (attrition rate, hiring plan, salary growth)
 *   3. Run Monte Carlo simulation (1000 iterasi default)
 *   4. Lihat hasil distribusi p10/p50/p90 dari headcount + total cost
 *   5. Compare dua scenario side-by-side
 *
 * Endpoints:
 *   GET/POST /workforce-scenarios
 *   POST /workforce-scenarios/:id/run
 *   POST /workforce-scenarios/compare
 */

const toast = useToast()

const scenarios = ref([])
const showCreate = ref(false)
const createForm = reactive({
  name: '',
  description: '',
  starting_headcount: 100,
  monthly_attrition_rate: 0.02,    // 2%/bulan
  monthly_hiring_target: 5,
  avg_salary: 10000000,
  monthly_salary_growth: 0.005,    // 0.5%/bulan
  horizon_months: 12,
  iterations: 1000,
})

const running = ref(null) // scenario id yang sedang di-run
const showCompare = ref(false)
const compareIds = ref([])
const compareResult = ref(null)
const expandedRun = ref(null)

async function loadScenarios() {
  try {
    const res = await api.get('/workforce-scenarios')
    scenarios.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat scenarios')
  }
}

async function createScenario() {
  if (! createForm.name?.trim()) {
    toast.warning('Nama wajib')
    return
  }
  try {
    await api.post('/workforce-scenarios', createForm)
    toast.success('Scenario tersimpan')
    showCreate.value = false
    Object.assign(createForm, {
      name: '', description: '', starting_headcount: 100,
      monthly_attrition_rate: 0.02, monthly_hiring_target: 5, avg_salary: 10000000,
      monthly_salary_growth: 0.005, horizon_months: 12, iterations: 1000,
    })
    loadScenarios()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal create')
  }
}

async function runScenario(s) {
  running.value = s.id
  try {
    await api.post(`/workforce-scenarios/${s.id}/run`)
    toast.success('Simulation selesai')
    await loadScenarios()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal run')
  } finally {
    running.value = null
  }
}

async function compareScenarios() {
  if (compareIds.value.length !== 2) {
    toast.warning('Pilih 2 scenario untuk compare')
    return
  }
  try {
    const res = await api.post('/workforce-scenarios/compare', {
      scenario_ids: compareIds.value,
    })
    compareResult.value = res.data.data
    showCompare.value = true
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal compare')
  }
}

function toggleCompareSelect(id) {
  const i = compareIds.value.indexOf(id)
  if (i >= 0) compareIds.value.splice(i, 1)
  else if (compareIds.value.length < 2) compareIds.value.push(id)
  else toast.warning('Hanya bisa pilih 2 scenario')
}

function fmtPct(n, digits = 1) {
  return (Number(n || 0) * 100).toFixed(digits) + '%'
}

const lastRun = computed(() => (s) => {
  return s.runs?.[0] || null
})

onMounted(loadScenarios)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <PresentationChartLineIcon class="w-6 h-6" /> Workforce Scenario Modeling
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Monte Carlo simulation untuk proyeksi headcount dan biaya workforce dalam berbagai skenario.
          Distribusi p10/p50/p90 membantu antisipasi best-case, expected, dan worst-case.
        </p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary" @click="loadScenarios">
          <ArrowPathIcon class="w-4 h-4" /> Refresh
        </button>
        <button class="btn-secondary" @click="compareScenarios" :disabled="compareIds.length !== 2">
          <ArrowsRightLeftIcon class="w-4 h-4" /> Compare ({{ compareIds.length }}/2)
        </button>
        <button class="btn-primary" @click="showCreate = true">
          <PlusIcon class="w-4 h-4" /> Scenario Baru
        </button>
      </div>
    </div>

    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th class="w-10"></th>
              <th>Scenario</th>
              <th class="text-right">HC Awal</th>
              <th class="text-right">Horizon</th>
              <th class="text-right">Attrition %/bln</th>
              <th class="text-right">Hiring/bln</th>
              <th>Last Run</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="scenarios.length === 0">
              <td colspan="8" class="text-center py-8 text-gray-400">
                Belum ada scenario. Klik "Scenario Baru" untuk mulai.
              </td>
            </tr>
            <template v-for="s in scenarios" :key="s.id">
              <tr>
                <td class="text-center">
                  <input type="checkbox"
                    :checked="compareIds.includes(s.id)"
                    @change="toggleCompareSelect(s.id)" />
                </td>
                <td>
                  <div class="font-medium">{{ s.name }}</div>
                  <div class="text-xs text-gray-500">{{ s.description }}</div>
                </td>
                <td class="text-right">{{ s.starting_headcount }}</td>
                <td class="text-right">{{ s.horizon_months }} bln</td>
                <td class="text-right">{{ fmtPct(s.monthly_attrition_rate) }}</td>
                <td class="text-right">{{ s.monthly_hiring_target }}</td>
                <td class="text-xs text-gray-500">
                  <span v-if="s.last_run_at">{{ s.last_run_at?.slice(0, 16) }}</span>
                  <span v-else class="italic">never</span>
                </td>
                <td class="text-right">
                  <button class="btn-secondary text-xs" @click="runScenario(s)" :disabled="running === s.id">
                    <PlayIcon class="w-3 h-3" /> {{ running === s.id ? 'Running…' : 'Run' }}
                  </button>
                  <button v-if="s.runs?.length"
                    class="btn-icon ml-1"
                    @click="expandedRun = expandedRun === s.id ? null : s.id"
                    title="View results">
                    <BeakerIcon class="w-4 h-4" />
                  </button>
                </td>
              </tr>
              <tr v-if="expandedRun === s.id && s.runs?.length" class="bg-gray-50">
                <td colspan="8" class="p-4">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                      <h4 class="font-medium text-sm mb-2">Headcount Distribution (akhir horizon)</h4>
                      <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                          <span>p10 (worst-case):</span>
                          <strong class="text-red-600">{{ s.runs[0].headcount_p10 }}</strong>
                        </div>
                        <div class="flex justify-between">
                          <span>p50 (median):</span>
                          <strong class="text-blue-600">{{ s.runs[0].headcount_p50 }}</strong>
                        </div>
                        <div class="flex justify-between">
                          <span>p90 (best-case):</span>
                          <strong class="text-emerald-600">{{ s.runs[0].headcount_p90 }}</strong>
                        </div>
                      </div>
                    </div>
                    <div>
                      <h4 class="font-medium text-sm mb-2">Total Cost Distribution</h4>
                      <div class="space-y-1 text-sm">
                        <div class="flex justify-between">
                          <span>p10:</span>
                          <strong class="text-emerald-600">{{ fmtCurrency(s.runs[0].total_cost_p10) }}</strong>
                        </div>
                        <div class="flex justify-between">
                          <span>p50:</span>
                          <strong class="text-blue-600">{{ fmtCurrency(s.runs[0].total_cost_p50) }}</strong>
                        </div>
                        <div class="flex justify-between">
                          <span>p90:</span>
                          <strong class="text-red-600">{{ fmtCurrency(s.runs[0].total_cost_p90) }}</strong>
                        </div>
                      </div>
                      <div class="text-xs text-gray-500 mt-2">
                        Termasuk BPJS load factor (default 1.13×).
                        {{ s.runs[0].iterations }} iterasi Monte Carlo.
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Help -->
    <div class="mt-4 flex items-start gap-2 text-sm text-gray-500 bg-gray-50 rounded p-3">
      <InformationCircleIcon class="w-5 h-5 flex-shrink-0 text-gray-400 mt-0.5" />
      <div>
        Engine: Monte Carlo dengan Poisson sampler untuk noise di attrition + hiring tiap bulan.
        Output dalam <strong>percentile</strong> (linear interpolation): p10 = 10% probabilitas
        skenario di bawah, p90 = 10% di atas. Cocok untuk forecast budget workforce skala enterprise.
      </div>
    </div>

    <!-- Modal: Create -->
    <div v-if="showCreate" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showCreate = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h3 class="font-semibold">Scenario Baru</h3>
          <button @click="showCreate = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <div class="p-5 grid grid-cols-2 gap-3">
          <div class="col-span-2">
            <label class="label">Nama</label>
            <input v-model="createForm.name" class="input"
              placeholder="contoh: Aggressive growth 2027 H1" />
          </div>
          <div class="col-span-2">
            <label class="label">Deskripsi</label>
            <textarea v-model="createForm.description" class="input" rows="2"
              placeholder="Asumsi & konteks scenario"></textarea>
          </div>
          <div>
            <label class="label">Starting Headcount</label>
            <input type="number" v-model.number="createForm.starting_headcount" class="input" />
          </div>
          <div>
            <label class="label">Horizon (bulan)</label>
            <input type="number" min="3" max="60" v-model.number="createForm.horizon_months" class="input" />
          </div>
          <div>
            <label class="label">Monthly Attrition Rate</label>
            <input type="number" step="0.001" v-model.number="createForm.monthly_attrition_rate" class="input" />
            <p class="text-xs text-gray-500 mt-1">contoh: 0.02 = 2%/bulan</p>
          </div>
          <div>
            <label class="label">Monthly Hiring Target</label>
            <input type="number" v-model.number="createForm.monthly_hiring_target" class="input" />
          </div>
          <div>
            <label class="label">Avg Salary (gross)</label>
            <input type="number" v-model.number="createForm.avg_salary" class="input" />
          </div>
          <div>
            <label class="label">Monthly Salary Growth</label>
            <input type="number" step="0.001" v-model.number="createForm.monthly_salary_growth" class="input" />
            <p class="text-xs text-gray-500 mt-1">contoh: 0.005 = 0.5%/bulan</p>
          </div>
          <div class="col-span-2">
            <label class="label">Iterations Monte Carlo</label>
            <select v-model.number="createForm.iterations" class="input">
              <option :value="100">100 (cepat, kurang akurat)</option>
              <option :value="1000">1.000 (default)</option>
              <option :value="5000">5.000 (akurasi tinggi)</option>
            </select>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showCreate = false">Batal</button>
          <button class="btn-primary" @click="createScenario">Simpan Scenario</button>
        </div>
      </div>
    </div>

    <!-- Modal: Compare result -->
    <div v-if="showCompare && compareResult" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showCompare = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] flex flex-col">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <h3 class="font-semibold">Compare Scenarios</h3>
          <button @click="showCompare = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>
        <div class="p-5 overflow-y-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Metric</th>
                <th class="text-right" v-for="s in compareResult.scenarios" :key="`hdr-${s.id}`">
                  {{ s.name }}
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr>
                <td>Starting HC</td>
                <td v-for="s in compareResult.scenarios" :key="`hc-${s.id}`" class="text-right">
                  {{ s.starting_headcount }}
                </td>
              </tr>
              <tr>
                <td>Horizon</td>
                <td v-for="s in compareResult.scenarios" :key="`h-${s.id}`" class="text-right">
                  {{ s.horizon_months }} bln
                </td>
              </tr>
              <tr>
                <td>HC p50 (median)</td>
                <td v-for="s in compareResult.scenarios" :key="`p50-${s.id}`" class="text-right font-medium">
                  {{ s.headcount_p50 }}
                </td>
              </tr>
              <tr>
                <td>Total Cost p50</td>
                <td v-for="s in compareResult.scenarios" :key="`c50-${s.id}`" class="text-right font-medium">
                  {{ fmtCurrency(s.total_cost_p50) }}
                </td>
              </tr>
              <tr>
                <td>Cost Range (p10 - p90)</td>
                <td v-for="s in compareResult.scenarios" :key="`cr-${s.id}`" class="text-right text-xs">
                  {{ fmtCurrency(s.total_cost_p10) }} — {{ fmtCurrency(s.total_cost_p90) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>