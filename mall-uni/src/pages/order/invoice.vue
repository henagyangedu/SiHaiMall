<script setup>
import { ref, reactive, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getInvoice, applyInvoice, cancelInvoice } from '@/api'
import { money } from '@/utils/app'
import { usePageTitle, toast, confirm } from '@/utils/page'

// 订单发票：付款后申请，商家开出前可以修改或撤销；开出后在这里查看发票文件
const { t } = useI18n()
usePageTitle('invoice.title')

const orderNo = ref('')
const info = ref(null)
const editing = ref(false)
const saving = ref(false)
const emptyForm = () => ({ title_type: 1, title: '', tax_no: '', email: '', company_info: { address: '', phone: '', bank: '', bank_account: '' } })
const form = reactive(emptyForm())
const more = ref(false)

const inv = computed(() => info.value?.invoice || null)
// 待开票、被驳回的可以撤销；没开出的都可以改
const canCancel = computed(() => inv.value && [0, 2].includes(inv.value.status))
const showForm = computed(() => info.value?.can_apply && (!inv.value || editing.value))

function fill(src) {
  Object.assign(form, emptyForm(), src ? { title_type: src.title_type, title: src.title, tax_no: src.tax_no, email: src.email, company_info: { ...emptyForm().company_info, ...(src.company_info || {}) } } : {})
  more.value = Object.values(form.company_info).some(Boolean)
}
async function load() {
  info.value = await getInvoice(orderNo.value)
  editing.value = false
  // 没申请过：带出上次在别的订单上填的抬头
  fill(inv.value || info.value.last)
}
async function submit() {
  if (saving.value) return
  if (!form.title.trim()) return toast(t('invoice.needName'))
  if (form.title_type === 2 && !form.tax_no.trim()) return toast(t('invoice.needTaxNo'))
  if (!form.email.trim()) return toast(t('invoice.needEmail'))
  saving.value = true
  try {
    await applyInvoice(orderNo.value, { ...form, company_info: form.title_type === 2 ? form.company_info : {} })
    toast(t('invoice.submitted'))
    await load()
  } finally {
    saving.value = false
  }
}
async function onCancel() {
  if (!(await confirm(t('invoice.cancelConfirm')))) return
  await cancelInvoice(orderNo.value)
  toast(t('invoice.cancelled'))
  load()
}
// 打开发票文件：浏览器里直接新开；小程序里图片用预览，PDF 先下载再用系统的文档查看
function openFile() {
  const url = inv.value.file_url
  // #ifdef H5
  window.open(url, '_blank')
  // #endif
  // #ifndef H5
  if (/\.(jpe?g|png)$/i.test(url)) {
    uni.previewImage({ urls: [url] })
    return
  }
  uni.showLoading({ title: '' })
  uni.downloadFile({
    url,
    success: (r) => uni.openDocument({ filePath: r.tempFilePath, fileType: 'pdf', showMenu: true }),
    fail: () => toast(t('invoice.openFailed')),
    complete: () => uni.hideLoading(),
  })
  // #endif
}

onLoad((q) => {
  orderNo.value = q.order_no || ''
  load()
})
</script>

