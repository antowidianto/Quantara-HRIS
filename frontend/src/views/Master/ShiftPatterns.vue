<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import {
  PlusIcon, PencilIcon, TrashIcon, XMarkIcon,
  ArrowPathIcon, BeakerIcon, CalendarDaysIcon,
} from '@heroicons/vue/24/outline'

/**
 * Pola Shift (Shift Patterns).
 *
 * Pattern adalah array sepanjang `cycle_days`, indeks 0..(cycle_days-1).
 * Setiap slot { shift_id, is_off, is_holiday, label } akan diaplikasikan
 * berulang ke shift_schedules saat HR tekan "Generate Roster".
 */

const toast = useToast()

const items = ref([])
const loading = ref(false)
const shifts = ref([])

const showEditor = ref(false)
const editing = ref(false)
const form = ref(emptyForm())

function emptyForm() {
  return {
    id: null,
    code: '',
    name: '',
    description: '',
    cycle_days: 7,
    is_active: 1,
    pattern_data: defaultPattern(7),
  }
}

function defaultPattern(n) {
  const out = []
  for (let i = 0; i < n; i++) out.push({ shift_id: null, is_off: true, is_holiday: false, label: '' })
  return out
}

const dayLabels = ['Hari 1','Hari 2','Hari 3','Hari 4','Hari 5','Hari 6','Hari 7']

const shiftOptionsMap = computed(() => {
  const m = new Map()
  shifts.value.forEach(s => m.set(s.id, s))
  return m
})

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/shift-patterns', { params: { is_active: '' } })
    items.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat pola')
  } finally {
    loading.value = false
  }
}

async function loadShifts() {
  try {
    const res = await api.get('/shifts', { params: { per_page: 200 } })
    shifts.value = res.data.data || []
  } catch (_) { /* silent */ }
}

onMounted(() => {
  fetchData()
  loadShifts()
})

function openCreate() {
  form.value = emptyForm()
  editing.value = false
  showEditor.value = true
}

async function openEdit(item) {
  try {
    const res = await api.get(`/shift-patterns/${item.id}`)
    const data = res.data.data
    form.value = {
      ...emptyForm(),
      ...data,
      pattern_data: Array.isArray(data.pattern_data) && data.pattern_data.length
        ? data.pattern_data
        : defaultPattern(data.cycle_days || 7),
    }
    editing.value = true
    showEditor.value = true
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat pola')
  }
}

// Resize pattern_data setiap kali cycle_days berubah
watch(() => form.value.cycle_days, (n) => {
  const target = Math.max(1, Math.min(366, parseInt(n, 10) || 1))
  const cur = form.value.pattern_data || []
  if (target > cur.length) {
    for (let i = cur.length; i < target; i++) {
      cur.push({ shift_id: null, is_off: true, is_holiday: false, label: '' })
    }
  } else if (target < cur.length) {
    cur.length = target
  }
  form.value.cycle_days = target
})

async function save() {
  if (! form.value.code || ! form.value.name) {
    toast.warning('Kode & nama wajib diisi')
    return
  }
  try {
    const payload = { ...form.value }
    if (editing.value) {
      await api.put(`/shift-patterns/${payload.id}`, payload)
      toast.success('Pola diperbarui')
    } else {
      await api.post('/shift-patterns', payload)
      toast.success('Pola dibuat')
    }
    showEditor.value = false
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: 'Hapus Pola',
    message: `Hapus pola "${item.name}"?`,
    okText: 'Hapus', variant: 'danger',
  })
  if (! ok) return
  try {
    await api.delete(`/shift-patterns/${item.id}`)
    toast.success('Berhasil dihapus')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menghapus')
  }
}

// Helper UI: cepat isi pola umum
function quickFill(type) {
  const cycle = form.value.cycle_days
  const office = shifts.value.find(s => /office|kantor/i.test(s.code) || s.is_default)
  if (! office) {
    toast.info('Tidak ada shift OFFICE/default. Pilih shift manual di tiap slot.')
    return
  }
  const out = []
  if (type === '5-2') {
    for (let i = 0; i < cycle; i++) {
      const d = i % 7
      out.push(d <= 4
        ? { shift_id: office.id, is_off: false, is_holiday: false, label: '' }
        : { shift_id: null, is_off: true, is_holiday: false, label: '' })
    }
  } else if (type === '4-2') {
    for (let i = 0; i < cycle; i++) {
      const d = i % 6
      out.push(d <= 3
        ? { shift_id: office.id, is_off: false, is_holiday: false, label: '' }
        : { shift_id: null, is_off: true, is_holiday: false, label: '' })
    }
  } else if (type === 'all-on') {
    for (let i = 0; i < cycle; i++) out.push({ shift_id: office.id, is_off: false, is_holiday: false, label: '' })
  } else if (type === 'all-off') {
    for (let i = 0; i < cycle; i++) out.push({ shift_id: null, is_off: true, is_holiday: false, label: '' })
  }
  form.value.pattern_data = out
}

function shiftColor(slot) {
  if (slot.is_off) return '#e5e7eb'
  if (! slot.shift_id) return '#f3f4f6'
  return shiftOptionsMap.value.get(Number(slot.shift_id))?.color || '#cbd5e1'
}

