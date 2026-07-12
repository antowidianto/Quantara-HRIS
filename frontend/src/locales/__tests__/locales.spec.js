import { describe, expect, it } from 'vitest'
import en from '../en'
import id from '../id'

function flatten(value, prefix = '', result = {}) {
  for (const [key, child] of Object.entries(value)) {
    const path = prefix ? `${prefix}.${key}` : key
    if (child && typeof child === 'object') flatten(child, path, result)
    else result[path] = child
  }
  return result
}

describe('locale dictionaries', () => {
  const idMessages = flatten(id)
  const enMessages = flatten(en)

  it('keeps Indonesian and English keys in exact parity', () => {
    expect(Object.keys(enMessages).sort()).toEqual(Object.keys(idMessages).sort())
  })

  it('contains non-empty translated strings for every key', () => {
    for (const key of Object.keys(idMessages)) {
      expect(idMessages[key], `Missing Indonesian value for ${key}`).toBeTypeOf('string')
      expect(idMessages[key].trim(), `Empty Indonesian value for ${key}`).not.toBe('')
      expect(enMessages[key], `Missing English value for ${key}`).toBeTypeOf('string')
      expect(enMessages[key].trim(), `Empty English value for ${key}`).not.toBe('')
    }
  })
})
