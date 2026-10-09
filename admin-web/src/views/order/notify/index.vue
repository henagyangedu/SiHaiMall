<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listNotify, showNotify, retryNotify } from '@/api/pay'

const query = reactive({ channel: '', handle_status: '', trade_no: '', range: [], page: 1, size: 20 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const [from, to] = query.range || []
    const d = await listNotify({ channel: query.channel, handle_status: query.handle_status, trade_no: query.trade_no, date_from: from || '', date_to: to || '', page: query.page, size: query.size })
    list.value = d.list
    total.value = d.total
  } finally {
    loading.value = false
  }
}
function search() {
  query.page = 1
  load()
}
const typeOf = (s) => ({ 0: 'warning', 1: 'success', 2: 'danger', 3: 'info' })[s] || 'info'

const dialog = ref(false)
const detail = ref(null)
async function openDetail(r) {
  detail.value = await showNotify(r.id)
  dialog.value = true
}
async function onRetry(r) {
  await ElMessageBox.confirm(`重新处理回调 ${r.event_id}？已入账的支付不会重复入账。`, '重新处理', { type: 'warning' })
  await retryNotify(r.id)
  ElMessage.success('已重新处理')
  load()
}
onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px"
        title="渠道的每次回调原文都会先入库再处理。「失败」表示需要人工关注（验签失败、金额不一致、订单已关闭仍收到付款等）；渠道重复推送会自动忽略。" />
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.trade_no" placeholder="支付单号" clearable style="width: 200px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.channel" placeholder="渠道" clearable style="width: 120px" @change="search">
            <el-option label="Stripe" value="stripe" /><el-option label="PayPal" value="paypal" /><el-option label="支付宝" value="alipay" /><el-option label="微信" value="wechat" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.handle_status" placeholder="处理结果" clearable style="width: 120px" @change="search">
            <el-option label="待处理" :value="0" /><el-option label="成功" :value="1" /><el-option label="失败" :value="2" /><el-option label="重复忽略" :value="3" />
          </el-select>
        </el-form-item>
        <el-form-item><el-date-picker v-model="query.range" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始" end-placeholder="结束" @change="search" /></el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="channel" label="渠道" width="90" />
        <el-table-column prop="event_id" label="事件 ID" min-width="220" show-overflow-tooltip />
        <el-table-column prop="trade_no" label="支付单号" min-width="190" />
        <el-table-column label="验签" width="80"><template #default="{ row }"><el-tag :type="row.verify_ok ? 'success' : 'danger'">{{ row.verify_ok ? '通过' : '未过' }}</el-tag></template></el-table-column>
        <el-table-column label="结果" width="100"><template #default="{ row }"><el-tag :type="typeOf(row.handle_status)">{{ row.handle_text }}</el-tag></template></el-table-column>
        <el-table-column prop="error_msg" label="说明" min-width="260" show-overflow-tooltip />
        <el-table-column prop="created_at" label="时间" width="165" />
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'order:notify:view'" link type="primary" @click="openDetail(row)">原文</el-button>
            <el-button v-if="row.verify_ok && (row.handle_status === 2 || row.handle_status === 0)" v-perm="'order:notify:retry'" link type="warning" @click="onRetry(row)">重新处理</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="dialog" title="回调原文" width="760px" destroy-on-close>
      <template v-if="detail">
        <div style="margin-bottom: 8px; color: #909399">{{ detail.channel }} · {{ detail.event_id }}</div>
        <div style="font-weight: 600; margin-bottom: 4px">请求头（已过滤敏感项）</div>
        <pre class="code">{{ JSON.stringify(detail.headers, null, 2) }}</pre>
        <div style="font-weight: 600; margin: 8px 0 4px">内容</div>
        <pre class="code">{{ detail.body }}</pre>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.code { background: #f5f7fa; padding: 10px; border-radius: 4px; max-height: 360px; overflow: auto; font-size: 12px; white-space: pre-wrap; word-break: break-all; }
</style>
