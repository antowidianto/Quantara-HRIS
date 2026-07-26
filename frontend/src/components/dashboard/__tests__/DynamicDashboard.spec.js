import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DynamicDashboard from '../DynamicDashboard.vue'

const dashboard = {
  name: 'Monitoring HR',
  description: 'Ringkasan operasional',
  target_role_slug: 'hr_admin',
  status: 'PUBLISHED',
  widgets: [
    {
      id: 'a',
      metric_key: 'headcount.active',
      label: 'Karyawan Aktif',
      type: 'stat',
      width: 'quarter',
      color: 'blue',
      data: { value: 70, unit: 'orang' },
    },
    {
      id: 'b',
      metric_key: 'attendance.today',
      label: 'Kehadiran',
      type: 'progress',
      width: 'half',
      color: 'emerald',
      data: { value: 60, max: 70, percent: 85.7 },
    },
    {
      id: 'c',
      metric_key: 'payroll.by_status',
      label: 'Status Payroll',
      description: 'Distribusi',
      type: 'bar',
      width: 'half',
      color: 'violet',
      data: { items: [{ label: 'PAID', value: 3 }] },
    },
    {
      id: 'd',
      metric_key: 'employee.by_status',
      label: 'Status Karyawan',
      description: 'Komposisi',
      type: 'donut',
      width: 'half',
      color: 'blue',
      data: {
        items: [
          { label: 'Tetap', value: 40 },
          { label: 'Kontrak', value: 30 },
        ],
      },
    },
    {
      id: 'e',
      metric_key: 'quick.links',
      label: 'Akses Cepat',
      type: 'links',
      width: 'full',
      color: 'slate',
      data: { items: [{ label: 'Karyawan', to: '/employees' }] },
    },
  ],
}

describe('DynamicDashboard', () => {
  it('renders every supported widget and dark-mode surfaces', () => {
    const wrapper = mount(DynamicDashboard, {
      props: { dashboard },
      global: {
        stubs: {
          RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
        },
      },
    })

    expect(wrapper.text()).toContain('Monitoring HR')
    expect(wrapper.text()).toContain('70')
    expect(wrapper.text()).toContain('85.7%')
    expect(wrapper.text()).toContain('PAID')
    expect(wrapper.text()).toContain('Kontrak')
    expect(wrapper.text()).toContain('Akses Cepat')
    expect(wrapper.find('.dark\\:bg-gray-900').exists()).toBe(true)
  })
})
