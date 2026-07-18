import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AppDatePicker from '../AppDatePicker.vue'

vi.mock('primevue/calendar', () => ({
  default: {
    name: 'PrimeCalendarStub',
    inheritAttrs: false,
    props: {
      modelValue: { default: null },
      dateFormat: { type: String, default: '' },
      view: { type: String, default: 'date' },
      showTime: { type: Boolean, default: false },
      hourFormat: { type: String, default: '' },
      minDate: { default: undefined },
      maxDate: { default: undefined },
      inputProps: { type: Object, default: () => ({}) },
      inputClass: { type: String, default: '' },
      showIcon: { type: Boolean, default: false },
      iconDisplay: { type: String, default: '' },
    },
    emits: ['update:model-value', 'blur', 'focus'],
    template: '<div data-test="calendar-stub" />',
  },
}))

function mountPicker(props = {}, attrs = {}) {
  return mount(AppDatePicker, { props, attrs })
}

function expectLocalDate(value, year, month, day, hour = 0, minute = 0) {
  expect(value).toBeInstanceOf(Date)
  expect([
    value.getFullYear(),
    value.getMonth(),
    value.getDate(),
    value.getHours(),
    value.getMinutes(),
  ]).toEqual([year, month, day, hour, minute])
}

describe('AppDatePicker', () => {
  it('converts API dates to local Date objects and back without a timezone shift', async () => {
    const wrapper = mountPicker({
      modelValue: '2026-07-18',
      min: '2026-07-01',
      max: '2026-07-31',
      required: true,
    }, { class: 'input w-44' })
    const picker = wrapper.findComponent({ name: 'PrimeCalendarStub' })

    expectLocalDate(picker.props('modelValue'), 2026, 6, 18)
    expectLocalDate(picker.props('minDate'), 2026, 6, 1)
    expectLocalDate(picker.props('maxDate'), 2026, 6, 31)
    expect(picker.props('dateFormat')).toBe('dd/mm/yy')
    expect(picker.props('inputProps')).toMatchObject({ required: true, autocomplete: 'off' })
    expect(picker.props('inputClass')).toContain('input')
    expect(picker.props('showIcon')).toBe(true)
    expect(picker.props('iconDisplay')).toBe('button')

    picker.vm.$emit('update:model-value', new Date(2026, 6, 20))
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-07-20']])
    expect(wrapper.emitted('change')).toEqual([['2026-07-20']])
  })

  it('keeps month payloads in YYYY-MM format', async () => {
    const wrapper = mountPicker({ mode: 'month', modelValue: '2026-07' })
    const picker = wrapper.findComponent({ name: 'PrimeCalendarStub' })

    expectLocalDate(picker.props('modelValue'), 2026, 6, 1)
    expect(picker.props('view')).toBe('month')
    expect(picker.props('dateFormat')).toBe('mm/yy')

    picker.vm.$emit('update:model-value', new Date(2026, 10, 1))
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-11']])
  })

  it('keeps datetime payloads local and minute-precise', async () => {
    const wrapper = mountPicker({ mode: 'datetime', modelValue: '2026-07-18T14:35' })
    const picker = wrapper.findComponent({ name: 'PrimeCalendarStub' })

    expectLocalDate(picker.props('modelValue'), 2026, 6, 18, 14, 35)
    expect(picker.props('showTime')).toBe(true)
    expect(picker.props('hourFormat')).toBe('24')

    picker.vm.$emit('update:model-value', new Date(2026, 6, 19, 8, 5))
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-07-19T08:05']])
  })

  it('emits empty strings when the value is cleared', async () => {
    const wrapper = mountPicker({ modelValue: '2026-07-18' })
    const picker = wrapper.findComponent({ name: 'PrimeCalendarStub' })

    picker.vm.$emit('update:model-value', null)
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('update:modelValue')).toEqual([['']])
    expect(wrapper.emitted('change')).toEqual([['']])
  })
})
