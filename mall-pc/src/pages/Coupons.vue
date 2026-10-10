<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { getCoupons, receiveCoupon, getMyCoupons } from '@/api'
import { couponText, couponValidText } from '@/utils/app'

// 优惠券：可领取（领券中心）、未使用、已使用、已过期
const { t } = useI18n()
const TABS = ['receivable', 'unused', 'used', 'expired']
const tab = ref('receivable')
const list = ref([])
const page = reactive({ no: 1, size: 12, total: 0, loading: false })
const claiming = ref(0)

async function load() {
  page.loading = true
  try {
    if (tab.value === 'receivable') {
      list.value = await getCoupons()
      page.total = 0
    } else {
      const d = await getMyCoupons({ status: tab.value, page: page.no, size: page.size })
      list.value = d.list
      page.total = d.total
    }
  } finally {
    page.loading = false
  }
}
function pick(name) {
  tab.value = name
  page.no = 1
  list.value = []
  load()
}
async function claim(c) {
  if (claiming.value || !c.can_receive) return
  claiming.value = c.coupon_id
  try {
    await receiveCoupon(c.coupon_id)
    ElMessage.success(t('coupon.receiveOk'))
    await load()
  } finally {
    claiming.value = 0
  }
}

onMounted(load)
</script>

<template>
  <div class="panel">
    <div class="panel-title">{{ t('coupon.title') }}</div>
    <el-tabs :model-value="tab" @tab-change="pick">
      <el-tab-pane v-for="x in TABS" :key="x" :label="t(`coupon.${x}`)" :name="x" />
    </el-tabs>

    <div v-loading="page.loading" class="grid">
      <div v-for="c in list" :key="c.id || c.coupon_id" class="coupon" :class="{ dim: tab === 'used' || tab === 'expired' }">
        <div class="face">
          <div class="value">{{ couponText(c, true) }}</div>
          <div class="cond">{{ couponText(c, false, true) }}</div>
        </div>
        <div class="body">
          <div class="name ellipsis">{{ c.name }}</div>
          <div class="sub">{{ t(c.scope === 1 ? 'coupon.scopeAll' : 'coupon.scopePart') }}</div>
          <div class="sub">{{ couponValidText(c) }}</div>
          <el-button v-if="tab === 'receivable'" class="act" size="small" type="primary" round :disabled="!c.can_receive" :loading="claiming === c.coupon_id" @click="claim(c)">
            {{ t(c.can_receive ? 'coupon.receive' : 'coupon.received') }}
          </el-button>
        </div>
      </div>
    </div>
    <div v-if="!page.loading && !list.length" class="empty">{{ t('coupon.empty') }}</div>
    <div v-if="page.total > page.size" class="pager">
      <el-pagination v-model:current-page="page.no" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="load" />
    </div>
  </div>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; min-height: 60px; }
.coupon { display: flex; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
.coupon.dim { opacity: 0.55; }
.face { width: 150px; flex: none; padding: 20px 10px; background: var(--theme); color: #fff; text-align: center; display: flex; flex-direction: column; justify-content: center; }
.value { font-size: 22px; font-weight: 700; line-height: 1.2; }
.cond { margin-top: 6px; font-size: 12px; opacity: 0.9; }
.body { position: relative; flex: 1; min-width: 0; padding: 14px 16px; line-height: 1.7; }
.name { font-weight: 600; padding-right: 76px; }
.act { position: absolute; right: 14px; top: 12px; }
</style>
