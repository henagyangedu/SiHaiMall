<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { getOrders, cancelOrder, receiveOrder } from '@/api'
import { money, confirm } from '@/utils/app'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

// 页签与后端的 tab 参数一致
const TABS = [
  { key: 'all', label: 'order.tabAll' }, { key: 'unpaid', label: 'order.tabUnpaid' }, { key: 'to_ship', label: 'order.tabToShip' },
  { key: 'shipped', label: 'order.tabShipped' }, { key: 'finished', label: 'order.tabFinished' },
]
const tab = ref(TABS.some((x) => x.key === route.query.tab) ? String(route.query.tab) : 'all')
const list = ref([])
const page = reactive({ no: 1, size: 10, total: 0, loading: false })

async function load() {
  page.loading = true
  try {
    const d = await getOrders({ tab: tab.value, page: page.no, size: page.size })
    list.value = d.list
    page.total = d.total
  } finally {
    page.loading = false
  }
}
function pick(key) {
  tab.value = key
  page.no = 1
  load()
}
const detail = (o, pay = false) => router.push({ name: 'order-detail', params: { orderNo: o.order_no }, query: pay ? { pay: 1 } : {} })

async function onCancel(o) {
  if (!(await confirm(t('order.cancelConfirm')))) return
  await cancelOrder(o.order_no)
  ElMessage.success(t('order.cancelled'))
  load()
}
async function onReceive(o) {
  if (!(await confirm(t('order.receiveConfirm')))) return
  await receiveOrder(o.order_no)
  ElMessage.success(t('order.received'))
  load()
}

onMounted(load)
</script>

<template>
  <div class="panel">
    <div class="panel-title">{{ t('order.list') }}</div>
    <el-tabs :model-value="tab" @tab-change="pick">
      <el-tab-pane v-for="x in TABS" :key="x.key" :label="t(x.label)" :name="x.key" />
    </el-tabs>

    <div v-loading="page.loading" class="list">
      <div v-for="o in list" :key="o.order_no" class="order">
        <div class="head">
          <span class="sub">{{ o.created_at }}　{{ t('order.no') }} {{ o.order_no }}</span>
          <span class="status">{{ t(`orderStatus.${o.status}`) }}</span>
        </div>
        <div class="body">
          <div class="items">
            <div v-for="(i, k) in o.items" :key="k" class="item">
              <img :src="i.image" alt="" class="thumb" />
              <div class="info">
                <div class="ellipsis-2">{{ i.title }}</div>
                <div class="sub">{{ i.spec_text }}</div>
              </div>
              <div class="cell">{{ money(i.price, o.currency) }}<div class="sub">× {{ i.qty }}</div></div>
            </div>
          </div>
          <div class="side">
            <div>{{ t('order.payAmount') }} <span class="price">{{ money(o.pay_amount, o.currency) }}</span></div>
            <div class="ops">
              <el-button v-if="o.status === 0" type="primary" size="small" @click="detail(o, true)">{{ t('order.payNow') }}</el-button>
              <el-button v-if="o.status === 2" type="primary" size="small" @click="onReceive(o)">{{ t('order.receive') }}</el-button>
              <el-button v-if="o.status === 0" size="small" @click="onCancel(o)">{{ t('order.cancel') }}</el-button>
              <el-button link type="primary" size="small" @click="detail(o)">{{ t('pc.viewDetail') }}</el-button>
            </div>
          </div>
        </div>
      </div>
      <div v-if="!page.loading && !list.length" class="empty">{{ t('pc.noOrders') }}</div>
    </div>

    <div v-if="page.total > page.size" class="pager">
      <el-pagination v-model:current-page="page.no" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="load" />
    </div>
  </div>
</template>

<style scoped>
.list { min-height: 200px; }
.order { border: 1px solid var(--line); border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
.head { display: flex; justify-content: space-between; background: #fafafa; padding: 10px 16px; }
.status { color: var(--theme); }
.body { display: flex; }
.items { flex: 1; min-width: 0; padding: 4px 16px; }
.item { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--line); }
.item:last-child { border-bottom: none; }
.info { flex: 1; min-width: 0; }
.cell { width: 110px; text-align: right; flex: none; }
.side { width: 240px; flex: none; border-left: 1px solid var(--line); padding: 16px; text-align: center; display: flex; flex-direction: column; justify-content: center; gap: 12px; }
.ops { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; }
.ops .el-button { margin: 0; }
</style>
