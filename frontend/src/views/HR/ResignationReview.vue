<script setup>
/**
 * ResignationReview — halaman HR/atasan untuk review pengajuan resignation.
 *
 * Filter `as`:
 *   - hr         → semua resignation di company (butuh perm resignation.view/manage)
 *   - supervisor → khusus dari karyawan yang supervisor/manager-nya = caller
 *
 * Saat HR approve di stage PENDING_HR, bisa pilih offboarding template
 * yang dipakai. Backend default ke template OFFBOARD aktif kalau tidak
 * di-set.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { fmtDate } from '@/composables/useResource'
import {
  ArrowLeftStartOnRectangleIcon, ClockIcon, CheckCircleIcon, XMarkIcon,
  EyeIcon, MagnifyingGlassIcon, ChatBubbleLeftEllipsisIcon, UserIcon,
  CalendarDaysIcon, BookOpenIcon, ExclamationTriangleIcon, FunnelIcon,
  DocumentTextIcon, BanknotesIcon, ShieldCheckIcon, ShieldExclamationIcon,
  ArrowDownTrayIcon, CurrencyDollarIcon,
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const toast = useToast()
const route = useRoute()

const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = 20
const loading = ref(false)
const offboardingTemplates = ref([])
const selected = ref(null)   // request yang dibuka di modal review (detail lengkap)
const decision = ref({ note: '', offboarding_template_id: '' })
const acting = ref(false)
const loadingDetail = ref(false)

// State khusus offboarding clearance form (Sprint 1)
const clearanceForm = ref({ note: '' })
const finalSettlementForm = ref({ amount: 0, proof_path: '', note: '' })

// Filter UI — default ke 'hr' kalau ada permission, ke 'supervisor' kalau tidak.
const filterAs = ref(
  auth.can('resignation.view') || auth.can('resignation.manage')
    ? 'hr'
    : 'supervisor'
)
const filterStatus = ref(route.query.status || '')

const isHr = computed(() =>
  auth.isSuperAdmin || auth.can('resignation.view') || auth.can('resignation.manage'),
)

function statusBadge(s) {
  return ({
    PENDING_SUPERVISOR: 'bg-amber-100 text-amber-800',
    PENDING_HR:         'bg-blue-100 text-blue-800',
    APPROVED:           'bg-emerald-100 text-emerald-800',
    REJECTED:           'bg-red-100 text-red-800',
    CANCELLED:          'bg-gray-100 text-gray-500',
  })[s] || 'bg-gray-100 text-gray-700'
}
const statusLabel = (s) => ({
  PENDING_SUPERVISOR: 'Menunggu Atasan',
  PENDING_HR:         'Menunggu HR',
  APPROVED:           'Disetujui',
  REJECTED:           'Ditolak',
  CANCELLED:          'Dibatalkan',
})[s] || s

async function fetchData() {
  loading.value = true
  try {
    const params = { as: filterAs.value, page: page.value, per_page: perPage }
    if (filterStatus.value) params.status = filterStatus.value

    const res = await api.get('/resignations', { params })
    items.value = res.data?.data || []
    total.value = res.data?.meta?.total || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat data')
  } finally {
    loading.value = false
  }
}

async function fetchTemplates() {
  if (! isHr.value) return
  try {
    const res = await api.get('/task-templates', { params: { type: 'OFFBOARD', per_page: 50 } })
    offboardingTemplates.value = (res.data?.data || []).filter(t => t.is_active && t.type === 'OFFBOARD')
  } catch { /* abaikan, optional */ }
}

async function openReview(req) {
  // Buka modal cepat dengan data list, lalu fetch detail untuk dapat
  // offboarding clearance status (yang tidak ada di list endpoint).
  selected.value = req
  decision.value = { note: '', offboarding_template_id: '' }
  clearanceForm.value = { note: '' }
  finalSettlementForm.value = { amount: 0, proof_path: '', note: '' }
  loadingDetail.value = true
  try {
    const res = await api.get(`/resignations/${req.id}`)
    selected.value = res.data?.data || req
  } catch (e) {
    // Best-effort: tetap tampilkan data list walau detail gagal
    toast.warning('Gagal memuat detail offboarding clearance: ' + (e.response?.data?.message || e.message))
  } finally {
    loadingDetail.value = false
  }
}

