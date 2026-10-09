<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { statsOverview, statsTrend, statsGoodsRank, statsBreakdown } from '@/api/stats'
import TrendChart from '@/components/TrendChart.vue'

const router = useRouter()
const loading = ref(false)
const overview = ref(null)
const days = ref(30)
const trend = ref([])
const rank = ref([])
const breakdown = ref({ source: [], currency: [], country: [], region: [] })
const showTable = ref(false)

const cur = computed(() => overview.value?.base_currency || '')
const money = (v) => Number(v).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const short = (v) => (v >= 10000 ? `${+(v / 10000).toFixed(1)}万` : String(+Number(v).toFixed(2)))

const PERIODS = [
  { key: 'today', label: '今日' }, { key: 'yesterday', label: '昨日' },
  { key: 'last7', label: '近 7 天' }, { key: 'last30', label: '近 30 天' },
]
const period = ref('today')
const tiles = computed(() => {
  const p = overview.value?.periods?.[period.value]
  if (!p) return []
  return [
    { label: `支付金额（${cur.value}）`, value: money(p.paid_amount) },
    { label: '支付订单数', value: p.paid_orders },
    { label: '支付买家数', value: p.buyers },
    { label: `客单价（${cur.value}）`, value: money(p.avg_order) },
    { label: `退款金额（${cur.value}）`, value: money(p.refund_amount) },
    { label: '新增会员', value: p.new_members },
  ]
})
const TODOS = [
  { key: 'to_ship', label: '待发货订单', path: '/order/ship' },
  { key: 'refund_pending', label: '待处理售后', path: '/order/refund' },
  { key: 'withdraw_pending', label: '待处理提现', path: '/distribution/withdraw' },
  { key: 'distributor_pending', label: '待审核分销员', path: '/distribution/distributor' },
  { key: 'low_stock', label: '库存预警规格', path: '/goods/list' },
]

const amountPoints = computed(() => trend.value.map((r) => ({ label: r.date, value: Number(r.paid_amount) })))
const orderPoints = computed(() => trend.value.map((r) => ({ label: r.date, value: r.paid_orders })))
const rankMax = computed(() => Math.max(1, ...rank.value.map((r) => r.qty)))
const BREAKDOWNS = [
  { key: 'source', label: '来源端' }, { key: 'region', label: '定价区域' },
  { key: 'country', label: '收货国家/地区' }, { key: 'currency', label: '下单币种' },
]
const dim = ref('source')
const dimRows = computed(() => breakdown.value[dim.value] || [])
const dimMax = computed(() => Math.max(1, ...dimRows.value.map((r) => Number(r.amount))))

