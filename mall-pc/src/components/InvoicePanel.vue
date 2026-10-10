<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { getInvoice, applyInvoice, cancelInvoice } from '@/api'
import { money, confirm } from '@/utils/app'

// 订单详情里的“发票”一栏：付款后申请，商家开出前可以修改或撤销；开出后可以打开发票文件
const props = defineProps({ orderNo: { type: String, required: true } })
const { t } = useI18n()

const info = ref(null)
const dialog = ref(false)
const saving = ref(false)
const emptyForm = () => ({ title_type: 1, title: '', tax_no: '', email: '', company_info: { address: '', phone: '', bank: '', bank_account: '' } })
const form = reactive(emptyForm())

const inv = computed(() => info.value?.invoice || null)
const canCancel = computed(() => inv.value && [0, 2].includes(inv.value.status))

async function load() {
  info.value = await getInvoice(props.orderNo)
}
function open() {
  // 没申请过：带出上次在别的订单上填的抬头
  const src = inv.value || info.value.last
  Object.assign(form, emptyForm(), src ? { title_type: src.title_type, title: src.title, tax_no: src.tax_no, email: src.email, company_info: { ...emptyForm().company_info, ...(src.company_info || {}) } } : {})
  dialog.value = true
}
async function submit() {
  if (!form.title.trim()) return ElMessage.error(t('invoice.needName'))
  if (form.title_type === 2 && !form.tax_no.trim()) return ElMessage.error(t('invoice.needTaxNo'))
  if (!form.email.trim()) return ElMessage.error(t('invoice.needEmail'))
  saving.value = true
  try {
    await applyInvoice(props.orderNo, { ...form, company_info: form.title_type === 2 ? form.company_info : {} })
    ElMessage.success(t('invoice.submitted'))
    dialog.value = false
    await load()
  } finally {
    saving.value = false
  }
}
async function onCancel() {
  if (!(await confirm(t('invoice.cancelConfirm')))) return
  await cancelInvoice(props.orderNo)
  ElMessage.success(t('invoice.cancelled'))
  load()
}

onMounted(load)
</script>

<template>
  <div v-if="info && (inv || info.can_apply)" class="panel invoice">
    <div class="panel-title">{{ t('invoice.title') }}
      <el-tag v-if="inv" size="small" :type="['warning', 'success', 'info', 'info'][inv.status]" class="tag">{{ t(`invoice.st${inv.status}`) }}</el-tag>
    </div>
    <div v-if="!inv" class="row">
      <span class="sub">{{ t('invoice.none') }}</span>
      <el-button type="primary" plain @click="open">{{ t('invoice.apply') }}</el-button>
    </div>
    <template v-else>
      <div class="row">
        <div class="info">
          <div>{{ inv.title }}<span v-if="inv.tax_no" class="sub">　{{ inv.tax_no }}</span></div>
          <div class="sub">{{ t('invoice.amount') }} {{ money(inv.status === 1 ? inv.amount : info.amount, info.currency) }}　{{ t('invoice.email') }} {{ inv.email }}</div>
          <div v-if="inv.invoice_no" class="sub">{{ t('invoice.invoiceNo') }} {{ inv.invoice_no }}</div>
          <div v-if="inv.reject_reason" class="reason">{{ t('invoice.reason') }}：{{ inv.reject_reason }}</div>
        </div>
        <div class="ops">
          <a v-if="inv.file_url" :href="inv.file_url" target="_blank" rel="noopener" class="file"><el-button type="primary">{{ t('invoice.view') }}</el-button></a>
          <el-button v-if="info.can_apply" type="primary" plain @click="open">{{ t(inv.status === 0 ? 'invoice.edit' : 'invoice.reapply') }}</el-button>
          <el-button v-if="canCancel" @click="onCancel">{{ t('invoice.cancel') }}</el-button>
        </div>
      </div>
    </template>

    <el-dialog v-model="dialog" :title="t(inv ? 'invoice.edit' : 'invoice.apply')" width="520px" destroy-on-close>
      <div class="amount">{{ t('invoice.amount') }}：<span class="price">{{ money(info.amount, info.currency) }}</span><span class="sub">　{{ t('invoice.amountTip') }}</span></div>
      <div v-if="info.notice" class="notice">{{ info.notice }}</div>
      <el-form label-position="top">
        <el-form-item :label="t('invoice.type')">
          <el-radio-group v-model="form.title_type">
            <el-radio :value="1">{{ t('invoice.personal') }}</el-radio>
            <el-radio :value="2">{{ t('invoice.company') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('invoice.name')" required><el-input v-model="form.title" maxlength="100" :placeholder="t(form.title_type === 2 ? 'invoice.companyPh' : 'invoice.namePh')" /></el-form-item>
        <el-form-item v-if="form.title_type === 2" :label="t('invoice.taxNo')" required><el-input v-model="form.tax_no" maxlength="20" /></el-form-item>
        <el-form-item :label="t('invoice.email')" required><el-input v-model="form.email" maxlength="100" :placeholder="t('invoice.emailPh')" /></el-form-item>
        <template v-if="form.title_type === 2">
          <div class="sub more">{{ t('invoice.more') }}</div>
          <div class="grid">
            <el-input v-model="form.company_info.address" maxlength="100" :placeholder="t('invoice.address')" />
            <el-input v-model="form.company_info.phone" maxlength="30" :placeholder="t('invoice.phone')" />
            <el-input v-model="form.company_info.bank" maxlength="100" :placeholder="t('invoice.bank')" />
            <el-input v-model="form.company_info.bank_account" maxlength="40" :placeholder="t('invoice.bankAccount')" />
          </div>
        </template>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :loading="saving" @click="submit">{{ t('invoice.submit') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.tag { margin-left: 10px; vertical-align: 2px; }
.row { display: flex; justify-content: space-between; align-items: center; gap: 20px; }
.info { min-width: 0; line-height: 1.9; word-break: break-all; }
.reason { color: var(--theme); font-size: 13px; }
.ops { display: flex; gap: 12px; flex: none; }
.ops .el-button { margin-left: 0; }
.amount { margin-bottom: 12px; }
.notice { margin-bottom: 16px; padding: 10px 14px; border-radius: 8px; background: var(--bg); color: var(--sub); font-size: 13px; line-height: 1.7; white-space: pre-wrap; }
.more { margin-bottom: 8px; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
</style>
