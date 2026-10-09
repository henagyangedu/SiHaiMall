<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

// 单序列折线图（按日趋势）。points：[{ label: '2026-10-09', value: 12.5 }]
// 一张图只画一个指标；量纲不同的指标各用一张图，不做双轴。
const props = defineProps({
  points: { type: Array, required: true },
  height: { type: Number, default: 220 },
  color: { type: String, default: '#2a78d6' },
  // 数值的显示格式（坐标轴刻度和提示框共用）
  format: { type: Function, default: (v) => String(v) },
  // 计数类指标（订单数、人数）：纵轴只出现整数刻度
  integer: { type: Boolean, default: false },
})

const PAD = { left: 56, right: 16, top: 14, bottom: 26 }
const box = ref(null)
const width = ref(600)
let observer = null
onMounted(() => {
  observer = new ResizeObserver(([e]) => { width.value = Math.max(260, Math.floor(e.contentRect.width)) })
  observer.observe(box.value)
})
onBeforeUnmount(() => observer?.disconnect())

// 把最大值向上取成 1 / 2 / 5 × 10^n，让刻度是整齐的数
function niceMax(v) {
  if (v <= 0) return 1
  const exp = Math.pow(10, Math.floor(Math.log10(v)))
  const f = v / exp
  return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * exp
}
const yMax = computed(() => {
  const max = niceMax(Math.max(0, ...props.points.map((p) => Number(p.value) || 0)))
  return props.integer ? Math.max(max, 4) : max
})
// 刻度段数：计数类指标选能整除的段数，避免出现 2.5 这样的刻度
const steps = computed(() => (!props.integer ? 4 : [4, 5, 2].find((n) => yMax.value % n === 0) || 1))
const innerW = computed(() => width.value - PAD.left - PAD.right)
const innerH = computed(() => props.height - PAD.top - PAD.bottom)
const x = (i) => PAD.left + (props.points.length > 1 ? (innerW.value * i) / (props.points.length - 1) : innerW.value / 2)
const y = (v) => PAD.top + innerH.value * (1 - (Number(v) || 0) / yMax.value)

const path = computed(() => props.points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' '))
const ticks = computed(() => Array.from({ length: steps.value + 1 }, (_, k) => (yMax.value * k) / steps.value).map((v) => ({ v, y: y(v) })))
// 横轴标签：最多 6 个，均匀抽取，首尾必有
const xLabels = computed(() => {
  const n = props.points.length
  if (!n) return []
  const count = Math.min(n, 6)
  const idx = [...new Set(Array.from({ length: count }, (_, k) => Math.round(((n - 1) * k) / Math.max(count - 1, 1))))]
  return idx.map((i) => ({ i, x: x(i), text: String(props.points[i].label).slice(5) }))
})

// 悬停：取离鼠标最近的那一天，画十字线、圆点和提示框
const hover = ref(-1)
function onMove(e) {
  const n = props.points.length
  if (!n) return
  const rect = box.value.getBoundingClientRect()
  const ratio = (e.clientX - rect.left - PAD.left) / Math.max(innerW.value, 1)
  hover.value = Math.min(n - 1, Math.max(0, Math.round(ratio * (n - 1))))
}
const tip = computed(() => {
  if (hover.value < 0 || !props.points[hover.value]) return null
  const p = props.points[hover.value]
  const px = x(hover.value)
  return { p, px, py: y(p.value), flip: px > width.value - 150 }
})
const isEmpty = computed(() => props.points.every((p) => !Number(p.value)))
</script>

<template>
  <div ref="box" class="trend" :style="{ height: height + 'px' }" @mousemove="onMove" @mouseleave="hover = -1">
    <svg :width="width" :height="height" role="img">
      <g v-for="t in ticks" :key="t.v">
        <line :x1="PAD.left" :x2="width - PAD.right" :y1="t.y" :y2="t.y" class="grid" />
        <text :x="PAD.left - 8" :y="t.y + 4" text-anchor="end" class="axis">{{ format(t.v) }}</text>
      </g>
      <text v-for="l in xLabels" :key="l.i" :x="l.x" :y="height - 8" text-anchor="middle" class="axis">{{ l.text }}</text>

      <path v-if="points.length > 1" :d="path" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
      <circle v-if="points.length === 1" :cx="x(0)" :cy="y(points[0].value)" r="4" :fill="color" />

      <g v-if="tip">
        <line :x1="tip.px" :x2="tip.px" :y1="PAD.top" :y2="height - PAD.bottom" class="cross" />
        <circle :cx="tip.px" :cy="tip.py" r="5" :fill="color" stroke="#fff" stroke-width="2" />
      </g>
    </svg>
    <div v-if="isEmpty" class="empty">这段时间没有数据</div>
    <div v-if="tip" class="tip" :style="{ left: (tip.flip ? tip.px - 12 : tip.px + 12) + 'px', top: Math.max(tip.py - 44, 0) + 'px', transform: tip.flip ? 'translateX(-100%)' : 'none' }">
      <div class="tip-label">{{ tip.p.label }}</div>
      <div class="tip-value">{{ format(Number(tip.p.value) || 0) }}</div>
    </div>
  </div>
</template>

<style scoped>
.trend { position: relative; width: 100%; }
.grid { stroke: #ebeef5; stroke-width: 1; }
.cross { stroke: #c0c4cc; stroke-width: 1; stroke-dasharray: 3 3; }
.axis { fill: #909399; font-size: 11px; }
.empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 13px; pointer-events: none; }
.tip { position: absolute; pointer-events: none; background: #fff; border: 1px solid #e4e7ed; border-radius: 6px; padding: 6px 10px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); white-space: nowrap; }
.tip-label { color: #909399; font-size: 12px; }
.tip-value { color: #303133; font-size: 14px; font-weight: 600; }
</style>
