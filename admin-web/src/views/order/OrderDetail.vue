<script setup>
// 订单详情内容（抽屉里用）：基本信息、商品、收货信息、物流、支付流水、状态日志
import { ref } from 'vue'
import { shippingTrack } from '@/api/order'

defineProps({ d: { type: Object, required: true } })

// 物流轨迹：点开才查；“刷新”会重新向服务商查询（后端限制同一个包裹 5 分钟最多查一次）
const track = ref(null)
const trackShow = ref(false)
const trackLoading = ref(false)
const TRACK_STATUS = { 0: ['暂无轨迹', 'info'], 1: ['运输中', 'primary'], 2: ['已签收', 'success'], 3: ['异常', 'danger'] }
async function openTrack(row, refresh = false) {
  trackShow.value = true
  trackLoading.value = true
  try {
    track.value = await shippingTrack(row.id, refresh)
  } catch (e) {
    if (!refresh) trackShow.value = false
  } finally {
    trackLoading.value = false
  }
}
const statusType = (s) => ({ 0: 'warning', 1: 'primary', 2: 'primary', 3: 'success', 4: 'info', 5: 'danger', 6: 'info' })[s] || 'info'
</script>

<template>
  <el-descriptions :column="2" border>
    <el-descriptions-item label="订单号">{{ d.order_no }}</el-descriptions-item>
    <el-descriptions-item label="状态"><el-tag :type="statusType(d.status)">{{ d.status_text }}</el-tag> <el-tag size="small" :type="d.pay_status === 1 ? 'success' : 'info'">{{ d.pay_status_text }}</el-tag></el-descriptions-item>
    <el-descriptions-item label="会员">{{ d.member ? `${d.member.name || '-'} (#${d.member.id})` : '-' }}</el-descriptions-item>
    <el-descriptions-item label="联系方式">{{ d.member?.phone || d.member?.email || '-' }}</el-descriptions-item>
    <el-descriptions-item label="下单币种">{{ d.currency }}（汇率 {{ d.rate }}）</el-descriptions-item>
    <el-descriptions-item label="来源 / 语言">{{ d.source || '-' }} / {{ d.lang }}</el-descriptions-item>
    <el-descriptions-item label="支付渠道">{{ d.pay_channel || '-' }}</el-descriptions-item>
    <el-descriptions-item label="下单时间">{{ d.created_at }}</el-descriptions-item>
    <el-descriptions-item label="支付时间">{{ d.paid_at || '-' }}</el-descriptions-item>
    <el-descriptions-item :label="d.status === 0 ? '支付截止' : '发货 / 完成'">{{ d.status === 0 ? d.expire_at : (d.shipped_at || '-') + ' / ' + (d.finished_at || '-') }}</el-descriptions-item>
    <el-descriptions-item v-if="d.remark" label="买家留言" :span="2">{{ d.remark }}</el-descriptions-item>
    <el-descriptions-item v-if="d.cancel_reason" label="取消/关闭原因" :span="2">{{ d.cancel_reason }}</el-descriptions-item>
  </el-descriptions>

  <div class="sec">商品</div>
  <el-table :data="d.items" border size="small">
    <el-table-column label="商品" min-width="260">
      <template #default="{ row }">
        <div style="display: flex; gap: 8px; align-items: center">
          <el-image v-if="row.image" :src="row.image" fit="cover" style="width: 44px; height: 44px; flex: none" />
          <div><div>{{ row.title }}</div><div v-if="row.spec_text" style="color: #909399; font-size: 12px">{{ row.spec_text }}</div></div>
        </div>
      </template>
    </el-table-column>
    <el-table-column prop="price" label="单价" width="100" />
    <el-table-column prop="qty" label="数量" width="70" />
    <el-table-column prop="subtotal" label="小计" width="100" />
    <el-table-column label="发货" width="80"><template #default="{ row }"><el-tag size="small" :type="row.ship_status ? 'success' : 'info'">{{ row.ship_status ? '已发' : '未发' }}</el-tag></template></el-table-column>
  </el-table>
  <div class="sum">
    <div>商品金额：{{ d.goods_amount }}<span v-if="d.price_region_name" style="color: #909399; font-size: 12px">（按销售区域“{{ d.price_region_name }}”定价）</span></div>
    <div>运费：{{ d.shipping_fee }}</div>
    <div v-if="Number(d.bundle_amount) > 0">套餐优惠：-{{ d.bundle_amount }}<span style="color: #909399; font-size: 12px">（{{ (d.bundles || []).map((b) => `${b.name} ×${b.sets}`).join('、') }}）</span></div>
    <div>优惠：-{{ d.discount_amount }}</div>
    <div v-if="Number(d.coupon_amount) > 0">优惠券：-{{ d.coupon_amount }}</div>
    <div v-if="Number(d.points_amount) > 0">积分抵扣：-{{ d.points_amount }}</div>
    <div class="total">应付：{{ d.pay_amount }} {{ d.currency }} <span style="color: #909399; font-size: 12px">（折合基准币种 {{ d.base_pay_amount }}）</span></div>
  </div>

  <template v-if="d.address">
    <div class="sec">收货信息</div>
    <el-descriptions :column="1" border size="small">
      <el-descriptions-item label="收件人">{{ d.address.name }}　+{{ d.address.phone_area }} {{ d.address.phone }}</el-descriptions-item>
      <el-descriptions-item label="地址">{{ [d.address.country_code, d.address.province, d.address.city, d.address.district, d.address.address, d.address.address2].filter(Boolean).join(' ') }}　{{ d.address.postcode }}</el-descriptions-item>
    </el-descriptions>
  </template>

  <template v-if="d.shippings.length">
    <div class="sec">物流</div>
    <el-table :data="d.shippings" border size="small">
      <el-table-column prop="express_name" label="快递公司" width="140" />
      <el-table-column prop="express_no" label="单号" min-width="160" />
      <el-table-column prop="shipped_at" label="发货时间" width="165" />
      <el-table-column label="轨迹" width="100"><template #default="{ row }"><el-button link type="primary" @click="openTrack(row)">查看物流</el-button></template></el-table-column>
      <!-- 电子面单发的货：面单图片在服务商那边，有有效期 -->
      <el-table-column v-if="d.shippings.some((s) => s.label_url)" label="面单" width="100">
        <template #default="{ row }"><a v-if="row.label_url" :href="row.label_url" target="_blank" rel="noopener" style="color: var(--el-color-primary)">打印面单</a></template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="trackShow" :title="track ? `${track.express_name} ${track.express_no}` : '物流轨迹'" width="560px" append-to-body>
      <div v-loading="trackLoading" style="min-height: 100px">
        <template v-if="track">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px">
            <el-tag :type="TRACK_STATUS[track.status]?.[1] || 'info'">{{ TRACK_STATUS[track.status]?.[0] || '未知' }}</el-tag>
            <span v-if="track.queried_at" style="color: #909399; font-size: 12px">更新于 {{ track.queried_at }}</span>
            <el-button v-if="track.status !== 2" link type="primary" style="margin-left: auto" @click="openTrack(track, true)">刷新</el-button>
          </div>
          <el-alert v-if="track.message" type="info" :closable="false" :title="track.message" style="margin-bottom: 12px" />
          <el-timeline v-if="track.traces.length">
            <el-timeline-item v-for="(x, i) in track.traces" :key="i" :timestamp="x.time" placement="top" :type="i === 0 ? 'primary' : ''">{{ x.context }}</el-timeline-item>
          </el-timeline>
        </template>
      </div>
    </el-dialog>
  </template>

  <template v-if="d.trades.length">
    <div class="sec">支付流水</div>
    <el-table :data="d.trades" border size="small">
      <el-table-column prop="trade_no" label="支付单号" min-width="170" />
      <el-table-column prop="channel" label="渠道" width="90" />
      <el-table-column prop="trade_type" label="类型" width="70" />
      <el-table-column label="金额" width="120"><template #default="{ row }">{{ row.amount }} {{ row.currency }}</template></el-table-column>
      <el-table-column prop="status" label="状态" width="80" />
      <el-table-column prop="channel_trade_no" label="渠道交易号" min-width="160" show-overflow-tooltip />
    </el-table>
  </template>

  <div class="sec">操作日志</div>
  <el-timeline>
    <el-timeline-item v-for="(l, i) in d.logs" :key="i" :timestamp="l.created_at" placement="top">
      <b>{{ l.to }}</b><span style="color: #909399"> · {{ l.operator }}</span><div v-if="l.remark">{{ l.remark }}</div>
    </el-timeline-item>
  </el-timeline>
</template>

<style scoped>
.sec { margin: 18px 0 8px; font-weight: 600; }
.sum { text-align: right; margin-top: 8px; line-height: 1.9; }
.total { font-size: 16px; font-weight: 600; color: #f56c6c; }
</style>
