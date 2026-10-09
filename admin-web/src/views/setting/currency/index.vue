<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listCurrency, createCurrency, updateCurrency, updateCurrencyRate, setBaseCurrency, deleteCurrency } from '@/api/currency'

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
    `1 ${base.value?.code || '基准币种'} = ？ ${row.code}`,
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

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
        <template #title>
          基准币种：<b>{{ base ? `${base.code}（${base.name}）` : '未设置' }}</b>。商品价格以基准币种存储，汇率表示 1 个基准币种可兑换多少本币种；当前汇率为手动维护，暂不支持自动更新。
        </template>
      </el-alert>
      <div class="toolbar">
        <el-button v-perm="'setting:currency:add'" type="success" @click="openCreate">新增币种</el-button>
      </div>
      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="code" label="代码" width="90" />
        <el-table-column prop="name" label="名称" min-width="120" />
        <el-table-column prop="symbol" label="符号" width="80" />
        <el-table-column label="汇率" min-width="200">
          <template #default="{ row }">
            <span v-if="row.is_base === 1">基准币种（固定为 1）</span>
            <span v-else>1 {{ base?.code }} = {{ showRate(row.rate) }} {{ row.code }}</span>
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
