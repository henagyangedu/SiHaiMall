<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getConfig, saveConfig } from '@/api/config'
import { languageOptions } from '@/api/language'
import LangRichText from '@/components/LangRichText.vue'

// key 与后端配置分组 protocol 的键一致，也是 C 端接口 /api/protocols/{key} 的 key
const PROTOCOLS = [
  { key: 'user_agreement', label: '用户协议', tip: '注册页面“我已阅读并同意”所指的协议' },
  { key: 'privacy_policy', label: '隐私政策', tip: '说明收集哪些个人信息、如何使用和保存' },
  { key: 'refund_policy', label: '退换货政策', tip: '售后条件、期限和运费承担方式' },
  { key: 'shipping_policy', label: '配送政策', tip: '配送范围、时效和运费说明' },
  { key: 'distribution_agreement', label: '分销员协议', tip: '申请成为分销员时需要同意的协议' },
]

const languages = ref([])
const loading = ref(false)
const saving = ref(false)
const tab = ref(PROTOCOLS[0].key)
const form = reactive(Object.fromEntries(PROTOCOLS.map((p) => [p.key, {}])))

async function load() {
  loading.value = true
  try {
    const [langs, data] = await Promise.all([languageOptions(), getConfig('protocol')])
    languages.value = langs
    // 没保存过的协议后端返回空数组，统一成对象
    for (const p of PROTOCOLS) form[p.key] = data[p.key] && !Array.isArray(data[p.key]) ? data[p.key] : {}
  } finally {
    loading.value = false
  }
}
// 只保存当前页签的协议，避免一次提交全部长文本
async function submit() {
  saving.value = true
  try {
    await saveConfig('protocol', { [tab.value]: form[tab.value] })
    ElMessage.success('保存成功')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card v-loading="loading" shadow="never">
      <el-tabs v-model="tab">
        <el-tab-pane v-for="p in PROTOCOLS" :key="p.key" :label="p.label" :name="p.key" lazy>
          <div class="tip">{{ p.tip }}。没有填写的语言，前台会显示默认语言的内容；默认语言也没填时前台不显示该协议入口。</div>
          <LangRichText v-model="form[p.key]" :languages="languages" :height="460" ai :field-key="p.key" />
        </el-tab-pane>
      </el-tabs>
      <div style="margin-top: 16px">
        <el-button v-perm="'setting:protocol:edit'" type="primary" :loading="saving" @click="submit">保存当前协议</el-button>
        <span class="tip" style="margin-left: 12px">切换页签前请先保存，每次只保存当前页签的协议</span>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.tip { color: #909399; font-size: 13px; margin-bottom: 10px; }
</style>
