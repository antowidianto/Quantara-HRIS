<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useToast } from 'vue-toastification'
import {
  ArrowLeftIcon,
  ArrowRightIcon,
  BuildingOffice2Icon,
  ChartBarSquareIcon,
  CheckIcon,
  EyeIcon,
  EyeSlashIcon,
  GlobeAltIcon,
  LockClosedIcon,
  MoonIcon,
  ShieldCheckIcon,
  SunIcon,
  UserIcon,
} from '@heroicons/vue/24/outline'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useDarkMode } from '@/composables/useDarkMode'
import { useI18n } from '@/locales'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const toast = useToast()
const { t, locale, setLocale } = useI18n()
const { effective: activeTheme, setMode: setTheme } = useDarkMode()

const identifier = ref('')
const password = ref('')
const loading = ref(false)
const showPassword = ref(false)
const requires2fa = ref(false)
const challenge = ref('')
const otpCode = ref('')

const branding = ref({
  app_name: 'Quantara HRIS',
  logo_path: '',
  favicon_path: '',
  primary_color: '#1E40AF',
  secondary_color: '#3B82F6',
  login_bg_path: '',
})

const currentYear = new Date().getFullYear()
const appName = computed(() => branding.value.app_name || t('auth.title'))
const brandInitial = computed(() => appName.value.trim().charAt(0).toUpperCase() || 'Q')

function normalizeColor(value, fallback) {
  return /^#[0-9a-f]{6}$/i.test(value || '') ? value : fallback
}

function publicAssetUrl(value) {
  if (!value || typeof window === 'undefined') return ''
  try {
    const url = new URL(value, window.location.origin)
    return ['http:', 'https:'].includes(url.protocol) ? url.href : ''
  } catch (_) {
    return ''
  }
}

const logoUrl = computed(() => publicAssetUrl(branding.value.logo_path))
const pageStyle = computed(() => ({
  '--brand-primary': normalizeColor(branding.value.primary_color, '#1E40AF'),
  '--brand-secondary': normalizeColor(branding.value.secondary_color, '#3B82F6'),
}))
const marketingPanelStyle = computed(() => {
  const background = publicAssetUrl(branding.value.login_bg_path)
  return background
    ? {
        backgroundImage: `linear-gradient(135deg, rgb(6 16 38 / 0.94), rgb(15 35 72 / 0.84)), url("${background.replaceAll('"', '%22')}")`,
      }
    : {}
})

async function loadBranding() {
  try {
    const response = await api.get('/branding/by-host', {
      params: { host: window.location.hostname },
    })
    branding.value = { ...branding.value, ...(response.data?.data || {}) }

    document.title = `${appName.value} — ${t('auth.sign_in')}`
    const favicon = publicAssetUrl(branding.value.favicon_path)
    if (favicon) {
      const faviconElement = document.querySelector('link[rel~="icon"]')
      if (faviconElement) faviconElement.setAttribute('href', favicon)
    }
  } catch (_) {
    // Branding is an enhancement. Login must remain available if it fails.
  }
}

function toggleTheme() {
  setTheme(activeTheme.value === 'dark' ? 'light' : 'dark')
}

function toggleLocale() {
  setLocale(locale.value === 'id' ? 'en' : 'id')
}

function updateOtp(event) {
  otpCode.value = event.target.value.replace(/\D/g, '').slice(0, 6)
}

function backToCredentials() {
  requires2fa.value = false
  otpCode.value = ''
}

async function submit() {
  loading.value = true
  try {
    const result = await auth.login({ identifier: identifier.value, password: password.value })
    if (result?.requires_2fa) {
      requires2fa.value = true
      challenge.value = result.challenge
      toast.info(t('auth.twofa_required'))
      return
    }
    if (result?.mustChangePassword) {
      router.push('/change-password')
      return
    }
    router.push(route.query.redirect || '/dashboard')
  } catch (e) {
    toast.error(e.response?.data?.message || t('auth.login_failed'))
  } finally {
    loading.value = false
  }
}

