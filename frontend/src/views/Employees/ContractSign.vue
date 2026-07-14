<script setup>
import { ref, onMounted, computed, nextTick, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import api from '@/api/client'
import { useI18n } from '@/locales'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { t } = useI18n()

const contractId = computed(() => Number(route.params.id))
const loading = ref(true)
const contract = ref(null)
const contractHtml = ref('')
const submitting = ref(false)
const signed = ref(false)

const signatureMethod = ref('draw')
const canvasEl = ref(null)
const signatureData = ref(null)
const fileInput = ref(null)
const isDrawing = ref(false)
const hasSignature = ref(false)
const lastPoint = ref(null)

onMounted(async () => {
  try {
    const [contractRes, htmlRes] = await Promise.all([
      api.get(`/generated-contracts/${contractId.value}`),
      api.get(`/generated-contracts/${contractId.value}/html`, { responseType: 'text' }),
    ])
    contract.value = contractRes.data.data
    contractHtml.value = htmlRes.data
  } catch (e) {
    toast.error(e.response?.data?.message || t('contract_sign.load_failed'))
  } finally {
    loading.value = false
    await nextTick()
    if (contract.value) initCanvas()
  }
})

watch(signatureMethod, async (method) => {
  signatureData.value = null
  hasSignature.value = false
  if (fileInput.value) fileInput.value.value = ''
  if (method === 'draw') {
    await nextTick()
    initCanvas()
  }
})

function initCanvas() {
  const canvas = canvasEl.value
  if (!canvas) return
  const rect = canvas.parentElement.getBoundingClientRect()
  const dpr = window.devicePixelRatio || 1
  const w = Math.min(600, rect.width - 32)
  const h = 200
  canvas.style.width = w + 'px'
  canvas.style.height = h + 'px'
  canvas.width = w * dpr
  canvas.height = h * dpr
  const ctx = canvas.getContext('2d')
  ctx.scale(dpr, dpr)
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, w, h)
  ctx.strokeStyle = '#1e293b'
  ctx.lineWidth = 2.5
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
}

function getPos(e) {
  const canvas = canvasEl.value
  const rect = canvas.getBoundingClientRect()
  const clientX = e.touches ? e.touches[0].clientX : e.clientX
  const clientY = e.touches ? e.touches[0].clientY : e.clientY
  return {
    x: clientX - rect.left,
    y: clientY - rect.top,
  }
}

function startDraw(e) {
  isDrawing.value = true
  const pos = getPos(e)
  lastPoint.value = pos
  const ctx = canvasEl.value.getContext('2d')
  ctx.beginPath()
  ctx.moveTo(pos.x, pos.y)
}

function draw(e) {
  if (!isDrawing.value) return
  const ctx = canvasEl.value.getContext('2d')
  const pos = getPos(e)
  const last = lastPoint.value
  if (!last) return

  const midX = (last.x + pos.x) / 2
  const midY = (last.y + pos.y) / 2
  ctx.quadraticCurveTo(last.x, last.y, midX, midY)
  ctx.stroke()

  lastPoint.value = pos
  hasSignature.value = true
}

function endDraw() {
  isDrawing.value = false
  lastPoint.value = null
  if (hasSignature.value) {
    signatureData.value = canvasEl.value.toDataURL('image/png')
  }
}

function clearCanvas() {
  const canvas = canvasEl.value
  if (!canvas) return
  const rect = canvas.parentElement.getBoundingClientRect()
  const w = Math.min(600, rect.width - 32)
  const h = 200
  const ctx = canvas.getContext('2d')
  ctx.save()
  ctx.setTransform(1, 0, 0, 1, 0, 0)
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  ctx.restore()
  canvas.style.width = w + 'px'
  canvas.style.height = h + 'px'
  hasSignature.value = false
  signatureData.value = null
}

function onFileSelected(e) {
  const file = e.target.files?.[0]
  if (!file) return
  if (!file.type.startsWith('image/')) {
    toast.error(t('contract_sign.image_only'))
    e.target.value = ''
    return
  }
  if (file.size > 2 * 1024 * 1024) {
    toast.error(t('contract_sign.max_size'))
    e.target.value = ''
    return
  }
  const reader = new FileReader()
  reader.onload = (ev) => {
    signatureData.value = ev.target.result
    hasSignature.value = true
  }
  reader.readAsDataURL(file)
}

function clearUploadedSignature() {
  signatureData.value = null
  hasSignature.value = false
  if (fileInput.value) fileInput.value.value = ''
}

async function submitSignature() {
  if (!signatureData.value) {
    toast.error(t('contract_sign.signature_required'))
    return
  }
  submitting.value = true
  try {
    await api.post(`/generated-contracts/${contractId.value}/sign`, {
      signature_data: signatureData.value,
    })
    signed.value = true
    toast.success(t('contract_sign.signed_toast'))
  } catch (e) {
    toast.error(e.response?.data?.message || t('contract_sign.save_failed'))
  } finally {
    submitting.value = false
  }
}

function toDashboard() {
  router.push('/dashboard')
}
</script>

