<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { useCandidateAuthStore, candidateApi } from '@/stores/candidateAuth'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const store = useCandidateAuthStore()
const api = candidateApi()

const slug = computed(() => route.params.slug)
const loading = ref(true)
const data = ref({ account: null, applications: [], tests: [] })
// Sprint 25 / Option C — count offer SENT yang masih menunggu respon
// supaya kita bisa tampilkan badge di dashboard. Endpoint terpisah biar
// /me tetap ringan & call gagal di sini tidak menggagalkan render.
const pendingOffersCount = ref(0)

onMounted(async () => {
  if (! store.isAuthenticated) {
    return router.push({ name: 'candidate-login', params: { slug: slug.value } })
  }
  try {
    const res = await api.get('/careers/account/me')
    data.value = res.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat dashboard')
  } finally {
    loading.value = false
  }
  // Best-effort fetch offers count — kalau gagal cuma sembunyikan badge,
  // tidak perlu error toast karena offer fitur opsional.
  try {
    const res = await api.get('/careers/account/offers')
    pendingOffersCount.value = res.data.data.pending_count || 0
  } catch (_) { /* ignore */ }
})

async function logout() {
  await store.logout()
  toast.success('Berhasil logout')
  router.push({ name: 'career-home', params: { slug: slug.value } })
}

const stageLabel = (stage) => ({
  NEW: 'Baru Masuk',
  SCREENING: 'Screening Awal',
  ONLINE_TEST: 'Tes Online',
  INTERVIEW_HR: 'Interview HR',
  INTERVIEW_USER: 'Interview User',
  OFFER: 'Offering',
  HIRED: 'Diterima',
  REJECTED: 'Tidak Lolos',
  WITHDRAWN: 'Dibatalkan',
}[stage] || stage)

const stageColor = (stage) => ({
  NEW: 'bg-slate-100 text-slate-700',
  SCREENING: 'bg-blue-100 text-blue-700',
  ONLINE_TEST: 'bg-purple-100 text-purple-700',
  INTERVIEW_HR: 'bg-indigo-100 text-indigo-700',
  INTERVIEW_USER: 'bg-cyan-100 text-cyan-700',
  OFFER: 'bg-amber-100 text-amber-700',
  HIRED: 'bg-emerald-100 text-emerald-700',
  REJECTED: 'bg-rose-100 text-rose-700',
  WITHDRAWN: 'bg-slate-100 text-slate-500',
}[stage] || 'bg-slate-100 text-slate-700')

const testStatusLabel = (status) => ({
  PENDING: 'Menunggu',
  IN_PROGRESS: 'Sedang Berjalan',
  COMPLETED: 'Selesai',
  EXPIRED: 'Kadaluarsa',
  CANCELLED: 'Dibatalkan',
}[status] || status)

const testStatusColor = (status) => ({
  PENDING: 'bg-amber-100 text-amber-700',
  IN_PROGRESS: 'bg-blue-100 text-blue-700',
  COMPLETED: 'bg-emerald-100 text-emerald-700',
  EXPIRED: 'bg-rose-100 text-rose-700',
  CANCELLED: 'bg-slate-100 text-slate-500',
}[status] || 'bg-slate-100 text-slate-700')

function fmtDate(d) {
  if (! d) return '-'
  return new Date(d).toLocaleString('id-ID', { dateStyle: 'medium' })
}
</script>

