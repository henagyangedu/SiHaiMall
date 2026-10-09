<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { state } from '@/store'
import { getDistSummary, applyDistributor, getCommissions, getTeam, getWithdraws, applyWithdraw } from '@/api'
import { money } from '@/utils/app'

const { t } = useI18n()
// 分销员状态：null 未申请，0 待审核，1 正常，2 已拒绝，3 已停用。佣金金额都是基准币种
const s = ref(null)
const base = computed(() => state.config.base_currency)
const tab = ref('overview')

async function loadSummary() {
  s.value = await getDistSummary()
}

// ---- 申请成为分销员
const apply = reactive({ real_name: '', remark: '', saving: false })
async function onApply() {
  if (apply.saving) return
  if (!apply.real_name.trim()) return ElMessage.warning(t('dist.needName'))
  apply.saving = true
  try {
    await applyDistributor({ real_name: apply.real_name.trim(), remark: apply.remark.trim() })
    loadSummary()
  } finally {
    apply.saving = false
  }
}

// ---- 邀请
const inviteLink = computed(() => `${window.location.origin}${window.location.pathname}#/login?invite=${s.value?.invite_code || ''}`)
async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success(t('dist.copied'))
  } catch (e) {
    ElMessage.info(text) // 浏览器不允许写剪贴板时把内容显示出来，方便手动复制
  }
}

// ---- 佣金明细 / 团队 / 提现记录：三个列表结构一样，各自分页
const lists = reactive({
  commissions: { rows: [], no: 1, size: 10, total: 0, loading: false },
  team: { rows: [], no: 1, size: 10, total: 0, loading: false, level: 1 },
  withdraws: { rows: [], no: 1, size: 10, total: 0, loading: false },
})
async function loadList(key) {
  const l = lists[key]
  l.loading = true
  try {
    const params = { page: l.no, size: l.size }
    const d = key === 'commissions' ? await getCommissions(params) : key === 'team' ? await getTeam({ ...params, level: l.level }) : await getWithdraws(params)
    l.rows = d.list
    l.total = d.total
  } finally {
    l.loading = false
  }
}
function onTab(name) {
  tab.value = name
  if (name === 'commissions' || name === 'team') loadList(name)
  if (name === 'withdraw') loadList('withdraws')
}
function pickLevel(n) {
  Object.assign(lists.team, { level: n, no: 1 })
  loadList('team')
}

// ---- 提现
const METHODS = [
  { key: 'bank', label: 'dist.methodBank' }, { key: 'paypal', label: 'dist.methodPaypal' }, { key: 'alipay', label: 'dist.methodAlipay' },
  { key: 'wechat', label: 'dist.methodWechat' }, { key: 'other', label: 'dist.methodOther' },
]
const wd = reactive({ amount: '', method: 'bank', name: '', no: '', bank: '', saving: false })
async function onWithdraw() {
  if (wd.saving) return
  const amount = Number(wd.amount)
  if (!amount || amount <= 0) return ElMessage.warning(t('dist.needAmount'))
  if (!wd.name.trim() || !wd.no.trim()) return ElMessage.warning(t('dist.needAccount'))
  if (wd.method === 'bank' && !wd.bank.trim()) return ElMessage.warning(t('dist.needBank'))
  wd.saving = true
  try {
    const account = { name: wd.name.trim(), no: wd.no.trim(), ...(wd.bank.trim() ? { bank: wd.bank.trim() } : {}) }
    await applyWithdraw({ amount: String(wd.amount), method: wd.method, account })
    ElMessage.success(t('dist.withdrawSent'))
    wd.amount = ''
    loadSummary()
    loadList('withdraws')
  } finally {
    wd.saving = false
  }
}

onMounted(loadSummary)
</script>

