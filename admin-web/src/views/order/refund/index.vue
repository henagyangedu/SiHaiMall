<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listRefund, refundCounts, getRefund, auditRefund, confirmRefundReturn, payRefund } from '@/api/refund'
import { hasPerm } from '@/utils/perm'

// 售后单状态：0待审核 1待买家退货 2待确认收货 3退款中 4已完成 5已拒绝 6已撤销
const PENDING = 0
const WAIT_RECEIVE = 2
const REFUNDING = 3

const query = reactive({ keyword: '', status: '', type: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)
const counts = ref([])

async function load() {
  loading.value = true
  try {
    const d = await listRefund(query)
    list.value = d.list
    total.value = d.total
  } finally {
    loading.value = false
  }
  refundCounts().then((c) => (counts.value = c)).catch(() => {})
}
function search() {
  query.page = 1
  load()
}
function onTab(name) {
  query.status = name === 'all' ? '' : Number(name)
  search()
}
const statusType = (s) => ({ 0: 'warning', 1: 'primary', 2: 'primary', 3: 'danger', 4: 'success', 5: 'info', 6: 'info' })[s] || 'info'

// 详情
const drawer = ref(false)
const d = ref(null)
async function openDetail(row) {
  d.value = await getRefund(row.id)
  drawer.value = true
}
async function refresh() {
  if (d.value) d.value = await getRefund(d.value.id)
  load()
}

// 审核
const audit = reactive({ show: false, pass: 1, amount: 0, reject_reason: '', saving: false })
function openAudit() {
  Object.assign(audit, { show: true, pass: 1, amount: Number(d.value.refund_amount), reject_reason: '', saving: false })
}
async function submitAudit() {
  if (!audit.pass && !audit.reject_reason.trim()) return ElMessage.warning('请填写拒绝原因')
  audit.saving = true
  try {
    await auditRefund(d.value.id, audit.pass ? { pass: 1, amount: audit.amount } : { pass: 0, reject_reason: audit.reject_reason.trim() })
    ElMessage.success(audit.pass ? '已同意' : '已拒绝')
    audit.show = false
    refresh()
  } finally {
    audit.saving = false
  }
}

// 确认收到退货
async function onConfirm() {
  await ElMessageBox.confirm('确认已收到买家寄回的商品？确认后进入退款环节。', '确认收货', { type: 'warning' })
  await confirmRefundReturn(d.value.id)
  ElMessage.success('已确认收货')
  refresh()
}

// 执行退款
async function onPay(offline) {
  const amount = `${d.value.refund_amount} ${d.value.currency}`
  const text = offline
    ? `登记为“线下已退款”：系统不会向支付渠道发起退款，请确认 ${amount} 已通过线下或渠道后台退给买家。`
    : `将通过 ${d.value.order.pay_channel} 把 ${amount} 原路退回给买家，操作不可撤销。`
  await ElMessageBox.confirm(text, offline ? '线下已退款' : '原路退款', { type: 'warning', confirmButtonText: '确认退款' })
  const res = await payRefund(d.value.id, offline)
  if (res.done) ElMessage.success('退款已完成')
  else ElMessage.warning('支付渠道已受理，正在处理中，请稍后再次点击“原路退款”查询结果')
  refresh()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-tabs :model-value="query.status === '' ? 'all' : String(query.status)" @tab-change="onTab">
        <el-tab-pane label="全部" name="all" />
        <el-tab-pane v-for="c in counts" :key="c.status" :name="String(c.status)">
          <template #label>{{ c.text }}<el-badge v-if="c.count && [0, 2, 3].includes(c.status)" :value="c.count" :max="99" style="margin-left: 4px" /></template>
        </el-tab-pane>
      </el-tabs>

      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="售后单号 / 订单号" clearable style="width: 220px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.type" placeholder="售后类型" clearable style="width: 130px" @change="search">
            <el-option label="仅退款" :value="1" /><el-option label="退货退款" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="售后单" min-width="300">
          <template #default="{ row }">
            <div style="display: flex; gap: 8px; align-items: center">
              <el-image v-if="row.image" :src="row.image" fit="cover" style="width: 44px; height: 44px; flex: none" />
              <div style="min-width: 0">
                <div style="font-weight: 600">{{ row.refund_no }}</div>
                <div class="sub ellipsis">{{ row.goods }}<span v-if="!row.whole_order">　× {{ row.qty }}</span></div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="order_no" label="订单号" min-width="190" />
        <el-table-column prop="member" label="会员" min-width="120" />
        <el-table-column prop="type_text" label="类型" width="100" />
        <el-table-column label="退款金额" width="140"><template #default="{ row }"><b>{{ row.refund_amount }}</b> {{ row.currency }}</template></el-table-column>
        <el-table-column label="状态" width="120"><template #default="{ row }"><el-tag :type="statusType(row.status)">{{ row.status_text }}</el-tag></template></el-table-column>
        <el-table-column prop="created_at" label="申请时间" min-width="160" />
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }"><el-button link type="primary" @click="openDetail(row)">处理</el-button></template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-drawer v-model="drawer" title="售后详情" size="720px" destroy-on-close>
      <template v-if="d">
        <div class="head">
          <div>
            <div class="no">{{ d.refund_no }}</div>
            <div class="sub">订单 {{ d.order.order_no }}　{{ d.order.status_text }} / {{ d.order.pay_status_text }}</div>
          </div>
          <el-tag :type="statusType(d.status)" size="large">{{ d.status_text }}</el-tag>
        </div>

        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="售后类型">{{ d.type_text }}{{ d.whole_order ? '（整单）' : '' }}</el-descriptions-item>
          <el-descriptions-item label="退款金额"><b>{{ d.refund_amount }}</b> {{ d.currency }}</el-descriptions-item>
          <el-descriptions-item label="会员">{{ d.member?.name }}</el-descriptions-item>
          <el-descriptions-item label="申请时间">{{ d.created_at }}</el-descriptions-item>
          <el-descriptions-item label="申请原因" :span="2">{{ d.reason }}</el-descriptions-item>
          <el-descriptions-item v-if="d.images.length" label="凭证" :span="2">
            <el-image v-for="(u, i) in d.images" :key="i" :src="u" :preview-src-list="d.images" :initial-index="i" preview-teleported fit="cover" style="width: 60px; height: 60px; margin-right: 6px" />
          </el-descriptions-item>
          <el-descriptions-item v-if="d.return_express_no" label="退货物流" :span="2">{{ d.return_express }} {{ d.return_express_no }}</el-descriptions-item>
          <el-descriptions-item v-if="d.reject_reason" label="拒绝原因" :span="2">{{ d.reject_reason }}</el-descriptions-item>
          <el-descriptions-item v-if="d.refund_trade_no" label="退款流水号" :span="2">{{ d.refund_trade_no }}</el-descriptions-item>
          <el-descriptions-item v-if="d.finished_at" label="结束时间" :span="2">{{ d.finished_at }}</el-descriptions-item>
        </el-descriptions>

        <h4>售后商品</h4>
        <el-table :data="d.items" border size="small">
          <el-table-column label="商品" min-width="240">
            <template #default="{ row }">
              <div style="display: flex; gap: 8px; align-items: center">
                <el-image v-if="row.image" :src="row.image" fit="cover" style="width: 40px; height: 40px; flex: none" />
                <div><div>{{ row.title }}</div><div class="sub">{{ row.spec_text }}</div></div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="price" label="单价" width="90" />
          <el-table-column prop="qty" label="购买" width="70" />
          <el-table-column prop="apply_qty" label="本次申请" width="90" />
          <el-table-column prop="refund_qty" label="已退" width="70" />
          <el-table-column label="发货" width="80"><template #default="{ row }"><el-tag size="small" :type="row.ship_status ? 'success' : 'info'">{{ row.ship_status ? '已发' : '未发' }}</el-tag></template></el-table-column>
        </el-table>

        <div class="sum">
          <div>商品金额：{{ d.order.goods_amount }}　运费：{{ d.order.shipping_fee }}　实付：<b>{{ d.order.pay_amount }}</b> {{ d.currency }}</div>
          <div>该订单已退：{{ d.order.refunded_amount }} {{ d.currency }}　支付方式：{{ d.order.online_paid ? d.order.pay_channel : '线下收款' }}</div>
        </div>

        <div class="actions">
          <el-button v-if="d.status === PENDING && hasPerm('order:refund:audit')" type="primary" @click="openAudit">审核</el-button>
          <el-button v-if="d.status === WAIT_RECEIVE && hasPerm('order:refund:confirm')" type="primary" @click="onConfirm">确认收到退货</el-button>
          <template v-if="d.status === REFUNDING && hasPerm('order:refund:pay')">
            <el-button v-if="d.order.online_paid" type="danger" @click="onPay(false)">原路退款</el-button>
            <el-button @click="onPay(true)">线下已退款</el-button>
          </template>
        </div>
        <div v-if="d.status === REFUNDING" class="sub" style="margin-top: 8px">
          “原路退款”会向支付渠道发起退款；“线下已退款”只做登记，用于已在线下或渠道后台退过款的情况。未发货的商品、退货退款的商品在退款完成时自动回补库存。
        </div>
      </template>
    </el-drawer>

    <el-dialog v-model="audit.show" title="售后审核" width="480px" destroy-on-close>
      <el-form label-width="90px">
        <el-form-item label="审核结果">
          <el-radio-group v-model="audit.pass">
            <el-radio :value="1">同意</el-radio>
            <el-radio :value="0">拒绝</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="audit.pass" label="退款金额">
          <el-input-number v-model="audit.amount" :min="0.01" :precision="2" :controls="false" style="width: 180px" />
          <span class="sub" style="margin-left: 8px">{{ d?.currency }}，买家申请 {{ d?.refund_amount }}</span>
        </el-form-item>
        <el-form-item v-else label="拒绝原因" required>
          <el-input v-model="audit.reject_reason" type="textarea" :rows="3" maxlength="255" show-word-limit placeholder="买家可见" />
        </el-form-item>
        <div v-if="audit.pass" class="sub" style="padding-left: 90px">
          {{ d?.type === 2 ? '同意后等待买家寄回商品，确认收货后再退款。' : '同意后进入“退款中”，需要再执行退款。' }}金额可改，不能超过订单剩余可退金额。
        </div>
      </el-form>
      <template #footer>
        <el-button @click="audit.show = false">取消</el-button>
        <el-button type="primary" :loading="audit.saving" @click="submitAudit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; }
.ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.head .no { font-size: 16px; font-weight: 600; }
h4 { margin: 16px 0 8px; }
.sum { margin-top: 10px; line-height: 1.9; font-size: 13px; }
.actions { margin-top: 16px; }
</style>
