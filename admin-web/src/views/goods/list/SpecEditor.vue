<script setup>
import { ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Delete } from '@element-plus/icons-vue'
import { translateText } from '@/api/translate'

// 规格项编辑器。v-model 是 specs 数组：[{ id, name:{lang:text}, values:[{ id, key, name:{lang:text} }] }]
// 说明：直接修改父组件传入的数组（父组件持有这份数据），改动后父组件会监听规格值 key 的变化来重建 SKU 表。
const props = defineProps({
  modelValue: { type: Array, required: true },
  languages: { type: Array, default: () => [] },
})

let seq = 0
const uid = () => `n${++seq}${Math.random().toString(36).slice(2, 6)}`

function addSpec() {
  if (props.modelValue.length >= 3) return
  props.modelValue.push({ id: 0, name: {}, values: [{ id: 0, key: uid(), name: {} }] })
}
function removeSpec(i) {
  props.modelValue.splice(i, 1)
}
function addValue(spec) {
  if (spec.values.length >= 30) return
  spec.values.push({ id: 0, key: uid(), name: {} })
}
function removeValue(spec, j) {
  spec.values.splice(j, 1)
}
const ph = (l) => l.name + (l.is_default ? '（必填）' : '')

// ---- AI 翻译：把默认语言的规格名、规格值翻译到其他语言里还空着的格子，已填的不动 ----
const translating = ref(false)
const defaultCode = computed(() => props.languages.find((l) => l.is_default)?.code || '')
const others = computed(() => props.languages.filter((l) => !l.is_default))
const BATCH = 20 // 翻译接口一次最多 20 个字段

async function aiTranslate() {
  const from = defaultCode.value
  // 所有规格名和规格值的 name 对象，按顺序编号作为字段名
  const cells = props.modelValue.flatMap((s) => [s.name, ...s.values.map((v) => v.name)])
  if (!cells.some((c) => (c[from] || '').trim())) {
    ElMessage.warning('请先填写默认语言的规格名和规格值')
    return
  }
  translating.value = true
  let filled = 0
  const failed = []
  try {
    for (const l of others.value) {
      const todo = cells.map((c, i) => [i, c]).filter(([, c]) => (c[from] || '').trim() && !(c[l.code] || '').trim())
      try {
        for (let k = 0; k < todo.length; k += BATCH) {
          const part = todo.slice(k, k + BATCH)
          const res = await translateText({ from, to: l.code, fields: Object.fromEntries(part.map(([i, c]) => [`spec_${i}`, c[from].trim()])) })
          for (const [i, c] of part) {
            const text = res.fields[`spec_${i}`]
            if (text) { c[l.code] = text; filled++ }
          }
        }
      } catch (e) {
        failed.push(l.name) // 具体原因已由请求层弹出
      }
    }
  } finally {
    translating.value = false
  }
  if (failed.length) ElMessage.error(`${failed.join('、')} 翻译失败，可稍后重试`)
  else if (!filled) ElMessage.info('其他语言都已填写；要重新翻译，请先清空对应的格子')
  else ElMessage.success(`已填写 ${filled} 处，请检查后保存`)
}
</script>

<template>
  <div class="spec-editor">
    <div v-for="(spec, i) in modelValue" :key="spec.id || i" class="spec-card">
      <div class="row head">
        <span class="label">规格名</span>
        <el-input v-for="l in languages" :key="l.code" v-model="spec.name[l.code]" class="inp" size="small" :placeholder="ph(l)" maxlength="100" />
        <el-button type="danger" link :icon="Delete" @click="removeSpec(i)">删除规格</el-button>
      </div>
      <div v-for="(v, j) in spec.values" :key="v.key" class="row">
        <span class="label sub">规格值</span>
        <el-input v-for="l in languages" :key="l.code" v-model="v.name[l.code]" class="inp" size="small" :placeholder="ph(l)" maxlength="100" />
        <el-button type="danger" link :icon="Delete" :disabled="spec.values.length <= 1" @click="removeValue(spec, j)" />
      </div>
      <el-button size="small" :icon="Plus" :disabled="spec.values.length >= 30" @click="addValue(spec)">添加规格值</el-button>
    </div>
    <el-button type="primary" plain :icon="Plus" :disabled="modelValue.length >= 3" @click="addSpec">添加规格项（最多 3 个）</el-button>
    <el-button v-if="others.length && modelValue.length" type="warning" plain :loading="translating" @click="aiTranslate">AI 翻译规格名和规格值</el-button>
    <div class="tip">例如规格项“颜色”下有红色、蓝色，规格项“尺码”下有 S、M、L，系统会自动生成 6 个规格组合。各语言的规格名和规格值都要填写，默认语言必填，其它语言缺失时前台回退显示默认语言。</div>
  </div>
</template>

<style scoped>
.spec-card { border: 1px solid #ebeef5; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; background: #fafafa; }
.row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.row.head { font-weight: 500; }
.label { width: 52px; flex: none; color: #606266; }
.label.sub { color: #909399; padding-left: 12px; width: 64px; }
.inp { width: 170px; }
.tip { margin-top: 8px; color: #909399; font-size: 12px; line-height: 1.6; }
</style>
