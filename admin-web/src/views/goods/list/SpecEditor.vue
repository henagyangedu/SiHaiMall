<script setup>
import { Plus, Delete } from '@element-plus/icons-vue'

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
