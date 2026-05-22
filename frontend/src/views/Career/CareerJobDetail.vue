<script setup>
/*
 * CareerJobDetail — public detail page lowongan.
 * URL: /careers/{slug}/jobs/{jobSlug}
 *
 * Improvements:
 *   - Skeleton loader.
 *   - Sticky bottom-bar di mobile dengan tombol "Lamar Sekarang" supaya
 *     tidak perlu scroll panjang untuk apply.
 *   - Inject brand ke layout (header).
 *   - "Diposting X hari lalu" indicator + closing soon warning.
 *   - Better share (toast pengganti alert).
 *   - Mobile-friendly header (button stack on small screens).
 *   - Closing-date countdown.
 */
import { ref, computed, onMounted, watch, inject } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import careerApi from '@/api/careerPublic'
import { useToast } from 'vue-toastification'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const slug = computed(() => route.params.slug)
const jobSlug = computed(() => route.params.jobSlug)

const careerBrand = inject('careerBrand', null)

const loading = ref(true)
const error = ref(null)
const company = ref(null)
const job = ref(null)
const extraQuestions = ref([])

async function fetchData() {
  loading.value = true
  error.value = null
  try {
    const res = await careerApi.get(`/careers/${slug.value}/jobs/${jobSlug.value}`)
    const data = res.data.data
    company.value = data.company
    job.value = data.job
    extraQuestions.value = data.extra_questions || []
    const brandColor = company.value?.brand_color || '#0F62FE'
    document.documentElement.style.setProperty('--brand-color', brandColor)
    if (careerBrand) {
      careerBrand.value = {
        name:     company.value.name,
        logo_url: company.value.logo_url || '',
        color:    brandColor,
      }
    }
    document.title = `${job.value.title} — ${company.value.name}`
  } catch (e) {
    error.value = e.response?.data?.message || 'Lowongan tidak ditemukan atau sudah ditutup'
  } finally {
    loading.value = false
  }
}

onMounted(fetchData)
watch(jobSlug, fetchData)

function fmtSalary(min, max) {
  if (!min && !max) return null
  const f = (n) => 'Rp ' + Number(n).toLocaleString('id-ID')
  if (min && max) return `${f(min)} – ${f(max)}`
  if (min) return `Mulai ${f(min)}`
  return `Hingga ${f(max)}`
}

function workArrangementLabel(v) {
  return { WFO: 'On-site', WFH: 'Remote', HYBRID: 'Hybrid' }[v] || v
}

function employmentTypeLabel(v) {
  return {
    PKWT: 'Kontrak (PKWT)',
    PKWTT: 'Tetap (PKWTT)',
    MAGANG: 'Magang',
    OUTSOURCING: 'Outsourcing',
    PARTTIME: 'Part-time',
    CONTRACT: 'Kontrak',
  }[v] || v
}

