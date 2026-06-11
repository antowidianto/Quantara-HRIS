<script setup>
/**
 * S1.1 SaaS — Upgrade Banner.
 *
 * Tampilkan banner CTA upgrade plan kalau feature tertentu di-block oleh
 * tenant plan saat ini. Pakai dengan v-if + useFeature.
 *
 * Props:
 *   - feature      (string)  Feature key (mis. 'white_label.enabled')
 *   - title        (string)  Override judul (default: feature description)
 *   - message      (string)  Override pesan
 *   - planRequired (string)  Plan minimum (mis. 'PRO', 'ENTERPRISE')
 *   - dismissible  (bool)    Bisa di-dismiss user (default: false)
 *
 * Usage:
 *   <UpgradeBanner v-if="!has('white_label.enabled')" feature="white_label.enabled" plan-required="ENTERPRISE" />
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useFeature } from '@/composables/useFeature'
import { ArrowUpCircleIcon, XMarkIcon, SparklesIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  feature: { type: String, required: true },
  title: { type: String, default: '' },
  message: { type: String, default: '' },
  planRequired: { type: String, default: 'PRO' },
  dismissible: { type: Boolean, default: false },
})

const router = useRouter()
const { info } = useFeature()
const dismissed = ref(false)

const featureInfo = computed(() => info(props.feature))
const computedTitle = computed(() => {
  if (props.title) return props.title
  if (featureInfo.value?.description) return featureInfo.value.description
  return 'Fitur Premium'
})
const computedMessage = computed(() => {
  if (props.message) return props.message
  return `Fitur ini tersedia di plan ${props.planRequired} ke atas. Upgrade untuk mengakses.`
})

function gotoUpgrade() {
  router.push('/billing/plan')
}
</script>

<template>
  <div
    v-if="!dismissed"
    class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/30 p-4 mb-4"
  >
    <div class="flex items-start gap-3">
      <SparklesIcon class="h-6 w-6 flex-shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
      <div class="flex-1 min-w-0">
        <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-100">
          {{ computedTitle }}
        </h3>
        <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
          {{ computedMessage }}
        </p>
        <div class="mt-3 flex items-center gap-2 flex-wrap">
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
            @click="gotoUpgrade"
          >
            <ArrowUpCircleIcon class="h-4 w-4" />
            Upgrade ke {{ planRequired }}
          </button>
          <span class="text-xs text-amber-700 dark:text-amber-300">
            Mulai dari Rp 99.000/bulan
          </span>
        </div>
      </div>
      <button
        v-if="dismissible"
        type="button"
        class="flex-shrink-0 rounded-md text-amber-600 hover:text-amber-800 dark:text-amber-400"
        aria-label="Tutup banner"
        @click="dismissed = true"
      >
        <XMarkIcon class="h-5 w-5" />
      </button>
    </div>
  </div>
</template>