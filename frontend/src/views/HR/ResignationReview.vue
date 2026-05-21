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
const selected = ref(null)   // request yang dibuka di modal review
const decision = ref({ note: '', offboarding_template_id: '' })
const acting = ref(false)

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

function openReview(req) {
  selected.value = req
  decision.value = { note: '', offboarding_template_id: '' }
}

function closeReview() {
  selected.value = null
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