import { describe, expect, it } from 'vitest'
import { apiErrorMessage, apiValidationErrors } from '../apiError'

describe('API error translation', () => {
  it('prioritizes a stable translated error code', () => {
    const error = { response: { data: { code: 'PERIOD_DUPLICATE', message: 'raw' } } }
    expect(apiErrorMessage(error, {
      codes: { PERIOD_DUPLICATE: 'translated duplicate' },
      fallback: 'fallback',
    })).toBe('translated duplicate')
  })

  it('falls back through server message and safe default', () => {
    expect(apiErrorMessage({ response: { data: { message: 'server' } } }, { fallback: 'fallback' })).toBe('server')
    expect(apiErrorMessage(new Error('network'), { fallback: 'fallback' })).toBe('fallback')
  })

  it('normalizes field validation arrays and strings', () => {
    const error = { response: { data: { errors: { name: ['Required', 'Too short'], month: 'Invalid' } } } }
    expect(apiValidationErrors(error)).toEqual({ name: 'Required Too short', month: 'Invalid' })
    expect(apiValidationErrors(new Error('network'))).toEqual({})
  })
})
