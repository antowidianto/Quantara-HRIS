<script setup>
import { computed, useAttrs } from 'vue'
import Calendar from 'primevue/calendar'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  modelValue: { type: [String, Date, Number], default: '' },
  mode: {
    type: String,
    default: 'date',
    validator: value => ['date', 'month', 'datetime'].includes(value),
  },
  min: { type: [String, Date, Number], default: undefined },
  max: { type: [String, Date, Number], default: undefined },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
  placeholder: { type: String, default: '' },
  id: { type: String, default: undefined },
  name: { type: String, default: undefined },
})

const emit = defineEmits(['update:modelValue', 'change', 'blur', 'focus'])
const attrs = useAttrs()

const pad = value => String(value).padStart(2, '0')

function parseDateValue(value, mode = 'date') {
  if (value == null || value === '') return null
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : new Date(value.getTime())
  if (typeof value === 'number') {
    const parsed = new Date(value)
    return Number.isNaN(parsed.getTime()) ? null : parsed
  }

  const text = String(value).trim()
  let match

  if (mode === 'month') {
    match = text.match(/^(\d{4})-(\d{2})/)
    if (match) return new Date(Number(match[1]), Number(match[2]) - 1, 1)
  } else if (mode === 'datetime') {
    match = text.match(/^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})/)
    if (match) {
      return new Date(
        Number(match[1]),
        Number(match[2]) - 1,
        Number(match[3]),
        Number(match[4]),
        Number(match[5]),
      )
    }
  } else {
    match = text.match(/^(\d{4})-(\d{2})-(\d{2})/)
    if (match) return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  }

  return null
}

function formatModelValue(value) {
  const date = parseDateValue(value, props.mode)
  if (!date) return ''

  const year = date.getFullYear()
  const month = pad(date.getMonth() + 1)
  if (props.mode === 'month') return `${year}-${month}`

  const day = pad(date.getDate())
  if (props.mode === 'datetime') {
    return `${year}-${month}-${day}T${pad(date.getHours())}:${pad(date.getMinutes())}`
  }

  return `${year}-${month}-${day}`
}

const calendarValue = computed(() => parseDateValue(props.modelValue, props.mode))
const minDate = computed(() => parseDateValue(props.min, props.mode))
const maxDate = computed(() => parseDateValue(props.max, props.mode))
const dateFormat = computed(() => (props.mode === 'month' ? 'mm/yy' : 'dd/mm/yy'))
const inputProps = computed(() => ({
  required: props.required,
  autocomplete: 'off',
}))

const forwardedAttrs = computed(() => {
  const { class: _class, ...rest } = attrs
  return rest
})

function flattenClasses(value) {
  if (!value) return []
  if (typeof value === 'string') return value.split(/\s+/).filter(Boolean)
  if (Array.isArray(value)) return value.flatMap(flattenClasses)
  if (typeof value === 'object') return Object.keys(value).filter(key => value[key])
  return []
}

const inputClass = computed(() => ['app-date-picker__input', ...flattenClasses(attrs.class)].join(' '))

const wrapperClass = computed(() => {
  const layoutClassPattern = /^(?:(?:sm|md|lg|xl|2xl):)?(?:w-|min-w-|max-w-|flex(?:-|$)|grow|shrink|self-|justify-self-|place-self-)/
  const layoutClasses = flattenClasses(attrs.class).filter(token => layoutClassPattern.test(token))
  return ['app-date-picker', ...(layoutClasses.length ? layoutClasses : ['w-full'])]
})

function updateValue(value) {
  const normalized = value == null ? '' : formatModelValue(value)
  emit('update:modelValue', normalized)
  emit('change', normalized)
}
</script>

<template>
  <Calendar
    v-bind="forwardedAttrs"
    :model-value="calendarValue"
    :date-format="dateFormat"
    :view="mode === 'month' ? 'month' : 'date'"
    :show-time="mode === 'datetime'"
    :min-date="minDate"
    :max-date="maxDate"
    :disabled="disabled"
    :readonly="readonly"
    :placeholder="placeholder"
    :input-id="id"
    :name="name"
    :input-class="inputClass"
    :input-props="inputProps"
    :class="wrapperClass"
    hour-format="24"
    icon-display="button"
    show-icon
    manual-input
    month-navigator
    year-navigator
    append-to="body"
    @update:model-value="updateValue"
    @blur="emit('blur', $event)"
    @focus="emit('focus', $event)"
  />
</template>

<style scoped>
:deep(.app-date-picker__input) {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 38px;
  padding-right: 0.75rem !important;
}

:deep(.p-datepicker-trigger) {
  flex: 0 0 2.5rem;
  width: 2.5rem;
  padding: 0;
}

:deep(.p-calendar-w-btn .app-date-picker__input) {
  border-top-right-radius: 0;
  border-bottom-right-radius: 0;
}

:global(html.dark .p-datepicker),
:global(html.dark .p-datepicker:not(.p-datepicker-inline)),
:global(html.dark .p-datepicker:not(.p-datepicker-inline) .p-datepicker-header),
:global(html.dark .p-datepicker .p-datepicker-header) {
  background: #111827;
  color: #e5e7eb;
  border-color: #374151;
}

:global(html.dark .p-datepicker .p-datepicker-header .p-datepicker-title .p-datepicker-year),
:global(html.dark .p-datepicker .p-datepicker-header .p-datepicker-title .p-datepicker-month),
:global(html.dark .p-datepicker .p-datepicker-header .p-datepicker-prev),
:global(html.dark .p-datepicker .p-datepicker-header .p-datepicker-next),
:global(html.dark .p-datepicker .p-timepicker button) {
  color: #d1d5db;
}

:global(html.dark .p-datepicker table td > span.p-highlight),
:global(html.dark .p-datepicker .p-monthpicker .p-monthpicker-month.p-highlight) {
  background: #3730a3;
  color: #eef2ff;
}

:global(html.dark .p-datepicker table td.p-datepicker-today > span) {
  background: #374151;
  color: #f3f4f6;
}

:global(html.dark .p-datepicker:not(.p-disabled) table td span:not(.p-highlight):not(.p-disabled):hover),
:global(html.dark .p-datepicker:not(.p-disabled) .p-monthpicker .p-monthpicker-month:not(.p-disabled):not(.p-highlight):hover),
:global(html.dark .p-datepicker .p-datepicker-header .p-datepicker-prev:enabled:hover),
:global(html.dark .p-datepicker .p-datepicker-header .p-datepicker-next:enabled:hover),
:global(html.dark .p-datepicker .p-timepicker button:enabled:hover) {
  background: #1f2937;
  color: #f9fafb;
}

:global(html.dark .p-datepicker .p-datepicker-buttonbar),
:global(html.dark .p-datepicker .p-timepicker) {
  border-color: #374151;
}
</style>