async function submitOtp() {
  loading.value = true
  try {
    await auth.verifyTwoFactor(challenge.value, otpCode.value)
    router.push(route.query.redirect || '/dashboard')
  } catch (e) {
    toast.error(e.response?.data?.message || t('auth.twofa_invalid'))
  } finally {
    loading.value = false
  }
}

onMounted(loadBranding)
</script>

<template>
  <main class="login-shell" :style="pageStyle">
    <aside class="marketing-panel" :style="marketingPanelStyle">
      <div class="pattern-grid" aria-hidden="true"></div>
      <div class="brand-orb brand-orb-one" aria-hidden="true"></div>
      <div class="brand-orb brand-orb-two" aria-hidden="true"></div>

      <div class="marketing-content">
        <div class="brand-lockup">
          <div class="brand-mark">
            <img v-if="logoUrl" :src="logoUrl" v-bind="{ alt: appName }" class="brand-logo" />
            <span v-else>{{ brandInitial }}</span>
          </div>
          <div>
            <p class="brand-name">{{ appName }}</p>
            <p class="brand-caption">{{ t('login_marketing.suite_caption') }}</p>
          </div>
        </div>

        <div class="marketing-copy">
          <div class="enterprise-pill">
            <span class="status-dot"></span>
            {{ t('login_marketing.eyebrow') }}
          </div>
          <h1>{{ t('login_marketing.hero_title') }}</h1>
          <p class="hero-description">{{ t('login_marketing.hero_description') }}</p>

          <div class="benefit-list">
            <div class="benefit-item">
              <div class="benefit-icon"><ChartBarSquareIcon /></div>
              <div>
                <h2>{{ t('login_marketing.visibility_title') }}</h2>
                <p>{{ t('login_marketing.visibility_description') }}</p>
              </div>
            </div>
            <div class="benefit-item">
              <div class="benefit-icon"><BuildingOffice2Icon /></div>
              <div>
                <h2>{{ t('login_marketing.scale_title') }}</h2>
                <p>{{ t('login_marketing.scale_description') }}</p>
              </div>
            </div>
            <div class="benefit-item">
              <div class="benefit-icon"><ShieldCheckIcon /></div>
              <div>
                <h2>{{ t('login_marketing.security_title') }}</h2>
                <p>{{ t('login_marketing.security_description') }}</p>
              </div>
            </div>
          </div>
        </div>

        <div
          class="capability-strip"
          v-bind="{ 'aria-label': t('login_marketing.capabilities_label') }"
        >
          <span><CheckIcon />{{ t('login_marketing.multi_company') }}</span>
          <span><CheckIcon />{{ t('login_marketing.audit_trail') }}</span>
          <span><CheckIcon />{{ t('login_marketing.indonesia_ready') }}</span>
        </div>
      </div>
    </aside>

    <section class="login-panel">
      <div class="utility-actions">
        <button
          type="button"
          class="utility-button"
          v-bind="{ title: t('language.label'), 'aria-label': t('language.label') }"
          @click="toggleLocale"
        >
          <GlobeAltIcon />
          <span>{{ locale.toUpperCase() }}</span>
        </button>
        <button
          type="button"
          class="utility-button utility-icon-button"
          v-bind="{ title: t('theme.title'), 'aria-label': t('theme.title') }"
          @click="toggleTheme"
        >
          <SunIcon v-if="activeTheme === 'dark'" />
          <MoonIcon v-else />
        </button>
      </div>

      <div class="form-container">
        <div class="mobile-brand">
          <div class="brand-mark brand-mark-small">
            <img v-if="logoUrl" :src="logoUrl" v-bind="{ alt: appName }" class="brand-logo" />
            <span v-else>{{ brandInitial }}</span>
          </div>
          <span>{{ appName }}</span>
        </div>

        <Transition name="auth-step" mode="out-in">
          <div v-if="!requires2fa" key="credentials">
            <div class="form-heading">
              <div class="secure-kicker">
                <LockClosedIcon />{{ t('login_marketing.secure_access') }}
              </div>
              <h2>{{ t('login_marketing.welcome_back') }}</h2>
              <p>{{ t('login_marketing.login_description') }}</p>
            </div>

            <form class="login-form" @submit.prevent="submit">
              <div class="field-group">
                <label for="identifier">{{ t('auth.identifier') }}</label>
                <div class="input-wrap">
                  <UserIcon class="field-icon" />
                  <input
                    id="identifier"
                    v-model.trim="identifier"
                    type="text"
                    required
                    autofocus
                    autocomplete="username"
                    v-bind="{ placeholder: t('auth.identifier_placeholder') }"
                  />
                </div>
                <p class="field-hint">{{ t('login_marketing.identifier_hint') }}</p>
              </div>

              <div class="field-group">
                <div class="label-row">
                  <label for="password">{{ t('auth.password') }}</label>
                  <RouterLink to="/forgot-password">{{ t('auth.forgot_password') }}</RouterLink>
                </div>
                <div class="input-wrap">
                  <LockClosedIcon class="field-icon" />
                  <input
                    id="password"
                    v-model="password"
                    :type="showPassword ? 'text' : 'password'"
                    required
                    autocomplete="current-password"
                    class="password-input"
                  />
                  <button
                    type="button"
                    class="password-toggle"
                    v-bind="{
                      title: showPassword ? t('auth.hide_password') : t('auth.show_password'),
                      'aria-label': showPassword
                        ? t('auth.hide_password')
                        : t('auth.show_password'),
                    }"
                    @click="showPassword = !showPassword"
                  >
                    <EyeSlashIcon v-if="showPassword" />
                    <EyeIcon v-else />
                  </button>
                </div>
              </div>

              <button type="submit" class="submit-button" :disabled="loading">
                <span v-if="loading" class="button-spinner" aria-hidden="true"></span>
                <span>{{ loading ? t('auth.signing_in') : t('auth.sign_in') }}</span>
                <ArrowRightIcon v-if="!loading" />
              </button>
            </form>

            <div class="session-note">
              <ShieldCheckIcon />
              <span>{{ t('login_marketing.session_note') }}</span>
            </div>
          </div>

          <div v-else key="two-factor" class="two-factor-step">
            <div class="verification-icon">
              <ShieldCheckIcon />
            </div>
            <div class="form-heading form-heading-centered">
              <div class="secure-kicker">{{ t('login_marketing.identity_check') }}</div>
              <h2>{{ t('auth.twofa_title') }}</h2>
              <p>{{ t('auth.twofa_subtitle') }}</p>
            </div>

            <form class="login-form" @submit.prevent="submitOtp">
              <div class="field-group">
                <label for="otp-code" class="sr-only">{{ t('auth.twofa_code') }}</label>
                <input
                  id="otp-code"
                  :value="otpCode"
                  type="text"
                  inputmode="numeric"
                  pattern="[0-9]*"
                  maxlength="6"
                  required
                  autofocus
                  autocomplete="one-time-code"
                  class="otp-input"
                  placeholder="000000"
                  @input="updateOtp"
                />
              </div>
              <button
                type="submit"
                class="submit-button"
                :disabled="loading || otpCode.length !== 6"
              >
                <span v-if="loading" class="button-spinner" aria-hidden="true"></span>
                <span>{{ loading ? t('auth.verifying') : t('auth.verify') }}</span>
                <ArrowRightIcon v-if="!loading" />
              </button>
              <button type="button" class="back-button" @click="backToCredentials">
                <ArrowLeftIcon />{{ t('auth.back') }}
              </button>
            </form>
          </div>
        </Transition>

        <p class="support-copy">{{ t('login_marketing.support') }}</p>
      </div>

      <footer>
        {{ t('auth.encrypted_footer', { year: currentYear }) }}
        <span aria-hidden="true">•</span>
        {{ t('login_marketing.privacy_note') }}
      </footer>
    </section>
  </main>
