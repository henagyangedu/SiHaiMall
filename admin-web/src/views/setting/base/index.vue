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
  company_name: '', service_email: '', service_phone: '', service_url: '', service_hours: {}, search_hot_words: {}, copyright: {}, site_icp: '',
  site_closed: 0, close_notice: {}, email_verify_enabled: 0, storage_notice_enabled: 0,
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
        <el-form-item label="站点名称"><LangInput v-model="form.site_name" :languages="languages" :maxlength="100" ai field-key="site_name" /></el-form-item>
        <el-form-item label="站点描述"><LangInput v-model="form.site_desc" :languages="languages" type="textarea" :maxlength="500" ai field-key="seo_desc" /></el-form-item>
        <el-form-item label="站点关键词"><LangInput v-model="form.site_keywords" :languages="languages" :maxlength="255" ai field-key="keywords" /></el-form-item>
        <el-form-item label="版权信息"><LangInput v-model="form.copyright" :languages="languages" :maxlength="255" ai field-key="copyright" /></el-form-item>

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
        <el-form-item label="在线客服链接">
          <el-input v-model="form.service_url" maxlength="500" placeholder="https://…" />
          <div style="color: #909399; font-size: 12px; line-height: 1.6; width: 100%">
            企业微信客服、美洽等第三方客服提供的网页地址，必须以 https:// 开头。填了之后，买家在商品详情和个人中心点“联系客服”会打开它；
            不填则只显示客服电话和服务时间。
          </div>
        </el-form-item>
        <el-form-item label="客服服务时间"><LangInput v-model="form.service_hours" :languages="languages" :maxlength="100" placeholder="如：周一至周五 9:00-18:00" /></el-form-item>
        <el-form-item label="热门搜索词">
          <LangInput v-model="form.search_hot_words" :languages="languages" :maxlength="200" placeholder="如：喂食器，饮水机，猫砂盆" />
          <div style="color: #909399; font-size: 12px; line-height: 1.6; width: 100%">
            买家点开搜索框时看到的推荐词，用逗号分开，最多显示 10 个。不填就不显示这一栏。
          </div>
        </el-form-item>
        <el-form-item label="备案号"><el-input v-model="form.site_icp" maxlength="100" placeholder="面向国内用户时填写 ICP 备案号" /></el-form-item>

        <el-divider content-position="left">站点状态</el-divider>
        <el-form-item label="关闭站点">
          <el-switch v-model="form.site_closed" :active-value="1" :inactive-value="0" />
          <span style="margin-left: 12px; color: #909399">开启后 C 端会收到关闭状态和提示语（C 端接入后生效）</span>
        </el-form-item>
        <el-form-item label="关闭提示"><LangInput v-model="form.close_notice" :languages="languages" type="textarea" :maxlength="500" ai field-key="notice" /></el-form-item>

        <el-divider content-position="left">注册安全</el-divider>
        <el-form-item label="邮箱注册验证">
          <el-switch v-model="form.email_verify_enabled" :active-value="1" :inactive-value="0" />
          <span style="margin-left: 12px; color: #909399">开启后邮箱注册必须填写邮箱验证码，并开放邮箱找回密码。需先在服务器 .env 里配好发信邮箱（MAIL_*）</span>
        </el-form-item>

        <el-divider content-position="left">隐私</el-divider>
        <el-form-item label="本地存储告知条">
          <el-switch v-model="form.storage_notice_enabled" :active-value="1" :inactive-value="0" />
          <span style="margin-left: 12px; color: #909399">开启后，买家第一次打开网页版（PC 端、手机网页）时底部显示一条说明：本站只用必要的本地存储、不做跟踪。面向欧洲等地区的买家时建议开启</span>
        </el-form-item>
        <el-alert type="info" :closable="false" show-icon style="margin: 0 0 18px 0" title="在“协议管理”里填了用户协议或隐私政策后，买家注册时必须勾选同意，同意的时间会记在会员资料里。" />

        <el-form-item>
          <el-button v-perm="'setting:base:edit'" type="primary" :loading="saving" @click="submit">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>
