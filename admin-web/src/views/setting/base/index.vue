<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getConfig, saveConfig } from '@/api/config'
import { languageOptions } from '@/api/language'
import LangInput from '@/components/LangInput.vue'
import ImageUpload from '@/components/ImageUpload.vue'

const languages = ref([])
const loading = ref(false)
const saving = ref(false)
const form = reactive({
  site_name: {}, site_logo: '', site_favicon: '', site_desc: {}, site_keywords: {},
  company_name: '', service_email: '', service_phone: '', copyright: {}, site_icp: '',
  site_closed: 0, close_notice: {}, email_verify_enabled: 0,
})

async function load() {
  loading.value = true
  try {
    const [langs, data] = await Promise.all([languageOptions(), getConfig('base')])
    languages.value = langs
    Object.assign(form, data)
  } finally {
    loading.value = false
  }
}
async function submit() {
  saving.value = true
  try {
    await saveConfig('base', { ...form })
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
      <el-form :model="form" label-width="120px" style="max-width: 760px">
        <el-divider content-position="left">站点信息（多语言）</el-divider>
        <el-form-item label="站点名称"><LangInput v-model="form.site_name" :languages="languages" :maxlength="100" /></el-form-item>
        <el-form-item label="站点描述"><LangInput v-model="form.site_desc" :languages="languages" type="textarea" :maxlength="500" /></el-form-item>
        <el-form-item label="站点关键词"><LangInput v-model="form.site_keywords" :languages="languages" :maxlength="255" /></el-form-item>
        <el-form-item label="版权信息"><LangInput v-model="form.copyright" :languages="languages" :maxlength="255" /></el-form-item>

        <el-divider content-position="left">品牌与联系方式</el-divider>
        <el-form-item label="站点 Logo">
          <ImageUpload v-model="form.site_logo" scene="setting" :width="200" :height="70" tip="建议透明背景 PNG，单张不超过 5MB" />
        </el-form-item>
        <el-form-item label="站点图标">
          <ImageUpload v-model="form.site_favicon" scene="setting" :width="70" :height="70" tip="favicon，支持 png / ico" />
        </el-form-item>
        <el-form-item label="公司名称"><el-input v-model="form.company_name" maxlength="100" /></el-form-item>
        <el-form-item label="客服邮箱"><el-input v-model="form.service_email" maxlength="100" /></el-form-item>
        <el-form-item label="客服电话"><el-input v-model="form.service_phone" maxlength="30" /></el-form-item>
        <el-form-item label="备案号"><el-input v-model="form.site_icp" maxlength="100" placeholder="面向国内用户时填写 ICP 备案号" /></el-form-item>

        <el-divider content-position="left">站点状态</el-divider>
        <el-form-item label="关闭站点">
          <el-switch v-model="form.site_closed" :active-value="1" :inactive-value="0" />
          <span style="margin-left: 12px; color: #909399">开启后 C 端会收到关闭状态和提示语（C 端接入后生效）</span>
        </el-form-item>
        <el-form-item label="关闭提示"><LangInput v-model="form.close_notice" :languages="languages" type="textarea" :maxlength="500" /></el-form-item>

        <el-divider content-position="left">注册安全</el-divider>
        <el-form-item label="邮箱注册验证">
          <el-switch v-model="form.email_verify_enabled" :active-value="1" :inactive-value="0" />
          <span style="margin-left: 12px; color: #909399">开启后邮箱注册必须填写邮箱验证码，并开放邮箱找回密码。需先在服务器 .env 里配好发信邮箱（MAIL_*）</span>
        </el-form-item>

        <el-form-item>
          <el-button v-perm="'setting:base:edit'" type="primary" :loading="saving" @click="submit">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>
