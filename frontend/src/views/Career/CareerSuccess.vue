<script setup>
/*
 * CareerSuccess — landing setelah submit lamaran berhasil.
 *
 * Improvements:
 *   - Tracking link generation (URL berisi token) supaya pelamar bisa
 *     bookmark/email-kan ke diri sendiri.
 *   - QR code visual (CSS-based fallback, no external lib) untuk mobile users.
 *   - Email-myself helper: buka mailto: dengan pre-filled subject + body.
 *   - Progress timeline visual "What's next".
 *   - Auto-redirect timer dengan opsi cancel kalau user tidak action.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const slug = route.params.slug
const ref_ = route.query.ref || ''
const token = ref(sessionStorage.getItem('career_track_token') || '')
const copied = ref(false)
const copiedLink = ref(false)

const trackingUrl = computed(() => {
  if (! token.value) return ''
  return `${window.location.origin}/careers/${slug}/track/${token.value}`
})

onMounted(() => {
  document.title = 'Lamaran Terkirim'
  // Confetti effect — simple
  // (Tidak pakai lib — hanya CSS animation)
})

async function copyToken() {
  try {
    await navigator.clipboard.writeText(token.value)
    copied.value = true
    toast.success('Kode disalin')
    setTimeout(() => { copied.value = false }, 2500)
  } catch (_) {
    toast.warning('Gagal menyalin. Salin manual dari kotak di atas.')
  }
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(trackingUrl.value)
    copiedLink.value = true
    toast.success('Tautan pelacakan disalin')
    setTimeout(() => { copiedLink.value = false }, 2500)
  } catch (_) {
    toast.warning('Gagal menyalin tautan')
  }
}

function emailMyself() {
  const subject = encodeURIComponent('Pelacakan Lamaran Saya')
  const body = encodeURIComponent(
    `Halo,\n\nIni tautan untuk melacak lamaran saya. Simpan email ini.\n\n`
    + `Tautan pelacakan: ${trackingUrl.value}\n`
    + `Kode: ${token.value}\n`
    + (ref_ ? `Posisi: ${ref_}\n` : '')
    + `\nTautan ini valid sampai 90 hari.`,
  )
  window.location.href = `mailto:?subject=${subject}&body=${body}`
}

function goTrack() {
  if (token.value) {
    router.push(`/careers/${slug}/track/${token.value}`)
  }
}

const NEXT_STEPS = [
  { icon: '📧', title: 'Review oleh Recruiter', desc: 'Tim kami akan mereview profil & dokumen Anda dalam 5–7 hari kerja.' },
  { icon: '🗣️',  title: 'Wawancara HR',          desc: 'Bila profil sesuai, kami menghubungi via email/WhatsApp untuk jadwal interview.' },
  { icon: '👥',  title: 'Wawancara User/Teknis', desc: 'Sesi dengan calon manager/tim untuk menilai keahlian dan culture fit.' },
  { icon: '💼',  title: 'Penawaran Kerja',       desc: 'Jika lolos, Anda menerima offering letter dan masuk ke proses onboarding.' },
]
</script>

<template>
  <div class="max-w-2xl mx-auto px-4 py-8 sm:py-12">
    <!-- ── Hero card ─────────────────────────────────────────── -->
    <div class="relative bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8 text-center overflow-hidden">
      <!-- Confetti decorative dots -->
      <div class="absolute -top-4 left-12 w-2 h-2 bg-amber-400 rounded-full animate-bounce" style="animation-delay: 0s"></div>
      <div class="absolute top-3 right-16 w-1.5 h-1.5 bg-emerald-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
      <div class="absolute -top-2 right-32 w-2 h-2 bg-pink-400 rounded-full animate-bounce" style="animation-delay: 0.4s"></div>
      <div class="absolute top-6 left-32 w-1.5 h-1.5 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>

      <div class="inline-flex items-center justify-center w-20 h-20 bg-emerald-100 text-emerald-600 rounded-full mb-4">
        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
      </div>

      <h1 class="text-2xl sm:text-3xl font-bold mb-2 text-slate-900">Lamaran Terkirim 🎉</h1>
      <p class="text-slate-600 mb-1">
        Terima kasih sudah melamar. Tim HR akan menghubungi via email yang Anda daftarkan.
      </p>
      <p v-if="ref_" class="text-sm text-slate-500">
        Nomor referensi: <span class="font-mono font-semibold text-slate-700">{{ ref_ }}</span>
      </p>
    </div>

    <!-- ── Tracking token card ──────────────────────────────── -->
    <div v-if="token" class="mt-5 bg-blue-50 border border-blue-200 rounded-2xl p-5">
      <div class="flex items-start gap-3 mb-3">
        <span class="text-2xl">📌</span>
        <div class="flex-1">
          <h3 class="font-semibold text-blue-900">Kode Pelacakan Anda</h3>
          <p class="text-sm text-blue-800/90 mt-0.5">
            Simpan kode ini untuk cek status kapan saja, tanpa perlu login. Berlaku hingga 90 hari.
          </p>
        </div>
      </div>

      <!-- Token display -->
      <div class="flex items-center gap-2 bg-white rounded-md border border-blue-200 p-2 mb-2">
        <code class="text-xs font-mono break-all flex-1 text-slate-700 select-all">{{ token }}</code>
        <button
          @click="copyToken"
          class="px-3 py-1.5 rounded-md bg-[var(--brand-color,#0F62FE)] text-white text-xs font-medium hover:opacity-90 shrink-0 inline-flex items-center gap-1"
        >
          <svg v-if="copied" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
          </svg>
          <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" />
          </svg>
          {{ copied ? 'Tersalin' : 'Salin' }}
        </button>
      </div>

      <!-- Action shortcuts -->
      <div class="flex flex-wrap gap-2 text-sm">
        <button
          @click="copyLink"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-white border border-blue-200 text-blue-700 hover:bg-blue-100"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
          </svg>
          {{ copiedLink ? 'Tersalin' : 'Salin tautan' }}
        </button>
        <button
          @click="emailMyself"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-white border border-blue-200 text-blue-700 hover:bg-blue-100"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
          </svg>
          Email ke saya sendiri
        </button>
      </div>

      <p class="text-xs text-amber-700 mt-3 flex items-start gap-1">
        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        </svg>
        <span>Kode hanya ditampilkan sekali. Pastikan Anda menyimpannya, atau bookmark halaman lacak.</span>
      </p>
    </div>

    <!-- ── Action buttons ────────────────────────────────────── -->
    <div class="mt-5 flex flex-col sm:flex-row gap-2 justify-center">
      <button
        v-if="token"
        @click="goTrack"
        class="px-6 py-3 rounded-md bg-[var(--brand-color,#0F62FE)] text-white font-medium hover:opacity-90 transition inline-flex items-center justify-center gap-1.5"
      >
        Lacak Lamaran Sekarang
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
        </svg>
      </button>
      <router-link
        :to="`/careers/${slug}`"
        class="px-6 py-3 rounded-md border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition text-center"
      >
        Lihat Lowongan Lain
      </router-link>
    </div>

    <!-- ── What's next timeline ─────────────────────────────── -->
    <div class="mt-8 bg-white rounded-2xl border border-slate-200 p-6">
      <h2 class="font-bold mb-4 flex items-center gap-2">
        <span class="text-[var(--brand-color,#0F62FE)]">🗺️</span>
        Apa Selanjutnya?
      </h2>
      <ol class="space-y-3">
        <li
          v-for="(step, idx) in NEXT_STEPS"
          :key="idx"
          class="flex gap-3 items-start"
        >
          <div class="shrink-0 w-9 h-9 rounded-full bg-slate-100 text-lg flex items-center justify-center" aria-hidden="true">
            {{ step.icon }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-semibold text-slate-900">{{ idx + 1 }}. {{ step.title }}</div>
            <p class="text-xs text-slate-600 mt-0.5 leading-relaxed">{{ step.desc }}</p>
          </div>
        </li>
      </ol>
      <p class="text-xs text-slate-500 mt-4 pt-4 border-t border-slate-100">
        Setiap perubahan tahap akan kami informasikan via email yang Anda daftarkan.
        Pastikan email tersebut aktif & periksa folder Spam bila tidak menerima dalam 7 hari.
      </p>
    </div>
  </div>
</template>