<script setup>
/**
 * PwaBanner — banner notifikasi untuk fitur PWA:
 *   1. Offline indicator (sticky top)
 *   2. Install app prompt (kalau belum installed dan support)
 *   3. Update available banner (kalau SW baru ready)
 *   4. Queue status feedback (kalau queue offline ter-flush)
 */
import { ref, onMounted, watch } from 'vue'
import { usePwa } from '@/composables/usePwa'
import { useToast } from 'vue-toastification'
import {
  WifiIcon, ArrowDownTrayIcon, ArrowPathIcon, XMarkIcon,
  CheckCircleIcon,
} from '@heroicons/vue/24/outline'

const pwa = usePwa()
const toast = useToast()
const installDismissed = ref(false)

onMounted(() => {
  pwa.register()
  // Restore dismissed state dari localStorage
  installDismissed.value = localStorage.getItem('pwa.install_dismissed') === '1'
})

// Toast saat queue ter-flush
watch(() => pwa.queueStatus.value.ts, (ts) => {
  if (! ts) return
  const { success, failed, remaining } = pwa.queueStatus.value
  if (success > 0) {
    toast.success(`✓ ${success} permintaan offline berhasil dikirim`)
  }
  if (failed > 0) {
    toast.warning(`⚠ ${failed} gagal, ${remaining} masih dalam antrian`)
  }
})

// Toast saat status online berubah
watch(pwa.isOnline, (online) => {
  if (online) toast.success('Kembali online — sinkronisasi data...')
  else toast.warning('Anda offline. Aksi akan dikirim saat online.')
})

async function doInstall() {
  const ok = await pwa.promptInstall()
  if (ok) toast.success('App terpasang di home screen')
}

function dismissInstall() {
  installDismissed.value = true
  localStorage.setItem('pwa.install_dismissed', '1')
}

function applyUpdate() {
  pwa.applyUpdate()
}
</script>

<template>
  <!-- Offline indicator -->
  <div v-if="!pwa.isOnline.value"
    class="fixed top-0 left-0 right-0 z-[100] bg-amber-500 text-white text-center py-1.5 text-xs flex items-center justify-center gap-2 shadow">
    <WifiIcon class="w-4 h-4" />
    Anda sedang offline — beberapa fitur terbatas, aksi akan disinkronisasi saat online
  </div>

  <!-- Update available banner -->
  <div v-if="pwa.updateAvailable.value"
    class="fixed bottom-4 right-4 z-[100] bg-blue-600 text-white rounded-lg shadow-lg p-4 max-w-sm">
    <div class="flex items-start gap-3">
      <ArrowPathIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
      <div class="flex-1">
        <div class="font-semibold text-sm">Versi baru tersedia</div>
        <div class="text-xs opacity-90 mt-1">Reload halaman untuk pakai versi terbaru.</div>
        <div class="mt-2 flex gap-2">
          <button class="bg-white text-blue-600 px-3 py-1 rounded text-xs font-medium hover:bg-gray-100"
            @click="applyUpdate">
            Reload Sekarang
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Install prompt banner -->
  <div v-if="pwa.canInstall.value && !installDismissed"
    class="fixed bottom-4 left-4 z-[100] bg-emerald-600 text-white rounded-lg shadow-lg p-4 max-w-sm">
    <div class="flex items-start gap-3">
      <ArrowDownTrayIcon class="w-5 h-5 flex-shrink-0 mt-0.5" />
      <div class="flex-1">
        <div class="font-semibold text-sm">Pasang HRIS di perangkat Anda</div>
        <div class="text-xs opacity-90 mt-1">Akses cepat lewat home screen, bisa offline.</div>
        <div class="mt-2 flex gap-2">
          <button class="bg-white text-emerald-700 px-3 py-1 rounded text-xs font-medium hover:bg-gray-100"
            @click="doInstall">
            Pasang
          </button>
          <button class="text-white/80 hover:text-white px-3 py-1 rounded text-xs"
            @click="dismissInstall">
            Nanti
          </button>
        </div>
      </div>
      <button class="text-white/60 hover:text-white" @click="dismissInstall">
        <XMarkIcon class="w-4 h-4" />
      </button>
    </div>
  </div>
</template>