</template>

<style scoped>
.login-shell {
  --brand-primary: #1e40af;
  --brand-secondary: #3b82f6;
  min-height: 100vh;
  display: grid;
  grid-template-columns: minmax(0, 1.08fr) minmax(480px, 0.92fr);
  background: #f8fafc;
  color: #0f172a;
}

.marketing-panel {
  position: relative;
  isolation: isolate;
  min-height: 100vh;
  overflow: hidden;
  background-color: #071329;
  background-size: cover;
  background-position: center;
  color: white;
}

.marketing-panel::after {
  content: '';
  position: absolute;
  inset: 0;
  z-index: -1;
  background: linear-gradient(180deg, rgb(7 19 41 / 0.05), rgb(7 19 41 / 0.5));
}

.pattern-grid {
  position: absolute;
  inset: 0;
  z-index: -3;
  opacity: 0.2;
  background-image:
    linear-gradient(rgb(148 163 184 / 0.16) 1px, transparent 1px),
    linear-gradient(90deg, rgb(148 163 184 / 0.16) 1px, transparent 1px);
  background-size: 52px 52px;
  mask-image: linear-gradient(to bottom right, black, transparent 72%);
}

.brand-orb {
  position: absolute;
  z-index: -2;
  border-radius: 999px;
  filter: blur(2px);
  pointer-events: none;
}

