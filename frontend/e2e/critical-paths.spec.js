import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

async function mockAuthenticatedApi(page) {
  await page.addInitScript(() => localStorage.setItem('hris_access', 'e2e-access-token'))
  await page.route('**/api/v1/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname
    if (pathname.endsWith('/auth/me')) {
      return route.fulfill({ json: { status: 'success', data: {
        user: { id: 10, email: 'hr@example.test', full_name: 'E2E HR' },
        employee: { id: 20, full_name: 'E2E HR', employee_number: 'E2E-001' },
        company: { id: 1, name: 'E2E Company' },
        branch: null,
        role: { id: 1, slug: 'super_admin', name: 'Super Admin' },
        permissions: [],
      } } })
    }
    if (pathname.endsWith('/companies')) {
      return route.fulfill({ json: { status: 'success', data: [{ id: 1, name: 'E2E Company' }] } })
    }
    if (pathname.includes('/features/me')) {
      return route.fulfill({ json: { status: 'success', data: {} } })
    }
    return route.fulfill({ json: { status: 'success', data: [], meta: { page: 1, pages: 1, total: 0 } } })
  })
}

test('employee login enters the forced 2FA challenge safely', async ({ page }) => {
  await page.route('**/api/v1/auth/login', (route) => route.fulfill({ json: {
    status: 'success', data: { requires_2fa: true, challenge: 'e2e-challenge' },
  } }))
  await page.goto('/login')
  await page.getByLabel(/email|nik|identifier/i).fill('E2E-001')
  await page.getByLabel(/password|kata sandi/i).fill('not-a-real-password')
  await page.getByRole('button', { name: /masuk|sign in/i }).click()
  await expect(page.locator('input[inputmode="numeric"]')).toBeVisible()
})

for (const [name, route] of [
  ['clock-in/out', '/attendance/clock'],
  ['leave submission', '/leaves/new'],
  ['manager approval', '/approvals/inbox'],
  ['payroll review', '/payroll/periods'],
  ['tenant onboarding', '/onboarding'],
  ['tenant administration', '/admin/users'],
]) {
  test(`${name} route boots under an authenticated tenant`, async ({ page }) => {
    await mockAuthenticatedApi(page)
    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))
    await page.goto(route)
    await expect(page).toHaveURL(new RegExp(route.replaceAll('/', '\\/')))
    await expect(page.locator('#app')).not.toBeEmpty()
    expect(errors).toEqual([])
  })
}

test('candidate application stays public while offers require candidate login', async ({ page }) => {
  await page.route('**/api/v1/careers/**', (route) => route.fulfill({ json: {
    status: 'success', data: { company: { name: 'E2E Company' }, job: { title: 'Engineer' }, questions: [] },
  } }))
  await page.goto('/careers/demo/jobs/engineer/apply')
  await expect(page).toHaveURL(/\/careers\/demo\/jobs\/engineer\/apply/)
  await page.goto('/careers/demo/offers')
  await expect(page).toHaveURL(/\/careers\/demo\/login/)
})

test('clock-in exposes a rejected state and can be retried successfully', async ({ page, context }) => {
  await mockAuthenticatedApi(page)
  await context.grantPermissions(['geolocation'], { origin: 'http://127.0.0.1:4173' })
  await context.setGeolocation({ latitude: -6.2, longitude: 106.8 })
  let attempts = 0
  await page.route('**/api/v1/attendance/clock-in', async (route) => {
    attempts += 1
    if (attempts === 1) {
      return route.fulfill({ status: 422, json: { status: 'error', message: 'Outside geofence' } })
    }
    return route.fulfill({ json: { status: 'success', data: { id: 99 } } })
  })
  await page.goto('/attendance/clock')
  const button = page.getByRole('button', { name: /clock in|masuk/i })
  await expect(button).toBeEnabled()
  await button.click()
  await expect(page.getByText('Outside geofence')).toBeVisible()
  await button.click()
  await expect.poll(() => attempts).toBe(2)
})

