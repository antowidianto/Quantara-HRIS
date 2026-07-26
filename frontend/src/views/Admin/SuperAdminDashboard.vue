<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import {
  ArrowPathIcon,
  BellAlertIcon,
  BuildingOffice2Icon,
  CheckCircleIcon,
  ChevronRightIcon,
  CircleStackIcon,
  ClockIcon,
  CloudIcon,
  Cog6ToothIcon,
  CpuChipIcon,
  CurrencyDollarIcon,
  EnvelopeIcon,
  ExclamationTriangleIcon,
  FingerPrintIcon,
  ServerStackIcon,
  ShieldCheckIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'

const auth = useAuthStore()
const router = useRouter()
const toast = useToast()
const data = ref(null)
const loading = ref(true)
const refreshing = ref(false)
const autoRefresh = ref(true)
const errorMessage = ref('')
let refreshTimer = null

const overallTone = computed(() => {
  const status = data.value?.status || 'UNKNOWN'
  if (status === 'HEALTHY') return 'bg-emerald-50 text-emerald-700 ring-emerald-200'
  if (status === 'DEGRADED') return 'bg-amber-50 text-amber-700 ring-amber-200'
  return 'bg-rose-50 text-rose-700 ring-rose-200'
})

const criticalAlerts = computed(() =>
  (data.value?.alerts || []).filter(item => item.severity === 'CRITICAL').length
)

const quickLinks = [
  { label: 'Tenant Console', hint: 'Kelola dan inspeksi tenant', to: '/admin/tenants', icon: BuildingOffice2Icon },
  { label: 'SaaS Metrics', hint: 'MRR, ARR, churn dan funnel', to: '/admin/saas-metrics', icon: CurrencyDollarIcon },
  { label: 'Customer Health', hint: 'Tenant berisiko dan adoption', to: '/admin/customer-health', icon: UserGroupIcon },
  { label: 'Audit & Security', hint: 'Aktivitas kritis dan akses', to: '/admin/audit-logs', icon: FingerPrintIcon },
  { label: 'Approval Inbox', hint: 'Workflow yang perlu keputusan', to: '/approvals/inbox', icon: ShieldCheckIcon },
  { label: 'Email Operations', hint: 'Konfigurasi dan antrean email', to: '/admin/email-settings', icon: EnvelopeIcon },
]

const dependencyIcons = {
  database: CircleStackIcon,
  cache: CpuChipIcon,
  queue: ServerStackIcon,
  storage: CloudIcon,
  subscriptions: BuildingOffice2Icon,
}

const queueCards = computed(() => {
  const queues = data.value?.queues || {}
  return [
    {
      key: 'jobs',
      label: 'Background Jobs',
      icon: Cog6ToothIcon,
      waiting: (queues.jobs?.PENDING || 0) + (queues.jobs?.RUNNING || 0),
      failed: queues.jobs?.FAILED || 0,
      success: queues.jobs?.DONE || 0,
    },
    {
      key: 'business_jobs',
      label: 'Business Jobs',
      icon: CpuChipIcon,
      waiting: queues.business_jobs?.RUNNING || 0,
      failed: (queues.business_jobs?.FAILED || 0) + (queues.business_jobs?.STUCK || 0),
      success: queues.business_jobs?.SUCCESS || 0,
    },
    {
      key: 'email',
      label: 'Email Delivery',
      icon: EnvelopeIcon,
      waiting: (queues.email?.QUEUED || 0) + (queues.email?.SENDING || 0),
      failed: queues.email?.FAILED || 0,
      success: queues.email?.SENT || 0,
    },
    {
      key: 'webhooks',
      label: 'Webhook Delivery',
      icon: CloudIcon,
      waiting: (queues.webhooks?.QUEUED || 0) + (queues.webhooks?.SENDING || 0),
      failed: queues.webhooks?.FAILED || 0,
      success: queues.webhooks?.DELIVERED || 0,
    },
    {
      key: 'approval_outbox',
      label: 'Approval Outbox',
      icon: ShieldCheckIcon,
      waiting: (queues.approval_outbox?.PENDING || 0) + (queues.approval_outbox?.PROCESSING || 0),
      failed: queues.approval_outbox?.FAILED || 0,
      success: queues.approval_outbox?.PUBLISHED || 0,
    },
  ]
})

const businessFlowCards = computed(() => {
  const flows = data.value?.business_flows || {}
  return [
    {
      label: 'Absensi & Waktu',
      to: '/attendance',
      metrics: [
        ['Koreksi', flows.attendance?.changes_pending || 0],
        ['WFH', flows.attendance?.wfh_pending || 0],
        ['Lembur', flows.attendance?.overtime_pending || 0],
      ],
    },
    {
      label: 'Cuti',
      to: '/leaves',
      metrics: [['Menunggu', flows.leave?.pending || 0]],
    },
    {
      label: 'Rekrutmen',
      to: '/recruitment/jobs',
      metrics: [
        ['Lowongan aktif', flows.recruitment?.open_jobs || 0],
        ['Kandidat aktif', flows.recruitment?.active_candidates || 0],
        ['Offer berjalan', flows.recruitment?.offers_pending || 0],
      ],
    },
    {
      label: 'Siklus Karyawan',
      to: '/employees',
      metrics: [
        ['Onboarding', flows.employee_lifecycle?.onboarding_active || 0],
        ['Resignasi', flows.employee_lifecycle?.resignations_pending || 0],
        ['Probation ≤30 hari', flows.employee_lifecycle?.probation_due_30d || 0],
      ],
    },
    {
      label: 'HR Helpdesk',
      to: '/helpdesk/tickets',
      metrics: [
        ['Tiket terbuka', flows.service?.tickets_open || 0],
        ['Lewat SLA', flows.service?.tickets_overdue || 0],
      ],
    },
  ]
})

async function load(showSpinner = false) {
  if (showSpinner) refreshing.value = true
  else loading.value = true
  try {
    const response = await api.get('/admin/operations/overview')
    data.value = response.data?.data || null
    errorMessage.value = ''
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Gagal memuat Super Admin Command Center'
    toast.error(errorMessage.value)
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

function scheduleRefresh() {
  if (refreshTimer) clearInterval(refreshTimer)
  refreshTimer = null
  if (autoRefresh.value) refreshTimer = setInterval(() => load(true), 30000)
}

function toggleAutoRefresh() {
  autoRefresh.value = !autoRefresh.value
  scheduleRefresh()
}

function go(link) {
  if (link) router.push(link)
}

function number(value) {
  return Number(value || 0).toLocaleString('id-ID')
}

function relativeTime(value) {
  if (! value) return '-'
  const diff = Math.max(0, Date.now() - new Date(value).getTime())
  const minutes = Math.floor(diff / 60000)
  if (minutes < 1) return 'baru saja'
  if (minutes < 60) return `${minutes} menit lalu`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours} jam lalu`
  return `${Math.floor(hours / 24)} hari lalu`
}

function dependencyDetail(check) {
  if (check?.error) return check.error
  const detail = check?.detail
  if (! detail) return check?.ok ? 'Beroperasi normal' : 'Tidak tersedia'
  if (typeof detail === 'string') return detail
  if (detail.used_pct !== undefined && detail.used_pct !== null) return `Disk terpakai ${detail.used_pct}%`
  if (detail.pending !== undefined) return `${detail.pending} pending · ${detail.failed || 0} gagal`
  if (detail.active !== undefined) return `${detail.active} aktif · ${detail.past_due || 0} past due`
  return check?.ok ? 'Beroperasi normal' : 'Perlu pemeriksaan'
}

onMounted(() => {
  if (! auth.isSuperAdmin) {
    router.replace('/dashboard')
    return
  }
  load()
  scheduleRefresh()
})

onUnmounted(() => {
  if (refreshTimer) clearInterval(refreshTimer)
})
</script>

<template>
  <div class="mx-auto max-w-[1600px] space-y-6">
    <div class="overflow-hidden rounded-2xl bg-slate-950 text-white shadow-xl">
      <div class="relative px-6 py-6 lg:px-8">
        <div class="absolute inset-0 opacity-30"
          style="background-image: radial-gradient(circle at 85% 20%, #2563eb 0, transparent 28%), radial-gradient(circle at 65% 90%, #0f766e 0, transparent 25%);"></div>
        <div class="relative flex flex-col justify-between gap-5 lg:flex-row lg:items-center">
          <div>
            <div class="mb-2 flex flex-wrap items-center gap-2">
              <span class="rounded-full bg-blue-500/15 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-blue-200 ring-1 ring-blue-400/30">
                Platform Command Center
              </span>
              <span v-if="data" :class="overallTone"
                class="rounded-full px-2.5 py-1 text-[11px] font-bold ring-1">
                {{ data.status }}
              </span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight lg:text-3xl">Super Admin Operations</h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-300">
              Satu pandangan untuk kesehatan platform, risiko tenant, workflow, payroll, keamanan, dan proses bisnis HRIS.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button type="button"
              class="rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-xs font-medium text-slate-200 hover:bg-white/10"
              @click="toggleAutoRefresh">
              Auto refresh {{ autoRefresh ? 'ON' : 'OFF' }}
            </button>
            <button type="button"
              class="inline-flex items-center gap-2 rounded-lg bg-white px-3.5 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-100 disabled:opacity-60"
              :disabled="refreshing" @click="load(true)">
              <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': refreshing }" />
              Refresh
            </button>
          </div>
        </div>
        <div v-if="data" class="relative mt-5 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-400">
          <span>Snapshot {{ relativeTime(data.snapshot_at) }}</span>
          <span>Interval rekomendasi {{ data.refresh_after_seconds }} detik</span>
          <span>{{ data.alerts?.length || 0 }} alert aktif · {{ criticalAlerts }} kritikal</span>
        </div>
      </div>
    </div>

    <div v-if="errorMessage"
      class="flex flex-col gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <span class="font-semibold">Snapshot gagal diperbarui.</span>
        {{ errorMessage }}
        <span v-if="data" class="text-rose-600">Data terakhir tetap ditampilkan.</span>
      </div>
      <button type="button" class="shrink-0 font-semibold text-rose-700 hover:underline" @click="load(true)">
        Coba lagi
      </button>
    </div>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <div v-for="i in 8" :key="i" class="h-28 animate-pulse rounded-xl border bg-white p-5">
        <div class="h-3 w-24 rounded bg-slate-200"></div>
        <div class="mt-5 h-8 w-16 rounded bg-slate-200"></div>
      </div>
    </div>

    <template v-else-if="data">
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tenant aktif</span>
            <BuildingOffice2Icon class="h-5 w-5 text-blue-600" />
          </div>
          <div class="mt-3 text-3xl font-bold text-slate-900">{{ number(data.tenants.active) }}</div>
          <div class="mt-1 text-xs text-slate-500">
            {{ number(data.tenants.total) }} total · {{ number(data.tenants.trial) }} trial
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Karyawan aktif</span>
            <UserGroupIcon class="h-5 w-5 text-violet-600" />
          </div>
          <div class="mt-3 text-3xl font-bold text-slate-900">{{ number(data.tenants.employees_active) }}</div>
          <div class="mt-1 text-xs text-slate-500">{{ number(data.tenants.users_active) }} user aktif</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Approval menunggu</span>
            <ClockIcon class="h-5 w-5 text-amber-600" />
          </div>
          <div class="mt-3 text-3xl font-bold text-slate-900">{{ number(data.workflows.pending) }}</div>
          <div class="mt-1 text-xs" :class="data.workflows.stuck_24h ? 'text-rose-600' : 'text-slate-500'">
            {{ number(data.workflows.stuck_24h) }} tertahan &gt;24 jam
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Payroll berjalan</span>
            <CurrencyDollarIcon class="h-5 w-5 text-emerald-600" />
          </div>
          <div class="mt-3 text-3xl font-bold text-slate-900">{{ number(data.payroll.in_process) }}</div>
          <div class="mt-1 text-xs" :class="data.payroll.failed ? 'text-rose-600' : 'text-slate-500'">
            {{ number(data.payroll.failed) }} gagal · {{ number(data.payroll.pending_approval) }} approval
          </div>
        </div>
      </div>

      <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
              <h2 class="font-semibold text-slate-900">Active Alerts</h2>
              <p class="text-xs text-slate-500">Kondisi yang membutuhkan perhatian operasional.</p>
            </div>
            <BellAlertIcon class="h-5 w-5 text-slate-400" />
          </div>
          <div v-if="!data.alerts?.length" class="flex items-center gap-3 px-5 py-8 text-sm text-emerald-700">
            <CheckCircleIcon class="h-6 w-6" />
            Tidak ada alert aktif. Semua indikator berada dalam batas normal.
          </div>
          <div v-else class="divide-y divide-slate-100">
            <button v-for="(alert, index) in data.alerts" :key="`${alert.title}-${index}`"
              type="button" class="flex w-full items-start gap-3 px-5 py-3.5 text-left hover:bg-slate-50"
              @click="go(alert.link)">
              <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0"
                :class="alert.severity === 'CRITICAL' ? 'text-rose-600' : 'text-amber-500'" />
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-semibold text-slate-800">{{ alert.title }}</span>
                  <span class="rounded px-1.5 py-0.5 text-[10px] font-bold"
                    :class="alert.severity === 'CRITICAL' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'">
                    {{ alert.severity }}
                  </span>
                </div>
                <p class="mt-0.5 text-xs text-slate-500">{{ alert.detail }}</p>
              </div>
              <ChevronRightIcon class="mt-1 h-4 w-4 text-slate-400" />
            </button>
          </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="font-semibold text-slate-900">Platform Dependencies</h2>
            <p class="text-xs text-slate-500">Health check dependency dan latensi respons.</p>
          </div>
          <div class="divide-y divide-slate-100">
            <div v-for="(check, key) in data.health" :key="key" class="flex items-center gap-3 px-5 py-3">
              <div class="rounded-lg p-2" :class="check.ok ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'">
                <component :is="dependencyIcons[key] || ServerStackIcon" class="h-5 w-5" />
              </div>
              <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-sm font-semibold capitalize text-slate-800">{{ key }}</span>
                  <span class="text-xs text-slate-400">{{ check.duration_ms }} ms</span>
                </div>
                <p class="truncate text-xs text-slate-500">{{ dependencyDetail(check) }}</p>
              </div>
              <span class="h-2.5 w-2.5 rounded-full" :class="check.ok ? 'bg-emerald-500' : 'bg-rose-500'"></span>
            </div>
          </div>
        </section>
      </div>

      <section>
        <div class="mb-3">
          <h2 class="font-semibold text-slate-900">Operational Pipelines</h2>
          <p class="text-xs text-slate-500">Antrean dan proses asynchronous lintas tenant.</p>
        </div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          <div v-for="queue in queueCards" :key="queue.key"
            class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
              <component :is="queue.icon" class="h-5 w-5 text-slate-500" />
              <span v-if="queue.failed" class="rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-700">
                ACTION
              </span>
              <span v-else class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">
                NORMAL
              </span>
            </div>
            <h3 class="mt-3 text-sm font-semibold text-slate-800">{{ queue.label }}</h3>
            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
              <div><div class="text-lg font-bold text-amber-600">{{ number(queue.waiting) }}</div><div class="text-[10px] text-slate-400">WAIT</div></div>
              <div><div class="text-lg font-bold text-rose-600">{{ number(queue.failed) }}</div><div class="text-[10px] text-slate-400">FAIL</div></div>
              <div><div class="text-lg font-bold text-emerald-600">{{ number(queue.success) }}</div><div class="text-[10px] text-slate-400">DONE</div></div>
            </div>
          </div>
        </div>
      </section>

      <div class="grid gap-6 xl:grid-cols-[1fr_1fr]">
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="font-semibold text-slate-900">Business Flow Monitor</h2>
            <p class="text-xs text-slate-500">Volume proses aktif yang memerlukan tindak lanjut.</p>
          </div>
          <div class="grid gap-3 p-4 sm:grid-cols-2">
            <button v-for="flow in businessFlowCards" :key="flow.label" type="button"
              class="rounded-lg border border-slate-200 p-4 text-left transition hover:border-blue-300 hover:bg-blue-50/30"
              @click="go(flow.to)">
              <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-slate-800">{{ flow.label }}</span>
                <ChevronRightIcon class="h-4 w-4 text-slate-400" />
              </div>
              <div class="mt-3 flex flex-wrap gap-2">
                <span v-for="metric in flow.metrics" :key="metric[0]"
                  class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600">
                  {{ metric[0] }} <strong class="ml-1 text-slate-900">{{ number(metric[1]) }}</strong>
                </span>
              </div>
            </button>
          </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="font-semibold text-slate-900">Security & Governance</h2>
            <p class="text-xs text-slate-500">Sinyal akses, RBAC, dan privileged operation.</p>
          </div>
          <div class="grid grid-cols-2 gap-px bg-slate-100">
            <div class="bg-white p-5">
              <div class="text-xs text-slate-500">Login gagal · 1 jam</div>
              <div class="mt-1 text-2xl font-bold text-slate-900">{{ number(data.security.failed_logins_1h) }}</div>
            </div>
            <div class="bg-white p-5">
              <div class="text-xs text-slate-500">Audit high/critical · 24 jam</div>
              <div class="mt-1 text-2xl font-bold text-slate-900">{{ number(data.security.critical_audit_24h) }}</div>
            </div>
            <div class="bg-white p-5">
              <div class="text-xs text-slate-500">User terkunci</div>
              <div class="mt-1 text-2xl font-bold text-slate-900">{{ number(data.tenants.locked_users) }}</div>
            </div>
            <div class="bg-white p-5">
              <div class="text-xs text-slate-500">Perubahan RBAC menunggu</div>
              <div class="mt-1 text-2xl font-bold text-slate-900">{{ number(data.security.rbac_pending) }}</div>
            </div>
          </div>
        </section>
      </div>

      <div class="grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
        <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
              <h2 class="font-semibold text-slate-900">Tenant Risk Ranking</h2>
              <p class="text-xs text-slate-500">Skor dari job, delivery, dan approval bermasalah.</p>
            </div>
            <button class="text-xs font-semibold text-blue-600 hover:underline" @click="go('/admin/tenants')">
              Semua tenant
            </button>
          </div>
          <div v-if="!data.tenant_risks?.length" class="px-5 py-8 text-sm text-slate-500">Belum ada tenant berisiko.</div>
          <div v-else class="divide-y divide-slate-100">
            <button v-for="tenant in data.tenant_risks" :key="tenant.company_id" type="button"
              class="flex w-full items-center gap-3 px-5 py-3 text-left hover:bg-slate-50"
              @click="go(`/admin/tenants/${tenant.company_id}`)">
              <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-slate-600">
                {{ tenant.company_code?.slice(0, 3) || tenant.company_id }}
              </div>
              <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-semibold text-slate-800">{{ tenant.company_name }}</div>
                <div class="text-xs text-slate-500">{{ tenant.company_code }}</div>
              </div>
              <div class="rounded-full bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700">
                {{ tenant.risk_score }}
              </div>
            </button>
          </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="font-semibold text-slate-900">Recent Technical Failures</h2>
            <p class="text-xs text-slate-500">Metadata kegagalan terbaru tanpa payload sensitif.</p>
          </div>
          <div v-if="!data.recent_failures?.length" class="px-5 py-8 text-sm text-slate-500">Tidak ada kegagalan terbaru.</div>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                  <th class="px-4 py-3">Sumber</th>
                  <th class="px-4 py-3">Referensi</th>
                  <th class="px-4 py-3">Tenant</th>
                  <th class="px-4 py-3">Error</th>
                  <th class="px-4 py-3">Waktu</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="failure in data.recent_failures" :key="`${failure.source}-${failure.reference}`">
                  <td class="px-4 py-3"><span class="rounded bg-rose-50 px-2 py-1 text-[10px] font-bold text-rose-700">{{ failure.source }}</span></td>
                  <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-700">{{ failure.reference }}</td>
                  <td class="max-w-[180px] truncate px-4 py-3 text-slate-600">{{ failure.company_name || 'Platform' }}</td>
                  <td class="max-w-[260px] truncate px-4 py-3 text-xs text-slate-500" :title="failure.error">{{ failure.error }}</td>
                  <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500">{{ relativeTime(failure.occurred_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <section>
        <div class="mb-3">
          <h2 class="font-semibold text-slate-900">Command Center Shortcuts</h2>
          <p class="text-xs text-slate-500">Masuk ke console spesialis untuk investigasi dan tindakan.</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <button v-for="item in quickLinks" :key="item.to" type="button"
            class="group flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm hover:border-blue-300 hover:shadow"
            @click="go(item.to)">
            <div class="rounded-lg bg-slate-100 p-2.5 text-slate-600 group-hover:bg-blue-50 group-hover:text-blue-600">
              <component :is="item.icon" class="h-5 w-5" />
            </div>
            <div class="min-w-0 flex-1">
              <div class="text-sm font-semibold text-slate-800">{{ item.label }}</div>
              <div class="truncate text-xs text-slate-500">{{ item.hint }}</div>
            </div>
            <ChevronRightIcon class="h-4 w-4 text-slate-400" />
          </button>
        </div>
      </section>
    </template>

    <div v-else class="rounded-xl border border-slate-200 bg-white px-6 py-12 text-center shadow-sm">
      <ServerStackIcon class="mx-auto h-10 w-10 text-slate-300" />
      <h2 class="mt-3 font-semibold text-slate-800">Snapshot operasional belum tersedia</h2>
      <p class="mt-1 text-sm text-slate-500">Periksa koneksi atau coba muat ulang command center.</p>
      <button type="button" class="mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
        @click="load(true)">
        Muat ulang
      </button>
    </div>
  </div>
</template>
