<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { useCandidateAuthStore, candidateApi } from '@/stores/candidateAuth'

/**
 * CandidateOfferDetail — halaman review + accept/reject offer.
 *
 * Sprint 25 / Option C — alur:
 *   1. Kandidat klik "Review & Respon" dari list offer
 *   2. Halaman ini show breakdown lengkap (gaji, tunjangan, benefits,
 *      probation, tanggal mulai)
 *   3. Tombol "Terima" / "Tolak" → buka konfirmasi dialog dengan
 *      ringkasan + textarea note
 *   4. POST /careers/account/offers/{id}/respond → success page
 *
 * Setelah respond, halaman tetap accessible (read-only) untuk audit
 * trail kandidat — sama pola dengan online test result.
 */
const route = useRoute()
const router = useRouter()
const toast = useToast()
const store = useCandidateAuthStore()
const api = candidateApi()

const slug = computed(() => route.params.slug)
const offerId = computed(() => Number(route.params.id))

const loading = ref(true)
const data = ref(null)

// Dialog state
const showDialog = ref(false)
const dialogDecision = ref(null) // 'ACCEPTED' | 'REJECTED'
const note = ref('')
const rejectionReason = ref('')
const submitting = ref(false)

onMounted(async () => {
  if (! store.isAuthenticated) {
    return router.push({ name: 'candidate-login', params: { slug: slug.value } })
  }
  await load()
})

async function load() {
  loading.value = true
  try {
    const res = await api.get(`/careers/account/offers/${offerId.value}`)
    data.value = res.data.data
  } catch (e) {
    if (e.response?.status === 404) {
      toast.error('Penawaran tidak ditemukan')
      return router.push({ name: 'candidate-offers', params: { slug: slug.value } })
    }
    toast.error(e.response?.data?.message || 'Gagal memuat detail penawaran')
  } finally {
    loading.value = false
  }
}

function openDialog(decision) {
  dialogDecision.value = decision
  note.value = ''
  rejectionReason.value = ''
  showDialog.value = true
}

function closeDialog() {
  if (submitting.value) return
  showDialog.value = false
  dialogDecision.value = null
}

async function submitResponse() {
  if (submitting.value) return

  if (dialogDecision.value === 'REJECTED') {
    if (! rejectionReason.value.trim() || rejectionReason.value.trim().length < 5) {
      toast.error('Alasan penolakan minimal 5 karakter')
      return
    }
  }

  submitting.value = true
  try {
    const payload = {
      decision: dialogDecision.value,
      note: note.value.trim() || null,
    }
    if (dialogDecision.value === 'REJECTED') {
      payload.rejection_reason = rejectionReason.value.trim()
    }
    const res = await api.post(`/careers/account/offers/${offerId.value}/respond`, payload)
    toast.success(res.data.message || 'Respon berhasil dicatat')
    showDialog.value = false
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mencatat respon')
  } finally {
    submitting.value = false
  }
}

// ─── Format helpers ───────────────────────────────────────────────

const offer = computed(() => data.value?.offer || null)
const job = computed(() => data.value?.job || null)
const company = computed(() => data.value?.company || null)
const canRespond = computed(() => data.value?.can_respond || false)
const expired = computed(() => data.value?.expired || false)

const totalAllowance = computed(() => {
  if (! offer.value?.allowances) return 0
  return offer.value.allowances.reduce((sum, a) => sum + Number(a.amount || 0), 0)
})

const totalCompensation = computed(() => {
  if (! offer.value) return 0
  return Number(offer.value.basic_salary || 0) + totalAllowance.value
})

const statusLabel = (status) => ({
  DRAFT:     'Draft',
  SENT:      'Menunggu Respon Anda',
  ACCEPTED:  'Sudah Anda Terima',
  REJECTED:  'Sudah Anda Tolak',
  EXPIRED:   'Kadaluarsa',
  WITHDRAWN: 'Dibatalkan oleh HR',
}[status] || status)