.brand-orb-one {
  width: 32rem;
  height: 32rem;
  top: -13rem;
  right: -8rem;
  background: radial-gradient(
    circle,
    color-mix(in srgb, var(--brand-secondary) 55%, transparent),
    transparent 68%
  );
  opacity: 0.68;
}

.brand-orb-two {
  width: 26rem;
  height: 26rem;
  bottom: -12rem;
  left: -8rem;
  background: radial-gradient(
    circle,
    color-mix(in srgb, var(--brand-primary) 52%, transparent),
    transparent 70%
  );
  opacity: 0.6;
}

.marketing-content {
  width: 100%;
  min-height: 100vh;
  max-width: 820px;
  margin: 0 auto;
  padding: clamp(2rem, 5vw, 4.5rem);
  display: flex;
  flex-direction: column;
}

.brand-lockup {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}

.brand-mark {
  width: 3rem;
  height: 3rem;
  flex: 0 0 auto;
  display: grid;
  place-items: center;
  overflow: hidden;
  border-radius: 0.9rem;
  background: linear-gradient(135deg, var(--brand-secondary), var(--brand-primary));
  box-shadow:
    0 12px 30px rgb(0 0 0 / 0.25),
    inset 0 0 0 1px rgb(255 255 255 / 0.18);
  color: white;
  font-size: 1.2rem;
  font-weight: 800;
  letter-spacing: -0.04em;
}

.brand-logo {
  width: 100%;
  height: 100%;
  object-fit: contain;
  background: white;
  padding: 0.35rem;
}

