<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { state, currencySymbol } from '@/store'
import { getCart, setCartQty, removeCart, clearInvalidCart } from '@/api'
import { toLogin } from '@/utils/request'
import { refreshCartCount, confirm } from '@/utils/app'

const { t } = useI18n()
const router = useRouter()

const items = ref([])
const checked = ref([]) // 选中的购物车行 ID
const loaded = ref(false)

async function load() {
  if (!state.token) { items.value = []; loaded.value = true; return }
  try {
    const d = await getCart({ auth: false })
    items.value = d.items
    // 只保留仍然可买的选中项
    const ok = d.items.filter((i) => i.valid && i.enough).map((i) => i.id)
    checked.value = checked.value.filter((id) => ok.includes(id))
    state.cartCount = d.count
  } finally {
    loaded.value = true
  }
}

const buyable = computed(() => items.value.filter((i) => i.valid && i.enough))
const invalid = computed(() => items.value.filter((i) => !i.valid))
const allChecked = computed({
  get: () => buyable.value.length > 0 && buyable.value.every((i) => checked.value.includes(i.id)),
  set: (v) => { checked.value = v ? buyable.value.map((i) => i.id) : [] },
})
// 合计只是给买家看的预估，实际金额以下单时服务端计算为准
const total = computed(() => items.value.filter((i) => checked.value.includes(i.id)).reduce((s, i) => s + Number(i.line_amount), 0).toFixed(2))

function toggle(item, on) {
  const i = checked.value.indexOf(item.id)
  if (on && i < 0) checked.value.push(item.id)
  if (!on && i >= 0) checked.value.splice(i, 1)
}
async function setQty(item, next) {
  if (!next || next === item.qty) return
  try {
    await setCartQty(item.id, next)
  } finally {
    load()
  }
}
async function remove(ids) {
  if (!ids.length) return ElMessage.warning(t('cart.chooseFirst'))
  if (!(await confirm(t('cart.removeConfirm')))) return
  await removeCart(ids)
  checked.value = checked.value.filter((id) => !ids.includes(id))
  await load()
  refreshCartCount()
}
async function clearInvalid() {
  await clearInvalidCart()
  load()
}
function goCheckout() {
  if (!checked.value.length) return ElMessage.warning(t('cart.chooseFirst'))
  router.push({ name: 'checkout', query: { cart_ids: checked.value.join(',') } })
}

onMounted(load)
</script>

<template>
  <div v-if="!state.token" class="panel empty">
    <div>{{ t('pc.loginTip') }}</div>
    <el-button type="primary" style="margin-top: 20px" @click="toLogin">{{ t('pc.loginOrRegister') }}</el-button>
  </div>
  <div v-else-if="loaded && !items.length" class="panel empty">
    <div>{{ t('cart.empty') }}</div>
    <el-button type="primary" style="margin-top: 20px" @click="router.push({ name: 'home' })">{{ t('cart.goShopping') }}</el-button>
  </div>

  <template v-else-if="loaded">
    <div class="panel">
      <div class="panel-title">{{ t('cart.title') }}</div>
      <table class="table">
        <thead>
          <tr>
            <th class="c-check"><el-checkbox v-model="allChecked" :disabled="!buyable.length" /></th>
            <th class="c-goods">{{ t('pc.goodsCol') }}</th>
            <th>{{ t('pc.priceCol') }}</th>
            <th>{{ t('pc.qtyCol') }}</th>
            <th>{{ t('pc.amountCol') }}</th>
            <th>{{ t('pc.actionCol') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="i in items" :key="i.id" :class="{ off: !i.valid }">
            <td class="c-check"><el-checkbox :model-value="checked.includes(i.id)" :disabled="!i.valid || !i.enough" @change="(v) => toggle(i, v)" /></td>
            <td class="c-goods">
              <div class="goods">
                <img :src="i.image" alt="" class="thumb" />
                <div class="body">
                  <router-link v-if="i.goods_id" :to="{ name: 'goods-detail', params: { id: i.goods_id } }" class="ellipsis-2 name">{{ i.title }}</router-link>
                  <div v-else class="ellipsis-2">{{ i.title }}</div>
                  <div class="sub">{{ i.spec_text }}</div>
                  <div v-if="!i.valid" class="sub">{{ t('cart.invalid') }}</div>
                  <div v-else-if="!i.enough" class="warn">{{ t('cart.onlyLeft', { n: i.stock }) }}</div>
                </div>
              </div>
            </td>
            <td>{{ currencySymbol() }}{{ i.price }}</td>
            <td>
              <el-input-number v-if="i.valid" :model-value="i.qty" :min="1" :max="Math.max(1, Math.min(i.stock, 999))" size="small" @change="(v) => setQty(i, v)" />
              <span v-else>{{ i.qty }}</span>
            </td>
            <td class="price">{{ currencySymbol() }}{{ i.line_amount }}</td>
            <td><a class="link" @click="remove([i.id])">{{ t('pc.remove') }}</a></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="panel foot">
      <div>
        <el-checkbox v-model="allChecked" :disabled="!buyable.length">{{ t('cart.selectAll') }}</el-checkbox>
        <a class="link op" @click="remove(checked)">{{ t('pc.remove') }}</a>
        <a v-if="invalid.length" class="link op" @click="clearInvalid">{{ t('cart.clearInvalid') }}</a>
      </div>
      <div class="sum">
        <span class="sub">{{ t('pc.selected', { n: checked.length }) }}　</span>{{ t('cart.total') }}
        <span class="price total">{{ currencySymbol() }}{{ total }}</span>
        <el-button type="primary" size="large" @click="goCheckout">{{ t('cart.checkout', { n: checked.length }) }}</el-button>
      </div>
    </div>
  </template>
</template>

<style scoped>
.table { width: 100%; border-collapse: collapse; }
.table th { text-align: left; font-weight: 500; color: var(--sub); padding: 10px 8px; border-bottom: 1px solid var(--line); }
.table td { padding: 16px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; }
.table tr.off { opacity: 0.55; }
.c-check { width: 44px; }
.c-goods { width: 46%; }
.goods { display: flex; gap: 12px; align-items: center; }
.body { min-width: 0; }
.name:hover { color: var(--theme); }
.warn { color: #e6a23c; font-size: 13px; }
.foot { display: flex; justify-content: space-between; align-items: center; }
.op { margin-left: 20px; }
.sum { display: flex; align-items: center; gap: 12px; }
.total { font-size: 22px; }
</style>
