<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getConfig, saveConfig } from '@/api/config'

const loading = ref(false)
const saving = ref(false)
const form = reactive({ enabled: 0, level: 1, rate_1: 10, rate_2: 5, min_withdraw: 100 })

async function load() {
  loading.value = true
  try {
    const d = await getConfig('distribution')
    Object.assign(form, d, { rate_1: Number(d.rate_1), rate_2: Number(d.rate_2), min_withdraw: Number(d.min_withdraw) })
  } finally {
    loading.value = false
  }
}
async function submit() {
  saving.value = true
  try {
    await saveConfig('distribution', { ...form })
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
      <el-form :model="form" label-width="160px" style="max-width: 820px">
        <el-form-item label="开启分销">
          <el-switch v-model="form.enabled" :active-value="1" :inactive-value="0" />
          <span class="unit">关闭后不再产生新佣金，也不接受分销员申请；已有佣金照常结算和提现</span>
        </el-form-item>
        <el-form-item label="分销层级">
          <el-radio-group v-model="form.level">
            <el-radio :value="1">一级（只给买家的直接上级）</el-radio>
            <el-radio :value="2">二级（上级和上级的上级）</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="一级佣金比例">
          <el-input-number v-model="form.rate_1" :min="0" :max="50" :precision="2" :controls="false" style="width: 140px" />
          <span class="unit">%</span>
        </el-form-item>
        <el-form-item v-if="form.level === 2" label="二级佣金比例">
          <el-input-number v-model="form.rate_2" :min="0" :max="50" :precision="2" :controls="false" style="width: 140px" />
          <span class="unit">%</span>
        </el-form-item>
        <el-form-item label="最低提现金额">
          <el-input-number v-model="form.min_withdraw" :min="0" :precision="2" :controls="false" style="width: 140px" />
          <span class="unit">按基准币种计</span>
        </el-form-item>
        <el-form-item>
          <el-button v-perm="'distribution:setting:edit'" type="primary" :loading="saving" @click="submit">保存设置</el-button>
        </el-form-item>
      </el-form>

      <el-alert type="info" :closable="false" title="规则说明" style="max-width: 820px">
        <ul class="rules">
          <li>关系：新用户注册时填写邀请码，永久成为推荐人的下级；之后只能在“分销员”页面由后台调整。</li>
          <li>资格：会员申请、后台审核通过后成为分销员。上级不是分销员时该层不发佣金，也不往上顺延；分销员自己下单不给自己返佣。</li>
          <li>佣金 = （商品金额 - 会员折扣 - 积分抵扣）× 比例，不含运费，统一按基准币种计算。修改比例只影响之后支付的订单。</li>
          <li>订单支付成功生成“待结算”佣金；订单完成并过了“售后申请期限”后自动转为可提现。发生退款按已退商品款的占比扣减，商品全部退完则作废。</li>
          <li>提现：分销员申请后在“提现管理”审核，线下打款后登记“已打款”。</li>
        </ul>
      </el-alert>
    </el-card>
  </div>
</template>

<style scoped>
.unit { margin-left: 10px; color: #909399; }
.rules { margin: 4px 0 0; padding-left: 18px; line-height: 1.9; }
</style>
