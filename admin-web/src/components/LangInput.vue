<script setup>
import { ref, computed, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { translateText } from '@/api/translate'

// 用法：<LangInput v-model="form.title" :languages="languages" ai field-key="title" />
// modelValue 是 { 'zh-CN': '...', en: '...' }；languages 来自 /language/options
// 加上 ai 后，非默认语言的页签会出现“AI 翻译”按钮：把默认语言的内容翻译到当前页签（译文需人工确认后再保存）
const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
  languages: { type: Array, default: () => [] },
  type: { type: String, default: 'input' }, // input | textarea
  maxlength: { type: Number, default: undefined },
  rows: { type: Number, default: 3 },
  placeholder: { type: String, default: '' },
  ai: { type: Boolean, default: false },
  fieldKey: { type: String, default: 'text' },
})
const emit = defineEmits(['update:modelValue'])

const active = ref('')
const loadingCode = ref('')
const defaultCode = computed(() => props.languages.find((l) => l.is_default)?.code || '')

watch(
  () => props.languages,
  (list) => {
    if (list.length && !list.find((l) => l.code === active.value)) active.value = list[0].code
  },
  { immediate: true },
)

function update(code, value) {
  emit('update:modelValue', { ...(props.modelValue || {}), [code]: value })
}

async function aiTranslate(code) {
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
  loadingCode.value = code
  try {
    const res = await translateText({ from: defaultCode.value, to: code, fields: { [props.fieldKey]: src } })
    update(code, res.fields[props.fieldKey])
    if (res.warnings?.length) ElMessage.warning(`译文提示：${res.warnings.join('；')}`)
    else ElMessage.success('已翻译，请检查后再保存')
  } finally {
    loadingCode.value = ''
  }
}
</script>

<template>
  <el-tabs v-model="active" type="card" class="lang-input">
    <el-tab-pane v-for="l in languages" :key="l.code" :name="l.code" :label="l.name + (l.is_default ? '（默认）' : '')">
      <div class="line">
        <el-input
          :model-value="modelValue?.[l.code] || ''"
          :type="type === 'textarea' ? 'textarea' : 'text'"
          :rows="rows"
          :maxlength="maxlength"
          :placeholder="placeholder"
          @update:model-value="update(l.code, $event)"
        />
        <el-button v-if="ai && !l.is_default" type="warning" plain :loading="loadingCode === l.code" class="ai-btn" @click="aiTranslate(l.code)">AI 翻译</el-button>
      </div>
    </el-tab-pane>
  </el-tabs>
</template>

<style scoped>
.lang-input { width: 100%; }
.lang-input :deep(.el-tabs__header) { margin-bottom: 8px; }
.line { display: flex; gap: 8px; align-items: flex-start; }
.ai-btn { flex: none; }
</style>
