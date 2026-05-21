<script setup>
import { ref, computed } from 'vue'
import { MapPinIcon, ArrowPathIcon, ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline'

/**
 * LocationPicker — komponen reusable untuk input koordinat geofence.
 *
 * Cocok untuk form yang butuh user mendaftarkan titik tempat clock-in
 * diizinkan (kantor cabang, site proyek, gudang, dll). Mendukung:
 *  - Tombol "Ambil dari GPS sekarang" yang otomatis isi lat/lng pakai
 *    browser geolocation. Cara paling cepat & akurat untuk user yang
 *    sedang berdiri di lokasi yang mau didaftarkan.
 *  - Input manual lat/lng decimal sebagai fallback (kalau user pakai
 *    desktop / tidak di lokasi).
 *  - Radius geofence dengan preset 50/100/200/500/1000 m.
 *  - Link verifikasi ke Google Maps supaya user bisa cek visual
 *    sebelum simpan.
 *
 * Pemakaian (di parent):
 *   <LocationPicker
 *     v-model:lat="form.lat"
 *     v-model:lng="form.lng"
 *     v-model:radius="form.geofence_radius_m"
 *   />
 */

const props = defineProps({
  lat: { type: [Number, String, null], default: null },
  lng: { type: [Number, String, null], default: null },
  radius: { type: [Number, String, null], default: null },
  // Apakah field radius wajib dikelola juga (default true).
  showRadius: { type: Boolean, default: true },
})

const emit = defineEmits(['update:lat', 'update:lng', 'update:radius'])

const fetching = ref(false)
const error = ref('')
const accuracy = ref(null)
const lastFetched = ref(null)

const radiusPresets = [50, 100, 200, 500, 1000]

const hasCoords = computed(() => {
  const lt = parseFloat(props.lat); const lg = parseFloat(props.lng)
  return ! isNaN(lt) && ! isNaN(lg) && lt !== 0 && lg !== 0
})

const mapsLink = computed(() => {
  if (! hasCoords.value) return null
  return `https://www.google.com/maps?q=${props.lat},${props.lng}&z=18`
})

function fetchCurrent() {
  if (! navigator.geolocation) {
    error.value = 'Browser tidak mendukung geolocation'
    return
  }
  fetching.value = true
  error.value = ''
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      // Bulatkan ke 6 digit decimal (~11 cm precision) agar form bersih.
      const lt = Math.round(pos.coords.latitude * 1e6) / 1e6
      const lg = Math.round(pos.coords.longitude * 1e6) / 1e6
      emit('update:lat', lt)
      emit('update:lng', lg)
      accuracy.value = Math.round(pos.coords.accuracy)
      lastFetched.value = new Date()
      // Kalau radius masih kosong, kasih saran default 100m.
      if (props.showRadius) {
        const rad = parseFloat(props.radius)
        if (isNaN(rad) || rad <= 0) emit('update:radius', 100)
      }
      fetching.value = false
    },
    (err) => {
      fetching.value = false
      const map = {
        1: 'Akses lokasi ditolak — izinkan akses lokasi di browser',
        2: 'Lokasi tidak tersedia',
        3: 'Pencarian lokasi timeout',
      }
      error.value = map[err.code] || 'Gagal mengambil lokasi'
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
  )
}

function setLat(e) {
  const v = e.target.value
  emit('update:lat', v === '' ? null : Number(v))
}
function setLng(e) {
  const v = e.target.value
  emit('update:lng', v === '' ? null : Number(v))
}
function setRadius(v) {
  emit('update:radius', v === '' || v === null ? null : Number(v))
}
function setRadiusFromInput(e) { setRadius(e.target.value) }

function clearCoords() {
  emit('update:lat', null)
  emit('update:lng', null)
  accuracy.value = null
  lastFetched.value = null
}
</script>

<template>
  <div class="space-y-2 border border-gray-200 rounded-md p-3 bg-gray-50/50">
    <div class="flex items-center justify-between gap-2">
      <div class="text-sm font-medium text-gray-700 flex items-center gap-1">
        <MapPinIcon class="w-4 h-4" /> Koordinat Lokasi (Geofence)
      </div>
      <div class="flex items-center gap-2">
        <button type="button"
          class="text-xs inline-flex items-center gap-1 px-2 py-1 rounded bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50"
          :disabled="fetching" @click="fetchCurrent">
          <ArrowPathIcon class="w-3.5 h-3.5" :class="fetching ? 'animate-spin' : ''" />
          {{ fetching ? 'Mengambil...' : 'Ambil dari GPS sekarang' }}
        </button>
        <button v-if="hasCoords" type="button"
          class="text-xs text-red-600 hover:underline" @click="clearCoords">
          Reset
        </button>
      </div>
    </div>

    <p class="text-xs text-gray-500">
      Tekan tombol di atas saat Anda sedang di lokasi, atau isi manual koordinat di bawah.
      Decimal format. Contoh Jakarta: -6.200000, 106.816666.
    </p>

    <div v-if="error" class="text-xs text-red-700 bg-red-50 px-2 py-1 rounded">{{ error }}</div>
    <div v-if="lastFetched" class="text-xs text-emerald-700">
      ✓ Diambil {{ lastFetched.toLocaleTimeString() }}<span v-if="accuracy"> · akurasi ±{{ accuracy }} m</span>
    </div>

    <div class="grid grid-cols-2 gap-2">
      <div>
        <label class="block text-xs text-gray-600 mb-0.5">Latitude</label>
        <input :value="lat ?? ''" @input="setLat" type="number" step="any"
          placeholder="-6.200000"
          class="input w-full text-sm" />
      </div>
      <div>
        <label class="block text-xs text-gray-600 mb-0.5">Longitude</label>
        <input :value="lng ?? ''" @input="setLng" type="number" step="any"
          placeholder="106.816666"
          class="input w-full text-sm" />
      </div>
    </div>

    <div v-if="showRadius">
      <label class="block text-xs text-gray-600 mb-0.5">Radius Geofence (meter)</label>
      <div class="flex items-center gap-2">
        <input :value="radius ?? ''" @input="setRadiusFromInput" type="number" min="0" step="1"
          placeholder="100"
          class="input w-32 text-sm" />
        <div class="flex flex-wrap gap-1">
          <button v-for="r in radiusPresets" :key="r" type="button"
            class="text-xs px-2 py-0.5 rounded-full"
            :class="Number(radius) === r ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-primary-100'"
            @click="setRadius(r)">
            {{ r }} m
          </button>
        </div>
      </div>
      <p class="text-xs text-gray-500 mt-1">
        Karyawan harus berada dalam radius ini saat clock-in. Default 100 m untuk kantor kecil,
        500–1000 m untuk site/gudang luas.
      </p>
    </div>

    <div v-if="mapsLink">
      <a :href="mapsLink" target="_blank" rel="noopener"
        class="text-xs text-primary-600 hover:underline inline-flex items-center gap-1">
        <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
        Buka di Google Maps untuk verifikasi
      </a>
    </div>
  </div>
</template>