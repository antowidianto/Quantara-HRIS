<script setup>
/**
 * S3.4 SaaS — "What's New" Announcement Modal.
 *
 * Polling endpoint /announcements/unread setiap kali user mount AppLayout
 * (juga setelah login). Bila ada announcement belum dibaca dengan target
 * plan match → tampilkan modal cards (queue, satu per satu).
 *
 * User klik "Mark as Read" → POST /announcements/{id}/read
 * User klik CTA tombol → POST /announcements/{id}/read dengan cta_clicked=1
 *
 * Mount di AppLayout.vue (di paling akhir <template> sebelum penutup div root).
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/api/client'
import { XMarkIcon, MegaphoneIcon, ArrowRightIcon } from '@heroicons/vue/24/outline'

const announcements = ref([])
const currentIndex = ref(0)
const dismissed = ref(false)
const loading = ref(false)

const current = computed(() => announcements.value[currentIndex.value] || null)
const hasMore = computed(() => currentIndex.value < announcements.value.length - 1)
const showModal = computed(() => current.value && ! dismissed.value)

async function loadAnnouncements() {
  loading.value = true
  try {
    const res = await api.get('/announcements/unread')
    announcements.value = res.data?.data || []
    currentIndex.value = 0
  } catch {
    // Endpoint tidak ada / tenant belum punya data → silent
    announcements.value = []
  } finally {
    loading.value = false
  }
}

async function markRead(ctaClicked = false) {
  if (! current.value) return
  const id = current.value.id
  try {
    await api.post(`/announcements/${id}/read`, { cta_clicked: ctaClicked })
  } catch {
    // ignore — kalau gagal, tetap lanjut UI flow
  }
}

async function handleCta() {
  if (! current.value?.cta_url) return
  await markRead(true)
  // Internal link → router push (kalau pakai); external → window.open
  const url = current.value.cta_url
  if (url.startsWith('http://') || url.startsWith('https://')) {
    window.open(url, '_blank')
  } else {
    window.location.href = url
  }
  goNextOrClose()
}

async function dismissCurrent() {
  await markRead(false)
  goNextOrClose()
}

function goNextOrClose() {
  if (hasMore.value) {
    currentIndex.value++
  } else {
    dismissed.value = true
  }
}

function formatDate(d) {
  if (! d) return ''
  return new Date(d).toLocaleDateString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
  })
}

onMounted(loadAnnouncements)
</script>

<template>
  <transition
    enter-active-class="transition duration-200"
    enter-from-class="opacity-0"
    enter-to-class="opacity-100"
    leave-active-class="transition duration-150"
    leave-from-class="opacity-100"
    leave-to-class="opacity-0"
  >
    <div
      v-if="showModal"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
      @click.self="dismissCurrent"
    >
      <transition
        enter-active-class="transition duration-200"
        enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100"
      >
        <div
          class="relative w-full max-w-lg bg-white dark:bg-gray-800 rounded-xl shadow-2xl overflow-hidden"
          @click.stop
        >
          <!-- Header dengan accent biru -->
          <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-4 flex items-start gap-3">
            <div class="shrink-0 w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center">
              <MegaphoneIcon class="h-5 w-5 text-white" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="text-xs font-semibold text-blue-100 uppercase tracking-wide">
                What's New
                <span v-if="current.version" class="ml-1 px-1.5 py-0.5 bg-white/20 rounded text-[10px]">
                  v{{ current.version }}
                </span>
              </div>
              <h3 class="text-lg font-bold text-white mt-0.5">{{ current.title }}</h3>
            </div>
            <button
              type="button"
              class="shrink-0 text-white/80 hover:text-white transition-colors"
              :aria-label="'Tutup announcement'"
              @click="dismissCurrent"
            >
              <XMarkIcon class="h-5 w-5" />
            </button>
          </div>

          <!-- Body -->
          <div class="px-6 py-5 space-y-3">
            <div class="text-sm text-gray-700 dark:text-gray-200 leading-relaxed whitespace-pre-line">
              {{ current.body }}
            </div>

            <div class="text-xs text-gray-500 dark:text-gray-400">
              <span v-if="current.published_at">Diterbitkan: {{ formatDate(current.published_at) }}</span>
            </div>

            <!-- Multi-announcement counter -->
            <div v-if="announcements.length > 1" class="flex items-center gap-1 pt-1">
              <span
                v-for="(a, i) in announcements"
                :key="a.id"
                class="h-1.5 w-6 rounded-full transition-colors"
                :class="i === currentIndex ? 'bg-blue-500' : (i < currentIndex ? 'bg-blue-300' : 'bg-gray-200 dark:bg-gray-700')"
              />
              <span class="ml-2 text-xs text-gray-500">
                {{ currentIndex + 1 }} / {{ announcements.length }}
              </span>
            </div>
          </div>

          <!-- Actions -->
          <div class="px-6 py-3 bg-gray-50 dark:bg-gray-900/50 flex items-center justify-end gap-2 border-t border-gray-200 dark:border-gray-700">
            <button
              type="button"
              class="px-3 py-1.5 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
              @click="dismissCurrent"
            >
              {{ hasMore ? 'Lewati' : 'Tutup' }}
            </button>
            <button
              v-if="current.cta_url"
              type="button"
              class="px-3 py-1.5 text-sm font-medium bg-blue-600 text-white rounded hover:bg-blue-500 inline-flex items-center gap-1"
              @click="handleCta"
            >
              {{ current.cta_label || 'Pelajari' }}
              <ArrowRightIcon class="h-3 w-3" />
            </button>
          </div>
        </div>
      </transition>
    </div>
  </transition>
</template>