<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getConfig, saveConfig } from '@/api/config'
import { receiptHtml, printHtml } from '@/utils/receipt'

const loading = ref(false)
const saving = ref(false)
const form = reactive({ title: '', footer: '', paper_width: '80', show_price: 1, show_address: 1, show_remark: 1, show_admin_remark: 0 })

// 预览用的示例订单，字段和后台订单详情接口一致
const SAMPLE = {
  order_no: 'GM260101120000123456', created_at: '2026-01-01 12:00:00', paid_at: '2026-01-01 12:01:30', currency: 'CNY',
  items: [
    { title: '示例商品 A', spec_text: '红色 / L', price: '99.00', qty: 2, subtotal: '198.00' },
    { title: '示例商品 B', spec_text: '', price: '35.50', qty: 1, subtotal: '35.50' },
  ],
  goods_amount: '233.50', shipping_fee: '10.00', discount_amount: '5.00', points_amount: '0.00', pay_amount: '238.50',
  address: { name: '张三', phone_area: '86', phone: '13800000000', country_code: 'CN', province: '广东省', city: '深圳市', district: '南山区', address: '示例路 1 号', address2: '', postcode: '518000' },
  remark: '请尽快发货', admin_remark: '老客户',
}
const preview = computed(() => receiptHtml(SAMPLE, form))
// 预览框按纸宽显示：1mm 约 3.78 像素
const previewWidth = computed(() => `${Math.round((form.paper_width === '58' ? 58 : 80) * 3.78)}px`)

async function load() {
  loading.value = true
  try {
    Object.assign(form, await getConfig('receipt'))
    form.paper_width = String(form.paper_width || '80')
  } finally {
    loading.value = false
  }
}
async function submit() {
  saving.value = true
  try {
    await saveConfig('receipt', { ...form })
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
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px"
        title="小票用浏览器打印：在“订单列表”的订单详情或“发货管理”里点“打印小票”，在弹出的打印对话框里选择小票机或普通打印机。用热敏小票机时，请在打印对话框里把纸张选成对应的宽度、边距设为“无”或“最小”。" />
      <div class="wrap">
        <el-form :model="form" label-width="130px" class="form">
          <el-form-item label="小票抬头">
            <el-input v-model="form.title" maxlength="60" placeholder="如店铺名称，留空则不打印抬头" />
          </el-form-item>
          <el-form-item label="页脚文字">
            <el-input v-model="form.footer" type="textarea" :rows="3" maxlength="200" show-word-limit placeholder="如：感谢惠顾，售后请联系客服" />
          </el-form-item>
          <el-form-item label="纸张宽度">
            <el-radio-group v-model="form.paper_width">
              <el-radio value="80">80 毫米</el-radio>
              <el-radio value="58">58 毫米</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="打印金额">
            <el-switch v-model="form.show_price" :active-value="1" :inactive-value="0" />
            <span class="hint">关闭后只打印商品和数量，适合随货的拣货单</span>
          </el-form-item>
          <el-form-item label="打印收货信息"><el-switch v-model="form.show_address" :active-value="1" :inactive-value="0" /></el-form-item>
          <el-form-item label="打印买家留言"><el-switch v-model="form.show_remark" :active-value="1" :inactive-value="0" /></el-form-item>
          <el-form-item label="打印商家备注">
            <el-switch v-model="form.show_admin_remark" :active-value="1" :inactive-value="0" />
            <span class="hint">商家备注是内部信息，小票会交给买家时不要打开</span>
          </el-form-item>
          <el-form-item>
            <el-button v-perm="'setting:receipt:edit'" type="primary" :loading="saving" @click="submit">保存设置</el-button>
            <el-button @click="printHtml(preview)">打印测试页</el-button>
          </el-form-item>
        </el-form>

        <div class="preview">
          <div class="preview-title">预览（示例订单）</div>
          <iframe class="paper" :style="{ width: previewWidth }" :srcdoc="preview" title="小票预览" />
        </div>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.wrap { display: flex; gap: 40px; align-items: flex-start; flex-wrap: wrap; }
.form { flex: 1; min-width: 420px; max-width: 640px; }
.hint { margin-left: 10px; color: #909399; font-size: 12px; }
.preview { flex: none; }
.preview-title { color: #909399; margin-bottom: 8px; }
.paper { height: 520px; border: 1px solid #dcdfe6; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06); padding: 0; }
</style>
