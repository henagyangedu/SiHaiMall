<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { getRefundable, getOrder, applyRefund, uploadImage } from '@/api'
import { money } from '@/utils/app'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const orderNo = String(route.params.orderNo)

const info = ref(null)
const currency = ref('')
// target：'whole' 整单，或商品行 ID
const form = reactive({ target: null, qty: 1, type: 1, reason: '' })
const submitting = ref(false)
// 凭证图片：已上传成功的地址，最多 6 张
const MAX_IMAGES = 6
const images = ref([])
const uploading = ref(false)
const picker = ref(null)

const items = computed(() => (info.value?.items || []).filter((i) => i.available_qty > 0))
const item = computed(() => items.value.find((i) => i.item_id === form.target) || null)
const nothing = computed(() => !!info.value && !info.value.whole_order.allowed && !items.value.length)

function pick(target) {
  Object.assign(form, { target, qty: 1, type: 1 })
}

async function onFiles(e) {
  const files = [...e.target.files].slice(0, MAX_IMAGES - images.value.length)
  e.target.value = '' // 允许再次选择同一张图
  if (!files.length || uploading.value) return
  uploading.value = true
  try {
    // 逐张上传：某一张失败（提示已弹出）就停下，已传好的保留
    for (const f of files) images.value.push((await uploadImage(f, 'refund')).url)
  } catch (err) {
    // 提示已由 upload 弹出
  } finally {
    uploading.value = false
  }
}

async function submit() {
  if (submitting.value || uploading.value) return
  if (form.target === null) return ElMessage.warning(t('refund.needItem'))
  if (!form.reason.trim()) return ElMessage.warning(t('refund.needReason'))
  submitting.value = true
  try {
    const base = { reason: form.reason.trim(), images: images.value }
    const r = await applyRefund(orderNo, form.target === 'whole' ? { ...base, type: 1 } : { ...base, order_item_id: form.target, qty: form.qty, type: form.type })
    ElMessage.success(t('refund.submitted'))
    router.replace({ name: 'refund-detail', params: { refundNo: r.refund_no } })
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  info.value = await getRefundable(orderNo)
  getOrder(orderNo).then((o) => { currency.value = o.currency }).catch(() => {})
  // 只有一种选择时直接选中
  if (info.value.whole_order.allowed && !items.value.length) pick('whole')
  else if (!info.value.whole_order.allowed && items.value.length === 1) pick(items.value[0].item_id)
})
</script>

<template>
  <div v-if="info" class="panel">
    <div class="panel-title">{{ t('refund.apply') }}<span class="sub no">{{ t('refund.orderNo') }} {{ orderNo }}</span></div>

    <div v-if="nothing" class="empty">{{ info.reason || t('refund.none') }}</div>

    <el-form v-else label-width="160px">
      <el-form-item :label="t('refund.chooseItem')">
        <div class="targets">
          <div v-if="info.whole_order.allowed" class="opt" :class="{ on: form.target === 'whole' }" @click="pick('whole')">
            <div><b>{{ t('refund.whole') }}</b>　<span class="price">{{ money(info.whole_order.amount, currency) }}</span></div>
            <div class="sub">{{ t('refund.wholeTip') }}</div>
          </div>
          <div v-for="i in items" :key="i.item_id" class="opt goods" :class="{ on: form.target === i.item_id }" @click="pick(i.item_id)">
            <img :src="i.image" alt="" class="thumb" />
            <div class="body">
              <div class="ellipsis">{{ i.title }}</div>
              <div class="sub">{{ i.spec_text }}</div>
              <div class="sub">{{ t('refund.available', { n: i.available_qty }) }}　{{ t('refund.maxAmount', { amount: money(i.max_amount, currency) }) }}</div>
            </div>
          </div>
        </div>
      </el-form-item>

      <template v-if="item">
        <el-form-item :label="t('refund.type')">
          <el-radio-group v-model="form.type">
            <el-radio :value="1">{{ t('refundType.1') }}</el-radio>
            <!-- 退货退款只有已发货的商品可以选 -->
            <el-radio v-if="item.can_return" :value="2">{{ t('refundType.2') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('refund.qty')">
          <el-input-number v-model="form.qty" :min="1" :max="item.available_qty" />
          <span class="sub tip">{{ t('refund.amountTip') }}</span>
        </el-form-item>
      </template>

      <el-form-item :label="t('refund.reason')" required>
        <el-input v-model="form.reason" type="textarea" :rows="4" maxlength="255" show-word-limit :placeholder="t('refund.reasonPh')" />
      </el-form-item>
      <el-form-item :label="t('pc.chooseImage')">
        <div class="pics">
          <div v-for="(u, i) in images" :key="u" class="pic">
            <el-image :src="u" :preview-src-list="images" :initial-index="i" fit="cover" preview-teleported class="pic-img" />
            <span class="pic-del" @click="images.splice(i, 1)">×</span>
          </div>
          <div v-if="images.length < MAX_IMAGES" v-loading="uploading" class="pic pic-add" @click="picker.click()">＋</div>
          <input ref="picker" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden @change="onFiles" />
        </div>
        <div class="sub hint">{{ t('refund.imagesTip', { n: MAX_IMAGES }) }}</div>
      </el-form-item>

      <el-form-item>
        <el-button type="primary" size="large" :loading="submitting" :disabled="uploading" @click="submit">{{ t('refund.submit') }}</el-button>
        <el-button size="large" @click="router.back()">{{ t('common.cancel') }}</el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.no { margin-left: 16px; font-weight: 400; }
.targets { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; width: 100%; }
.opt { border: 2px solid var(--line); border-radius: 8px; padding: 12px 14px; cursor: pointer; line-height: 1.6; }
.opt:hover { border-color: var(--el-color-primary-light-5); }
.opt.on { border-color: var(--theme); background: var(--el-color-primary-light-9); }
.goods { display: flex; gap: 12px; align-items: center; }
.body { min-width: 0; }
.tip { margin-left: 14px; }
.pics { display: flex; flex-wrap: wrap; gap: 10px; }
.pic { position: relative; width: 88px; height: 88px; border-radius: 6px; background: #f5f6f8; }
.pic-img { width: 100%; height: 100%; border-radius: 6px; }
.pic-del { position: absolute; top: -8px; right: -8px; width: 20px; height: 20px; line-height: 18px; text-align: center; border-radius: 50%; background: rgba(0, 0, 0, 0.6); color: #fff; cursor: pointer; }
.pic-add { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 28px; border: 1px dashed #dcdfe6; cursor: pointer; }
.hint { width: 100%; }
</style>
