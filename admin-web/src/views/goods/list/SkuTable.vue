<script setup>
import { ref, computed } from 'vue'
import ImageUpload from '@/components/ImageUpload.vue'
import { hasPerm } from '@/utils/perm'

// SKU 表。rows：[{ id, keys, sku_sn, image, price, market_price, cost_price, stock, warn_stock, weight, volume, status, prices, region_prices }]
const props = defineProps({
  rows: { type: Array, required: true },
  specs: { type: Array, required: true },
  languages: { type: Array, default: () => [] },
  currencies: { type: Array, default: () => [] }, // 非基准币种
  regions: { type: Array, default: () => [] }, // 启用的销售区域 [{ id, name, currency }]
  baseCode: { type: String, default: '' },
})
const emit = defineEmits(['adjust'])

const defaultLang = computed(() => props.languages.find((l) => l.is_default)?.code || props.languages[0]?.code || '')

function valueName(key) {
  for (const s of props.specs) {
    const v = s.values.find((x) => x.key === key)
    if (v) return v.name[defaultLang.value] || Object.values(v.name).find(Boolean) || '（未命名）'
  }
  return '?'
}
const label = (row) => row.keys.map(valueName).join(' / ')

// 批量设置
const FIELDS = [
  { value: 'price', label: '售价' },
  { value: 'market_price', label: '划线价' },
  { value: 'cost_price', label: '成本价' },
  { value: 'stock', label: '库存（仅新增行）' },
  { value: 'warn_stock', label: '预警值' },
  { value: 'weight', label: '重量' },
  { value: 'volume', label: '体积' },
]
const batchField = ref('price')
const batchValue = ref(0)
function applyBatch() {
  props.rows.forEach((r) => {
    if (batchField.value === 'stock' && r.id) return
    r[batchField.value] = batchValue.value || 0
  })
}
</script>

<template>
  <div class="sku-table">
    <div class="batch">
      <span>批量设置：</span>
      <el-select v-model="batchField" size="small" style="width: 150px">
        <el-option v-for="f in FIELDS" :key="f.value" :label="f.label" :value="f.value" />
      </el-select>
      <el-input-number v-model="batchValue" size="small" :min="0" :controls="false" style="width: 110px" />
      <el-button size="small" @click="applyBatch">应用到全部</el-button>
    </div>

    <el-table :data="rows" border size="small" max-height="460">
      <el-table-column label="规格组合" min-width="150" fixed>
        <template #default="{ row }"><b>{{ label(row) }}</b></template>
      </el-table-column>
      <el-table-column label="图片" width="80">
        <template #default="{ row }"><ImageUpload v-model="row.image" scene="goods" :width="48" :height="48" /></template>
      </el-table-column>
      <el-table-column label="规格编码" width="130">
        <template #default="{ row }"><el-input v-model="row.sku_sn" size="small" maxlength="60" /></template>
      </el-table-column>
      <el-table-column :label="`售价(${baseCode})`" width="120">
        <template #default="{ row }"><el-input-number v-model="row.price" size="small" :min="0" :precision="2" :controls="false" style="width: 100%" /></template>
      </el-table-column>
      <el-table-column label="划线价" width="110">
        <template #default="{ row }"><el-input-number v-model="row.market_price" size="small" :min="0" :precision="2" :controls="false" style="width: 100%" /></template>
      </el-table-column>
      <el-table-column label="成本价" width="110">
        <template #default="{ row }"><el-input-number v-model="row.cost_price" size="small" :min="0" :precision="2" :controls="false" style="width: 100%" /></template>
      </el-table-column>
      <el-table-column v-for="c in currencies" :key="c.code" :label="`${c.code} 定价`" width="115">
        <template #default="{ row }"><el-input-number v-model="row.prices[c.code]" size="small" :min="0" :precision="2" :controls="false" placeholder="自动折算" style="width: 100%" /></template>
      </el-table-column>
      <el-table-column v-for="r in regions" :key="`r${r.id}`" :label="`${r.name}(${r.currency})`" width="125">
        <template #default="{ row }"><el-input-number v-model="row.region_prices[r.id]" size="small" :min="0" :precision="2" :controls="false" placeholder="按标准价" style="width: 100%" /></template>
      </el-table-column>
      <el-table-column label="库存" width="130">
        <template #default="{ row }">
          <template v-if="row.id">
            <b>{{ row.stock }}</b>
            <el-button v-if="hasPerm('goods:list:stock')" link type="primary" size="small" style="margin-left: 6px" @click="emit('adjust', row, label(row))">调整</el-button>
          </template>
          <el-input-number v-else v-model="row.stock" size="small" :min="0" :max="99999999" :controls="false" style="width: 100%" />
        </template>
      </el-table-column>
      <el-table-column label="预警值" width="95">
        <template #default="{ row }"><el-input-number v-model="row.warn_stock" size="small" :min="0" :controls="false" style="width: 100%" /></template>
      </el-table-column>
      <el-table-column label="重量kg" width="95">
        <template #default="{ row }"><el-input-number v-model="row.weight" size="small" :min="0" :precision="3" :controls="false" style="width: 100%" /></template>
      </el-table-column>
      <el-table-column label="体积m³" width="95">
        <template #default="{ row }"><el-input-number v-model="row.volume" size="small" :min="0" :precision="3" :controls="false" style="width: 100%" /></template>
      </el-table-column>
      <el-table-column label="启用" width="70" fixed="right">
        <template #default="{ row }"><el-switch v-model="row.status" :active-value="1" :inactive-value="0" size="small" /></template>
      </el-table-column>
    </el-table>
    <div class="tip">共 {{ rows.length }} 个规格组合；停用的组合不可购买。已有组合的库存只能点“调整”修改，新增组合可直接填写初始库存。<template v-if="regions.length">销售区域价留空表示该区域按标准价销售。</template></div>
  </div>
</template>

<style scoped>
.batch { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 13px; }
.tip { margin-top: 6px; color: #909399; font-size: 12px; }
</style>
