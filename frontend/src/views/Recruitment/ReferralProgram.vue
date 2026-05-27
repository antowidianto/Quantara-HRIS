<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { fmtCurrency } from '@/composables/useResource'
import {
  ShareIcon, LinkIcon, ClipboardIcon, CheckIcon, ArrowPathIcon,
  TrophyIcon, BriefcaseIcon, UserPlusIcon, GiftIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 12 / H2.5 — Employee Referral Program.
 *
 * Tabs:
 *   1. Saya — daftar referral link aktif untuk karyawan
 *   2. Top Referrers — leaderboard HR-only
 *
 * Endpoints:
 *   POST /referrals/links              — generate referral link
 *   GET  /referrals/links/me           — list referral link user
 *   GET  /referrals/top-referrers      — leaderboard
 *   GET  /careers/r/:slug              — public resolve (untuk preview)
 */

const auth = useAuthStore()
const toast = useToast()

const tab = ref('mine')
const isHR = computed(() =>
  auth.can('recruitment.manage') || auth.isSuperAdmin
)

// ─── Karyawan: my referral links ───────────────────
const myLinks = ref([])
const jobPostings = ref([])
const showShare = ref(null) // job posting yang sedang dibagikan

async function loadMyLinks() {
  try {
    const res = await api.get('/referrals/links/me')
    myLinks.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat referral link')
  }
}

async function loadJobs() {
  try {
    const res = await api.get('/job-postings', { params: { status: 'OPEN', per_page: 100 } })
    jobPostings.value = res.data.data || []
  } catch (_) {}
}

async function shareJob(job) {
  // Cek apakah sudah punya link untuk job ini
  const existing = myLinks.value.find(l => l.job_posting_id === job.id)
  if (existing) {
    showShare.value = { ...job, link: existing }
    return
  }
  try {
    const res = await api.post('/referrals/links', { job_posting_id: job.id })
    const link = res.data.data
    toast.success('Referral link berhasil dibuat')
    await loadMyLinks()
    showShare.value = { ...job, link }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal generate link')
  }
}

const copiedSlug = ref(null)
function copyLink(slug) {
  // Build URL: ke career portal company saat ini, dengan path /r/{slug}.
  // Slug resolve di backend (public endpoint), redirect ke job detail.
  const baseUrl = window.location.origin
  const url = `${baseUrl}/api/v1/referrals/r/${slug}`
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(url).then(() => {
      copiedSlug.value = slug
      toast.success('Link berhasil disalin')
      setTimeout(() => { copiedSlug.value = null }, 2000)
    })
  } else {
    // Fallback
    window.prompt('Salin link ini:', url)
  }
}

function shareToWhatsApp(slug, jobTitle) {
  const baseUrl = window.location.origin
  const url = `${baseUrl}/api/v1/referrals/r/${slug}`
  const text = encodeURIComponent(
    `Halo! Perusahaan saya sedang membuka lowongan ${jobTitle}. ` +
    `Kalau tertarik, silakan apply lewat link referral saya: ${url}`
  )
  window.open(`https://wa.me/?text=${text}`, '_blank')
}

// ─── HR: top referrers ─────────────────────────────
const topReferrers = ref([])

async function loadTopReferrers() {
  try {
    const res = await api.get('/referrals/top-referrers')
    topReferrers.value = res.data.data || []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat top referrers')
  }
}

function switchTab(t) {
  tab.value = t
  if (t === 'mine') {
    loadMyLinks()
    loadJobs()
  } else if (t === 'leaderboard' && isHR.value) {
    loadTopReferrers()
  }
}

const myStats = computed(() => {
  return myLinks.value.reduce((acc, l) => ({
    totalLinks: acc.totalLinks + 1,
    totalClicks: acc.totalClicks + (l.click_count || 0),
    totalApplies: acc.totalApplies + (l.apply_count || 0),
    totalHired: acc.totalHired + (l.hired_count || 0),
  }), { totalLinks: 0, totalClicks: 0, totalApplies: 0, totalHired: 0 })
})

