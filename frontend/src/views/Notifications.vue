<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/api/client'
import { fmtDateTime } from '@/composables/useResource'

const items = ref([])
async function fetchData() {
  const res = await api.get('/notifications', { params: { per_page: 50 } })
  items.value = res.data.data
}
onMounted(fetchData)

async function markRead(id) {
  await api.post(`/notifications/${id}/read`)
  fetchData()
}
async function markAllRead() {
  await api.post('/notifications/read-all')
  fetchData()
}
</script>
<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">Notifikasi</h1>
      <button class="btn-secondary" @click="markAllRead">Tandai semua dibaca</button>
    </div>
    <div class="card">
      <div class="card-body">
        <div v-if="items.length === 0" class="text-center text-gray-400 py-8">Belum ada notifikasi</div>
        <ul class="divide-y divide-gray-100">
          <li v-for="n in items" :key="n.id" class="py-3 flex items-start justify-between gap-4"
              :class="{ 'opacity-60': n.is_read }">
            <div class="flex-1">
              <div class="font-medium">{{ n.title }}</div>
              <div class="text-sm text-gray-600">{{ n.message }}</div>
              <div class="text-xs text-gray-400 mt-1">{{ fmtDateTime(n.created_at) }} · {{ n.type }}</div>
            </div>
            <div class="flex flex-col items-end gap-1">
              <RouterLink v-if="n.link_url" :to="n.link_url" class="text-primary-600 text-sm hover:underline">Buka</RouterLink>
              <button v-if="!n.is_read" @click="markRead(n.id)" class="text-xs text-gray-500 hover:text-primary-600">Tandai dibaca</button>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>