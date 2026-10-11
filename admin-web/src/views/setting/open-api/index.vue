<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listOpenApp, createOpenApp, updateOpenApp, resetOpenAppKey, deleteOpenApp, listOpenAppLogs } from '@/api/openApp'
import { hasPerm } from '@/utils/perm'

const list = ref([])
const scopes = ref([]) // 权限的选项 [{code, label}]
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listOpenApp()
    list.value = d.list
    scopes.value = d.scopes
  } finally {
    loading.value = false
  }
}
const scopeLabel = (code) => scopes.value.find((s) => s.code === code)?.label || code

// ---------------------------------------------------------------- 新建 / 编辑
const dialog = ref(false)
const saving = ref(false)
const emptyForm = () => ({ id: 0, name: '', scopes: [], ip_whitelist: '', status: 1, remark: '' })
const form = reactive(emptyForm())

function openCreate() {
  Object.assign(form, emptyForm())
  dialog.value = true
}
function openEdit(row) {
  // 白名单存的是逗号分隔，编辑时一行一个更好看
  Object.assign(form, { ...row, scopes: [...row.scopes], ip_whitelist: row.ip_whitelist.split(',').filter(Boolean).join('\n') })
  dialog.value = true
}
const payload = (f) => ({ name: f.name.trim(), scopes: f.scopes, ip_whitelist: f.ip_whitelist || '', status: f.status, remark: (f.remark || '').trim() })

