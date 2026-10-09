<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listSaleRegion, cnDivisions, createSaleRegion, updateSaleRegion, setSaleRegionStatus, deleteSaleRegion } from '@/api/saleRegion'
import { currencyOptions } from '@/api/option'
import { COUNTRIES, countryName } from '@/utils/countries'
import { hasPerm } from '@/utils/perm'

const SCOPE_CN = 1
const SCOPE_OVERSEAS = 2
// 中国大陆只能配成国内区域（按省市），境外区域的国家列表里不出现
const OVERSEAS_COUNTRIES = COUNTRIES.filter((c) => c.code !== 'CN')

const query = reactive({ keyword: '', scope: '' })
const list = ref([])
const loading = ref(false)
const currencies = ref([])
const divisions = ref({}) // 省 → 市[]
const provinces = computed(() => Object.keys(divisions.value))

async function load() {
  loading.value = true
  try {
    list.value = await listSaleRegion(query)
  } finally {
    loading.value = false
  }
}

function coverage(row) {
  if (row.scope === SCOPE_OVERSEAS) return row.countries.map(countryName).join('、')
  return row.areas.map((a) => (a.cities.length ? `${a.province}（${a.cities.join('、')}）` : a.province)).join('、')
}

const dialog = ref(false)
const isEdit = ref(false)
const saving = ref(false)
const newArea = () => ({ province: '', cities: [] })
const emptyForm = () => ({ id: 0, name: '', scope: SCOPE_CN, currency: '', sort: 0, status: 1, areas: [newArea()], countries: [] })
const form = reactive(emptyForm())
const baseCode = computed(() => currencies.value.find((c) => c.is_base === 1)?.code || '')

function openCreate() {
  isEdit.value = false
  Object.assign(form, emptyForm(), { currency: baseCode.value })
  dialog.value = true
}
function openEdit(row) {
  isEdit.value = true
  Object.assign(form, emptyForm(), {
    id: row.id, name: row.name, scope: row.scope, currency: row.currency, sort: row.sort, status: row.status,
    areas: row.areas.length ? row.areas.map((a) => ({ province: a.province, cities: [...a.cities] })) : [newArea()],
    countries: [...row.countries],
  })
  dialog.value = true
}
function onCountries() {
  form.countries = [...new Set(form.countries.map((c) => String(c).trim().toUpperCase()).filter(Boolean))]
}

async function submit() {
  if (!form.name.trim()) return ElMessage.warning('请填写区域名称')
  if (!form.currency) return ElMessage.warning('请选择定价币种')
  const payload = { name: form.name.trim(), scope: form.scope, currency: form.currency, sort: form.sort, status: form.status }
  if (form.scope === SCOPE_CN) {
    payload.areas = form.areas.filter((a) => a.province)
    if (!payload.areas.length) return ElMessage.warning('请至少选择一个省份')
  } else {
    payload.countries = form.countries
    if (!payload.countries.length) return ElMessage.warning('请至少选择一个国家/地区')
  }

  saving.value = true
  try {
    if (isEdit.value) await updateSaleRegion(form.id, payload)
    else await createSaleRegion(payload)
    ElMessage.success('保存成功')
    dialog.value = false
    load()
  } finally {
    saving.value = false
  }
}
async function onStatus(row, val) {
  try {
    await setSaleRegionStatus(row.id, val)
  } finally {
    load()
  }
}
async function onDelete(row) {
  const extra = row.sku_count ? `已有 ${row.sku_count} 个规格设置了该区域的区域价，删除后这些区域价会一并清除，对应地区恢复按标准价销售。` : ''
  await ElMessageBox.confirm(`确定删除销售区域「${row.name}」吗？${extra}`, '提示', { type: 'warning' })
  await deleteSaleRegion(row.id)
  ElMessage.success('已删除')
  load()
}

