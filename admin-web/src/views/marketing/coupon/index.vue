<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listCoupon, createCoupon, updateCoupon, setCouponStatus, deleteCoupon, grantCoupon, couponRecords } from '@/api/coupon'
import { categoryTree } from '@/api/category'
import { listGoods } from '@/api/goods'

const query = reactive({ keyword: '', type: '', status: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listCoupon(query)
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

// ---- 新建 / 编辑
const emptyForm = () => ({
  id: 0, name: '', type: 1, threshold: 0, amount: 0, discount: 90, max_amount: 0, scope: 1, scope_ids: [],
  total: 0, per_member: 1, valid_type: 1, valid_range: [], valid_days: 7, receive_range: [], is_public: 1, status: 1, received: 0,
})
const dialog = ref(false)
const saving = ref(false)
const form = reactive(emptyForm())
// 已经有人领过的券，面额、门槛、范围、有效期不能再改
const locked = computed(() => form.id > 0 && form.received > 0)

const categories = ref([])
const goodsOptions = ref([])
const goodsLoading = ref(false)
async function searchGoods(keyword) {
  goodsLoading.value = true
  try {
    const d = await listGoods({ keyword, size: 20 })
    // 已经选中的要留在选项里，否则选择框里只显示一个数字
    const picked = goodsOptions.value.filter((g) => form.scope_ids.includes(g.id))
    goodsOptions.value = [...picked, ...d.list.filter((g) => !picked.some((p) => p.id === g.id)).map((g) => ({ id: g.id, title: g.title }))]
  } finally {
    goodsLoading.value = false
  }
}

function openCreate() {
  Object.assign(form, emptyForm())
  goodsOptions.value = []
  dialog.value = true
}
function openEdit(r) {
  Object.assign(form, emptyForm(), {
    id: r.id, name: r.name, type: r.type, threshold: Number(r.threshold), amount: Number(r.amount), discount: Number(r.discount) || 90,
    max_amount: Number(r.max_amount), scope: r.scope, scope_ids: [...r.scope_ids], total: r.total, per_member: r.per_member,
    valid_type: r.valid_type, valid_range: r.valid_start ? [r.valid_start, r.valid_end] : [], valid_days: r.valid_days || 7,
    receive_range: r.receive_start || r.receive_end ? [r.receive_start, r.receive_end] : [], is_public: r.is_public, status: r.status, received: r.received,
  })
  // 指定商品的券：把已选商品的名称取回来显示
  goodsOptions.value = r.scope === 3 ? r.scope_ids.map((id) => ({ id, title: `商品 #${id}` })) : []
  if (r.scope === 3) searchGoods('')
  dialog.value = true
}
async function submit() {
  if (!form.name.trim()) return ElMessage.error('请填写券名称')
  if (form.valid_type === 1 && form.valid_range.length !== 2) return ElMessage.error('请选择有效期')
  const data = {
    name: form.name.trim(), type: form.type, threshold: form.threshold, amount: form.amount, discount: form.discount, max_amount: form.max_amount,
    scope: form.scope, scope_ids: form.scope === 1 ? [] : form.scope_ids, total: form.total, per_member: form.per_member,
    valid_type: form.valid_type, valid_start: form.valid_range[0] || null, valid_end: form.valid_range[1] || null, valid_days: form.valid_days,
    receive_start: form.receive_range?.[0] || null, receive_end: form.receive_range?.[1] || null, is_public: form.is_public, status: form.status,
  }
  saving.value = true
  try {
    if (form.id) await updateCoupon(form.id, data)
    else await createCoupon(data)
    ElMessage.success('保存成功')
    dialog.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function toggle(r) {
  const next = r.status === 1 ? 0 : 1
  if (next === 0) await ElMessageBox.confirm('停用后买家不能再领取，后台也不能再发放；已经领到手的券不受影响，照常能用。', '停用优惠券', { type: 'warning' })
  await setCouponStatus(r.id, next)
  ElMessage.success(next === 1 ? '已启用' : '已停用')
  load()
}
async function remove(r) {
  await ElMessageBox.confirm(`确定删除“${r.name}”吗？`, '删除优惠券', { type: 'warning' })
  await deleteCoupon(r.id)
  ElMessage.success('已删除')
  load()
}

// ---- 发放
const grant = reactive({ show: false, row: null, text: '', saving: false })
function openGrant(r) {
  Object.assign(grant, { show: true, row: r, text: '', saving: false })
}
async function submitGrant() {
  const members = grant.text.split(/[\n,，;；\s]+/).map((x) => x.trim()).filter(Boolean)
  if (!members.length) return ElMessage.error('请填写要发放的会员')
  grant.saving = true
  try {
    const r = await grantCoupon(grant.row.id, members)
    ElMessage.success(`已发放给 ${r.granted} 位会员`)
    grant.show = false
    load()
  } finally {
    grant.saving = false
  }
}

// ---- 领取记录
const rec = reactive({ show: false, row: null, list: [], total: 0, page: 1, size: 15, status: '', loading: false })
async function loadRecords() {
  rec.loading = true
  try {
    const d = await couponRecords(rec.row.id, { page: rec.page, size: rec.size, status: rec.status })
    rec.list = d.list
    rec.total = d.total
  } finally {
    rec.loading = false
  }
}
function openRecords(r) {
  Object.assign(rec, { show: true, row: r, list: [], total: 0, page: 1, status: '' })
  loadRecords()
}

onMounted(() => {
  load()
  categoryTree().then((d) => { categories.value = d }).catch(() => {})
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="券名称" clearable style="width: 200px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.type" placeholder="类型" clearable style="width: 120px" @change="search">
            <el-option label="满减券" :value="1" /><el-option label="折扣券" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 110px" @change="search">
            <el-option label="启用" :value="1" /><el-option label="停用" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
        <el-form-item><el-button v-perm="'marketing:coupon:add'" type="success" @click="openCreate">新建优惠券</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="优惠券" min-width="220">
          <template #default="{ row }">
            <div style="font-weight: 600">{{ row.name }}</div>
            <div class="sub">{{ row.type_text }}　{{ row.desc }}</div>
          </template>
        </el-table-column>
        <el-table-column label="适用范围" width="110" prop="scope_text" />
        <el-table-column label="有效期" min-width="190">
          <template #default="{ row }">
            <template v-if="row.valid_type === 1">{{ row.valid_start }}<br />至 {{ row.valid_end }}</template>
            <template v-else>领取后 {{ row.valid_days }} 天内</template>
            <el-tag v-if="row.ended" size="small" type="info" style="margin-left: 6px">已结束</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="发行 / 已领 / 已用" width="150">
          <template #default="{ row }">{{ row.total || '不限' }} / {{ row.received }} / {{ row.used }}</template>
        </el-table-column>
        <el-table-column label="领取方式" width="110">
          <template #default="{ row }">{{ row.is_public ? `买家自领（限 ${row.per_member} 张）` : '仅后台发放' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }"><el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="操作" width="250" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'marketing:coupon:grant'" link type="success" :disabled="row.status !== 1 || row.ended" @click="openGrant(row)">发放</el-button>
            <el-button link type="primary" @click="openRecords(row)">记录</el-button>
            <el-button v-perm="'marketing:coupon:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'marketing:coupon:edit'" link :type="row.status === 1 ? 'warning' : 'success'" @click="toggle(row)">{{ row.status === 1 ? '停用' : '启用' }}</el-button>
            <el-button v-perm="'marketing:coupon:del'" link type="danger" :disabled="row.received > 0" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="dialog" :title="form.id ? '编辑优惠券' : '新建优惠券'" width="720px" destroy-on-close>
      <el-alert v-if="locked" type="warning" :closable="false" show-icon style="margin-bottom: 14px"
        :title="`这张券已经被领取 ${form.received} 张，面额、门槛、适用范围和有效期不能再改（买家领到手的不该变）。需要调整请停用后新建一张。`" />
      <el-form label-width="110px">
        <el-form-item label="券名称" required><el-input v-model="form.name" maxlength="100" placeholder="买家能看到，如：新人满 300 减 30" /></el-form-item>
        <el-form-item label="类型" required>
          <el-radio-group v-model="form.type" :disabled="locked">
            <el-radio :value="1">满减券</el-radio><el-radio :value="2">折扣券</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="使用门槛">
          <span class="unit" style="margin-left: 0; margin-right: 8px">适用商品满</span>
          <el-input-number v-model="form.threshold" :min="0" :precision="2" :controls="false" :disabled="locked" style="width: 130px" />
          <span class="unit">元可用，填 0 表示无门槛</span>
        </el-form-item>
        <el-form-item v-if="form.type === 1" label="满减金额" required>
          <el-input-number v-model="form.amount" :min="0" :precision="2" :controls="false" :disabled="locked" style="width: 130px" /><span class="unit">元</span>
        </el-form-item>
        <template v-else>
          <el-form-item label="折扣" required>
            <el-input-number v-model="form.discount" :min="1" :max="99" :precision="0" :controls="false" :disabled="locked" style="width: 130px" />
            <span class="unit">填 90 表示 9 折，85 表示 85 折</span>
          </el-form-item>
          <el-form-item label="最高减免">
            <el-input-number v-model="form.max_amount" :min="0" :precision="2" :controls="false" :disabled="locked" style="width: 130px" />
            <span class="unit">元，填 0 表示不限</span>
          </el-form-item>
        </template>

        <el-form-item label="适用范围" required>
          <el-radio-group v-model="form.scope" :disabled="locked" @change="form.scope_ids = []">
            <el-radio :value="1">全部商品</el-radio><el-radio :value="2">指定分类</el-radio><el-radio :value="3">指定商品</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.scope === 2" label="适用分类" required>
          <el-tree-select v-model="form.scope_ids" :data="categories" :props="{ label: 'name_text', children: 'children' }" node-key="id" multiple check-strictly
            :disabled="locked" placeholder="选中上级分类时，它下面的分类也适用" style="width: 100%" />
        </el-form-item>
        <el-form-item v-if="form.scope === 3" label="适用商品" required>
          <el-select v-model="form.scope_ids" multiple filterable remote :remote-method="searchGoods" :loading="goodsLoading" :disabled="locked"
            placeholder="输入商品名称搜索" style="width: 100%" @focus="!goodsOptions.length && searchGoods('')">
            <el-option v-for="g in goodsOptions" :key="g.id" :label="g.title" :value="g.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="有效期" required>
          <el-radio-group v-model="form.valid_type" :disabled="locked">
            <el-radio :value="1">固定时间段</el-radio><el-radio :value="2">领取后若干天</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.valid_type === 1" label=" ">
          <el-date-picker v-model="form.valid_range" type="datetimerange" value-format="YYYY-MM-DD HH:mm:ss" :default-time="[new Date(2000, 0, 1, 0, 0, 0), new Date(2000, 0, 1, 23, 59, 59)]"
            start-placeholder="开始时间" end-placeholder="结束时间" :disabled="locked" style="width: 100%" />
        </el-form-item>
        <el-form-item v-else label=" ">
          <span class="unit" style="margin-left: 0; margin-right: 8px">领取后</span>
          <el-input-number v-model="form.valid_days" :min="1" :max="3650" :disabled="locked" /><span class="unit">天内有效</span>
        </el-form-item>

        <el-divider content-position="left">发行与领取</el-divider>
        <el-form-item label="发行总量">
          <el-input-number v-model="form.total" :min="0" :max="100000000" /><span class="unit">张，填 0 表示不限</span>
        </el-form-item>
        <el-form-item label="领取方式">
          <el-radio-group v-model="form.is_public">
            <el-radio :value="1">买家可以自己领</el-radio><el-radio :value="0">只能后台发放</el-radio>
          </el-radio-group>
        </el-form-item>
        <template v-if="form.is_public">
          <el-form-item label="每人限领"><el-input-number v-model="form.per_member" :min="1" :max="100" /><span class="unit">张</span></el-form-item>
          <el-form-item label="可领取时间">
            <el-date-picker v-model="form.receive_range" type="datetimerange" value-format="YYYY-MM-DD HH:mm:ss" start-placeholder="不填表示马上可领" end-placeholder="不填表示一直可领" style="width: 100%" />
          </el-form-item>
        </template>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" inline-prompt /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">保存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="grant.show" title="发放优惠券" width="520px" destroy-on-close>
      <div v-if="grant.row" class="quote"><b>{{ grant.row.name }}</b>　{{ grant.row.desc }}<span v-if="grant.row.total">　剩余 {{ grant.row.total - grant.row.received }} 张</span></div>
      <el-input v-model="grant.text" type="textarea" :rows="6" placeholder="每行一位会员：会员 ID、账号、手机号或邮箱都可以" />
      <div class="sub" style="margin-top: 8px">每人发一张，不受“每人限领”和“领取方式”的限制。有任何一位找不到，整批都不会发。一次最多 500 位。</div>
      <template #footer>
        <el-button @click="grant.show = false">取消</el-button>
        <el-button type="primary" :loading="grant.saving" @click="submitGrant">发放</el-button>
      </template>
    </el-dialog>

    <el-drawer v-model="rec.show" :title="`领取记录：${rec.row?.name || ''}`" size="760px" destroy-on-close>
      <el-select v-model="rec.status" placeholder="全部状态" clearable style="width: 130px; margin-bottom: 12px" @change="rec.page = 1; loadRecords()">
        <el-option label="未使用" :value="0" /><el-option label="已使用" :value="1" /><el-option label="已过期" :value="2" />
      </el-select>
      <el-table v-loading="rec.loading" :data="rec.list" border size="small">
        <el-table-column prop="member" label="会员" min-width="120" />
        <el-table-column prop="source_text" label="来源" width="90" />
        <el-table-column label="状态" width="80"><template #default="{ row }"><el-tag size="small" :type="{ 0: 'primary', 1: 'success', 2: 'info' }[row.status]">{{ row.status_text }}</el-tag></template></el-table-column>
        <el-table-column label="有效期至" prop="valid_end" min-width="150" />
        <el-table-column label="使用的订单" prop="order_no" min-width="170" />
        <el-table-column label="领取时间" prop="created_at" min-width="150" />
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="rec.page" :page-size="rec.size" :total="rec.total" layout="total, prev, pager, next" @current-change="loadRecords" />
      </div>
    </el-drawer>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; }
.unit { margin-left: 8px; color: #909399; font-size: 13px; }
.quote { margin-bottom: 12px; padding: 10px 12px; background: #f5f7fa; border-radius: 4px; }
</style>
