<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import { useI18n } from '@/locales'
import {
  ChatBubbleLeftEllipsisIcon, ArrowPathIcon, KeyIcon,
  CheckCircleIcon, XCircleIcon, ExclamationTriangleIcon,
  PaperAirplaneIcon, InformationCircleIcon,
} from '@heroicons/vue/24/outline'

const { t } = useI18n()

/**
 * Sprint 9 / H1.9 — WhatsApp Provider Settings (Admin).
 *
 * Konfigurasi provider WhatsApp Business API per perusahaan. API key
 * di-encrypt at-rest dengan AES-256-GCM. Mode dry-run via env
 * WHATSAPP_DRY_RUN=true (default) untuk dev/testing.
 *
 * Endpoints yang diharapkan tersedia (atau via /settings):
 *   GET  /whatsapp/settings           — current config (api_key di-mask)
 *   PUT  /whatsapp/settings           — update provider, sender_id, api_key
 *   POST /whatsapp/settings/test      — kirim pesan test
 *   GET  /whatsapp/send-log           — recent log untuk troubleshoot
 *
 * Catatan: jika endpoint dedicated belum tersedia, fallback ke /settings
 * dengan key prefix `whatsapp.*` (sama pola dengan email-settings).
 */

const auth = useAuthStore()
const toast = useToast()

const isAdmin = computed(() =>
  auth.can('settings.manage') || auth.isSuperAdmin
)

const config = reactive({
  provider: 'FONNTE',
  sender_id: '',
  api_key: '',
  is_active: false,
  // Mask diisi backend; jika ada artinya key sudah pernah di-set
  api_key_masked: '',
})

const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const testForm = reactive({ phone: '', message: 'Test pesan dari HRIS — abaikan jika menerima ini.' })
const sendLog = ref([])

async function loadConfig() {
  loading.value = true
  try {
    // Endpoint primary; fallback ke /settings jika 404
    let res
    try {
      res = await api.get('/whatsapp/settings')
      Object.assign(config, res.data.data || {})
    } catch (e404) {
      // Fallback: baca dari /settings flat dengan prefix whatsapp.
      res = await api.get('/settings')
      const all = res.data.data || {}
      config.provider = all['whatsapp.provider'] || 'FONNTE'
      config.sender_id = all['whatsapp.sender_id'] || ''
      config.api_key_masked = all['whatsapp.api_key_masked'] || ''
      config.is_active = all['whatsapp.is_active'] === '1' || all['whatsapp.is_active'] === true
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('whatsapp_settings.loadFailed'))
  } finally {
    loading.value = false
  }
}

