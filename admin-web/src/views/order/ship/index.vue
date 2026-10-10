<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { waybillOptions } from '@/api/waybill'
import { listShipOrder, getOrder, expressOptions, shipOrder, editExpress } from '@/api/order'
import { hasPerm } from '@/utils/perm'
import OrderDetail from '../OrderDetail.vue'
import { printOrderReceipt } from '@/utils/receipt'

const query = reactive({ order_no: '', keyword: '', status: 1, page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)
const express = ref([])

async function load() {
  loading.value = true
  try {
    const d = await listShipOrder({ ...query })
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

// ---- 发货：手填快递单号，或者（配置了电子面单时）由系统取号
const shipDialog = ref(false)
const order = ref(null)
const shipping = ref(false)
// 电子面单能不能用、哪些快递配了账号
const waybill = ref({ enabled: false, expresses: [] })
const form = reactive({ mode: 'manual', express_code: 'SF', express_name: '', express_no: '', item_ids: [], weight: undefined })
function setMode(mode) {
  form.mode = mode
  form.express_code = mode === 'waybill' ? (waybill.value.expresses[0]?.code || '') : 'SF'
}
async function openShip(row) {
  order.value = await getOrder(row.id)
  const pending = order.value.items.filter((i) => !i.ship_status).map((i) => i.id)
  Object.assign(form, { mode: 'manual', express_code: 'SF', express_name: '', express_no: '', item_ids: pending, weight: undefined })
  shipDialog.value = true
}
async function submitShip() {
  if (!form.item_ids.length) return ElMessage.error('请选择本次发货的商品')
  if (form.mode === 'waybill') return submitWaybill()
  if (!form.express_no.trim()) return ElMessage.error('请填写快递单号')
  if (form.express_code === 'OTHER' && !form.express_name.trim()) return ElMessage.error('请填写快递公司名称')
  await shipOrder(order.value.id, { express_code: form.express_code, express_name: form.express_name, express_no: form.express_no, item_ids: form.item_ids })
  ElMessage.success('发货成功')
  shipDialog.value = false
  load()
}
// 电子面单：向服务商取号要几秒钟，按钮转圈、不能重复点；成功后问要不要马上打印面单
async function submitWaybill() {
  if (!form.express_code) return ElMessage.error('请选择快递公司')
  shipping.value = true
  try {
    const r = await shipOrder(order.value.id, { waybill: 1, express_code: form.express_code, item_ids: form.item_ids, weight: form.weight || undefined })
    shipDialog.value = false
    load()
    if (!r.label_url) return ElMessage.success(`发货成功，快递单号 ${r.express_no}`)
    try {
      await ElMessageBox.confirm(`已取到快递单号 ${r.express_no} 并完成发货。现在打印面单吗？`, '发货成功', { type: 'success', confirmButtonText: '打印面单', cancelButtonText: '稍后再打' })
      window.open(r.label_url, '_blank', 'noopener')
    } catch (e) { /* 稍后在订单详情的物流里打印 */ }
  } finally {
    shipping.value = false
  }
}

// ---- 修改单号
const editDialog = ref(false)
const eform = reactive({ id: 0, express_code: 'SF', express_name: '', express_no: '' })
async function openEdit(row) {
  const d = await getOrder(row.id)
  const s = d.shippings.at(-1)
  if (!s) return ElMessage.warning('该订单还没有发货记录')
  Object.assign(eform, { id: s.id, express_code: s.express_code, express_name: s.express_name, express_no: s.express_no })
  editDialog.value = true
}
async function submitEdit() {
  if (!eform.express_no.trim()) return ElMessage.error('请填写快递单号')
  await editExpress(eform.id, { express_code: eform.express_code, express_name: eform.express_name, express_no: eform.express_no })
  ElMessage.success('已修改')
  editDialog.value = false
}

// ---- 详情
const drawer = ref(false)
const detail = ref(null)
// 打印小票：列表里的数据不全，先取订单详情
async function onPrint(row) {
  printOrderReceipt(await getOrder(row.id))
}
async function openDetail(row) {
  detail.value = await getOrder(row.id)
  drawer.value = true
}

onMounted(async () => {
  load()
  try { express.value = await expressOptions() } catch (e) { /* 无权限 */ }
  if (hasPerm('order:ship:do')) waybillOptions().then((d) => { waybill.value = d }).catch(() => {})
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-tabs :model-value="String(query.status)" @tab-change="(n) => { query.status = Number(n); search() }">
        <el-tab-pane label="待发货" name="1" />
        <el-tab-pane label="已发货（待收货）" name="2" />
      </el-tabs>
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.order_no" placeholder="订单号" clearable style="width: 200px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item><el-input v-model="query.keyword" placeholder="会员 / 收件人 / 手机" clearable style="width: 200px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="订单" min-width="300">
          <template #default="{ row }">
            <div style="display: flex; gap: 8px; align-items: center">
              <el-image v-if="row.first_image" :src="row.first_image" fit="cover" style="width: 44px; height: 44px; flex: none" />
              <div><div style="font-weight: 600">{{ row.order_no }}</div><div style="color: #606266; font-size: 12px">{{ row.first_title }}<span v-if="row.item_count > 1"> 等 {{ row.item_count }} 种</span>　× {{ row.qty }}</div></div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="receiver" label="收件人" min-width="170" />
        <el-table-column label="未发货商品行" width="110"><template #default="{ row }"><el-tag v-if="row.unshipped" type="warning">{{ row.unshipped }} 行</el-tag><el-tag v-else type="success">已全部发出</el-tag></template></el-table-column>
        <el-table-column prop="admin_remark" label="商家备注" min-width="140" show-overflow-tooltip />
        <el-table-column prop="paid_at" label="支付时间" width="165" />
        <el-table-column label="操作" width="270" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'order:list:detail,order:ship:view'" link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button v-if="row.status === 1 && row.unshipped" v-perm="'order:ship:do'" link type="success" @click="openShip(row)">发货</el-button>
            <el-button v-if="row.status === 2 || row.unshipped === 0" v-perm="'order:ship:edit'" link type="warning" @click="openEdit(row)">改单号</el-button>
            <el-button v-perm="'order:ship:print'" link @click="onPrint(row)">打印小票</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="shipDialog" title="发货" width="620px" destroy-on-close>
      <template v-if="order">
        <el-descriptions :column="1" border size="small" style="margin-bottom: 12px">
          <el-descriptions-item label="订单号">{{ order.order_no }}</el-descriptions-item>
          <el-descriptions-item label="收件信息">{{ order.address ? `${order.address.name} +${order.address.phone_area} ${order.address.phone}　${[order.address.country_code, order.address.province, order.address.city, order.address.district, order.address.address].filter(Boolean).join(' ')}` : '-' }}</el-descriptions-item>
        </el-descriptions>
        <el-form :model="form" label-width="90px">
          <el-form-item label="发货商品" required>
            <el-checkbox-group v-model="form.item_ids" style="display: flex; flex-direction: column">
              <el-checkbox v-for="i in order.items" :key="i.id" :value="i.id" :disabled="!!i.ship_status">{{ i.title }}<span v-if="i.spec_text">（{{ i.spec_text }}）</span> × {{ i.qty }}<el-tag v-if="i.ship_status" size="small" type="success" style="margin-left: 6px">已发</el-tag></el-checkbox>
            </el-checkbox-group>
          </el-form-item>
          <el-form-item v-if="waybill.enabled" label="发货方式">
            <el-radio-group :model-value="form.mode" @update:model-value="setMode">
              <el-radio value="manual">手填快递单号</el-radio>
              <el-radio value="waybill">电子面单取号</el-radio>
            </el-radio-group>
          </el-form-item>
          <template v-if="form.mode === 'waybill'">
            <el-form-item label="快递公司" required>
              <el-select v-model="form.express_code" style="width: 200px" placeholder="还没有配置快递账号"><el-option v-for="e in waybill.expresses" :key="e.code" :label="e.name" :value="e.code" /></el-select>
              <span class="tip">只列出在“设置 → API 配置 → 电子面单”里配了账号的快递</span>
            </el-form-item>
            <el-form-item label="重量">
              <el-input-number v-model="form.weight" :min="0.01" :max="1000" :precision="2" :controls="false" placeholder="按商品重量" />
              <span class="tip">千克。不填就按所选商品的重量之和（没有填重量的按 1 千克）</span>
            </el-form-item>
          </template>
          <template v-else>
            <el-form-item label="快递公司" required>
              <el-select v-model="form.express_code" filterable style="width: 200px"><el-option v-for="e in express" :key="e.code" :label="e.name" :value="e.code" /></el-select>
              <el-input v-if="form.express_code === 'OTHER'" v-model="form.express_name" placeholder="快递公司名称" maxlength="60" style="width: 200px; margin-left: 8px" />
            </el-form-item>
            <el-form-item label="快递单号" required><el-input v-model="form.express_no" maxlength="60" placeholder="快递单号" /></el-form-item>
          </template>
        </el-form>
        <div style="color: #909399; font-size: 12px">只勾选部分商品即为部分发货；全部发出后订单自动进入「待收货」。</div>
      </template>
      <template #footer><el-button @click="shipDialog = false">取消</el-button><el-button type="primary" :loading="shipping" @click="submitShip">{{ form.mode === 'waybill' ? '取号并发货' : '确认发货' }}</el-button></template>
    </el-dialog>

    <el-dialog v-model="editDialog" title="修改物流单号（最近一次发货）" width="500px" destroy-on-close>
      <el-form :model="eform" label-width="90px">
        <el-form-item label="快递公司">
          <el-select v-model="eform.express_code" filterable style="width: 200px"><el-option v-for="e in express" :key="e.code" :label="e.name" :value="e.code" /></el-select>
          <el-input v-if="eform.express_code === 'OTHER'" v-model="eform.express_name" placeholder="快递公司名称" maxlength="60" style="width: 180px; margin-left: 8px" />
        </el-form-item>
        <el-form-item label="快递单号"><el-input v-model="eform.express_no" maxlength="60" /></el-form-item>
      </el-form>
      <template #footer><el-button @click="editDialog = false">取消</el-button><el-button type="primary" @click="submitEdit">保存</el-button></template>
    </el-dialog>

    <el-drawer v-model="drawer" :title="detail ? `订单 ${detail.order_no}` : '订单详情'" size="760px" destroy-on-close>
      <OrderDetail v-if="detail" :d="detail" />
    </el-drawer>
  </div>
</template>

<style scoped>
.tip { margin-left: 10px; color: #909399; font-size: 12px; }
</style>
