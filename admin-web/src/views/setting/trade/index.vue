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
