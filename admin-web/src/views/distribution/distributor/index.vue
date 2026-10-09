<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listDistributor, auditDistributor, setDistributorStatus, appointDistributor, getRelation, setRelation } from '@/api/distribution'
import { hasPerm } from '@/utils/perm'

// 分销员状态：0待审核 1正常 2已拒绝 3已禁用
const query = reactive({ keyword: '', status: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listDistributor(query)
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
const statusType = (s) => ({ 0: 'warning', 1: 'success', 2: 'info', 3: 'danger' })[s] || 'info'

async function onPass(row) {
  await ElMessageBox.confirm(`通过「${row.member}」的分销员申请？`, '审核', { type: 'warning' })
  await auditDistributor(row.member_id, { pass: 1 })
  ElMessage.success('已通过')
  load()
}
async function onReject(row) {
  const { value } = await ElMessageBox.prompt(`拒绝「${row.member}」的申请，请填写原因（申请人可见）`, '拒绝申请', { inputPattern: /^[\s\S]{1,255}$/, inputErrorMessage: '请填写 1 到 255 字的原因' })
  await auditDistributor(row.member_id, { pass: 0, reject_reason: value })
  ElMessage.success('已拒绝')
  load()
}
async function onToggle(row) {
  const enable = row.status === 3
  await ElMessageBox.confirm(enable ? `恢复「${row.member}」的分销资格？` : `停用「${row.member}」的分销资格？停用后不再产生新佣金，已有佣金和提现照常处理。`, enable ? '恢复' : '停用', { type: 'warning' })
  await setDistributorStatus(row.member_id, enable ? 1 : 0)
  ElMessage.success(enable ? '已恢复' : '已停用')
  load()
}

// 指定分销员 / 调整上级：按会员 ID 操作
const tool = reactive({ show: false, mode: 'appoint', memberId: null, parentId: 0, info: null, saving: false })
function openTool(mode, row) {
  Object.assign(tool, { show: true, mode, memberId: row?.member_id || null, parentId: row?.parent_id || 0, info: null, saving: false })
  if (row && mode === 'parent') lookup()
}
async function lookup() {
  if (!tool.memberId) return
  tool.info = await getRelation(tool.memberId)
  tool.parentId = tool.info.parent_id
}
async function submitTool() {
  if (!tool.memberId) return ElMessage.warning('请填写会员 ID')
  tool.saving = true
  try {
    if (tool.mode === 'appoint') await appointDistributor(tool.memberId)
    else await setRelation(tool.memberId, tool.parentId || 0)
    ElMessage.success('操作成功')
    tool.show = false
    load()
  } finally {
    tool.saving = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="昵称 / 账号 / 手机 / 姓名 / 会员 ID" clearable style="width: 260px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="全部状态" clearable style="width: 130px" @change="search">
            <el-option label="待审核" :value="0" /><el-option label="正常" :value="1" /><el-option label="已拒绝" :value="2" /><el-option label="已禁用" :value="3" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="search">查询</el-button>
          <el-button v-perm="'distribution:distributor:audit'" type="success" @click="openTool('appoint')">指定分销员</el-button>
          <el-button v-perm="'distribution:distributor:edit'" @click="openTool('parent')">调整会员上级</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="会员" min-width="180">
          <template #default="{ row }"><div>{{ row.member }}<span class="sub">　ID {{ row.member_id }}</span></div><div class="sub">{{ row.real_name }}<span v-if="row.apply_remark">　{{ row.apply_remark }}</span></div></template>
        </el-table-column>
        <el-table-column prop="invite_code" label="邀请码" width="100" />
        <el-table-column label="上级" min-width="130"><template #default="{ row }">{{ row.parent || '—' }}</template></el-table-column>
        <el-table-column prop="team_count" label="直接下级" width="90" />
        <el-table-column prop="pending_amount" label="待结算" width="100" />
        <el-table-column prop="available_amount" label="可提现" width="100" />
        <el-table-column prop="frozen_amount" label="提现中" width="100" />
        <el-table-column prop="withdrawn_amount" label="已提现" width="100" />
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)">{{ row.status_text }}</el-tag>
            <div v-if="row.status === 2 && row.reject_reason" class="sub">{{ row.reject_reason }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="申请时间" min-width="160" />
        <el-table-column label="操作" width="190" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 0 && hasPerm('distribution:distributor:audit')">
              <el-button link type="primary" @click="onPass(row)">通过</el-button>
              <el-button link type="danger" @click="onReject(row)">拒绝</el-button>
            </template>
            <el-button v-if="[1, 3].includes(row.status) && hasPerm('distribution:distributor:status')" link :type="row.status === 3 ? 'success' : 'danger'" @click="onToggle(row)">{{ row.status === 3 ? '恢复' : '停用' }}</el-button>
            <el-button v-perm="'distribution:distributor:edit'" link @click="openTool('parent', row)">改上级</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="tool.show" :title="tool.mode === 'appoint' ? '指定分销员' : '调整会员上级'" width="480px" destroy-on-close>
      <el-form label-width="100px">
        <el-form-item label="会员 ID" required>
          <el-input-number v-model="tool.memberId" :min="1" :controls="false" style="width: 160px" />
          <el-button v-if="tool.mode === 'parent'" style="margin-left: 8px" @click="lookup">查询当前上级</el-button>
        </el-form-item>
        <template v-if="tool.mode === 'parent'">
          <el-form-item v-if="tool.info" label="当前"><span>{{ tool.info.member }} 的上级：{{ tool.info.parent ? `${tool.info.parent}（ID ${tool.info.parent_id}）` : '无' }}</span></el-form-item>
          <el-form-item label="新上级 ID">
            <el-input-number v-model="tool.parentId" :min="0" :controls="false" style="width: 160px" />
            <span class="sub" style="margin-left: 8px">填 0 表示清除上级</span>
          </el-form-item>
          <div class="sub" style="padding-left: 100px">只影响之后支付的订单；已生成的佣金不变。</div>
        </template>
        <div v-else class="sub" style="padding-left: 100px">跳过申请，直接把该会员设为分销员。会员 ID 可在“会员列表”查看。</div>
      </el-form>
      <template #footer>
        <el-button @click="tool.show = false">取消</el-button>
        <el-button type="primary" :loading="tool.saving" @click="submitTool">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; }
</style>
