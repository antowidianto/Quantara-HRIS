<script setup>
/**
 * MyResignation — halaman self-service surat resignation untuk karyawan.
 *
 * Flow:
 *   1. Cek apakah sudah ada pengajuan aktif. Kalau ada, tampilkan status.
 *   2. Kalau belum, tampilkan form pengajuan.
 *   3. Setelah submit:
 *      - Jika ada supervisor → status PENDING_SUPERVISOR
 *      - Jika tidak ada      → langsung PENDING_HR
 *   4. Karyawan bisa cancel selama belum APPROVED.
 *
 * Validasi sisi client mirror sisi backend (notice period 30 hari, dst.)
 * supaya feedback cepat. Backend tetap revalidasi.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate } from '@/composables/useResource'
import {
  ArrowLeftStartOnRectangleIcon, CalendarDaysIcon, ClockIcon,
  PaperAirplaneIcon, XMarkIcon, ChatBubbleLeftEllipsisIcon,
  ShieldCheckIcon, BuildingOffice2Icon, DocumentTextIcon, CheckCircleIcon,
  ExclamationTriangleIcon, BookOpenIcon,
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const toast = useToast()

const loading = ref(false)
const submitting = ref(false)
const myRequests = ref([])
const reasonCategories = ref([])

// Form state
const form = ref({
  last_working_date: '',
  effective_date:    '',
  reason_category:   '',
  reason_text:       '',
  attachment_path:   '',
})

// Hari minimum sesuai UU 13/2003 — supaya UI bisa highlight tanggal valid
const MIN_NOTICE_DAYS = 30
const minLastWorkingDate = computed(() => {
  const d = new Date()
  d.setDate(d.getDate() + MIN_NOTICE_DAYS)
  return d.toISOString().slice(0, 10)
})

const activeRequest = computed(() =>
  myRequests.value.find(r =>
    ['PENDING_SUPERVISOR', 'PENDING_HR', 'APPROVED'].includes(r.status),
  ) || null,
)

const historicalRequests = computed(() =>
  myRequests.value.filter(r => ! ['PENDING_SUPERVISOR', 'PENDING_HR', 'APPROVED'].includes(r.status)),
)

const noticeDays = computed(() => {
  if (! form.value.last_working_date) return null
  const today = new Date(); today.setHours(0,0,0,0)
  const lwd = new Date(form.value.last_working_date)
  return Math.round((lwd - today) / 86400000)
})

const canSubmit = computed(() =>
  ! activeRequest.value
  && form.value.last_working_date
  && form.value.reason_category
  && (noticeDays.value ?? 0) >= MIN_NOTICE_DAYS,
)

function statusBadge(status) {
  return ({
    DRAFT:              'bg-gray-100 text-gray-700',
    PENDING_SUPERVISOR: 'bg-amber-100 text-amber-800',
    PENDING_HR:         'bg-blue-100 text-blue-800',
    APPROVED:           'bg-emerald-100 text-emerald-800',
    REJECTED:           'bg-red-100 text-red-800',
    CANCELLED:          'bg-gray-100 text-gray-500',
  })[status] || 'bg-gray-100 text-gray-700'
}

function statusLabel(status) {
  return ({
    DRAFT:              'Draf',
    PENDING_SUPERVISOR: 'Menunggu Atasan',
    PENDING_HR:         'Menunggu HR',
    APPROVED:           'Disetujui',
    REJECTED:           'Ditolak',
    CANCELLED:          'Dibatalkan',
  })[status] || status
}

async function fetchData() {
  loading.value = true
  try {
    const [list, reasons] = await Promise.all([
      api.get('/resignations', { params: { as: 'mine', per_page: 50 } }),
      api.get('/resignations/reasons'),
    ])
    myRequests.value = list.data?.data || []
    reasonCategories.value = reasons.data?.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data')
  } finally {
    loading.value = false
  }
}

async function submit() {
  if (! canSubmit.value) return

  // Konfirmasi 2 langkah karena ini keputusan besar
  const ok = await confirmDialog({
    title:   'Kirim pengajuan resignation?',
    message: 'Pengajuan ini akan dikirim ke atasan langsung Anda dan kemudian ke HR. '
      + 'Setelah disetujui HR, status karyawan Anda berubah menjadi resign per tanggal '
      + 'yang Anda tentukan. Yakin ingin melanjutkan?',
    okText:  'Ya, Kirim',
    variant: 'danger',
  })
  if (! ok) return

  submitting.value = true
  try {
    const payload = { ...form.value }
    if (! payload.effective_date) delete payload.effective_date
    if (! payload.attachment_path) delete payload.attachment_path
    if (! payload.reason_text) delete payload.reason_text

    const res = await api.post('/resignations', payload)
    toast.success(res.data?.message || 'Pengajuan terkirim')

    // Reset form
    form.value = {
      last_working_date: '', effective_date: '',
      reason_category: '', reason_text: '', attachment_path: '',
    }
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mengirim pengajuan')
  } finally {
    submitting.value = false
  }
}

async function cancelRequest(req) {
  const ok = await confirmDialog({
    title:   'Batalkan pengajuan?',
    message: `Pengajuan resignation per ${fmtDate(req.last_working_date)} akan dibatalkan. `
      + 'Anda bisa mengajukan ulang nanti jika diperlukan.',
    okText:  'Ya, Batalkan',
    variant: 'danger',
  })
  if (! ok) return

  try {
    await api.post(`/resignations/${req.id}/cancel`, { note: 'Dibatalkan oleh karyawan' })
    toast.success('Pengajuan dibatalkan')
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal membatalkan')
  }
}

async function uploadAttachment(ev) {
  const file = ev.target.files?.[0]
  if (! file) return
  if (! ['application/pdf', 'image/jpeg', 'image/png'].includes(file.type)) {
    toast.error('Hanya PDF/JPG/PNG yang didukung')
    return
  }
  if (file.size > 5 * 1024 * 1024) {
    toast.error('Ukuran file melebihi 5 MB')
    return
  }
  try {
    const fd = new FormData()
    fd.append('file', file)
    const res = await api.post('/upload/attachment?context=misc', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    form.value.attachment_path = res.data?.data?.path
    toast.success('Lampiran terupload')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal upload')
  }
}

onMounted(fetchData)
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-4">
    <!-- ─── Header ─────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-body flex items-start gap-4">
        <div class="w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0">
          <ArrowLeftStartOnRectangleIcon class="w-6 h-6" />
        </div>
        <div class="flex-1 min-w-0">
          <h1 class="text-xl font-bold text-gray-900">Pengajuan Resignation</h1>
          <p class="text-sm text-gray-600 mt-1">
            Ajukan surat pengunduran diri secara resmi. Setelah disetujui, sistem akan otomatis
            men-generate task offboarding (serah terima, return aset, clearance, dll) untuk Anda dan tim terkait.
          </p>
          <p class="text-xs text-gray-500 mt-2 inline-flex items-center gap-1">
            <BookOpenIcon class="w-3.5 h-3.5" />
            Pemberitahuan minimal 30 hari sebelum tanggal terakhir kerja (UU 13/2003 Pasal 162).
          </p>
        </div>
      </div>
    </div>

    <div v-if="loading" class="card"><div class="card-body text-center text-gray-400 py-8">Memuat...</div></div>

    <!-- ─── Active request status ─────────────────────────────────── -->
    <div v-else-if="activeRequest" class="card border-l-4 border-amber-400">
      <div class="card-body space-y-4">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-start gap-3 min-w-0">
            <ClockIcon class="w-6 h-6 text-amber-600 flex-shrink-0 mt-0.5" />
            <div class="min-w-0">
              <h2 class="font-semibold text-gray-800">Pengajuan Aktif</h2>
              <p class="text-xs text-gray-500 mt-0.5">
                Diajukan {{ fmtDate(activeRequest.submitted_at || activeRequest.created_at) }}
              </p>
            </div>
          </div>
          <span class="px-3 py-1 rounded-full text-xs font-medium" :class="statusBadge(activeRequest.status)">
            {{ statusLabel(activeRequest.status) }}
          </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <div>
            <div class="text-xs text-gray-500">Tanggal Terakhir Kerja</div>
            <div class="font-medium">{{ fmtDate(activeRequest.last_working_date) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">Efektif Resign</div>
            <div class="font-medium">{{ fmtDate(activeRequest.effective_date) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500">Notice Period</div>
            <div class="font-medium">{{ activeRequest.notice_period_days }} hari</div>
          </div>
        </div>

        <div>
          <div class="text-xs text-gray-500">Alasan</div>
          <div class="text-sm">{{ activeRequest.reason_category }}</div>
          <div v-if="activeRequest.reason_text" class="text-sm text-gray-600 mt-1 italic">
            "{{ activeRequest.reason_text }}"
          </div>
        </div>

        <!-- Approval timeline -->
        <div class="border-t border-gray-100 pt-3 space-y-2">
          <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Riwayat Persetujuan</h3>

          <div class="flex items-start gap-2 text-sm">
            <div class="w-6 h-6 rounded-full flex-shrink-0 flex items-center justify-center"
              :class="activeRequest.supervisor_decision === 'APPROVED' ? 'bg-emerald-100 text-emerald-700'
                : activeRequest.status === 'PENDING_SUPERVISOR' ? 'bg-amber-100 text-amber-700'
                : 'bg-gray-100 text-gray-400'">
              <CheckCircleIcon v-if="activeRequest.supervisor_decision === 'APPROVED'" class="w-4 h-4" />
              <ClockIcon v-else-if="activeRequest.status === 'PENDING_SUPERVISOR'" class="w-4 h-4" />
              <span v-else>—</span>
            </div>
            <div>
              <div class="font-medium">Atasan Langsung</div>
              <div class="text-xs text-gray-500">
                {{ activeRequest.supervisor_action_at ? fmtDate(activeRequest.supervisor_action_at) : 'Menunggu...' }}
              </div>
              <div v-if="activeRequest.supervisor_note" class="text-xs text-gray-600 mt-0.5 italic">
                "{{ activeRequest.supervisor_note }}"
              </div>
            </div>
          </div>

          <div class="flex items-start gap-2 text-sm">
            <div class="w-6 h-6 rounded-full flex-shrink-0 flex items-center justify-center"
              :class="activeRequest.hr_decision === 'APPROVED' ? 'bg-emerald-100 text-emerald-700'
                : activeRequest.status === 'PENDING_HR' ? 'bg-amber-100 text-amber-700'
                : 'bg-gray-100 text-gray-400'">
              <CheckCircleIcon v-if="activeRequest.hr_decision === 'APPROVED'" class="w-4 h-4" />
              <ClockIcon v-else-if="activeRequest.status === 'PENDING_HR'" class="w-4 h-4" />
              <span v-else>—</span>
            </div>
            <div>
              <div class="font-medium">HR</div>
              <div class="text-xs text-gray-500">
                {{ activeRequest.hr_action_at ? fmtDate(activeRequest.hr_action_at) : 'Menunggu...' }}
              </div>
              <div v-if="activeRequest.hr_note" class="text-xs text-gray-600 mt-0.5 italic">
                "{{ activeRequest.hr_note }}"
              </div>
            </div>
          </div>
        </div>

        <!-- After approved -->
        <div v-if="activeRequest.status === 'APPROVED'"
          class="bg-emerald-50 border border-emerald-200 rounded p-3 text-sm">
          <div class="font-medium text-emerald-800 inline-flex items-center gap-1">
            <CheckCircleIcon class="w-4 h-4" /> Pengajuan disetujui
          </div>
          <div class="text-emerald-700 mt-1">
            {{ activeRequest.tasks_generated_count }} task offboarding telah ter-generate.
            Cek menu <strong>Onboarding/Offboarding</strong> di sidebar untuk melihat task Anda.
          </div>
        </div>

        <!-- Cancel button -->
        <div v-if="activeRequest.status !== 'APPROVED'" class="flex justify-end">
          <button class="btn-outline-danger" @click="cancelRequest(activeRequest)">
            <XMarkIcon class="w-4 h-4 mr-1 inline" />
            Batalkan Pengajuan
          </button>
        </div>
      </div>
    </div>

    <!-- ─── Form pengajuan baru ────────────────────────────────────── -->
    <div v-else class="card">
      <div class="card-body space-y-5">
        <h2 class="font-semibold text-gray-800 inline-flex items-center gap-2">
          <DocumentTextIcon class="w-5 h-5 text-primary-600" />
          Form Pengajuan Resignation
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="label" for="lwd">
              Tanggal Terakhir Kerja <span class="text-red-500">*</span>
            </label>
            <AppDatePicker id="lwd"
              v-model="form.last_working_date"
              :min="minLastWorkingDate"
              class="input" />
            <p v-if="noticeDays !== null" class="text-xs mt-1"
              :class="noticeDays >= MIN_NOTICE_DAYS ? 'text-emerald-600' : 'text-red-600'">
              Notice period: {{ noticeDays }} hari
              <span v-if="noticeDays < MIN_NOTICE_DAYS"> — minimum 30 hari</span>
            </p>
          </div>
          <div>
            <label class="label" for="effdate">
              Tanggal Efektif Resign
              <span class="text-gray-500 font-normal text-xs">(opsional)</span>
            </label>
            <AppDatePicker id="effdate"
              v-model="form.effective_date"
              :min="form.last_working_date || minLastWorkingDate"
              class="input" />
            <p class="text-xs text-gray-500 mt-1">
              Default = tanggal terakhir kerja. Beda kalau ada cuti pengganti / saldo cuti dipakai.
            </p>
          </div>
        </div>

        <div>
          <label class="label" for="reason">
            Kategori Alasan <span class="text-red-500">*</span>
          </label>
          <select id="reason" v-model="form.reason_category" class="input">
            <option value="">— Pilih Alasan —</option>
            <option v-for="r in reasonCategories" :key="r.code" :value="r.code">
              {{ r.label }}
            </option>
          </select>
        </div>

        <div>
          <label class="label" for="reason_text">
            Detail Alasan
            <span class="text-gray-500 font-normal text-xs">(opsional, akan dimasukkan ke surat resign)</span>
          </label>
          <textarea id="reason_text" v-model="form.reason_text" rows="4" class="input"
            maxlength="2000"
            placeholder="Misal: Pindah ke perusahaan lain dengan kesempatan karir yang lebih sesuai..."></textarea>
          <p class="text-xs text-gray-500 mt-1">{{ (form.reason_text || '').length }} / 2000 karakter</p>
        </div>

        <div>
          <label class="label">
            Lampiran Surat Resign (opsional)
            <span class="text-gray-500 font-normal text-xs">PDF/JPG/PNG, maks 5 MB</span>
          </label>
          <input type="file" accept=".pdf,image/jpeg,image/png" @change="uploadAttachment"
            class="input file:mr-3 file:px-3 file:py-1 file:rounded file:border-0 file:bg-gray-100 file:text-gray-700" />
          <p v-if="form.attachment_path" class="text-xs text-emerald-600 mt-1">
            ✓ Lampiran terupload: {{ form.attachment_path.split('/').pop() }}
          </p>
        </div>

        <!-- Disclaimer -->
        <div class="bg-amber-50 border border-amber-200 rounded p-3 text-sm flex items-start gap-2">
          <ExclamationTriangleIcon class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
          <div class="text-amber-900">
            <strong>Penting:</strong> Setelah pengajuan ini disetujui HR, status karyawan Anda akan
            otomatis berubah menjadi resign per tanggal efektif. Pastikan Anda sudah
            berdiskusi dengan atasan dan menyelesaikan semua kewajiban kerja.
          </div>
        </div>

        <div class="flex items-center justify-between gap-3 pt-2">
          <p class="text-xs text-gray-500">
            <ShieldCheckIcon class="w-3.5 h-3.5 inline mr-1" />
            Data Anda dienkripsi dan hanya bisa dilihat oleh atasan & HR.
          </p>
          <button class="btn-primary" :disabled="! canSubmit || submitting" @click="submit">
            <PaperAirplaneIcon class="w-4 h-4 mr-1 inline" />
            {{ submitting ? 'Mengirim...' : 'Kirim Pengajuan' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ─── History ──────────────────────────────────────────────── -->
    <div v-if="historicalRequests.length > 0" class="card">
      <div class="card-header">
        <h3 class="font-semibold text-sm">Riwayat Pengajuan</h3>
      </div>
      <div class="card-body p-0">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th class="px-4 py-2 text-left">Tanggal Diajukan</th>
              <th class="px-4 py-2 text-left">LWD</th>
              <th class="px-4 py-2 text-left">Alasan</th>
              <th class="px-4 py-2 text-left">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in historicalRequests" :key="r.id">
              <td class="px-4 py-2">{{ fmtDate(r.created_at) }}</td>
              <td class="px-4 py-2">{{ fmtDate(r.last_working_date) }}</td>
              <td class="px-4 py-2 text-gray-600">{{ r.reason_category }}</td>
              <td class="px-4 py-2">
                <span class="px-2 py-0.5 rounded text-xs font-medium" :class="statusBadge(r.status)">
                  {{ statusLabel(r.status) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
