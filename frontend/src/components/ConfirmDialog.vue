<script setup>
import { onMounted, onUnmounted, computed, ref, watch, nextTick } from 'vue'
import { confirmState, closeConfirm } from '@/composables/useConfirm'

const inputRef = ref(null)

function onKey(e) {
  if (! confirmState.value.open) return
  if (e.key === 'Escape') closeConfirm(false)
  // Enter: confirm only when not in textarea/multiline input
  if (e.key === 'Enter' && ! e.shiftKey) {
    if (confirmState.value.needsInput && confirmState.value.inputType === 'textarea') return
    if (canConfirm.value) closeConfirm(true)
  }
}

const canConfirm = computed(() => {
  if (confirmState.value.needsInput && confirmState.value.inputRequired) {
    return String(confirmState.value.inputValue || '').trim().length > 0
  }
  return true
})

watch(() => confirmState.value.open, (open) => {
  if (open && confirmState.value.needsInput) {
    nextTick(() => inputRef.value?.focus())
  }
})

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))

const variantClass = {
  primary: 'btn-primary',
  danger:  'btn-danger',
  warning: 'btn-warning',
  success: 'btn-success',
}
</script>

<template>
  <div v-if="confirmState.open" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md" role="dialog" aria-modal="true">
      <div class="px-5 py-4 border-b border-gray-200 flex items-center gap-2">
        <span v-if="confirmState.variant === 'danger'" class="text-red-600 text-2xl">⚠</span>
        <span v-else-if="confirmState.variant === 'warning'" class="text-amber-600 text-2xl">⚠</span>
        <span v-else-if="confirmState.variant === 'success'" class="text-green-600 text-2xl">✓</span>
        <span v-else class="text-blue-600 text-2xl">?</span>
        <h2 class="text-lg font-semibold">{{ confirmState.title }}</h2>
      </div>
      <div class="px-5 py-4 text-sm text-gray-700">
        <p v-if="confirmState.message" class="whitespace-pre-line mb-3">{{ confirmState.message }}</p>
        <div v-if="confirmState.needsInput">
          <label v-if="confirmState.inputLabel" class="block text-sm font-medium mb-1">{{ confirmState.inputLabel }}</label>
          <textarea
            v-if="confirmState.inputType === 'textarea'"
            ref="inputRef"
            v-model="confirmState.inputValue"
            class="input w-full"
            rows="4"
            :placeholder="confirmState.inputPlaceholder"
          ></textarea>
          <input
            v-else
            ref="inputRef"
            v-model="confirmState.inputValue"
            :type="confirmState.inputType"
            class="input w-full"
            :placeholder="confirmState.inputPlaceholder"
          />
        </div>
      </div>
      <div class="px-5 py-3 border-t border-gray-200 flex justify-end gap-2">
        <button v-if="!confirmState.alertOnly" type="button" class="btn-secondary" @click="closeConfirm(false)">
          {{ confirmState.cancelText }}
        </button>
        <button
          type="button"
          :class="variantClass[confirmState.variant] || 'btn-primary'"
          :disabled="!canConfirm"
          @click="closeConfirm(true)"
        >
          {{ confirmState.okText }}
        </button>
      </div>
    </div>
  </div>
</template>