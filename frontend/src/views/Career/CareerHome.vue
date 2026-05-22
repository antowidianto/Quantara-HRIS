<script setup>
/*
 * CareerHome — landing page public per perusahaan.
 * URL: /careers/{slug}
 *
 * Improvements vs versi awal:
 *   - Skeleton loader (bukan teks "Memuat..." yang plain).
 *   - Inject brand ke layout (logo + nama company tampil di header).
 *   - Filter tambahan: department + employment type.
 *   - "Reset filter" tombol kalau user main aktif filter.
 *   - "Posted Xh ago" relative timestamp di kartu.
 *   - Empty state lebih membantu (saran reset filter).
 *   - Tag yang panjang di-trim, salary aturan internasionalisasi.
 */
import { ref, reactive, onMounted, computed, watch, inject } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import careerApi, { fileUrl } from '@/api/careerPublic'

const route = useRoute()
const router = useRouter()
const slug = computed(() => route.params.slug)

const careerBrand = inject('careerBrand', null)

const loading = ref(true)
const error = ref(null)
const company = ref(null)
const page = ref(null)
const jobs = ref([])
const stats = ref({ open_jobs: 0, departments: 0, cities: 0 })

const filters = reactive({
  q: '',
  city: '',
  department: '',
  employment_type: '',
  work_arrangement: '',
})

const filteredJobs = computed(() => {
  const q = filters.q.trim().toLowerCase()
  return jobs.value.filter((j) => {
    if (q && !`${j.title} ${j.department_name || ''} ${(j.tags || []).join(' ')}`
        .toLowerCase().includes(q)) return false
    if (filters.city && j.city !== filters.city) return false
    if (filters.department && j.department_name !== filters.department) return false
    if (filters.employment_type && j.employment_type !== filters.employment_type) return false
    if (filters.work_arrangement && j.work_arrangement !== filters.work_arrangement) return false
    return true
  })
})

const cityOptions = computed(() =>
  Array.from(new Set(jobs.value.map((j) => j.city).filter(Boolean))).sort()
)
const departmentOptions = computed(() =>
  Array.from(new Set(jobs.value.map((j) => j.department_name).filter(Boolean))).sort()
)
const employmentOptions = computed(() =>
  Array.from(new Set(jobs.value.map((j) => j.employment_type).filter(Boolean))).sort()
)

const hasActiveFilter = computed(() =>
  filters.q || filters.city || filters.department
  || filters.employment_type || filters.work_arrangement,
)

function resetFilters() {
  Object.assign(filters, { q: '', city: '', department: '', employment_type: '', work_arrangement: '' })
}

async function fetchData() {
  loading.value = true
  error.value = null
  try {
    const res = await careerApi.get(`/careers/${slug.value}`)
    const data = res.data.data
    company.value = data.company
    page.value = data.page
    jobs.value = data.jobs || []
    stats.value = data.stats
    // Set brand (CSS var + layout)
    const brandColor = page.value?.brand_color || '#0F62FE'
    document.documentElement.style.setProperty('--brand-color', brandColor)
    if (careerBrand) {
      careerBrand.value = {
        name:     company.value.name,
        logo_url: company.value.logo_url || page.value?.logo_url || '',
        color:    brandColor,
      }
    }
    document.title = `Karier — ${company.value.name}`
  } catch (e) {
    error.value = e.response?.data?.message || 'Career portal tidak ditemukan'
  } finally {
    loading.value = false
  }
}

onMounted(fetchData)
watch(slug, fetchData)

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

/**
 * Relative time singkat untuk badge "diposting Xh lalu".
 * Kembalikan null kalau >30 hari (terlalu lama, tidak menarik ditampilkan).
 */
