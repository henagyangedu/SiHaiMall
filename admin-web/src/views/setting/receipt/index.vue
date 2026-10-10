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
const paperMm = computed(() => (form.paper_width === '58' ? 58 : 80))
// 预览里的纸按真实纸宽显示：1 毫米约 3.78 像素
const previewWidth = computed(() => `${Math.round(paperMm.value * 3.78)}px`)
// 纸的高度跟着内容走（小票是连续纸，打多长算多长），不留一大截空白
const previewHeight = ref('420px')
function fitHeight(e) {
  const doc = e.target.contentDocument
  if (!doc?.body) return
  // 量正文的高度再加上四周的留白；不能量整个文档——它至少有预览框那么高，内容变短时不会跟着缩
  const pad = parseFloat(doc.defaultView.getComputedStyle(doc.documentElement).paddingTop) || 0
  previewHeight.value = `${Math.ceil(doc.body.getBoundingClientRect().height + pad * 2)}px`
}

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
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 20px"
        title="小票用浏览器打印：在“订单列表”的订单详情或“发货管理”里点“打印小票”，在弹出的打印对话框里选择小票机或普通打印机。用热敏小票机时，请在打印对话框里把纸张选成对应的宽度、边距设为“无”或“最小”。" />
      <div class="wrap">
        <el-form :model="form" label-width="110px" class="form">
          <div class="group">小票内容</div>
          <el-form-item label="小票抬头">
            <el-input v-model="form.title" maxlength="60" placeholder="如店铺名称，留空则不打印抬头" />
          </el-form-item>
          <el-form-item label="页脚文字">
            <el-input v-model="form.footer" type="textarea" :rows="3" maxlength="200" show-word-limit placeholder="如：感谢惠顾，售后请联系客服" />
          </el-form-item>

          <div class="group">纸张</div>
          <el-form-item label="纸张宽度">
            <el-radio-group v-model="form.paper_width">
              <el-radio-button value="80">80 毫米</el-radio-button>
              <el-radio-button value="58">58 毫米</el-radio-button>
            </el-radio-group>
            <div class="hint block">按你的小票机用的纸来选；普通打印机选哪个都能打。</div>
          </el-form-item>

          <div class="group">打印哪些内容</div>
          <div class="switches">
            <div class="switch">
              <div><div class="s-title">金额</div><div class="hint">关闭后只打印商品和数量，适合随货的拣货单</div></div>
              <el-switch v-model="form.show_price" :active-value="1" :inactive-value="0" />
            </div>
            <div class="switch">
              <div><div class="s-title">收货信息</div><div class="hint">收货人、电话和地址</div></div>
              <el-switch v-model="form.show_address" :active-value="1" :inactive-value="0" />
            </div>
            <div class="switch">
              <div><div class="s-title">买家留言</div><div class="hint">买家下单时填的备注</div></div>
              <el-switch v-model="form.show_remark" :active-value="1" :inactive-value="0" />
            </div>
            <div class="switch">
              <div><div class="s-title">商家备注</div><div class="hint">内部信息，小票会交给买家时不要打开</div></div>
              <el-switch v-model="form.show_admin_remark" :active-value="1" :inactive-value="0" />
            </div>
          </div>

          <div class="actions">
            <el-button v-perm="'setting:receipt:edit'" type="primary" :loading="saving" @click="submit">保存设置</el-button>
            <el-button @click="printHtml(preview)">打印测试页</el-button>
          </div>
        </el-form>

        <div class="preview">
          <div class="preview-head">
            <span class="preview-title">预览</span>
            <span class="hint">{{ paperMm }} 毫米纸 · 实际大小 · 示例订单，改左边的设置会立刻反映在这里</span>
          </div>
          <div class="desk">
            <div class="sheet" :style="{ width: previewWidth }">
              <iframe class="paper" :style="{ width: previewWidth, height: previewHeight }" :srcdoc="preview" title="小票预览" scrolling="no" @load="fitHeight" />
              <div class="tear" />
            </div>
          </div>
        </div>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.wrap { display: flex; gap: 32px; align-items: flex-start; flex-wrap: wrap; }
.form { flex: 1 1 460px; max-width: 600px; }
.group { margin: 4px 0 14px; padding-left: 10px; border-left: 3px solid var(--el-color-primary); font-weight: 600; line-height: 1.2; }
.group:not(:first-child) { margin-top: 26px; }
.hint { color: #909399; font-size: 12px; line-height: 1.5; }
.hint.block { width: 100%; margin-top: 6px; }
.switches { border: 1px solid #ebeef5; border-radius: 6px; }
.switch { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 12px 16px; }
.switch + .switch { border-top: 1px solid #ebeef5; }
.s-title { line-height: 1.6; }
.actions { margin-top: 24px; }

/* 预览：灰色的“桌面”上放一张按真实纸宽显示的小票，页面往下滚时跟着停在视野里 */
.preview { flex: 1 1 380px; min-width: 360px; position: sticky; top: 16px; }
.preview-head { display: flex; align-items: baseline; gap: 12px; margin-bottom: 10px; }
.preview-title { font-weight: 600; }
.desk { display: flex; justify-content: center; padding: 28px 20px 36px; border-radius: 8px; background: #eef0f4; }
.sheet { background: #fff; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12); }
.paper { display: block; border: 0; background: #fff; transition: height 0.15s; }
/* 纸的下边缘做成撕口的锯齿 */
.tear { height: 8px; background: linear-gradient(-45deg, #eef0f4 4px, transparent 0), linear-gradient(45deg, #eef0f4 4px, transparent 0); background-size: 8px 8px; background-position: left bottom; background-repeat: repeat-x; }
</style>
