<script setup>
import { computed } from 'vue'
import { Line, Bar, Pie, Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  BarElement,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  ArcElement,
  Filler,
} from 'chart.js'

ChartJS.register(
  Title,
  Tooltip,
  Legend,
  BarElement,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  ArcElement,
  Filler
)

const props = defineProps({
  // 'line' | 'area' | 'bar' | 'pie' | 'donut'
  type: { type: String, required: true },
  // [{ label, value }]
  items: { type: Array, default: () => [] },
  color: { type: String, default: 'blue' },
  height: { type: Number, default: 240 },
})

const palette = {
  blue: '#3b82f6',
  emerald: '#10b981',
  amber: '#f59e0b',
  rose: '#f43f5e',
  violet: '#8b5cf6',
  slate: '#64748b',
}
const categorical = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#f43f5e', '#06b6d4', '#64748b', '#84cc16']

const baseColor = computed(() => palette[props.color] || palette.blue)
const labels = computed(() => props.items.map((item) => String(item.label ?? '')))
const values = computed(() => props.items.map((item) => Number(item.value ?? 0)))

function hexToRgba(hex, alpha) {
  const value = hex.replace('#', '')
  const r = parseInt(value.substring(0, 2), 16)
  const g = parseInt(value.substring(2, 4), 16)
  const b = parseInt(value.substring(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

const chartComponent = computed(() => {
  switch (props.type) {
    case 'pie':
      return Pie
    case 'donut':
      return Doughnut
    case 'bar':
      return Bar
    default:
      return Line
  }
})

const chartData = computed(() => {
  if (props.type === 'pie' || props.type === 'donut') {
    return {
      labels: labels.value,
      datasets: [
        {
          data: values.value,
          backgroundColor: labels.value.map((_, index) => categorical[index % categorical.length]),
          borderWidth: 2,
          borderColor: '#ffffff',
        },
      ],
    }
  }
  if (props.type === 'bar') {
    return {
      labels: labels.value,
      datasets: [
        {
          data: values.value,
          backgroundColor: hexToRgba(baseColor.value, 0.85),
          borderRadius: 6,
          maxBarThickness: 42,
        },
      ],
    }
  }
  // line / area
  return {
    labels: labels.value,
    datasets: [
      {
        data: values.value,
        borderColor: baseColor.value,
        backgroundColor: hexToRgba(baseColor.value, props.type === 'area' ? 0.18 : 0.05),
        fill: props.type === 'area',
        tension: 0.35,
        pointRadius: 3,
        pointBackgroundColor: baseColor.value,
        borderWidth: 2,
      },
    ],
  }
})

const isPieLike = computed(() => props.type === 'pie' || props.type === 'donut')

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      display: isPieLike.value,
      position: 'right',
      labels: { boxWidth: 12, usePointStyle: true, padding: 12 },
    },
    tooltip: {
      callbacks: {
        label: (ctx) => {
          const value = isPieLike.value ? ctx.parsed : ctx.parsed.y
          return ` ${Number(value ?? 0).toLocaleString('id-ID')}`
        },
      },
    },
  },
  scales: isPieLike.value
    ? {}
    : {
        x: {
          grid: { display: false },
          ticks: { color: '#94a3b8', font: { size: 11 } },
        },
        y: {
          beginAtZero: true,
          grid: { color: 'rgba(148, 163, 184, 0.15)' },
          ticks: { color: '#94a3b8', font: { size: 11 }, precision: 0 },
        },
      },
}))
</script>

<template>
  <div :style="{ height: `${height}px` }">
    <component :is="chartComponent" :data="chartData" :options="chartOptions" />
  </div>
</template>