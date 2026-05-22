<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'

/**
 * CareerSettings — admin page untuk pengaturan Career Portal.
 *
 * Permission: `careers.manage` (auto-grant role 1, 2)
 */
const auth = useAuthStore()
const toast = useToast()

const loading = ref(false)
const saving = ref(false)
const company = ref(null)
const page = ref(null)
const stats = ref({ published_jobs: 0, total_applicants_via_career: 0, applicants_last_30d: 0 })
const publicUrl = ref('')

const form = reactive({
  career_slug: '',
  is_active: 1,
  headline: '',
  about_html: '',
  benefits_html: '',
  brand_color: '#0F62FE',
  contact_email: '',
  contact_phone: '',
  instagram_url: '',
  linkedin_url: '',
  website_url: '',
  tiktok_url: '',
  announcement: '',
  extra_questions: [],
})

const canManage = computed(() => auth.can('careers.manage'))

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/career-page')
    const d = res.data.data
    company.value = d.company
    page.value = d.page
    stats.value = d.stats
    publicUrl.value = d.public_url
    form.career_slug = d.company.career_slug || ''
    form.is_active = (d.page?.is_active ?? 1) ? 1 : 0
    form.headline = d.page?.headline || ''
    form.about_html = d.page?.about_html || ''
    form.benefits_html = d.page?.benefits_html || ''
    form.brand_color = d.page?.brand_color || '#0F62FE'
    form.contact_email = d.page?.contact_email || ''
    form.contact_phone = d.page?.contact_phone || ''
    form.instagram_url = d.page?.instagram_url || ''
    form.linkedin_url = d.page?.linkedin_url || ''
    form.website_url = d.page?.website_url || ''
    form.tiktok_url = d.page?.tiktok_url || ''
    form.announcement = d.page?.announcement || ''
    form.extra_questions = Array.isArray(d.page?.extra_questions) ? d.page.extra_questions : []
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat pengaturan')
  } finally {
    loading.value = false
  }
}

onMounted(fetchData)

async function save() {
  saving.value = true
  try {
    await api.put('/career-page', form)
    toast.success('Pengaturan tersimpan')
    fetchData()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal menyimpan')
  } finally {
    saving.value = false
  }
}

async function regenSlug() {
  if (!confirm('Generate ulang slug dari nama perusahaan? URL public lama akan rusak.')) return
  try {
    const res = await api.post('/career-page/regenerate-slug')
    form.career_slug = res.data.data.career_slug
    publicUrl.value = res.data.data.public_url
    toast.success('Slug di-regenerate')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal')
  }
}

function addExtraQuestion() {
  form.extra_questions.push({
    key: 'question_' + (form.extra_questions.length + 1),
    label: '',
    type: 'text',
    required: false,
    options: [],
  })
}

function removeExtraQuestion(idx) {
  form.extra_questions.splice(idx, 1)
}

function copyPublicUrl() {
  const fullUrl = window.location.origin + publicUrl.value
  navigator.clipboard.writeText(fullUrl).then(() => toast.success('URL disalin'))
}
</script>

