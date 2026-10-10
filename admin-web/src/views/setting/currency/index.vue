<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  listCurrency, createCurrency, updateCurrency, updateCurrencyRate, setBaseCurrency, deleteCurrency,
  setCurrencyRateMode, getRateConfig, saveRateConfig, syncRates,
} from '@/api/currency'
import { hasPerm } from '@/utils/perm'

const list = ref([])
const loading = ref(false)
const base = computed(() => list.value.find((c) => c.is_base === 1))

async function load() {
  loading.value = true
  try {
    list.value = await listCurrency()
  } finally {
    loading.value = false
  }
}
const showRate = (v) => String(Number(v)) // 0.14000000 -> 0.14

// ---- 汇率自动更新
const canRate = hasPerm('setting:currency:rate')
const auto = reactive({ rate_source: 'frankfurter', rate_markup: 0, rate_max_change: 10, sources: [], state: null })
const autoList = computed(() => list.value.filter((c) => c.rate_mode === 2 && c.is_base !== 1))
const syncing = ref(false)
const savingAuto = ref(false)
// 最近一次更新里各币种的结果，按币种代码取
const lastItem = (code) => (auto.state?.items || []).find((i) => i.code === code)
const sourceName = (code) => auto.sources.find((s) => s.code === code)?.name || code

async function loadAuto() {
  const d = await getRateConfig()
  Object.assign(auto, { ...d, rate_markup: Number(d.rate_markup) })
}
async function saveAuto() {
  savingAuto.value = true
  try {
    await saveRateConfig({ rate_source: auto.rate_source, rate_markup: String(auto.rate_markup ?? 0), rate_max_change: auto.rate_max_change })
    ElMessage.success('保存成功，下次更新时生效')
  } finally {
    savingAuto.value = false
  }
}
async function onSync() {
  syncing.value = true
  try {
    const r = await syncRates()
    const skipped = r.items.filter((i) => i.status === 'skipped').length
    ElMessage[skipped ? 'warning' : 'success'](`更新了 ${r.updated} 个币种${skipped ? `，${skipped} 个没有更新，原因见列表` : ''}`)
  } finally {
    syncing.value = false
    load()
    loadAuto()
  }
}
async function onMode(row, mode) {
  try {
    await setCurrencyRateMode(row.id, mode)
    ElMessage.success(mode === 2 ? `「${row.code}」已设为自动更新，点“立即更新”可以马上取一次行情` : `「${row.code}」已改为手动维护`)
  } finally {
    load()
  }
}

const dialog = ref(false)
const isEdit = ref(false)
const formRef = ref()
const emptyForm = () => ({ id: 0, code: '', name: '', symbol: '', rate: 1, decimals: 2, status: 1, sort: 0 })
const form = reactive(emptyForm())
const rules = {
  code: [
    { required: true, message: '请输入币种代码', trigger: 'blur' },
    { pattern: /^[A-Z]{3}$/, message: '3 位大写字母，如 CNY、USD、EUR', trigger: 'blur' },
  ],
  name: [{ required: true, message: '请输入币种名称', trigger: 'blur' }],
  rate: [{ required: true, message: '请输入汇率', trigger: 'change' }],
}

function openCreate() {
  isEdit.value = false
  Object.assign(form, emptyForm())
  dialog.value = true
}
function openEdit(row) {
  isEdit.value = true
  Object.assign(form, { id: row.id, code: row.code, name: row.name, symbol: row.symbol, decimals: row.decimals, status: row.status, sort: row.sort })
  dialog.value = true
}
async function submit() {
  await formRef.value.validate()
  if (isEdit.value) {
    await updateCurrency(form.id, { name: form.name, symbol: form.symbol, decimals: form.decimals, status: form.status, sort: form.sort })
  } else {
    await createCurrency({ code: form.code, name: form.name, symbol: form.symbol, rate: form.rate, decimals: form.decimals, status: form.status, sort: form.sort })
  }
  ElMessage.success('保存成功')
  dialog.value = false
  load()
}

