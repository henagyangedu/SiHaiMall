<script setup>
// 商家代买家发起售后：选整单或某个商品、数量、类型、金额。提交后直接视为审核通过，再到“售后订单”里执行退款
import { ref, reactive, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { orderRefundable, applyRefundForOrder } from '@/api/refund'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  orderId: { type: Number, default: 0 },
})
const emit = defineEmits(['update:modelValue', 'done'])

const loading = ref(false)
const saving = ref(false)
const info = ref(null)
// target：'whole' 整单，或商品行 ID
const form = reactive({ target: null, qty: 1, type: 1, amount: 0, reason: '' })

const items = computed(() => (info.value?.items || []).filter((i) => i.available_qty > 0))
const item = computed(() => items.value.find((i) => i.item_id === form.target) || null)
const nothing = computed(() => !!info.value && !info.value.whole_order.allowed && !items.value.length)
// 可退上限：整单是实付；单品按所选数量由后端算出的上限等比估算，最终以后端校验为准
const maxAmount = computed(() => {
  if (form.target === 'whole') return Number(info.value.whole_order.amount)
  if (!item.value) return 0
  return Math.round((Number(item.value.max_amount) * form.qty / item.value.available_qty) * 100) / 100
})

function pick(target) {
  form.target = target
  form.qty = 1
  form.type = 1
  form.amount = maxAmount.value
}
watch(() => form.qty, () => { form.amount = maxAmount.value })

watch(() => props.modelValue, async (v) => {
  if (!v) return
  info.value = null
  Object.assign(form, { target: null, qty: 1, type: 1, amount: 0, reason: '' })
  loading.value = true
  try {
    info.value = await orderRefundable(props.orderId)
    if (info.value.whole_order.allowed && !items.value.length) pick('whole')
    else if (!info.value.whole_order.allowed && items.value.length === 1) pick(items.value[0].item_id)
  } catch (e) {
    emit('update:modelValue', false)
  } finally {
    loading.value = false
  }
})

async function submit() {
  if (form.target === null) return ElMessage.warning('请选择要售后的商品')
  if (!form.reason.trim()) return ElMessage.warning('请填写原因')
  if (!(form.amount > 0)) return ElMessage.warning('请填写退款金额')
  saving.value = true
  try {
    const base = { type: form.type, reason: form.reason.trim(), amount: form.amount }
    const r = await applyRefundForOrder(props.orderId, form.target === 'whole' ? base : { ...base, order_item_id: form.target, qty: form.qty })
    ElMessage.success(r.status === 1 ? `售后单 ${r.refund_no} 已创建，等待买家寄回商品` : `售后单 ${r.refund_no} 已创建，请到“售后订单”执行退款`)
    emit('update:modelValue', false)
    emit('done', r)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-dialog :model-value="modelValue" :title="info ? `发起售后 · 订单 ${info.order_no}` : '发起售后'" width="640px" destroy-on-close @update:model-value="emit('update:modelValue', $event)">
    <div v-loading="loading" style="min-height: 120px">
      <template v-if="info">
        <el-alert v-if="nothing" type="info" :closable="false" :title="info.reason || '这个订单当前没有可以发起售后的商品'" />
        <el-form v-else label-width="90px">
          <el-form-item label="售后对象">
            <el-radio-group v-model="form.target" class="targets" @change="pick">
              <el-radio v-if="info.whole_order.allowed" value="whole">整单退款（含运费）{{ info.whole_order.amount }} {{ info.currency }}</el-radio>
              <el-radio v-for="i in items" :key="i.item_id" :value="i.item_id">
                {{ i.title }}<span v-if="i.spec_text" class="muted">　{{ i.spec_text }}</span>
                <span class="muted">　可退 {{ i.available_qty }} 件，最多 {{ i.max_amount }} {{ info.currency }}</span>
              </el-radio>
            </el-radio-group>
          </el-form-item>
          <template v-if="item">
            <el-form-item label="数量">
              <el-input-number v-model="form.qty" :min="1" :max="item.available_qty" />
            </el-form-item>
            <el-form-item label="售后类型">
              <el-radio-group v-model="form.type">
                <el-radio :value="1">仅退款</el-radio>
                <el-radio :value="2" :disabled="!item.can_return">退货退款<span v-if="!item.can_return" class="muted">（还没发货）</span></el-radio>
              </el-radio-group>
            </el-form-item>
          </template>
          <el-form-item v-if="form.target !== null" label="退款金额">
            <el-input-number v-model="form.amount" :min="0.01" :max="maxAmount" :precision="2" :controls="false" :disabled="form.target === 'whole'" />
            <span class="muted">　{{ info.currency }}，最多 {{ maxAmount.toFixed(2) }}</span>
          </el-form-item>
          <el-form-item label="原因">
            <el-input v-model="form.reason" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="买家能看到，例如：协商一致退款、缺货" />
          </el-form-item>
          <el-alert type="warning" :closable="false" title="提交后直接视为审核通过：仅退款进入“退款中”，需要到“售后订单”里执行退款；退货退款要等买家寄回商品。商家发起不受售后申请期限限制。" />
        </el-form>
      </template>
    </div>
    <template #footer>
      <el-button @click="emit('update:modelValue', false)">取消</el-button>
      <el-button v-if="info && !nothing" type="primary" :loading="saving" @click="submit">提交</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.targets { display: flex; flex-direction: column; align-items: flex-start; }
.muted { color: #909399; font-size: 12px; }
</style>
