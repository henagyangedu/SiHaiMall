<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { checkout, createOrder, getAddresses } from '@/api'
import { money, refreshCartCount, addressLine } from '@/utils/app'
import AddressDialog from '@/components/AddressDialog.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

// 两种入口：购物车结算（cart_ids）或商品页立即购买（sku_id + qty）
const source = route.query.cart_ids
  ? { cart_ids: String(route.query.cart_ids).split(',').map(Number).filter(Boolean) }
  : { items: [{ sku_id: Number(route.query.sku_id), qty: Number(route.query.qty || 1) }] }

const addresses = ref([])
const addressId = ref(0)
const usePoints = ref(false)
const remark = ref('')
const data = ref(null)
const loading = ref(false)
const submitting = ref(false)
const dialog = ref(false)
const editing = ref(null)

const payload = () => ({ ...source, ...(addressId.value ? { address_id: addressId.value } : {}), use_points: usePoints.value })

// 金额、运费、区域价都由服务端按所选地址算好，这里只负责展示
async function load() {
  loading.value = true
  try {
    data.value = await checkout(payload())
    if (data.value.address && !addressId.value) addressId.value = data.value.address.id
  } finally {
    loading.value = false
  }
}
async function loadAddresses() {
  addresses.value = await getAddresses()
}
function pickAddress(id) {
  if (id === addressId.value) return
  addressId.value = id
  load()
}
function openAddress(a = null) {
  editing.value = a
  dialog.value = true
}
// 新增或修改地址后：选中它并重新计算（换地址可能改变运费和区域价）
async function onAddressSaved(id) {
  await loadAddresses()
  if (id) addressId.value = id
  load()
}

async function submit() {
  if (submitting.value || !data.value) return
  if (data.value.need_address && !addressId.value) return ElMessage.warning(t('checkout.needAddress'))
  if (data.value.problems.length) return ElMessage.warning(data.value.problems[0].msg)
  submitting.value = true
  try {
    const order = await createOrder({ ...payload(), remark: remark.value.trim() })
    refreshCartCount()
    router.replace({ name: 'order-detail', params: { orderNo: order.order_no }, query: { pay: 1 } })
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await loadAddresses().catch(() => {})
  load()
})
</script>

<template>
  <div v-loading="loading && !data" class="page">
    <template v-if="data">
      <div v-if="data.need_address" class="panel">
        <div class="panel-title">{{ t('pc.address') }}<el-button link type="primary" class="add" @click="openAddress()">＋ {{ t('pc.newAddress') }}</el-button></div>
        <div v-if="!addresses.length" class="sub">{{ t('pc.noAddress') }}</div>
        <div class="addrs">
          <div v-for="a in addresses" :key="a.id" class="addr" :class="{ on: a.id === addressId }" @click="pickAddress(a.id)">
            <div class="who">{{ a.name }}　<span class="sub">+{{ a.phone_area }} {{ a.phone }}</span><el-tag v-if="a.is_default" size="small" type="danger" effect="plain" class="tag">{{ t('pc.defaultTag') }}</el-tag></div>
            <div class="sub ellipsis-2">{{ addressLine(a) }}</div>
            <a class="link edit" @click.stop="openAddress(a)">{{ t('common.edit') }}</a>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-title">{{ t('pc.orderItems') }}</div>
        <div v-for="i in data.items" :key="i.sku_id" class="item">
          <img :src="i.image" alt="" class="thumb" />
          <div class="body">
            <div class="ellipsis-2">{{ i.title }}</div>
            <div class="sub">{{ i.spec_text }}</div>
          </div>
          <div class="cell">{{ money(i.price, data.currency) }}</div>
          <div class="cell sub">× {{ i.qty }}</div>
        </div>
        <div v-if="data.price_region" class="sub region">{{ t('checkout.region', { name: data.price_region.name }) }}</div>
        <el-alert v-for="(p, i) in data.problems" :key="i" :title="p.msg" type="warning" :closable="false" show-icon class="problem" />
      </div>

      <div class="panel bottom">
        <div class="left">
          <el-input v-model="remark" type="textarea" :rows="3" maxlength="500" show-word-limit :placeholder="t('checkout.remark')" />
        </div>
        <div class="right">
          <div class="kv"><span>{{ t('checkout.goods') }}</span><span>{{ money(data.goods_amount, data.currency) }}</span></div>
          <div class="kv"><span>{{ t('checkout.shipping') }}</span><span>{{ Number(data.shipping_fee) > 0 ? money(data.shipping_fee, data.currency) : t('checkout.free') }}</span></div>
          <div v-if="Number(data.discount_amount) > 0" class="kv"><span>{{ t('checkout.discount') }}</span><span class="price">-{{ money(data.discount_amount, data.currency) }}</span></div>
          <div v-if="data.points.enabled && data.points.usable_points > 0" class="kv">
            <el-checkbox v-model="usePoints" @change="load">{{ t('checkout.usePoints', { n: data.points.usable_points, amount: money(data.points.deduct_amount, data.currency) }) }}</el-checkbox>
            <span v-if="usePoints" class="price">-{{ money(data.points_amount, data.currency) }}</span>
          </div>
          <div v-if="data.earn_points > 0" class="sub earn">{{ t('checkout.earnPoints', { n: data.earn_points }) }}</div>
          <div class="pay">{{ t('checkout.pay') }} <span class="price total">{{ money(data.pay_amount, data.currency) }}</span></div>
          <el-button type="primary" size="large" class="go" :loading="submitting" @click="submit">{{ t('checkout.submit') }}</el-button>
        </div>
      </div>
    </template>

    <AddressDialog v-model="dialog" :address="editing" @saved="onAddressSaved" />
  </div>
</template>

<style scoped>
.page { min-height: 300px; }
.add { margin-left: 16px; font-weight: 400; }
.addrs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.addr { position: relative; border: 2px solid var(--line); border-radius: 8px; padding: 14px 16px; cursor: pointer; min-height: 92px; }
.addr:hover { border-color: var(--el-color-primary-light-5); }
.addr.on { border-color: var(--theme); background: var(--el-color-primary-light-9); }
.who { font-weight: 600; margin-bottom: 6px; }
.tag { margin-left: 8px; }
.edit { position: absolute; right: 14px; bottom: 10px; font-size: 13px; display: none; }
.addr:hover .edit { display: block; }
.item { display: flex; align-items: center; gap: 14px; padding: 12px 0; border-bottom: 1px solid var(--line); }
.item:last-of-type { border-bottom: none; }
.body { flex: 1; min-width: 0; }
.cell { width: 120px; text-align: right; flex: none; }
.region { margin-top: 8px; }
.problem { margin-top: 10px; }
.bottom { display: flex; gap: 40px; }
.left { flex: 1; }
.right { width: 360px; flex: none; }
.earn { text-align: right; padding: 4px 0; }
.pay { text-align: right; margin: 12px 0; }
.total { font-size: 26px; }
.go { width: 100%; }
</style>