<template>
  <view v-if="info" class="page">
    <view v-if="inv && !editing" class="card">
      <view class="kv"><text class="sub">{{ t('invoice.status') }}</text><text class="st" :class="`s${inv.status}`">{{ t(`invoice.st${inv.status}`) }}</text></view>
      <view v-if="inv.reject_reason" class="kv"><text class="sub">{{ t('invoice.reason') }}</text><text class="val">{{ inv.reject_reason }}</text></view>
      <view class="kv"><text class="sub">{{ t('invoice.name') }}</text><text class="val">{{ inv.title }}</text></view>
      <view v-if="inv.tax_no" class="kv"><text class="sub">{{ t('invoice.taxNo') }}</text><text class="val" selectable>{{ inv.tax_no }}</text></view>
      <view class="kv"><text class="sub">{{ t('invoice.email') }}</text><text class="val">{{ inv.email }}</text></view>
      <view class="kv"><text class="sub">{{ t('invoice.amount') }}</text><text>{{ money(inv.status === 1 ? inv.amount : info.amount, info.currency) }}</text></view>
      <view v-if="inv.invoice_no" class="kv"><text class="sub">{{ t('invoice.invoiceNo') }}</text><text class="val" selectable>{{ inv.invoice_no }}</text></view>
      <view v-if="inv.file_url" class="btn view-btn" @click="openFile">{{ t('invoice.view') }}</view>
      <view v-if="info.can_apply || canCancel" class="ops">
        <view v-if="canCancel" class="btn small gray" @click="onCancel">{{ t('invoice.cancel') }}</view>
        <view v-if="info.can_apply" class="btn small plain" @click="editing = true">{{ t(inv.status === 0 ? 'invoice.edit' : 'invoice.reapply') }}</view>
      </view>
    </view>

    <view v-if="!inv && !info.can_apply" class="empty">{{ info.reason }}</view>

    <template v-if="showForm">
      <view class="card">
        <view class="kv"><text class="sub">{{ t('invoice.amount') }}</text><text class="price">{{ money(info.amount, info.currency) }}</text></view>
        <view class="sub">{{ t('invoice.amountTip') }}</view>
        <view v-if="info.notice" class="notice">{{ info.notice }}</view>
      </view>
      <view class="card form">
        <view class="types">
          <view class="type" :class="{ on: form.title_type === 1 }" @click="form.title_type = 1">{{ t('invoice.personal') }}</view>
          <view class="type" :class="{ on: form.title_type === 2 }" @click="form.title_type = 2">{{ t('invoice.company') }}</view>
        </view>
        <input v-model="form.title" class="input" maxlength="100" :placeholder="t(form.title_type === 2 ? 'invoice.companyPh' : 'invoice.namePh')" />
        <input v-if="form.title_type === 2" v-model="form.tax_no" class="input" maxlength="20" :placeholder="t('invoice.taxNo')" />
        <input v-model="form.email" class="input" maxlength="100" :placeholder="t('invoice.emailPh')" />
        <template v-if="form.title_type === 2">
          <view class="more" @click="more = !more">{{ t('invoice.more') }}<text class="sub">　{{ more ? '−' : '+' }}</text></view>
          <template v-if="more">
            <input v-model="form.company_info.address" class="input" maxlength="100" :placeholder="t('invoice.address')" />
            <input v-model="form.company_info.phone" class="input" maxlength="30" :placeholder="t('invoice.phone')" />
            <input v-model="form.company_info.bank" class="input" maxlength="100" :placeholder="t('invoice.bank')" />
            <input v-model="form.company_info.bank_account" class="input" maxlength="40" :placeholder="t('invoice.bankAccount')" />
          </template>
        </template>
        <view class="btn submit" :class="{ disabled: saving }" @click="submit">{{ t('invoice.submit') }}</view>
        <view v-if="editing" class="back sub" @click="load">{{ t('common.cancel') }}</view>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
.page { padding-bottom: 60rpx; }
.kv { display: flex; justify-content: space-between; padding: 10rpx 0; }
.val { max-width: 70%; text-align: right; word-break: break-all; }
.st { font-weight: 600; color: var(--theme); }
.st.s1 { color: var(--sage); }
.st.s2, .st.s3 { color: var(--sub); }
.view-btn { margin-top: 24rpx; }
.ops { display: flex; justify-content: flex-end; gap: 16rpx; margin-top: 24rpx; }
.notice { margin-top: 16rpx; padding: 16rpx 20rpx; border-radius: 12rpx; background: var(--bg); color: var(--sub); font-size: 24rpx; line-height: 1.7; white-space: pre-wrap; }
.types { display: flex; gap: 20rpx; margin-bottom: 12rpx; }
.type { padding: 10rpx 44rpx; border-radius: 36rpx; border: 2rpx solid #ded6c8; font-size: 26rpx; }
.type.on { border-color: var(--theme); color: var(--theme); }
.input { height: 92rpx; border-bottom: 2rpx solid var(--line); font-size: 28rpx; }
.more { padding: 24rpx 0 4rpx; color: var(--sub); font-size: 26rpx; }
.submit { margin-top: 36rpx; }
.back { text-align: center; padding: 28rpx 0 4rpx; }
</style>
