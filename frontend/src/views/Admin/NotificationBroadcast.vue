<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import { MegaphoneIcon, UsersIcon, MagnifyingGlassIcon, PaperAirplaneIcon } from '@heroicons/vue/24/outline'

const toast = useToast()
const options = reactive({ roles: [], departments: [], branches: [] })
const form = reactive({ target_type: 'ALL', target_id: '', target_values: '', title: '', message: '', link_url: '', notif_type: 'broadcast.general' })
const preview = ref(null)
const loadingPreview = ref(false)
const sending = ref(false)
const targetNeedsSelect = computed(() => ['ROLE', 'DEPARTMENT', 'BRANCH'].includes(form.target_type))
const targetNeedsValues = computed(() => ['NIK', 'USER_ID'].includes(form.target_type))
const targetOptions = computed(() => form.target_type === 'ROLE' ? options.roles : form.target_type === 'DEPARTMENT' ? options.departments : options.branches)

watch(() => form.target_type, () => { form.target_id = ''; form.target_values = ''; preview.value = null })

async function loadOptions() {
  try { Object.assign(options, (await api.get('/notification-broadcast/options')).data.data || {}) }
  catch (e) { toast.error(e.response?.data?.message || 'Gagal memuat pilihan target') }
}
async function previewRecipients() {
  loadingPreview.value = true
  try { preview.value = (await api.post('/notification-broadcast/preview', form)).data.data }
  catch (e) { preview.value = null; toast.error(e.response?.data?.message || 'Target tidak valid') }
  finally { loadingPreview.value = false }
}
async function sendBroadcast() {
  if (! preview.value?.count) return toast.warning('Preview penerima terlebih dahulu')
  if (! form.title.trim() || ! form.message.trim()) return toast.warning('Judul dan pesan wajib diisi')
  if (! window.confirm(`Kirim notifikasi kepada ${preview.value.count} user?`)) return
  sending.value = true
  try {
    const res = await api.post('/notification-broadcast/send', form)
    toast.success(`${res.data.data.sent} notifikasi berhasil dikirim`)
    form.title = ''; form.message = ''; form.link_url = ''; preview.value = null
  } catch (e) { toast.error(e.response?.data?.message || 'Gagal mengirim broadcast') }
  finally { sending.value = false }
}
onMounted(loadOptions)
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <div class="mb-5">
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2"><MegaphoneIcon class="w-6 h-6" /> Broadcast Notifikasi</h1>
      <p class="text-sm text-gray-500 mt-1">Kirim pengumuman terarah kepada user dalam perusahaan aktif.</p>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-5">
      <div class="border border-gray-200 bg-white p-5 space-y-4">
        <div><label class="label">Target penerima <span class="text-red-500">*</span></label>
          <select v-model="form.target_type" class="input w-full">
            <option value="ALL">Semua user aktif</option><option value="ROLE">Role tertentu</option>
            <option value="DEPARTMENT">Department tertentu</option><option value="BRANCH">Branch tertentu</option>
            <option value="NIK">Daftar NIK</option><option value="USER_ID">Daftar User ID</option>
          </select>
        </div>
        <div v-if="targetNeedsSelect"><label class="label">Pilihan target <span class="text-red-500">*</span></label>
          <select v-model="form.target_id" class="input w-full"><option value="">Pilih...</option><option v-for="item in targetOptions" :key="item.id" :value="item.id">{{ item.name }}<template v-if="item.slug"> ({{ item.slug }})</template></option></select>
        </div>
        <div v-if="targetNeedsValues"><label class="label">{{ form.target_type === 'NIK' ? 'NIK karyawan' : 'User ID' }} <span class="text-red-500">*</span></label>
          <textarea v-model="form.target_values" class="input w-full" rows="3" placeholder="Pisahkan dengan koma atau baris baru"></textarea>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="label">Jenis notifikasi</label><input v-model="form.notif_type" class="input w-full" placeholder="broadcast.general" /></div>
          <div><label class="label">Link tujuan</label><input v-model="form.link_url" class="input w-full" placeholder="/notifications (opsional)" /></div>
        </div>
        <div><label class="label">Judul <span class="text-red-500">*</span></label><input v-model="form.title" maxlength="150" class="input w-full" /></div>
        <div><label class="label">Pesan <span class="text-red-500">*</span></label><textarea v-model="form.message" maxlength="2000" rows="7" class="input w-full"></textarea><div class="text-xs text-gray-400 text-right">{{ form.message.length }}/2000</div></div>
      </div>
      <div class="space-y-4">
        <div class="border border-gray-200 bg-white p-5">
          <div class="font-semibold text-gray-900 flex items-center gap-2"><UsersIcon class="w-5 h-5" /> Preview Penerima</div>
          <button class="btn-secondary w-full mt-4" :disabled="loadingPreview" @click="previewRecipients"><MagnifyingGlassIcon class="w-4 h-4" /> {{ loadingPreview ? 'Memeriksa...' : 'Periksa Penerima' }}</button>
          <template v-if="preview"><div class="text-3xl font-bold text-primary-700 mt-5">{{ preview.count }}</div><div class="text-sm text-gray-500">user akan menerima notifikasi</div>
            <div class="mt-4 divide-y divide-gray-100 border-t"><div v-for="user in preview.sample" :key="user.user_id" class="py-2"><div class="text-sm font-medium">{{ user.name }}</div><div class="text-xs text-gray-500">{{ user.employee_number || `User #${user.user_id}` }} · {{ user.email }}</div></div></div>
          </template>
        </div>
        <button class="btn-primary w-full" :disabled="sending || !preview?.count" @click="sendBroadcast"><PaperAirplaneIcon class="w-4 h-4" /> {{ sending ? 'Mengirim...' : 'Kirim Broadcast' }}</button>
      </div>
    </div>
  </div>
</template>
