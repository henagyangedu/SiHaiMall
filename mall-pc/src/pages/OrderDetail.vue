<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue'
import QRCode from 'qrcode'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { getOrder, getOrderTracks, cancelOrder, receiveOrder, getPayChannels, startPay, confirmPay } from '@/api'
import { money, confirm, addressLine } from '@/utils/app'
import ReviewDialog from '@/components/ReviewDialog.vue'
import InvoicePanel from '@/components/InvoicePanel.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const orderNo = String(route.params.orderNo)

const order = ref(null)
// 正在评价的商品行；null 表示评价弹窗关着
const reviewing = ref(null)
// 弹窗是写评价还是追评
const appending = ref(false)
function openReview(item, append) {
  appending.value = append
  reviewing.value = item
}
async function onReviewed() {
  reviewing.value = null
  order.value = await getOrder(orderNo)
}
// 发起支付后把支付单号记在本地：买家从收银台回来时用它向后端确认是否到账
const tradeKey = `gm_trade_${orderNo}`
const savedTrade = () => { try { return localStorage.getItem(tradeKey) || '' } catch (e) { return '' } }
const forgetTrade = () => { try { localStorage.removeItem(tradeKey) } catch (e) { /* 忽略 */ } }

/** 向后端确认之前发起的那笔支付，返回 success / pending / 其它；没有待确认的支付返回空串 */
async function checkTrade() {
  const tradeNo = savedTrade()
  if (!tradeNo) return ''
  try {
    const r = await confirmPay(tradeNo)
    if (r.status !== 'pending') forgetTrade()
    return r.status
  } catch (e) {
    return '' // 确认失败不影响看订单
  }
}
async function load() {
  const status = await checkTrade()
  if (status === 'success') ElMessage.success(t('order.paidSuccess'))
  order.value = await getOrder(orderNo)
  if (!order.value.can_pay) forgetTrade()
}

// ---- 支付：选渠道 → 跳到渠道收银台 → 回到本页后确认
const payDialog = ref(false)
const channels = ref([])
const paying = ref('')
async function openPay() {
  channels.value = await getPayChannels(orderNo)
  payDialog.value = true
}
async function pay(channel) {
  if (paying.value) return
  paying.value = channel
  try {
    // 回跳地址去掉 pay=1，免得付完回来又弹出支付方式
    const back = window.location.href.replace(/([?&])pay=1(&|$)/, (m, a, b) => (b ? a : ''))
    const r = await startPay(orderNo, { channel, return_url: back })
    try { localStorage.setItem(tradeKey, r.trade_no) } catch (e) { /* 忽略 */ }
    if (r.redirect_url) {
      window.location.href = r.redirect_url
    } else if (r.client_params?.code_url) {
      // 扫码支付（微信）：把渠道给的内容画成二维码，买家用手机扫
      payDialog.value = false
      paying.value = ''
      await openScan(r.client_params.code_url)
    } else {
      paying.value = ''
    }
  } catch (e) {
    paying.value = ''
  }
}
// ---- 扫码支付：显示二维码，每 3 秒问一次后端有没有到账；关掉弹窗就停止
const scan = reactive({ show: false, image: '' })
let scanTimer = null
async function openScan(codeUrl) {
  scan.image = await QRCode.toDataURL(codeUrl, { width: 240, margin: 1 })
  scan.show = true
  clearInterval(scanTimer)
  scanTimer = setInterval(async () => {
    const status = await checkTrade()
    // 还没付，或这一次查询没成功（网络抖动）：继续等
    if (status === 'pending' || status === '') return
    closeScan()
    if (status === 'success') ElMessage.success(t('order.paidSuccess'))
    load()
  }, 3000)
}
function closeScan() {
  clearInterval(scanTimer)
  scanTimer = null
  scan.show = false
}
onUnmounted(() => clearInterval(scanTimer))

// 买家在另一个窗口付完款后，手动点“我已完成支付”
async function onPaidCheck() {
  const status = await checkTrade()
  if (status === 'pending' || status === '') ElMessage.info(t(status === 'pending' ? 'order.payPending' : 'pc.payCheckPending'))
  payDialog.value = false
  load()
}

async function onCancel() {
  if (!(await confirm(t('order.cancelConfirm')))) return
  await cancelOrder(orderNo)
  ElMessage.success(t('order.cancelled'))
  load()
}
async function onReceive() {
  if (!(await confirm(t('order.receiveConfirm')))) return
  await receiveOrder(orderNo)
  ElMessage.success(t('order.received'))
  load()
}

