import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import api, { isPublicAuthPath, tokenExpiresWithin } from '../client'
import { useAuthStore } from '@/stores/auth'

const originalAdapter = api.defaults.adapter

function jwtWithExp(exp) {
  const encode = (value) =>
    btoa(JSON.stringify(value)).replace(/=/g, '').replace(/\+/g, '-').replace(/\//g, '_')
  return `${encode({ alg: 'none' })}.${encode({ exp })}.signature`
}

describe('API authentication session policy', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  afterEach(() => {
    api.defaults.adapter = originalAdapter
  })

  it('does not run refresh retry logic recursively for public auth endpoints', () => {
    expect(isPublicAuthPath('/auth/refresh')).toBe(true)
    expect(isPublicAuthPath('/api/v1/auth/login')).toBe(true)
    expect(isPublicAuthPath('/notifications')).toBe(false)
  })

  it('refreshes proactively when access token is expired or nearly expired', () => {
    expect(tokenExpiresWithin(jwtWithExp(1020), 30, 1000)).toBe(true)
    expect(tokenExpiresWithin(jwtWithExp(1100), 30, 1000)).toBe(false)
    expect(tokenExpiresWithin('invalid-token', 30, 1000)).toBe(true)
  })

  it('refreshes once before sending a request with an expired access token', async () => {
    const auth = useAuthStore()
    auth.accessToken = jwtWithExp(1)
    auth.refreshToken = 'valid-refresh'
    const calls = []
    api.defaults.adapter = async (config) => {
      calls.push(config.url)
      if (config.url === '/auth/refresh') {
        return {
          config,
          status: 200,
          statusText: 'OK',
          headers: {},
          data: { data: { tokens: { access_token: jwtWithExp(4_000_000_000), refresh_token: 'rotated' } } },
        }
      }
      return { config, status: 200, statusText: 'OK', headers: {}, data: { data: [] } }
    }

    await api.get('/notifications')

    expect(calls).toEqual(['/auth/refresh', '/notifications'])
    expect(auth.refreshToken).toBe('rotated')
  })

  it('does not recursively retry an invalid refresh token', async () => {
    window.history.replaceState({}, '', '/login')
    const auth = useAuthStore()
    auth.accessToken = jwtWithExp(1)
    auth.refreshToken = 'invalid-refresh'
    let calls = 0
    api.defaults.adapter = async (config) => {
      calls += 1
      const response = { config, status: 401, statusText: 'Unauthorized', headers: {}, data: {} }
      throw new AxiosError('Unauthorized', 'ERR_BAD_REQUEST', config, null, response)
    }

    await expect(api.get('/notifications')).rejects.toThrow('Unauthorized')

    expect(calls).toBe(1)
    expect(auth.accessToken).toBeNull()
    expect(auth.refreshToken).toBeNull()
  })

  it('updates reactive permissions when the server authorization version changes', async () => {
    const auth = useAuthStore()
    auth.accessToken = jwtWithExp(4_000_000_000)
    auth.user = { id: 22 }
    auth.role = { id: 4, slug: 'payroll_admin', name: 'Payroll Admin' }
    auth.permissions = ['payroll.calculate', 'payroll.view']
    auth.authorizationVersion = 'before'
    api.defaults.adapter = async (config) => ({
      config,
      status: 200,
      statusText: 'OK',
      headers: {},
      data: {
        data: {
          role: { id: 4, slug: 'payroll_admin', name: 'Payroll Admin' },
          permissions: ['payroll.view'],
          version: 'after',
        },
      },
    })

    await expect(auth.syncAuthorization()).resolves.toBe(true)
    expect(auth.can('payroll.calculate')).toBe(false)
    expect(auth.can('payroll.view')).toBe(true)
  })
})