test('payroll finalize confirms, releases its failure lock, and succeeds on retry', async ({ page }) => {
  await mockAuthenticatedApi(page)
  const period = {
    id: 42, name: 'July Payroll', code: 'PAY-2026-07', start_date: '2026-07-01',
    end_date: '2026-07-31', status: 'APPROVED', total_gross: 1000000,
    total_deduction: 100000, total_net: 900000,
  }
  await page.route('**/api/v1/payroll-periods/42', (route) => route.fulfill({ json: { status: 'success', data: period } }))
  let attempts = 0
  await page.route('**/api/v1/payroll-periods/42/finalize', async (route) => {
    attempts += 1
    if (attempts === 1) {
      return route.fulfill({ status: 409, json: { status: 'error', message: 'Finalize blocked' } })
    }
    return route.fulfill({ json: { status: 'success', data: { status: 'FINALIZED' } } })
  })
  await page.goto('/payroll/periods/42')
  const finalize = page.getByRole('button', { name: /^finalize$|^finalisasi$/i }).first()
  await finalize.click()
  await page.getByRole('button', { name: /^finalize$|^finalisasi$/i }).last().click()
  await expect(page.getByText('Finalize blocked')).toBeVisible()
  await expect(finalize).toBeEnabled()
  await finalize.click()
  await page.getByRole('button', { name: /^finalize$|^finalisasi$/i }).last().click()
  await expect.poll(() => attempts).toBe(2)
})

test('employee submits an eligible leave request', async ({ page }) => {
  await mockAuthenticatedApi(page)
  const today = new Date().toISOString().slice(0, 10)
  await page.route('**/api/v1/leave-types/for-me', (route) => route.fulfill({ json: {
    status: 'success', data: { types: [{
      id: 1, name: 'Annual Leave', category: 'ANNUAL', eligible: true, reasons: [],
      min_start_date: today, max_start_date: '2026-12-31', requires_doc: false,
      is_paid: true, balance: { remaining: 10, allocated: 12 },
    }] },
  } }))
  await page.route('**/api/v1/leaves/team-calendar**', (route) => route.fulfill({ json: {
    status: 'success', data: { leaves: [] },
  } }))
  let submitted = false
  await page.route('**/api/v1/leaves', async (route) => {
    if (route.request().method() === 'POST') submitted = true
    return route.fulfill({ json: { status: 'success', data: { id: 77 } } })
  })
  await page.goto('/leaves/new')
  await page.locator('textarea[required]').fill('Family annual leave')
  await page.getByRole('button', { name: /submit|ajukan/i }).click()
  await expect.poll(() => submitted).toBe(true)
  await expect(page).toHaveURL(/\/leaves$/)
})

test('manager opens a pending request and approves it', async ({ page }) => {
  await mockAuthenticatedApi(page)
  let approved = false
  await page.route('**/api/v1/approval-requests/inbox**', (route) => route.fulfill({ json: {
    status: 'success', data: approved ? [] : [{
      id: 55, subject: 'Annual Leave', module: 'LEAVE', employee_name: 'Budi',
      current_step_order: 1, step_name: 'Manager', created_at: '2026-07-14 08:00:00',
    }],
  } }))
  await page.route('**/api/v1/approval-requests/55', (route) => route.fulfill({ json: {
    status: 'success', data: {
      id: 55, subject: 'Annual Leave', module: 'LEAVE', employee_name: 'Budi',
      status: 'PENDING', created_at: '2026-07-14 08:00:00', meta: { days: 2 }, decisions: [],
    },
  } }))
  await page.route('**/api/v1/approval-requests/55/approve', (route) => {
    approved = true
    return route.fulfill({ json: { status: 'success', data: { status: 'APPROVED' } } })
  })
  await page.goto('/approvals/inbox')
  await expect(page.getByText('Annual Leave').first()).toBeVisible()
  await page.getByRole('button', { name: /approve|setujui/i }).click()
  await expect.poll(() => approved).toBe(true)
  await expect(page.getByText(/request approved|permintaan disetujui/i)).toBeVisible()
})

