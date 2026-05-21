<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { LockClosedIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'
import FileUploader from '@/components/FileUploader.vue'

/**
 * Modal pengajuan cuti / izin sakit cepat dari halaman Absensi Saya.
 *
 * Sumber data: GET /leave-types/for-me — backend sudah filter berdasarkan
 * gender, marital status, masa kerja, dan saldo. Setiap entry punya
 * `eligible` (bool) dan `reasons[]` kalau tidak eligible.
 *
 * Prop preferCategory: 'SICK' | 'PAID' | '' menentukan default tipe.
 */

const props = defineProps({
  day:            { type: Object, required: true },
  preferCategory: { type: String, default: '' },
})
const emit = defineEmits(['close', 'submitted'])
const toast = useToast()

const types = ref([])      // semua leave types (eligible & ineligible)
const policy = ref(null)
const loadingTypes = ref(false)
const submitting = ref(false)

const form = ref({
  leave_type_id: '',
  start_date: props.day.date,
  end_date: props.day.date,
  reason: '',
  is_half_day: false,
  half_day_part: 'MORNING',
  attachment: '',
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

const titleByCategory = {
  SICK: 'Ajukan Izin Sakit',
  PAID: 'Ajukan Cuti',
  '':   'Ajukan Cuti / Izin',
}
const title = computed(() => titleByCategory[props.preferCategory] || titleByCategory[''])

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

const selectedType = computed(() => types.value.find(t => t.id === Number(form.value.leave_type_id)) || null)

onMounted(async () => {
  loadingTypes.value = true
  try {
    const res = await api.get('/leave-types/for-me')
    // Backend baru kembalikan { types, policy }; backward-compat untuk array.
    if (Array.isArray(res.data.data)) {
      types.value = res.data.data
    } else {
      types.value = res.data.data?.types || []
      policy.value = res.data.data?.policy || null
    }
    pickDefault()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat tipe cuti')
  } finally {
    loadingTypes.value = false
  }
})

watch(() => props.preferCategory, pickDefault)

function pickDefault() {
  const eligible = eligibleTypes.value
  if (! eligible.length) return
  const cat = (props.preferCategory || '').toUpperCase()

  let pick = null
  if (cat === 'SICK') {
    pick = eligible.find(t => t.category === 'SICK')
  } else if (cat === 'PAID') {
    pick = eligible.find(t => t.category === 'ANNUAL')
  }
  pick ||= eligible[0]
  form.value.leave_type_id = pick.id

  // Sesuaikan tanggal supaya tidak melanggar window backend.
  syncDatesToType()
}

// Setiap kali tipe cuti berubah, clamp tanggal ke window yang valid.
watch(() => form.value.leave_type_id, () => syncDatesToType())

function syncDatesToType() {
  const t = selectedType.value
  if (! t) return
  form.value.start_date = clampDate(form.value.start_date, t.min_start_date, t.max_start_date)
  if (form.value.end_date < form.value.start_date) form.value.end_date = form.value.start_date
  form.value.end_date = clampDate(form.value.end_date, t.min_start_date, t.max_start_date)
}

watch(() => form.value.start_date, (newVal) => {
  if (form.value.end_date < newVal) form.value.end_date = newVal
})

async function submit() {
  if (! form.value.leave_type_id) {
    toast.warning('Pilih tipe cuti terlebih dahulu')
    return
  }
  if (selectedType.value && ! selectedType.value.eligible) {
    toast.error('Tipe cuti yang Anda pilih tidak memenuhi syarat: '
      + selectedType.value.reasons.join(', '))
    return
  }
  if (form.value.reason.trim().length < 5) {
    toast.warning('Alasan minimal 5 karakter')
    return
  }
  if (selectedType.value?.requires_doc && ! form.value.attachment.trim()) {
    toast.warning(`Tipe cuti "${selectedType.value.name}" memerlukan lampiran dokumen pendukung.`)
    return
  }

  submitting.value = true
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

    await api.post('/leaves', payload)
    emit('submitted')
  } catch (e) {
    const msg = e.response?.data?.message || 'Gagal mengirim pengajuan'
    const errors = e.response?.data?.errors
    if (errors) {
      toast.error(msg + ': ' + Object.values(errors).flat().join(', '))
    } else {
      toast.error(msg)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto">
      <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold">{{ title }}</h2>
        <button class="text-gray-400 hover:text-gray-600" @click="emit('close')">✕</button>
      </div>

      <div class="p-5 space-y-4">
        <div class="bg-gray-50 rounded p-3 text-sm">
          <div class="font-medium">{{ day.day_name }}, {{ day.date }}</div>
          <div class="text-xs text-gray-600 mt-1">
            Status saat ini: <span class="font-medium">{{ day.status_label }}</span>
          </div>
        </div>

        <div v-if="loadingTypes" class="text-sm text-gray-500 text-center py-3">Memuat tipe cuti...</div>

        <div v-else-if="eligibleTypes.length === 0"
          class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
          <div class="font-medium">Tidak ada tipe cuti yang dapat Anda ajukan saat ini.</div>
          <div class="text-xs mt-1">
            Hubungi HR untuk mengonfirmasi data karyawan Anda (gender, status menikah, masa kerja).
          </div>
        </div>

        <template v-else>
          <div>
            <label class="block text-sm font-medium mb-1">Tipe Cuti / Izin *</label>
            <select v-model="form.leave_type_id" class="input w-full" required>
              <optgroup label="Tersedia untuk Anda">
                <option v-for="t in eligibleTypes" :key="t.id" :value="t.id">
                  {{ t.name }} ({{ categoryLabel[t.category] || t.category }})
                  <template v-if="t.balance">
                    — sisa {{ t.balance.remaining }}/{{ t.balance.allocated }} hari
                  </template>
                </option>
              </optgroup>
              <optgroup v-if="ineligibleTypes.length" label="🔒 Tidak tersedia untuk Anda" disabled>
                <option v-for="t in ineligibleTypes" :key="t.id" disabled :value="t.id">
                  {{ t.name }} — {{ t.reasons[0] }}
                </option>
              </optgroup>
            </select>

            <div v-if="selectedType" class="mt-2 text-xs space-y-1">
              <div v-if="selectedType.description" class="text-gray-600 italic">
                {{ selectedType.description }}
              </div>
              <div v-if="selectedType.balance"
                class="inline-block px-2 py-0.5 rounded-full text-[11px] bg-blue-50 text-blue-700">
                Saldo {{ selectedType.balance.remaining }} dari {{ selectedType.balance.allocated }} hari
                ({{ selectedType.balance.used }} terpakai)
              </div>
              <div v-if="selectedType.is_paid"
                class="inline-block ml-1 px-2 py-0.5 rounded-full text-[11px] bg-green-50 text-green-700">
                Berbayar
              </div>
              <div v-else
                class="inline-block ml-1 px-2 py-0.5 rounded-full text-[11px] bg-gray-100 text-gray-600">
                Tanpa bayaran
              </div>
              <div v-if="selectedType.requires_doc"
                class="text-amber-700 flex items-center gap-1 mt-1">
                <InformationCircleIcon class="w-3.5 h-3.5" />
                Memerlukan lampiran dokumen pendukung (mis. surat dokter).
              </div>
              <div v-if="selectedType.min_tenure_months" class="text-gray-500">
                Masa kerja minimum: {{ selectedType.min_tenure_months }} bulan.
              </div>
            </div>
          </div>

          <div v-if="selectedType" class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium mb-1">Mulai *</label>
              <input v-model="form.start_date" type="date" class="input w-full" required
                :min="selectedType.min_start_date" :max="selectedType.max_start_date" />
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Selesai *</label>
              <input v-model="form.end_date" type="date" class="input w-full" required
                :min="form.start_date" :max="selectedType.max_start_date" />
            </div>
            <div class="col-span-2 text-[11px] text-gray-500">
              Periode pengajuan: {{ fmtDateID(selectedType.min_start_date) }} – {{ fmtDateID(selectedType.max_start_date) }}.
              <span v-if="policy?.current_period">
                Mengikuti payroll <span class="font-medium">{{ policy.current_period.name }}</span>.
              </span>
            </div>
          </div>

          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="form.is_half_day" class="w-4 h-4" />
            <span>Setengah hari</span>
          </label>
          <div v-if="form.is_half_day">
            <label class="block text-sm font-medium mb-1">Bagian *</label>
            <select v-model="form.half_day_part" class="input w-full">
              <option value="MORNING">Pagi</option>
              <option value="AFTERNOON">Siang</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Alasan *</label>
            <textarea v-model="form.reason" rows="3" class="input w-full"
              :placeholder="preferCategory === 'SICK'
                ? 'Contoh: demam tinggi sejak semalam, dilampirkan surat dokter.'
                : 'Tuliskan alasan pengajuan.'"></textarea>
            <div class="text-xs text-gray-500 mt-1">Minimal 5 karakter. Akan ditinjau oleh atasan / HR.</div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">
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
        </template>
      </div>

      <div class="px-5 py-4 border-t border-gray-200 flex justify-end gap-2">
        <button class="btn-secondary" @click="emit('close')" :disabled="submitting">Batal</button>
        <button class="btn-primary" :disabled="submitting || ! form.leave_type_id" @click="submit">
          {{ submitting ? 'Mengirim...' : 'Kirim Pengajuan' }}
        </button>
      </div>
    </div>
  </div>
</template>