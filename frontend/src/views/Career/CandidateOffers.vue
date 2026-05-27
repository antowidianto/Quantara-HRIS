<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { useCandidateAuthStore, candidateApi } from '@/stores/candidateAuth'

/**
 * CandidateOffers — list semua penawaran kerja milik kandidat ini.
 *
 * Sprint 25 / Option C — kandidat self-service review offer.
 * Backend endpoint: GET /careers/account/offers
 *
 * Status SENT → highlight + tombol "Review & Respond" yang link ke
 * halaman detail (CandidateOfferDetail). Status lain (ACCEPTED/REJECTED/
 * EXPIRED/WITHDRAWN) tampil sebagai history read-only.
 */
const route = useRoute()
const router = useRouter()
const toast = useToast()
const store = useCandidateAuthStore()
const api = candidateApi()

const slug = computed(() => route.params.slug)
const loading = ref(true)
const offers = ref([])
const pendingCount = ref(0)

onMounted(async () => {
  if (! store.isAuthenticated) {
    return router.push({ name: 'candidate-login', params: { slug: slug.value } })
  }
  await load()
})

async function load() {
  loading.value = true
  try {
    const res = await api.get('/careers/account/offers')
    offers.value = res.data.data.offers || []
    pendingCount.value = res.data.data.pending_count || 0
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat penawaran')
  } finally {
    loading.value = false
  }
}

const statusLabel = (status) => ({
  DRAFT:     'Draft',
  SENT:      'Menunggu Respon Anda',
  ACCEPTED:  'Diterima',
  REJECTED:  'Ditolak',
  EXPIRED:   'Kadaluarsa',
  WITHDRAWN: 'Dibatalkan HR',
}[status] || status)

const statusColor = (status) => ({
  DRAFT:     'bg-slate-100 text-slate-600',
  SENT:      'bg-amber-100 text-amber-800 ring-2 ring-amber-300',
  ACCEPTED:  'bg-emerald-100 text-emerald-700',
  REJECTED:  'bg-rose-100 text-rose-700',
  EXPIRED:   'bg-slate-200 text-slate-600',
  WITHDRAWN: 'bg-slate-200 text-slate-600',
}[status] || 'bg-slate-100 text-slate-700')

const employmentLabel = (s) => ({
  PKWTT: 'PKWTT (Tetap)',
  PKWT: 'PKWT (Kontrak)',
  PROBATION: 'Probation',
  MAGANG: 'Magang',
  OUTSOURCING: 'Outsourcing',
}[s] || s)

function fmtMoney(amount, currency = 'IDR') {
  if (amount == null) return '-'
  const n = Number(amount)
  if (currency === 'IDR') {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(n)
  }
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency }).format(n)
}

function fmtDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
}

function daysUntilDeadline(deadline) {
  if (! deadline) return null
  const now = new Date()
  const dl = new Date(deadline + 'T23:59:59')
  const diff = Math.ceil((dl.getTime() - now.getTime()) / (1000 * 60 * 60 * 24))
  return diff
}
</script>

<template>
  <div class="max-w-4xl mx-auto py-8 px-4">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Penawaran Kerja</h1>
        <p class="text-sm text-slate-500 mt-1">
          Review dan respon penawaran kerja yang diberikan kepada Anda.
        </p>
      </div>
      <router-link :to="{ name: 'candidate-dashboard', params: { slug } }"
        class="px-4 py-2 bg-white border border-slate-300 text-slate-700 rounded-lg text-sm hover:bg-slate-50">
        ← Dashboard
      </router-link>
    </div>

    <div v-if="pendingCount > 0"
      class="mb-6 bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-start gap-3">
      <div class="text-2xl">📩</div>
      <div>
        <div class="font-semibold text-amber-900">
          Anda punya {{ pendingCount }} penawaran kerja yang menunggu respon
        </div>
        <div class="text-sm text-amber-700 mt-1">
          Klik tombol "Review & Respon" pada kartu di bawah untuk melihat detail dan menerima/menolak penawaran.
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-500">Memuat...</div>

    <div v-else-if="offers.length === 0"
      class="bg-white border border-slate-200 rounded-xl p-12 text-center">
      <div class="text-4xl mb-3">📭</div>
      <div class="text-slate-700 font-medium">Belum ada penawaran kerja</div>
      <div class="text-sm text-slate-500 mt-2">
        Penawaran akan muncul di sini setelah HR mengirimkan surat penawaran kerja kepada Anda.
      </div>
    </div>

    <div v-else class="space-y-4">
      <div v-for="o in offers" :key="o.id"
        class="bg-white border rounded-xl p-5 transition-shadow"
        :class="o.status === 'SENT' ? 'border-amber-300 shadow-md' : 'border-slate-200'">
        <div class="flex items-start justify-between gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="font-semibold text-slate-900">
                {{ o.job_title || 'Penawaran Kerja' }}
              </span>
              <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="statusColor(o.status)">
                {{ statusLabel(o.status) }}
              </span>
            </div>
            <div class="text-xs text-slate-500 mt-1 space-x-2">
              <span>No. {{ o.offer_number }}</span>
              <span v-if="o.position_name">· {{ o.position_name }}</span>
              <span v-if="o.department_name">· {{ o.department_name }}</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-3 text-sm">
              <div>
                <div class="text-xs text-slate-500">Gaji Pokok</div>
                <div class="font-semibold text-slate-900">{{ fmtMoney(o.basic_salary, o.currency) }}</div>
              </div>
              <div>
                <div class="text-xs text-slate-500">Status Kerja</div>
                <div class="text-slate-900">{{ employmentLabel(o.employment_status) }}</div>
              </div>
              <div>
                <div class="text-xs text-slate-500">Mulai Kerja</div>
                <div class="text-slate-900">{{ fmtDate(o.proposed_join_date) }}</div>
              </div>
            </div>
            <div v-if="o.status === 'SENT' && o.response_deadline"
              class="mt-3 text-sm">
              <template v-if="daysUntilDeadline(o.response_deadline) >= 0">
                <span class="text-amber-700">
                  ⏱ Batas respon: <strong>{{ fmtDate(o.response_deadline) }}</strong>
                  <span class="text-xs">
                    ({{ daysUntilDeadline(o.response_deadline) }} hari lagi)
                  </span>
                </span>
              </template>
              <template v-else>
                <span class="text-rose-600">⚠ Deadline sudah lewat</span>
              </template>
            </div>
            <div v-if="o.responded_at" class="mt-2 text-xs text-slate-500">
              Direspon pada: {{ fmtDate(o.responded_at) }}
            </div>
          </div>
          <div class="shrink-0">
            <router-link
              :to="{ name: 'candidate-offer-detail', params: { slug, id: o.id } }"
              class="inline-flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap"
              :class="o.status === 'SENT'
                ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                : 'bg-slate-100 text-slate-700 hover:bg-slate-200'">
              {{ o.status === 'SENT' ? 'Review & Respon' : 'Lihat Detail' }}
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
              </svg>
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>