<template>
  <div v-if="s" class="panel">
    <div class="panel-title">{{ t('dist.title') }}</div>

    <div v-if="!s.enabled && s.status === null" class="empty">{{ t('dist.closed') }}</div>

    <!-- 还没申请，或被拒绝后重新申请 -->
    <div v-else-if="s.status === null || s.status === 2" class="apply">
      <h3>{{ t('dist.applyTitle') }}</h3>
      <p class="sub">{{ t('dist.applyTip') }}</p>
      <p class="sub">{{ t('dist.rate', { a: Number(s.rate_1) }) }}<template v-if="s.level === 2">{{ t('dist.rate2', { b: Number(s.rate_2) }) }}</template></p>
      <el-alert v-if="s.status === 2" type="error" :closable="false" :title="t('dist.rejected') + (s.reject_reason ? `：${s.reject_reason}` : '')" class="alert" />
      <el-input v-model="apply.real_name" maxlength="60" :placeholder="t('dist.realName')" class="field" />
      <el-input v-model="apply.remark" maxlength="255" :placeholder="t('dist.remark')" class="field" />
      <el-button type="primary" :loading="apply.saving" @click="onApply">{{ t(s.status === 2 ? 'dist.reapply' : 'dist.submitApply') }}</el-button>
    </div>

    <div v-else-if="s.status === 0" class="empty">{{ t('dist.pending') }}</div>

    <template v-else>
      <el-alert v-if="s.status === 3" type="warning" :closable="false" :title="t('dist.disabled')" class="alert" />
      <el-tabs :model-value="tab" @tab-change="onTab">
        <el-tab-pane :label="t('pc.distOverview')" name="overview">
          <div class="stats">
            <div class="stat main"><div class="n">{{ money(s.available_amount, base) }}</div><div class="sub">{{ t('dist.available') }}</div></div>
            <div class="stat"><div class="n">{{ money(s.pending_amount, base) }}</div><div class="sub">{{ t('dist.pendingAmount') }}</div></div>
            <div class="stat"><div class="n">{{ money(s.frozen_amount, base) }}</div><div class="sub">{{ t('dist.frozen') }}</div></div>
            <div class="stat"><div class="n">{{ money(s.withdrawn_amount, base) }}</div><div class="sub">{{ t('dist.withdrawn') }}</div></div>
          </div>
          <div class="invite">
            <div class="kv"><span class="sub">{{ t('dist.inviteCode') }}</span><span><b class="code">{{ s.invite_code }}</b><a class="link op" @click="copy(s.invite_code)">{{ t('dist.copy') }}</a></span></div>
            <div class="kv"><span class="sub">{{ t('pc.inviteLink') }}</span><span class="ellipsis url">{{ inviteLink }}<a class="link op" @click="copy(inviteLink)">{{ t('dist.copy') }}</a></span></div>
            <div class="sub">{{ t('dist.inviteTip') }}　{{ t('dist.rate', { a: Number(s.rate_1) }) }}<template v-if="s.level === 2">{{ t('dist.rate2', { b: Number(s.rate_2) }) }}</template></div>
            <div class="sub">{{ t('dist.team1') }} {{ s.team_level1 }}<template v-if="s.level === 2">　{{ t('dist.team2') }} {{ s.team_level2 }}</template></div>
          </div>
        </el-tab-pane>

        <el-tab-pane :label="t('dist.commissions')" name="commissions">
          <el-table v-loading="lists.commissions.loading" :data="lists.commissions.rows" :empty-text="t('common.empty')">
            <el-table-column :label="t('pc.timeCol')" prop="created_at" width="170" />
            <el-table-column :label="t('pc.orderCol')" prop="order_no" min-width="190" />
            <el-table-column :label="t('pc.buyerCol')" prop="buyer" min-width="110" />
            <el-table-column :label="t('pc.levelCol')" width="80"><template #default="{ row }">{{ t('dist.level', { n: row.level }) }}</template></el-table-column>
            <el-table-column :label="t('pc.baseCol')" width="120" align="right"><template #default="{ row }">{{ money(row.base_amount, base) }}</template></el-table-column>
            <el-table-column :label="t('pc.rateCol')" width="80" align="right"><template #default="{ row }">{{ Number(row.rate) }}%</template></el-table-column>
            <el-table-column :label="t('pc.commissionCol')" width="120" align="right"><template #default="{ row }"><span class="price">{{ money(row.amount, base) }}</span></template></el-table-column>
            <el-table-column :label="t('pc.statusCol')" width="100"><template #default="{ row }">{{ t(`commissionStatus.${row.status}`) }}</template></el-table-column>
          </el-table>
          <div v-if="lists.commissions.total > lists.commissions.size" class="pager">
            <el-pagination v-model:current-page="lists.commissions.no" :page-size="lists.commissions.size" :total="lists.commissions.total" layout="prev, pager, next" background @current-change="loadList('commissions')" />
          </div>
        </el-tab-pane>

        <el-tab-pane :label="t('dist.team')" name="team">
          <el-radio-group v-if="s.level === 2" :model-value="lists.team.level" class="levels" @change="pickLevel">
            <el-radio-button :value="1">{{ t('dist.team1') }} {{ s.team_level1 }}</el-radio-button>
            <el-radio-button :value="2">{{ t('dist.team2') }} {{ s.team_level2 }}</el-radio-button>
          </el-radio-group>
          <el-table v-loading="lists.team.loading" :data="lists.team.rows" :empty-text="t('common.empty')">
            <el-table-column :label="t('pc.memberCol')" prop="nickname" min-width="200" />
            <el-table-column :label="t('dist.joinedAt')" prop="joined_at" width="200" />
          </el-table>
          <div v-if="lists.team.total > lists.team.size" class="pager">
            <el-pagination v-model:current-page="lists.team.no" :page-size="lists.team.size" :total="lists.team.total" layout="prev, pager, next" background @current-change="loadList('team')" />
          </div>
        </el-tab-pane>

        <el-tab-pane :label="t('dist.withdraw')" name="withdraw">
          <div class="sub">{{ t('dist.minTip', { amount: money(s.min_withdraw, base), available: money(s.available_amount, base) }) }}</div>
          <el-form label-width="110px" class="wd">
            <el-form-item :label="t('dist.amount')" required><el-input v-model="wd.amount" maxlength="12" style="width: 200px" /></el-form-item>
            <el-form-item :label="t('dist.method')" required>
              <el-select v-model="wd.method" style="width: 200px"><el-option v-for="m in METHODS" :key="m.key" :label="t(m.label)" :value="m.key" /></el-select>
            </el-form-item>
            <el-form-item :label="t('dist.accountName')" required><el-input v-model="wd.name" maxlength="100" /></el-form-item>
            <el-form-item :label="t('dist.accountNo')" required><el-input v-model="wd.no" maxlength="100" /></el-form-item>
            <el-form-item v-if="wd.method === 'bank'" :label="t('dist.bank')" required><el-input v-model="wd.bank" maxlength="100" /></el-form-item>
            <el-form-item><el-button type="primary" :loading="wd.saving" @click="onWithdraw">{{ t('dist.submitWithdraw') }}</el-button></el-form-item>
          </el-form>

          <h4>{{ t('dist.history') }}</h4>
          <el-table v-loading="lists.withdraws.loading" :data="lists.withdraws.rows" :empty-text="t('common.empty')">
            <el-table-column :label="t('pc.timeCol')" prop="created_at" width="170" />
            <el-table-column :label="t('pc.withdrawNoCol')" prop="withdraw_no" min-width="190" />
            <el-table-column :label="t('dist.amount')" width="130" align="right"><template #default="{ row }">{{ money(row.amount, base) }}</template></el-table-column>
            <el-table-column :label="t('pc.statusCol')" width="110"><template #default="{ row }">{{ t(`withdrawStatus.${row.status}`) }}</template></el-table-column>
            <el-table-column :label="t('pc.reasonCol')" prop="reject_reason" min-width="160" />
          </el-table>
          <div v-if="lists.withdraws.total > lists.withdraws.size" class="pager">
            <el-pagination v-model:current-page="lists.withdraws.no" :page-size="lists.withdraws.size" :total="lists.withdraws.total" layout="prev, pager, next" background @current-change="loadList('withdraws')" />
          </div>
        </el-tab-pane>
      </el-tabs>
    </template>
  </div>
</template>

<style scoped>
.apply { max-width: 520px; }
.apply h3 { margin: 0 0 8px; }
.field { margin-bottom: 14px; }
.alert { margin-bottom: 14px; }
.stats { display: flex; gap: 16px; margin-bottom: 20px; }
.stat { flex: 1; background: #f7f8fa; border-radius: 8px; padding: 18px 20px; }
.stat.main { background: #fcf5f5; }
.n { font-size: 22px; font-weight: 600; margin-bottom: 4px; }
.stat.main .n { color: var(--theme); }
.invite { border: 1px solid var(--line); border-radius: 8px; padding: 14px 20px; line-height: 1.9; }
.code { font-size: 18px; letter-spacing: 2px; }
.op { margin-left: 14px; }
.url { max-width: 640px; }
.levels { margin-bottom: 14px; }
.wd { max-width: 520px; margin-top: 14px; }
</style>
