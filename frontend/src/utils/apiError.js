/** Translate stable backend error codes while retaining a safe server/fallback message. */
export function apiErrorMessage(error, { codes = {}, fallback }) {
  const body = error?.response?.data
  if (body?.code && codes[body.code]) return codes[body.code]
  if (typeof body?.message === 'string' && body.message.trim()) return body.message
  return fallback
}

/** Flatten common backend validation payloads for field-level display. */
export function apiValidationErrors(error) {
  const errors = error?.response?.data?.errors
  if (!errors || typeof errors !== 'object' || Array.isArray(errors)) return {}
  return Object.fromEntries(
    Object.entries(errors).map(([field, value]) => [
      field,
      Array.isArray(value) ? value.filter(Boolean).join(' ') : String(value || ''),
    ]),
  )
}