<template>
  <div class="p-4 sm:p-6 max-w-5xl mx-auto">
    <div class="mb-6">
      <h1 class="text-2xl font-bold">Career Portal Settings</h1>
      <p class="text-gray-500 text-sm mt-1">Konfigurasi tampilan public career portal & aturan aplikasi.</p>
    </div>

    <div v-if="!canManage" class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4">
      Anda tidak memiliki izin untuk mengelola Career Portal. Hubungi admin HR.
    </div>

    <div v-else-if="loading" class="text-center py-12 text-gray-500">Memuat...</div>

    <div v-else class="space-y-4">
      <!-- Stats + Public URL -->
      <div class="grid sm:grid-cols-3 gap-3">
        <div class="bg-white border rounded-xl p-4">
          <div class="text-xs uppercase text-gray-500 tracking-wider">Posting Aktif</div>
          <div class="text-2xl font-bold text-gray-900">{{ stats.published_jobs }}</div>
        </div>
        <div class="bg-white border rounded-xl p-4">
          <div class="text-xs uppercase text-gray-500 tracking-wider">Total Pelamar</div>
          <div class="text-2xl font-bold text-gray-900">{{ stats.total_applicants_via_career }}</div>
        </div>
        <div class="bg-white border rounded-xl p-4">
          <div class="text-xs uppercase text-gray-500 tracking-wider">30 Hari Terakhir</div>
          <div class="text-2xl font-bold text-gray-900">{{ stats.applicants_last_30d }}</div>
        </div>
      </div>

      <!-- Public URL -->
      <div class="bg-white border rounded-xl p-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <div>
            <div class="text-xs uppercase text-gray-500 tracking-wider">URL Public</div>
            <div class="text-sm font-mono mt-0.5">{{ publicUrl || '—' }}</div>
          </div>
          <div class="flex gap-2">
            <a v-if="publicUrl" :href="publicUrl" target="_blank" rel="noopener" class="px-3 py-2 rounded-md border text-sm hover:bg-gray-50">↗ Buka</a>
            <button @click="copyPublicUrl" class="px-3 py-2 rounded-md border text-sm hover:bg-gray-50">📋 Salin</button>
            <button @click="regenSlug" class="px-3 py-2 rounded-md border text-sm hover:bg-gray-50 text-amber-600">🔄 Regen</button>
          </div>
        </div>
      </div>

      <!-- General -->
      <div class="bg-white border rounded-xl p-5">
        <h2 class="font-semibold mb-3">Umum</h2>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="text-sm font-medium block mb-1">Slug URL</label>
            <input v-model="form.career_slug" type="text" class="cs-input" placeholder="pt-quantara" />
            <p class="text-xs text-gray-500 mt-1">Hanya huruf kecil, angka, dan dash. Min 3 karakter.</p>
          </div>
          <div>
            <label class="text-sm font-medium block mb-1">Brand Color</label>
            <div class="flex items-center gap-2">
              <input v-model="form.brand_color" type="color" class="w-12 h-10 border rounded cursor-pointer" />
              <input v-model="form.brand_color" type="text" class="cs-input font-mono" placeholder="#0F62FE" maxlength="7" />
            </div>
          </div>
          <div class="sm:col-span-2 flex items-center gap-2">
            <label class="flex items-center gap-2 cursor-pointer">
              <input v-model="form.is_active" type="checkbox" :true-value="1" :false-value="0" />
              <span class="text-sm">Aktifkan portal karier publik</span>
            </label>
          </div>
        </div>
      </div>

      <!-- Hero / Headline -->
      <div class="bg-white border rounded-xl p-5">
        <h2 class="font-semibold mb-3">Hero & Pengumuman</h2>
        <div class="space-y-3">
          <div>
            <label class="text-sm font-medium block mb-1">Headline</label>
            <input v-model="form.headline" type="text" class="cs-input" placeholder="Bangun Karier Bersama Kami" maxlength="200" />
          </div>
          <div>
            <label class="text-sm font-medium block mb-1">Pengumuman (banner)</label>
            <input v-model="form.announcement" type="text" class="cs-input" placeholder="Kami sedang membuka 5 lowongan baru!" maxlength="500" />
          </div>
        </div>
      </div>

      <!-- About + Benefits -->
      <div class="bg-white border rounded-xl p-5">
        <h2 class="font-semibold mb-3">Konten Halaman</h2>
        <div class="space-y-3">
          <div>
            <label class="text-sm font-medium block mb-1">Tentang Kami (HTML diperbolehkan)</label>
            <textarea v-model="form.about_html" rows="5" class="cs-input font-mono text-xs" placeholder="<p>Profil singkat perusahaan...</p>"></textarea>
          </div>
          <div>
            <label class="text-sm font-medium block mb-1">Benefit & Culture (HTML)</label>
            <textarea v-model="form.benefits_html" rows="5" class="cs-input font-mono text-xs" placeholder="<ul><li>BPJS Kesehatan</li>...</ul>"></textarea>
          </div>
        </div>
      </div>

      <!-- Contact -->
      <div class="bg-white border rounded-xl p-5">
        <h2 class="font-semibold mb-3">Kontak & Sosmed</h2>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="text-sm font-medium block mb-1">Email Recruiter</label>
            <input v-model="form.contact_email" type="email" class="cs-input" placeholder="hr@perusahaan.com" />
          </div>
          <div>
            <label class="text-sm font-medium block mb-1">Nomor Telepon</label>
            <input v-model="form.contact_phone" type="tel" class="cs-input" placeholder="+62 21 1234 5678" />
          </div>
          <div>
            <label class="text-sm font-medium block mb-1">LinkedIn</label>
            <input v-model="form.linkedin_url" type="url" class="cs-input" placeholder="https://linkedin.com/company/..." />
          </div>
          <div>
            <label class="text-sm font-medium block mb-1">Instagram</label>
            <input v-model="form.instagram_url" type="url" class="cs-input" placeholder="https://instagram.com/..." />
          </div>
          <div>
            <label class="text-sm font-medium block mb-1">Website</label>
            <input v-model="form.website_url" type="url" class="cs-input" placeholder="https://perusahaan.com" />
          </div>
          <div>
            <label class="text-sm font-medium block mb-1">TikTok</label>
            <input v-model="form.tiktok_url" type="url" class="cs-input" placeholder="https://tiktok.com/@..." />
          </div>
        </div>
      </div>

      <!-- Extra questions -->
      <div class="bg-white border rounded-xl p-5">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold">Pertanyaan Tambahan (Form Apply)</h2>
          <button @click="addExtraQuestion" type="button" class="px-3 py-1.5 rounded-md bg-blue-600 text-white text-sm hover:bg-blue-700">+ Tambah</button>
        </div>
        <p class="text-xs text-gray-500 mb-3">Pertanyaan ini akan ditampilkan di bagian akhir form lamaran semua lowongan publish (kecuali job punya form schema sendiri).</p>
        <div v-if="form.extra_questions.length === 0" class="text-sm text-gray-400 text-center py-4 border border-dashed rounded">
          Belum ada pertanyaan tambahan.
        </div>
        <div v-for="(q, idx) in form.extra_questions" :key="idx" class="border rounded-lg p-3 mb-2">
          <div class="grid sm:grid-cols-12 gap-2">
            <input v-model="q.key" type="text" class="cs-input sm:col-span-3" placeholder="key (id_field)" />
            <input v-model="q.label" type="text" class="cs-input sm:col-span-5" placeholder="Label pertanyaan" />
            <select v-model="q.type" class="cs-input sm:col-span-2">
              <option value="text">Text</option>
              <option value="textarea">Textarea</option>
              <option value="number">Number</option>
              <option value="date">Date</option>
              <option value="url">URL</option>
              <option value="select">Select</option>
              <option value="checkbox">Checkbox</option>
            </select>
            <label class="text-xs flex items-center gap-1 sm:col-span-1">
              <input v-model="q.required" type="checkbox" /> Wajib
            </label>
            <button @click="removeExtraQuestion(idx)" type="button" class="text-red-500 hover:bg-red-50 rounded sm:col-span-1 text-sm">Hapus</button>
          </div>
          <div v-if="q.type === 'select'" class="mt-2">
            <input
              :value="(q.options || []).join('|')"
              @input="(e) => q.options = e.target.value.split('|').map(s => s.trim()).filter(Boolean)"
              type="text"
              class="cs-input text-xs"
              placeholder="Opsi dipisah pipe |, mis: Ya, fleksibel|Hanya weekday|Tidak"
            />
          </div>
        </div>
      </div>

      <!-- Sticky save bar -->
      <div class="sticky bottom-0 bg-white border-t py-3 px-4 -mx-4 sm:-mx-6 flex justify-end gap-2">
        <button @click="fetchData" type="button" class="px-4 py-2 rounded-md border text-sm hover:bg-gray-50">Batal</button>
        <button @click="save" :disabled="saving" type="button" class="px-6 py-2 rounded-md bg-blue-600 text-white font-medium hover:bg-blue-700 transition disabled:opacity-50">
          {{ saving ? 'Menyimpan...' : 'Simpan Perubahan' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cs-input {
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem;
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
  font-size: 0.875rem;
  background: #fff;
}
.cs-input:focus {
  outline: none;
  border-color: #0F62FE;
  box-shadow: 0 0 0 3px rgba(15, 98, 254, 0.2);
}
</style>