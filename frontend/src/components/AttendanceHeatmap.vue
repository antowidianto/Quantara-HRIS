<!--
  AttendanceHeatmap — visualisasi matrix departemen × hari untuk menunjukkan
  pola absensi bermasalah (late + absent rate). Sprint 5-6 item T.

  Pakai pure CSS grid + Tailwind — tidak butuh chart library. Render lebih cepat
  daripada Apex/Chart.js untuk dataset kecil-menengah (< 30 dept × 31 hari).

  Color scale (semakin merah = semakin bermasalah):
    0-5%   = green-100  (normal/baik)
    5-15%  = yellow-200 (perhatian)
    15-30% = orange-300 (waspada)
    30%+   = red-500    (perlu tindakan)

  Tooltip on hover muncul native via title attribute (sederhana, accessible).
-->
<template>
  <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
    <div class="flex items-center justify-between mb-3">
      <div>
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
          Heatmap Absensi
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
          Persentase late + absent per departemen ({{ formatMonth(month) }})
        </p>
      </div>
      <div class="flex items-center gap-2">
        <AppDatePicker mode="month"
          v-model="month"
          @change="onMonthChange"
          class="text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1
                 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
         />
      </div>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
      Memuat data heatmap...
    </div>

    <div v-else-if="error" class="py-8 text-center">
      <p class="text-sm text-red-600 dark:text-red-400 mb-2">{{ error }}</p>
      <button @click="load" class="text-xs text-primary-600 dark:text-primary-400 hover:underline">
        Coba lagi
      </button>
    </div>

    <div v-else-if="!data || data.departments.length === 0" class="py-12 text-center">
      <p class="text-sm text-gray-500 dark:text-gray-400">
        Belum ada data absensi untuk bulan ini.
      </p>
    </div>

    <div v-else class="overflow-x-auto">
      <!-- Header: tanggal -->
      <table class="w-full text-xs">
        <thead>
          <tr class="text-gray-500 dark:text-gray-400">
            <th class="text-left pr-3 pb-2 font-normal sticky left-0 bg-white dark:bg-gray-800 z-10">
              Departemen
            </th>
            <th
              v-for="d in data.days"
              :key="d.date"
              class="px-0.5 pb-2 font-normal text-center w-7"
              :class="{ 'text-gray-400 dark:text-gray-500': d.is_weekend }"
              :title="d.date"
            >
              {{ d.day }}
            </th>
            <th class="text-right pl-3 pb-2 font-normal w-12">Avg</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="dept in data.departments"
            :key="dept.id"
            class="border-t border-gray-100 dark:border-gray-700"
          >
            <td class="text-left pr-3 py-1 sticky left-0 bg-white dark:bg-gray-800 z-10
                       text-gray-700 dark:text-gray-200 font-medium truncate max-w-[160px]"
                :title="dept.name">
              {{ dept.name }}
            </td>
            <td
              v-for="d in data.days"
              :key="d.date"
              class="px-0.5 py-0.5"
            >
              <div
                class="h-5 w-full rounded-sm"
                :class="cellClass(dept.cells[d.date], d.is_weekend)"
                :title="cellTitle(dept.cells[d.date], d.date, dept.name)"
              ></div>
            </td>
            <td class="text-right pl-3 py-1 font-mono"
                :class="rateColor(dept.avg_issue_rate)">
              {{ dept.avg_issue_rate }}%
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Legend -->
      <div class="mt-3 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
        <span>Skala:</span>
        <div class="flex items-center gap-1">
          <div class="h-3 w-3 rounded-sm bg-green-100 dark:bg-green-900/40"></div>
          <span>0-5%</span>
        </div>
        <div class="flex items-center gap-1">
          <div class="h-3 w-3 rounded-sm bg-yellow-200 dark:bg-yellow-900/50"></div>
          <span>5-15%</span>
        </div>
        <div class="flex items-center gap-1">
          <div class="h-3 w-3 rounded-sm bg-orange-300 dark:bg-orange-900/60"></div>
          <span>15-30%</span>
        </div>
        <div class="flex items-center gap-1">
          <div class="h-3 w-3 rounded-sm bg-red-500 dark:bg-red-700"></div>
          <span>≥30%</span>
        </div>
        <div class="flex items-center gap-1 ml-auto">
          <div class="h-3 w-3 rounded-sm bg-gray-100 dark:bg-gray-700"></div>
          <span>Tidak ada data / weekend</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/api/client'

const data = ref(null)
const loading = ref(false)
const error = ref(null)
const month = ref(new Date().toISOString().slice(0, 7))

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await api.get('/reports/attendance-heatmap', { params: { month: month.value } })
    data.value = res.data?.data || res.data
  } catch (e) {
    error.value = e?.response?.data?.message || 'Gagal memuat heatmap absensi.'
  } finally {
    loading.value = false
  }
}

function onMonthChange() {
  load()
}

function formatMonth(m) {
  if (!m) return ''
  const [y, mo] = m.split('-')
  const months = ['Januari','Februari','Maret','April','Mei','Juni',
                  'Juli','Agustus','September','Oktober','November','Desember']
  return `${months[parseInt(mo, 10) - 1]} ${y}`
}

function cellClass(cell, isWeekend) {
  if (isWeekend && (! cell || cell.total === 0)) {
    return 'bg-gray-50 dark:bg-gray-800/50'
  }
  if (! cell || cell.total === 0) {
    return 'bg-gray-100 dark:bg-gray-700'
  }
  const score = cell.score
  if (score < 5) return 'bg-green-100 dark:bg-green-900/40 hover:ring-2 hover:ring-green-500'
  if (score < 15) return 'bg-yellow-200 dark:bg-yellow-900/50 hover:ring-2 hover:ring-yellow-500'
  if (score < 30) return 'bg-orange-300 dark:bg-orange-900/60 hover:ring-2 hover:ring-orange-500'
  return 'bg-red-500 dark:bg-red-700 hover:ring-2 hover:ring-red-500'
}

function rateColor(rate) {
  if (rate < 5) return 'text-green-600 dark:text-green-400'
  if (rate < 15) return 'text-yellow-600 dark:text-yellow-400'
  if (rate < 30) return 'text-orange-600 dark:text-orange-400'
  return 'text-red-600 dark:text-red-400'
}

function cellTitle(cell, date, deptName) {
  if (! cell || cell.total === 0) return `${deptName} — ${date}: tidak ada data`
  return `${deptName} — ${date}\n`
       + `Total kehadiran: ${cell.total}\n`
       + `Late: ${cell.late} | Absent: ${cell.absent}\n`
       + `Issue rate: ${cell.score}%`
}

onMounted(load)
</script>
