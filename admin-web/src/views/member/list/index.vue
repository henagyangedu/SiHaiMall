<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  listMember, getMember, updateMember, setMemberStatus, resetMemberPassword,
  adjustMemberPoints, adjustMemberBalance, memberBalanceLog, levelOptions, listPointLog, exportMembers,
} from '@/api/member'
import { languageOptions } from '@/api/language'
import { currencyOptions } from '@/api/option'
import { hasPerm } from '@/utils/perm'

const query = reactive({ keyword: '', level_id: '', status: '', source: '', range: [], page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)
const levels = ref([])
const languages = ref([])
const currencies = ref([])
const sources = [['h5', 'H5'], ['mp', '小程序'], ['app', 'App'], ['pc', 'PC']]
const sourceText = (v) => sources.find((s) => s[0] === v)?.[1] || v || '-'

function filters() {
  const [from, to] = query.range || []
  return { keyword: query.keyword, level_id: query.level_id, status: query.status, source: query.source, date_from: from || '', date_to: to || '' }
}
async function load() {
  loading.value = true
  try {
    const d = await listMember({ ...filters(), page: query.page, size: query.size })
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
const exporting = ref(false)
async function onExport() {
  exporting.value = true
  try {
    await exportMembers(filters())
  } catch (e) {
    ElMessage.error(e.message || '导出失败')
  } finally {
    exporting.value = false
  }
}
async function onStatus(row, val) {
  try {
    await setMemberStatus(row.id, val)
    ElMessage.success(val === 1 ? '已启用' : '已禁用')
  } finally {
    load()
  }
}

// ---------- 详情抽屉 ----------
const drawer = ref(false)
const detail = ref(null)
const tab = ref('info')
const pointLog = reactive({ list: [], total: 0, page: 1 })
const balLog = reactive({ list: [], total: 0, page: 1 })
async function openDetail(row) {
  detail.value = await getMember(row.id)
  tab.value = 'info'
  pointLog.page = 1
  balLog.page = 1
  pointLog.list = []
  balLog.list = []
  drawer.value = true
}
async function loadPointLog() {
  const d = await listPointLog({ member_id: detail.value.id, page: pointLog.page, size: 10 })
  pointLog.list = d.list
  pointLog.total = d.total
}
async function loadBalLog() {
  const d = await memberBalanceLog(detail.value.id, { page: balLog.page, size: 10 })
  balLog.list = d.list
  balLog.total = d.total
}
function onTab(name) {
  if (name === 'points') loadPointLog()
  if (name === 'balance') loadBalLog()
}
async function refreshDetail() {
  detail.value = await getMember(detail.value.id)
  if (tab.value === 'points') loadPointLog()
  if (tab.value === 'balance') loadBalLog()
}

// ---------- 编辑 ----------
const editDialog = ref(false)
const form = reactive({})
function openEdit(row) {
  Object.assign(form, {
    id: row.id, nickname: row.nickname, gender: row.gender ?? 0, birthday: row.birthday || '', level_id: row.level_id,
    phone_area: row.phone_area ?? '86', phone: row.phone_raw ?? '', email: row.email, lang: row.lang, currency: row.currency,
  })
  editDialog.value = true
}
async function openEditFromList(row) {
  openEdit(await getMember(row.id))
}
async function submitEdit() {
  const { id, ...payload } = form
  await updateMember(id, payload)
  ElMessage.success('保存成功')
  editDialog.value = false
  load()
  if (drawer.value && detail.value?.id === id) refreshDetail()
}

// ---------- 调积分 / 调余额 / 重置密码 ----------
const adjDialog = ref(false)
const adjKind = ref('points')
const adj = reactive({ id: 0, name: '', current: '', value: null, remark: '' })
function openAdjust(row, kind) {
  adjKind.value = kind
  Object.assign(adj, { id: row.id, name: row.nickname || row.account || `#${row.id}`, current: kind === 'points' ? row.points : row.balance, value: null, remark: '' })
  adjDialog.value = true
}
async function submitAdjust() {
  if (adj.value === null || adj.value === '' || Number(adj.value) === 0) return ElMessage.error('请输入调整数量（正数增加，负数扣减）')
  if (!adj.remark.trim()) return ElMessage.error('请填写备注')
  if (adjKind.value === 'points') await adjustMemberPoints(adj.id, { delta: adj.value, remark: adj.remark })
  else await adjustMemberBalance(adj.id, { amount: String(adj.value), remark: adj.remark })
  ElMessage.success('调整成功')
  adjDialog.value = false
  load()
  if (drawer.value && detail.value?.id === adj.id) refreshDetail()
}
async function onResetPassword(row) {
  const { value } = await ElMessageBox.prompt(`为「${row.nickname || row.account || '#' + row.id}」设置新密码（6-64 位）`, '重置密码', {
    inputType: 'password', inputPattern: /^.{6,64}$/, inputErrorMessage: '密码长度 6-64 位',
  })
  await resetMemberPassword(row.id, value)
  ElMessage.success('密码已重置')
}

onMounted(async () => {
  load()
  try { levels.value = await levelOptions() } catch (e) { /* 无权限 */ }
  try { languages.value = await languageOptions() } catch (e) { /* 无权限 */ }
  try { currencies.value = await currencyOptions() } catch (e) { /* 无权限 */ }
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="ID / 昵称 / 账号 / 手机 / 邮箱" clearable style="width: 240px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.level_id" placeholder="会员等级" clearable style="width: 130px" @change="search">
            <el-option v-for="l in levels" :key="l.id" :label="l.name" :value="l.id" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 100px" @change="search">
            <el-option label="正常" :value="1" /><el-option label="禁用" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.source" placeholder="注册来源" clearable style="width: 120px" @change="search">
            <el-option v-for="s in sources" :key="s[0]" :label="s[1]" :value="s[0]" />
          </el-select>
        </el-form-item>
        <el-form-item><el-date-picker v-model="query.range" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="注册开始" end-placeholder="注册结束" @change="search" /></el-form-item>
        <el-form-item>
          <el-button type="primary" @click="search">查询</el-button>
          <el-button v-perm="'member:list:export'" :loading="exporting" @click="onExport">导出 CSV</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="会员" min-width="180">
          <template #default="{ row }">
            <div style="display: flex; align-items: center; gap: 8px">
              <el-avatar :size="32" :src="row.avatar || undefined">{{ (row.nickname || row.account || '?').slice(0, 1) }}</el-avatar>
              <div><div>{{ row.nickname || '-' }}</div><div style="color: #909399; font-size: 12px">{{ row.account }}</div></div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="手机 / 邮箱" min-width="190">
          <template #default="{ row }"><div>{{ row.phone || '-' }}</div><div style="color: #909399; font-size: 12px">{{ row.email }}</div></template>
        </el-table-column>
        <el-table-column prop="level_name" label="等级" width="110" />
        <el-table-column prop="points" label="积分" width="90" />
        <el-table-column label="余额" width="110"><template #default="{ row }">{{ row.balance }}</template></el-table-column>
        <el-table-column label="来源" width="80"><template #default="{ row }">{{ sourceText(row.register_source) }}</template></el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" :disabled="!hasPerm('member:list:status')" @change="(v) => onStatus(row, v)" />
          </template>
        </el-table-column>
        <el-table-column prop="last_login_at" label="最后登录" width="165" />
        <el-table-column prop="created_at" label="注册时间" width="165" />
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'member:list:detail'" link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button v-perm="'member:list:edit'" link type="primary" @click="openEditFromList(row)">编辑</el-button>
            <el-dropdown v-if="hasPerm('member:list:points,member:list:balance,member:list:edit')" trigger="click" style="margin-left: 10px">
              <el-button link type="primary">更多</el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item v-if="hasPerm('member:list:points')" @click="openAdjust(row, 'points')">调整积分</el-dropdown-item>
                  <el-dropdown-item v-if="hasPerm('member:list:balance')" @click="openAdjust(row, 'balance')">调整余额</el-dropdown-item>
                  <el-dropdown-item v-if="hasPerm('member:list:edit')" @click="onResetPassword(row)">重置密码</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50, 100]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <!-- 详情 -->
    <el-drawer v-model="drawer" :title="detail ? `会员详情 #${detail.id}` : '会员详情'" size="720px" destroy-on-close>
      <template v-if="detail">
        <el-tabs v-model="tab" @tab-change="onTab">
          <el-tab-pane label="基本信息" name="info">
            <el-descriptions :column="2" border>
              <el-descriptions-item label="昵称">{{ detail.nickname || '-' }}</el-descriptions-item>
              <el-descriptions-item label="账号">{{ detail.account || '-' }}</el-descriptions-item>
              <el-descriptions-item label="手机">{{ detail.phone || '-' }}</el-descriptions-item>
              <el-descriptions-item label="邮箱">{{ detail.email || '-' }}</el-descriptions-item>
              <el-descriptions-item label="性别">{{ ['未知', '男', '女'][detail.gender] }}</el-descriptions-item>
              <el-descriptions-item label="生日">{{ detail.birthday || '-' }}</el-descriptions-item>
              <el-descriptions-item label="等级">{{ detail.level_name }}</el-descriptions-item>
              <el-descriptions-item label="状态"><el-tag :type="detail.status === 1 ? 'success' : 'danger'">{{ detail.status === 1 ? '正常' : '禁用' }}</el-tag></el-descriptions-item>
              <el-descriptions-item label="积分">{{ detail.points }}</el-descriptions-item>
              <el-descriptions-item label="余额（基准币种）">{{ detail.balance }}</el-descriptions-item>
              <el-descriptions-item label="语言 / 币种">{{ detail.lang }} / {{ detail.currency }}</el-descriptions-item>
              <el-descriptions-item label="注册来源">{{ sourceText(detail.register_source) }}</el-descriptions-item>
              <el-descriptions-item label="第三方绑定">{{ detail.bindings.length ? detail.bindings.join('、') : '无' }}</el-descriptions-item>
              <el-descriptions-item label="最后登录">{{ detail.last_login_at || '-' }}<span v-if="detail.last_login_ip" style="color: #909399"> ({{ detail.last_login_ip }})</span></el-descriptions-item>
              <el-descriptions-item label="注册时间" :span="2">{{ detail.created_at }}</el-descriptions-item>
            </el-descriptions>
            <div style="margin: 16px 0 8px; font-weight: 600">收货地址（{{ detail.addresses.length }}）</div>
            <el-table :data="detail.addresses" border size="small" empty-text="暂无地址">
              <el-table-column prop="name" label="收件人" width="100" />
              <el-table-column prop="phone" label="电话" width="140" />
              <el-table-column label="地址" min-width="260">
                <template #default="{ row }">{{ [row.country_code, row.province, row.city, row.district, row.address, row.address2].filter(Boolean).join(' ') }} <span v-if="row.postcode" style="color: #909399">{{ row.postcode }}</span></template>
              </el-table-column>
              <el-table-column label="默认" width="70"><template #default="{ row }"><el-tag v-if="row.is_default" size="small" type="success">默认</el-tag></template></el-table-column>
            </el-table>
            <div style="margin-top: 16px">
              <el-button v-perm="'member:list:points'" @click="openAdjust(detail, 'points')">调整积分</el-button>
              <el-button v-perm="'member:list:balance'" @click="openAdjust(detail, 'balance')">调整余额</el-button>
            </div>
          </el-tab-pane>
          <el-tab-pane label="积分记录" name="points">
            <el-table :data="pointLog.list" border size="small" empty-text="暂无记录">
              <el-table-column prop="type_text" label="类型" width="100" />
              <el-table-column label="变动" width="90"><template #default="{ row }"><b :style="{ color: row.change_points > 0 ? '#67c23a' : '#f56c6c' }">{{ row.change_points > 0 ? '+' : '' }}{{ row.change_points }}</b></template></el-table-column>
              <el-table-column prop="after_points" label="变动后" width="90" />
              <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip />
              <el-table-column prop="operator" label="操作人" width="90" />
              <el-table-column prop="created_at" label="时间" width="160" />
            </el-table>
            <el-pagination v-model:current-page="pointLog.page" :total="pointLog.total" :page-size="10" layout="total, prev, pager, next" small style="margin-top: 8px" @current-change="loadPointLog" />
          </el-tab-pane>
          <el-tab-pane label="余额记录" name="balance">
            <el-table :data="balLog.list" border size="small" empty-text="暂无记录">
              <el-table-column prop="type_text" label="类型" width="100" />
              <el-table-column label="变动" width="110"><template #default="{ row }"><b :style="{ color: Number(row.change_amount) > 0 ? '#67c23a' : '#f56c6c' }">{{ Number(row.change_amount) > 0 ? '+' : '' }}{{ row.change_amount }}</b></template></el-table-column>
              <el-table-column prop="after_amount" label="变动后" width="110" />
              <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip />
              <el-table-column prop="created_at" label="时间" width="160" />
            </el-table>
            <el-pagination v-model:current-page="balLog.page" :total="balLog.total" :page-size="10" layout="total, prev, pager, next" small style="margin-top: 8px" @current-change="loadBalLog" />
          </el-tab-pane>
        </el-tabs>
      </template>
    </el-drawer>

    <!-- 编辑 -->
    <el-dialog v-model="editDialog" title="编辑会员" width="560px" destroy-on-close>
      <el-form :model="form" label-width="90px">
        <el-form-item label="昵称"><el-input v-model="form.nickname" maxlength="60" /></el-form-item>
        <el-form-item label="性别">
          <el-radio-group v-model="form.gender"><el-radio :value="0">未知</el-radio><el-radio :value="1">男</el-radio><el-radio :value="2">女</el-radio></el-radio-group>
        </el-form-item>
        <el-form-item label="生日"><el-date-picker v-model="form.birthday" type="date" value-format="YYYY-MM-DD" placeholder="可选" /></el-form-item>
        <el-form-item label="会员等级" required>
          <el-select v-model="form.level_id" style="width: 200px"><el-option v-for="l in levels" :key="l.id" :label="l.name + (l.status === 1 ? '' : '（已禁用）')" :value="l.id" /></el-select>
        </el-form-item>
        <el-form-item label="手机">
          <el-input v-model="form.phone_area" style="width: 90px" maxlength="8"><template #prefix>+</template></el-input>
          <el-input v-model="form.phone" style="width: 240px; margin-left: 8px" maxlength="30" />
        </el-form-item>
        <el-form-item label="邮箱"><el-input v-model="form.email" maxlength="100" /></el-form-item>
        <el-form-item label="语言偏好">
          <el-select v-model="form.lang" filterable allow-create style="width: 200px"><el-option v-for="l in languages" :key="l.code" :label="l.name" :value="l.code" /></el-select>
        </el-form-item>
        <el-form-item label="币种偏好">
          <el-select v-model="form.currency" filterable allow-create style="width: 200px"><el-option v-for="c in currencies" :key="c.code" :label="`${c.code} ${c.name}`" :value="c.code" /></el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editDialog = false">取消</el-button>
        <el-button type="primary" @click="submitEdit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 调整积分 / 余额 -->
    <el-dialog v-model="adjDialog" :title="adjKind === 'points' ? '调整积分' : '调整余额'" width="460px" destroy-on-close>
      <el-form label-width="90px">
        <el-form-item label="会员">{{ adj.name }}</el-form-item>
        <el-form-item :label="adjKind === 'points' ? '当前积分' : '当前余额'">{{ adj.current }}</el-form-item>
        <el-form-item :label="adjKind === 'points' ? '调整积分' : '调整金额'" required>
          <el-input-number v-model="adj.value" :precision="adjKind === 'points' ? 0 : 2" :min="-100000000" :max="100000000" :step="adjKind === 'points' ? 10 : 1" controls-position="right" />
          <div style="color: #909399; font-size: 12px; width: 100%">正数增加，负数扣减{{ adjKind === 'balance' ? '；金额按基准币种' : '' }}</div>
        </el-form-item>
        <el-form-item label="备注" required><el-input v-model="adj.remark" maxlength="200" show-word-limit placeholder="必填，会记录在流水和操作日志中" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="adjDialog = false">取消</el-button>
        <el-button type="primary" @click="submitAdjust">确认调整</el-button>
      </template>
    </el-dialog>
  </div>
</template>