async function loadRange() {
  const [t, r, b] = await Promise.all([statsTrend(days.value), statsGoodsRank(days.value), statsBreakdown(days.value)])
  trend.value = t.rows
  rank.value = r
  breakdown.value = b
}
async function load() {
  loading.value = true
  try {
    const [o] = await Promise.all([statsOverview(), loadRange()])
    overview.value = o
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page">
    <el-card shadow="never">
      <div class="bar">
        <el-radio-group v-model="period">
          <el-radio-button v-for="p in PERIODS" :key="p.key" :value="p.key">{{ p.label }}</el-radio-button>
        </el-radio-group>
        <span class="sub">金额均折合为基准币种 {{ cur }}；日期按系统时区（{{ overview?.timezone }}）划分；支付金额不扣除退款</span>
      </div>
      <div class="tiles">
        <div v-for="t in tiles" :key="t.label" class="tile">
          <div class="tile-label">{{ t.label }}</div>
          <div class="tile-value">{{ t.value }}</div>
        </div>
      </div>
      <div v-if="overview" class="todos">
        <span class="sub">待办：</span>
        <el-button v-for="t in TODOS" :key="t.key" link :type="overview.todo[t.key] ? 'primary' : 'info'" @click="router.push(t.path)">
          {{ t.label }} <b style="margin-left: 4px">{{ overview.todo[t.key] }}</b>
        </el-button>
        <span class="sub" style="margin-left: auto">会员总数 {{ overview.totals.members }}　在售商品 {{ overview.totals.goods_on_sale }}</span>
      </div>
    </el-card>

    <el-card shadow="never">
      <div class="bar">
        <b>趋势与分布</b>
        <el-radio-group v-model="days" size="small" @change="loadRange">
          <el-radio-button :value="7">近 7 天</el-radio-button>
          <el-radio-button :value="30">近 30 天</el-radio-button>
          <el-radio-button :value="90">近 90 天</el-radio-button>
        </el-radio-group>
        <el-button size="small" style="margin-left: auto" @click="showTable = !showTable">{{ showTable ? '查看图表' : '查看数据表' }}</el-button>
      </div>

      <el-table v-if="showTable" :data="[...trend].reverse()" border size="small" max-height="420">
        <el-table-column prop="date" label="日期" width="130" />
        <el-table-column :label="`支付金额（${cur}）`"><template #default="{ row }">{{ money(row.paid_amount) }}</template></el-table-column>
        <el-table-column prop="paid_orders" label="支付订单数" />
        <el-table-column :label="`退款金额（${cur}）`"><template #default="{ row }">{{ money(row.refund_amount) }}</template></el-table-column>
        <el-table-column prop="new_members" label="新增会员" />
      </el-table>
      <div v-else class="charts">
        <div class="chart">
          <div class="chart-title">每日支付金额（{{ cur }}）</div>
          <TrendChart :points="amountPoints" :format="short" />
        </div>
        <div class="chart">
          <div class="chart-title">每日支付订单数</div>
          <TrendChart :points="orderPoints" integer />
        </div>
      </div>
    </el-card>

    <div class="two">
      <el-card shadow="never">
        <div class="bar"><b>商品销量排行</b><span class="sub">近 {{ days }} 天支付的订单，销量已扣除退款数量</span></div>
        <el-table :data="rank" size="small" empty-text="这段时间没有销量">
          <el-table-column type="index" label="#" width="46" />
          <el-table-column label="商品" min-width="150">
            <template #default="{ row }">
              <div style="display: flex; gap: 8px; align-items: center">
                <el-image v-if="row.image" :src="row.image" fit="cover" style="width: 32px; height: 32px; flex: none" />
                <span>{{ row.title }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="销量" min-width="130">
            <template #default="{ row }">
              <div class="bar-cell"><div class="bar-fill" :style="{ width: (row.qty / rankMax) * 100 + '%' }" /><span>{{ row.qty }}</span></div>
            </template>
          </el-table-column>
          <el-table-column :label="`销售额（${cur}）`" width="120"><template #default="{ row }">{{ money(row.amount) }}</template></el-table-column>
          <el-table-column prop="orders" label="订单数" width="72" />
        </el-table>
      </el-card>

      <el-card shadow="never">
        <div class="bar">
          <b>订单分布</b>
          <el-radio-group v-model="dim" size="small">
            <el-radio-button v-for="b in BREAKDOWNS" :key="b.key" :value="b.key">{{ b.label }}</el-radio-button>
          </el-radio-group>
        </div>
        <el-table :data="dimRows" size="small" empty-text="这段时间没有订单">
          <el-table-column prop="label" :label="BREAKDOWNS.find((b) => b.key === dim).label" min-width="150" />
          <el-table-column :label="`支付金额（${cur}）`" min-width="220">
            <template #default="{ row }">
              <div class="bar-cell"><div class="bar-fill" :style="{ width: (Number(row.amount) / dimMax) * 100 + '%' }" /><span>{{ money(row.amount) }}</span></div>
            </template>
          </el-table-column>
          <el-table-column prop="orders" label="订单数" width="90" />
        </el-table>
      </el-card>
    </div>
  </div>
</template>

<style scoped>
.bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.sub { color: #909399; font-size: 12px; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
.tile { background: #f7f8fa; border-radius: 8px; padding: 14px 16px; }
.tile-label { color: #606266; font-size: 13px; }
.tile-value { margin-top: 6px; font-size: 24px; font-weight: 600; color: #303133; font-variant-numeric: tabular-nums; }
.todos { display: flex; align-items: center; flex-wrap: wrap; gap: 4px 8px; margin-top: 14px; }
.charts { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 16px; }
.chart { min-width: 0; }
.chart-title { color: #606266; font-size: 13px; margin-bottom: 4px; }
.two { display: grid; grid-template-columns: repeat(auto-fit, minmax(460px, 1fr)); gap: 16px; margin-top: 16px; }
.two .el-card { margin-top: 0 !important; }
.bar-cell { display: flex; align-items: center; gap: 8px; }
.bar-cell span { flex: none; font-variant-numeric: tabular-nums; }
.bar-fill { height: 10px; min-width: 2px; background: #2a78d6; border-radius: 0 4px 4px 0; }
</style>
