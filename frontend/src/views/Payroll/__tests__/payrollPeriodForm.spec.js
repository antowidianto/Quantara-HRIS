import { describe, expect, it } from 'vitest'
import { validatePayrollPeriod } from '../payrollPeriodForm'

const valid = {
  name: 'Juli 2026', period_year: 2026, period_month: 7,
  start_date: '2026-07-01', end_date: '2026-07-31', pay_date: '2026-08-05',
}

describe('payroll period validation', () => {
  it('accepts a coherent period', () => {
    expect(validatePayrollPeriod(valid)).toEqual({})
  })

  it('rejects missing, out-of-range, and reversed values', () => {
    expect(validatePayrollPeriod({
      ...valid, name: ' ', period_year: 1999, period_month: 13,
      start_date: '2026-08-01', end_date: '2026-07-31', pay_date: '',
    })).toEqual({
      name: 'required', period_year: 'year', period_month: 'month',
      pay_date: 'required', end_date: 'date_order',
    })
  })
})