async function saveConfig() {
  if (! config.provider) {
    toast.warning(t('whatsapp_settings.providerRequired'))
    return
  }
  if (! config.sender_id?.trim()) {
    toast.warning(t('whatsapp_settings.senderRequired'))
    return
  }
  saving.value = true
  try {
    const payload = {
      provider: config.provider,
      sender_id: config.sender_id.trim(),
      is_active: config.is_active ? 1 : 0,
    }
    // Hanya kirim api_key kalau user mengubahnya (non-empty)
    if (config.api_key?.trim()) {
      payload.api_key = config.api_key.trim()
    }
    try {
      await api.put('/whatsapp/settings', payload)
    } catch (e404) {
      // Fallback ke /settings flat
      const flat = {
        'whatsapp.provider': payload.provider,
        'whatsapp.sender_id': payload.sender_id,
        'whatsapp.is_active': String(payload.is_active),
      }
      if (payload.api_key) flat['whatsapp.api_key'] = payload.api_key
      await api.put('/settings', flat)
    }
    toast.success(t('whatsapp_settings.saved'))
    config.api_key = ''
    await loadConfig()
  } catch (e) {
    toast.error(e.response?.data?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

async function testSend() {
  if (! testForm.phone?.trim()) {
    toast.warning(t('whatsapp_settings.targetRequired'))
    return
  }
  testing.value = true
  try {
    await api.post('/whatsapp/settings/test', {
      phone: testForm.phone.trim(),
      message: testForm.message,
    })
    toast.success(t('whatsapp_settings.testSent'))
    loadSendLog()
  } catch (e) {
    toast.error(e.response?.data?.message || t('whatsapp_settings.testFailed'))
  } finally {
    testing.value = false
  }
}

async function loadSendLog() {
  try {
    const res = await api.get('/whatsapp/send-log', { params: { per_page: 20 } })
    sendLog.value = res.data.data || []
  } catch (_) {
    // Endpoint optional
    sendLog.value = []
  }
}

function statusBadge(s) {
  const map = {
    QUEUED:        'bg-blue-100 text-blue-700',
    SENT:          'bg-blue-100 text-blue-700',
    DELIVERED:     'bg-emerald-100 text-emerald-700',
    FAILED:        'bg-red-100 text-red-700',
    RATE_LIMITED:  'bg-amber-100 text-amber-700',
  }
  return map[s] || 'bg-gray-100 text-gray-700'
}

onMounted(() => {
  if (isAdmin.value) {
    loadConfig()
    loadSendLog()
  }
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-bold flex items-center gap-2">
          <ChatBubbleLeftEllipsisIcon class="w-6 h-6" /> {{ t('whatsapp_settings.title') }}
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          {{ t('whatsapp_settings.subtitle') }}
        </p>
      </div>
      <button v-if="isAdmin" class="btn-secondary" @click="loadConfig" :disabled="loading">
        <ArrowPathIcon class="w-4 h-4" :class="loading ? 'animate-spin' : ''" /> {{ t('common.refresh') }}
      </button>
    </div>

    <div v-if="! isAdmin" class="card">
      <div class="card-body text-center py-12 text-gray-500">
        <ExclamationTriangleIcon class="w-10 h-10 mx-auto mb-2 text-amber-400" />
        {{ t('whatsapp_settings.adminOnly') }}
      </div>
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Config form -->
      <div class="card">
        <div class="card-header">
          <span class="font-medium">{{ t('whatsapp_settings.configTitle') }}</span>
        </div>
        <div class="card-body space-y-3">
          <div>
            <label class="label">{{ t('whatsapp_settings.provider') }}</label>
            <select v-model="config.provider" class="input">
              <option value="FONNTE">Fonnte (Indonesia)</option>
              <option value="WATI">Wati</option>
              <option value="TWILIO">Twilio</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">
              {{ t('whatsapp_settings.providerHelp') }}
            </p>
          </div>
          <div>
            <label class="label">{{ t('whatsapp_settings.senderId') }}</label>
            <input v-model="config.sender_id" class="input"
              placeholder="contoh: 6281234567890 atau ID akun" />
          </div>
          <div>
            <label class="label flex items-center gap-1">
              <KeyIcon class="w-4 h-4" /> {{ t('whatsapp_settings.apiKey') }}
            </label>
            <input v-model="config.api_key" type="password" class="input"
              :placeholder="config.api_key_masked
                ? t('whatsapp_settings.apiKeyPlaceholderMasked', { mask: config.api_key_masked })
                : t('whatsapp_settings.apiKeyPlaceholder')" />
            <p class="text-xs text-gray-500 mt-1">
              {{ t('whatsapp_settings.apiKeyHelp') }}
            </p>
          </div>
          <div class="flex items-center gap-2">
            <input id="wa-active" type="checkbox" v-model="config.is_active" />
            <label for="wa-active" class="text-sm">{{ t('whatsapp_settings.activate') }}</label>
          </div>
          <div class="bg-blue-50 border border-blue-200 rounded p-2 text-xs text-blue-800 flex items-start gap-2">
            <InformationCircleIcon class="w-4 h-4 flex-shrink-0 mt-0.5" />
            <div>
              {{ t('whatsapp_settings.rateLimitHelp') }}
            </div>
          </div>
          <button class="btn-primary w-full" @click="saveConfig" :disabled="saving">
            {{ saving ? t('common.saving') : t('whatsapp_settings.saveButton') }}
          </button>
        </div>
      </div>

      <!-- Test send -->
      <div class="card">
        <div class="card-header">
          <span class="font-medium">{{ t('whatsapp_settings.testTitle') }}</span>
        </div>
        <div class="card-body space-y-3">
          <div>
            <label class="label">{{ t('whatsapp_settings.testTarget') }}</label>
            <input v-model="testForm.phone" class="input"
              placeholder="081234567890 (auto-konversi ke 62)" />
          </div>
          <div>
            <label class="label">{{ t('whatsapp_settings.testMessage') }}</label>
            <textarea v-model="testForm.message" class="input" rows="3"></textarea>
          </div>
          <button class="btn-secondary w-full" @click="testSend"
            :disabled="testing || ! config.is_active">
            <PaperAirplaneIcon class="w-4 h-4" />
            {{ testing ? t('common.sending') : t('whatsapp_settings.testSendButton') }}
          </button>
          <p v-if="! config.is_active" class="text-xs text-amber-600 text-center">
            {{ t('whatsapp_settings.activateFirst') }}
          </p>
        </div>
      </div>
    </div>

    <!-- Send log -->
    <div v-if="isAdmin" class="card mt-4">
      <div class="card-header flex items-center justify-between">
        <span class="font-medium">{{ t('whatsapp_settings.logTitle') }}</span>
        <button class="btn-icon" @click="loadSendLog" :title="t('common.refresh')">
          <ArrowPathIcon class="w-4 h-4" />
        </button>
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>{{ t('whatsapp_settings.log.time') }}</th>
              <th>{{ t('whatsapp_settings.log.target') }}</th>
              <th>{{ t('whatsapp_settings.log.message') }}</th>
              <th>{{ t('whatsapp_settings.log.status') }}</th>
              <th>{{ t('whatsapp_settings.log.error') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="sendLog.length === 0">
              <td colspan="5" class="text-center py-8 text-gray-400">
                {{ t('whatsapp_settings.log.noData') }}
              </td>
            </tr>
            <tr v-for="r in sendLog" :key="r.id">
              <td class="text-xs text-gray-500">{{ r.created_at?.slice(0, 19).replace('T', ' ') }}</td>
              <td class="font-mono text-xs">{{ r.phone }}</td>
              <td class="text-sm text-gray-700 max-w-md truncate">{{ r.message_preview || r.message }}</td>
              <td>
                <span class="inline-block px-2 py-0.5 rounded text-xs" :class="statusBadge(r.status)">
                   {{ r.status }}
                </span>
              </td>
              <td class="text-xs text-red-600">{{ r.error_message || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
