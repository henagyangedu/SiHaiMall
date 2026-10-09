<script setup>
import { ref, computed, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { translateText } from '@/api/translate'

// 用法：<LangInput v-model="form.title" :languages="languages" ai field-key="title" />
// modelValue 是 { 'zh-CN': '...', en: '...' }；languages 来自 /language/options
// 加上 ai 后，非默认语言的页签会出现“AI 翻译”按钮：把默认语言的内容翻译到当前页签（译文需人工确认后再保存）；
// 默认语言的页签会出现“翻译到其他语言”：一次填好所有还空着的语言，已有内容的语言不动
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

// 默认语言 → 所有还没填的语言
async function aiFillOthers() {
  const src = (props.modelValue?.[defaultCode.value] || '').trim()
  if (!src) {
    ElMessage.warning('请先填写默认语言的内容')
    return
  }
  const targets = props.languages.filter((l) => !l.is_default && !(props.modelValue?.[l.code] || '').trim())
  if (!targets.length) {
    ElMessage.info('其他语言都已填写；要重新翻译某个语言，请到它的页签里点“AI 翻译”')
    return
  }
  loadingCode.value = defaultCode.value
  const next = { ...(props.modelValue || {}) }
  const warnings = []
  const failed = []
  try {
    for (const l of targets) {
      try {
        const res = await translateText({ from: defaultCode.value, to: l.code, fields: { [props.fieldKey]: src } })
        next[l.code] = res.fields[props.fieldKey]
        emit('update:modelValue', { ...next })
        ;(res.warnings || []).forEach((w) => warnings.push(`${l.name}：${w}`))
      } catch (e) {
        failed.push(l.name) // 具体原因已由请求层弹出
      }
    }
  } finally {
    loadingCode.value = ''
  }
  if (failed.length) ElMessage.error(`${failed.join('、')} 翻译失败，可稍后重试`)
  else if (warnings.length) ElMessage.warning(`译文提示：${warnings.join('；')}`)
  else ElMessage.success('已翻译，请检查后再保存')
}
const hasOthers = computed(() => props.languages.some((l) => !l.is_default))
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
        <el-button v-if="ai && l.is_default && hasOthers" type="warning" plain :loading="loadingCode === l.code" class="ai-btn" @click="aiFillOthers">翻译到其他语言</el-button>
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