function shiftLabel(slot) {
  if (slot.is_holiday) return 'LIBUR'
  if (slot.is_off) return 'OFF'
  const s = shiftOptionsMap.value.get(Number(slot.shift_id))
  return s ? s.code : '—'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <CalendarDaysIcon class="w-6 h-6" /> Pola Shift (Cycle)
      </h1>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Buat Pola
      </button>
    </div>

    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Kode</th>
              <th>Nama</th>
              <th>Siklus (hari)</th>
              <th>Visualisasi</th>
              <th class="text-center">Assignment</th>
              <th>Status</th>
              <th class="text-right w-32">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading"><td colspan="7" class="text-center py-8 text-gray-400">Memuat…</td></tr>
            <tr v-else-if="items.length === 0"><td colspan="7" class="text-center py-8 text-gray-400">Belum ada pola</td></tr>
            <tr v-for="item in items" :key="item.id">
              <td class="font-mono text-sm">{{ item.code }}</td>
              <td class="font-medium">{{ item.name }}</td>
              <td class="text-center">{{ item.cycle_days }}</td>
              <td>
                <div class="flex flex-wrap gap-1 max-w-md">
                  <span
                    v-for="(slot, i) in (item.pattern_data || []).slice(0, 28)"
                    :key="i"
                    class="inline-block w-4 h-5 rounded-sm border border-white shadow-sm"
                    :style="{ background: shiftColor(slot) }"
                    :title="shiftLabel(slot)"
                  ></span>
                  <span v-if="(item.pattern_data || []).length > 28" class="text-xs text-gray-400 ml-1">
                    +{{ item.pattern_data.length - 28 }}
                  </span>
                </div>
              </td>
              <td class="text-center">{{ item.assignment_count || 0 }}</td>
              <td>
                <span :class="['px-2 py-0.5 rounded-full text-xs',
                  Number(item.is_active) === 1 ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'
                ]">
                  {{ Number(item.is_active) === 1 ? 'Aktif' : 'Non-aktif' }}
                </span>
              </td>
              <td class="text-right">
                <div class="inline-flex gap-2">
                  <button class="text-gray-500 hover:text-primary-600" @click="openEdit(item)"><PencilIcon class="w-4 h-4" /></button>
                  <button class="text-gray-500 hover:text-red-600" @click="remove(item)"><TrashIcon class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Editor -->
    <div v-if="showEditor" class="fixed inset-0 z-40 flex items-stretch bg-black/40 p-4">
      <div class="bg-white w-full m-auto rounded-lg shadow-xl flex flex-col max-h-[95vh]" style="max-width: 1100px">
        <div class="px-6 py-3 border-b border-gray-200 flex items-center justify-between">
          <h2 class="font-semibold">{{ editing ? 'Edit' : 'Buat' }} Pola Shift</h2>
          <button class="text-gray-500" @click="showEditor = false"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-6 gap-3 px-6 py-3 border-b border-gray-100 bg-gray-50">
          <div class="md:col-span-1">
            <label class="label">Kode</label>
            <input v-model="form.code" class="input" :disabled="editing" placeholder="P_5_2" required />
          </div>
          <div class="md:col-span-2">
            <label class="label">Nama</label>
            <input v-model="form.name" class="input" placeholder="5-2 Kantor (Sen-Jum)" required />
          </div>
          <div class="md:col-span-1">
            <label class="label">Siklus (hari)</label>
            <input v-model.number="form.cycle_days" type="number" min="1" max="366" class="input" />
          </div>
          <div class="md:col-span-2 flex items-end gap-3 flex-wrap">
            <button class="btn-secondary text-xs" @click="quickFill('5-2')">5-2 Kantor</button>
            <button class="btn-secondary text-xs" @click="quickFill('4-2')">4-2 Rolling</button>
            <button class="btn-secondary text-xs" @click="quickFill('all-on')">Semua Hari Kerja</button>
            <button class="btn-secondary text-xs" @click="quickFill('all-off')">Semua OFF</button>
          </div>
          <div class="md:col-span-6">
            <label class="label">Deskripsi</label>
            <input v-model="form.description" class="input" placeholder="Pola standar 5 hari kerja kantor" />
          </div>
        </div>

        <!-- Pattern grid -->
        <div class="flex-1 overflow-y-auto p-6">
          <div class="text-sm text-gray-500 mb-3">
            Atur shift untuk tiap hari dalam siklus. Sistem akan memutar pola ini
            mulai dari <strong>anchor_date</strong> assignment-nya.
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-3">
            <div v-for="(slot, i) in form.pattern_data" :key="i"
              class="border border-gray-200 rounded-md p-3 bg-white"
              :style="{ borderLeft: '4px solid ' + shiftColor(slot) }"
            >
              <div class="text-xs font-medium text-gray-500 mb-2">Hari {{ i + 1 }}</div>
              <select v-model="slot.shift_id" class="input text-sm mb-2"
                :disabled="slot.is_off || slot.is_holiday">
                <option :value="null">— Pilih shift —</option>
                <option v-for="s in shifts" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
              <div class="flex flex-col gap-1 text-xs">
                <label class="flex items-center gap-1">
                  <input v-model="slot.is_off" type="checkbox"
                    @change="slot.is_off ? (slot.shift_id = null) : null" />
                  Libur (off)
                </label>
                <label class="flex items-center gap-1">
                  <input v-model="slot.is_holiday" type="checkbox" />
                  Tandai sebagai libur
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2 bg-white">
          <button class="btn-secondary" @click="showEditor = false">Tutup</button>
          <button class="btn-primary" @click="save">
            {{ editing ? 'Simpan Perubahan' : 'Simpan Pola' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>