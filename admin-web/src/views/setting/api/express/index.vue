<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listDriver, saveDriver, testDriver } from '@/api/driver'

const TYPE = 'express'
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
    ElMessage.success(next === 1 ? `已启用 ${r.name}，订单详情里可以查看物流轨迹` : `已关闭 ${r.name}，订单详情不再显示物流轨迹`)
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
    await ElMessageBox.alert(r.driver === 'track17'
      ? '连接成功：API 密钥可用。\n\n测试只查了一次剩余额度，不消耗额度；真实效果请在一张已发货的订单里点“查看物流”确认。'
      : '连接成功：账号和授权 key 可用。\n\n测试用的是一个不存在的单号，可能会计一次查询；真实效果请在一张已发货的订单里点“查看物流”确认。', '测试通过', { type: 'success' })
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
        title="启用后，买家和管理员可以在订单详情里查看物流轨迹。查询要收费，所以商城会把结果缓存 30 分钟，已签收的包裹不再查询。两个可以同时启用：快递100 查得了的快递公司归快递100，其余的（跨境小包、“其它”）归 17TRACK；只启用快递100 时，这些包裹查不了。" />
      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="name" label="物流查询服务" min-width="200" />
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
            <el-button v-perm="'setting:api_express:edit'" link type="primary" @click="openConfig(r)">配置</el-button>
            <el-button v-perm="'setting:api_express:test'" link type="success" :loading="testing === r.driver" @click="onTest(r)">测试连接</el-button>
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