.brand-name {
  font-size: 1rem;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.brand-caption {
  margin-top: 0.15rem;
  color: #94a3b8;
  font-size: 0.675rem;
  font-weight: 600;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.marketing-copy {
  margin: auto 0;
  padding: 3.5rem 0;
}

.enterprise-pill,
.secure-kicker {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  color: #bfdbfe;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.enterprise-pill {
  padding: 0.5rem 0.75rem;
  border: 1px solid rgb(147 197 253 / 0.2);
  border-radius: 999px;
  background: rgb(30 64 175 / 0.18);
  backdrop-filter: blur(10px);
}

.status-dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: #60a5fa;
  box-shadow: 0 0 0 5px rgb(96 165 250 / 0.12);
}

.marketing-copy > h1 {
  max-width: 680px;
  margin-top: 1.75rem;
  font-size: clamp(2.45rem, 4.5vw, 4.25rem);
  line-height: 1.02;
  letter-spacing: -0.052em;
  font-weight: 750;
  text-wrap: balance;
}

.hero-description {
  max-width: 640px;
  margin-top: 1.5rem;
  color: #cbd5e1;
  font-size: clamp(1rem, 1.3vw, 1.125rem);
  line-height: 1.8;
}

.benefit-list {
  display: grid;
  gap: 1rem;
  margin-top: 2.5rem;
}

.benefit-item {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  max-width: 650px;
  padding: 0.8rem 0;
}

.benefit-icon {
  width: 2.5rem;
  height: 2.5rem;
  flex: 0 0 auto;
  display: grid;
  place-items: center;
  border: 1px solid rgb(148 163 184 / 0.16);
  border-radius: 0.75rem;
  background: rgb(255 255 255 / 0.07);
  color: #93c5fd;
}

.benefit-icon svg {
  width: 1.2rem;
}

.benefit-item h2 {
  font-size: 0.9rem;
  font-weight: 700;
}

.benefit-item p {
  margin-top: 0.3rem;
  color: #94a3b8;
  font-size: 0.82rem;
  line-height: 1.6;
}

.capability-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.5rem;
  padding-top: 1.35rem;
  border-top: 1px solid rgb(148 163 184 / 0.15);
  color: #cbd5e1;
  font-size: 0.72rem;
  font-weight: 600;
}

.capability-strip span {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.capability-strip svg {
  width: 0.9rem;
  color: #60a5fa;
  stroke-width: 2.5;
}

.login-panel {
  position: relative;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 5rem clamp(2rem, 5vw, 5.5rem) 2rem;
  background: radial-gradient(circle at 85% 10%, rgb(219 234 254 / 0.55), transparent 30%), #f8fafc;
}

.utility-actions {
  position: absolute;
  top: 1.5rem;
  right: clamp(1.5rem, 4vw, 3rem);
  display: flex;
  gap: 0.5rem;
}

.utility-button {
  min-height: 2.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  padding: 0.55rem 0.75rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
  background: rgb(255 255 255 / 0.82);
  color: #475569;
  box-shadow: 0 4px 16px rgb(15 23 42 / 0.04);
  font-size: 0.74rem;
  font-weight: 700;
  transition:
    border-color 150ms,
    color 150ms,
    transform 150ms,
    background 150ms;
}

.utility-button:hover {
  border-color: #cbd5e1;
  background: white;
  color: var(--brand-primary);
  transform: translateY(-1px);
}

.utility-button svg {
  width: 1rem;
}

.utility-icon-button {
  width: 2.5rem;
  padding: 0;
}

.form-container {
  width: min(100%, 440px);
}

.mobile-brand {
  display: none;
  align-items: center;
  gap: 0.7rem;
  margin-bottom: 2.25rem;
  color: #0f172a;
  font-size: 1rem;
  font-weight: 750;
}

.brand-mark-small {
  width: 2.6rem;
  height: 2.6rem;
  border-radius: 0.75rem;
  font-size: 1rem;
}

.form-heading {
  margin-bottom: 2rem;
}

.secure-kicker {
  color: var(--brand-primary);
}

.secure-kicker svg {
  width: 0.9rem;
}

.form-heading h2 {
  margin-top: 0.85rem;
  color: #0f172a;
  font-size: clamp(1.85rem, 3vw, 2.35rem);
  line-height: 1.15;
  font-weight: 760;
  letter-spacing: -0.04em;
}

.form-heading p {
  margin-top: 0.65rem;
  color: #64748b;
  font-size: 0.94rem;
  line-height: 1.65;
}

.login-form {
  display: grid;
  gap: 1.35rem;
}

.field-group label,
.label-row label {
  display: block;
  margin-bottom: 0.5rem;
  color: #334155;
  font-size: 0.78rem;
  font-weight: 700;
}

.label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.label-row a {
  margin-bottom: 0.5rem;
  color: var(--brand-primary);
  font-size: 0.75rem;
  font-weight: 650;
}

.label-row a:hover {
  text-decoration: underline;
  text-underline-offset: 3px;
}

.input-wrap {
  position: relative;
}

.input-wrap input {
  width: 100%;
  height: 3.15rem;
  padding: 0 1rem 0 2.9rem;
  border: 1px solid #cbd5e1;
  border-radius: 0.85rem;
  background: white;
  color: #0f172a;
  box-shadow: 0 1px 2px rgb(15 23 42 / 0.03);
  font-size: 0.9rem;
  transition:
    border-color 150ms,
    box-shadow 150ms,
    background 150ms;
}

.input-wrap input:hover {
  border-color: #94a3b8;
}

.input-wrap input:focus {
  border-color: var(--brand-secondary);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand-secondary) 14%, transparent);
}