async function submit() {
  if (!form.name.trim()) return ElMessage.warning('请填写应用名称')
  if (!form.scopes.length) return ElMessage.warning('请至少选择一项权限')
  saving.value = true
  try {
    if (form.id) {
      await updateOpenApp(form.id, payload(form))
      ElMessage.success('保存成功')
    } else {
      const d = await createOpenApp(payload(form))
      showKey(d.name, d.key)
    }
    dialog.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onStatus(row, status) {
  await updateOpenApp(row.id, payload({ ...row, status }))
  ElMessage.success(status ? '已启用' : '已停用，这把密钥现在不能调用接口')
  load()
}

async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除“${row.name}”吗？它的密钥会马上失效，调用记录也一起删除。`, '删除确认', { type: 'warning' })
  await deleteOpenApp(row.id)
  ElMessage.success('已删除')
  load()
}

// ---------------------------------------------------------------- 密钥只显示一次
const keyDialog = ref(false)
const keyInfo = reactive({ name: '', key: '' })

function showKey(name, key) {
  Object.assign(keyInfo, { name, key })
  keyDialog.value = true
}
async function onReset(row) {
  await ElMessageBox.confirm(`重新生成后，“${row.name}”现在用的密钥马上失效，对方系统要换成新密钥才能继续调用。确定吗？`, '重新生成密钥', { type: 'warning' })
  const d = await resetOpenAppKey(row.id)
  showKey(row.name, d.key)
  load()
}
async function copyKey() {
  try {
    await navigator.clipboard.writeText(keyInfo.key)
    ElMessage.success('已复制')
  } catch (e) {
    ElMessage.warning('复制失败，请手动选中密钥复制')
  }
}
function closeKey() {
  keyDialog.value = false
  keyInfo.key = ''
}

// ---------------------------------------------------------------- 调用记录
const logDrawer = ref(false)
const logs = reactive({ app: null, list: [], total: 0, page: 1, loading: false })

async function loadLogs() {
  logs.loading = true
  try {
    const d = await listOpenAppLogs(logs.app.id, { page: logs.page, size: 20 })
    logs.list = d.list
    logs.total = d.total
  } finally {
    logs.loading = false
  }
}
function openLogs(row) {
  Object.assign(logs, { app: row, list: [], total: 0, page: 1 })
  logDrawer.value = true
  loadLogs()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
        开放接口是给 ERP、财务、物流这些外部系统用的：对方拿这里发的密钥，来读取订单、回传发货、同步库存、拉取支付流水。
        一个外部系统建一个应用，只勾它用得到的权限。接口地址是“后端域名 + /open-api/v1”，参数和返回见仓库里的 docs/开放接口.md。
      </el-alert>

      <el-button v-perm="'setting:open_api:add'" type="success" style="margin-bottom: 12px" @click="openCreate">新建应用</el-button>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="应用" min-width="180">
          <template #default="{ row }">
            <div>{{ row.name }}</div>
            <div v-if="row.remark" class="sub">{{ row.remark }}</div>
          </template>
        </el-table-column>
        <el-table-column label="密钥" width="150"><template #default="{ row }"><code>{{ row.key_hint }}</code></template></el-table-column>
        <el-table-column label="权限" min-width="260">
          <template #default="{ row }">
            <el-tag v-for="s in row.scopes" :key="s" size="small" style="margin: 2px 4px 2px 0">{{ scopeLabel(s) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="IP 白名单" min-width="150">
          <template #default="{ row }">
            <span v-if="!row.ip_whitelist" class="sub">不限</span>
            <div v-for="ip in row.ip_whitelist.split(',').filter(Boolean)" v-else :key="ip">{{ ip }}</div>
          </template>
        </el-table-column>
        <el-table-column label="最近调用" width="170"><template #default="{ row }">{{ row.last_used_at || '还没有调用过' }}</template></el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" :disabled="!hasPerm('setting:open_api:edit')" @change="(v) => onStatus(row, v)" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="280" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'setting:open_api:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="openLogs(row)">调用记录</el-button>
            <el-button v-perm="'setting:open_api:reset'" link type="warning" @click="onReset(row)">重新生成密钥</el-button>
            <el-button v-perm="'setting:open_api:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="form.id ? '编辑应用' : '新建应用'" width="620px" :close-on-click-modal="false">
      <el-form label-width="100px">
        <el-form-item label="应用名称" required>
          <el-input v-model="form.name" maxlength="60" placeholder="对方系统的名称，如“金蝶 ERP”“仓库发货系统”" />
          <div class="note">它通过接口发的货、改的库存，订单日志和库存日志里会写明是这个应用做的。</div>
        </el-form-item>
        <el-form-item label="权限" required>
          <el-checkbox-group v-model="form.scopes" class="scopes">
            <el-checkbox v-for="s in scopes" :key="s.code" :value="s.code">{{ s.label }}</el-checkbox>
          </el-checkbox-group>
          <div class="note">只勾对方用得到的。比如物流系统不需要“读取支付流水和售后单”。</div>
        </el-form-item>
        <el-form-item label="IP 白名单">
          <el-input v-model="form.ip_whitelist" type="textarea" :rows="3" placeholder="一行一个 IP；不填 = 不限制来源" />
          <div class="note">填了之后，只有从这些 IP 发来的请求才会被接受。对方系统的出口 IP 固定时建议填上。不支持网段。</div>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" maxlength="200" placeholder="选填，如对接人、用途" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">{{ form.id ? '保存' : '创建并生成密钥' }}</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="keyDialog" title="请保存密钥" width="620px" :close-on-click-modal="false" :close-on-press-escape="false" :show-close="false">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 14px">
        密钥只显示这一次，关掉之后就看不到了（系统里不保存密钥原文）。请现在复制下来交给对接方；丢了只能重新生成。
      </el-alert>
      <div class="sub" style="margin-bottom: 6px">“{{ keyInfo.name }}”的密钥</div>
      <el-input :model-value="keyInfo.key" readonly class="key-input">
        <template #append><el-button @click="copyKey">复制</el-button></template>
      </el-input>
      <div class="note">对方调用时放在请求头里：<code>Authorization: Bearer 密钥</code></div>
      <template #footer>
        <el-button type="primary" @click="closeKey">我已保存</el-button>
      </template>
    </el-dialog>

    <el-drawer v-model="logDrawer" :title="`调用记录：${logs.app?.name || ''}`" size="760px">
      <div class="sub" style="margin-bottom: 10px">只记录写操作（发货、改单号、改库存），不论成功还是失败；读取数据的调用不记。</div>
      <el-table v-loading="logs.loading" :data="logs.list" border size="small">
        <el-table-column prop="created_at" label="时间" width="160" />
        <el-table-column label="接口" min-width="220"><template #default="{ row }">{{ row.method }} /{{ row.path }}</template></el-table-column>
        <el-table-column label="结果" width="90">
          <template #default="{ row }"><el-tag size="small" :type="row.code === 0 ? 'success' : 'danger'">{{ row.code === 0 ? '成功' : `失败 ${row.code}` }}</el-tag></template>
        </el-table-column>
        <el-table-column prop="ip" label="IP" width="130" />
        <el-table-column prop="summary" label="提交的内容" min-width="240" show-overflow-tooltip />
      </el-table>
      <el-pagination
        v-model:current-page="logs.page" :total="logs.total" :page-size="20" layout="total, prev, pager, next"
        style="margin-top: 12px" @current-change="loadLogs"
      />
    </el-drawer>
  </div>
</template>

<style scoped>
.note { margin-top: 6px; color: #909399; font-size: 13px; line-height: 1.6; width: 100%; }
.sub { color: #909399; font-size: 13px; }
.scopes { display: flex; flex-direction: column; align-items: flex-start; }
.key-input :deep(input) { font-family: Consolas, Menlo, monospace; }
</style>
