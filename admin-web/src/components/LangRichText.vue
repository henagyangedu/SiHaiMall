<script setup>
import { ref, computed, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { translateText } from '@/api/translate'
import RichText from './RichText.vue'

// 用法：<LangRichText v-model="form.description" :languages="languages" ai field-key="description" />
// v-model 是 { 'zh-CN': '<p>…</p>', en: '…' }
const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
  languages: { type: Array, default: () => [] },
  height: { type: Number, default: 360 },
  ai: { type: Boolean, default: false },
  fieldKey: { type: String, default: 'description' },
})
const emit = defineEmits(['update:modelValue'])

const active = ref('')
const loading = ref(false)
const defaultCode = computed(() => props.languages.find((l) => l.is_default)?.code || '')
const activeLang = computed(() => props.languages.find((l) => l.code === active.value))

watch(
  () => props.languages,
  (list) => {
    if (list.length && !list.find((l) => l.code === active.value)) active.value = list[0].code
  },
  { immediate: true },
)
function update(code, v) {
  emit('update:modelValue', { ...(props.modelValue || {}), [code]: v })
}

async function aiTranslate() {
  const code = active.value
  const src = (props.modelValue?.[defaultCode.value] || '').trim()
  if (!src) {
    ElMessage.warning('请先填写默认语言的内容')
    return
  }
  if ((props.modelValue?.[code] || '').trim()) {
    try {
      await ElMessageBox.confirm('该语言已有内容，翻译后将覆盖，是否继续？', 'AI 翻译', { type: 'warning' })
    } catch (e) {
      return
    }
  }
  loading.value = true
  try {
    const res = await translateText({ from: defaultCode.value, to: code, fields: { [props.fieldKey]: src } })
    update(code, res.fields[props.fieldKey])
    if (res.warnings?.length) ElMessage.warning(`译文提示：${res.warnings.join('；')}`)
    else ElMessage.success('已翻译，请检查后再保存')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <el-tabs v-model="active" type="card" style="width: 100%">
    <el-tab-pane v-for="l in languages" :key="l.code" :name="l.code" :label="l.name + (l.is_default ? '（默认）' : '')" lazy>
      <div v-if="ai && !l.is_default" class="ai-bar">
        <el-button type="warning" plain size="small" :loading="loading" @click="aiTranslate">AI 翻译（从默认语言）</el-button>
        <span class="tip">图片和 HTML 格式会保留，翻译后请检查再保存</span>
      </div>
      <RichText :model-value="modelValue?.[l.code] || ''" :height="height" @update:model-value="update(l.code, $event)" />
    </el-tab-pane>
  </el-tabs>
</template>

<style scoped>
.ai-bar { margin-bottom: 8px; display: flex; align-items: center; gap: 10px; }
.tip { color: #909399; font-size: 12px; }
</style>
