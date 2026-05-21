<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { InformationCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import FileUploader from '@/components/FileUploader.vue'

/**
 * Form pengajuan cuti / izin (full page).
 *
 * Sumber tipe cuti: GET /leave-types/for-me — backend memfilter berdasarkan
 * gender, status menikah, masa kerja, dan saldo, plus mengembalikan window
 * tanggal yang dibolehkan per kategori (mengikuti payroll yang aktif).
 */

const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const types = ref([])
const policy = ref(null)
const loadingTypes = ref(false)
const saving = ref(false)

const form = ref({
  leave_type_id: '',
  start_date: '',
  end_date: '',
  reason: '',
  is_half_day: false,
  half_day_part: 'MORNING',
  attachment: '',
  contact_during_leave: '',
})

function fmtDateID(d) {
  if (! d) return ''
  return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
}

function clampDate(d, min, max) {
  if (! d) return d
  if (min && d < min) return min
  if (max && d > max) return max
  return d
}

const categoryLabel = {
  ANNUAL:      'Cuti Tahunan',
  SICK:        'Sakit',
  MATERNITY:   'Melahirkan',
  PATERNITY:   'Pendamping Melahirkan',
  MENSTRUAL:   'Haid',
  MARRIAGE:    'Menikah',
  BEREAVEMENT: 'Duka Cita',
  HAJJ:        'Haji / Ibadah',
  UNPAID:      'Tanpa Bayaran',
  SPECIAL:     'Khusus',
}

const eligibleTypes   = computed(() => types.value.filter(t => t.eligible))
const ineligibleTypes = computed(() => types.value.filter(t => ! t.eligible))

const selectedType = computed(() =>
  types.value.find(t => t.id === Number(form.value.leave_type_id)) || null
)

// Konflik tim: tarik /leaves/team-calendar untuk window pilihan saya, lalu
// hitung berapa rekan satu cabang/dept yang juga cuti pada hari yang sama.
// Tujuannya bukan blokir, tapi memberi sinyal awal supaya user
// mempertimbangkan ulang (mis. "3 rekan se-tim juga cuti tgl segini").
const teamConflicts = ref([])
const conflictsLoading = ref(false)
let conflictTimer = null

async function fetchConflicts() {
  if (! form.value.start_date || ! form.value.end_date) {
    teamConflicts.value = []
    return
  }
  conflictsLoading.value = true
  try {
    const res = await api.get('/leaves/team-calendar', {
      params: { start_date: form.value.start_date, end_date: form.value.end_date },
    })
    // Backend (LeaveController::teamCalendar) return { leaves: [...], count, ... }
    // dengan tiap row punya employee_id + leave_name + start/end_date.
    const data = res.data?.data || {}
    const leaves = Array.isArray(data.leaves) ? data.leaves : []
    const myEmpId = Number(auth?.user?.employee_id || auth?.profile?.employee_id || 0)
    teamConflicts.value = leaves.filter(l => Number(l.employee_id) !== myEmpId)
  } catch (e) {
    // Jangan ganggu user dengan toast — silent fail.
    teamConflicts.value = []
  } finally {
    conflictsLoading.value = false
  }
}

function debounceFetchConflicts() {
  if (conflictTimer) clearTimeout(conflictTimer)
  conflictTimer = setTimeout(fetchConflicts, 350)
}

onMounted(async () => {
  loadingTypes.value = true
  try {
    const res = await api.get('/leave-types/for-me')
    if (Array.isArray(res.data.data)) {
      types.value = res.data.data
    } else {
      types.value = res.data.data?.types || []
      policy.value = res.data.data?.policy || null
    }
    if (eligibleTypes.value.length) {
      const t = eligibleTypes.value[0]
      form.value.leave_type_id = t.id
      form.value.start_date = t.min_start_date || new Date().toISOString().slice(0, 10)
      form.value.end_date   = form.value.start_date
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat tipe cuti')
  } finally {
    loadingTypes.value = false
  }
})

// Saat user pindah tipe cuti, clamp ulang tanggal ke window-nya.
watch(() => form.value.leave_type_id, () => {
  const t = selectedType.value
  if (! t) return
  form.value.start_date = clampDate(form.value.start_date || t.min_start_date, t.min_start_date, t.max_start_date)
  if (form.value.end_date < form.value.start_date) form.value.end_date = form.value.start_date
  form.value.end_date = clampDate(form.value.end_date, t.min_start_date, t.max_start_date)
})

watch(() => form.value.start_date, (newVal) => {
  if (form.value.end_date < newVal) form.value.end_date = newVal
  debounceFetchConflicts()
})

watch(() => form.value.end_date, () => debounceFetchConflicts())

async function submit() {
  if (! form.value.leave_type_id) {
    toast.warning('Pilih tipe cuti terlebih dahulu')
    return
  }
  if (selectedType.value && ! selectedType.value.eligible) {
    toast.error('Tipe cuti tidak memenuhi syarat: ' + selectedType.value.reasons.join(', '))
    return
  }
  // Pre-validation tanggal di client supaya feedback instan.
  if (selectedType.value) {
    if (form.value.start_date < selectedType.value.min_start_date) {
      toast.error(`Tanggal mulai tidak boleh sebelum ${fmtDateID(selectedType.value.min_start_date)}.`)
      return
    }
    if (form.value.start_date > selectedType.value.max_start_date) {
      toast.error(`Tanggal mulai tidak boleh setelah ${fmtDateID(selectedType.value.max_start_date)}.`)
      return
    }
  }
  if (form.value.reason.trim().length < 5) {
    toast.warning('Alasan minimal 5 karakter')
    return
  }
  if (selectedType.value?.requires_doc && ! form.value.attachment.trim()) {
    toast.warning(`Tipe cuti "${selectedType.value.name}" memerlukan lampiran dokumen pendukung.`)
    return
  }

  saving.value = true
  try {
    const payload = {
      leave_type_id: Number(form.value.leave_type_id),
      start_date: form.value.start_date,
      end_date: form.value.end_date,
      reason: form.value.reason.trim(),
      is_half_day: form.value.is_half_day ? 1 : 0,
    }
    if (form.value.is_half_day) payload.half_day_part = form.value.half_day_part
    if (form.value.attachment.trim()) payload.attachment = form.value.attachment.trim()
    if (form.value.contact_during_leave.trim()) {
      payload.contact_during_leave = form.value.contact_during_leave.trim()
    }
    await api.post('/leaves', payload)
    toast.success('Pengajuan cuti dikirim')
    router.push('/leaves')
  } catch (e) {
    const msg = e.response?.data?.message || 'Gagal mengajukan cuti'
    const errors = e.response?.data?.errors
    toast.error(errors ? `${msg}: ${Object.values(errors).flat().join(', ')}` : msg)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl">
    <h1 class="text-2xl font-bold mb-1">Ajukan Cuti / Izin</h1>
    <p class="text-sm text-gray-500 mb-4">
      Sesuai UU 13/2003, UU 6/2023, dan PP 35/2021. Sistem memfilter tipe cuti
      yang dapat Anda ajukan berdasarkan gender, status menikah, dan masa kerja.
    </p>

    <div v-if="loadingTypes" class="card p-6 text-center text-gray-500">
      Memuat data tipe cuti...
    </div>

    <div v-else-if="eligibleTypes.length === 0" class="card p-6">
      <div class="flex items-start gap-3 text-amber-800">
        <ExclamationTriangleIcon class="w-6 h-6 flex-shrink-0 mt-0.5" />
        <div>
          <div class="font-semibold">Tidak ada tipe cuti yang dapat diajukan</div>
          <div class="text-sm text-gray-700 mt-1">
            Hubungi HR untuk mengonfirmasi data karyawan Anda (gender, status menikah, masa kerja),
            atau tunggu sampai memenuhi syarat masa kerja.
          </div>
        </div>
      </div>
      <ul v-if="ineligibleTypes.length" class="mt-4 text-xs text-gray-600 space-y-1">
        <li v-for="t in ineligibleTypes" :key="t.id">
          🔒 <strong>{{ t.name }}</strong> — {{ t.reasons.join(', ') }}
        </li>
      </ul>
    </div>

    <form v-else @submit.prevent="submit" class="card">
      <div class="card-body space-y-4">
        <!-- Banner periode payroll aktif -->
        <div v-if="policy?.current_period"
          class="rounded-md bg-blue-50 border border-blue-200 p-3 text-xs text-blue-900">
          <div class="font-medium">Periode Payroll Aktif: {{ policy.current_period.name }}</div>
          <div>
            {{ fmtDateID(policy.current_period.start_date) }} s/d {{ fmtDateID(policy.current_period.end_date) }}
            (gaji dibayar {{ fmtDateID(policy.current_period.pay_date) }})
          </div>
          <div class="text-[11px] text-blue-700 mt-1">{{ policy.help_text }}</div>
        </div>
        <div v-else-if="policy"
          class="rounded-md bg-amber-50 border border-amber-200 p-3 text-xs text-amber-900">
          Belum ada periode payroll aktif untuk hari ini. Pengajuan akan mengikuti default 180 hari ke depan.
        </div>

        <div>
          <label class="label">Tipe Cuti *</label>
          <select v-model="form.leave_type_id" class="input" required>
            <optgroup label="Tersedia untuk Anda">
              <option v-for="t in eligibleTypes" :key="t.id" :value="t.id">
                {{ t.name }} ({{ categoryLabel[t.category] || t.category }})
                <template v-if="t.balance">— sisa {{ t.balance.remaining }}/{{ t.balance.allocated }} hari</template>
              </option>
            </optgroup>
            <optgroup v-if="ineligibleTypes.length" label="🔒 Tidak tersedia untuk Anda">
              <option v-for="t in ineligibleTypes" :key="t.id" disabled :value="t.id">
                {{ t.name }} — {{ t.reasons[0] }}
              </option>
            </optgroup>
          </select>

          <div v-if="selectedType" class="mt-3 rounded-md bg-blue-50/60 border border-blue-100 p-3 text-xs space-y-1.5">
            <div v-if="selectedType.description" class="text-gray-700 italic">
              {{ selectedType.description }}
            </div>
            <div class="flex flex-wrap gap-1.5">
              <span v-if="selectedType.balance"
                class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">
                Saldo {{ selectedType.balance.remaining }}/{{ selectedType.balance.allocated }} hari
              </span>
              <span v-if="selectedType.is_paid"
                class="px-2 py-0.5 rounded-full bg-green-100 text-green-800">Berbayar</span>
              <span v-else class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">Tanpa bayaran</span>
              <span v-if="selectedType.default_days"
                class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">
                Default {{ selectedType.default_days }} hari
              </span>
              <span v-if="selectedType.min_tenure_months"
                class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">
                Min. masa kerja {{ selectedType.min_tenure_months }} bulan
              </span>
            </div>
            <div v-if="selectedType.requires_doc" class="flex items-center gap-1 text-amber-700">
              <InformationCircleIcon class="w-3.5 h-3.5" />
              Memerlukan lampiran dokumen pendukung (mis. surat dokter).
            </div>
          </div>
        </div>

        <div v-if="selectedType" class="grid grid-cols-2 gap-4">
          <div>
            <label class="label">Tanggal Mulai *</label>
            <input v-model="form.start_date" type="date" class="input" required
              :min="selectedType.min_start_date" :max="selectedType.max_start_date" />
          </div>
          <div>
            <label class="label">Tanggal Selesai *</label>
            <input v-model="form.end_date" type="date" class="input" required
              :min="form.start_date" :max="selectedType.max_start_date" />
          </div>
          <div class="col-span-2 -mt-1 text-xs text-gray-500">
            Periode pengajuan yang diizinkan untuk tipe ini:
            <span class="font-medium">{{ fmtDateID(selectedType.min_start_date) }}</span> –
            <span class="font-medium">{{ fmtDateID(selectedType.max_start_date) }}</span>.
            <span v-if="selectedType.category === 'SICK'" class="text-amber-700">
              (Cuti sakit boleh mundur sampai awal periode payroll berjalan / 14 hari ke belakang.)
            </span>
          </div>

          <!-- Notifikasi bentrok cuti tim -->
          <div v-if="teamConflicts.length" class="col-span-2 rounded-md bg-amber-50 border border-amber-200 p-3">
            <div class="flex items-start gap-2">
              <ExclamationTriangleIcon class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
              <div class="text-xs text-amber-900">
                <div class="font-semibold mb-1">
                  {{ teamConflicts.length }} rekan satu tim juga cuti pada rentang ini
                </div>
                <ul class="space-y-0.5 max-h-24 overflow-y-auto">
                  <li v-for="c in teamConflicts.slice(0, 8)" :key="c.id">
                    • <span class="font-medium">{{ c.employee_name }}</span>
                    <span v-if="c.leave_name" class="text-amber-700">— {{ c.leave_name }}</span>
                    <span v-if="c.start_date" class="text-amber-700">
                      ({{ fmtDateID(c.start_date) }}<span v-if="c.end_date && c.end_date !== c.start_date">–{{ fmtDateID(c.end_date) }}</span>)
                    </span>
                    <span v-if="c.status === 'PENDING'" class="text-gray-500 italic"> · pending</span>
                  </li>
                  <li v-if="teamConflicts.length > 8" class="text-amber-700 italic">
                    …dan {{ teamConflicts.length - 8 }} lainnya
                  </li>
                </ul>
                <div class="mt-1.5 text-amber-700 italic">
                  Pengajuan tetap dapat diteruskan; ini hanya pengingat agar atasan dapat mempertimbangkan beban tim.
                </div>
              </div>
            </div>
          </div>
          <div v-else-if="conflictsLoading" class="col-span-2 text-xs text-gray-400 italic">
            Mengecek jadwal tim…
          </div>
        </div>

        <label class="flex items-center gap-2">
          <input type="checkbox" v-model="form.is_half_day" />
          <span class="text-sm">Setengah hari</span>
        </label>

        <div v-if="form.is_half_day">
          <label class="label">Bagian *</label>
          <select v-model="form.half_day_part" class="input">
            <option value="MORNING">Pagi</option>
            <option value="AFTERNOON">Siang</option>
          </select>
        </div>

        <div>
          <label class="label">Alasan *</label>
          <textarea v-model="form.reason" rows="3" class="input" required
            placeholder="Tuliskan alasan pengajuan."></textarea>
        </div>

        <div>
          <label class="label">
            Lampiran
            <span v-if="selectedType?.requires_doc" class="text-red-500">*</span>
            <span v-else class="text-gray-400">(opsional)</span>
          </label>
          <FileUploader
            v-model="form.attachment"
            context="leave"
            hint="Surat dokter, surat keterangan, dst. Max 5 MB."
          />
        </div>

        <div>
          <label class="label">Kontak selama cuti (opsional)</label>
          <input v-model="form.contact_during_leave" type="text" class="input"
            placeholder="No. telepon yang bisa dihubungi" />
        </div>
      </div>
      <div class="card-body border-t flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="$router.back()">Batal</button>
        <button type="submit" class="btn-primary" :disabled="saving">
          {{ saving ? 'Mengirim...' : 'Kirim Pengajuan' }}
        </button>
      </div>
    </form>
  </div>
</template>