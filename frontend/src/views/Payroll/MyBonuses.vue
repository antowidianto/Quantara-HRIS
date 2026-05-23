<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { fmtCurrency, fmtDate } from '@/composables/useResource'
import { GiftIcon, CalendarDaysIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'

/**
 * Sprint 21+ — Bonus History per karyawan (self-service).
 *
 * Tampilkan riwayat bonus yang diterima karyawan login. Data dari
 * `GET /employees/{id}/bonus-history` (Sprint 20+).
 *
 * Default hanya tampilkan run yang sudah APPROVED/PAID — DRAFT/CALCULATED
 * confidential. HR yang butuh melihat data pending harus pakai admin
 * view di profile karyawan (separate page, defer Sprint 22+).
 *
 * Pattern mirip `MyTransfers.vue` & `MyPayslips.vue` — self-access ke
 * data milik diri sendiri tanpa perlu permission khusus.
 */
const auth = useAuthStore()
const toast = useToast()
const loading = ref(false)
const history = ref([])
const stats = ref({ total_amount: 0, count: 0, by_kind: {} })

const KIND_LABEL = {
  ANNUAL:        'Annual / Tahunan',
  KPI:           'KPI / Performance',
  MILESTONE:     'Milestone',
  SIGNON:        'Sign-on',
  RETENTION:     'Retention',
  DISCRETIONARY: 'Discretionary',
}
const KIND_COLOR = {
  ANNUAL:        'badge-blue',
  KPI:           'badge-green',
  MILESTONE:     'badge-purple',
  SIGNON:        'badge-yellow',
  RETENTION:     'badge-pink',
  DISCRETIONARY: 'badge-gray',
}
const STATUS_BADGE = {
  APPROVED: 'badge-yellow',
  PAID:     'badge-green',
}

const employeeId = computed(() => auth.employee?.id || auth.user?.employee_id)
const noEmployeeLink = ref(false)
const employeeName = computed(() => auth.employee?.full_name || auth.user?.name || 'Anda')
const includePending = ref(false)            // Toggle di empty state untuk lihat pending
const lastFetchedAt = ref(null)              // Timestamp terakhir fetch berhasil
// Diagnostic dari backend (Sprint 23+) — dipakai panel troubleshooting
// kalau hasil utama kosong supaya user bisa tahu cause langsung dari UI.
const diagnostic = ref(null)

async function fetchData() {
  noEmployeeLink.value = false
  if (! employeeId.value) {
    // Super admin / IT admin / role tanpa employee link → tampilkan
    // diagnostic message, jangan toast error (bukan bug, by design).
    noEmployeeLink.value = true
    return
  }
  loading.value = true
  try {
    const params = {}
    if (includePending.value) params.include_pending = 1
    const res = await api.get(`/employees/${employeeId.value}/bonus-history`, { params })
    history.value = res.data?.data?.history || []
    stats.value = res.data?.data?.stats || { total_amount: 0, count: 0, by_kind: {} }
    diagnostic.value = res.data?.data?.diagnostic || null
    lastFetchedAt.value = new Date()
  } catch (e) {
    if (e.response?.status === 403) {
      toast.error('Anda tidak memiliki akses ke data ini')
    } else {
      toast.error(e.response?.data?.message || 'Gagal memuat riwayat bonus')
    }
    console.error('[MyBonuses] fetch failed', e.response?.status, e.response?.data)
  } finally {
    loading.value = false
  }
}

/**
 * Interpret diagnostic dari backend ke pesan actionable. Return null
 * kalau tidak ada anomali (data normal, cuma karena belum ada bonus
 * di-approve).
 */
const diagnosticMessage = computed(() => {
  const d = diagnostic.value
  if (! d) return null
  // Cross-company: user login di company X, tapi employee record di company Y
  if (d.cross_company) {
    return {
      level: 'error',
      title: 'Akun Anda lintas-company dengan record karyawan',
      detail: `User Anda (company #${d.user_tenant_company_id}) tidak match dengan
        record karyawan (#${d.employee_id} di company #${d.employee_company_id}).
        Hubungi IT/HR untuk fix mapping di tabel users.`,
    }
  }
  // Ada banyak recipient di status APPROVED/PAID tapi semuanya di-exclude
  const approvedish = (d.recipients_by_status?.APPROVED || 0) + (d.recipients_by_status?.PAID || 0)
  if (approvedish > 0 && d.excluded_count >= approvedish) {
    return {
      level: 'error',
      title: 'Bonus Anda di-exclude dari run',
      detail: `Ada ${approvedish} entry di run APPROVED/PAID, tapi semuanya
        di-mark as excluded (${d.excluded_count} dari total ${d.total_recipients_db}
        recipient). Minta HR include kembali (Bonus Run Detail → Include All).`,
    }
  }
  // Ada recipient pending tapi belum di-approve
  const pending = (d.recipients_by_status?.DRAFT || 0) + (d.recipients_by_status?.CALCULATED || 0)
  if (approvedish === 0 && pending > 0) {
    return {
      level: 'info',
      title: `Ada ${pending} bonus menunggu approval HR`,
      detail: `Bonus run yang melibatkan Anda masih di status
        ${d.recipients_by_status.DRAFT > 0 ? 'DRAFT ' : ''}${d.recipients_by_status.CALCULATED > 0 ? 'CALCULATED' : ''}.
        Belum disetujui manajemen. Hubungi HR untuk konfirmasi timeline approval.`,
    }
  }
  // Run cancelled
  if ((d.recipients_by_status?.CANCELLED || 0) > 0
      && approvedish === 0 && pending === 0) {
    return {
      level: 'warning',
      title: 'Run bonus dibatalkan',
      detail: `Bonus run yang melibatkan Anda telah di-cancel oleh HR
        (${d.recipients_by_status.CANCELLED} entry). Konfirmasi alasan ke HR.`,
    }
  }
  // Tidak ada recipient sama sekali
  if (d.total_recipients_db === 0) {
    return {
      level: 'info',
      title: 'Belum pernah masuk run bonus apapun',
      detail: 'Anda belum pernah ter-include di bonus run manapun. '
        + 'Bisa karena belum ada run yang dibuat, atau Anda tidak lolos eligibility filter '
        + '(min tenure, employment status, dst).',
    }
  }
  return null
})

async function togglePending() {
  includePending.value = ! includePending.value
  await fetchData()
}

onMounted(fetchData)

/**
 * Group history by year (period_year) untuk timeline display.
 * Format: { 2027: [...], 2026: [...] } — terbaru dulu.
 */
const historyByYear = computed(() => {
  const grouped = {}
  for (const item of history.value) {
    const year = item.period_year || new Date(item.pay_date).getFullYear()
    if (! grouped[year]) grouped[year] = []
    grouped[year].push(item)
  }
  // Sort years descending
  return Object.keys(grouped)
    .sort((a, b) => Number(b) - Number(a))
    .map(year => ({ year: Number(year), items: grouped[year] }))
})

const taxLabel = (treatment) => {
  switch ((treatment || '').toUpperCase()) {
    case 'NORMAL':
    case 'GROSS':       return { text: 'Taxable', cls: 'bg-blue-100 text-blue-700' }
    case 'NON_TAXABLE': return { text: 'Non-Pajak', cls: 'bg-green-100 text-green-700' }
    case 'GROSS_UP':    return { text: 'Gross-Up (Employer Absorb)', cls: 'bg-amber-100 text-amber-700' }
    case 'NET':         return { text: 'Net (Employer Absorb)', cls: 'bg-amber-100 text-amber-700' }
    default:            return { text: 'Taxable', cls: 'bg-gray-100 text-gray-700' }
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <GiftIcon class="w-7 h-7 text-amber-600" />
          Riwayat Bonus
        </h1>
        <p class="text-sm text-gray-500">
          Daftar bonus yang sudah Anda terima. Hanya menampilkan run yang
          sudah disetujui atau dibayar.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <span v-if="lastFetchedAt" class="text-xs text-gray-400">
          Diperbarui {{ lastFetchedAt.toLocaleTimeString('id-ID') }}
        </span>
        <button @click="fetchData" :disabled="loading" class="btn-secondary" title="Reload data">
          <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
          Refresh
        </button>
      </div>
    </div>

    <div v-if="loading" class="card p-12 text-center text-gray-400">
      Memuat riwayat bonus...
    </div>

    <!-- User account tidak punya employee_id (mis. super admin / IT admin) -->
    <template v-else-if="noEmployeeLink">
      <div class="card p-12 text-center">
        <GiftIcon class="w-16 h-16 mx-auto text-gray-300 mb-3" />
        <h3 class="text-lg font-medium text-gray-700 mb-1">Akun belum terhubung ke karyawan</h3>
        <p class="text-sm text-gray-500 max-w-lg mx-auto">
          Akun {{ employeeName }} tidak terhubung ke record karyawan, jadi tidak ada
          data bonus pribadi untuk ditampilkan. Halaman ini diperuntukkan
          karyawan dengan profil HR aktif.
        </p>
        <p class="text-xs text-gray-400 mt-3">
          Kalau Anda HR / admin yang ingin melihat bonus seluruh karyawan,
          buka <strong>Payroll → Bonus Runs</strong>.
        </p>
      </div>
    </template>

    <template v-else-if="history.length === 0">
      <div class="card p-12 text-center">
        <GiftIcon class="w-16 h-16 mx-auto text-gray-300 mb-3" />
        <h3 class="text-lg font-medium text-gray-700 mb-1">
          {{ includePending ? 'Belum ada bonus (termasuk pending)' : 'Belum ada bonus' }}
        </h3>
        <p class="text-sm text-gray-500 max-w-lg mx-auto">
          Riwayat bonus tampil di sini hanya untuk run yang sudah berstatus
          <strong>APPROVED</strong> atau <strong>PAID</strong>.
        </p>
        <!-- Diagnostic dari backend — actionable message berdasarkan
             kondisi data sebenarnya. -->
        <div v-if="diagnosticMessage"
             :class="[
               'mt-4 inline-flex flex-col gap-1 text-left p-3 rounded-lg max-w-md text-xs',
               diagnosticMessage.level === 'error' ? 'bg-red-50 border border-red-200 text-red-900' :
               diagnosticMessage.level === 'warning' ? 'bg-amber-50 border border-amber-200 text-amber-900' :
                                                       'bg-blue-50 border border-blue-200 text-blue-900',
             ]">
          <p class="font-semibold">{{ diagnosticMessage.title }}</p>
          <p>{{ diagnosticMessage.detail }}</p>
        </div>

        <div class="mt-4 inline-flex flex-col gap-2 text-left text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-lg p-3 max-w-md">
          <p class="font-semibold text-gray-700">Alur status bonus:</p>
          <ol class="list-decimal list-inside space-y-1">
            <li><span class="font-mono text-gray-600">DRAFT</span> — HR baru bikin run, belum dihitung.</li>
            <li><span class="font-mono text-gray-600">CALCULATED</span> — sudah dihitung, masih bisa direvisi HR.</li>
            <li><span class="font-mono text-amber-700">APPROVED</span> — disetujui manajemen, mulai muncul di sini.</li>
            <li><span class="font-mono text-green-700">PAID</span> — sudah dibayarkan.</li>
          </ol>
          <p class="text-gray-400 mt-1">
            Kalau HR sudah bilang bonus Anda sudah dihitung tapi belum tampil,
            artinya masih menunggu approval manajemen. Hubungi HRBP atau Finance
            untuk konfirmasi.
          </p>
        </div>

        <!-- Diagnostic info — kalau HR sudah approve tapi karyawan tidak melihat,
             panel ini bantu identifikasi penyebab (multi-tenant scope, employee_id
             salah, dll). -->
        <details class="mt-4 inline-block max-w-md text-left">
          <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">
            Bonus Anda sudah di-approve tapi tidak tampil di sini?
          </summary>
          <div class="mt-2 p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-900 space-y-1.5">
            <p>Coba langkah-langkah berikut:</p>
            <ol class="list-decimal list-inside space-y-1">
              <li>
                Klik <strong>Refresh</strong> di kanan atas (cache UI mungkin belum update).
              </li>
              <li>
                Logout dan login ulang. Token JWT lama mungkin tidak punya
                <code>employee_id</code> yang ter-link.
              </li>
              <li>
                Cek di profile — pastikan akun Anda terhubung ke record karyawan yang
                benar (NIK / employee_number cocok dengan run yang di-approve HR).
              </li>
              <li>
                Konfirmasi ke HR: company / cabang Anda match dengan bonus run.
                Bonus run di company A tidak tampil ke karyawan di company B.
              </li>
              <li>
                Klik <strong>"Tampilkan Pending"</strong> di bawah untuk cek apakah
                run masih di status CALCULATED (belum APPROVED).
              </li>
            </ol>
            <p class="pt-1 border-t border-amber-200 mt-2">
              <strong>Debug info:</strong> employee_id yang dipakai = <code>{{ employeeId }}</code>,
              filter saat ini = <code>{{ includePending ? 'APPROVED + PAID + pending' : 'APPROVED + PAID' }}</code>.
            </p>
            <details v-if="diagnostic" class="pt-1">
              <summary class="cursor-pointer text-xs text-gray-500">
                Backend diagnostic (untuk laporan ke IT/HR)
              </summary>
              <pre class="mt-1 p-2 bg-white rounded border border-gray-200 overflow-x-auto text-[10px] font-mono">{{ JSON.stringify(diagnostic, null, 2) }}</pre>
            </details>
          </div>
        </details>

        <div class="mt-4">
          <button @click="togglePending" class="btn-secondary text-xs">
            {{ includePending ? 'Sembunyikan Pending' : 'Tampilkan Pending (DRAFT/CALCULATED)' }}
          </button>
        </div>
      </div>
    </template>

    <template v-else>
      <!-- Stats summary -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="card p-4">
          <div class="text-xs text-gray-500 mb-1">Total Bonus Sepanjang Karir</div>
          <div class="text-2xl font-bold text-primary-700">{{ fmtCurrency(stats.total_amount) }}</div>
          <div class="text-xs text-gray-400 mt-1">
            {{ stats.count }} bonus diterima
          </div>
        </div>
        <div class="card p-4 md:col-span-2">
          <div class="text-xs text-gray-500 mb-2">Breakdown per Jenis</div>
          <div class="flex flex-wrap gap-2">
            <div v-for="(amount, kind) in stats.by_kind"
                 :key="kind"
                 class="flex items-center gap-2 px-3 py-1.5 rounded-md border border-gray-200 bg-gray-50">
              <span :class="KIND_COLOR[kind] || 'badge-gray'">
                {{ KIND_LABEL[kind] || kind }}
              </span>
              <span class="text-sm font-semibold tabular-nums">{{ fmtCurrency(amount) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Timeline by year -->
      <div v-for="group in historyByYear" :key="group.year" class="mb-6">
        <h2 class="text-lg font-semibold text-gray-700 mb-3 flex items-center gap-2">
          <CalendarDaysIcon class="w-5 h-5 text-gray-400" />
          {{ group.year }}
        </h2>
        <div class="card overflow-hidden">
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Bonus</th>
                  <th>Jenis</th>
                  <th>Pay Date</th>
                  <th>Tax Treatment</th>
                  <th class="text-right">Amount</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="item in group.items" :key="item.id">
                  <td>
                    <div class="font-medium text-sm">{{ item.run_name || item.scheme_name }}</div>
                    <div v-if="item.scheme_code" class="text-xs text-gray-500 font-mono">
                      {{ item.scheme_code }}
                    </div>
                  </td>
                  <td>
                    <span :class="KIND_COLOR[item.scheme_kind] || 'badge-gray'">
                      {{ KIND_LABEL[item.scheme_kind] || item.scheme_kind }}
                    </span>
                  </td>
                  <td class="text-sm">{{ fmtDate(item.pay_date) }}</td>
                  <td>
                    <span :class="['text-xs px-1.5 py-0.5 rounded', taxLabel(item.tax_treatment).cls]">
                      {{ taxLabel(item.tax_treatment).text }}
                    </span>
                  </td>
                  <td class="text-right tabular-nums font-semibold">
                    {{ fmtCurrency(item.amount) }}
                  </td>
                  <td>
                    <span :class="STATUS_BADGE[item.run_status] || 'badge-gray'">
                      {{ item.run_status }}
                    </span>
                    <div v-if="item.payroll_period_id" class="text-xs text-gray-400 mt-0.5">
                      → Slip gaji
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <p class="text-xs text-gray-400 mt-3">
        Catatan: Bonus dengan tax treatment <strong>Non-Pajak</strong> tidak masuk
        perhitungan PPh21. Bonus <strong>Gross-Up / Net</strong> berarti pajaknya
        sudah ditanggung perusahaan; nominal yang Anda lihat adalah amount setelah
        gross-up untuk transparansi.
      </p>
    </template>
  </div>
</template>