onMounted(() => {
  loadMyLinks()
  loadJobs()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ShareIcon class="w-6 h-6" /> Employee Referral Program
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          Bagikan lowongan ke jaringan kamu. Dapatkan bonus saat kandidat referral di-hire dan lulus probasi.
        </p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-4 flex gap-1">
      <button v-for="t in [
        { id: 'mine', label: 'Referral Saya', icon: LinkIcon },
        ...(isHR ? [{ id: 'leaderboard', label: 'Top Referrers (HR)', icon: TrophyIcon }] : []),
      ]" :key="t.id" @click="switchTab(t.id)"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors flex items-center gap-2',
          tab === t.id ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700'
        ]">
        <component :is="t.icon" class="w-4 h-4" />
        {{ t.label }}
      </button>
    </div>

    <!-- Tab: Mine -->
    <div v-if="tab === 'mine'">
      <!-- Stats -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="card-body bg-blue-50 rounded-lg border border-blue-100">
          <div class="text-xs text-blue-700">Link Aktif</div>
          <div class="text-2xl font-bold text-blue-900">{{ myStats.totalLinks }}</div>
        </div>
        <div class="card-body bg-purple-50 rounded-lg border border-purple-100">
          <div class="text-xs text-purple-700">Total Klik</div>
          <div class="text-2xl font-bold text-purple-900">{{ myStats.totalClicks }}</div>
        </div>
        <div class="card-body bg-amber-50 rounded-lg border border-amber-100">
          <div class="text-xs text-amber-700">Lamaran Masuk</div>
          <div class="text-2xl font-bold text-amber-900">{{ myStats.totalApplies }}</div>
        </div>
        <div class="card-body bg-emerald-50 rounded-lg border border-emerald-100">
          <div class="text-xs text-emerald-700">Berhasil Hired</div>
          <div class="text-2xl font-bold text-emerald-900">{{ myStats.totalHired }}</div>
        </div>
      </div>

      <!-- Lowongan available -->
      <div class="card mb-4">
        <div class="card-header flex items-center gap-2">
          <BriefcaseIcon class="w-4 h-4" />
          <span class="font-medium">Lowongan yang Bisa Dibagikan</span>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Posisi</th>
                <th>Department</th>
                <th>Lokasi</th>
                <th class="text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="jobPostings.length === 0">
                <td colspan="4" class="text-center py-8 text-gray-400">
                  Belum ada lowongan terbuka
                </td>
              </tr>
              <tr v-for="j in jobPostings" :key="j.id">
                <td class="font-medium">{{ j.title }}</td>
                <td class="text-sm">{{ j.department_name || '—' }}</td>
                <td class="text-sm">{{ j.work_location || j.branch_name || '—' }}</td>
                <td class="text-right">
                  <button class="btn-primary text-xs" @click="shareJob(j)">
                    <ShareIcon class="w-4 h-4" /> Share
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- My links -->
      <div class="card">
        <div class="card-header">
          <span class="font-medium">Link Referral Saya</span>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Lowongan</th>
                <th>Slug</th>
                <th class="text-right">Klik</th>
                <th class="text-right">Apply</th>
                <th class="text-right">Hired</th>
                <th>Bonus</th>
                <th class="text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="myLinks.length === 0">
                <td colspan="7" class="text-center py-8 text-gray-400">
                  Belum ada referral link. Klik "Share" di tabel atas untuk generate.
                </td>
              </tr>
              <tr v-for="l in myLinks" :key="l.id">
                <td class="font-medium">{{ l.job_title || `Job #${l.job_posting_id}` }}</td>
                <td class="font-mono text-xs text-gray-600">{{ l.slug }}</td>
                <td class="text-right">{{ l.click_count || 0 }}</td>
                <td class="text-right">{{ l.apply_count || 0 }}</td>
                <td class="text-right">
                  <span v-if="l.hired_count > 0" class="text-emerald-700 font-medium">
                    {{ l.hired_count }}
                  </span>
                  <span v-else class="text-gray-400">0</span>
                </td>
                <td>
                  <span v-if="l.bonus_paid" class="inline-flex items-center gap-1 text-emerald-700 text-xs">
                    <GiftIcon class="w-3.5 h-3.5" /> {{ fmtCurrency(l.bonus_amount) }} terbayar
                  </span>
                  <span v-else-if="l.hired_count > 0" class="text-xs text-amber-600">
                    Pending probasi
                  </span>
                  <span v-else class="text-xs text-gray-400">—</span>
                </td>
                <td class="text-right">
                  <div class="inline-flex gap-1">
                    <button class="btn-icon" @click="copyLink(l.slug)" title="Salin link">
                      <CheckIcon v-if="copiedSlug === l.slug" class="w-4 h-4 text-emerald-600" />
                      <ClipboardIcon v-else class="w-4 h-4" />
                    </button>
                    <button class="btn-icon text-emerald-600" @click="shareToWhatsApp(l.slug, l.job_title)"
                      title="Bagikan via WhatsApp">
                      <ShareIcon class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Modal: share -->
      <div v-if="showShare" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
        @click.self="showShare = null">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
          <div class="px-5 py-3 border-b">
            <h3 class="font-semibold">Bagikan Lowongan</h3>
          </div>
          <div class="p-5 space-y-3">
            <div class="font-medium">{{ showShare.title }}</div>
            <div class="bg-gray-50 rounded p-3 text-sm font-mono text-gray-700 break-all">
              {{ window.location.origin }}/api/v1/referrals/r/{{ showShare.link.slug }}
            </div>
            <div class="grid grid-cols-2 gap-2">
              <button class="btn-secondary" @click="copyLink(showShare.link.slug)">
                <ClipboardIcon class="w-4 h-4" /> Salin Link
              </button>
              <button class="btn-primary bg-emerald-600 hover:bg-emerald-700"
                @click="shareToWhatsApp(showShare.link.slug, showShare.title)">
                <ShareIcon class="w-4 h-4" /> Kirim ke WhatsApp
              </button>
            </div>
            <p class="text-xs text-gray-500 text-center">
              Setiap klik dari link ini di-track. Saat kandidat apply lewat link kamu,
              kamu otomatis jadi referrer-nya.
            </p>
          </div>
          <div class="px-5 py-3 border-t flex justify-end">
            <button class="btn-secondary" @click="showShare = null">Tutup</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Tab: Leaderboard -->
    <div v-if="tab === 'leaderboard' && isHR">
      <div class="flex justify-end mb-3">
        <button class="btn-secondary" @click="loadTopReferrers">
          <ArrowPathIcon class="w-4 h-4" /> Refresh
        </button>
      </div>
      <div class="card">
        <div class="card-header flex items-center gap-2">
          <TrophyIcon class="w-4 h-4 text-amber-500" />
          <span class="font-medium">Top Referrers</span>
        </div>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th class="w-12">#</th>
                <th>Karyawan</th>
                <th>Department</th>
                <th class="text-right">Total Klik</th>
                <th class="text-right">Apply</th>
                <th class="text-right">Hired</th>
                <th class="text-right">Acceptance %</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-if="topReferrers.length === 0">
                <td colspan="7" class="text-center py-8 text-gray-400">
                  Belum ada data referral
                </td>
              </tr>
              <tr v-for="(r, i) in topReferrers" :key="r.employee_id">
                <td class="text-center font-bold"
                  :class="i === 0 ? 'text-amber-500' : i < 3 ? 'text-gray-700' : 'text-gray-400'">
                  {{ i + 1 }}
                </td>
                <td>
                  <div class="font-medium">{{ r.full_name }}</div>
                  <div class="text-xs text-gray-500">{{ r.position_name || '—' }}</div>
                </td>
                <td class="text-sm">{{ r.department_name || '—' }}</td>
                <td class="text-right">{{ r.total_clicks || 0 }}</td>
                <td class="text-right">{{ r.total_apply || 0 }}</td>
                <td class="text-right text-emerald-700 font-medium">{{ r.total_hired || 0 }}</td>
                <td class="text-right">
                  <span v-if="r.total_apply > 0" class="text-sm">
                    {{ ((r.total_hired / r.total_apply) * 100).toFixed(0) }}%
                  </span>
                  <span v-else class="text-gray-400">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>