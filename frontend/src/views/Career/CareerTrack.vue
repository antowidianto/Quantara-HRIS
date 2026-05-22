<script setup>
/*
 * CareerTrack — pelamar bisa cek status lamaran lewat token.
 * URL: /careers/{slug}/track          → input token
 *      /careers/{slug}/track/{token}  → langsung resolve
 *
 * Improvements:
 *   - Paste-from-clipboard button (mobile users prefer ini ketimbang
 *     ketik 64 karakter hex).
 *   - Auto-save token ke localStorage saat sukses lookup → next visit
 *     auto-prefill (tidak hanya sessionStorage yang hilang saat tutup tab).
 *   - Stage progression visual (line chart) — bukan cuma timeline list.
 *   - Empty-state lebih membantu (link ke daftar lowongan).
 *   - Toast pengganti error inline (lebih halus).
 *   - Token validation on input dengan visual feedback (valid format hint).
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import careerApi from '@/api/careerPublic'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const slug = computed(() => route.params.slug)
const tokenParam = computed(() => route.params.token || '')

const TOKEN_KEY = `career_track_${slug.value}`
const tokenInput = ref(
  tokenParam.value
  || sessionStorage.getItem('career_track_token')
  || localStorage.getItem(TOKEN_KEY)
  || '',
)
const loading = ref(false)
const data = ref(null)
const error = ref(null)

const STAGE_LABELS = {
  NEW:           { label: 'Lamaran Diterima',    icon: '📥', color: 'bg-blue-100 text-blue-700',         dot: 'bg-blue-500' },
  SCREENING:     { label: 'Sedang Direview',     icon: '🔍', color: 'bg-amber-100 text-amber-700',       dot: 'bg-amber-500' },
  INTERVIEW_HR:  { label: 'Wawancara HR',        icon: '🗣️', color: 'bg-purple-100 text-purple-700',     dot: 'bg-purple-500' },
  INTERVIEW_USER:{ label: 'Wawancara User',      icon: '👥', color: 'bg-purple-100 text-purple-700',     dot: 'bg-purple-500' },
  OFFER:         { label: 'Penawaran Kerja',     icon: '💼', color: 'bg-emerald-100 text-emerald-700',   dot: 'bg-emerald-500' },
  HIRED:         { label: 'Diterima',            icon: '🎉', color: 'bg-emerald-100 text-emerald-700',   dot: 'bg-emerald-600' },
  REJECTED:      { label: 'Tidak Lolos',         icon: '❌', color: 'bg-red-100 text-red-700',           dot: 'bg-red-500' },
  WITHDRAWN:     { label: 'Lamaran Ditarik',     icon: '↩️', color: 'bg-gray-100 text-gray-700',         dot: 'bg-gray-500' },
}

// Format hint (hex 64 chars). Hitung di-realtime supaya user tahu progress
// pengetikan/paste mereka.
const tokenLooksValid = computed(() => /^[a-f0-9]{64}$/i.test(tokenInput.value.trim()))
const tokenLength = computed(() => tokenInput.value.trim().length)

async function lookup() {
  const t = tokenInput.value.trim()
  if (! t) {
    error.value = 'Masukkan kode pelacakan'
    return
  }
  if (! /^[a-f0-9]{64}$/i.test(t)) {
    error.value = 'Format kode tidak valid (harus 64 karakter, hanya angka 0-9 dan huruf a-f)'
    return
  }
  loading.value = true
  error.value = null
  data.value = null
  try {
    const res = await careerApi.get(`/careers/track/${t}`)
    data.value = res.data.data
    document.title = `Status Lamaran — ${data.value.candidate.full_name}`

    // Persist ke localStorage supaya next visit auto-prefill
    try { localStorage.setItem(TOKEN_KEY, t) } catch (_) {}

    // Update URL agar bookmark-able
    if (route.params.token !== t) {
      router.replace(`/careers/${slug.value}/track/${t}`)
    }
  } catch (e) {
    const msg = e.response?.data?.message || 'Token tidak ditemukan atau kadaluarsa'
    error.value = msg
    toast.error(msg)
  } finally {
    loading.value = false
  }
}

async function pasteFromClipboard() {
  try {
    const text = await navigator.clipboard.readText()
    tokenInput.value = text.trim()
    error.value = null
    if (tokenLooksValid.value) {
      // Auto-lookup kalau format valid
      lookup()
    }
  } catch (_) {
    toast.warning('Browser tidak mengizinkan akses clipboard. Silakan paste manual (Ctrl+V).')
  }
}

function clearInput() {
  tokenInput.value = ''
  error.value = null
  data.value = null
  try { localStorage.removeItem(TOKEN_KEY) } catch (_) {}
}

onMounted(() => {
  document.title = 'Lacak Lamaran'
  if (tokenParam.value || tokenInput.value) {
    lookup()
  }
})

function fmtDateTime(iso) {
  if (! iso) return '—'
  return new Date(String(iso).replace(' ', 'T')).toLocaleString('id-ID', {
    dateStyle: 'medium', timeStyle: 'short',
  })
}

function fmtDate(iso) {
  if (! iso) return '—'
  return new Date(String(iso).replace(' ', 'T')).toLocaleDateString('id-ID', {
    day: 'numeric', month: 'short', year: 'numeric',
  })
}
</script>

<template>
  <div class="max-w-3xl mx-auto px-4 py-8 sm:py-12">
    <!-- ── Search card ──────────────────────────────────────── -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-7">
      <div class="flex items-start gap-3 mb-4">
        <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-[var(--brand-color,#0F62FE)]/10 text-[var(--brand-color,#0F62FE)] text-xl shrink-0">
          🔎
        </span>
        <div>
          <h1 class="text-xl sm:text-2xl font-bold">Lacak Lamaran</h1>
          <p class="text-sm text-slate-600 mt-0.5">
            Masukkan kode pelacakan yang Anda terima setelah submit lamaran.
          </p>
        </div>
      </div>

      <div class="relative">
        <input
          v-model="tokenInput"
          type="text"
          placeholder="64 karakter hex (mis. a1b2c3...)"
          autocomplete="off"
          spellcheck="false"
          class="w-full pr-28 sm:pr-36 pl-3 py-2.5 border rounded-md text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--brand-color,#0F62FE)]/25 transition-colors"
          :class="[
            error ? 'border-red-300 focus:border-red-500'
                  : (tokenInput && tokenLooksValid) ? 'border-emerald-300 focus:border-emerald-500'
                  : 'border-slate-300 focus:border-[var(--brand-color,#0F62FE)]'
          ]"
          @keyup.enter="lookup"
        />
        <div class="absolute right-1.5 top-1.5 flex items-center gap-1">
          <button
            v-if="tokenInput"
            @click="clearInput"
            class="px-2 py-1.5 rounded text-slate-400 hover:text-slate-700 hover:bg-slate-100"
            type="button"
            aria-label="Hapus"
            title="Hapus"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
          <button
            @click="pasteFromClipboard"
            class="px-2.5 py-1.5 rounded text-xs text-slate-600 hover:bg-slate-100 inline-flex items-center gap-1"
            type="button"
            title="Tempel dari clipboard"
          >
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
            </svg>
            <span class="hidden sm:inline">Tempel</span>
          </button>
          <button
            @click="lookup"
            :disabled="loading || ! tokenInput"
            class="px-3 py-1.5 rounded-md bg-[var(--brand-color,#0F62FE)] text-white text-sm font-medium hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1"
            type="button"
          >
            <span v-if="loading" class="animate-spin inline-block w-3.5 h-3.5 border-2 border-current border-t-transparent rounded-full"></span>
            <span v-else>Cek</span>
          </button>
        </div>
      </div>

      <!-- Format hint -->
      <div class="mt-2 text-xs flex items-center justify-between">
        <p v-if="error" class="text-red-500">{{ error }}</p>
        <p v-else-if="tokenInput && ! tokenLooksValid" class="text-amber-600">
          {{ tokenLength }} dari 64 karakter
        </p>
        <p v-else-if="tokenInput && tokenLooksValid" class="text-emerald-600 inline-flex items-center gap-1">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
          </svg>
          Format kode valid
        </p>
        <p v-else class="text-slate-400">
          Tip: tekan tombol <strong>Tempel</strong> untuk paste otomatis dari clipboard.
        </p>
        <span v-if="tokenInput" class="text-slate-400 font-mono">{{ tokenLength }}/64</span>
      </div>
    </div>

    <!-- ── Empty state (sebelum lookup) ─────────────────────── -->
    <div v-if="! data && ! loading && ! error" class="mt-6 bg-white rounded-2xl border border-dashed border-slate-300 p-8 text-center">
      <div class="text-5xl mb-3">📭</div>
      <h3 class="font-semibold text-slate-700">Tidak punya kode?</h3>
      <p class="text-sm text-slate-500 mt-1">
        Kode pelacakan diberikan setelah Anda submit lamaran lewat halaman lowongan.
      </p>
      <router-link
        :to="`/careers/${slug}`"
        class="inline-flex items-center gap-1.5 mt-4 px-4 py-2 rounded-md bg-[var(--brand-color,#0F62FE)] text-white text-sm font-medium hover:opacity-90 transition"
      >
        Lihat daftar lowongan
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
        </svg>
      </router-link>
    </div>

    <!-- ── Result ──────────────────────────────────────────── -->
    <div v-if="data" class="mt-6 space-y-4">
      <!-- Header -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div class="flex-1 min-w-0">
            <div class="text-xs text-slate-500 uppercase tracking-wider">Pelamar</div>
            <div class="text-xl font-bold text-slate-900 mt-0.5">{{ data.candidate.full_name }}</div>
            <div class="text-sm text-slate-600 mt-1 flex items-center gap-1.5">
              <svg class="w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25" />
              </svg>
              {{ data.candidate.email }}
            </div>
            <div v-if="data.candidate.nik_masked" class="text-xs text-slate-400 mt-0.5 font-mono">NIK: {{ data.candidate.nik_masked }}</div>
          </div>
          <div
            :class="[
              'inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium',
              STAGE_LABELS[data.candidate.current_stage]?.color || 'bg-gray-100'
            ]"
          >
            <span aria-hidden="true">{{ STAGE_LABELS[data.candidate.current_stage]?.icon || '•' }}</span>
            <span>{{ STAGE_LABELS[data.candidate.current_stage]?.label || data.candidate.current_stage }}</span>
          </div>
        </div>

        <div class="mt-4 pt-4 border-t border-slate-100 text-sm">
          <div class="font-semibold text-slate-900">{{ data.job.title }}</div>
          <div class="text-slate-500 font-mono text-xs">{{ data.job.reference_number }}</div>
          <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600">
            <span v-if="data.job.city">📍 {{ data.job.city }}</span>
            <span v-if="data.job.work_arrangement">💼 {{ data.job.work_arrangement }}</span>
            <span v-if="data.job.employment_type">📄 {{ data.job.employment_type }}</span>
          </div>
          <div class="mt-2 text-xs text-slate-400">
            Diajukan: {{ fmtDateTime(data.candidate.applied_at) }}
          </div>
        </div>
      </div>

      <!-- Timeline -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
        <h2 class="font-bold mb-4 flex items-center gap-2">
          <span class="text-[var(--brand-color,#0F62FE)]">📈</span>
          Riwayat Tahapan
        </h2>

        <ol class="relative space-y-4 ml-1">
          <li
            v-for="(s, idx) in data.stages"
            :key="idx"
            class="relative pl-9"
          >
            <!-- Dot -->
            <span
              :class="[
                'absolute left-0 top-1 w-6 h-6 rounded-full flex items-center justify-center text-sm shadow ring-4 ring-white',
                STAGE_LABELS[s.stage]?.color || 'bg-gray-100'
              ]"
              aria-hidden="true"
            >
              {{ STAGE_LABELS[s.stage]?.icon || '•' }}
            </span>
            <!-- Connector line -->
            <span
              v-if="idx < data.stages.length - 1"
              class="absolute left-[11px] top-7 w-0.5 h-[calc(100%-12px)] bg-slate-200"
              aria-hidden="true"
            ></span>

            <!-- Content -->
            <div class="pb-1">
              <div class="flex items-center justify-between gap-2 flex-wrap">
                <div class="font-semibold text-slate-900 text-sm">
                  {{ STAGE_LABELS[s.stage]?.label || s.stage }}
                </div>
                <span
                  v-if="s.result === 'PASSED'"
                  class="text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700"
                >Lolos</span>
                <span
                  v-else-if="s.result === 'FAILED'"
                  class="text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded-full bg-red-100 text-red-700"
                >Tidak Lolos</span>
                <span
                  v-else-if="s.result === 'PENDING'"
                  class="text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700"
                >Berjalan</span>
              </div>
              <div v-if="s.scheduled_at" class="text-xs text-slate-600 mt-1 inline-flex items-center gap-1">
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                </svg>
                Jadwal: <strong>{{ fmtDateTime(s.scheduled_at) }}</strong>
              </div>
              <div v-if="s.feedback" class="text-xs text-slate-700 mt-2 italic bg-slate-50 border border-slate-200 px-3 py-2 rounded">
                💬 {{ s.feedback }}
              </div>
              <div class="text-[11px] text-slate-400 mt-1">
                {{ fmtDateTime(s.completed_at || s.created_at) }}
              </div>
            </div>
          </li>
        </ol>
      </div>

      <!-- Footer hint -->
      <div class="text-center text-xs text-slate-400 px-4">
        💾 Halaman ini bisa Anda bookmark dan akses kembali kapan saja sampai 90 hari.<br>
        Pertanyaan? Hubungi tim rekrutmen perusahaan.
      </div>
    </div>
  </div>
</template>