function closeReview() {
  selected.value = null
  loadingDetail.value = false
}

async function refreshSelected() {
  if (! selected.value?.id) return
  try {
    const res = await api.get(`/resignations/${selected.value.id}`)
    selected.value = res.data?.data || selected.value
  } catch {}
}

// ─── Offboarding clearance actions (Sprint 1) ──────────────────────

async function setAssetClearance(cleared) {
  if (! selected.value) return
  const note = clearanceForm.value.note.trim()
  // Backend akan throw kalau ada aset belum balik & note kurang dari 10 char
  if (cleared && selected.value.offboarding?.clearance?.assets?.pending_count > 0
      && note.length < 10) {
    toast.warning(
      'Masih ada aset belum dikembalikan. Catatan exception minimum 10 karakter '
      + '(mis. "BAP polisi 2026/123 untuk laptop hilang").',
    )
    return
  }
  acting.value = true
  try {
    await api.post(`/resignations/${selected.value.id}/offboarding/asset-clearance`, {
      cleared,
      note: note || null,
    })
    toast.success(cleared ? 'Asset clearance disetujui' : 'Asset clearance di-reset')
    clearanceForm.value.note = ''
    await refreshSelected()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal update asset clearance')
  } finally { acting.value = false }
}

async function markBpjsDeactivated() {
  if (! selected.value) return
  const ok = await confirmDialog({
    title: 'Tandai BPJS Sudah Di-deactivate?',
    message: 'Pastikan Anda sudah submit deactivate ke e-Dabu (Kesehatan) & SiPP (Ketenagakerjaan). '
      + 'Setelah ditandai, status compliance H+7 dianggap terpenuhi.',
    okText: 'Ya, Tandai',
    variant: 'primary',
  })
  if (! ok) return
  acting.value = true
  try {
    await api.post(`/resignations/${selected.value.id}/offboarding/bpjs-deactivated`, {
      note: clearanceForm.value.note || null,
    })
    toast.success('BPJS ditandai sudah di-deactivate')
    clearanceForm.value.note = ''
    await refreshSelected()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal update BPJS status')
  } finally { acting.value = false }
}

async function generatePaklaring() {
  if (! selected.value) return
  const ok = await confirmDialog({
    title: 'Terbitkan Paklaring?',
    message: 'Surat Keterangan Kerja (paklaring) akan dibuat & link-nya dikirim ke karyawan. '
      + 'Pre-condition: status APPROVED, asset clearance OK, dan resign_date sudah lewat.',
    okText: 'Ya, Terbitkan',
    variant: 'primary',
  })
  if (! ok) return
  acting.value = true
  try {
    const res = await api.post(`/resignations/${selected.value.id}/offboarding/paklaring`, {})
    toast.success(res.data?.message || 'Paklaring berhasil diterbitkan')
    await refreshSelected()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal generate paklaring')
  } finally { acting.value = false }
}

function downloadPaklaring(format = 'pdf') {
  if (! selected.value?.offboarding?.paklaring?.generated) return
  // Backend butuh paklaring_id sebagai segment kedua untuk IDOR protection
  const resignationId = selected.value.id
  // paklaring_id tersedia di selected.value (column resignation_requests.paklaring_id)
  const paklaringId = selected.value.paklaring_id
  if (! paklaringId) {
    toast.warning('Paklaring ID tidak ditemukan, refresh halaman.')
    return
  }
  const url = `/api/v1/resignations/${resignationId}/offboarding/paklaring/${paklaringId}?format=${format}`
  window.open(url, '_blank')
}

async function linkFinalSettlement() {
  if (! selected.value) return
  const ok = await confirmDialog({
    title: 'Link Final Settlement ke Payroll?',
    message: 'Sistem akan estimasi gaji prorata + sisa cuti + pesangon (kalau ada severance FINALIZED) '
      + 'dan link ke periode payroll bulan resign. HR perlu adjust komponen di Payroll module setelahnya.',
    okText: 'Ya, Hitung & Link',
    variant: 'primary',
  })
  if (! ok) return
  acting.value = true
  try {
    const res = await api.post(`/resignations/${selected.value.id}/offboarding/final-settlement`, {})
    toast.success(res.data?.message || res.data?.data?.notes || 'Final settlement ter-link')
    finalSettlementForm.value.amount = res.data?.data?.amount || 0
    await refreshSelected()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal link final settlement')
  } finally { acting.value = false }
}

