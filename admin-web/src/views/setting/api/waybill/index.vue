<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listDriver, saveDriver } from '@/api/driver'
import { listWaybillAccount, createWaybillAccount, updateWaybillAccount, deleteWaybillAccount } from '@/api/waybill'

// 电子面单：上半部分是服务商（快递100）的授权信息和发货人，下半部分是每家快递的月结账号。两部分都配好，发货弹窗里才有“电子面单取号”
const TYPE = 'waybill'
const list = ref([])
const loading = ref(false)

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
    ElMessage.success(next === 1 ? `已启用 ${r.name}，发货时可以选“电子面单取号”` : `已关闭 ${r.name}，发货时只能手填快递单号`)
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

// ---- 快递账号
const accounts = ref([])
const expresses = ref([])
const accLoading = ref(false)
async function loadAccounts() {
  accLoading.value = true
  try {
    const d = await listWaybillAccount()
    accounts.value = d.list
    expresses.value = d.expresses
  } finally {
    accLoading.value = false
  }
}
const emptyAcc = () => ({ id: 0, express_code: '', partner_id: '', partner_key: '', net: '', temp_id: '', remark: '', status: 1, has_key: false })
const acc = reactive(emptyAcc())
const accDialog = ref(false)
function openAcc(r) {
  Object.assign(acc, emptyAcc(), r ? { ...r, partner_key: '' } : { express_code: expresses.value.find((e) => !accounts.value.some((a) => a.express_code === e.code))?.code || '' })
  accDialog.value = true
}
async function submitAcc() {
  if (!acc.express_code) return ElMessage.error('请选择快递公司')
  if (!acc.partner_id.trim()) return ElMessage.error('请填写月结账号')
  const payload = { express_code: acc.express_code, partner_id: acc.partner_id, partner_key: acc.partner_key, net: acc.net, temp_id: acc.temp_id, remark: acc.remark, status: acc.status }
  if (acc.id) await updateWaybillAccount(acc.id, payload)
  else await createWaybillAccount(payload)
  ElMessage.success('保存成功')
  accDialog.value = false
  loadAccounts()
}
async function removeAcc(r) {
  await ElMessageBox.confirm(`确定删除「${r.express_name}」的电子面单账号吗？删除后这家快递不能再取电子面单。`, '提示', { type: 'warning' })
  await deleteWaybillAccount(r.id)
  ElMessage.success('已删除')
  loadAccounts()
}

onMounted(() => {
  load()
  loadAccounts()
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 12px"
        title="启用后，发货时可以选“电子面单取号”：由商城向服务商下单，取回快递单号和面单图片，自动完成发货。这个功能是按快递100 的公开文档接入的，还没有用真实账号联调过；第一次使用请先拿一张测试订单验证，取号失败时会原样显示服务商给的原因。" />
      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="name" label="电子面单服务" min-width="200" />
        <el-table-column label="配置" width="90"><template #default="{ row: r }"><el-tag :type="r.configured ? 'success' : 'info'">{{ r.configured ? '已配置' : '未配置' }}</el-tag></template></el-table-column>
        <el-table-column label="启用" width="110">
          <template #default="{ row: r }">
            <span :title="r.configured ? '' : '请先点「配置」填写完整后再启用'">
              <el-switch :model-value="r.is_enabled === 1" inline-prompt active-text="开启" inactive-text="关闭" :disabled="!r.configured && r.is_enabled !== 1" :before-change="() => toggleEnabled(r)" />
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="updated_at" label="更新时间" width="170" />
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row: r }"><el-button v-perm="'setting:api_waybill:edit'" link type="primary" @click="openConfig(r)">配置</el-button></template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-card shadow="never">
      <template #header>
        <div class="head">
          <span>快递账号<span class="hint inline">每家快递一条，填你和快递公司签约的月结账号。只有这里配了账号且启用的快递，发货时才能取电子面单。</span></span>
          <el-button v-perm="'setting:api_waybill:edit'" type="success" :disabled="accounts.length >= expresses.length" @click="openAcc(null)">新增快递账号</el-button>
        </div>
      </template>
      <el-table v-loading="accLoading" :data="accounts" border stripe empty-text="还没有配置快递账号">
        <el-table-column prop="express_name" label="快递公司" width="150" />
        <el-table-column prop="partner_id" label="月结账号" min-width="160" />
        <el-table-column label="密码" width="90"><template #default="{ row: r }">{{ r.has_key ? '已填' : '未填' }}</template></el-table-column>
        <el-table-column prop="net" label="网点" min-width="140" />
        <el-table-column prop="temp_id" label="面单模板编号" min-width="160" />
        <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip />
        <el-table-column label="状态" width="90"><template #default="{ row: r }"><el-tag :type="r.status === 1 ? 'success' : 'info'">{{ r.status === 1 ? '启用' : '停用' }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row: r }">
            <el-button v-perm="'setting:api_waybill:edit'" link type="primary" @click="openAcc(r)">编辑</el-button>
            <el-button v-perm="'setting:api_waybill:edit'" link type="danger" @click="removeAcc(r)">删除</el-button>
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

    <el-dialog v-model="accDialog" :title="acc.id ? '编辑快递账号' : '新增快递账号'" width="560px" destroy-on-close>
      <el-form label-width="110px">
        <el-form-item label="快递公司" required>
          <el-select v-model="acc.express_code" :disabled="!!acc.id" style="width: 220px">
            <el-option v-for="e in expresses" :key="e.code" :label="e.name" :value="e.code" :disabled="accounts.some((a) => a.express_code === e.code && a.id !== acc.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="月结账号" required>
          <el-input v-model="acc.partner_id" maxlength="100" placeholder="快递公司给的月结账号 / 客户号" />
        </el-form-item>
        <el-form-item label="账号密码">
          <el-input v-model="acc.partner_key" type="password" show-password maxlength="200" :placeholder="acc.has_key ? '已保存，不修改请留空' : '有的快递不需要，按快递公司的要求填'" />
          <div class="hint">加密保存，保存后页面不再显示。</div>
        </el-form-item>
        <el-form-item label="网点"><el-input v-model="acc.net" maxlength="100" placeholder="部分快递（如中通、圆通、韵达）需要填网点名称" /></el-form-item>
        <el-form-item label="面单模板编号">
          <el-input v-model="acc.temp_id" maxlength="100" placeholder="在快递100 后台的“电子面单模板”里查看" />
        </el-form-item>
        <el-form-item label="备注"><el-input v-model="acc.remark" maxlength="100" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="acc.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="accDialog = false">取消</el-button>
        <el-button type="primary" @click="submitAcc">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.hint { color: #909399; font-size: 12px; line-height: 1.5; margin-top: 2px; width: 100%; }
.hint.inline { margin-left: 12px; font-weight: 400; }
.head { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
</style>
