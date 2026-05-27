<script setup>
import { ref, computed } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { fmtCurrency } from '@/composables/useResource'
import {
  DocumentMagnifyingGlassIcon, CheckCircleIcon, ExclamationTriangleIcon,
  ArrowPathIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 13 / H2.8 — OCR Receipt extractor (reusable component).
 *
 * Component wrapping endpoint POST /reimbursements/:id/ocr-extract supaya bisa
 * dipasang di ReimbursementList atau form detail dengan emit `extracted`
 * berisi field yang bisa di-prefill ke form.
 *
 * Props:
 *   reimbursementId — ID reimbursement record yang attachment_path-nya sudah di-upload
 *
 * Emits:
 *   @extracted — { vendor, total, date, currency, confidence }
 *
 * Pemakaian:
 *   <OcrReceiptExtract :reimbursement-id="id" @extracted="prefillForm" />
 */

const props = defineProps({
  reimbursementId: { type: [Number, String], required: true },
})

const emit = defineEmits(['extracted'])

const toast = useToast()

const extracting = ref(false)
const result = ref(null)

async function extract() {
  if (! props.reimbursementId) {
    toast.warning('Reimbursement ID tidak valid')
    return
  }
  extracting.value = true
  result.value = null
  try {
    const res = await api.post(`/reimbursements/${props.reimbursementId}/ocr-extract`)
    result.value = res.data.data
    emit('extracted', result.value)
    if (result.value?.confidence >= 0.6) {
      toast.success('OCR extract berhasil — silakan review hasilnya')
    } else {
      toast.warning('OCR confidence rendah — periksa ulang hasil ekstraksi')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal extract OCR')
  } finally {
    extracting.value = false
  }
}

function confidenceColor(conf) {
  const v = Number(conf || 0)
  if (v >= 0.8) return 'text-emerald-700 bg-emerald-50 border-emerald-200'
  if (v >= 0.6) return 'text-amber-700 bg-amber-50 border-amber-200'
  return 'text-red-700 bg-red-50 border-red-200'
}

function confidenceLabel(conf) {
  const v = Number(conf || 0)
  if (v >= 0.8) return 'High'
  if (v >= 0.6) return 'Medium'
  return 'Low'
}
</script>

<template>
  <div class="border border-gray-200 rounded-lg p-3">
    <div class="flex items-center justify-between gap-3">
      <div class="flex items-center gap-2">
        <DocumentMagnifyingGlassIcon class="w-5 h-5 text-primary-600" />
        <div>
          <div class="text-sm font-medium">OCR Auto-Extract</div>
          <div class="text-xs text-gray-500">
            Ekstrak nominal, tanggal, dan vendor dari struk yang sudah di-upload
          </div>
        </div>
      </div>
      <button class="btn-secondary text-xs" @click="extract" :disabled="extracting">
        <ArrowPathIcon class="w-4 h-4" :class="extracting ? 'animate-spin' : ''" />
        {{ extracting ? 'Extracting…' : 'Extract' }}
      </button>
    </div>

    <div v-if="result" class="mt-3 space-y-2">
      <div class="flex items-center gap-2">
        <span class="text-xs text-gray-500">Confidence:</span>
        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium border"
          :class="confidenceColor(result.confidence)">
          {{ confidenceLabel(result.confidence) }}
          ({{ ((result.confidence || 0) * 100).toFixed(0) }}%)
        </span>
      </div>

      <div class="grid grid-cols-2 gap-2 text-sm">
        <div>
          <div class="text-xs text-gray-500">Vendor</div>
          <div class="font-medium">{{ result.vendor || '—' }}</div>
        </div>
        <div>
          <div class="text-xs text-gray-500">Tanggal</div>
          <div class="font-medium">{{ result.date || '—' }}</div>
        </div>
        <div>
          <div class="text-xs text-gray-500">Total</div>
          <div class="font-medium text-emerald-700">
            {{ result.total ? fmtCurrency(result.total) : '—' }}
          </div>
        </div>
        <div>
          <div class="text-xs text-gray-500">Currency</div>
          <div class="font-medium">{{ result.currency || 'IDR' }}</div>
        </div>
      </div>

      <div v-if="(result.confidence || 0) < 0.6"
        class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2 flex items-start gap-1.5">
        <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0 mt-0.5" />
        <div>
          Confidence rendah. Sangat disarankan periksa ulang hasil extract dengan struk asli
          sebelum simpan reimbursement.
        </div>
      </div>

      <div v-else
        class="text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 rounded p-2 flex items-start gap-1.5">
        <CheckCircleIcon class="w-4 h-4 flex-shrink-0 mt-0.5" />
        <div>
          Hasil OCR di-emit ke parent form. Field di atas bisa di-prefill ke form reimbursement
          (vendor → merchant_name, total → amount, date → expense_date).
        </div>
      </div>
    </div>
  </div>
</template>