<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { statsOverview } from '@/api/stats'
import { hasPerm } from '@/utils/perm'

const store = useUserStore()
const router = useRouter()
// 经营数据只给有“数据概览”权限的管理员看
const canSee = computed(() => hasPerm('data:overview:view'))
const overview = ref(null)

const money = (v) => Number(v).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const tiles = computed(() => {
  const t = overview.value?.periods?.today
  const y = overview.value?.periods?.yesterday
  if (!t) return []
  const cur = overview.value.base_currency
  return [
    { label: `今日支付金额（${cur}）`, value: money(t.paid_amount), prev: `昨日 ${money(y.paid_amount)}` },
    { label: '今日支付订单数', value: t.paid_orders, prev: `昨日 ${y.paid_orders}` },
    { label: `今日退款金额（${cur}）`, value: money(t.refund_amount), prev: `昨日 ${money(y.refund_amount)}` },
    { label: '今日新增会员', value: t.new_members, prev: `昨日 ${y.new_members}` },
  ]
})
const TODOS = [
  { key: 'to_ship', label: '待发货订单', path: '/order/ship' },
  { key: 'refund_pending', label: '待处理售后', path: '/order/refund' },
  { key: 'withdraw_pending', label: '待处理提现', path: '/distribution/withdraw' },
  { key: 'distributor_pending', label: '待审核分销员', path: '/distribution/distributor' },
  { key: 'low_stock', label: '库存预警规格', path: '/goods/list' },
]

onMounted(async () => {
  if (!canSee.value) return
  try { overview.value = await statsOverview() } catch (e) { /* 首页不因统计失败而报错 */ }
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <h3 style="margin: 0 0 6px">欢迎回来，{{ store.info?.nickname || store.info?.username }}</h3>
      <div class="sub">当前角色：{{ store.info?.role_name }}</div>
    </el-card>

    <el-card v-if="canSee && overview" shadow="never">
      <div class="tiles">
        <div v-for="t in tiles" :key="t.label" class="tile">
          <div class="tile-label">{{ t.label }}</div>
          <div class="tile-value">{{ t.value }}</div>
          <div class="sub">{{ t.prev }}</div>
        </div>
      </div>
      <div class="todos">
        <span class="sub">待办：</span>
        <el-button v-for="t in TODOS" :key="t.key" link :type="overview.todo[t.key] ? 'primary' : 'info'" @click="router.push(t.path)">
          {{ t.label }} <b style="margin-left: 4px">{{ overview.todo[t.key] }}</b>
        </el-button>
        <el-button link type="primary" style="margin-left: auto" @click="router.push('/data/overview')">查看数据概览</el-button>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.tile { background: #f7f8fa; border-radius: 8px; padding: 14px 16px; }
.tile-label { color: #606266; font-size: 13px; }
.tile-value { margin: 6px 0 4px; font-size: 24px; font-weight: 600; color: #303133; font-variant-numeric: tabular-nums; }
.todos { display: flex; align-items: center; flex-wrap: wrap; gap: 4px 8px; margin-top: 14px; }
</style>
