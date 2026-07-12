<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { BellAlertIcon, ClockIcon, EnvelopeIcon, DevicePhoneMobileIcon, ComputerDesktopIcon } from '@heroicons/vue/24/outline'

const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const types = ref([])
const channels = [
  { key: 'in_app', label: 'In-App', icon: BellAlertIcon, locked: true },
  { key: 'email', label: 'Email', icon: EnvelopeIcon },
  { key: 'whatsapp', label: 'WhatsApp', icon: DevicePhoneMobileIcon },
  { key: 'web_push', label: 'Push', icon: ComputerDesktopIcon },
]
const values = reactive({})
const quiet = reactive({ enabled: false, start: '21:00', end: '07:00' })

function defaultEnabled(type, channel) {
  if (channel === 'in_app') return true
  if (channel === 'email') return type !== '*'
  if (channel === 'whatsapp') return type === 'approval.pending'
  return false
}

async function load() {
  loading.value = true
  try {
    const res = await api.get('/notification-preferences')
    const data = res.data.data || {}
    types.value = data.available_types || []
    const saved = {}
    for (const pref of data.preferences || []) saved[pref.notif_type] = pref.channels || {}
    for (const type of types.value) {
      values[type.key] = {}
      for (const channel of channels) {
        values[type.key][channel.key] = saved[type.key]?.[channel.key]
          ?? defaultEnabled(type.key, channel.key)
      }
    }
    quiet.enabled = !!(data.quiet_hours?.start && data.quiet_hours?.end)
    quiet.start = data.quiet_hours?.start?.slice(0, 5) || '21:00'
    quiet.end = data.quiet_hours?.end?.slice(0, 5) || '07:00'
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat pengaturan notifikasi')
  } finally { loading.value = false }
}

async function save() {
  saving.value = true
  try {
    const preferences = []
    for (const type of types.value) {
      for (const channel of channels) {
        preferences.push({ notif_type: type.key, channel: channel.key, enabled: !!values[type.key]?.[channel.key] })
      }
    }
    await Promise.all([
      api.put('/notification-preferences', { preferences }),
      api.put('/notification-preferences/quiet-hours', quiet.enabled
        ? { start: quiet.start, end: quiet.end }
        : { start: null, end: null }),
    ])
    toast.success('Pengaturan notifikasi disimpan')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan pengaturan')
  } finally { saving.value = false }
}

onMounted(load)
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <div class="flex items-end justify-between gap-4 mb-5">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Pengaturan Notifikasi</h1>
        <p class="text-sm text-gray-500 mt-1">Atur channel penerimaan untuk akun Anda.</p>
      </div>
      <button class="btn-primary" :disabled="loading || saving" @click="save">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </div>

    <div class="border border-gray-200 bg-white overflow-hidden">
      <div class="grid grid-cols-[minmax(240px,1fr)_repeat(4,110px)] bg-gray-50 border-b px-4 py-3 text-xs font-semibold text-gray-600">
        <span>Jenis notifikasi</span>
        <span v-for="channel in channels" :key="channel.key" class="text-center">{{ channel.label }}</span>
      </div>
      <div v-if="loading" class="p-10 text-center text-sm text-gray-400">Memuat pengaturan...</div>
      <div v-else class="divide-y divide-gray-100">
        <div v-for="type in types" :key="type.key" class="grid grid-cols-[minmax(240px,1fr)_repeat(4,110px)] items-center px-4 py-3">
          <div>
            <div class="text-sm font-medium text-gray-800">{{ type.label }}</div>
            <div class="text-xs font-mono text-gray-400">{{ type.key }}</div>
          </div>
          <label v-for="channel in channels" :key="channel.key" class="flex justify-center">
            <input v-model="values[type.key][channel.key]" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary-600"
              :disabled="channel.locked" :title="channel.locked ? 'Notifikasi in-app selalu aktif' : channel.label" />
          </label>
        </div>
      </div>
    </div>

    <div class="mt-5 border border-gray-200 bg-white p-5">
      <div class="flex items-start gap-3">
        <ClockIcon class="w-5 h-5 text-gray-500 mt-0.5" />
        <div class="flex-1">
          <div class="flex items-center justify-between gap-3">
            <div>
              <div class="font-semibold text-gray-900">Quiet Hours</div>
              <div class="text-sm text-gray-500">Matikan channel eksternal pada jam istirahat. In-app tetap tersimpan.</div>
            </div>
            <input v-model="quiet.enabled" type="checkbox" class="h-5 w-5 rounded border-gray-300 text-primary-600" />
          </div>
          <div v-if="quiet.enabled" class="flex gap-3 mt-4 max-w-sm">
            <div class="flex-1"><label class="label">Mulai</label><input v-model="quiet.start" type="time" class="input w-full" /></div>
            <div class="flex-1"><label class="label">Selesai</label><input v-model="quiet.end" type="time" class="input w-full" /></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