onMounted(async () => {
  load()
  try {
    const [curs, divs] = await Promise.all([currencyOptions(), cnDivisions()])
    currencies.value = curs
    divisions.value = divs
  } catch (e) { /* 无权限时下拉为空 */ }
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="load">
        <el-form-item><el-input v-model="query.keyword" placeholder="区域名称" clearable style="width: 200px" @clear="load" @keyup.enter="load" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.scope" placeholder="全部类型" clearable style="width: 130px" @change="load">
            <el-option label="国内（省市）" :value="1" />
            <el-option label="境外（国家）" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="load">查询</el-button>
          <el-button v-perm="'goods:region:add'" type="success" @click="openCreate">新增销售区域</el-button>
        </el-form-item>
      </el-form>

      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
        商品可以为每个销售区域单独定价（在商品的“价格库存”里填写）。下单时按收货地址匹配区域：国内按“省 + 市”优先、其次整个省，境外按国家。商品没填市级区域的价时，先用所在省的区域价；都没有或没有匹配到区域时，按标准价销售。
      </el-alert>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="区域名称" min-width="140" />
        <el-table-column label="类型" width="110">
          <template #default="{ row }"><el-tag :type="row.scope === 1 ? 'primary' : 'warning'">{{ row.scope === 1 ? '国内' : '境外' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="覆盖地区" min-width="320" show-overflow-tooltip>
          <template #default="{ row }">{{ coverage(row) }}</template>
        </el-table-column>
        <el-table-column prop="currency" label="定价币种" width="100" />
        <el-table-column prop="sku_count" label="已定价规格数" width="120" />
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" :disabled="!hasPerm('goods:region:edit')" @change="(v) => onStatus(row, v)" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'goods:region:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'goods:region:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑销售区域' : '新增销售区域'" width="820px" destroy-on-close>
      <el-form :model="form" label-width="90px">
        <el-form-item label="区域名称" required><el-input v-model="form.name" maxlength="100" placeholder="如：华东、深圳、北美" style="width: 320px" /></el-form-item>
        <el-form-item label="区域类型" required>
          <el-radio-group v-model="form.scope" :disabled="isEdit">
            <el-radio :value="1">国内（按省市）</el-radio>
            <el-radio :value="2">境外（按国家）</el-radio>
          </el-radio-group>
          <span v-if="isEdit" class="hint">创建后不能更改</span>
        </el-form-item>
        <el-form-item label="定价币种" required>
          <el-select v-model="form.currency" style="width: 200px">
            <el-option v-for="c in currencies" :key="c.code" :label="`${c.code} ${c.name}`" :value="c.code" />
          </el-select>
          <span class="hint">该区域的区域价按此币种填写；买家用其他币种结算时按汇率换算。已有商品定价后不能更改</span>
        </el-form-item>

        <el-form-item v-if="form.scope === 1" label="覆盖省市" required>
          <div class="areas">
            <div v-for="(a, i) in form.areas" :key="i" class="area-row">
              <el-select v-model="a.province" filterable placeholder="选择省份" style="width: 190px" @change="a.cities = []">
                <el-option v-for="p in provinces" :key="p" :label="p" :value="p" />
              </el-select>
              <el-select v-model="a.cities" multiple filterable collapse-tags collapse-tags-tooltip :max-collapse-tags="3" :disabled="!a.province" placeholder="不选 = 整个省" style="flex: 1">
                <el-option v-for="c in divisions[a.province] || []" :key="c" :label="c" :value="c" />
              </el-select>
              <el-button link type="danger" :disabled="form.areas.length === 1" @click="form.areas.splice(i, 1)">删除</el-button>
            </div>
            <el-button @click="form.areas.push(newArea())">添加省份</el-button>
            <div class="note">城市留空表示整个省。“整个省”和“该省的某个市”可以分属两个区域，匹配时市优先；商品没填市级区域的价时，用整省区域的价。</div>
          </div>
        </el-form-item>
        <el-form-item v-else label="国家/地区" required>
          <el-select v-model="form.countries" multiple filterable allow-create default-first-option placeholder="可多选，列表外的可输入两位国家码" style="width: 100%" @change="onCountries">
            <el-option v-for="c in OVERSEAS_COUNTRIES" :key="c.code" :label="`${c.name}（${c.code}）`" :value="c.code" />
          </el-select>
          <div class="note">中国大陆请建“国内”区域；港澳台按独立的地区码（HK、MO、TW）处理。</div>
        </el-form-item>

        <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" :max="9999" /><span class="hint">越小越靠前</span></el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
          <span class="hint">停用后该区域的地区按标准价销售，已填的区域价保留</span>
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
.areas { width: 100%; }
.area-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.note { margin-top: 6px; color: #909399; font-size: 13px; line-height: 1.6; }
</style>