const statusColor = (status) => ({
  DRAFT:     'bg-slate-100 text-slate-600',
  SENT:      'bg-amber-100 text-amber-800',
  ACCEPTED:  'bg-emerald-100 text-emerald-700',
  REJECTED:  'bg-rose-100 text-rose-700',
  EXPIRED:   'bg-slate-200 text-slate-600',
  WITHDRAWN: 'bg-slate-200 text-slate-600',
}[status] || 'bg-slate-100 text-slate-700')

const employmentLabel = (s) => ({
  PKWTT: 'PKWTT (Karyawan Tetap)',
  PKWT: 'PKWT (Karyawan Kontrak)',
  PROBATION: 'Probation',
  MAGANG: 'Magang',
  OUTSOURCING: 'Outsourcing',
}[s] || s)

function fmtMoney(amount, currency = 'IDR') {
  if (amount == null) return '-'
  const n = Number(amount)
  if (currency === 'IDR') return 'Rp ' + new Intl.NumberFormat('id-ID').format(n)
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency }).format(n)
}

function fmtDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <div class="max-w-3xl mx-auto py-8 px-4">
    <!-- Back button -->
    <router-link :to="{ name: 'candidate-offers', params: { slug } }"
      class="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900 mb-4">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
      </svg>
      Kembali ke daftar penawaran
    </router-link>

    <div v-if="loading" class="text-center py-12 text-slate-500">Memuat...</div>

    <template v-else-if="offer">
      <!-- Header -->
      <div class="bg-white rounded-xl border border-slate-200 p-6 mb-4">
        <div class="flex items-start justify-between gap-4 mb-4">
          <div>
            <div class="text-xs text-slate-500">Surat Penawaran Kerja</div>
            <h1 class="text-xl font-bold text-slate-900 mt-1">
              {{ offer.offer_number }}
            </h1>
            <p v-if="company" class="text-sm text-slate-600 mt-1">{{ company.name }}</p>
          </div>
          <span class="text-xs px-3 py-1 rounded-full font-medium whitespace-nowrap"
            :class="statusColor(offer.status)">
            {{ statusLabel(offer.status) }}
          </span>
        </div>

        <!-- Status banners -->
        <div v-if="offer.status === 'SENT' && expired"
          class="bg-rose-50 border border-rose-200 rounded-lg p-3 text-sm text-rose-800 mb-4">
          ⚠ Batas waktu respon ({{ fmtDate(offer.response_deadline) }}) telah lewat.
          Hubungi tim HR untuk konfirmasi lebih lanjut.
        </div>
        <div v-else-if="offer.status === 'SENT' && offer.response_deadline"
          class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800 mb-4">
          ⏱ Mohon respon penawaran ini sebelum
          <strong>{{ fmtDate(offer.response_deadline) }}</strong>.
        </div>
        <div v-else-if="offer.status === 'ACCEPTED'"
          class="bg-emerald-50 border border-emerald-200 rounded-lg p-3 text-sm text-emerald-800 mb-4">
          ✓ Anda menerima penawaran ini pada {{ fmtDate(offer.responded_at) }}.
          Tim HR akan menghubungi Anda untuk proses berikutnya.
        </div>
        <div v-else-if="offer.status === 'REJECTED'"
          class="bg-rose-50 border border-rose-200 rounded-lg p-3 text-sm text-rose-800 mb-4">
          Anda menolak penawaran ini pada {{ fmtDate(offer.responded_at) }}.
          <span v-if="offer.rejection_reason">
            <br /><span class="font-medium">Alasan:</span> {{ offer.rejection_reason }}
          </span>
        </div>
      </div>

      <!-- Position info -->
      <div v-if="job || offer.position_name"
        class="bg-white rounded-xl border border-slate-200 p-6 mb-4">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Posisi yang Ditawarkan</h2>
        <div class="space-y-2 text-sm">
          <div v-if="job?.title || offer.position_name">
            <span class="text-slate-500">Jabatan:</span>
            <span class="ml-2 font-medium text-slate-900">{{ offer.position_name || job?.title }}</span>
          </div>
          <div v-if="offer.department_name">
            <span class="text-slate-500">Departemen:</span>
            <span class="ml-2 text-slate-900">{{ offer.department_name }}</span>
          </div>
          <div v-if="offer.branch_name">
            <span class="text-slate-500">Cabang:</span>
            <span class="ml-2 text-slate-900">{{ offer.branch_name }}</span>
          </div>
          <div v-if="job?.work_arrangement">
            <span class="text-slate-500">Pengaturan Kerja:</span>
            <span class="ml-2 text-slate-900">{{ job.work_arrangement }}</span>
          </div>
          <div v-if="job?.city">
            <span class="text-slate-500">Lokasi:</span>
            <span class="ml-2 text-slate-900">{{ job.city }}</span>
          </div>
        </div>
      </div>

      <!-- Compensation -->
      <div class="bg-white rounded-xl border border-slate-200 p-6 mb-4">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Kompensasi</h2>
        <div class="space-y-3">
          <div class="flex items-center justify-between py-2 border-b border-slate-100">
            <span class="text-sm text-slate-600">Gaji Pokok</span>
            <span class="font-semibold text-slate-900">
              {{ fmtMoney(offer.basic_salary, offer.currency) }}
            </span>
          </div>
          <div v-if="offer.allowances && offer.allowances.length">
            <div class="text-xs text-slate-500 mb-1">Tunjangan</div>
            <div v-for="(a, i) in offer.allowances" :key="i"
              class="flex items-center justify-between text-sm py-1.5 pl-3">
              <span class="text-slate-700">
                {{ a.label }}
                <span v-if="a.type" class="text-xs text-slate-400">({{ a.type }})</span>
              </span>
              <span class="text-slate-900">{{ fmtMoney(a.amount, offer.currency) }}</span>
            </div>
          </div>
          <div v-if="offer.joining_bonus > 0"
            class="flex items-center justify-between py-2 border-b border-slate-100">
            <span class="text-sm text-slate-600">Joining Bonus (sekali bayar)</span>
            <span class="text-slate-900">{{ fmtMoney(offer.joining_bonus, offer.currency) }}</span>
          </div>
          <div class="flex items-center justify-between pt-3 border-t border-slate-200">
            <span class="text-sm font-semibold text-slate-900">Total Bulanan</span>
            <span class="text-lg font-bold text-indigo-600">
              {{ fmtMoney(totalCompensation, offer.currency) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Employment terms -->
      <div class="bg-white rounded-xl border border-slate-200 p-6 mb-4">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Ketentuan Kerja</h2>
        <div class="space-y-2 text-sm">
          <div>
            <span class="text-slate-500">Status Karyawan:</span>
            <span class="ml-2 font-medium text-slate-900">{{ employmentLabel(offer.employment_status) }}</span>
          </div>
          <div v-if="offer.proposed_join_date">
            <span class="text-slate-500">Tanggal Mulai:</span>
            <span class="ml-2 text-slate-900">{{ fmtDate(offer.proposed_join_date) }}</span>
          </div>
          <div v-if="offer.probation_months > 0">
            <span class="text-slate-500">Masa Percobaan:</span>
            <span class="ml-2 text-slate-900">{{ offer.probation_months }} bulan</span>
            <span class="text-xs text-slate-400 ml-2">(sesuai UU 13/2003 Pasal 60)</span>
          </div>
          <div v-else-if="offer.employment_status === 'PKWTT'">
            <span class="text-slate-500">Masa Percobaan:</span>
            <span class="ml-2 text-slate-900">Tidak ada</span>
          </div>
        </div>
      </div>

      <!-- Benefits -->
      <div v-if="offer.benefits && offer.benefits.length"
        class="bg-white rounded-xl border border-slate-200 p-6 mb-4">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Benefit Tambahan</h2>
        <ul class="text-sm text-slate-700 space-y-1">
          <li v-for="(b, i) in offer.benefits" :key="i" class="flex items-start gap-2">
            <span class="text-emerald-500">✓</span>
            <span v-if="typeof b === 'string'">{{ b }}</span>
            <span v-else>{{ b.label || b.name || JSON.stringify(b) }}</span>
          </li>
        </ul>
      </div>

      <!-- Action buttons -->
      <div v-if="canRespond" class="bg-white rounded-xl border-2 border-indigo-200 p-6 mb-4">
        <h2 class="text-base font-semibold text-slate-900 mb-2">Respon Anda</h2>
        <p class="text-sm text-slate-600 mb-4">
          Setelah Anda menerima atau menolak penawaran ini, tim HR akan menerima notifikasi
          secara otomatis. Pastikan Anda sudah membaca seluruh detail di atas dengan teliti.
        </p>
        <div class="flex flex-col sm:flex-row gap-2">
          <button @click="openDialog('ACCEPTED')"
            class="flex-1 px-4 py-3 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">
            ✓ Terima Penawaran
          </button>
          <button @click="openDialog('REJECTED')"
            class="flex-1 px-4 py-3 bg-white border border-rose-300 text-rose-700 rounded-lg font-medium hover:bg-rose-50 transition">
            ✕ Tolak Penawaran
          </button>
        </div>
      </div>
    </template>

    <!-- Confirmation dialog -->
    <div v-if="showDialog"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
      @click.self="closeDialog">
      <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-bold text-slate-900 mb-3">
          {{ dialogDecision === 'ACCEPTED' ? 'Konfirmasi Terima Penawaran' : 'Konfirmasi Tolak Penawaran' }}
        </h3>

        <div class="bg-slate-50 rounded-lg p-3 mb-4 text-sm space-y-1">
          <div><span class="text-slate-500">Nomor:</span> <strong>{{ offer.offer_number }}</strong></div>
          <div><span class="text-slate-500">Posisi:</span> {{ offer.position_name || job?.title || '-' }}</div>
          <div><span class="text-slate-500">Total bulanan:</span>
            <strong>{{ fmtMoney(totalCompensation, offer.currency) }}</strong></div>
          <div><span class="text-slate-500">Mulai:</span> {{ fmtDate(offer.proposed_join_date) }}</div>
        </div>

        <div v-if="dialogDecision === 'ACCEPTED'" class="mb-4">
          <p class="text-sm text-slate-700 mb-3">
            Dengan menerima penawaran ini, Anda menyatakan kesediaan untuk bergabung dengan
            <strong>{{ company?.name }}</strong> sesuai ketentuan yang tertera. Tim HR akan
            menghubungi Anda untuk proses onboarding berikutnya.
          </p>
          <label class="block text-sm font-medium text-slate-700 mb-1">
            Catatan untuk HR (opsional)
          </label>
          <textarea v-model="note" rows="3" maxlength="2000"
            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
            placeholder="Misalnya: ada permintaan tanggal mulai yang berbeda, dll." />
        </div>

        <div v-else class="mb-4">
          <p class="text-sm text-slate-700 mb-3">
            Dengan menolak penawaran ini, Anda menyatakan tidak bisa bergabung dengan posisi
            yang ditawarkan. Status lamaran Anda akan ditandai sebagai ditolak oleh kandidat.
          </p>
          <label class="block text-sm font-medium text-slate-700 mb-1">
            Alasan Penolakan <span class="text-rose-500">*</span>
          </label>
          <textarea v-model="rejectionReason" rows="3" maxlength="1000" required
            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
            placeholder="Misalnya: sudah menerima offer di tempat lain, paket tidak sesuai ekspektasi, dll." />
          <div class="text-xs text-slate-400 mt-1">Minimal 5 karakter</div>

          <label class="block text-sm font-medium text-slate-700 mb-1 mt-3">
            Catatan tambahan (opsional)
          </label>
          <textarea v-model="note" rows="2" maxlength="2000"
            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
        </div>

        <div class="flex gap-2 justify-end">
          <button @click="closeDialog" :disabled="submitting"
            class="px-4 py-2 text-sm text-slate-700 hover:bg-slate-100 rounded-lg disabled:opacity-50">
            Batal
          </button>
          <button @click="submitResponse" :disabled="submitting"
            class="px-4 py-2 text-sm font-medium text-white rounded-lg disabled:opacity-50"
            :class="dialogDecision === 'ACCEPTED'
              ? 'bg-emerald-600 hover:bg-emerald-700'
              : 'bg-rose-600 hover:bg-rose-700'">
            {{ submitting ? 'Memproses...' : (dialogDecision === 'ACCEPTED' ? 'Ya, Terima' : 'Ya, Tolak') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>