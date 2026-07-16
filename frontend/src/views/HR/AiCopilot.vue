<script setup>
import { ref, reactive, computed, onMounted, nextTick, watch } from 'vue'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import {
  ChatBubbleLeftRightIcon, PlusIcon, ArchiveBoxIcon, PaperAirplaneIcon,
  ArrowPathIcon, SparklesIcon, ShieldCheckIcon, UserCircleIcon,
} from '@heroicons/vue/24/outline'

/**
 * Sprint 19+ / H4.1 — AI HR Copilot.
 *
 * Chat UI untuk berinteraksi dengan AI assistant. Setiap sesi punya
 * scope yang ditentukan saat create (mis. "general", "recruitment",
 * "payroll"). PII di-redact sebelum dikirim ke LLM.
 *
 * Endpoints:
 *   GET  /ai/sessions                    — list session aktif
 *   POST /ai/sessions                    — create session baru
 *   GET  /ai/sessions/:id/messages       — history chat
 *   POST /ai/sessions/:id/messages       — kirim message + dapatkan reply
 *   POST /ai/sessions/:id/archive        — archive session
 */

const toast = useToast()

const sessions = ref([])
const activeSessionId = ref(null)
const messages = ref([])
const loadingSessions = ref(false)
const loadingMessages = ref(false)
const sending = ref(false)

const newMessage = ref('')
const showNewSession = ref(false)
const newSessionForm = reactive({
  title: '',
  scope: 'general',
})

const messagesContainer = ref(null)

