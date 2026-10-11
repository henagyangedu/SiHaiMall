<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listDriver, saveDriver, testDriver } from '@/api/driver'

const TYPE = 'oauth'
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
    ElMessage.success(next === 1 ? `已启用 ${r.name}，登录页会出现对应的登录按钮` : `已关闭 ${r.name}，登录页不再显示对应的登录按钮`)
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
    await testDriver(TYPE, r.driver)
    await ElMessageBox.alert('服务器能取到 Google 的公钥，客户端 ID 的格式也对。\n\n这个测试验证不了客户端 ID 本身对不对、来源网址有没有填对——请在商城的登录页实际点一次 Google 登录确认。', '测试通过', { type: 'success' })
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
        title="启用后，买家在电脑端和手机浏览器的登录页可以用第三方账号登录：第一次登录自动注册一个会员，之后同一个账号直接登录。小程序里不显示。关闭后，已经这样注册的会员不受影响，只是暂时不能用这种方式登录（设置过密码的仍可用邮箱和密码登录）。" />
      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="name" label="登录方式" min-width="200" />
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
            <el-button v-perm="'app:oauth:edit'" link type="primary" @click="openConfig(r)">配置</el-button>
            <el-button v-perm="'app:oauth:test'" link type="success" :loading="testing === r.driver" @click="onTest(r)">测试连接</el-button>
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
