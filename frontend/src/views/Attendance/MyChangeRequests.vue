<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtDate, fmtDateTime } from '@/composables/useResource'
import { confirm as confirmDialog } from '@/composables/useConfirm'
import { useI18n } from '@/locales'
import { computed } from 'vue'
import {
  EyeIcon, ArrowPathIcon, XMarkIcon, DocumentTextIcon,
  ArrowDownTrayIcon, TrashIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()
const { t } = useI18n()
const items = ref([])
const meta = ref({ total: 0, page: 1, per_page: 20, pages: 1 })
const loading = ref(false)
const detail = ref(null)
const detailLoadingId = ref(null)
const documentUrl = ref('')
const documentType = ref('')
const documentLoading = ref(false)
const documentError = ref('')
const filters = ref({ status: '', start_date: '', end_date: '' })

async function fetchData(page = 1) {
  loading.value = true
  try {
    const res = await api.get('/attendance/change-requests', {
      params: { mine: 1, page, per_page: 20, ...filters.value },
    })
    items.value = res.data.data
    meta.value = res.data.meta
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.change_list.load_failed'))
  } finally {
    loading.value = false
  }
}

function clearDocumentPreview() {
  if (documentUrl.value) URL.revokeObjectURL(documentUrl.value)
  documentUrl.value = ''
  documentType.value = ''
  documentError.value = ''
}

function closeDetail() {
  detail.value = null
  clearDocumentPreview()
}

async function loadDocument(url) {
  if (!url) return
  clearDocumentPreview()
  documentLoading.value = true

  try {
    // `api` sudah memiliki baseURL `/api/v1`; hindari hasil
    // `/api/v1/api/v1/files/serve` dari signed URL backend.
    const apiRelativeUrl = url.replace(/^\/api\/v1\//, '/')
    const res = await api.get(apiRelativeUrl, {
      responseType: 'blob',
    })
    documentType.value = res.data.type || res.headers['content-type'] || ''
    documentUrl.value = URL.createObjectURL(res.data)
  } catch (e) {
    documentError.value = e.response?.data?.message || 'Dokumen tidak dapat dimuat'
    toast.error(documentError.value)
  } finally {
    documentLoading.value = false
  }
}

async function openDetail(row) {
  detailLoadingId.value = row.id
  clearDocumentPreview()

  try {
    const res = await api.get(`/attendance/change-requests/${row.id}`)
    detail.value = res.data.data
    if (detail.value?.attachment_url) {
      await loadDocument(detail.value.attachment_url)
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat detail pengajuan')
  } finally {
    detailLoadingId.value = null
  }
}

function downloadDocument() {
  if (!documentUrl.value || !detail.value?.attachment_path) return
  const link = document.createElement('a')
  link.href = documentUrl.value
  link.download = detail.value.attachment_path.split('/').pop() || 'lampiran'
  document.body.appendChild(link)
  link.click()
  link.remove()
}

const isImageDocument = computed(() => documentType.value.startsWith('image/'))
const isPdfDocument = computed(() => documentType.value === 'application/pdf')

onBeforeUnmount(clearDocumentPreview)

function displayDateTime(value) {
  if (!value) return '—'
  return fmtDateTime(value)
}

function displayTime(value) {
  if (!value) return '—'
  const match = String(value).match(/(\d{2}:\d{2})/)
  return match?.[1] || value
}

function decisionBadge(status) {
  const map = {
    APPROVED: 'bg-emerald-100 text-emerald-700',
    REJECTED: 'bg-red-100 text-red-700',
    SKIPPED: 'bg-gray-100 text-gray-600',
    DELEGATED: 'bg-blue-100 text-blue-700',
    PENDING: 'bg-amber-100 text-amber-700',
    WAITING_APPROVAL: 'bg-amber-100 text-amber-700',
  }
  return map[status] || 'bg-gray-100 text-gray-600'
}

async function cancelRequest(row) {
  const ok = await confirmDialog({
    title: t('attendance.change_list.cancel_title'),
    message: t('attendance.change_list.cancel_message', { number: row.request_number }),
    okText: t('attendance.change_list.cancel_ok'),
    variant: 'danger',
  })
  if (! ok) return
  try {
    await api.post(`/attendance/change-requests/${row.id}/cancel`)
    toast.success(t('attendance.change_list.cancelled'))
    fetchData(meta.value.page)
  } catch (e) {
    toast.error(e.response?.data?.message || t('attendance.change_list.cancel_failed'))
  }
}

const statusBadge = {
  PENDING:   'bg-amber-100 text-amber-800',
  APPROVED:  'bg-green-100 text-green-800',
  REJECTED:  'bg-red-100 text-red-800',
  CANCELLED: 'bg-gray-100 text-gray-700',
}

const typeLabel = computed(() => ({
  CLOCK_IN: t('attendance.change_list.type_clock_in'),
  CLOCK_OUT: t('attendance.change_list.type_clock_out'),
  BOTH: t('attendance.change_list.type_both'),
  STATUS: t('attendance.change_list.type_status'),
  MISSED: t('attendance.change_list.type_missed'),
}))

onMounted(() => fetchData())
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('attendance.change_list.title') }}</h1>
        <p class="text-sm text-gray-500">{{ t('attendance.change_list.subtitle') }}</p>
      </div>
      <RouterLink to="/attendance/my" class="btn-secondary">{{ t('attendance.change_list.back_to_calendar') }}</RouterLink>
    </div>

    <div class="card">
      <div class="card-header gap-3 flex-wrap">
        <select v-model="filters.status" class="input w-44">
          <option value="">{{ t('attendance.change_list.all_status') }}</option>
          <option value="PENDING">{{ t('common.pending') }}</option>
          <option value="APPROVED">{{ t('common.approved') }}</option>
          <option value="REJECTED">{{ t('common.rejected') }}</option>
          <option value="CANCELLED">{{ t('common.cancelled') }}</option>
        </select>
        <AppDatePicker v-model="filters.start_date"  class="input w-40" />
        <AppDatePicker v-model="filters.end_date"  class="input w-40" />
        <button class="btn-primary" @click="fetchData(1)" :disabled="loading">{{ t('common.filter') }}</button>
      </div>

      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('attendance.change_list.col_number') }}</th>
              <th>{{ t('attendance.change_list.col_work_date') }}</th>
              <th>{{ t('attendance.change_list.col_type') }}</th>
              <th>{{ t('attendance.change_list.col_request') }}</th>
              <th>{{ t('attendance.change_list.col_reason') }}</th>
              <th>{{ t('attendance.change_list.col_status') }}</th>
              <th>{{ t('attendance.change_list.col_reviewer') }}</th>
              <th>{{ t('attendance.change_list.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="!loading && items.length === 0">
              <td colspan="8" class="text-center py-8 text-gray-400">{{ t('attendance.change_list.no_requests') }}</td>
            </tr>
            <tr v-for="row in items" :key="row.id">
              <td class="font-mono text-xs">{{ row.request_number }}</td>
              <td>{{ fmtDate(row.work_date) }}</td>
              <td>{{ typeLabel[row.change_type] || row.change_type }}</td>
              <td class="text-xs">
                <div v-if="row.requested_clock_in">{{ t('attendance.change_list.req_in', { time: row.requested_clock_in.slice(11, 16) }) }}</div>
                <div v-if="row.requested_clock_out">{{ t('attendance.change_list.req_out', { time: row.requested_clock_out.slice(11, 16) }) }}</div>
                <div v-if="row.requested_status">{{ t('attendance.change_list.req_status', { status: row.requested_status }) }}</div>
              </td>
              <td class="max-w-xs truncate" :title="row.reason">{{ row.reason }}</td>
              <td>
                <span :class="['px-2 py-0.5 rounded text-xs font-medium', statusBadge[row.status]]">
                  {{ row.status }}
                </span>
                <div v-if="row.review_note" class="text-xs text-gray-500 mt-1">{{ row.review_note }}</div>
              </td>
              <td class="text-xs">
                <div v-if="row.reviewer_name">{{ row.reviewer_name }}</div>
                <div v-if="row.reviewed_at" class="text-gray-500">{{ fmtDateTime(row.reviewed_at) }}</div>
              </td>
              <td>
                <div class="flex items-center gap-2">
                  <button
                    class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm transition hover:border-blue-300 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:ring-offset-1 disabled:cursor-wait disabled:opacity-60"
                    :disabled="detailLoadingId === row.id" @click="openDetail(row)">
                    <ArrowPathIcon v-if="detailLoadingId === row.id" class="w-4 h-4 animate-spin" />
                    <EyeIcon v-else class="w-4 h-4" />
                    {{ detailLoadingId === row.id ? 'Memuat...' : 'Detail' }}
                  </button>
                  <button v-if="row.status === 'PENDING'"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 shadow-sm transition hover:border-red-300 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-1"
                    @click="cancelRequest(row)">
                    <TrashIcon class="w-4 h-4" />
                    {{ t('attendance.change_list.cancel') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="meta.pages > 1" class="px-4 py-3 flex items-center justify-between border-t border-gray-100 text-sm">
        <span class="text-gray-500">{{ t('attendance.change_list.total', { n: meta.total }) }}</span>
        <div class="flex gap-1">
          <button class="btn-secondary" :disabled="meta.page <= 1" @click="fetchData(meta.page - 1)">‹</button>
          <span class="px-3 py-1">{{ t('attendance.change_list.page_of', { page: meta.page, pages: meta.pages }) }}</span>
          <button class="btn-secondary" :disabled="meta.page >= meta.pages" @click="fetchData(meta.page + 1)">›</button>
        </div>
      </div>
    </div>

    <!-- Detail pengajuan dan timeline approval -->
    <div v-if="detail" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm"
      @click.self="closeDetail">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div class="sticky top-0 z-10 bg-white px-5 py-4 border-b flex items-start justify-between">
          <div>
            <h2 class="font-semibold text-lg">Detail Perubahan Absensi</h2>
            <p class="text-xs text-gray-500 font-mono">{{ detail.request_number }}</p>
          </div>
          <button
            class="rounded-lg border border-gray-200 bg-white p-2 text-gray-500 shadow-sm transition hover:bg-gray-100 hover:text-gray-800"
            title="Tutup" @click="closeDetail">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-5">
          <section>
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-semibold text-sm">Informasi Pengajuan</h3>
              <span :class="['px-2 py-0.5 rounded text-xs font-medium', statusBadge[detail.status]]">
                {{ detail.status }}
              </span>
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm bg-gray-50 rounded-lg p-4">
              <div>
                <dt class="text-xs text-gray-500">Karyawan</dt>
                <dd class="font-medium">{{ detail.employee_name || '—' }}</dd>
                <dd class="text-xs text-gray-500">{{ detail.employee_number || '—' }}</dd>
              </div>
              <div>
                <dt class="text-xs text-gray-500">Tanggal Kerja</dt>
                <dd class="font-medium">{{ fmtDate(detail.work_date) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-gray-500">Jenis Perubahan</dt>
                <dd>{{ typeLabel[detail.change_type] || detail.change_type }}</dd>
              </div>
              <div>
                <dt class="text-xs text-gray-500">Diajukan</dt>
                <dd>{{ displayDateTime(detail.created_at) }}</dd>
              </div>
              <div class="sm:col-span-2">
                <dt class="text-xs text-gray-500">Alasan</dt>
                <dd class="whitespace-pre-line">{{ detail.reason || '—' }}</dd>
              </div>
            </dl>
          </section>

          <section v-if="detail.attachment_path">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 class="flex items-center gap-2 font-semibold text-sm">
                  <DocumentTextIcon class="h-5 w-5 text-blue-600" />
                  Dokumen Pendukung
                </h3>
                <p class="mt-0.5 max-w-md truncate text-xs text-gray-500"
                  :title="detail.attachment_path">
                  {{ detail.attachment_path.split('/').pop() }}
                </p>
              </div>
              <button v-if="documentUrl"
                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                @click="downloadDocument">
                <ArrowDownTrayIcon class="h-4 w-4" />
                Unduh Dokumen
              </button>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-slate-100 shadow-inner">
              <div v-if="documentLoading"
                class="flex min-h-64 flex-col items-center justify-center gap-3 text-sm text-gray-500">
                <ArrowPathIcon class="h-7 w-7 animate-spin text-blue-600" />
                Memuat dokumen...
              </div>

              <div v-else-if="documentError"
                class="flex min-h-48 flex-col items-center justify-center gap-2 p-6 text-center">
                <DocumentTextIcon class="h-10 w-10 text-red-300" />
                <p class="text-sm font-medium text-red-600">{{ documentError }}</p>
                <button class="btn-secondary text-xs" @click="loadDocument(detail.attachment_url)">
                  Coba Lagi
                </button>
              </div>

              <img v-else-if="documentUrl && isImageDocument"
                :src="documentUrl"
                class="mx-auto max-h-[560px] w-auto max-w-full object-contain p-3"
                alt="Preview dokumen pendukung" />

              <iframe v-else-if="documentUrl && isPdfDocument"
                :src="documentUrl"
                class="h-[560px] w-full bg-white"
                title="Preview dokumen PDF">
              </iframe>

              <div v-else-if="documentUrl"
                class="flex min-h-48 flex-col items-center justify-center gap-3 p-6 text-center">
                <DocumentTextIcon class="h-12 w-12 text-blue-400" />
                <div>
                  <p class="text-sm font-semibold text-gray-700">Preview tidak tersedia untuk format ini</p>
                  <p class="mt-1 text-xs text-gray-500">Gunakan tombol Unduh Dokumen untuk membukanya.</p>
                </div>
              </div>
            </div>
          </section>

          <section>
            <h3 class="font-semibold text-sm mb-3">Perubahan yang Diajukan</h3>
            <div class="overflow-x-auto border rounded-lg">
              <table class="table text-sm">
                <thead>
                  <tr>
                    <th>Field</th>
                    <th>Sebelum</th>
                    <th>Pengajuan</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="['CLOCK_IN', 'BOTH', 'MISSED'].includes(detail.change_type)">
                    <td>Jam Masuk</td>
                    <td>{{ displayTime(detail.current_clock_in) }}</td>
                    <td class="font-medium text-blue-700">{{ displayTime(detail.requested_clock_in) }}</td>
                  </tr>
                  <tr v-if="['CLOCK_OUT', 'BOTH', 'MISSED'].includes(detail.change_type)">
                    <td>Jam Keluar</td>
                    <td>{{ displayTime(detail.current_clock_out) }}</td>
                    <td class="font-medium text-blue-700">{{ displayTime(detail.requested_clock_out) }}</td>
                  </tr>
                  <tr v-if="detail.change_type === 'STATUS'">
                    <td>Status Kehadiran</td>
                    <td>{{ detail.current_status || '—' }}</td>
                    <td class="font-medium text-blue-700">{{ detail.requested_status || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section v-if="detail.review_note || detail.reviewer_name">
            <h3 class="font-semibold text-sm mb-3">Hasil Review</h3>
            <div class="bg-gray-50 rounded-lg p-4 text-sm">
              <div>
                {{ detail.reviewer_name || 'Reviewer' }}
                <span v-if="detail.reviewed_at" class="text-gray-500">
                  · {{ displayDateTime(detail.reviewed_at) }}
                </span>
              </div>
              <div v-if="detail.review_note" class="mt-2 whitespace-pre-line">{{ detail.review_note }}</div>
            </div>
          </section>

          <section>
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-semibold text-sm">Tahapan Approval</h3>
              <span v-if="detail.approval" class="px-2 py-0.5 rounded text-xs"
                :class="decisionBadge(detail.approval.status)">
                {{ detail.approval.status }}
              </span>
            </div>

            <div v-if="!detail.approval" class="border border-dashed rounded-lg p-4 text-sm text-gray-500">
              Workflow approval belum tersedia untuk pengajuan ini.
            </div>

            <div v-else class="space-y-3">
              <div class="bg-blue-50 border border-blue-100 rounded-lg p-3 text-sm">
                <div><span class="text-gray-500">Approval chain:</span> {{ detail.approval.chain_name || 'Default' }}</div>
                <div class="text-xs text-gray-500 mt-1">
                  Request #{{ detail.approval.id }} · dibuat {{ displayDateTime(detail.approval.created_at) }}
                </div>
              </div>

              <div v-if="!detail.approval.timeline?.length"
                class="border border-dashed rounded-lg p-4 text-sm text-gray-500">
                Belum ada tahapan approval yang dikonfigurasi.
              </div>

              <ol v-else class="relative border-l-2 border-gray-200 ml-3 space-y-4">
                <li v-for="step in detail.approval.timeline"
                  :key="step.step_id ?? `fallback-${step.step_order}`"
                  class="pl-5 relative" :class="{ 'opacity-60': step.status === 'SKIPPED' }">
                  <span class="absolute -left-[7px] top-1 w-3 h-3 rounded-full border-2 border-white"
                    :class="step.status === 'APPROVED'
                      ? 'bg-emerald-500'
                      : step.status === 'REJECTED'
                        ? 'bg-red-500'
                        : step.status === 'SKIPPED'
                          ? 'bg-gray-400'
                          : 'bg-amber-400'">
                  </span>

                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <div class="font-medium text-sm">
                        {{ step.step_name || `Tahap ${step.step_order}` }}
                        <span v-if="step.is_current" class="ml-1 text-xs text-amber-700">(tahap aktif)</span>
                      </div>
                      <div class="text-xs text-gray-500">
                        {{ step.approver_user_name
                          || step.expected_approver_names?.join(', ')
                          || step.approver_type
                          || 'PIC belum ditentukan' }}
                      </div>
                      <div v-if="step.condition_field && step.condition_matched"
                        class="text-xs text-blue-600 mt-0.5">
                        Rule: {{ step.condition_field }} {{ step.condition_op }} {{ step.condition_value }}
                      </div>
                    </div>
                    <span class="px-2 py-0.5 rounded text-xs whitespace-nowrap"
                      :class="decisionBadge(step.status)">
                      {{ step.status === 'WAITING_APPROVAL' ? 'WAITING APPROVAL' : step.status }}
                    </span>
                  </div>

                  <div v-if="step.decided_at" class="text-xs text-gray-500 mt-1">
                    {{ displayDateTime(step.decided_at) }}
                  </div>
                  <div v-if="step.note" class="mt-1 text-xs bg-gray-50 rounded p-2 whitespace-pre-line">
                    {{ step.note }}
                  </div>
                </li>
              </ol>
            </div>
          </section>
        </div>

        <div class="sticky bottom-0 flex justify-end gap-2 border-t bg-white px-5 py-3">
          <button v-if="documentUrl"
            class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 transition hover:bg-blue-100"
            @click="downloadDocument">
            <ArrowDownTrayIcon class="h-4 w-4" />
            Unduh Lampiran
          </button>
          <button
            class="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
            @click="closeDetail">
            <XMarkIcon class="h-4 w-4" />
            Tutup
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
