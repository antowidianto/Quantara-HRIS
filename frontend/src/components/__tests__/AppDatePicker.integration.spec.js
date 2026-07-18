import { mount } from '@vue/test-utils'
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import PrimeVue from 'primevue/config'
import AppDatePicker from '../AppDatePicker.vue'
import { primeVueLocaleId } from '@/config/primeVueLocaleId'

let wrapper
const originalMatchMedia = globalThis.matchMedia

beforeAll(() => {
  globalThis.matchMedia = vi.fn(query => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }))
})

afterAll(() => {
  globalThis.matchMedia = originalMatchMedia
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
})

describe('AppDatePicker with PrimeVue Calendar', () => {
  it('renders the formatted date and calendar trigger as separate elements', async () => {
    wrapper = mount(AppDatePicker, {
      attachTo: document.body,
      props: { modelValue: '2026-07-18' },
      attrs: { class: 'input' },
      global: {
        plugins: [[PrimeVue, { locale: primeVueLocaleId }]],
      },
    })

    const input = wrapper.get('input.app-date-picker__input')
    const trigger = wrapper.get('button.p-datepicker-trigger')

    expect(input.element.value).toBe('18/07/2026')
    expect(input.element.nextElementSibling).toBe(trigger.element)

    await trigger.trigger('click')
    expect(document.body.querySelector('.p-datepicker')).not.toBeNull()
  })
})