function relativeTime(iso) {
  if (! iso) return null
  const d = new Date(String(iso).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return null
  const diffMs = Date.now() - d.getTime()
  const m = Math.floor(diffMs / 60000)
  if (m < 60) return m <= 1 ? 'baru saja' : `${m} menit lalu`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h} jam lalu`
  const days = Math.floor(h / 24)
  if (days <= 1) return 'kemarin'
  return `${days} hari lalu`
}

const closingInfo = computed(() => {
  const iso = job.value?.closing_date || job.value?.deadline_at
  if (! iso) return null
  const d = new Date(String(iso).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return null
  const diffDays = Math.ceil((d.getTime() - Date.now()) / (1000 * 60 * 60 * 24))
  if (diffDays < 0) return { text: 'Sudah ditutup', urgent: true, closed: true }
  if (diffDays === 0) return { text: 'Ditutup hari ini', urgent: true }
  if (diffDays <= 3) return { text: `Tutup dalam ${diffDays} hari`, urgent: true }
  if (diffDays <= 14) return { text: `Tutup dalam ${diffDays} hari`, urgent: false }
  return { text: `Berakhir ${d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })}`, urgent: false }
})

const postedRel = computed(() => relativeTime(job.value?.posted_at || job.value?.created_at))

function goApply() {
  router.push(`/careers/${slug.value}/jobs/${jobSlug.value}/apply`)
}

async function shareLink() {
  const url = window.location.href
  if (navigator.share) {
    try {
      await navigator.share({ title: job.value.title, text: job.value.title, url })
    } catch (_) { /* user cancelled */ }
  } else {
    try {
      await navigator.clipboard.writeText(url)
      toast.success('Tautan disalin ke clipboard')
    } catch (_) {
      toast.warning('Tidak bisa menyalin. Salin URL dari address bar.')
    }
  }
}
</script>

<template>
  <!-- ── Skeleton ─────────────────────────────────────────────── -->
  <div v-if="loading" class="max-w-4xl mx-auto px-4 py-8 space-y-4 animate-pulse">
    <div class="h-4 bg-slate-200 rounded w-32"></div>
    <div class="bg-slate-200 h-40 rounded-xl"></div>
    <div class="grid sm:grid-cols-2 gap-3">
      <div class="bg-slate-200 h-16 rounded-xl"></div>
      <div class="bg-slate-200 h-16 rounded-xl"></div>
    </div>
    <div class="bg-slate-200 h-64 rounded-xl"></div>
  </div>

  <!-- ── Error ────────────────────────────────────────────────── -->
  <div v-else-if="error" class="max-w-4xl mx-auto px-4 py-16 text-center">
    <div class="inline-flex flex-col items-center gap-3 bg-red-50 border border-red-200 text-red-700 rounded-lg px-6 py-5 max-w-md">
      <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
      </svg>
      <div class="font-semibold">{{ error }}</div>
      <p class="text-sm text-red-600/80">
        Lowongan mungkin sudah ditutup atau tautannya tidak valid.
      </p>
    </div>
    <div class="mt-6">
      <router-link
        :to="`/careers/${slug}`"
        class="inline-flex items-center gap-1 px-4 py-2 rounded-md bg-[var(--brand-color,#0F62FE)] text-white text-sm hover:opacity-90 transition"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
        </svg>
        Lihat lowongan lain
      </router-link>
    </div>
  </div>

  <template v-else>
    <div class="max-w-4xl mx-auto px-4 py-6 sm:py-8 pb-24 sm:pb-12">
      <!-- Breadcrumb -->
      <nav class="text-sm text-slate-500 mb-4 flex items-center gap-1.5">
        <router-link :to="`/careers/${slug}`" class="hover:underline hover:text-slate-700">Lowongan</router-link>
        <svg class="w-3.5 h-3.5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
        </svg>
        <span class="text-slate-700 truncate">{{ job.title }}</span>
      </nav>

      <!-- Header card -->
      <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 sm:p-6 mb-6">
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <div class="flex-1 min-w-0">
            <!-- Status / posted indicators -->
            <div class="flex flex-wrap items-center gap-1.5 mb-2">
              <span class="text-xs font-mono text-slate-400">{{ job.reference_number }}</span>
              <span v-if="postedRel" class="text-[11px] uppercase tracking-wide font-semibold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded">
                Diposting {{ postedRel }}
              </span>
              <span
                v-if="closingInfo"
                :class="[
                  'text-[11px] uppercase tracking-wide font-semibold px-1.5 py-0.5 rounded',
                  closingInfo.urgent ? 'text-amber-800 bg-amber-100' : 'text-slate-700 bg-slate-100'
                ]"
              >⏰ {{ closingInfo.text }}</span>
            </div>

            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 leading-tight">{{ job.title }}</h1>
            <div class="text-sm text-slate-600 mt-1.5">
              <span class="font-medium text-slate-900">{{ company.name }}</span>
              <span v-if="job.department_name"> · {{ job.department_name }}</span>
            </div>

            <!-- Quick attributes -->
            <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-slate-700">
              <span v-if="job.city" class="inline-flex items-center gap-1.5">
                <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                </svg>
                {{ job.city }}
              </span>
              <span v-if="job.work_arrangement">💼 {{ workArrangementLabel(job.work_arrangement) }}</span>
              <span v-if="job.employment_type">📄 {{ employmentTypeLabel(job.employment_type) }}</span>
              <span v-if="job.headcount > 1">👥 {{ job.headcount }} kursi tersedia</span>
            </div>

            <!-- Salary highlight -->
            <div v-if="job.salary_visible && (job.salary_min || job.salary_max)" class="mt-3 inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-800 px-3 py-1.5 rounded-md text-sm font-semibold border border-emerald-200">
              💰 {{ fmtSalary(job.salary_min, job.salary_max) }}
              <span class="font-normal text-emerald-700">/ bulan</span>
            </div>
          </div>

          <!-- Action buttons (desktop only — mobile uses sticky bottom bar) -->
          <div class="hidden sm:flex flex-col gap-2 shrink-0">
            <button
              @click="goApply"
              :disabled="closingInfo?.closed"
              class="px-6 py-2.5 rounded-md bg-[var(--brand-color,#0F62FE)] text-white font-medium hover:opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center justify-center gap-1.5"
            >
              {{ closingInfo?.closed ? 'Sudah Ditutup' : 'Lamar Sekarang' }}
              <svg v-if="!closingInfo?.closed" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
              </svg>
            </button>
            <button
              @click="shareLink"
              class="px-3 py-2 rounded-md border border-slate-300 text-sm hover:bg-slate-50 inline-flex items-center justify-center gap-1.5"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" />
              </svg>
              Bagikan
            </button>
          </div>
        </div>
      </div>

      <!-- Quick facts -->
      <div v-if="job.min_education || job.min_experience_years != null" class="grid sm:grid-cols-2 gap-3 mb-6">
        <div v-if="job.min_education" class="bg-white rounded-xl border border-slate-200 p-4 flex items-center gap-3">
          <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-[var(--brand-color,#0F62FE)]/10 text-[var(--brand-color,#0F62FE)] text-xl">🎓</span>
          <div>
            <div class="text-xs text-slate-500 uppercase tracking-wider">Pendidikan minimum</div>
            <div class="font-semibold text-slate-900">{{ job.min_education }}</div>
          </div>
        </div>
        <div v-if="job.min_experience_years != null" class="bg-white rounded-xl border border-slate-200 p-4 flex items-center gap-3">
          <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-[var(--brand-color,#0F62FE)]/10 text-[var(--brand-color,#0F62FE)] text-xl">⏳</span>
          <div>
            <div class="text-xs text-slate-500 uppercase tracking-wider">Pengalaman minimum</div>
            <div class="font-semibold text-slate-900">
              {{ job.min_experience_years === 0 ? 'Fresh graduate dipersilakan' : `${job.min_experience_years} tahun` }}
            </div>
          </div>
        </div>
      </div>

      <!-- Tags -->
      <div v-if="job.tags && job.tags.length" class="flex flex-wrap gap-2 mb-6">
        <span
          v-for="t in job.tags"
          :key="t"
          class="text-xs px-2.5 py-1 rounded-full bg-slate-100 text-slate-700"
        >#{{ t }}</span>
      </div>

      <!-- Description -->
      <section v-if="job.description" class="bg-white rounded-xl border border-slate-200 p-5 sm:p-6 mb-4">
        <h2 class="text-lg font-bold mb-3 flex items-center gap-2">
          <span class="text-[var(--brand-color,#0F62FE)]">📋</span> Deskripsi Pekerjaan
        </h2>
        <div class="prose prose-sm max-w-none text-slate-700" v-html="job.description"></div>
      </section>

      <!-- Requirements -->
      <section v-if="job.requirements" class="bg-white rounded-xl border border-slate-200 p-5 sm:p-6 mb-4">
        <h2 class="text-lg font-bold mb-3 flex items-center gap-2">
          <span class="text-[var(--brand-color,#0F62FE)]">✅</span> Persyaratan
        </h2>
        <div class="prose prose-sm max-w-none text-slate-700" v-html="job.requirements"></div>
      </section>

      <!-- Benefits -->
      <section v-if="job.benefits" class="bg-white rounded-xl border border-slate-200 p-5 sm:p-6 mb-4">
        <h2 class="text-lg font-bold mb-3 flex items-center gap-2">
          <span class="text-[var(--brand-color,#0F62FE)]">🎁</span> Benefit
        </h2>
        <div class="text-slate-700 whitespace-pre-line text-sm">{{ job.benefits }}</div>
      </section>

      <!-- Apply CTA bottom (desktop) -->
      <div
        v-if="!closingInfo?.closed"
        class="hidden sm:block bg-gradient-to-r from-[var(--brand-color,#0F62FE)]/10 to-[var(--brand-color,#0F62FE)]/5 border border-[var(--brand-color,#0F62FE)]/20 rounded-xl p-6 text-center mt-8"
      >
        <h2 class="text-xl font-bold mb-2">Tertarik dengan posisi ini?</h2>
        <p class="text-slate-600 mb-4 text-sm">
          Lengkapi data dan kirim lamaran Anda. Kami akan mereview dalam 5–7 hari kerja
          dan menghubungi via email/WhatsApp.
        </p>
        <button
          @click="goApply"
          class="px-8 py-3 rounded-md bg-[var(--brand-color,#0F62FE)] text-white font-medium hover:opacity-90 transition inline-flex items-center gap-2"
        >
          Lamar Sekarang
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Sticky bottom bar (mobile only) -->
    <div class="sm:hidden fixed bottom-0 left-0 right-0 z-30 bg-white border-t border-slate-200 px-4 py-3 flex items-center gap-2 shadow-lg">
      <button
        @click="shareLink"
        class="shrink-0 w-11 h-11 rounded-md border border-slate-300 inline-flex items-center justify-center hover:bg-slate-50"
        aria-label="Bagikan"
      >
        <svg class="w-5 h-5 text-slate-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" />
        </svg>
      </button>
      <button
        @click="goApply"
        :disabled="closingInfo?.closed"
        class="flex-1 h-11 rounded-md bg-[var(--brand-color,#0F62FE)] text-white font-medium hover:opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center justify-center gap-1.5"
      >
        {{ closingInfo?.closed ? 'Sudah Ditutup' : 'Lamar Sekarang' }}
        <svg v-if="!closingInfo?.closed" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
        </svg>
      </button>
    </div>
  </template>
</template>

<style>
.prose ul { list-style: disc; padding-left: 1.5rem; }
.prose ol { list-style: decimal; padding-left: 1.5rem; }
.prose p { margin: 0.5rem 0; }
.prose h4 { font-weight: 600; margin-top: 0.75rem; margin-bottom: 0.25rem; }
.prose strong { font-weight: 600; }
.prose a { color: var(--brand-color, #0F62FE); text-decoration: underline; }
</style>