test('candidate reviews and accepts a sent offer', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('candidate_access', 'candidate-e2e-token')
    localStorage.setItem('candidate_account', JSON.stringify({ id: 7, email: 'candidate@example.test' }))
  })
  let accepted = false
  await page.route('**/api/v1/careers/**', (route) => route.fulfill({ json: {
    status: 'success', data: { company: { name: 'E2E Company' } },
  } }))
  await page.route('**/api/v1/careers/account/offers/9', (route) => route.fulfill({ json: {
    status: 'success', data: {
      offer: {
        id: 9, offer_number: 'OFF-2026-009', status: accepted ? 'ACCEPTED' : 'SENT',
        response_deadline: '2026-12-31', responded_at: accepted ? '2026-07-14' : null,
        basic_salary: 12000000, currency: 'IDR', allowances: [], position_name: 'Engineer',
        employment_status: 'PKWTT', proposed_join_date: '2026-08-01',
      },
      job: { title: 'Engineer', city: 'Jakarta' },
      company: { name: 'E2E Company' }, can_respond: !accepted, expired: false,
    },
  } }))
  await page.route('**/api/v1/careers/account/offers/9/respond', (route) => {
    accepted = true
    return route.fulfill({ json: { status: 'success', message: 'Offer accepted', data: { status: 'ACCEPTED' } } })
  })
  await page.goto('/careers/demo/offers/9')
  await page.getByRole('button', { name: /terima penawaran/i }).click()
  await page.getByRole('button', { name: /ya, terima/i }).click()
  await expect.poll(() => accepted).toBe(true)
  await expect(page.getByText(/offer accepted/i)).toBeVisible()
})

test('candidate restores a complete draft and submits an application', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('candidate_access', 'candidate-e2e-token')
    localStorage.setItem('candidate_account', JSON.stringify({
      id: 7, full_name: 'Candidate E2E', email: 'candidate@example.test', phone: '081234567890',
    }))
    localStorage.setItem('career_draft_demo_engineer', JSON.stringify({
      _saved_at: Date.now(), _step: 5, full_name: 'Candidate E2E', email: 'candidate@example.test',
      phone: '081234567890', last_education_level: 'S1', cv_path: 'e2e/cv.pdf',
      cv_name: 'cv.pdf', work_experiences: [], language_items: [], skill_items: [],
      certification_items: [], extra_answers: {},
    }))
  })
  await page.route('**/api/v1/careers/**', (route) => route.fulfill({ json: {
    status: 'success', data: { company: { name: 'E2E Company' } },
  } }))
  await page.route('**/api/v1/careers/demo/jobs/engineer', (route) => route.fulfill({ json: {
    status: 'success', data: {
      company: { name: 'E2E Company', brand_color: '#0F62FE' },
      job: { title: 'Engineer', reference_number: 'JOB-E2E', min_education: 'S1' },
      extra_questions: [],
    },
  } }))
  await page.route('**/api/v1/careers/demo/options', (route) => route.fulfill({ json: {
    status: 'success', data: { locations: [], institutions: [] },
  } }))
  let submitted = false
  await page.route('**/api/v1/careers/demo/jobs/engineer/apply', (route) => {
    submitted = true
    return route.fulfill({ json: { status: 'success', data: {
      token: 'track-e2e', job: { reference_number: 'JOB-E2E' },
    } } })
  })
  await page.goto('/careers/demo/jobs/engineer/apply')
  await page.getByRole('button', { name: /ya, pulihkan/i }).click()
  await page.getByText(/saya menyetujui data/i).click()
  await page.getByRole('button', { name: /kirim lamaran/i }).click()
  await page.getByRole('button', { name: /ya, kirim sekarang/i }).click()
  await expect.poll(() => submitted).toBe(true)
  await expect(page).toHaveURL(/\/careers\/demo\/success/)
})

