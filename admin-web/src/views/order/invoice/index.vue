<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listInvoice, issueInvoice, rejectInvoice, voidInvoice } from '@/api/invoice'
import { uploadInvoiceFile } from '@/api/upload'

// 发票管理：买家付款后申请，商家在自己的开票软件里开好，在这里登记发票号码、上传文件。商城不对接税控
const STATUS = { 0: ['待开票', 'warning'], 1: ['已开票', 'success'], 2: ['已驳回', 'info'], 3: ['已作废', 'danger'] }
const COMPANY = { address: '注册地址', phone: '注册电话', bank: '开户银行', bank_account: '银行账号' }

const query = reactive({ status: 0, keyword: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listInvoice(query)
    list.value = d.list
    total.value = d.total
  } finally {
    loading.value = false
  }
}
function search() {
  query.page = 1
  load()
}
const companyLines = (r) => Object.keys(COMPANY).filter((k) => r.company_info?.[k]).map((k) => `${COMPANY[k]}：${r.company_info[k]}`)

// ---- 开票
const issue = reactive({ show: false, row: null, invoice_no: '', file_url: '', file_name: '', amount: 0, uploading: false, saving: false })
function openIssue(r) {
  Object.assign(issue, { show: true, row: r, invoice_no: '', file_url: '', file_name: '', amount: Number(r.invoiceable), uploading: false, saving: false })
}
function beforeUpload(file) {
  if (!['application/pdf', 'image/jpeg', 'image/png'].includes(file.type)) {
    ElMessage.error('仅支持 PDF、jpg、png')
    return false
  }
  if (file.size > 5 * 1024 * 1024) {
    ElMessage.error('文件不能超过 5MB')
    return false
  }
  return true
}
async function upload({ file }) {
  issue.uploading = true
  try {
    const d = await uploadInvoiceFile(file)
    issue.file_url = d.url
    issue.file_name = d.name
  } finally {
    issue.uploading = false
  }
}
async function submitIssue() {
  if (!issue.invoice_no.trim()) return ElMessage.error('请填写发票号码')
  if (!issue.file_url) return ElMessage.error('请上传发票文件')
  issue.saving = true
  try {
    await issueInvoice(issue.row.id, { invoice_no: issue.invoice_no.trim(), file_url: issue.file_url, amount: issue.amount })
    ElMessage.success('已登记开票，买家会收到通知')
    issue.show = false
    load()
  } finally {
    issue.saving = false
  }
}

// ---- 驳回、作废：都要填原因，买家看得到
async function askReason(title, tip) {
  const { value } = await ElMessageBox.prompt(tip, title, {
    inputType: 'textarea', inputPlaceholder: '买家能看到这段话', inputValidator: (v) => (v && v.trim() ? (v.trim().length <= 200 || '最多 200 个字') : '请填写原因'),
  })
  return value.trim()
}
async function reject(r) {
  const reason = await askReason('驳回发票申请', `驳回「${r.title}」的发票申请，买家可以修改后重新提交。`)
  await rejectInvoice(r.id, reason)
  ElMessage.success('已驳回')
  load()
}
async function voidIt(r) {
  const reason = await askReason('作废发票', `把发票 ${r.invoice_no} 标为已作废。这里只是登记，税务上的作废或红冲请在开票软件里操作。`)
  await voidInvoice(r.id, reason)
  ElMessage.success('已作废')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px"
        title="商城不对接税控和电子发票平台：请在自己的开票软件里开好发票，再到这里登记发票号码并上传文件，买家就能在订单详情里查看和下载。是否允许买家申请发票在“设置 → 交易设置”里开关。" />
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item>
          <el-select v-model="query.status" placeholder="全部状态" clearable style="width: 120px" @change="search">
            <el-option v-for="(s, k) in STATUS" :key="k" :label="s[0]" :value="Number(k)" />
          </el-select>
        </el-form-item>
        <el-form-item><el-input v-model="query.keyword" placeholder="订单号 / 抬头 / 发票号码" clearable style="width: 240px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="order_no" label="订单号" width="200" />
        <el-table-column label="抬头" min-width="260">
          <template #default="{ row }">
            <div><el-tag size="small" :type="row.title_type === 2 ? 'primary' : 'info'">{{ row.title_type === 2 ? '企业' : '个人' }}</el-tag> {{ row.title }}</div>
            <div v-if="row.tax_no" class="sub">税号：{{ row.tax_no }}</div>
            <div v-for="l in companyLines(row)" :key="l" class="sub">{{ l }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="email" label="收票邮箱" min-width="180" show-overflow-tooltip />
        <el-table-column label="金额" width="150">
          <template #default="{ row }">
            <div>{{ row.status === 1 ? row.amount : row.invoiceable }}</div>
            <div v-if="row.invoiceable !== row.pay_amount" class="sub">实付 {{ row.pay_amount }}，有退款</div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="170">
          <template #default="{ row }">
            <el-tag :type="STATUS[row.status][1]">{{ STATUS[row.status][0] }}</el-tag>
            <div v-if="row.invoice_no" class="sub">{{ row.invoice_no }}</div>
            <div v-if="row.reject_reason" class="sub">{{ row.reject_reason }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="申请时间" width="170" />
        <el-table-column label="操作" width="170" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 0">
              <el-button v-perm="'order:invoice:issue'" link type="primary" @click="openIssue(row)">开票</el-button>
              <el-button v-perm="'order:invoice:reject'" link type="warning" @click="reject(row)">驳回</el-button>
            </template>
            <a v-if="row.file_url" class="file" :href="row.file_url" target="_blank" rel="noopener">查看文件</a>
            <el-button v-if="row.status === 1" v-perm="'order:invoice:void'" link type="danger" @click="voidIt(row)">作废</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="issue.show" title="登记开票" width="560px" destroy-on-close>
      <el-form v-if="issue.row" label-width="90px">
        <el-form-item label="抬头">
          <div>
            <div>{{ issue.row.title }}</div>
            <div v-if="issue.row.tax_no" class="sub">税号：{{ issue.row.tax_no }}</div>
            <div v-for="l in companyLines(issue.row)" :key="l" class="sub">{{ l }}</div>
          </div>
        </el-form-item>
        <el-form-item label="开票金额" required>
          <el-input-number v-model="issue.amount" :min="0.01" :max="Number(issue.row.invoiceable)" :precision="2" :controls="false" />
          <span class="unit">最多 {{ issue.row.invoiceable }}（实付减去已退款）</span>
        </el-form-item>
        <el-form-item label="发票号码" required><el-input v-model="issue.invoice_no" maxlength="40" placeholder="开票软件给出的发票号码" /></el-form-item>
        <el-form-item label="发票文件" required>
          <el-upload :show-file-list="false" :before-upload="beforeUpload" :http-request="upload" accept=".pdf,.jpg,.jpeg,.png">
            <el-button :loading="issue.uploading">{{ issue.file_url ? '重新上传' : '选择文件' }}</el-button>
          </el-upload>
          <a v-if="issue.file_url" class="file picked" :href="issue.file_url" target="_blank" rel="noopener">{{ issue.file_name || '已上传' }}</a>
          <div class="sub" style="width: 100%">PDF、jpg 或 png，5MB 以内。登记后买家在订单详情里能打开这个文件。</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="issue.show = false">取消</el-button>
        <el-button type="primary" :loading="issue.saving" @click="submitIssue">确认开票</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; line-height: 1.6; }
.unit { margin-left: 10px; color: #909399; font-size: 13px; }
.file { color: var(--el-color-primary); font-size: 14px; margin-right: 12px; }
.file.picked { margin-left: 12px; }
</style>