function relativeTime(iso) {
  if (! iso) return null
  const d = new Date(String(iso).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return null
  const diffMs = Date.now() - d.getTime()
  const m = Math.floor(diffMs / 60000)
  if (m < 60) return m <= 1 ? 'baru saja' : `${m} mnt lalu`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h} jam lalu`
  const days = Math.floor(h / 24)
  if (days <= 1) return 'kemarin'
  if (days <= 30) return `${days} hari lalu`
  return null
}

function openJob(job) {
  router.push(`/careers/${slug.value}/jobs/${job.public_slug}`)
}
</script>

<template>
  <!-- ── Skeleton loader ──────────────────────────────────────── -->
  <div v-if="loading" class="animate-pulse">
    <section class="bg-slate-200 h-64 sm:h-80"></section>
    <section class="max-w-6xl mx-auto px-4 py-8 space-y-3">
      <div class="h-12 bg-slate-200 rounded-xl"></div>
      <div v-for="n in 4" :key="n" class="h-28 bg-slate-200 rounded-xl"></div>
    </section>
  </div>

  <!-- ── Error ─────────────────────────────────────────────────── -->
  <div v-else-if="error" class="max-w-6xl mx-auto px-4 py-16 text-center">
    <div class="inline-flex flex-col items-center gap-3 bg-red-50 border border-red-200 text-red-700 rounded-lg px-6 py-5">
      <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
      </svg>
      <div class="font-semibold">{{ error }}</div>
      <p class="text-sm text-red-600/80">Pastikan tautan portal benar atau hubungi tim rekrutmen perusahaan.</p>
    </div>
  </div>

  <template v-else>
    <!-- ── Hero ────────────────────────────────────────────────── -->
    <section
      class="relative overflow-hidden text-white"
      :style="`background-image: linear-gradient(135deg, var(--brand-color, #0F62FE) 0%, color-mix(in srgb, var(--brand-color, #0F62FE) 65%, black) 100%)`"
    >
      <!-- decorative blob -->
      <div class="absolute -top-12 -right-12 w-72 h-72 rounded-full bg-white/10 blur-3xl pointer-events-none"></div>
      <div class="absolute -bottom-20 -left-10 w-72 h-72 rounded-full bg-black/10 blur-3xl pointer-events-none"></div>

      <div class="relative max-w-6xl mx-auto px-4 py-14 sm:py-20">
        <div class="max-w-3xl">
          <div class="text-sm uppercase tracking-wider opacity-80 mb-2">{{ company.name }}</div>
          <h1 class="text-3xl sm:text-5xl font-bold leading-tight mb-4">
            {{ page.headline || 'Bangun Karier Bersama Kami' }}
          </h1>
          <p v-if="page.tagline" class="text-base sm:text-lg opacity-90 mb-6 max-w-2xl">
            {{ page.tagline }}
          </p>
          <div v-if="page.announcement" class="inline-flex items-start gap-2 bg-white/15 backdrop-blur-sm rounded-lg px-4 py-3 text-sm">
            <span aria-hidden="true">📣</span>
            <span>{{ page.announcement }}</span>
          </div>

          <!-- Quick CTA -->
          <div class="mt-6 flex flex-wrap items-center gap-3">
            <a
              href="#jobs"
              class="inline-flex items-center gap-2 bg-white text-slate-900 hover:bg-slate-50 font-medium px-5 py-2.5 rounded-md text-sm transition shadow-sm"
            >
              Lihat lowongan
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5L12 21m0 0l-7.5-7.5M12 21V3" />
              </svg>
            </a>
            <router-link
              :to="`/careers/${slug}/track`"
              class="inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 backdrop-blur text-white font-medium px-5 py-2.5 rounded-md text-sm transition"
            >
              Lacak lamaran saya
            </router-link>
          </div>
        </div>

        <!-- Stats strip -->
        <div class="mt-10 grid grid-cols-3 gap-3 max-w-xl">
          <div class="bg-white/10 backdrop-blur rounded-lg px-4 py-3">
            <div class="text-2xl font-bold">{{ stats.open_jobs }}</div>
            <div class="text-[10px] sm:text-xs uppercase tracking-wider opacity-80">Lowongan</div>
          </div>
          <div class="bg-white/10 backdrop-blur rounded-lg px-4 py-3">
            <div class="text-2xl font-bold">{{ stats.departments }}</div>
            <div class="text-[10px] sm:text-xs uppercase tracking-wider opacity-80">Departemen</div>
          </div>
          <div class="bg-white/10 backdrop-blur rounded-lg px-4 py-3">
            <div class="text-2xl font-bold">{{ stats.cities }}</div>
            <div class="text-[10px] sm:text-xs uppercase tracking-wider opacity-80">Kota</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── About + Benefits ─────────────────────────────────────── -->
    <section v-if="page.about_html || page.benefits_html" class="max-w-6xl mx-auto px-4 py-12 grid md:grid-cols-2 gap-6">
      <div v-if="page.about_html" class="bg-white rounded-xl p-6 shadow-sm border border-slate-200">
        <h2 class="text-lg font-bold mb-3 flex items-center gap-2">
          <span class="text-[var(--brand-color,#0F62FE)]">🏢</span> Tentang Kami
        </h2>
        <div class="prose prose-sm max-w-none text-slate-700" v-html="page.about_html"></div>
      </div>
      <div v-if="page.benefits_html" class="bg-white rounded-xl p-6 shadow-sm border border-slate-200">
        <h2 class="text-lg font-bold mb-3 flex items-center gap-2">
          <span class="text-[var(--brand-color,#0F62FE)]">🎁</span> Benefit & Culture
        </h2>
        <div class="prose prose-sm max-w-none text-slate-700" v-html="page.benefits_html"></div>
      </div>
    </section>

    <!-- ── Jobs section ─────────────────────────────────────────── -->
    <section class="max-w-6xl mx-auto px-4 py-8">
      <div class="flex items-end justify-between mb-4 flex-wrap gap-2">
        <div>
          <h2 id="jobs" class="text-2xl font-bold">Lowongan Tersedia</h2>
          <div class="text-sm text-slate-500 mt-0.5">
            {{ filteredJobs.length }}
            <span v-if="filteredJobs.length !== jobs.length"> dari {{ jobs.length }}</span>
            lowongan
          </div>
        </div>
        <button
          v-if="hasActiveFilter"
          @click="resetFilters"
          class="text-sm text-[var(--brand-color,#0F62FE)] hover:underline inline-flex items-center gap-1"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
          </svg>
          Reset filter
        </button>
      </div>

      <!-- Filter bar -->
      <div class="bg-white rounded-xl p-3 sm:p-4 shadow-sm border border-slate-200 mb-6 grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="relative sm:col-span-2 lg:col-span-2">
          <svg class="w-4 h-4 absolute left-3 top-2.5 text-slate-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2m2.2-5.3a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z" />
          </svg>
          <input
            v-model="filters.q"
            type="text"
            placeholder="Cari posisi, departemen, atau tag..."
            class="w-full pl-9 pr-3 py-2 border border-slate-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand-color,#0F62FE)]/25 focus:border-[var(--brand-color,#0F62FE)]"
          />
        </div>
        <select v-model="filters.department" class="px-3 py-2 border border-slate-300 rounded-md text-sm bg-white">
          <option value="">Semua departemen</option>
          <option v-for="d in departmentOptions" :key="d" :value="d">{{ d }}</option>
        </select>
        <select v-model="filters.city" class="px-3 py-2 border border-slate-300 rounded-md text-sm bg-white">
          <option value="">Semua kota</option>
          <option v-for="c in cityOptions" :key="c" :value="c">{{ c }}</option>
        </select>
        <select v-model="filters.work_arrangement" class="px-3 py-2 border border-slate-300 rounded-md text-sm bg-white">
          <option value="">Semua mode kerja</option>
          <option value="WFO">On-site</option>
          <option value="WFH">Remote</option>
          <option value="HYBRID">Hybrid</option>
        </select>
      </div>

      <!-- Job cards / empty state -->
      <div v-if="filteredJobs.length === 0" class="bg-white rounded-xl p-10 sm:p-12 border border-dashed border-slate-300 text-center text-slate-500">
        <div class="text-5xl mb-3">🔍</div>
        <div class="font-medium text-slate-700 mb-1">
          {{ jobs.length === 0 ? 'Belum ada lowongan terbuka' : 'Tidak ada lowongan yang cocok' }}
        </div>
        <p class="text-sm">
          {{ jobs.length === 0
            ? 'Pantau halaman ini secara berkala atau hubungi tim rekrutmen di bawah.'
            : 'Coba kurangi filter atau gunakan kata kunci yang lebih umum.' }}
        </p>
        <button
          v-if="hasActiveFilter && jobs.length > 0"
          @click="resetFilters"
          class="mt-3 text-sm text-[var(--brand-color,#0F62FE)] hover:underline"
        >Reset semua filter</button>
      </div>

      <div v-else class="grid gap-3">
        <article
          v-for="job in filteredJobs"
          :key="job.id"
          @click="openJob(job)"
          @keyup.enter="openJob(job)"
          tabindex="0"
          role="link"
          class="bg-white rounded-xl border border-slate-200 p-4 sm:p-5 cursor-pointer hover:shadow-md hover:border-[var(--brand-color,#0F62FE)]/40 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color,#0F62FE)]/30 transition group"
        >
          <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="flex-1 min-w-0">
              <!-- Tags & posted ago -->
              <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                <span
                  v-if="relativeTime(job.posted_at || job.created_at)"
                  class="text-[10px] uppercase tracking-wide font-semibold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded"
                >
                  Baru · {{ relativeTime(job.posted_at || job.created_at) }}
                </span>
                <span
                  v-for="t in (job.tags || []).slice(0, 3)"
                  :key="t"
                  class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"
                >{{ t }}</span>
                <span v-if="(job.tags || []).length > 3" class="text-xs text-slate-400">
                  +{{ job.tags.length - 3 }}
                </span>
              </div>

              <h3 class="text-lg font-semibold text-slate-900 group-hover:text-[var(--brand-color,#0F62FE)] transition-colors">
                {{ job.title }}
              </h3>
              <div class="text-sm text-slate-500 mt-0.5">
                {{ job.department_name || '—' }}
                <span v-if="job.position_name"> · {{ job.position_name }}</span>
              </div>

              <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
                <span v-if="job.city" class="inline-flex items-center gap-1">
                  <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                  </svg>
                  {{ job.city }}
                </span>
                <span v-if="job.work_arrangement">💼 {{ workArrangementLabel(job.work_arrangement) }}</span>
                <span v-if="job.employment_type">📄 {{ employmentTypeLabel(job.employment_type) }}</span>
                <span v-if="job.min_education">🎓 Min {{ job.min_education }}</span>
                <span v-if="job.min_experience_years != null">⏳ {{ job.min_experience_years }}+ thn</span>
              </div>

              <div v-if="job.salary_visible && (job.salary_min || job.salary_max)" class="mt-2 text-sm font-medium text-emerald-700">
                💰 {{ fmtSalary(job.salary_min, job.salary_max) }} <span class="text-slate-400 font-normal">/ bulan</span>
              </div>
            </div>

            <button
              type="button"
              tabindex="-1"
              class="px-4 py-2 rounded-md bg-[var(--brand-color,#0F62FE)] text-white text-sm font-medium hover:opacity-90 transition shrink-0 inline-flex items-center gap-1"
            >
              Lihat Detail
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
              </svg>
            </button>
          </div>
        </article>
      </div>
    </section>

    <!-- ── Contact ─────────────────────────────────────────────── -->
    <section v-if="page.contact_email || page.contact_phone || page.linkedin_url || page.instagram_url" class="max-w-6xl mx-auto px-4 pb-12">
      <div class="bg-white rounded-xl p-6 shadow-sm border border-slate-200 text-center">
        <h2 class="text-lg font-bold mb-1">Punya pertanyaan?</h2>
        <p class="text-slate-600 text-sm mb-4">Hubungi tim rekrutmen kami</p>
        <div class="flex flex-wrap items-center justify-center gap-3 text-sm">
          <a v-if="page.contact_email" :href="`mailto:${page.contact_email}`" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-200 hover:border-[var(--brand-color,#0F62FE)]/40 hover:bg-[var(--brand-color,#0F62FE)]/5 transition">
            ✉ {{ page.contact_email }}
          </a>
          <a v-if="page.contact_phone" :href="`tel:${page.contact_phone}`" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-200 hover:border-[var(--brand-color,#0F62FE)]/40 hover:bg-[var(--brand-color,#0F62FE)]/5 transition">
            📞 {{ page.contact_phone }}
          </a>
          <a v-if="page.linkedin_url" :href="page.linkedin_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-200 hover:border-[var(--brand-color,#0F62FE)]/40 hover:bg-[var(--brand-color,#0F62FE)]/5 transition">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
            LinkedIn
          </a>
          <a v-if="page.instagram_url" :href="page.instagram_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-slate-200 hover:border-[var(--brand-color,#0F62FE)]/40 hover:bg-[var(--brand-color,#0F62FE)]/5 transition">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
            Instagram
          </a>
        </div>
      </div>
    </section>
  </template>
</template>

<style>
.prose ul { list-style: disc; padding-left: 1.5rem; }
.prose ol { list-style: decimal; padding-left: 1.5rem; }
.prose p { margin: 0.5rem 0; }
.prose h4 { font-weight: 600; margin-top: 0.75rem; margin-bottom: 0.25rem; }
</style>