async function loadSessions() {
  loadingSessions.value = true
  try {
    const res = await api.get('/ai/sessions')
    sessions.value = res.data.data || []
    if (sessions.value.length && ! activeSessionId.value) {
      // Auto-pilih session pertama (paling baru)
      selectSession(sessions.value[0].id)
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat sessions')
  } finally {
    loadingSessions.value = false
  }
}

async function selectSession(id) {
  activeSessionId.value = id
  await loadMessages()
}

async function loadMessages() {
  if (! activeSessionId.value) return
  loadingMessages.value = true
  try {
    const res = await api.get(`/ai/sessions/${activeSessionId.value}/messages`)
    messages.value = res.data.data || []
    await nextTick()
    scrollToBottom()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal memuat messages')
  } finally {
    loadingMessages.value = false
  }
}

async function startSession() {
  if (! newSessionForm.title.trim()) {
    toast.warning('Beri title sesi (minimal "Quick chat")')
    return
  }
  try {
    const res = await api.post('/ai/sessions', newSessionForm)
    const created = res.data.data
    showNewSession.value = false
    newSessionForm.title = ''
    newSessionForm.scope = 'general'
    await loadSessions()
    if (created?.id) selectSession(created.id)
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal membuat session')
  }
}

async function sendMessage() {
  if (! newMessage.value.trim()) return
  if (! activeSessionId.value) {
    toast.warning('Pilih atau buat session dulu')
    return
  }
  const text = newMessage.value
  // Optimistic add user message
  const optimistic = {
    id: 'temp-' + Date.now(),
    role: 'user',
    content: text,
    created_at: new Date().toISOString(),
  }
  messages.value.push(optimistic)
  newMessage.value = ''
  await nextTick()
  scrollToBottom()
  sending.value = true
  try {
    const res = await api.post(`/ai/sessions/${activeSessionId.value}/messages`, {
      content: text,
    })
    // Replace optimistic + append assistant reply
    const data = res.data.data
    if (data?.user_message) {
      const idx = messages.value.findIndex(m => m.id === optimistic.id)
      if (idx >= 0) messages.value[idx] = data.user_message
    }
    if (data?.assistant_message) {
      messages.value.push(data.assistant_message)
    }
    await nextTick()
    scrollToBottom()
  } catch (e) {
    // Rollback optimistic
    messages.value = messages.value.filter(m => m.id !== optimistic.id)
    newMessage.value = text
    toast.error(e.response?.data?.message || 'AI gagal merespon')
  } finally {
    sending.value = false
  }
}

async function archiveSession(id) {
  if (! confirm('Arsipkan session ini?')) return
  try {
    await api.post(`/ai/sessions/${id}/archive`)
    toast.success('Session diarsipkan')
    if (activeSessionId.value === id) {
      activeSessionId.value = null
      messages.value = []
    }
    loadSessions()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Gagal archive')
  }
}

function scrollToBottom() {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}

const activeSession = computed(() =>
  sessions.value.find(s => s.id === activeSessionId.value) || null
)

function fmtTime(ts) {
  if (! ts) return ''
  return new Date(ts).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

function handleKeyDown(e) {
  // Enter to send, Shift+Enter for newline
  if (e.key === 'Enter' && ! e.shiftKey) {
    e.preventDefault()
    sendMessage()
  }
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

/**
 * Render subset Markdown yang aman untuk balasan AI. Link hanya boleh
 * menuju halaman internal aplikasi; API endpoint dan URL eksternal ditolak.
 */
function renderAssistantMessage(content) {
  let html = escapeHtml(content)
  html = html.replace(/\[([^\]\n]{1,120})\]\((\/[A-Za-z0-9_/?#=&.-]{0,240})\)/g, (full, label, href) => {
    if (href.startsWith('/api/') || href.startsWith('//')) return label
    return `<a href="${href}" class="font-medium text-primary-600 underline hover:text-primary-700">${label}</a>`
  })
  html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
  html = html.replace(/`([^`\n]+)`/g, '<code class="rounded bg-gray-100 px-1 py-0.5 text-xs">$1</code>')
  return html.replace(/\n/g, '<br>')
}

onMounted(loadSessions)
</script>

<template>
  <div class="flex h-[calc(100vh-7rem)] gap-3">
    <!-- Sidebar: session list -->
    <aside class="w-72 flex-shrink-0 card flex flex-col">
      <div class="card-header flex items-center justify-between">
        <span class="font-medium flex items-center gap-2">
          <ChatBubbleLeftRightIcon class="w-4 h-4" /> Sessions
        </span>
        <button class="btn-icon text-primary-600" @click="showNewSession = true" title="New session">
          <PlusIcon class="w-4 h-4" />
        </button>
      </div>
      <div class="flex-1 overflow-y-auto">
        <div v-if="loadingSessions" class="p-4 text-center text-gray-400 text-sm">Memuat…</div>
        <div v-else-if="sessions.length === 0" class="p-4 text-center text-gray-400 text-sm">
          Belum ada session. Klik + untuk mulai.
        </div>
        <button v-for="s in sessions" :key="s.id"
          @click="selectSession(s.id)"
          :class="[
            'w-full text-left px-3 py-2.5 border-b border-gray-100 hover:bg-gray-50',
            activeSessionId === s.id ? 'bg-primary-50' : ''
          ]">
          <div class="flex items-start justify-between gap-2">
            <div class="flex-1 min-w-0">
              <div class="font-medium text-sm truncate">{{ s.title }}</div>
              <div class="flex items-center gap-2 mt-0.5">
                <span class="text-xs text-gray-500 uppercase">{{ s.scope }}</span>
                <span class="text-xs text-gray-400">• {{ s.message_count || 0 }} msg</span>
              </div>
            </div>
            <button class="btn-icon text-gray-400 hover:text-red-600 -mr-1"
              @click.stop="archiveSession(s.id)" title="Archive">
              <ArchiveBoxIcon class="w-4 h-4" />
            </button>
          </div>
        </button>
      </div>
    </aside>

    <!-- Main: chat -->
    <section class="flex-1 card flex flex-col">
      <div v-if="! activeSessionId" class="flex-1 flex items-center justify-center text-gray-400 flex-col gap-3">
        <SparklesIcon class="w-12 h-12 text-gray-300" />
        <div class="text-center">
          <div class="font-medium text-gray-600">Pilih atau buat session untuk mulai chat</div>
          <div class="text-sm mt-1">AI assistant siap bantu pertanyaan HR-related</div>
        </div>
        <button class="btn-primary mt-2" @click="showNewSession = true">
          <PlusIcon class="w-4 h-4" /> Mulai Session Baru
        </button>
      </div>

      <template v-else>
        <!-- Session header -->
        <div class="card-header flex items-center justify-between">
          <div>
            <div class="font-medium">{{ activeSession?.title || '—' }}</div>
            <div class="text-xs text-gray-500 flex items-center gap-2 mt-0.5">
              <span>scope: {{ activeSession?.scope }}</span>
              <span class="inline-flex items-center gap-1 text-emerald-600">
                <ShieldCheckIcon class="w-3 h-3" /> PII redacted
              </span>
            </div>
          </div>
          <button class="btn-icon" @click="loadMessages" :disabled="loadingMessages" title="Refresh">
            <ArrowPathIcon class="w-4 h-4" :class="loadingMessages ? 'animate-spin' : ''" />
          </button>
        </div>

        <!-- Messages -->
        <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50">
          <div v-if="loadingMessages" class="text-center text-gray-400 py-4">Memuat history…</div>
          <div v-else-if="messages.length === 0" class="text-center text-gray-400 py-8">
            <SparklesIcon class="w-10 h-10 mx-auto mb-2 text-gray-300" />
            <p>Mulai dengan pertanyaan, mis:</p>
            <p class="text-sm italic mt-1">
              "Siapa di Sales yang belum ambil cuti tahun ini?" /
              "Draft email penolakan kandidat untuk posisi X"
            </p>
          </div>
          <div v-for="m in messages" :key="m.id"
            :class="['flex gap-2', m.role === 'user' ? 'justify-end' : 'justify-start']">
            <div v-if="m.role !== 'user'"
              class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center flex-shrink-0">
              <SparklesIcon class="w-4 h-4" />
            </div>
            <div :class="[
              'max-w-2xl rounded-lg px-4 py-2.5 shadow-sm',
              m.role === 'user'
                ? 'bg-primary-600 text-white'
                : 'bg-white border border-gray-200 text-gray-800'
            ]">
              <div v-if="m.role === 'user'" class="whitespace-pre-wrap text-sm">{{ m.content }}</div>
              <div v-else class="text-sm leading-6" v-html="renderAssistantMessage(m.content)"></div>
              <div :class="[
                'text-xs mt-1 flex items-center gap-2',
                m.role === 'user' ? 'text-primary-100' : 'text-gray-400'
              ]">
                {{ fmtTime(m.created_at) }}
                <span v-if="m.tokens" class="ml-auto">{{ m.tokens }} tokens</span>
                <span v-if="m.was_redacted" class="text-amber-500" title="PII di-redact sebelum dikirim ke LLM">
                  ⓘ redacted
                </span>
              </div>
            </div>
            <div v-if="m.role === 'user'"
              class="w-8 h-8 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center flex-shrink-0">
              <UserCircleIcon class="w-5 h-5" />
            </div>
          </div>
          <div v-if="sending" class="flex gap-2 justify-start">
            <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center">
              <SparklesIcon class="w-4 h-4 animate-pulse" />
            </div>
            <div class="bg-white border border-gray-200 rounded-lg px-4 py-2.5 text-sm text-gray-500">
              <span class="animate-pulse">AI sedang memproses…</span>
            </div>
          </div>
        </div>

        <!-- Composer -->
        <div class="border-t p-3 bg-white">
          <div class="flex items-end gap-2">
            <textarea
              v-model="newMessage"
              rows="2"
              class="input flex-1 resize-none"
              placeholder="Ketik pertanyaan… (Enter untuk kirim, Shift+Enter untuk baris baru)"
              @keydown="handleKeyDown"
              :disabled="sending"
            ></textarea>
            <button class="btn-primary h-12" @click="sendMessage" :disabled="sending || ! newMessage.trim()">
              <PaperAirplaneIcon class="w-4 h-4" />
            </button>
          </div>
          <div class="text-xs text-gray-400 mt-1.5 flex items-center gap-1">
            <ShieldCheckIcon class="w-3 h-3" />
            Pesan akan diproses dengan PII redactor (NIK/NPWP/email/phone/rekening) sebelum
            dikirim ke LLM. No-train flag aktif.
          </div>
        </div>
      </template>
    </section>

    <!-- Modal: New session -->
    <div v-if="showNewSession" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="showNewSession = false">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-3 border-b">
          <h3 class="font-semibold">Mulai Session Baru</h3>
        </div>
        <div class="p-5 space-y-3">
          <div>
            <label class="label">Title</label>
            <input v-model="newSessionForm.title" class="input"
              placeholder="contoh: Recruitment brainstorm Q3" />
          </div>
          <div>
            <label class="label">Scope</label>
            <select v-model="newSessionForm.scope" class="input">
              <option value="general">General — pertanyaan umum HR</option>
              <option value="recruitment">Recruitment — kandidat, sourcing, offer</option>
              <option value="payroll">Payroll — perhitungan, PPh21, BPJS</option>
              <option value="performance">Performance — review, OKR, IDP</option>
              <option value="compliance">Compliance — UU 13, PP 35, BPJS</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">
              Scope membantu AI memberikan konteks lebih relevan + filter data yang
              boleh diakses.
            </p>
          </div>
        </div>
        <div class="px-5 py-3 border-t flex justify-end gap-2">
          <button class="btn-secondary" @click="showNewSession = false">Batal</button>
          <button class="btn-primary" @click="startSession">Mulai</button>
        </div>
      </div>
    </div>
  </div>
</template>