<template>
  <div class="max-w-5xl mx-auto py-8 px-4">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Dashboard Saya</h1>
        <p class="text-sm text-slate-500" v-if="data.account">
          Halo, {{ data.account.full_name }} · {{ data.account.email }}
        </p>
      </div>
      <div class="flex gap-2">
        <router-link :to="{ name: 'career-home', params: { slug } }"
          class="px-4 py-2 bg-white border border-slate-300 text-slate-700 rounded-lg text-sm hover:bg-slate-50">
          Cari Lowongan
        </router-link>
        <button @click="logout"
          class="px-4 py-2 bg-rose-600 text-white rounded-lg text-sm hover:bg-rose-700">
          Logout
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12 text-slate-500">Memuat...</div>

    <template v-else>
      <!-- Sprint 25 / Option C — Quick access cards.
           Kalau ada offer pending → kartu Penawaran di-highlight dengan
           gradient amber + count. Kalau tidak ada → kartu netral
           (warna brand, tanpa badge) supaya kandidat tetap punya cara
           cepat ke halaman penawaran/history. -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
        <router-link
          :to="{ name: 'candidate-offers', params: { slug } }"
          class="block rounded-xl border-2 p-4 hover:shadow-md transition-shadow"
          :class="pendingOffersCount > 0
            ? 'bg-gradient-to-r from-amber-50 to-rose-50 border-amber-300'
            : 'bg-white border-slate-200'">
          <div class="flex items-center gap-3">
            <div class="text-3xl">📩</div>
            <div class="flex-1 min-w-0">
              <div class="font-bold text-slate-900 flex items-center gap-2">
                Penawaran Kerja
                <span v-if="pendingOffersCount > 0"
                  class="text-xs px-2 py-0.5 bg-rose-600 text-white rounded-full font-medium">
                  {{ pendingOffersCount }} baru
                </span>
              </div>
              <div class="text-sm text-slate-600 mt-0.5">
                <template v-if="pendingOffersCount > 0">
                  Anda punya {{ pendingOffersCount }} penawaran menunggu respon. Klik untuk review.
                </template>
                <template v-else>
                  Lihat semua penawaran kerja yang pernah Anda terima.
                </template>
              </div>
            </div>
            <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </div>
        </router-link>

        <router-link
          :to="{ name: 'career-home', params: { slug } }"
          class="block rounded-xl border-2 border-slate-200 bg-white p-4 hover:shadow-md transition-shadow">
          <div class="flex items-center gap-3">
            <div class="text-3xl">🔍</div>
            <div class="flex-1 min-w-0">
              <div class="font-bold text-slate-900">Cari Lowongan</div>
              <div class="text-sm text-slate-600 mt-0.5">
                Browse lowongan kerja terbaru dan ajukan lamaran.
              </div>
            </div>
            <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </div>
        </router-link>
      </div>

      <!-- Test online: tampilkan paling atas karena urgent -->
      <div v-if="data.tests && data.tests.length" class="mb-8">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Tes Online untuk Anda</h2>
        <div class="space-y-3">
          <div v-for="t in data.tests" :key="t.id"
            class="bg-white rounded-xl border border-slate-200 p-4 flex items-center justify-between">
            <div>
              <div class="flex items-center gap-2">
                <span class="font-semibold text-slate-900">{{ t.test_name }}</span>
                <span class="text-xs px-2 py-0.5 rounded-full" :class="testStatusColor(t.status)">
                  {{ testStatusLabel(t.status) }}
                </span>
              </div>
              <div class="text-sm text-slate-500 mt-1 space-x-3">
                <span>Kategori: {{ t.test_category }}</span>
                <span v-if="t.duration_minutes">Durasi: {{ t.duration_minutes }} menit</span>
                <span v-if="t.due_at">Batas: {{ fmtDate(t.due_at) }}</span>
                <span v-if="t.attempts_used > 0">Percobaan: {{ t.attempts_used }}/{{ t.max_attempts }}</span>
              </div>
              <div v-if="t.best_percentage !== null && t.best_percentage !== undefined" class="text-sm mt-1">
                Skor terbaik: <strong>{{ t.best_percentage }}%</strong>
                <span v-if="t.best_result === 'PASS'" class="text-emerald-600 ml-2">Lulus</span>
                <span v-else-if="t.best_result === 'FAIL'" class="text-rose-600 ml-2">Tidak Lulus</span>
              </div>
            </div>
            <div>
              <router-link
                v-if="['PENDING','IN_PROGRESS'].includes(t.status)"
                :to="{ name: 'candidate-test-detail', params: { slug, id: t.id } }"
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
                {{ t.status === 'IN_PROGRESS' ? 'Lanjutkan' : 'Mulai Test' }}
              </router-link>
              <router-link
                v-else
                :to="{ name: 'candidate-test-detail', params: { slug, id: t.id } }"
                class="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg text-sm hover:bg-slate-200">
                Lihat Detail
              </router-link>
            </div>
          </div>
        </div>
      </div>

      <!-- Lamaran -->
      <div>
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Lamaran Saya</h2>
        <div v-if="! data.applications || data.applications.length === 0"
          class="bg-white border border-slate-200 rounded-xl p-8 text-center text-slate-500">
          Anda belum melamar lowongan apa pun.
          <router-link :to="{ name: 'career-home', params: { slug } }"
            class="text-indigo-600 font-medium hover:underline ml-1">
            Cari lowongan
          </router-link>
        </div>
        <div v-else class="space-y-3">
          <div v-for="app in data.applications" :key="app.id"
            class="bg-white border border-slate-200 rounded-xl p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="flex-1 min-w-0">
                <div class="font-semibold text-slate-900">{{ app.job_title }}</div>
                <div class="text-sm text-slate-500 mt-1 space-x-3">
                  <span>Ref: {{ app.reference_number }}</span>
                  <span v-if="app.city">· {{ app.city }}</span>
                  <span v-if="app.work_arrangement">· {{ app.work_arrangement }}</span>
                </div>
                <div class="text-xs text-slate-400 mt-1">
                  Dilamar: {{ fmtDate(app.applied_at) }}
                </div>
              </div>
              <div class="flex flex-col items-end gap-2 shrink-0">
                <span class="text-xs px-3 py-1 rounded-full font-medium whitespace-nowrap" :class="stageColor(app.current_stage)">
                  {{ stageLabel(app.current_stage) }}
                </span>
                <!-- Sprint 23+ — "Profil Saya" link. Selalu tampil; halaman
                     itu sendiri yang handle locked vs editable berdasarkan
                     stage (HIRED/REJECTED/WITHDRAWN → read-only). -->
                <router-link
                  :to="{ name: 'candidate-profile', params: { slug, id: app.id } }"
                  class="text-xs text-indigo-600 hover:text-indigo-700 hover:underline inline-flex items-center gap-1 whitespace-nowrap">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                  </svg>
                  {{ ['HIRED','REJECTED','WITHDRAWN'].includes(app.current_stage) ? 'Lihat Profil' : 'Edit Profil' }}
                </router-link>
              </div>
            </div>
            <div v-if="app.rejection_reason && app.current_stage === 'REJECTED'"
              class="mt-2 text-sm text-rose-600 bg-rose-50 p-2 rounded">
              Alasan: {{ app.rejection_reason }}
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>