.input-wrap input::placeholder {
  color: #94a3b8;
}

.field-icon {
  position: absolute;
  z-index: 1;
  top: 50%;
  left: 1rem;
  width: 1.1rem;
  color: #94a3b8;
  transform: translateY(-50%);
  pointer-events: none;
}

.input-wrap .password-input {
  padding-right: 3rem;
}

.password-toggle {
  position: absolute;
  top: 50%;
  right: 0.75rem;
  width: 2rem;
  height: 2rem;
  display: grid;
  place-items: center;
  border-radius: 0.55rem;
  color: #64748b;
  transform: translateY(-50%);
}

.password-toggle:hover {
  background: #f1f5f9;
  color: var(--brand-primary);
}

.password-toggle svg {
  width: 1.1rem;
}

.field-hint {
  margin-top: 0.45rem;
  color: #94a3b8;
  font-size: 0.7rem;
  line-height: 1.5;
}

.submit-button {
  width: 100%;
  height: 3.2rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.65rem;
  margin-top: 0.15rem;
  border-radius: 0.85rem;
  background: linear-gradient(135deg, var(--brand-secondary), var(--brand-primary));
  color: white;
  box-shadow: 0 12px 28px color-mix(in srgb, var(--brand-primary) 24%, transparent);
  font-size: 0.86rem;
  font-weight: 750;
  transition:
    box-shadow 150ms,
    filter 150ms,
    transform 150ms;
}

.submit-button:hover:not(:disabled) {
  filter: saturate(1.08) brightness(1.04);
  box-shadow: 0 15px 34px color-mix(in srgb, var(--brand-primary) 32%, transparent);
  transform: translateY(-1px);
}

.submit-button:active:not(:disabled) {
  transform: translateY(0);
}

.submit-button:disabled {
  cursor: not-allowed;
  opacity: 0.65;
}

.submit-button svg {
  width: 1rem;
  transition: transform 150ms;
}

.submit-button:hover:not(:disabled) svg {
  transform: translateX(2px);
}

.button-spinner {
  width: 1rem;
  height: 1rem;
  border: 2px solid rgb(255 255 255 / 0.35);
  border-top-color: white;
  border-radius: 999px;
  animation: spin 700ms linear infinite;
}

.session-note {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  margin-top: 1.5rem;
  color: #94a3b8;
  font-size: 0.7rem;
}

.session-note svg {
  width: 0.9rem;
  color: #22c55e;
}

.support-copy {
  margin-top: 2.5rem;
  color: #94a3b8;
  font-size: 0.72rem;
  line-height: 1.5;
  text-align: center;
}

.two-factor-step {
  text-align: center;
}

