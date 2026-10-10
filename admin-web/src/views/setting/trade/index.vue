<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getConfig, saveConfig } from '@/api/config'
import { languageOptions } from '@/api/language'
import LangInput from '@/components/LangInput.vue'

const loading = ref(false)
const saving = ref(false)
const languages = ref([])
const form = reactive({
  order_expire_minutes: 30,
  auto_receive_days: 7,
  after_sale_days: 7,
  stock_deduct_mode: 'order',
  min_order_amount: '0',
  points_deduct_enabled: 0,
  points_per_unit: 100,
  points_max_percent: 50,
  points_earn_enabled: 0,
  points_earn_per_unit: 1,
  points_register: 0,
  points_signin: 0,
  tax_enabled: 0,
  invoice_enabled: 0,
  invoice_notice: {},
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

onMounted(() => {
  load()
  languageOptions().then((d) => { languages.value = d }).catch(() => {})
})
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
        <el-form-item label="消费得积分">
          <el-switch v-model="form.points_earn_enabled" :active-value="1" :inactive-value="0" />
          <span class="unit">开启后订单完成（确认收货）时按实付的商品金额赠送积分</span>
        </el-form-item>
        <template v-if="form.points_earn_enabled">
          <el-form-item label="赠送比例">
            <span class="unit" style="margin-left: 0; margin-right: 10px">每消费 1 元赠送</span>
            <el-input-number v-model="form.points_earn_per_unit" :min="1" :max="10000" />
            <span class="unit">个积分（按基准币种计，运费和已退款的部分不送，不足 1 个积分的舍去）</span>
          </el-form-item>
          <el-form-item label="退款扣回">
            <span class="unit" style="margin-left: 0">订单完成后再退款，按退款比例扣回已赠送的积分；买家积分不够时扣到 0 为止。</span>
          </el-form-item>
        </template>
        <el-form-item label="注册送积分">
          <el-input-number v-model="form.points_register" :min="0" :max="100000" />
          <span class="unit">个积分，新会员注册成功时赠送一次；填 0 表示不送</span>
        </el-form-item>
        <el-form-item label="每日签到">
          <el-input-number v-model="form.points_signin" :min="0" :max="100000" />
          <span class="unit">个积分，会员每天可以在“我的积分”里签到一次；填 0 表示不开放签到</span>
        </el-form-item>
        <el-form-item label="按收货地区收税">
          <el-switch v-model="form.tax_enabled" :active-value="1" :inactive-value="0" />
          <span class="unit">开启后按“设置 → 税率设置”里的规则，根据收货地址给订单算税；没有规则的地区不收税</span>
        </el-form-item>
        <el-form-item label="允许申请发票">
          <el-switch v-model="form.invoice_enabled" :active-value="1" :inactive-value="0" />
          <span class="unit">开启后买家可以在已付款的人民币订单上申请发票，到“订单 → 发票管理”处理</span>
        </el-form-item>
        <el-form-item v-if="form.invoice_enabled" label="开票说明">
          <LangInput v-model="form.invoice_notice" :languages="languages" type="textarea" :rows="3" :maxlength="500" />
          <div class="tip">显示在买家的发票申请页上，比如发票类型、多久开出、开票内容。可以不填。</div>
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
.tip { width: 100%; color: #909399; font-size: 12px; line-height: 1.6; }
</style>
