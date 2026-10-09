<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getConfig, saveConfig } from '@/api/config'

const loading = ref(false)
const saving = ref(false)
const form = reactive({
  order_expire_minutes: 30,
  auto_receive_days: 7,
  after_sale_days: 7,
  stock_deduct_mode: 'order',
  min_order_amount: '0',
  points_deduct_enabled: 0,
  points_per_unit: 100,
  points_max_percent: 50,
})

async function load() {
  loading.value = true
  try {
    Object.assign(form, await getConfig('trade'))
    form.min_order_amount = Number(form.min_order_amount)
  } finally {
    loading.value = false
  }
}
async function submit() {
  saving.value = true
  try {
    await saveConfig('trade', { ...form })
    ElMessage.success('保存成功')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card v-loading="loading" shadow="never">
      <el-form :model="form" label-width="200px" style="max-width: 760px">
        <el-form-item label="未支付订单自动关闭">
          <el-input-number v-model="form.order_expire_minutes" :min="1" :max="10080" />
          <span class="unit">分钟</span>
        </el-form-item>
        <el-form-item label="自动确认收货">
          <el-input-number v-model="form.auto_receive_days" :min="1" :max="90" />
          <span class="unit">天（发货后超过该天数自动完成订单）</span>
        </el-form-item>
        <el-form-item label="售后申请期限">
          <el-input-number v-model="form.after_sale_days" :min="0" :max="90" />
          <span class="unit">天（订单完成后可申请售后的天数，0 表示不允许）</span>
        </el-form-item>
        <el-form-item label="库存扣减时机">
          <el-radio-group v-model="form.stock_deduct_mode">
            <el-radio value="order">下单扣减（防超卖，未支付超时会回补）</el-radio>
            <el-radio value="pay">支付扣减（可能出现超卖）</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="最低下单金额">
          <el-input-number v-model="form.min_order_amount" :min="0" :precision="2" :controls="false" />
          <span class="unit">按基准币种计，0 表示不限制</span>
        </el-form-item>
        <el-form-item label="下单积分抵扣">
          <el-switch v-model="form.points_deduct_enabled" :active-value="1" :inactive-value="0" />
          <span class="unit">开启后买家下单时可以用积分抵扣部分商品金额</span>
        </el-form-item>
        <template v-if="form.points_deduct_enabled">
          <el-form-item label="积分兑换比例">
            <el-input-number v-model="form.points_per_unit" :min="1" :max="1000000" />
            <span class="unit">个积分抵 1 元（按基准币种计，其他币种按汇率折算）</span>
          </el-form-item>
          <el-form-item label="积分抵扣上限">
            <el-input-number v-model="form.points_max_percent" :min="1" :max="99" />
            <span class="unit">%（最多抵扣“商品金额 - 会员折扣”的比例，运费不参与）</span>
          </el-form-item>
          <el-form-item label="积分退还">
            <span class="unit" style="margin-left: 0">订单取消、超时关闭或商品全部退款时，抵扣的积分自动退还；部分退款不退积分。</span>
          </el-form-item>
        </template>
        <el-form-item>
          <el-button v-perm="'setting:trade:edit'" type="primary" :loading="saving" @click="submit">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.unit { margin-left: 10px; color: #909399; }
</style>
