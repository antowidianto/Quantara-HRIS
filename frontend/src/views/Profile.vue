<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const u = computed(() => auth.user)
const e = computed(() => auth.employee)
const c = computed(() => auth.company)
</script>
<template>
  <div class="max-w-3xl">
    <h1 class="text-2xl font-bold mb-4">Profil Saya</h1>
    <div class="card">
      <div class="card-body grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
        <div><div class="text-gray-500">Nama</div><div class="font-medium">{{ u?.name }}</div></div>
        <div><div class="text-gray-500">Email</div><div>{{ u?.email }}</div></div>
        <div><div class="text-gray-500">Role</div><div>{{ auth.role?.name }}</div></div>
        <div><div class="text-gray-500">Perusahaan</div><div>{{ c?.name }}</div></div>
        <div v-if="e"><div class="text-gray-500">NIP</div><div>{{ e.employee_number }}</div></div>
        <div v-if="e"><div class="text-gray-500">Nama Lengkap</div><div>{{ e.full_name }}</div></div>
        <div><div class="text-gray-500">2FA</div><div>{{ u?.twofa_enabled ? 'Aktif' : 'Tidak aktif' }}</div></div>
      </div>
    </div>
  </div>
</template>