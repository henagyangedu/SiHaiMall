<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  listOpenApp, createOpenApp, updateOpenApp, resetOpenAppKey, deleteOpenApp, listOpenAppLogs,
  resetOpenAppWebhookSecret, listOpenAppWebhooks, resendOpenAppWebhook, testOpenAppWebhook,
} from '@/api/openApp'
import { hasPerm } from '@/utils/perm'

const list = ref([])
const scopes = ref([]) // 权限的选项 [{code, label}]
const events = ref([]) // 能订阅的事件 [{code, label, scope}]
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listOpenApp()
    list.value = d.list
    scopes.value = d.scopes
    events.value = d.events
  } finally {
    loading.value = false
  }
}
const scopeLabel = (code) => scopes.value.find((s) => s.code === code)?.label || code
const eventLabel = (code) => (code === 'ping' ? '测试通知' : events.value.find((e) => e.code === code)?.label || code)

// ---------------------------------------------------------------- 新建 / 编辑
const dialog = ref(false)
const saving = ref(false)
const emptyForm = () => ({ id: 0, name: '', scopes: [], ip_whitelist: '', status: 1, remark: '', webhook_url: '', webhook_events: [], webhook_status: 0 })
const form = reactive(emptyForm())

function openCreate() {
  Object.assign(form, emptyForm())
  dialog.value = true
}
function openEdit(row) {
  // 白名单存的是逗号分隔，编辑时一行一个更好看
  Object.assign(form, {
    ...row, scopes: [...row.scopes], webhook_events: [...row.webhook_events],
    ip_whitelist: row.ip_whitelist.split(',').filter(Boolean).join('\n'),
  })
  dialog.value = true
}
const payload = (f) => ({
  name: f.name.trim(), scopes: f.scopes, ip_whitelist: f.ip_whitelist || '', status: f.status, remark: (f.remark || '').trim(),
  webhook_url: (f.webhook_url || '').trim(), webhook_status: f.webhook_status,
  // 取消勾选某项权限后，要这项权限的事件一起去掉
  webhook_events: f.webhook_events.filter((code) => f.scopes.includes(events.value.find((e) => e.code === code)?.scope)),
})

