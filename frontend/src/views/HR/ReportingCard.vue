<script setup>
/**
 * ReportingCard — kartu reporting line untuk detail JIC modal.
 *
 * Menampilkan tiga peran (Atasan Langsung, Manager, Kepala Departemen)
 * masing-masing dengan:
 *   - Nama lengkap + NIK karyawan (employee_number)
 *   - Posisi & departemen
 *   - Email / telepon untuk kontak langsung
 *
 * Variant `emerald` dipakai untuk kolom "Baru" supaya kontras dengan kolom
 * "Lama" yang neutral.
 */
import {
  UserIcon, IdentificationIcon, EnvelopeIcon, PhoneIcon,
} from '@heroicons/vue/24/outline'

defineProps({
  label:      { type: String, required: true },
  /** { employee_id, employee_number, full_name, position, position_tier, department, email, phone } | null */
  supervisor: { type: Object, default: null },
  manager:    { type: Object, default: null },
  deptHead:   { type: Object, default: null },
  /** 'neutral' | 'emerald' — variant warna untuk membedakan Lama vs Baru */
  variant:    { type: String, default: 'neutral' },
})

function initial(name) {
  if (! name) return '?'
  return String(name).charAt(0).toUpperCase()
}
</script>

<template>
  <div :class="['card p-4', variant === 'emerald' ? 'bg-emerald-50 dark:bg-emerald-900/10 border-emerald-200' : 'bg-gray-50 dark:bg-gray-800']">
    <div :class="['text-xs uppercase font-semibold mb-3', variant === 'emerald' ? 'text-emerald-700' : 'text-gray-500']">
      {{ label }}
    </div>

    <div class="space-y-3">
      <!-- Atasan Langsung -->
      <div v-if="supervisor" class="flex items-start gap-3">
        <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center flex-shrink-0 text-sm font-semibold">
          {{ initial(supervisor.full_name) }}
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-[11px] uppercase tracking-wide text-gray-500">Atasan Langsung</div>
          <div class="font-semibold text-sm truncate">{{ supervisor.full_name || '-' }}</div>
          <div class="text-xs mt-0.5 space-y-0.5">
            <div v-if="supervisor.employee_number" class="flex items-center gap-1">
              <IdentificationIcon class="w-3.5 h-3.5" />
              <span class="font-mono">{{ supervisor.employee_number }}</span>
            </div>
            <div v-if="supervisor.position || supervisor.department" class="truncate text-gray-600 dark:text-gray-400">
              {{ supervisor.position }}<template v-if="supervisor.position_tier"> ({{ supervisor.position_tier }})</template><span v-if="supervisor.department" class="text-gray-400"> · {{ supervisor.department }}</span>
            </div>
            <div v-if="supervisor.email" class="flex items-center gap-1 text-gray-500">
              <EnvelopeIcon class="w-3.5 h-3.5" />
              <a :href="`mailto:${supervisor.email}`" class="hover:underline truncate">{{ supervisor.email }}</a>
            </div>
            <div v-if="supervisor.phone" class="flex items-center gap-1 text-gray-500">
              <PhoneIcon class="w-3.5 h-3.5" />
              <span>{{ supervisor.phone }}</span>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="flex items-center gap-2 text-sm text-gray-400 italic">
        <UserIcon class="w-4 h-4" />
        <span>Atasan Langsung: belum ditugaskan</span>
      </div>

      <!-- Manager -->
      <div v-if="manager" class="flex items-start gap-3">
        <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center flex-shrink-0 text-sm font-semibold">
          {{ initial(manager.full_name) }}
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-[11px] uppercase tracking-wide text-gray-500">Manager</div>
          <div class="font-semibold text-sm truncate">{{ manager.full_name || '-' }}</div>
          <div class="text-xs mt-0.5 space-y-0.5">
            <div v-if="manager.employee_number" class="flex items-center gap-1">
              <IdentificationIcon class="w-3.5 h-3.5" />
              <span class="font-mono">{{ manager.employee_number }}</span>
            </div>
            <div v-if="manager.position || manager.department" class="truncate text-gray-600 dark:text-gray-400">
              {{ manager.position }}<template v-if="manager.position_tier"> ({{ manager.position_tier }})</template><span v-if="manager.department" class="text-gray-400"> · {{ manager.department }}</span>
            </div>
            <div v-if="manager.email" class="flex items-center gap-1 text-gray-500">
              <EnvelopeIcon class="w-3.5 h-3.5" />
              <a :href="`mailto:${manager.email}`" class="hover:underline truncate">{{ manager.email }}</a>
            </div>
          </div>
        </div>
      </div>

      <!-- Kepala Departemen -->
      <div v-if="deptHead" class="flex items-start gap-3">
        <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center flex-shrink-0 text-sm font-semibold">
          {{ initial(deptHead.full_name) }}
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-[11px] uppercase tracking-wide text-gray-500">Kepala Departemen</div>
          <div class="font-semibold text-sm truncate">{{ deptHead.full_name || '-' }}</div>
          <div class="text-xs mt-0.5 space-y-0.5">
            <div v-if="deptHead.employee_number" class="flex items-center gap-1">
              <IdentificationIcon class="w-3.5 h-3.5" />
              <span class="font-mono">{{ deptHead.employee_number }}</span>
            </div>
            <div v-if="deptHead.position || deptHead.department" class="truncate text-gray-600 dark:text-gray-400">
              {{ deptHead.position }}<template v-if="deptHead.position_tier"> ({{ deptHead.position_tier }})</template><span v-if="deptHead.department" class="text-gray-400"> · {{ deptHead.department }}</span>
            </div>
            <div v-if="deptHead.email" class="flex items-center gap-1 text-gray-500">
              <EnvelopeIcon class="w-3.5 h-3.5" />
              <a :href="`mailto:${deptHead.email}`" class="hover:underline truncate">{{ deptHead.email }}</a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <p v-if="!supervisor && !manager && !deptHead" class="text-xs text-gray-500 italic">
      Belum ada reporting line yang ter-resolve untuk konfigurasi ini.
    </p>
  </div>
</template>

<style scoped>
.card { @apply border rounded-lg dark:border-gray-700 shadow-sm; }
</style>