async function markFinalSettlementPaid() {
  if (! selected.value) return
  const amount = parseFloat(finalSettlementForm.value.amount)
  if (! amount || amount <= 0) {
    toast.warning('Jumlah final settlement wajib > 0')
    return
  }
  const ok = await confirmDialog({
    title: 'Tandai Final Settlement Sudah Dibayar?',
    message: `Jumlah Rp ${amount.toLocaleString('id-ID')} akan tercatat sebagai final settlement. `
      + `Compliance: UU 13/2003 Pasal 156 wajibkan pembayaran ≤ 7 hari dari resign_date.`,
    okText: 'Ya, Tandai Sudah Bayar',
    variant: 'primary',
  })
  if (! ok) return
  acting.value = true
  try {
    await api.post(`/resignations/${selected.value.id}/offboarding/final-settlement/paid`, {
      amount,
      proof_path: finalSettlementForm.value.proof_path || null,
      note: finalSettlementForm.value.note || null,
    })
    toast.success('Final settlement ditandai sudah dibayar')
    await refreshSelected()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mark paid')
  } finally { acting.value = false }
}

function fmtMoney(n) {
  if (n === null || n === undefined) return '—'
  return 'Rp ' + Number(n).toLocaleString('id-ID')
}

async function approve(req) {
  if (! decision.value.note || decision.value.note.length < 5) {
    // Note opsional saat approve, jadi kasih konfirmasi singkat
  }
  const isHrStage = req.status === 'PENDING_HR'
  const ok = await confirmDialog({
    title:   isHrStage ? 'Setujui resignation (final)?' : 'Setujui resignation?',
    message: isHrStage
      ? `Setelah disetujui, status karyawan ${req.employee_name} akan otomatis berubah `
        + `menjadi resign per ${fmtDate(req.effective_date || req.last_working_date)} `
        + `dan task offboarding akan ter-generate. Tindakan ini tidak mudah dibatalkan.`
      : 'Pengajuan akan diteruskan ke HR untuk final review. Lanjutkan?',
    okText:  'Ya, Setujui',
    variant: isHrStage ? 'danger' : 'primary',
  })
  if (! ok) return

  acting.value = true
  try {
    const payload = {
      note: decision.value.note || null,
    }
    if (isHrStage && decision.value.offboarding_template_id) {
      payload.offboarding_template_id = parseInt(decision.value.offboarding_template_id, 10)
    }
    const res = await api.post(`/resignations/${req.id}/approve`, payload)
    toast.success(res.data?.message || 'Disetujui')
    closeReview()
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyetujui')
  } finally {
    acting.value = false
  }
}

async function reject(req) {
  if (! decision.value.note || decision.value.note.length < 10) {
    toast.warning('Alasan penolakan wajib diisi (minimal 10 karakter)')
    return
  }
  const ok = await confirmDialog({
    title:   'Tolak pengajuan resignation?',
    message: `Pengajuan dari ${req.employee_name} akan ditolak. Karyawan akan menerima notifikasi.`,
    okText:  'Ya, Tolak',
    variant: 'danger',
  })
  if (! ok) return

  acting.value = true
  try {
    await api.post(`/resignations/${req.id}/reject`, { note: decision.value.note })
    toast.success('Pengajuan ditolak')
    closeReview()
    await fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menolak')
  } finally {
    acting.value = false
  }
}

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

onMounted(async () => {
  await fetchTemplates()
  await fetchData()
})
</script>