async function onRate(row) {
  const { value } = await ElMessageBox.prompt(
    `1 ${base.value?.code || '基准币种'} = ？ ${row.code}` + (row.rate_mode === 2 ? '（这个币种是自动更新的，下次更新时会按行情再改）' : ''),
    `更新「${row.code}」汇率`,
    {
      inputValue: showRate(row.rate),
      inputPattern: /^(?!0+(\.0+)?$)\d{1,9}(\.\d{1,8})?$/,
      inputErrorMessage: '请输入大于 0 的数字，最多 8 位小数',
    },
  )
  await updateCurrencyRate(row.id, value)
  ElMessage.success('汇率已更新')
  load()
}
async function onBase(row) {
  await ElMessageBox.confirm(
    `将「${row.code}」设为基准币种？\n商品价格都按基准币种存储，系统只允许在还没有商品和订单时更换，其它币种的汇率会自动按新基准换算。`,
    '更换基准币种',
    { type: 'warning' },
  )
  await setBaseCurrency(row.id)
  ElMessage.success('基准币种已更换')
  load()
}
async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除币种「${row.code}」吗？`, '提示', { type: 'warning' })
  await deleteCurrency(row.id)
  ElMessage.success('已删除')
  load()
}

onMounted(() => {
  load()
  loadAuto()
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
        <template #title>
          基准币种：<b>{{ base ? `${base.code}（${base.name}）` : '未设置' }}</b>。商品价格以基准币种存储，汇率表示 1 个基准币种可兑换多少本币种。每个币种可以选手动维护，或按行情自动更新；已经下的订单用的是下单时的汇率，不受影响。
        </template>
      </el-alert>
      <div class="toolbar">
        <el-button v-perm="'setting:currency:add'" type="success" @click="openCreate">新增币种</el-button>
      </div>
      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="code" label="代码" width="90" />
        <el-table-column prop="name" label="名称" min-width="120" />
        <el-table-column prop="symbol" label="符号" width="80" />
        <el-table-column label="汇率" min-width="220">
          <template #default="{ row }">
            <span v-if="row.is_base === 1">基准币种（固定为 1）</span>
            <template v-else>
              <div>1 {{ base?.code }} = {{ showRate(row.rate) }} {{ row.code }}</div>
              <div v-if="row.rate_mode === 2 && lastItem(row.code)?.status === 'skipped'" class="rate-warn">上次没有更新：{{ lastItem(row.code).msg }}<template v-if="lastItem(row.code).rate_new">（行情算出来是 {{ lastItem(row.code).rate_new }}）</template></div>
            </template>
          </template>
        </el-table-column>
        <el-table-column label="更新方式" width="150">
          <template #default="{ row }">
            <span v-if="row.is_base === 1">—</span>
            <el-select v-else-if="canRate" :model-value="row.rate_mode" size="small" style="width: 116px" @change="(v) => onMode(row, v)">
              <el-option :value="1" label="手动维护" />
              <el-option :value="2" label="自动更新" />
            </el-select>
            <span v-else>{{ row.rate_mode === 2 ? '自动更新' : '手动维护' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="decimals" label="小数位" width="80" />
        <el-table-column label="基准" width="80">
          <template #default="{ row }"><el-tag v-if="row.is_base === 1" type="warning">基准</el-tag></template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }"><el-tag :type="row.status === 1 ? 'success' : 'danger'">{{ row.status === 1 ? '启用' : '禁用' }}</el-tag></template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="70" />
        <el-table-column prop="updated_at" label="更新时间" min-width="160" />
        <el-table-column label="操作" width="290" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'setting:currency:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-if="row.is_base !== 1" v-perm="'setting:currency:rate'" link type="warning" @click="onRate(row)">更新汇率</el-button>
            <el-button v-if="row.is_base !== 1" v-perm="'super'" link type="info" @click="onBase(row)">设为基准</el-button>
            <el-button v-if="row.is_base !== 1" v-perm="'setting:currency:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-card shadow="never" class="auto-card">
      <template #header>
        <div class="auto-head">
          <span>汇率自动更新</span>
          <span class="auto-count">{{ autoList.length ? `${autoList.length} 个币种设为自动更新：${autoList.map((c) => c.code).join('、')}` : '还没有币种设为自动更新（在上面列表的“更新方式”里选）' }}</span>
        </div>
      </template>
      <el-form label-width="130px" style="max-width: 760px">
        <el-form-item label="行情源">
          <el-radio-group v-model="auto.rate_source" :disabled="!canRate" class="src-group">
            <el-radio v-for="s in auto.sources" :key="s.code" :value="s.code">{{ s.name }}<span class="hint">{{ s.desc }}</span></el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="加价">
          <el-input-number v-model="auto.rate_markup" :min="0" :max="20" :precision="2" :step="0.5" :disabled="!canRate" controls-position="right" style="width: 130px" />
          <span class="unit">%</span>
          <span class="hint">在行情汇率上再加这么多，用来盖住收款渠道的换汇手续费；填 0 就是按行情原价</span>
        </el-form-item>
        <el-form-item label="单次最大变动">
          <el-input-number v-model="auto.rate_max_change" :min="1" :max="50" :precision="0" :disabled="!canRate" controls-position="right" style="width: 130px" />
          <span class="unit">%</span>
          <span class="hint">和现在的汇率相差超过这个比例就不更新，等你确认后手工改一次</span>
        </el-form-item>
        <el-form-item label="最近一次更新">
          <span v-if="!auto.state">还没有更新过</span>
          <span v-else-if="auto.state.ok">{{ auto.state.at }}，来自 {{ sourceName(auto.state.source) }}<template v-if="auto.state.date">（{{ auto.state.date }} 的行情）</template></span>
          <span v-else class="rate-warn">{{ auto.state.at }} 更新失败：{{ auto.state.error }}，汇率没有变动</span>
        </el-form-item>
        <el-form-item v-if="canRate">
          <el-button type="primary" :loading="savingAuto" @click="saveAuto">保存设置</el-button>
          <el-button :loading="syncing" :disabled="!autoList.length" @click="onSync">立即更新</el-button>
          <span class="hint">系统每 6 小时自动更新一次（服务器要配好定时任务）；行情源一天只出一次新汇率</span>
        </el-form-item>
      </el-form>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑币种' : '新增币种'" width="480px" destroy-on-close>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="币种代码" prop="code"><el-input v-model="form.code" :disabled="isEdit" placeholder="如 USD" /></el-form-item>
        <el-form-item label="币种名称" prop="name"><el-input v-model="form.name" placeholder="如 美元" /></el-form-item>
        <el-form-item label="符号"><el-input v-model="form.symbol" placeholder="如 $" /></el-form-item>
        <el-form-item v-if="!isEdit" :label="`1 ${base?.code || '基准币'} =`" prop="rate">
          <el-input-number v-model="form.rate" :min="0.00000001" :precision="8" :step="0.01" :controls="false" style="width: 200px" />
          <span style="margin-left: 8px">{{ form.code || '本币种' }}</span>
        </el-form-item>
        <el-form-item label="小数位"><el-input-number v-model="form.decimals" :min="0" :max="4" /><span style="margin-left: 8px; color: #909399">日元等无小数币种填 0</span></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" :max="9999" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="禁用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.auto-card { margin-top: 12px; }
.auto-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.auto-count { font-size: 13px; color: var(--el-text-color-secondary); }
.src-group { display: flex; flex-direction: column; align-items: flex-start; }
.hint { margin-left: 10px; font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; }
.unit { margin-left: 6px; }
.rate-warn { font-size: 12px; color: var(--el-color-danger); line-height: 1.5; }
</style>
