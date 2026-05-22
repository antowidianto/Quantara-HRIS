<script setup>
/*
 * CareerPublicLayout — wrapper public career portal.
 *
 * Filosofi:
 *   - Header bersih: logo perusahaan (bila ada) + nama, bukan ikon generik.
 *     Pelamar harus tahu jelas perusahaan apa yang dia lamar.
 *   - Nav minimal: 2 link (Lowongan, Lacak Lamaran). Active state jelas.
 *   - Mobile-first: nav tidak overflow, footer collapse rapi.
 *   - Brand color via CSS var (--brand-color) — di-set oleh halaman child
 *     setelah resolve company.
 *
 * Anak page bisa override branding lewat `provide('careerBrand', {...})`
 * (lihat CareerHome.vue) — layout cuma render container + nav.
 */
import { ref, computed, provide } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const companySlug = computed(() => route.params.slug || '')

// Brand state — child page (CareerHome / CareerJobDetail / CareerApply)
// inject `careerBrand` lalu set company.value setelah fetch berhasil. Ini
// supaya layout tidak perlu fetch ulang per route change.
const brand = ref({ name: '', logo_url: '', color: '' })
provide('careerBrand', brand)

function goHome() {
  if (companySlug.value) router.push(`/careers/${companySlug.value}`)
}

const isHome  = computed(() => route.name === 'CareerHome')
const isTrack = computed(() => route.name === 'CareerTrack')

const year = new Date().getFullYear()
</script>

<template>
  <div class="min-h-screen flex flex-col bg-slate-50 text-slate-900">
    <!-- ── Header ────────────────────────────────────────────────── -->
    <header class="bg-white/95 backdrop-blur border-b border-slate-200 sticky top-0 z-30">
      <div class="max-w-6xl mx-auto px-4 h-14 flex items-center justify-between gap-4">
        <!-- Brand -->
        <button
          @click="goHome"
          class="flex items-center gap-2.5 hover:opacity-80 transition min-w-0"
          :title="brand.name || 'Career portal'"
        >
          <span
            v-if="brand.logo_url"
            class="inline-flex items-center justify-center w-9 h-9 rounded-lg overflow-hidden bg-slate-100 shrink-0"
          >
            <img :src="brand.logo_url" :alt="brand.name" class="w-full h-full object-contain" />
          </span>
          <span
            v-else
            class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-[var(--brand-color,#0F62FE)] text-white shrink-0"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
          </span>
          <span class="flex flex-col items-start min-w-0">
            <span class="font-semibold text-sm leading-tight truncate max-w-[180px] sm:max-w-none">
              {{ brand.name || 'Career Portal' }}
            </span>
            <span class="text-[10px] uppercase tracking-wider text-slate-500 leading-none">Karier</span>
          </span>
        </button>

        <!-- Nav -->
        <nav class="flex items-center gap-1 text-sm shrink-0">
          <router-link
            :to="`/careers/${companySlug}`"
            class="px-3 py-1.5 rounded-md transition-colors"
            :class="isHome
              ? 'bg-[var(--brand-color,#0F62FE)]/10 text-[var(--brand-color,#0F62FE)] font-medium'
              : 'text-slate-700 hover:bg-slate-100'"
          >
            <span class="hidden sm:inline">Lowongan</span>
            <svg class="sm:hidden w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
          </router-link>
          <router-link
            :to="`/careers/${companySlug}/track`"
            class="px-3 py-1.5 rounded-md transition-colors"
            :class="isTrack
              ? 'bg-[var(--brand-color,#0F62FE)]/10 text-[var(--brand-color,#0F62FE)] font-medium'
              : 'text-slate-700 hover:bg-slate-100'"
          >
            <span class="hidden sm:inline">Lacak Lamaran</span>
            <svg class="sm:hidden w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2m2.2-5.3a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z" />
            </svg>
          </router-link>
        </nav>
      </div>
    </header>

    <!-- ── Content ──────────────────────────────────────────────── -->
    <main class="flex-1">
      <router-view />
    </main>

    <!-- ── Footer ──────────────────────────────────────────────── -->
    <footer class="bg-white border-t border-slate-200 mt-12">
      <div class="max-w-6xl mx-auto px-4 py-6 text-sm text-slate-500 flex flex-col sm:flex-row items-center justify-between gap-3">
        <div class="text-center sm:text-left">
          © {{ year }} {{ brand.name || 'Career Portal' }}
          <span class="block sm:inline sm:ml-1">— Powered by Quantara HRIS</span>
        </div>
        <div class="flex items-center gap-4 text-xs">
          <span class="text-slate-400">Data Anda dilindungi</span>
          <span class="inline-flex items-center gap-1 text-emerald-600">
            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
            SSL Encrypted
          </span>
        </div>
      </div>
    </footer>
  </div>
</template>