<template>
  <div class="max-w-7xl mx-auto space-y-4">
    <!-- ─── Header ─────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-body flex items-start gap-4">
        <div class="w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0">
          <ArrowLeftStartOnRectangleIcon class="w-6 h-6" />
        </div>
        <div class="flex-1 min-w-0">
          <h1 class="text-xl font-bold text-gray-900">Review Pengajuan Resignation</h1>
          <p class="text-sm text-gray-600 mt-1">
            Setujui atau tolak pengajuan resignation dari karyawan. HR final approval akan otomatis
            men-trigger generate task offboarding sesuai template.
          </p>
        </div>
      </div>
    </div>

    <!-- ─── Filter bar ──────────────────────────────────────────── -->
    <div class="card">
      <div class="card-body flex flex-wrap items-center gap-3 text-sm">
        <FunnelIcon class="w-4 h-4 text-gray-400" />
        <span class="text-gray-500">Tampilkan:</span>

        <div class="inline-flex rounded border border-gray-200 overflow-hidden">
          <button v-if="isHr" class="px-3 py-1"
            :class="filterAs === 'hr' ? 'bg-primary-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
            @click="filterAs = 'hr'; page = 1; fetchData()">
            HR (semua)
          </button>
          <button class="px-3 py-1"
            :class="filterAs === 'supervisor' ? 'bg-primary-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
            @click="filterAs = 'supervisor'; page = 1; fetchData()">
            Sebagai Atasan
          </button>
        </div>

        <select v-model="filterStatus" @change="page = 1; fetchData()"
          class="border border-gray-200 rounded px-2 py-1">
          <option value="">— Semua Status —</option>
          <option value="PENDING_SUPERVISOR">Menunggu Atasan</option>
          <option value="PENDING_HR">Menunggu HR</option>
          <option value="APPROVED">Disetujui</option>
          <option value="REJECTED">Ditolak</option>
          <option value="CANCELLED">Dibatalkan</option>
        </select>

        <button class="ml-auto text-xs text-primary-600 hover:underline" @click="fetchData">
          Refresh
        </button>
      </div>
    </div>

    <!-- ─── Table ─────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-body p-0">
        <div v-if="loading" class="p-8 text-center text-gray-400">Memuat...</div>
        <div v-else-if="items.length === 0" class="p-8 text-center text-gray-400">
          Tidak ada pengajuan
        </div>
        <table v-else class="w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th class="px-4 py-2 text-left">Karyawan</th>
              <th class="px-4 py-2 text-left">Departemen</th>
              <th class="px-4 py-2 text-left">Diajukan</th>
              <th class="px-4 py-2 text-left">LWD</th>
              <th class="px-4 py-2 text-left">Notice</th>
              <th class="px-4 py-2 text-left">Alasan</th>
              <th class="px-4 py-2 text-left">Status</th>
              <th class="px-4 py-2 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in items" :key="r.id" class="hover:bg-gray-50">
              <td class="px-4 py-2">
                <div class="font-medium">{{ r.employee_name }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ r.employee_number }}</div>
              </td>
              <td class="px-4 py-2 text-gray-600">{{ r.department_name || '—' }}</td>
              <td class="px-4 py-2">{{ fmtDate(r.created_at) }}</td>
              <td class="px-4 py-2 font-medium">{{ fmtDate(r.last_working_date) }}</td>
              <td class="px-4 py-2">
                <span :class="r.notice_period_days >= 30 ? 'text-emerald-700' : 'text-red-600'">
                  {{ r.notice_period_days }} hari
                </span>
              </td>
              <td class="px-4 py-2 text-xs text-gray-600">{{ r.reason_category }}</td>
              <td class="px-4 py-2">
                <span class="px-2 py-0.5 rounded text-xs font-medium" :class="statusBadge(r.status)">
                  {{ statusLabel(r.status) }}
                </span>
              </td>
              <td class="px-4 py-2 text-right">
                <button class="text-primary-600 hover:underline text-xs inline-flex items-center gap-1"
                  @click="openReview(r)">
                  <EyeIcon class="w-4 h-4" /> Detail
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 flex items-center justify-between">
        <span>Total {{ total }} pengajuan</span>
        <div class="flex items-center gap-1">
          <button class="px-2 py-1 rounded border border-gray-200 disabled:opacity-50"
            :disabled="page <= 1" @click="page--; fetchData()">‹</button>
          <span class="px-2">{{ page }} / {{ totalPages }}</span>
          <button class="px-2 py-1 rounded border border-gray-200 disabled:opacity-50"
            :disabled="page >= totalPages" @click="page++; fetchData()">›</button>
        </div>
      </div>
    </div>

    <!-- ─── Review modal ─────────────────────────────────────────── -->
    <div v-if="selected"
      class="fixed inset-0 z-40 bg-black/40 flex items-center justify-center p-4"
      @click.self="closeReview">
      <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-3 border-b border-gray-200 flex items-center justify-between">
          <h3 class="font-semibold inline-flex items-center gap-2">
            <UserIcon class="w-5 h-5 text-amber-600" />
            Resignation #{{ selected.id }} — {{ selected.employee_name }}
          </h3>
          <button class="text-gray-400 hover:text-gray-600" @click="closeReview">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
              <div class="text-xs text-gray-500">Tanggal Terakhir Kerja</div>
              <div class="font-medium">{{ fmtDate(selected.last_working_date) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Efektif Resign</div>
              <div class="font-medium">{{ fmtDate(selected.effective_date) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500">Notice Period</div>
              <div class="font-medium" :class="selected.notice_period_days >= 30 ? 'text-emerald-700' : 'text-red-600'">
                {{ selected.notice_period_days }} hari
              </div>
            </div>
          </div>

          <div>
            <div class="text-xs text-gray-500">Alasan</div>
            <div class="font-medium">{{ selected.reason_category }}</div>
            <div v-if="selected.reason_text" class="text-sm text-gray-700 mt-1 italic bg-gray-50 rounded p-2">
              "{{ selected.reason_text }}"
            </div>
          </div>

          <div v-if="selected.attachment_path">
            <div class="text-xs text-gray-500">Lampiran Surat Resign</div>
            <a class="text-sm text-primary-600 hover:underline"
              :href="`/api/v1/files/serve?path=${encodeURIComponent(selected.attachment_path)}`"
              target="_blank">
              {{ selected.attachment_path.split('/').pop() }}
            </a>
            <p class="text-xs text-gray-400 mt-0.5">
              (Catatan: lampiran dilihat lewat signed URL — re-fetch detail kalau link expired)
            </p>
          </div>

          <!-- Persetujuan supervisor (kalau sudah ada) -->
          <div v-if="selected.supervisor_decision" class="border rounded p-3 text-sm bg-gray-50">
            <div class="text-xs text-gray-500">
              Atasan ({{ fmtDate(selected.supervisor_action_at) }})
            </div>
            <div class="font-medium" :class="selected.supervisor_decision === 'APPROVED' ? 'text-emerald-700' : 'text-red-600'">
              {{ selected.supervisor_decision }}
            </div>
            <div v-if="selected.supervisor_note" class="text-xs italic mt-1">
              "{{ selected.supervisor_note }}"
            </div>
          </div>

          <!-- HR review (kalau sudah ada) -->
          <div v-if="selected.hr_decision" class="border rounded p-3 text-sm bg-gray-50">
            <div class="text-xs text-gray-500">HR ({{ fmtDate(selected.hr_action_at) }})</div>
            <div class="font-medium" :class="selected.hr_decision === 'APPROVED' ? 'text-emerald-700' : 'text-red-600'">
              {{ selected.hr_decision }}
            </div>
            <div v-if="selected.hr_note" class="text-xs italic mt-1">
              "{{ selected.hr_note }}"
            </div>
            <div v-if="selected.tasks_generated_count > 0" class="text-xs text-emerald-700 mt-1">
              ✓ {{ selected.tasks_generated_count }} task offboarding ter-generate
            </div>
          </div>

          <!-- ─── Offboarding Compliance (Sprint 1) ──────────────────
               Tampil saat status APPROVED. Hide kalau pengajuan belum
               disetujui HR (asset/paklaring/final-settlement tidak relevan
               sebelum resign formal). -->
          <div v-if="selected.status === 'APPROVED' && selected.offboarding"
            class="border-t border-gray-200 pt-4">
            <h4 class="font-semibold text-sm inline-flex items-center gap-2 mb-3">
              <ShieldCheckIcon class="w-5 h-5 text-emerald-600" />
              Offboarding Compliance
              <span v-if="loadingDetail" class="text-xs text-gray-400 font-normal">(memuat…)</span>
            </h4>

            <!-- Asset clearance -->
            <div class="border rounded-lg p-3 mb-3">
              <div class="flex items-start justify-between gap-3">
                <div class="flex-1">
                  <div class="font-medium text-sm flex items-center gap-1.5">
                    <component
                      :is="selected.offboarding.clearance.assets.ok ? ShieldCheckIcon : ShieldExclamationIcon"
                      :class="selected.offboarding.clearance.assets.ok ? 'w-4 h-4 text-emerald-600' : 'w-4 h-4 text-amber-600'"
                    />
                    Asset Clearance
                  </div>
                  <div v-if="selected.offboarding.clearance.assets.ok" class="text-xs text-emerald-700 mt-1">
                    ✓ Cleared
                    <span v-if="selected.offboarding.clearance.assets.returned_at">
                      {{ fmtDate(selected.offboarding.clearance.assets.returned_at) }}
                    </span>
                    <div v-if="selected.offboarding.clearance.assets.note"
                      class="text-xs italic text-gray-600 mt-0.5">
                      "{{ selected.offboarding.clearance.assets.note }}"
                    </div>
                  </div>
                  <div v-else class="text-xs text-amber-700 mt-1">
                    {{ selected.offboarding.clearance.assets.pending_count }} aset belum dikembalikan
                    <ul v-if="selected.offboarding.clearance.assets.pending_assets?.length"
                      class="list-disc ml-4 mt-1 text-gray-600">
                      <li v-for="a in selected.offboarding.clearance.assets.pending_assets" :key="a.id">
                        {{ a.name }} <span class="text-gray-400">({{ a.asset_number }}, {{ a.category }})</span>
                      </li>
                    </ul>
                  </div>
                </div>
              </div>
              <!-- Action: toggle clearance -->
              <div v-if="!selected.offboarding.clearance.assets.ok && isHr" class="mt-2 space-y-2">
                <textarea v-model="clearanceForm.note" rows="2" maxlength="500"
                  class="input text-xs"
                  placeholder="Catatan exception (wajib jika ada aset belum balik, mis. 'BAP polisi 2026/123 untuk laptop hilang')"></textarea>
                <div class="flex gap-2">
                  <button class="btn-primary text-xs px-3 py-1" @click="setAssetClearance(true)" :disabled="acting">
                    Tandai Asset Clearance OK
                  </button>
                </div>
              </div>
              <div v-else-if="selected.offboarding.clearance.assets.ok && isHr" class="mt-2">
                <button class="text-xs text-gray-500 hover:text-red-600 underline"
                  @click="setAssetClearance(false)" :disabled="acting">
                  Reset clearance (jika perlu re-verify)
                </button>
              </div>
            </div>

            <!-- Finance clearance -->
            <div class="border rounded-lg p-3 mb-3">
              <div class="font-medium text-sm flex items-center gap-1.5">
                <component
                  :is="selected.offboarding.clearance.finance.ok ? ShieldCheckIcon : ShieldExclamationIcon"
                  :class="selected.offboarding.clearance.finance.ok ? 'w-4 h-4 text-emerald-600' : 'w-4 h-4 text-amber-600'"
                />
                Finance Clearance
              </div>
              <div v-if="selected.offboarding.clearance.finance.ok"
                class="text-xs text-emerald-700 mt-1">
                ✓ Tidak ada pinjaman / kasbon outstanding
              </div>
              <div v-else class="text-xs text-amber-700 mt-1">
                {{ selected.offboarding.clearance.finance.blocker }}
              </div>
            </div>

            <!-- BPJS deactivation (OFF-6) -->
            <div class="border rounded-lg p-3 mb-3">
              <div class="font-medium text-sm flex items-center gap-1.5">
                <component
                  :is="selected.offboarding.clearance.bpjs.ok ? ShieldCheckIcon : ShieldExclamationIcon"
                  :class="selected.offboarding.clearance.bpjs.ok ? 'w-4 h-4 text-emerald-600' : 'w-4 h-4 text-amber-600'"
                />
                BPJS Deactivation
                <span class="text-xs text-gray-400 font-normal">(SLA H+7 dari resign_date)</span>
              </div>
              <div v-if="selected.offboarding.clearance.bpjs.ok"
                class="text-xs text-emerald-700 mt-1">
                ✓ Sudah di-deactivate {{ fmtDate(selected.offboarding.clearance.bpjs.deactivated_at) }}
              </div>
              <div v-else class="text-xs mt-1"
                :class="selected.offboarding.clearance.bpjs.days_overdue >= 7 ? 'text-red-700' : 'text-amber-700'">
                Belum di-deactivate.
                <span v-if="selected.offboarding.clearance.bpjs.days_overdue > 0">
                  <strong>{{ selected.offboarding.clearance.bpjs.days_overdue }} hari overdue</strong>
                  dari batas H+7 ({{ fmtDate(selected.offboarding.clearance.bpjs.sla_due) }}).
                </span>
                <span v-else-if="selected.offboarding.clearance.bpjs.sla_due">
                  Batas waktu: {{ fmtDate(selected.offboarding.clearance.bpjs.sla_due) }}.
                </span>
              </div>
              <div v-if="!selected.offboarding.clearance.bpjs.ok && isHr" class="mt-2">
                <button class="btn-primary text-xs px-3 py-1" @click="markBpjsDeactivated" :disabled="acting">
                  Tandai BPJS Sudah Di-deactivate
                </button>
                <p class="text-xs text-gray-500 mt-1">
                  Klik setelah Anda submit deactivate via e-Dabu (Kesehatan) & SiPP (Ketenagakerjaan).
                </p>
              </div>
            </div>

            <!-- Paklaring -->
            <div class="border rounded-lg p-3 mb-3">
              <div class="font-medium text-sm flex items-center gap-1.5">
                <DocumentTextIcon class="w-4 h-4 text-blue-600" />
                Paklaring (Surat Keterangan Kerja)
                <span class="text-xs text-gray-400 font-normal">UU 13/2003 Pasal 153</span>
              </div>
              <div v-if="selected.offboarding.paklaring.generated"
                class="text-xs text-emerald-700 mt-1 space-y-1">
                <div>
                  ✓ Diterbitkan
                  <span class="font-mono">{{ selected.offboarding.paklaring.contract_number }}</span>
                  pada {{ fmtDate(selected.offboarding.paklaring.generated_at) }}
                </div>
                <div class="flex gap-2">
                  <button class="text-xs text-primary-600 hover:underline inline-flex items-center gap-1"
                    @click="downloadPaklaring('pdf')">
                    <ArrowDownTrayIcon class="w-3.5 h-3.5" /> Download PDF
                  </button>
                  <button class="text-xs text-primary-600 hover:underline inline-flex items-center gap-1"
                    @click="downloadPaklaring('html')">
                    <EyeIcon class="w-3.5 h-3.5" /> Preview HTML
                  </button>
                </div>
              </div>
              <div v-else class="text-xs text-gray-600 mt-1">
                Belum diterbitkan.
                <span v-if="!selected.offboarding.ready_for_paklaring" class="text-amber-700">
                  (Pre-condition belum terpenuhi: pastikan asset clearance OK & resign_date sudah lewat)
                </span>
              </div>
              <div v-if="!selected.offboarding.paklaring.generated && isHr" class="mt-2">
                <button class="btn-primary text-xs px-3 py-1"
                  :disabled="acting || !selected.offboarding.ready_for_paklaring"
                  @click="generatePaklaring">
                  Terbitkan Paklaring
                </button>
              </div>
            </div>

            <!-- Final settlement -->
            <div class="border rounded-lg p-3">
              <div class="font-medium text-sm flex items-center gap-1.5">
                <BanknotesIcon class="w-4 h-4 text-green-600" />
                Final Settlement
                <span class="text-xs text-gray-400 font-normal">UU 13/2003 Pasal 156 — wajib H+7</span>
              </div>
              <div v-if="selected.offboarding.final_settlement.paid_at"
                class="text-xs text-emerald-700 mt-1">
                ✓ Dibayar {{ fmtDate(selected.offboarding.final_settlement.paid_at) }}:
                {{ fmtMoney(selected.offboarding.final_settlement.amount) }}
                <span v-if="selected.offboarding.final_settlement.payslip_id" class="text-gray-500">
                  (payslip #{{ selected.offboarding.final_settlement.payslip_id }})
                </span>
              </div>
              <div v-else-if="selected.offboarding.final_settlement.linked"
                class="text-xs text-amber-700 mt-1">
                Linked ke payslip #{{ selected.offboarding.final_settlement.payslip_id }} —
                {{ fmtMoney(selected.offboarding.final_settlement.amount) }}, belum di-mark paid.
              </div>
              <div v-else class="text-xs text-gray-600 mt-1">
                Belum di-link / dibayar.
                <span v-if="!selected.offboarding.ready_for_final_settlement" class="text-amber-700">
                  (Pre-condition belum terpenuhi: asset & finance clearance harus OK)
                </span>
              </div>

              <div v-if="isHr && !selected.offboarding.final_settlement.paid_at" class="mt-3 space-y-2">
                <div class="flex flex-wrap gap-2">
                  <button class="btn-outline text-xs px-3 py-1"
                    :disabled="acting || !selected.offboarding.ready_for_final_settlement"
                    @click="linkFinalSettlement">
                    <CurrencyDollarIcon class="w-3.5 h-3.5 mr-1 inline" />
                    Hitung & Link ke Payroll
                  </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-xs">
                  <div>
                    <label class="text-xs text-gray-500">Jumlah dibayar (Rp)</label>
                    <input type="number" v-model="finalSettlementForm.amount" class="input text-xs" min="0" />
                  </div>
                  <div>
                    <label class="text-xs text-gray-500">Bukti transfer (path/URL)</label>
                    <input type="text" v-model="finalSettlementForm.proof_path" class="input text-xs"
                      placeholder="opsional" />
                  </div>
                  <div>
                    <label class="text-xs text-gray-500">Catatan</label>
                    <input type="text" v-model="finalSettlementForm.note" class="input text-xs"
                      placeholder="opsional" />
                  </div>
                </div>
                <button class="btn-primary text-xs px-3 py-1" @click="markFinalSettlementPaid" :disabled="acting">
                  Tandai Sudah Dibayar
                </button>
                <p class="text-xs text-gray-500">
                  Compliance: pembayaran < 7 hari dari resign_date. Telat → audit log severity CRITICAL.
                </p>
              </div>
            </div>
          </div>

          <!-- Decision form (hanya kalau pending) -->
          <div v-if="['PENDING_SUPERVISOR', 'PENDING_HR'].includes(selected.status)"
            class="border-t border-gray-100 pt-4 space-y-3">
            <h4 class="font-semibold text-sm inline-flex items-center gap-1">
              <ChatBubbleLeftEllipsisIcon class="w-4 h-4" />
              Keputusan Anda
            </h4>

            <!-- Template offboarding pickup (HR only, di stage PENDING_HR) -->
            <div v-if="selected.status === 'PENDING_HR' && isHr">
              <label class="label">Template Offboarding</label>
              <select v-model="decision.offboarding_template_id" class="input">
                <option value="">— Default (sistem pilih otomatis) —</option>
                <option v-for="t in offboardingTemplates" :key="t.id" :value="t.id">
                  {{ t.name }} <span v-if="t.is_default">⭐</span>
                </option>
              </select>
              <p class="text-xs text-gray-500 mt-1">
                Daftar task yang akan ter-generate (return aset, clearance, exit interview, dll)
                tergantung template ini. Lihat menu Master Data → Template Onboarding.
              </p>
            </div>

            <div>
              <label class="label">
                Catatan
                <span class="text-gray-500 font-normal text-xs">
                  (wajib jika menolak, opsional jika menyetujui)
                </span>
              </label>
              <textarea v-model="decision.note" rows="3" class="input"
                maxlength="2000"
                placeholder="Catatan untuk karyawan..."></textarea>
            </div>

            <div v-if="selected.status === 'PENDING_HR'"
              class="bg-amber-50 border border-amber-200 rounded p-2 text-xs text-amber-900 flex items-start gap-1">
              <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0 mt-0.5" />
              <div>
                Setelah <strong>Approve</strong>, sistem akan: (1) set <code>employees.resign_date</code>;
                (2) generate task offboarding; (3) notify karyawan, IT, dan Finance.
                Tindakan ini hanya bisa dibatalkan dengan akses HR khusus.
              </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
              <button class="btn-outline" @click="closeReview" :disabled="acting">Tutup</button>
              <button class="btn-outline-danger" @click="reject(selected)" :disabled="acting">
                <XMarkIcon class="w-4 h-4 mr-1 inline" /> Tolak
              </button>
              <button class="btn-primary" @click="approve(selected)" :disabled="acting">
                <CheckCircleIcon class="w-4 h-4 mr-1 inline" />
                {{ acting ? 'Memproses...' : 'Setujui' }}
              </button>
            </div>
          </div>

          <div v-else class="border-t border-gray-100 pt-3 text-xs text-gray-500">
            Pengajuan ini sudah dalam status final ({{ statusLabel(selected.status) }}).
            Tidak ada aksi yang tersedia.
          </div>
        </div>
      </div>
    </div>
  </div>
</template>