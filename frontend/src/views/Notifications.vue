<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { fmtDateTime } from '@/composables/useResource'
import { useI18n } from '@/locales'

const { t } = useI18n()
const router = useRouter()
const items = ref([])
const isUnread = (notification) => Number(notification?.is_read) === 0
async function fetchData() {
  const res = await api.get('/notifications', { params: { per_page: 50 } })
  items.value = res.data.data
}
onMounted(fetchData)

async function markRead(id) {
  const item = items.value.find(n => Number(n.id) === Number(id))
  if (item) item.is_read = 1
  await api.post(`/notifications/${id}/read`)
}
async function markAllRead() {
  items.value.forEach(n => { n.is_read = 1 })
  await api.post('/notifications/read-all')
}
async function openNotification(notification) {
  if (isUnread(notification)) await markRead(notification.id)
  if (notification.link_url) router.push(notification.link_url)
}
</script>
<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('nav.notifications') }}</h1>
      <button class="btn-secondary" @click="markAllRead">{{ t('nav.mark_all_read') }}</button>
    </div>
    <div class="card">
      <div class="card-body">
        <div v-if="items.length === 0" class="text-center text-gray-400 py-8">{{ t('nav.no_notifications') }}</div>
        <ul class="divide-y divide-gray-100">
          <li v-for="n in items" :key="n.id" class="px-4 py-3 flex items-start justify-between gap-4 border-l-4 transition-colors"
              :class="isUnread(n) ? 'bg-blue-50/70 border-primary-500' : 'bg-white border-transparent'">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span v-if="isUnread(n)" class="w-2 h-2 rounded-full bg-primary-600 flex-shrink-0" title="Belum dibaca"></span>
                <div :class="isUnread(n) ? 'font-semibold text-gray-900' : 'font-medium text-gray-600'">{{ n.title }}</div>
              </div>
              <div class="text-sm" :class="isUnread(n) ? 'text-gray-700' : 'text-gray-500'">{{ n.message }}</div>
              <div class="text-xs text-gray-400 mt-1">{{ fmtDateTime(n.created_at) }} · {{ n.type }}</div>
            </div>
            <div class="flex flex-col items-end gap-1">
              <button v-if="n.link_url" type="button" @click="openNotification(n)" class="text-primary-600 text-sm hover:underline">{{ t('common.open_link') }}</button>
              <button v-if="isUnread(n)" @click="markRead(n.id)" class="text-xs text-gray-500 hover:text-primary-600">{{ t('common.mark_read') }}</button>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
