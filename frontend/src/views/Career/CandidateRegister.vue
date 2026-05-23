<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { useCandidateAuthStore } from '@/stores/candidateAuth'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const store = useCandidateAuthStore()

const slug = computed(() => route.params.slug)
const form = ref({ full_name: '', email: '', phone: '', password: '', password2: '' })
const submitting = ref(false)

async function submit() {
  if (form.value.password !== form.value.password2) {
    return toast.error('Konfirmasi password tidak cocok')
  }
  submitting.value = true
  try {
    await store.register(slug.value, {
      full_name: form.value.full_name,
      email: form.value.email,
      phone: form.value.phone,
      password: form.value.password,
    })
    toast.success('Akun berhasil dibuat')
    router.push(`/careers/${slug.value}/dashboard`)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal mendaftar')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="max-w-md mx-auto py-12 px-4">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
      <h1 class="text-2xl font-bold text-slate-900">Daftar Akun Pelamar</h1>
      <p class="text-sm text-slate-500 mt-1">
        Sudah punya akun?
        <router-link :to="{ name: 'candidate-login', params: { slug } }" class="text-indigo-600 font-medium hover:underline">
          Masuk di sini
        </router-link>
      </p>

      <form @submit.prevent="submit" class="space-y-4 mt-6">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Nama Lengkap</label>
          <input v-model="form.full_name" required minlength="3"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
          <input v-model="form.email" type="email" required autocomplete="email"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Nomor HP</label>
          <input v-model="form.phone" type="tel" placeholder="08xxx"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
          <input v-model="form.password" type="password" required minlength="10" autocomplete="new-password"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <p class="text-xs text-slate-500 mt-1">Min 10 karakter, harus ada huruf besar, kecil, angka, dan simbol.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Konfirmasi Password</label>
          <input v-model="form.password2" type="password" required autocomplete="new-password"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>
        <button type="submit" :disabled="submitting"
          class="w-full bg-indigo-600 text-white py-2.5 rounded-lg font-medium hover:bg-indigo-700 disabled:opacity-50">
          {{ submitting ? 'Memproses...' : 'Daftar' }}
        </button>
      </form>
    </div>
  </div>
</template>