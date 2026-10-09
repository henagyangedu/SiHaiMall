<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listOrder, orderCounts, getOrder, setOrderRemark, changeOrderPrice, cancelOrder, receiveOrder } from '@/api/order'
import { hasPerm } from '@/utils/perm'
import OrderDetail from '../OrderDetail.vue'

const query = reactive({ order_no: '', keyword: '', status: '', pay_status: '', range: [], page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)
const counts = ref([])

async function load() {
  loading.value = true
  try {
    const [from, to] = query.range || []
    const d = await listOrder({ order_no: query.order_no, keyword: query.keyword, status: query.status, pay_status: query.pay_status, date_from: from || '', date_to: to || '', page: query.page, size: query.size })
    list.value = d.list
    total.value = d.total
  } finally {
    loading.value = false
  }
  orderCounts().then((c) => (counts.value = c)).catch(() => {})
}
function search() {
  query.page = 1
  load()
}
function onTab(name) {
  query.status = name === 'all' ? '' : Number(name)
  search()
}
const statusType = (s) => ({ 0: 'warning', 1: 'primary', 2: 'primary', 3: 'success', 4: 'info', 5: 'danger', 6: 'info' })[s] || 'info'

// 详情
const drawer = ref(false)
const detail = ref(null)
async function openDetail(row) {
  detail.value = await getOrder(row.id)
  drawer.value = true
}
async function refresh() {
  detail.value = await getOrder(detail.value.id)
  load()
}

// 备注
async function onRemark(id, current) {
  const { value } = await ElMessageBox.prompt('商家备注（仅后台可见）', '备注', { inputValue: current || '', inputType: 'textarea', inputPattern: /^[\s\S]{0,500}$/, inputErrorMessage: '最多 500 字' })
  await setOrderRemark(id, value)
  ElMessage.success('已保存')
  detail.value?.id === id ? refresh() : load()
}
// 改价
async function onPrice(row) {
  const { value } = await ElMessageBox.prompt(`当前应付 ${row.pay_amount} ${row.currency}。输入改后的应付金额（含运费，最多两位小数）`, '修改价格', {
    inputValue: row.pay_amount, inputPattern: /^\d{1,9}(\.\d{1,2})?$/, inputErrorMessage: '金额格式不正确',
  })
  await changeOrderPrice(row.id, value)
  ElMessage.success('改价成功')
  detail.value?.id === row.id ? refresh() : load()
}
// 取消
async function onCancel(row) {
  const { value } = await ElMessageBox.prompt(`取消订单 ${row.order_no}？库存将自动回补。`, '取消订单', { inputPlaceholder: '取消原因（可选）', confirmButtonText: '确认取消', type: 'warning', inputPattern: /^.{0,200}$/, inputErrorMessage: '最多 200 字' })
  await cancelOrder(row.id, value || '')
  ElMessage.success('订单已取消')
  detail.value?.id === row.id ? refresh() : load()
}
// 确认收货
async function onReceive(row) {
  await ElMessageBox.confirm(`确认订单 ${row.order_no} 已收货？`, '确认收货', { type: 'warning' })
  await receiveOrder(row.id)
  ElMessage.success('已确认收货')
  detail.value?.id === row.id ? refresh() : load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-tabs :model-value="query.status === '' ? 'all' : String(query.status)" @tab-change="onTab">
        <el-tab-pane label="全部" name="all" />
        <el-tab-pane v-for="c in counts" :key="c.status" :name="String(c.status)">
          <template #label>{{ c.text }}<el-badge v-if="c.count && [0, 1, 5].includes(c.status)" :value="c.count" :max="99" style="margin-left: 4px" /></template>
        </el-tab-pane>
      </el-tabs>

      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.order_no" placeholder="订单号" clearable style="width: 200px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item><el-input v-model="query.keyword" placeholder="会员 / 收件人 / 手机" clearable style="width: 200px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.pay_status" placeholder="支付状态" clearable style="width: 120px" @change="search">
            <el-option label="未支付" :value="0" /><el-option label="已支付" :value="1" /><el-option label="部分退款" :value="2" /><el-option label="已退款" :value="3" />
          </el-select>
        </el-form-item>
        <el-form-item><el-date-picker v-model="query.range" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="下单开始" end-placeholder="下单结束" @change="search" /></el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="订单" min-width="300">
          <template #default="{ row }">
            <div style="display: flex; gap: 8px; align-items: center">
              <el-image v-if="row.first_image" :src="row.first_image" fit="cover" style="width: 44px; height: 44px; flex: none" />
              <div style="min-width: 0">
                <div style="font-weight: 600">{{ row.order_no }}</div>
                <div style="color: #606266; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ row.first_title }}<span v-if="row.item_count > 1"> 等 {{ row.item_count }} 种</span>　× {{ row.qty }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="会员 / 收件人" min-width="170">
          <template #default="{ row }"><div>{{ row.member }}</div><div style="color: #909399; font-size: 12px">{{ row.receiver }}</div></template>
        </el-table-column>
        <el-table-column label="应付" width="130"><template #default="{ row }"><b>{{ row.pay_amount }}</b> {{ row.currency }}</template></el-table-column>
        <el-table-column label="状态" width="130">
          <template #default="{ row }"><el-tag :type="statusType(row.status)">{{ row.status_text }}</el-tag><div style="font-size: 12px; color: #909399; margin-top: 2px">{{ row.pay_status_text }}<span v-if="row.pay_channel"> · {{ row.pay_channel }}</span></div></template>
        </el-table-column>
        <el-table-column prop="admin_remark" label="商家备注" min-width="140" show-overflow-tooltip />
        <el-table-column prop="created_at" label="下单时间" width="165" />
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'order:list:detail'" link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button v-perm="'order:list:remark'" link type="primary" @click="onRemark(row.id, row.admin_remark)">备注</el-button>
            <el-button v-if="row.status === 0" v-perm="'order:list:price'" link type="warning" @click="onPrice(row)">改价</el-button>
            <el-button v-if="row.status === 0" v-perm="'order:list:cancel'" link type="danger" @click="onCancel(row)">取消</el-button>
            <el-button v-if="row.status === 2" v-perm="'order:ship:edit'" link type="success" @click="onReceive(row)">确认收货</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50, 100]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-drawer v-model="drawer" :title="detail ? `订单 ${detail.order_no}` : '订单详情'" size="760px" destroy-on-close>
      <template v-if="detail">
        <div style="margin-bottom: 12px; display: flex; gap: 8px; flex-wrap: wrap">
          <el-button v-if="hasPerm('order:list:remark')" @click="onRemark(detail.id, detail.admin_remark)">备注</el-button>
          <el-button v-if="detail.status === 0 && hasPerm('order:list:price')" type="warning" plain @click="onPrice(detail)">改价</el-button>
          <el-button v-if="detail.status === 0 && hasPerm('order:list:cancel')" type="danger" plain @click="onCancel(detail)">取消订单</el-button>
          <el-button v-if="detail.status === 2 && hasPerm('order:ship:edit')" type="success" plain @click="onReceive(detail)">确认收货</el-button>
        </div>
        <el-alert v-if="detail.admin_remark" type="warning" :closable="false" :title="`商家备注：${detail.admin_remark}`" style="margin-bottom: 12px" />
        <OrderDetail :d="detail" />
      </template>
    </el-drawer>
  </div>
</template>
