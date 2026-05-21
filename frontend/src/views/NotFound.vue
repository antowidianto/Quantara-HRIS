<script setup>
import { RouterLink } from 'vue-router'
</script>
<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
    <div class="text-center">
      <div class="text-6xl font-bold text-primary-600 mb-2">404</div>
      <h1 class="text-2xl font-semibold mb-2">Halaman Tidak Ditemukan</h1>
      <p class="text-gray-500 mb-6">Halaman yang Anda cari tidak tersedia.</p>
      <RouterLink to="/dashboard" class="btn-primary">Kembali ke Dashboard</RouterLink>
    </div>
  </div>
</template>