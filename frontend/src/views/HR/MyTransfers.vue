<script setup>
/**
 * Mutasi Saya — halaman karyawan untuk:
 *   1. Lihat daftar mutasi yang menyangkut diri sendiri (subjek mutasi)
 *   2. Memberi consent (setuju/tolak) kalau requires_consent=1 dan masih PENDING
 *   3. Tracking status: SUBMITTED → APPROVED → IMPLEMENTED
 *
 * Halaman ini selalu accessible oleh karyawan yang punya record employee
 * — tanpa permission khusus. Backend `/employee-transfers/my` auto-scope
 * filter ke employee_id user saat ini.
 *
 * Consent:
 *   - Karyawan boleh accept (AGREED) atau reject (REJECTED).
 *   - REJECTED otomatis cancel mutasi (status → CANCELLED).
 *   - AGREED unblock approval HR (kalau sudah SUBMITTED).
 *
 * Lihat docs/EMPLOYEE_TRANSFER.md.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate } from '@/composables/useResource'
import {
  ArrowsRightLeftIcon, EyeIcon, CheckCircleIcon, XCircleIcon,
  XMarkIcon, BuildingOffice2Icon, BuildingStorefrontIcon,
  BriefcaseIcon, MapPinIcon, ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()

const rows = ref([])
const total = ref(0)
const loading = ref(false)
const showDetail = ref(null)

const STATUS_BADGE = {
  DRAFT:       'bg-gray-100 text-gray-700',
  SUBMITTED:   'bg-amber-100 text-amber-800',
  APPROVED:    'bg-emerald-100 text-emerald-800',
  IMPLEMENTED: 'bg-emerald-200 text-emerald-900 font-semibold',
  REJECTED:    'bg-red-100 text-red-800',
  CANCELLED:   'bg-gray-100 text-gray-500',
}
const STATUS_LABEL = {
  DRAFT: 'Draft',
  SUBMITTED: 'Menunggu Approval HR',
  APPROVED: 'Disetujui',
  IMPLEMENTED: 'Sudah Berlaku',
  REJECTED: 'Ditolak',
  CANCELLED: 'Dibatalkan',
}
const TYPE_LABEL = {
  BRANCH: 'Pindah Cabang',
  DEPARTMENT: 'Pindah Department',
  POSITION: 'Pindah Posisi',
  WORK_LOCATION: 'Pindah Lokasi Kerja',
  COMBINED: 'Mutasi Kombinasi',
}
const REASON_LABEL = {
  OPERATIONAL: 'Kebutuhan Operasional',
  EMPLOYEE_REQUEST: 'Permintaan Saya',
  ROTATION: 'Rotasi Rutin',
  REORGANIZATION: 'Restrukturisasi',
  PROMOTION_PREP: 'Persiapan Promosi',
  PERSONAL: 'Alasan Pribadi',
  OTHER: 'Lainnya',
}

// Mutasi yang butuh aksi consent dari karyawan
const pendingConsent = computed(() =>
  rows.value.filter(r => r.requires_consent === 1 && r.consent_status === 'PENDING'
    && ['SUBMITTED', 'DRAFT'].includes(r.status))
)

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/employee-transfers/my', { params: { per_page: 50 } })
    rows.value = res.data.data || []
    total.value = res.data.meta?.total || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data mutasi')
  } finally {
    loading.value = false
  }
}

async function openDetail(id) {
  try {
    const res = await api.get(`/employee-transfers/${id}`)
    showDetail.value = res.data.data
  } catch (e) {
    toast.error('Gagal memuat detail')
  }
}

async function giveConsent(row, decision) {
  const labelMap = { AGREED: 'menyetujui', REJECTED: 'menolak' }
  const ok = await confirmDialog({
    title: decision === 'AGREED' ? 'Setujui Mutasi' : 'Tolak Mutasi',
    message: decision === 'REJECTED'
      ? `Anda yakin ingin menolak mutasi ${row.transfer_number}? Mutasi akan otomatis dibatalkan dan tidak bisa di-undo. HR akan dinotifikasi.`
      : `Anda yakin ingin menyetujui mutasi ${row.transfer_number}? Setelah ini HR akan melanjutkan proses approval.`,
    okText: decision === 'AGREED' ? 'Ya, Setuju' : 'Ya, Tolak',
    variant: decision === 'AGREED' ? 'success' : 'danger',
  })
  if (! ok) return

  try {
    await api.post(`/employee-transfers/${row.id}/consent`, { decision })
    toast.success(`Anda telah ${labelMap[decision]} mutasi ini`)
    await fetchData()
    if (showDetail.value?.id === row.id) await openDetail(row.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memproses')
  }
}

function isChanged(oldVal, newVal) {
  if (newVal === null || newVal === undefined || newVal === '') return false
  // eslint-disable-next-line eqeqeq
  return oldVal != newVal
}

onMounted(fetchData)
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-4">
      <h1 class="text-2xl font-bold flex items-center gap-2">
        <ArrowsRightLeftIcon class="w-6 h-6 text-primary-600" />
        Mutasi Saya
      </h1>
      <p class="text-sm text-gray-500">
        Daftar pengajuan mutasi yang menyangkut Anda. Berikan persetujuan atau penolakan
        untuk mutasi yang membutuhkan aksi Anda.
      </p>
    </div>

    <!-- Action banner: ada consent pending -->
    <div v-if="pendingConsent.length > 0"
      class="mb-4 p-4 rounded-lg border-2 border-amber-300 bg-amber-50 flex items-start gap-3">
      <ExclamationTriangleIcon class="w-6 h-6 text-amber-600 flex-shrink-0 mt-0.5" />
      <div class="flex-1">
        <div class="font-semibold text-amber-900">
          {{ pendingConsent.length }} mutasi membutuhkan persetujuan Anda
        </div>
        <div class="text-sm text-amber-800 mt-0.5">
          Klik tombol "Setuju" atau "Tolak" pada baris di bawah, atau buka detail untuk
          info lebih lengkap. Mutasi tidak akan diproses HR sampai Anda memberi keputusan.
        </div>
      </div>
    </div>

    <!-- List -->
    <div class="card">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>No. Mutasi</th>
              <th>Tipe</th>
              <th>Dari → Ke</th>
              <th>Berlaku</th>
              <th>Status</th>
              <th class="text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="loading">
              <td colspan="6" class="text-center py-8 text-gray-400">Memuat…</td>
            </tr>
            <tr v-else-if="rows.length === 0">
              <td colspan="6" class="text-center py-12 text-gray-400">
                Belum ada pengajuan mutasi atas nama Anda.
              </td>
            </tr>
            <tr v-for="r in rows" :key="r.id"
              class="align-top"
              :class="{ 'bg-amber-50/50': r.requires_consent && r.consent_status === 'PENDING' }">
              <td>
                <div class="font-mono text-xs">{{ r.transfer_number || '(draft)' }}</div>
                <div class="text-[10px] text-gray-400">{{ fmtDate(r.created_at) }}</div>
              </td>
              <td>
                <div class="text-xs">{{ TYPE_LABEL[r.transfer_type] || r.transfer_type }}</div>
                <div class="text-[10px] text-gray-400">{{ REASON_LABEL[r.reason] || r.reason }}</div>
              </td>
              <td class="text-xs leading-tight">
                <div v-if="r.transfer_type === 'BRANCH' || r.transfer_type === 'COMBINED'"
                  class="flex items-center gap-1">
                  <BuildingStorefrontIcon class="w-3.5 h-3.5 text-gray-400" />
                  <span class="text-gray-500">{{ r.old_branch_name || '-' }}</span>
                  <span class="mx-0.5">→</span>
                  <span class="font-medium">{{ r.new_branch_name || '-' }}</span>
                </div>
                <div v-if="r.transfer_type === 'DEPARTMENT' || r.transfer_type === 'COMBINED'"
                  class="flex items-center gap-1 mt-0.5">
                  <BuildingOffice2Icon class="w-3.5 h-3.5 text-gray-400" />
                  <span class="text-gray-500">{{ r.old_department_name || '-' }}</span>
                  <span class="mx-0.5">→</span>
                  <span class="font-medium">{{ r.new_department_name || '-' }}</span>
                </div>
                <div v-if="r.transfer_type === 'POSITION' || r.transfer_type === 'COMBINED'"
                  class="flex items-center gap-1 mt-0.5">
                  <BriefcaseIcon class="w-3.5 h-3.5 text-gray-400" />
                  <span class="text-gray-500">{{ r.old_position_name || '-' }}</span>
                  <span class="mx-0.5">→</span>
                  <span class="font-medium">{{ r.new_position_name || '-' }}</span>
                </div>
                <div v-if="r.transfer_type === 'WORK_LOCATION'"
                  class="flex items-center gap-1">
                  <MapPinIcon class="w-3.5 h-3.5 text-gray-400" />
                  <span class="text-gray-500">{{ r.old_work_location_name || '-' }}</span>
                  <span class="mx-0.5">→</span>
                  <span class="font-medium">{{ r.new_work_location_name || '-' }}</span>
                </div>
              </td>
              <td class="text-xs">{{ fmtDate(r.effective_date) }}</td>
              <td>
                <span class="px-2 py-0.5 rounded text-xs" :class="STATUS_BADGE[r.status]">
                  {{ STATUS_LABEL[r.status] || r.status }}
                </span>
                <div v-if="r.requires_consent && r.consent_status === 'PENDING'"
                  class="text-[10px] text-amber-700 font-medium mt-1">
                  ⚠ Butuh persetujuan Anda
                </div>
                <div v-else-if="r.consent_status === 'AGREED'"
                  class="text-[10px] text-emerald-700 mt-1">
                  ✓ Anda sudah setuju
                </div>
                <div v-else-if="r.consent_status === 'REJECTED'"
                  class="text-[10px] text-red-600 mt-1">
                  ✗ Anda menolak
                </div>
              </td>
              <td class="text-right">
                <div class="flex items-center justify-end gap-1 flex-wrap">
                  <button class="text-gray-500 hover:text-primary-600 p-1"
                    title="Lihat detail" @click="openDetail(r.id)">
                    <EyeIcon class="w-4 h-4" />
                  </button>
                  <template v-if="r.requires_consent && r.consent_status === 'PENDING'
                    && ['SUBMITTED','DRAFT'].includes(r.status)">
                    <button class="px-2 py-0.5 rounded text-xs bg-emerald-600 hover:bg-emerald-700 text-white inline-flex items-center gap-1"
                      title="Setujui mutasi" @click="giveConsent(r, 'AGREED')">
                      <CheckCircleIcon class="w-3.5 h-3.5" /> Setuju
                    </button>
                    <button class="px-2 py-0.5 rounded text-xs bg-red-100 hover:bg-red-200 text-red-700 inline-flex items-center gap-1"
                      title="Tolak mutasi" @click="giveConsent(r, 'REJECTED')">
                      <XCircleIcon class="w-3.5 h-3.5" /> Tolak
                    </button>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── Detail Modal ───────────────────────────────────────── -->
    <div v-if="showDetail" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showDetail = null">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <div>
            <h2 class="font-semibold">{{ showDetail.transfer_number || 'Mutasi' }}</h2>
            <p class="text-xs text-gray-500">{{ TYPE_LABEL[showDetail.transfer_type] }}</p>
          </div>
          <button class="text-gray-500" @click="showDetail = null">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-4 text-sm">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <div class="text-xs text-gray-500">Status</div>
              <span class="px-2 py-0.5 rounded text-xs inline-block mt-1"
                :class="STATUS_BADGE[showDetail.status]">
                {{ STATUS_LABEL[showDetail.status] }}
              </span>
            </div>
            <div>
              <div class="text-xs text-gray-500">Tanggal Berlaku</div>
              <div>{{ fmtDate(showDetail.effective_date) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Alasan</div>
              <div>{{ REASON_LABEL[showDetail.reason] }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Diajukan Oleh</div>
              <div>{{ showDetail.creator_name || '-' }}</div>
            </div>
          </div>

          <div v-if="showDetail.reason_text" class="bg-gray-50 rounded p-3 text-xs">
            <div class="text-gray-500 mb-1">Latar Belakang</div>
            <div class="whitespace-pre-line">{{ showDetail.reason_text }}</div>
          </div>

          <!-- Consent banner -->
          <div v-if="showDetail.requires_consent && showDetail.consent_status === 'PENDING'
              && ['SUBMITTED','DRAFT'].includes(showDetail.status)"
            class="border-2 border-amber-300 bg-amber-50 rounded p-3 text-xs">
            <div class="font-semibold text-amber-900 flex items-center gap-1.5 mb-1">
              <ExclamationTriangleIcon class="w-4 h-4" />
              Mutasi ini membutuhkan persetujuan Anda
            </div>
            <p class="text-amber-800">
              Berdasarkan asas konsensual UU Ketenagakerjaan, mutasi yang signifikan
              membutuhkan persetujuan tertulis dari karyawan ybs. Silakan tinjau
              perubahan di bawah lalu pilih <strong>Setuju</strong> atau <strong>Tolak</strong>.
            </p>
          </div>

          <!-- Comparison -->
          <div class="border rounded overflow-hidden">
            <div class="bg-gray-50 px-3 py-2 text-xs font-semibold uppercase text-gray-600">
              Perubahan Posisi
            </div>
            <table class="w-full text-sm">
              <thead class="bg-gray-50 text-xs">
                <tr>
                  <th class="text-left px-3 py-1">Field</th>
                  <th class="text-left px-3 py-1 text-gray-500">Saat Ini</th>
                  <th class="text-left px-3 py-1 text-emerald-700">Setelah Mutasi</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <tr v-if="showDetail.new_branch_id">
                  <td class="px-3 py-1.5">Cabang</td>
                  <td class="px-3 py-1.5 text-gray-500">{{ showDetail.old_branch_name || '-' }}</td>
                  <td class="px-3 py-1.5"
                    :class="isChanged(showDetail.old_branch_id, showDetail.new_branch_id) ? 'font-semibold text-emerald-700' : ''">
                    {{ showDetail.new_branch_name || '-' }}
                  </td>
                </tr>
                <tr v-if="showDetail.new_department_id">
                  <td class="px-3 py-1.5">Department</td>
                  <td class="px-3 py-1.5 text-gray-500">{{ showDetail.old_department_name || '-' }}</td>
                  <td class="px-3 py-1.5"
                    :class="isChanged(showDetail.old_department_id, showDetail.new_department_id) ? 'font-semibold text-emerald-700' : ''">
                    {{ showDetail.new_department_name || '-' }}
                  </td>
                </tr>
                <tr v-if="showDetail.new_position_id">
                  <td class="px-3 py-1.5">Posisi</td>
                  <td class="px-3 py-1.5 text-gray-500">{{ showDetail.old_position_name || '-' }}</td>
                  <td class="px-3 py-1.5"
                    :class="isChanged(showDetail.old_position_id, showDetail.new_position_id) ? 'font-semibold text-emerald-700' : ''">
                    {{ showDetail.new_position_name || '-' }}
                  </td>
                </tr>
                <tr v-if="showDetail.new_work_location_id">
                  <td class="px-3 py-1.5">Lokasi Kerja</td>
                  <td class="px-3 py-1.5 text-gray-500">{{ showDetail.old_work_location_name || '-' }}</td>
                  <td class="px-3 py-1.5"
                    :class="isChanged(showDetail.old_work_location_id, showDetail.new_work_location_id) ? 'font-semibold text-emerald-700' : ''">
                    {{ showDetail.new_work_location_name || '-' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Workflow history -->
          <div class="text-xs space-y-1">
            <div v-if="showDetail.consent_at" class="text-gray-700">
              Anda
              <strong v-if="showDetail.consent_status === 'AGREED'" class="text-emerald-700">menyetujui</strong>
              <strong v-else-if="showDetail.consent_status === 'REJECTED'" class="text-red-600">menolak</strong>
              mutasi ini pada {{ fmtDate(showDetail.consent_at) }}
            </div>
            <div v-if="showDetail.approved_at" class="text-emerald-700">
              Disetujui HR: {{ fmtDate(showDetail.approved_at) }}
              <span v-if="showDetail.approver_name"> oleh {{ showDetail.approver_name }}</span>
            </div>
            <div v-if="showDetail.implemented_at" class="text-emerald-800">
              Sudah berlaku per: {{ fmtDate(showDetail.implemented_at) }}
            </div>
            <div v-if="showDetail.rejection_reason" class="text-red-700">
              Alasan ditolak HR: {{ showDetail.rejection_reason }}
            </div>
            <div v-if="showDetail.cancellation_reason" class="text-gray-500">
              Dibatalkan: {{ showDetail.cancellation_reason }}
            </div>
          </div>
        </div>

        <div class="px-5 py-3 border-t flex justify-end gap-2 flex-wrap">
          <button class="btn-secondary" @click="showDetail = null">Tutup</button>
          <template v-if="showDetail.requires_consent && showDetail.consent_status === 'PENDING'
              && ['SUBMITTED','DRAFT'].includes(showDetail.status)">
            <button class="px-4 py-2 rounded bg-red-100 hover:bg-red-200 text-red-700 inline-flex items-center gap-1.5"
              @click="giveConsent(showDetail, 'REJECTED')">
              <XCircleIcon class="w-4 h-4" /> Tolak Mutasi
            </button>
            <button class="px-4 py-2 rounded bg-emerald-600 hover:bg-emerald-700 text-white inline-flex items-center gap-1.5"
              @click="giveConsent(showDetail, 'AGREED')">
              <CheckCircleIcon class="w-4 h-4" /> Setujui Mutasi
            </button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>