// ---- 物流轨迹：点“查看物流”才加载（按包裹顺序，和 order.shippings 一一对应）
const tracks = ref(null)
const tracksLoading = ref(false)
async function loadTracks() {
  if (tracksLoading.value) return
  tracksLoading.value = true
  try {
    tracks.value = await getOrderTracks(orderNo)
  } catch (e) {
    ElMessage.error(t('order.trackFailed'))
  } finally {
    tracksLoading.value = false
  }
}

onMounted(async () => {
  await load()
  // 从结算页或订单列表点“去支付”进来（地址带 pay=1）：直接弹出支付方式。
  // 不能在这里改地址去掉 pay=1——App.vue 按地址重建页面，弹窗会开在被销毁的旧页面上；
  // 发起支付时的回跳地址已经去掉了这个参数
  if (route.query.pay && order.value?.can_pay) openPay()
})
</script>

<template>
  <template v-if="order">
    <div class="panel status">
      <div>
        <div class="st">{{ t(`orderStatus.${order.status}`) }}</div>
        <div v-if="order.can_pay && order.expire_at" class="sub">{{ t('order.expireAt', { time: order.expire_at }) }}</div>
        <div v-if="order.cancel_reason" class="sub">{{ order.cancel_reason }}</div>
      </div>
      <div class="ops">
        <el-button v-if="order.can_pay" type="primary" size="large" @click="openPay">{{ t('order.payNow') }}</el-button>
        <el-button v-if="order.can_receive" type="primary" size="large" @click="onReceive">{{ t('order.receive') }}</el-button>
        <el-button v-if="order.can_cancel" size="large" @click="onCancel">{{ t('order.cancel') }}</el-button>
        <!-- 能不能申请、能退多少由售后申请页向后端查询，这里只给入口 -->
        <el-button v-if="order.can_refund" size="large" @click="router.push({ name: 'refund-apply', params: { orderNo } })">{{ t('refund.apply') }}</el-button>
      </div>
    </div>

    <div class="cols">
      <div class="panel col">
        <div class="panel-title">{{ t('pc.orderInfo') }}</div>
        <div class="kv"><span class="sub">{{ t('order.no') }}</span><span>{{ order.order_no }}</span></div>
        <div class="kv"><span class="sub">{{ t('order.createdAt') }}</span><span>{{ order.created_at }}</span></div>
        <div v-if="order.paid_at" class="kv"><span class="sub">{{ t('pc.paidAt') }}</span><span>{{ order.paid_at }}</span></div>
        <div v-if="order.pay_channel" class="kv"><span class="sub">{{ t('pc.payChannel') }}</span><span>{{ order.pay_channel }}</span></div>
        <div v-if="order.remark" class="kv"><span class="sub">{{ t('pc.remark') }}</span><span class="val">{{ order.remark }}</span></div>
      </div>
      <div v-if="order.address" class="panel col">
        <div class="panel-title">{{ t('order.address') }}</div>
        <div>{{ order.address.name }}　+{{ order.address.phone_area }} {{ order.address.phone }}</div>
        <div class="sub line">{{ addressLine(order.address) }}</div>
      </div>
    </div>

    <div v-if="order.shippings.length" class="panel">
      <div class="panel-title">{{ t('order.express') }}<el-button v-if="!tracks" link type="primary" class="more" :loading="tracksLoading" @click="loadTracks">{{ t('order.viewTrack') }}</el-button></div>
      <div v-for="(s, i) in order.shippings" :key="i" class="parcel">
        <div>{{ s.express_name }}　{{ s.express_no }}<el-tag v-if="tracks && tracks[i]" size="small" effect="plain" class="tstatus">{{ t(`trackStatus.${tracks[i].status}`) }}</el-tag></div>
        <template v-if="tracks && tracks[i]">
          <div v-if="tracks[i].message" class="sub line">{{ tracks[i].message }}</div>
          <el-timeline v-if="tracks[i].traces.length" class="traces">
            <el-timeline-item v-for="(x, k) in tracks[i].traces" :key="k" :timestamp="x.time" :type="k === 0 ? 'primary' : ''">{{ x.context }}</el-timeline-item>
          </el-timeline>
        </template>
      </div>
    </div>

    <div class="panel">
      <div class="panel-title">{{ t('pc.orderItems') }}</div>
      <div v-for="(i, k) in order.items" :key="k" class="item">
        <img :src="i.image" alt="" class="thumb" />
        <div class="body">
          <router-link :to="{ name: 'goods-detail', params: { id: i.goods_id } }" class="ellipsis-2 name">{{ i.title }}</router-link>
          <div class="sub">{{ i.spec_text }}</div>
        </div>
        <div class="cell">{{ money(i.price, order.currency) }}</div>
        <div class="cell sub">× {{ i.qty }}</div>
        <div class="cell rv">
          <el-button v-if="i.can_review" size="small" type="primary" plain @click="openReview(i, false)">{{ t('review.toReview') }}</el-button>
          <el-button v-else-if="i.can_append" size="small" plain class="append-btn" @click="openReview(i, true)">{{ t('review.appendBtn') }}</el-button>
          <span v-else-if="i.reviewed" class="sub">{{ t('review.reviewed') }}</span>
        </div>
      </div>
      <div class="sum">
        <div class="kv"><span class="sub">{{ t('checkout.goods') }}</span><span>{{ money(order.goods_amount, order.currency) }}</span></div>
        <div class="kv"><span class="sub">{{ t('checkout.shipping') }}</span><span>{{ Number(order.shipping_fee) > 0 ? money(order.shipping_fee, order.currency) : t('checkout.free') }}</span></div>
        <div v-if="Number(order.discount_amount) > 0" class="kv"><span class="sub">{{ t('checkout.discount') }}</span><span>-{{ money(order.discount_amount, order.currency) }}</span></div>
        <div v-if="Number(order.coupon_amount) > 0" class="kv"><span class="sub">{{ t('coupon.row') }}</span><span>-{{ money(order.coupon_amount, order.currency) }}</span></div>
        <div v-if="Number(order.points_amount) > 0" class="kv"><span class="sub">{{ t('checkout.points') }}</span><span>-{{ money(order.points_amount, order.currency) }}</span></div>
        <div class="kv"><span>{{ t('order.payAmount') }}</span><span class="price total">{{ money(order.pay_amount, order.currency) }}</span></div>
      </div>
    </div>

    <!-- 发票：能申请或已经申请过才显示（组件自己判断） -->
    <InvoicePanel v-if="order.invoice && (order.invoice.can_apply || order.invoice.status !== null)" :order-no="orderNo" />

    <el-dialog v-model="payDialog" :title="t('pc.payTitle')" width="440px">
      <div v-if="!channels.length" class="sub">{{ t('order.noChannel') }}</div>
      <el-button v-for="c in channels" :key="c.channel" class="channel" size="large" :loading="paying === c.channel" :disabled="!!paying && paying !== c.channel" @click="pay(c.channel)">{{ c.name }}</el-button>
      <div v-if="paying" class="sub tip">{{ t('pc.payingTip') }}</div>
      <template #footer>
        <el-button @click="payDialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" plain @click="onPaidCheck">{{ t('pc.payCheck') }}</el-button>
      </template>
    </el-dialog>

    <el-dialog :model-value="scan.show" :title="t('pc.scanTitle')" width="360px" :close-on-click-modal="false" @update:model-value="(v) => !v && closeScan()">
      <div class="scan">
        <img :src="scan.image" alt="" class="qr" />
        <div>{{ t('pc.scanTip') }}</div>
        <div class="price scan-amount">{{ money(order.pay_amount, order.currency) }}</div>
        <div class="sub">{{ t('pc.scanWaiting') }}</div>
      </div>
    </el-dialog>
  </template>
  <ReviewDialog :order-no="orderNo" :item="reviewing" :append="appending" @close="reviewing = null" @done="onReviewed" />