test('tenant admin completes an onboarding checklist step', async ({ page }) => {
  await mockAuthenticatedApi(page)
  let completed = false
  await page.route('**/api/v1/onboarding/progress', (route) => route.fulfill({ json: {
    status: 'success', data: {
      completed: completed ? 1 : 0, total_steps: 1, completion_percent: completed ? 100 : 0,
      steps: [{ step_key: 'timezone_set', completed, completed_at: completed ? '2026-07-14 09:00:00' : null }],
    },
  } }))
  await page.route('**/api/v1/onboarding/timezone_set/complete', (route) => {
    completed = true
    return route.fulfill({ json: { status: 'success', data: { completed: true } } })
  })
  await page.goto('/onboarding')
  await expect(page.getByText('Atur Timezone & Currency')).toBeVisible()
  await page.getByRole('button', { name: 'Skip' }).click()
  await expect.poll(() => completed).toBe(true)
  await expect(page.getByText(/tenant aktif/i).first()).toBeVisible()
})

test('HR reviews the tenant-scoped employee data-quality dashboard', async ({ page }) => {
  await mockAuthenticatedApi(page)
  await page.route('**/api/v1/employees/data-quality**', (route) => route.fulfill({ json: {
    status: 'success', data: {
      generated_at: '2026-07-14T09:00:00+07:00', company_id: 1,
      total_active_employees: 42, total_issues: 1,
      categories: {
        identity_tax: { count: 1, truncated: false, samples: [{ employee_id: 7, employee_number: 'EMP-007', employee_name: 'Sample Employee', codes: ['NIK_MISSING'] }] },
        bank_account: { count: 0, truncated: false, samples: [] },
        bpjs: { count: 0, truncated: false, samples: [] },
        expired_records: { count: 0, truncated: false, samples: [] },
        organization_completeness: { count: 0, truncated: false, samples: [] },
        minimum_wage: { count: 0, truncated: false, samples: [] },
        orphaned_references: { count: 0, truncated: false, samples: [] },
      },
    },
  } }))
  await page.goto('/hr/data-quality')
  await expect(page).toHaveURL(/\/hr\/data-quality/)
  await expect(page.getByText(/kualitas data karyawan|employee data quality/i).first()).toBeVisible()
  await expect(page.getByText('EMP-007')).toBeVisible()
  await expect(page.getByText(/nik belum diisi|nik is missing/i)).toBeVisible()
  await expect(page.locator('button').filter({ hasText: /NIK & NPWP/ })).toBeVisible()
})

test('HR reviews a compensation cycle, budget, and calibrated proposal', async ({ page }) => {
  await mockAuthenticatedApi(page)
  const cycle = { id: 11, code: 'MERIT-2027', name: 'Merit Review 2027', effective_date: '2027-01-01', status: 'OPEN', approval_levels: 2, summary: { budget: 10000000, approved_increase: 500000, remaining: 9500000, proposal_count: 1, status_counts: { IN_APPROVAL: 1 } } }
  await page.route('**/api/v1/compensation-reviews', (route) => route.fulfill({ json: { status: 'success', data: [cycle] } }))
  await page.route('**/api/v1/compensation-reviews/11', (route) => route.fulfill({ json: { status: 'success', data: { ...cycle, budgets: [{ id: 1, budget_amount: 10000000 }], proposals: [{ id: 21, full_name: 'Sample Employee', employee_number: 'EMP-007', current_salary: 5000000, proposed_salary: 5500000, compa_ratio: 0.9167, range_penetration: 0.375, status: 'IN_APPROVAL', approval_level: 1 }] } } }))
  await page.goto('/hr/compensation-review')
  await expect(page).toHaveURL(/\/hr\/compensation-review/)
  await expect(page.getByText(/review kompensasi|compensation review/i).first()).toBeVisible()
  await page.getByRole('button', { name: /Merit Review 2027/ }).click()
  await expect(page.getByText('Sample Employee')).toBeVisible()
  await expect(page.getByText('EMP-007')).toBeVisible()
  await expect(page.getByText('0.9167')).toBeVisible()
})

test('login has no serious or critical axe violations', async ({ page }) => {
  await page.goto('/login')
  const results = await new AxeBuilder({ page }).analyze()
  expect(results.violations.filter(({ impact }) => ['serious', 'critical'].includes(impact))).toEqual([])
})

test('employee self-service login remains usable on a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/login')
  await expect(page.getByLabel(/email|nik|identifier/i)).toBeVisible()
  await expect(page.getByRole('button', { name: /masuk|sign in/i })).toBeVisible()
})
