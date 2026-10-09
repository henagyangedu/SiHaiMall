<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listShippingTemplate, getShippingTemplate, createShippingTemplate, updateShippingTemplate, deleteShippingTemplate } from '@/api/shipping'
import { COUNTRIES, OTHER_COUNTRIES, countryName } from '@/utils/countries'

const CHARGE_TYPES = [
  { value: 1, label: '按件数', unit: '件', first: '首件', step: '续件' },
  { value: 2, label: '按重量', unit: 'kg', first: '首重', step: '续重' },
  { value: 3, label: '按体积', unit: 'm³', first: '首体积', step: '续体积' },
]
const chargeLabel = (v) => CHARGE_TYPES.find((t) => t.value === v)?.label || ''

const query = reactive({ keyword: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listShippingTemplate(query)
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

const dialog = ref(false)
const isEdit = ref(false)
const saving = ref(false)
const newRegion = () => ({ countries: [], provinces: [], first_unit: 1, first_fee: 0, step_unit: 1, step_fee: 0, free_amount: 0 })
const emptyForm = () => ({ id: 0, name: '', charge_type: 1, is_free_default: 0, regions: [newRegion()] })
const form = reactive(emptyForm())
const charge = computed(() => CHARGE_TYPES.find((t) => t.value === form.charge_type) || CHARGE_TYPES[0])
const unitPrecision = computed(() => (form.charge_type === 1 ? 0 : 3))

function openCreate() {
  isEdit.value = false
  Object.assign(form, emptyForm())
  dialog.value = true
}
async function openEdit(row) {
  const d = await getShippingTemplate(row.id)
  isEdit.value = true
  Object.assign(form, emptyForm(), {
    id: d.id, name: d.name, charge_type: d.charge_type, is_free_default: d.is_free_default,
    regions: d.regions.map((r) => ({
      countries: r.countries, provinces: r.provinces,
      first_unit: Number(r.first_unit), first_fee: Number(r.first_fee),
      step_unit: Number(r.step_unit), step_fee: Number(r.step_fee), free_amount: Number(r.free_amount),
    })),
  })
  dialog.value = true
}

// “其他所有国家/地区”与具体国家互斥；选了多个国家或“其他”时不能再细分省/州
function onCountries(region) {
  const codes = region.countries.map((c) => String(c).trim().toUpperCase()).filter(Boolean)
  const justPickedOther = codes.at(-1) === OTHER_COUNTRIES
  region.countries = codes.includes(OTHER_COUNTRIES) && (justPickedOther || codes.length === 1)
    ? [OTHER_COUNTRIES]
    : [...new Set(codes.filter((c) => c !== OTHER_COUNTRIES))]
  if (!canSplitProvince(region)) region.provinces = []
}
const canSplitProvince = (region) => region.countries.length === 1 && region.countries[0] !== OTHER_COUNTRIES

async function submit() {
  if (!form.name.trim()) return ElMessage.warning('请填写模板名称')
  if (!form.is_free_default && !form.regions.length) return ElMessage.warning('请至少添加一条配送地区规则，或开启“默认包邮”')
  const bad = form.regions.findIndex((r) => !r.countries.length)
  if (bad >= 0) return ElMessage.warning(`第 ${bad + 1} 条规则没有选择国家/地区`)

  saving.value = true
  try {
    const payload = { name: form.name.trim(), charge_type: form.charge_type, is_free_default: form.is_free_default, regions: form.regions }
    if (isEdit.value) await updateShippingTemplate(form.id, payload)
    else await createShippingTemplate(payload)
    ElMessage.success('保存成功')
    dialog.value = false
    load()
  } finally {
    saving.value = false
  }
}
async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除运费模板「${row.name}」吗？`, '提示', { type: 'warning' })
  await deleteShippingTemplate(row.id)
  ElMessage.success('已删除')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="模板名称" clearable style="width: 200px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-button type="primary" @click="search">查询</el-button>
          <el-button v-perm="'setting:shipping:add'" type="success" @click="openCreate">新增运费模板</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="模板名称" min-width="200" />
        <el-table-column label="计费方式" width="110">
          <template #default="{ row }">{{ chargeLabel(row.charge_type) }}</template>
        </el-table-column>
        <el-table-column label="配送规则" min-width="150">
          <template #default="{ row }">
            <el-tag v-if="row.is_free_default" type="success">默认包邮</el-tag>
            <span v-else>{{ row.region_count }} 条地区规则</span>
          </template>
        </el-table-column>
        <el-table-column prop="goods_count" label="使用商品数" width="110" />
        <el-table-column prop="updated_at" label="更新时间" min-width="160" />
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'setting:shipping:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'setting:shipping:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑运费模板' : '新增运费模板'" width="1180px" top="6vh" destroy-on-close>
      <el-form :model="form" label-width="90px">
        <el-form-item label="模板名称" required><el-input v-model="form.name" maxlength="100" placeholder="如：全国通用、海外小包" style="width: 360px" /></el-form-item>
        <el-form-item label="计费方式" required>
          <el-radio-group v-model="form.charge_type">
            <el-radio v-for="t in CHARGE_TYPES" :key="t.value" :value="t.value">{{ t.label }}</el-radio>
          </el-radio-group>
          <span class="hint">按重量、体积计费时取商品规格里填写的重量（kg）、体积（m³）</span>
        </el-form-item>
        <el-form-item label="默认包邮">
          <el-switch v-model="form.is_free_default" :active-value="1" :inactive-value="0" />
          <span class="hint">开启后使用该模板的商品全部免运费，下面的地区规则不生效</span>
        </el-form-item>

        <el-form-item v-if="!form.is_free_default" label="地区规则" required>
          <div class="regions">
            <el-table :data="form.regions" border size="small">
              <el-table-column label="国家/地区" min-width="230">
                <template #default="{ row }">
                  <el-select v-model="row.countries" multiple filterable allow-create default-first-option collapse-tags collapse-tags-tooltip :max-collapse-tags="2" placeholder="可多选，可输入两位国家码" style="width: 100%" @change="onCountries(row)">
                    <el-option :label="countryName(OTHER_COUNTRIES)" :value="OTHER_COUNTRIES" />
                    <el-option v-for="c in COUNTRIES" :key="c.code" :label="`${c.name}（${c.code}）`" :value="c.code" />
                  </el-select>
                </template>
              </el-table-column>
              <el-table-column label="省/州（可选）" min-width="190">
                <template #default="{ row }">
                  <el-select v-model="row.provinces" multiple filterable allow-create default-first-option collapse-tags collapse-tags-tooltip :disabled="!canSplitProvince(row)" :placeholder="canSplitProvince(row) ? '输入名称后回车' : '仅单个国家可细分'" style="width: 100%" />
                </template>
              </el-table-column>
              <el-table-column :label="`${charge.first}（${charge.unit}）`" width="120">
                <template #default="{ row }"><el-input-number v-model="row.first_unit" :min="form.charge_type === 1 ? 1 : 0.001" :precision="unitPrecision" :controls="false" style="width: 100%" /></template>
              </el-table-column>
              <el-table-column label="首费" width="120">
                <template #default="{ row }"><el-input-number v-model="row.first_fee" :min="0" :precision="2" :controls="false" style="width: 100%" /></template>
              </el-table-column>
              <el-table-column :label="`${charge.step}（${charge.unit}）`" width="120">
                <template #default="{ row }"><el-input-number v-model="row.step_unit" :min="0" :precision="unitPrecision" :controls="false" style="width: 100%" /></template>
              </el-table-column>
              <el-table-column label="续费" width="120">
                <template #default="{ row }"><el-input-number v-model="row.step_fee" :min="0" :precision="2" :controls="false" style="width: 100%" /></template>
              </el-table-column>
              <el-table-column label="满额包邮" width="130">
                <template #default="{ row }"><el-input-number v-model="row.free_amount" :min="0" :precision="2" :controls="false" style="width: 100%" /></template>
              </el-table-column>
              <el-table-column label="操作" width="70" fixed="right">
                <template #default="{ $index }"><el-button link type="danger" @click="form.regions.splice($index, 1)">删除</el-button></template>
              </el-table-column>
            </el-table>
            <el-button class="add" @click="form.regions.push(newRegion())">添加地区规则</el-button>
            <ul class="notes">
              <li>金额均为基准币种，下单时按汇率折算成买家所选币种。满额包邮填 0 表示不启用。</li>
              <li>匹配顺序：指定省/州 → 指定国家 → 其他所有国家/地区。收货地区不在任何规则内时，买家无法下单。</li>
              <li>省/州名称需与买家填写的收货地址一致（不区分大小写）。</li>
            </ul>
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.hint { margin-left: 12px; color: #909399; font-size: 13px; }
.regions { width: 100%; }
.regions .add { margin-top: 10px; }
.notes { margin: 10px 0 0; padding-left: 18px; color: #909399; font-size: 13px; line-height: 1.8; }
</style>