</template>

<style scoped>
.status { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.st { font-size: 22px; font-weight: 600; color: var(--theme); margin-bottom: 6px; }
.cols { display: flex; gap: 16px; margin-bottom: 16px; }
.col { flex: 1; margin-top: 0 !important; }
.val { max-width: 70%; text-align: right; word-break: break-all; }
.line { margin-top: 6px; }
.more { margin-left: 16px; font-weight: 400; }
.parcel + .parcel { border-top: 1px solid var(--line); margin-top: 14px; padding-top: 14px; }
.tstatus { margin-left: 10px; }
.traces { margin-top: 14px; padding-left: 4px; }
.item { display: flex; align-items: center; gap: 14px; padding: 12px 0; border-bottom: 1px solid var(--line); }
.body { flex: 1; min-width: 0; }
.name:hover { color: var(--theme); }
.cell { width: 120px; text-align: right; flex: none; }
.sum { width: 320px; margin: 14px 0 0 auto; }
.total { font-size: 22px; }
.channel { display: block; width: 100%; margin: 0 0 12px !important; }
.tip { text-align: center; }
.scan { text-align: center; line-height: 1.9; }
.qr { width: 240px; height: 240px; display: block; margin: 0 auto 12px; }
.scan-amount { font-size: 22px; }
</style>
