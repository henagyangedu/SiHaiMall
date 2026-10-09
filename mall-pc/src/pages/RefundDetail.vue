<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { getRefund, cancelRefund, submitRefundReturn } from '@/api'
import { money, confirm } from '@/utils/app'

const { t } = useI18n()
const route = useRoute()
const refundNo = String(route.params.refundNo)

const d = ref(null)
const ret = reactive({ express: '', express_no: '', saving: false })

async function load() {
  d.value = await getRefund(refundNo)
}
async function onCancel() {
  if (!(await confirm(t('refund.cancelConfirm')))) return
  await cancelRefund(refundNo)
  ElMessage.success(t('refund.cancelled'))
  load()
}
async function onReturn() {
  if (ret.saving) return
  if (!ret.express.trim() || !ret.express_no.trim()) return ElMessage.warning(t('refund.needExpress'))
  ret.saving = true
  try {
    await submitRefundReturn(refundNo, { express: ret.express.trim(), express_no: ret.express_no.trim() })
    ElMessage.success(t('refund.returnSent'))
    load()
  } finally {
    ret.saving = false
  }
}

onMounted(load)
</script>

<template>
  <template v-if="d">
    <div class="panel status">
      <div>
        <div class="st">{{ t(`refundStatus.${d.status}`) }}</div>
        <div class="sub">{{ d.whole_order ? t('refund.whole') : t(`refundType.${d.type}`) }}　{{ money(d.refund_amount, d.currency) }}</div>
      </div>
      <el-button v-if="d.can_cancel" size="large" @click="onCancel">{{ t('refund.cancel') }}</el-button>
    </div>

    <div v-if="d.reject_reason" class="panel">
      <div class="panel-title">{{ t('refund.rejectReason') }}</div>
      <div>{{ d.reject_reason }}</div>
    </div>

    <div v-if="d.can_submit_return" class="panel">
      <div class="panel-title">{{ t('refund.returnTitle') }}</div>
      <div class="sub tip">{{ t('refund.returnTip') }}</div>
      <div class="ret">
        <el-input v-model="ret.express" maxlength="60" :placeholder="t('refund.express')" />
        <el-input v-model="ret.express_no" maxlength="60" :placeholder="t('refund.expressNo')" />
        <el-button type="primary" :loading="ret.saving" @click="onReturn">{{ t('refund.submitReturn') }}</el-button>
      </div>
    </div>
    <div v-else-if="d.return_express_no" class="panel">
      <span class="sub">{{ t('refund.returned') }}　</span>{{ d.return_express }} {{ d.return_express_no }}
    </div>

    <div class="panel">
      <div v-for="(i, k) in d.items" :key="k" class="item">
        <img :src="i.image" alt="" class="thumb" />
        <div class="body"><div class="ellipsis-2">{{ i.title }}</div><div class="sub">{{ i.spec_text }}　× {{ i.qty }}</div></div>
      </div>
      <div class="kv"><span class="sub">{{ t('refund.reason') }}</span><span class="val">{{ d.reason }}</span></div>
      <div v-if="d.images && d.images.length" class="pics">
        <el-image v-for="(u, i) in d.images" :key="i" :src="u" :preview-src-list="d.images" :initial-index="i" fit="cover" preview-teleported class="pic" />
      </div>
      <div class="kv"><span class="sub">{{ t('refund.amount') }}</span><span class="price">{{ money(d.refund_amount, d.currency) }}</span></div>
      <div class="kv"><span class="sub">{{ t('refund.no') }}</span><span>{{ d.refund_no }}</span></div>
      <div class="kv">
        <span class="sub">{{ t('refund.orderNo') }}</span>
        <router-link :to="{ name: 'order-detail', params: { orderNo: d.order_no } }" class="link">{{ d.order_no }}</router-link>
      </div>
      <div class="kv"><span class="sub">{{ t('refund.applyAt') }}</span><span>{{ d.created_at }}</span></div>
    </div>
  </template>
</template>

<style scoped>
.status { display: flex; justify-content: space-between; align-items: center; }
.st { font-size: 22px; font-weight: 600; color: var(--theme); margin-bottom: 6px; }
.tip { margin-bottom: 12px; }
.ret { display: flex; gap: 10px; max-width: 640px; }
.item { display: flex; align-items: center; gap: 14px; padding: 8px 0 14px; border-bottom: 1px solid var(--line); margin-bottom: 8px; }
.body { min-width: 0; }
.val { max-width: 70%; text-align: right; word-break: break-all; }
.pics { display: flex; flex-wrap: wrap; gap: 10px; padding: 6px 0 10px; }
.pic { width: 80px; height: 80px; border-radius: 6px; }
</style>