<template>
  <div class="min-h-screen bg-slate-100">
    <div v-if="loading" class="flex items-center justify-center min-h-screen text-slate-400">
      {{ t('contract_sign.loading') }}
    </div>

    <template v-else-if="contract && !signed">
      <div class="max-w-4xl mx-auto py-6 px-4">
        <div class="mb-4">
          <h1 class="text-xl font-bold text-slate-900">{{ contract.subject || t('contract_sign.default_subject') }}</h1>
          <p class="text-sm text-slate-500">{{ contract.contract_number }}</p>
        </div>

        <!-- Contract preview -->
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden mb-6">
          <iframe
            class="w-full border-0"
            style="height: 70vh; min-height: 400px;"
            sandbox="allow-same-origin allow-scripts"
            :srcdoc="contractHtml"
            v-bind="{ title: t('contract_sign.preview_title') }"
          ></iframe>
        </div>

        <!-- Signature -->
        <div class="bg-white border border-slate-200 rounded-xl p-6 mb-6">
          <h2 class="text-base font-semibold text-slate-900 mb-4">{{ t('contract_sign.digital_signature') }}</h2>

          <div class="flex items-center gap-4 mb-4">
            <label class="flex items-center gap-2 text-sm cursor-pointer">
              <input type="radio" v-model="signatureMethod" value="draw" class="text-indigo-600" />
              {{ t('contract_sign.draw_manual') }}
            </label>
            <label class="flex items-center gap-2 text-sm cursor-pointer">
              <input type="radio" v-model="signatureMethod" value="upload" class="text-indigo-600" />
              {{ t('contract_sign.upload_photo') }}
            </label>
          </div>

          <!-- Draw signature -->
          <div v-if="signatureMethod === 'draw'">
            <div class="border-2 border-dashed border-slate-300 rounded-lg mb-3 bg-white">
              <canvas
                ref="canvasEl"
                class="touch-none cursor-crosshair"
                role="img"
                v-bind="{ 'aria-label': t('contract_sign.draw_area') }"
                @mousedown="startDraw"
                @mousemove="draw"
                @mouseup="endDraw"
                @mouseleave="endDraw"
                @touchstart.prevent="startDraw"
                @touchmove.prevent="draw"
                @touchend="endDraw"
              ></canvas>
            </div>
            <button
              type="button"
              v-if="hasSignature"
              @click="clearCanvas"
              class="text-sm text-slate-500 hover:text-rose-600 underline underline-offset-2"
            >
              {{ t('contract_sign.clear_redraw') }}
            </button>
            <p v-else class="text-xs text-slate-400">{{ t('contract_sign.draw_help') }}</p>
          </div>

          <!-- Upload signature -->
          <div v-else>
            <div
              class="border-2 border-dashed border-slate-300 rounded-lg p-6 text-center cursor-pointer hover:bg-slate-50"
              role="button"
              tabindex="0"
              v-bind="{ 'aria-label': t('contract_sign.upload_area') }"
              @click="fileInput?.click()"
              @keydown.enter.prevent="fileInput?.click()"
              @keydown.space.prevent="fileInput?.click()"
            >
              <input
                ref="fileInput"
                type="file"
                accept="image/*"
                class="hidden"
                @change="onFileSelected"
              />
              <div v-if="signatureData" class="mb-2">
                <img :src="signatureData" class="max-h-32 mx-auto" v-bind="{ alt: t('contract_sign.signature_preview') }" />
              </div>
              <div v-else>
                <p class="text-sm text-slate-500">{{ t('contract_sign.upload_prompt') }}</p>
                <p class="text-xs text-slate-400 mt-1">{{ t('contract_sign.file_hint') }}</p>
              </div>
            </div>
            <button
              type="button"
              v-if="signatureData && signatureMethod === 'upload'"
              @click="clearUploadedSignature"
              class="mt-2 text-sm text-slate-500 hover:text-rose-600 underline underline-offset-2"
            >
              {{ t('contract_sign.clear_reselect') }}
            </button>
          </div>

          <!-- Signature preview -->
          <div v-if="signatureData" class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
            <p class="text-xs text-amber-700 font-medium mb-1">{{ t('contract_sign.will_be_saved') }}</p>
            <img :src="signatureData" class="max-h-16" v-bind="{ alt: t('contract_sign.signature_preview') }" />
          </div>

          <!-- Submit -->
          <div class="mt-6 flex items-center gap-3">
            <button
              type="button"
              @click="submitSignature"
              :disabled="submitting || !hasSignature"
              class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ submitting ? t('contract_sign.saving') : t('contract_sign.sign_accept') }}
            </button>
            <button
              type="button"
              @click="toDashboard"
              class="px-4 py-2.5 border border-slate-300 rounded-lg text-sm text-slate-600 hover:bg-slate-50"
            >
              {{ t('contract_sign.later') }}
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- Signed success -->
    <div v-else-if="signed" class="max-w-lg mx-auto py-16 px-4 text-center">
      <div class="bg-white border border-slate-200 rounded-xl p-8">
        <div class="w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 class="text-xl font-bold text-slate-900 mb-2">{{ t('contract_sign.success_title') }}</h2>
        <p class="text-sm text-slate-500 mb-6">
          {{ t('contract_sign.success_message', { number: contract?.contract_number }) }}
        </p>
        <button
          type="button"
          @click="toDashboard"
          class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700"
        >
          {{ t('contract_sign.dashboard') }}
        </button>
      </div>
    </div>

    <div v-else class="flex items-center justify-center min-h-screen text-slate-400">
      {{ t('contract_sign.not_found') }}
    </div>
  </div>
</template>