.verification-icon {
  width: 4rem;
  height: 4rem;
  display: grid;
  place-items: center;
  margin: 0 auto 1.25rem;
  border: 1px solid color-mix(in srgb, var(--brand-secondary) 28%, transparent);
  border-radius: 1.25rem;
  background: color-mix(in srgb, var(--brand-secondary) 10%, white);
  color: var(--brand-primary);
}

.verification-icon svg {
  width: 1.8rem;
}

.form-heading-centered {
  text-align: center;
}

.otp-input {
  width: 100%;
  height: 4.25rem;
  border: 1px solid #cbd5e1;
  border-radius: 0.9rem;
  background: white;
  color: #0f172a;
  font-size: 1.75rem;
  font-weight: 700;
  letter-spacing: 0.45em;
  text-align: center;
  text-indent: 0.45em;
}

.otp-input:focus {
  border-color: var(--brand-secondary);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand-secondary) 14%, transparent);
}

.back-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  margin: 0 auto;
  color: #64748b;
  font-size: 0.78rem;
  font-weight: 650;
}

.back-button:hover {
  color: var(--brand-primary);
}

.back-button svg {
  width: 0.9rem;
}

.login-panel footer {
  position: absolute;
  bottom: 1.5rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: #94a3b8;
  font-size: 0.65rem;
}

.auth-step-enter-active,
.auth-step-leave-active {
  transition:
    opacity 180ms ease,
    transform 180ms ease;
}

.auth-step-enter-from {
  opacity: 0;
  transform: translateX(10px);
}

.auth-step-leave-to {
  opacity: 0;
  transform: translateX(-10px);
}

:global(.dark) .login-panel {
  background: radial-gradient(circle at 85% 10%, rgb(30 64 175 / 0.16), transparent 30%), #080f1d;
}

:global(.dark) .utility-button {
  border-color: #263449;
  background: rgb(15 23 42 / 0.82);
  color: #cbd5e1;
}

:global(.dark) .utility-button:hover {
  border-color: #475569;
  background: #172033;
  color: #bfdbfe;
}

:global(.dark) .mobile-brand,
:global(.dark) .form-heading h2 {
  color: #f8fafc;
}

:global(.dark) .form-heading p,
:global(.dark) .field-group label,
:global(.dark) .label-row label {
  color: #cbd5e1;
}

:global(.dark) .input-wrap input,
:global(.dark) .otp-input {
  border-color: #334155;
  background: #111827;
  color: #f8fafc;
}

:global(.dark) .input-wrap input:hover,
:global(.dark) .otp-input:hover {
  border-color: #475569;
}

:global(.dark) .password-toggle:hover {
  background: #1e293b;
}

:global(.dark) .verification-icon {
  background: color-mix(in srgb, var(--brand-secondary) 12%, #111827);
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

@media (max-width: 1050px) {
  .login-shell {
    grid-template-columns: minmax(0, 0.92fr) minmax(440px, 1.08fr);
  }

  .marketing-copy > h1 {
    font-size: 2.65rem;
  }

  .benefit-item:nth-child(2) {
    display: none;
  }
}

@media (max-width: 820px) {
  .login-shell {
    display: block;
  }

  .marketing-panel {
    display: none;
  }

  .login-panel {
    min-height: 100vh;
    padding: 6rem 1.5rem 4rem;
  }

  .mobile-brand {
    display: flex;
  }
}

@media (max-width: 480px) {
  .login-panel {
    justify-content: flex-start;
    padding: 5.5rem 1.15rem 4.5rem;
  }

  .utility-actions {
    top: 1.1rem;
    right: 1.1rem;
  }

  .mobile-brand {
    margin-bottom: 2rem;
  }

  .form-heading h2 {
    font-size: 1.8rem;
  }

  .login-panel footer {
    bottom: 1.1rem;
    width: calc(100% - 2.3rem);
    justify-content: center;
    flex-wrap: wrap;
  }
}

@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    scroll-behavior: auto !important;
    transition-duration: 0.01ms !important;
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
  }
}
</style>
