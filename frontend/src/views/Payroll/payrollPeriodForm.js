export function validatePayrollPeriod(form) {
  const errors = {}
  const year = Number(form.period_year)
  const month = Number(form.period_month)

  if (!String(form.name || '').trim()) errors.name = 'required'
  if (!Number.isInteger(year) || year < 2000 || year > 2100) errors.period_year = 'year'
  if (!Number.isInteger(month) || month < 1 || month > 12) errors.period_month = 'month'
  if (!form.start_date) errors.start_date = 'required'
  if (!form.end_date) errors.end_date = 'required'
  if (!form.pay_date) errors.pay_date = 'required'
  if (form.start_date && form.end_date && form.start_date > form.end_date) errors.end_date = 'date_order'

  return errors
}