async function submit() {
  if (!form.name.trim()) return ElMessage.warning('请填写应用名称')
  if (!form.scopes.length) return ElMessage.warning('请至少选择一项权限')
  const data = payload(form)
  if (data.webhook_status && !data.webhook_url) return ElMessage.warning('开启主动推送要先填写接收地址')
  if (data.webhook_status && !data.webhook_events.length) return ElMessage.warning('开启主动推送要至少订阅一个事件')
  saving.value = true
  try {
    if (form.id) {
      const d = await updateOpenApp(form.id, data)
      if (d.webhook_secret) showKey(data.name, '', d.webhook_secret)
      else ElMessage.success('保存成功')
    } else {
      const d = await createOpenApp(data)
      showKey(d.name, d.key, d.webhook_secret || '')
    }
    dialog.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onStatus(row, status) {
  await updateOpenApp(row.id, payload({ ...row, status }))
  ElMessage.success(status ? '已启用' : '已停用，这把密钥现在不能调用接口，也不再给它推送')
  load()
}

async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除“${row.name}”吗？它的密钥会马上失效，调用记录和推送记录也一起删除。`, '删除确认', { type: 'warning' })
  await deleteOpenApp(row.id)
  ElMessage.success('已删除')
  load()
}

// ---------------------------------------------------------------- 密钥只显示一次（接口密钥、推送的签名密钥共用这个弹窗）
const keyDialog = ref(false)
const keyInfo = reactive({ name: '', key: '', secret: '' })

function showKey(name, key, secret = '') {
  Object.assign(keyInfo, { name, key, secret })
  keyDialog.value = true
}
async function onReset(row) {
  await ElMessageBox.confirm(`重新生成后，“${row.name}”现在用的密钥马上失效，对方系统要换成新密钥才能继续调用。确定吗？`, '重新生成密钥', { type: 'warning' })
  const d = await resetOpenAppKey(row.id)
  showKey(row.name, d.key)
  load()
}
async function onResetSecret() {
  await ElMessageBox.confirm('重新生成后，之后发出的通知马上改用新的签名密钥，对方系统要换成新的才能验证通过。确定吗？', '重新生成签名密钥', { type: 'warning' })
  const d = await resetOpenAppWebhookSecret(form.id)
  showKey(form.name, '', d.webhook_secret)
}
async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch (e) {
    ElMessage.warning('复制失败，请手动选中复制')
  }
}
function closeKey() {
  keyDialog.value = false
  Object.assign(keyInfo, { key: '', secret: '' })
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

// ---------------------------------------------------------------- 推送记录
const hookDrawer = ref(false)
const hooks = reactive({ app: null, list: [], total: 0, page: 1, loading: false, testing: false, busy: 0 })
const HOOK_TAG = { 0: 'warning', 1: 'success', 2: 'danger' }

async function loadHooks() {
  hooks.loading = true
  try {
    const d = await listOpenAppWebhooks(hooks.app.id, { page: hooks.page, size: 20 })
    hooks.list = d.list
    hooks.total = d.total
  } finally {
    hooks.loading = false
  }
}
function openHooks(row) {
  Object.assign(hooks, { app: row, list: [], total: 0, page: 1 })
  hookDrawer.value = true
  loadHooks()
}
// 对方返回了什么：状态码，或者连不上的原因
const hookResult = (w) => [w.last_code ? `HTTP ${w.last_code}` : '', w.last_error].filter(Boolean).join('　') || (w.attempts ? '' : '还没有发')
function tellResult(w) {
  if (w.status === 1) ElMessage.success(`对方已收到（HTTP ${w.last_code}）`)
  else ElMessage.error(`没有发成功：${hookResult(w) || '未知原因'}`)
}
async function onResend(w) {
  hooks.busy = w.id
  try {
    tellResult(await resendOpenAppWebhook(hooks.app.id, w.id))
  } finally {
    hooks.busy = 0
    loadHooks()
  }
}
async function onTest() {
  hooks.testing = true
  try {
    tellResult(await testOpenAppWebhook(hooks.app.id))
  } finally {
    hooks.testing = false
    hooks.page = 1
    loadHooks()
  }
}
// 正文排成好读的样子
function pretty(text) {
  try {
    return JSON.stringify(JSON.parse(text), null, 2)
  } catch (e) {
    return text
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
        开放接口是给 ERP、财务、物流这些外部系统用的：对方拿这里发的密钥，来读取订单、回传发货、同步库存、拉取支付流水；
        也可以让商城在订单付款、发货、退款时主动通知对方（主动推送）。
        一个外部系统建一个应用，只勾它用得到的权限。接口地址是“后端域名 + /open-api/v1”，参数和返回见仓库里的 docs/开放接口.md。
      </el-alert>

      <el-button v-perm="'setting:open_api:add'" type="success" style="margin-bottom: 12px" @click="openCreate">新建应用</el-button>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="应用" min-width="170">
          <template #default="{ row }">
            <div>{{ row.name }}</div>
            <div v-if="row.remark" class="sub">{{ row.remark }}</div>
          </template>
        </el-table-column>
        <el-table-column label="密钥" width="140"><template #default="{ row }"><code>{{ row.key_hint }}</code></template></el-table-column>
        <el-table-column label="权限" min-width="240">
          <template #default="{ row }">
            <el-tag v-for="s in row.scopes" :key="s" size="small" style="margin: 2px 4px 2px 0">{{ scopeLabel(s) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="IP 白名单" min-width="130">
          <template #default="{ row }">
            <span v-if="!row.ip_whitelist" class="sub">不限</span>
            <div v-for="ip in row.ip_whitelist.split(',').filter(Boolean)" v-else :key="ip">{{ ip }}</div>
          </template>
        </el-table-column>
        <el-table-column label="主动推送" min-width="200">
          <template #default="{ row }">
            <span v-if="!row.webhook_url" class="sub">没有设置</span>
            <template v-else>
              <el-tag size="small" :type="row.webhook_status ? 'success' : 'info'">{{ row.webhook_status ? `已开启 · ${row.webhook_events.length} 个事件` : '已关闭' }}</el-tag>
              <div class="sub url">{{ row.webhook_url }}</div>
            </template>
          </template>
        </el-table-column>
        <el-table-column label="最近调用" width="165"><template #default="{ row }">{{ row.last_used_at || '还没有调用过' }}</template></el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" :disabled="!hasPerm('setting:open_api:edit')" @change="(v) => onStatus(row, v)" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <div>
              <el-button v-perm="'setting:open_api:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="primary" @click="openLogs(row)">调用记录</el-button>
              <el-button link type="primary" @click="openHooks(row)">推送记录</el-button>
            </div>
            <div>
              <el-button v-perm="'setting:open_api:reset'" link type="warning" @click="onReset(row)">重新生成密钥</el-button>
              <el-button v-perm="'setting:open_api:del'" link type="danger" @click="onDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="form.id ? '编辑应用' : '新建应用'" width="660px" :close-on-click-modal="false">
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

        <el-divider content-position="left">主动推送（选填）</el-divider>
        <div class="note" style="margin: -6px 0 14px">
          订单付款、发货、退款这些事情发生时，商城主动通知对方系统，对方不用反复来查。不填就是只让对方来拉取。
        </div>
        <el-form-item label="接收地址">
          <el-input v-model="form.webhook_url" maxlength="300" class="webhook-url" placeholder="对方系统提供的地址，https:// 开头" />
          <div class="note">对方要在 5 秒内返回成功（HTTP 2xx），否则算没收到，之后会隔一段时间重试，最多再试 6 次。</div>
        </el-form-item>
        <el-form-item label="通知哪些事">
          <el-checkbox-group v-model="form.webhook_events" class="scopes webhook-events">
            <el-checkbox v-for="e in events" :key="e.code" :value="e.code" :disabled="!form.scopes.includes(e.scope)">
              {{ e.label }}
              <span v-if="!form.scopes.includes(e.scope)" class="sub">（要先勾选权限“{{ scopeLabel(e.scope) }}”）</span>
            </el-checkbox>
          </el-checkbox-group>
        </el-form-item>
        <el-form-item label="推送">
          <el-switch v-model="form.webhook_status" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" class="webhook-switch" />
        </el-form-item>
        <el-form-item v-if="form.id && form.webhook_url" label="签名密钥">
          <el-button v-perm="'setting:open_api:reset'" @click="onResetSecret">重新生成签名密钥</el-button>
          <div class="note">对方用它验证通知确实是商城发的。第一次填接收地址时生成并显示过一次；丢了在这里重新生成。</div>
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
      <template v-if="keyInfo.key">
        <div class="sub" style="margin-bottom: 6px">“{{ keyInfo.name }}”的密钥</div>
        <el-input :model-value="keyInfo.key" readonly class="key-input">
          <template #append><el-button @click="copy(keyInfo.key)">复制</el-button></template>
        </el-input>
        <div class="note">对方调用时放在请求头里：<code>Authorization: Bearer 密钥</code></div>
      </template>
      <template v-if="keyInfo.secret">
        <div class="sub" :style="{ margin: keyInfo.key ? '18px 0 6px' : '0 0 6px' }">“{{ keyInfo.name }}”的推送签名密钥</div>
        <el-input :model-value="keyInfo.secret" readonly class="key-input secret-input">
          <template #append><el-button @click="copy(keyInfo.secret)">复制</el-button></template>
        </el-input>
        <div class="note">对方收到通知时用它验证签名，验证办法见 docs/开放接口.md 的“主动推送”。</div>
      </template>
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

    <el-drawer v-model="hookDrawer" :title="`推送记录：${hooks.app?.name || ''}`" size="860px">
      <div class="hook-bar">
        <div class="sub">
          商城发给对方的每一条通知。没发成功的会自动重试，试完还不行就标为“失败”，对方恢复后可以在这里重发。记录保留 30 天。
        </div>
        <div>
          <el-button @click="loadHooks">刷新</el-button>
          <el-button v-if="hooks.app?.webhook_url" v-perm="'setting:open_api:resend'" type="primary" plain :loading="hooks.testing" @click="onTest">发送测试通知</el-button>
        </div>
      </div>
      <el-table v-loading="hooks.loading" :data="hooks.list" border size="small" row-key="id">
        <el-table-column type="expand">
          <template #default="{ row }">
            <div class="hook-detail">
              <div class="sub">通知编号 {{ row.event_id }}<span v-if="row.delivered_at">　送达时间 {{ row.delivered_at }}</span></div>
              <pre>{{ pretty(row.payload) }}</pre>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="时间" width="160" />
        <el-table-column label="事件" min-width="170">
          <template #default="{ row }">
            <div>{{ eventLabel(row.event) }}</div>
            <div v-if="row.ref_no" class="sub">{{ row.ref_no }}</div>
          </template>
        </el-table-column>
        <el-table-column label="结果" width="90">
          <template #default="{ row }"><el-tag size="small" :type="HOOK_TAG[row.status]">{{ row.status_text }}</el-tag></template>
        </el-table-column>
        <el-table-column label="对方的应答" min-width="220">
          <template #default="{ row }">
            <div class="hook-result">{{ hookResult(row) }}</div>
            <div v-if="row.status === 0 && row.next_at" class="sub">已试 {{ row.attempts }} 次，{{ row.next_at }} 再试</div>
            <div v-else-if="row.attempts > 1" class="sub">共试了 {{ row.attempts }} 次</div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'setting:open_api:resend'" link type="primary" :loading="hooks.busy === row.id" @click="onResend(row)">重发</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-model:current-page="hooks.page" :total="hooks.total" :page-size="20" layout="total, prev, pager, next"
        style="margin-top: 12px" @current-change="loadHooks"
      />
    </el-drawer>
  </div>
</template>

<style scoped>
.note { margin-top: 6px; color: #909399; font-size: 13px; line-height: 1.6; width: 100%; }
.sub { color: #909399; font-size: 13px; }
.url { margin-top: 4px; word-break: break-all; }
.scopes { display: flex; flex-direction: column; align-items: flex-start; }
.key-input :deep(input) { font-family: Consolas, Menlo, monospace; }
.hook-bar { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 10px; }
.hook-bar > div:last-child { flex-shrink: 0; }
.hook-detail { padding: 4px 16px 8px; }
.hook-detail pre { margin: 8px 0 0; padding: 10px 12px; background: #f6f7f9; border-radius: 4px; font-size: 12px; line-height: 1.6; white-space: pre-wrap; word-break: break-all; }
.hook-result { word-break: break-all; }
</style>
