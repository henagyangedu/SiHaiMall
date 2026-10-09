<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listDriver, saveDriver, testDriver } from '@/api/driver'

const TYPE = 'sms'
const list = ref([])
const loading = ref(false)
const testing = ref('')

async function load() {
  loading.value = true
  try {
    list.value = await listDriver(TYPE)
  } finally {
    loading.value = false
  }
}

// 列表上直接开关：只提交 is_enabled，其余配置保持不变；成功后才翻转
async function toggleEnabled(r) {
  const next = r.is_enabled === 1 ? 0 : 1
  try {
    await saveDriver(TYPE, r.driver, { is_enabled: next })
    r.is_enabled = next
    ElMessage.success(next === 1 ? `已启用 ${r.name}，手机号注册将要求短信验证码` : `已关闭 ${r.name}，手机号注册不再校验验证码`)
    return true
  } catch (e) {
    return false
  }
}

const dialog = ref(false)
const row = ref(null)
const form = reactive({ is_enabled: 0, config: {} })
function openConfig(r) {
  row.value = r
  form.is_enabled = r.is_enabled
  form.config = { ...r.config }
  dialog.value = true
}
async function submit() {
  if (form.is_enabled === 1) {
    for (const f of row.value.fields) {
      if (f.required && !String(form.config[f.key] ?? '').trim()) {
        ElMessage.error(`请填写「${f.label}」`)
        return
      }
    }
  }
  await saveDriver(TYPE, row.value.driver, { is_enabled: form.is_enabled, config: form.config })
  ElMessage.success('保存成功')
  dialog.value = false
  load()
}

async function onTest(r) {
  testing.value = r.driver
  try {
    const d = await testDriver(TYPE, r.driver)
    const quota = d.quotas?.length ? `\n\n额度信息：${JSON.stringify(d.quotas)}` : ''
    await ElMessageBox.alert(`连接成功：接口地址、AppKey、AppSecret 可用。${quota}\n\n此测试只查询余额，不会发送短信；场景标识是否正确，请用真实手机号在前台获取一次验证码确认。`, '测试通过', { type: 'success' })
  } finally {
    testing.value = ''
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px"
        title="启用短信后：手机号注册必须填写短信验证码，并开放短信登录、短信找回密码。验证码由短信平台生成并校验，目前只支持中国大陆手机号，境外用户请使用邮箱注册。" />
      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="name" label="短信平台" min-width="200" />
        <el-table-column label="配置" width="90"><template #default="{ row: r }"><el-tag :type="r.configured ? 'success' : 'info'">{{ r.configured ? '已配置' : '未配置' }}</el-tag></template></el-table-column>
        <el-table-column label="启用" width="110">
          <template #default="{ row: r }">
            <span :title="r.configured ? '' : '请先点「配置」填写完整后再启用'">
              <el-switch :model-value="r.is_enabled === 1" inline-prompt active-text="开启" inactive-text="关闭" :disabled="!r.configured && r.is_enabled !== 1" :before-change="() => toggleEnabled(r)" />
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="updated_at" label="更新时间" width="170" />
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row: r }">
            <el-button v-perm="'setting:api_sms:edit'" link type="primary" @click="openConfig(r)">配置</el-button>
            <el-button v-perm="'setting:api_sms:test'" link type="success" :loading="testing === r.driver" @click="onTest(r)">测试连接</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="`配置：${row?.name || ''}`" width="680px" destroy-on-close>
      <el-form v-if="row" label-width="140px">
        <el-alert type="info" :closable="false" style="margin-bottom: 14px" :title="row.desc" />
        <el-form-item v-for="f in row.fields" :key="f.key" :label="f.label" :required="f.required">
          <el-input v-model="form.config[f.key]" :type="f.type === 'secret' ? 'password' : 'text'" :show-password="f.type === 'secret'" />
          <div v-if="f.hint" class="hint">{{ f.hint }}</div>
        </el-form-item>
        <el-divider />
        <el-form-item label="启用"><el-switch v-model="form.is_enabled" :active-value="1" :inactive-value="0" /><span class="hint" style="margin-left: 12px; width: auto">启用前请填写完整的必填项</span></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.hint { color: #909399; font-size: 12px; line-height: 1.5; margin-top: 2px; width: 100%; }
</style>
