<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listTaxRate, createTaxRate, updateTaxRate, deleteTaxRate } from '@/api/taxRate'
import { cnDivisions } from '@/api/saleRegion'
import { COUNTRIES, countryName } from '@/utils/countries'
import { hasPerm } from '@/utils/perm'

const list = ref([])
const enabled = ref(true)
const loading = ref(false)
const provinces = ref([]) // 国内省份的标准名称

async function load() {
  loading.value = true
  try {
    const d = await listTaxRate()
    list.value = d.list
    enabled.value = d.enabled
  } finally {
    loading.value = false
  }
}

// countryName 给出的已经是“英国（GB）”这样带国家码的
const area = (row) => `${countryName(row.country_code)}${row.province ? ' / ' + row.province : ''}`

const dialog = ref(false)
const saving = ref(false)
const emptyForm = () => ({ id: 0, country_code: '', province: '', name: '', rate: '', included: 0, shipping_taxable: 0, status: 1 })
const form = reactive(emptyForm())
const isCn = computed(() => form.country_code === 'CN')

function openCreate() {
  Object.assign(form, emptyForm())
  dialog.value = true
}
function openEdit(row) {
  Object.assign(form, { ...row })
  dialog.value = true
}
function onCountry() {
  form.country_code = String(form.country_code || '').trim().toUpperCase()
  form.province = ''
}

const payload = (f) => ({
  country_code: f.country_code, province: (f.province || '').trim(), name: f.name.trim(), rate: String(f.rate).trim(),
  included: f.included, shipping_taxable: f.shipping_taxable, status: f.status,
})

async function submit() {
  if (!/^[A-Z]{2}$/.test(form.country_code)) return ElMessage.warning('请选择国家 / 地区')
  if (!form.name.trim()) return ElMessage.warning('请填写税名')
  if (!/^\d{1,2}(\.\d{1,3})?$/.test(String(form.rate).trim()) || Number(form.rate) <= 0) return ElMessage.warning('税率要填 0 到 100 之间的百分数，最多 3 位小数')
  saving.value = true
  try {
    if (form.id) await updateTaxRate(form.id, payload(form))
    else await createTaxRate(payload(form))
    ElMessage.success('保存成功')
    dialog.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function onStatus(row, status) {
  await updateTaxRate(row.id, payload({ ...row, status }))
  ElMessage.success(status ? '已启用' : '已停用')
  load()
}

async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除“${area(row)}”的税率规则吗？已经下的订单不受影响。`, '删除确认', { type: 'warning' })
  await deleteTaxRate(row.id)
  ElMessage.success('已删除')
  load()
}

onMounted(async () => {
  load()
  provinces.value = Object.keys(await cnDivisions())
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert v-if="!enabled" type="warning" :closable="false" show-icon style="margin-bottom: 12px">
        现在还没有开始收税：“设置 → 交易设置”里的“按收货地区收税”是关闭的。打开之后下面的规则才生效。
      </el-alert>
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
        下单时按收货地址匹配：先找“国家 + 省 / 州”的规则，没有再用整个国家的规则，都没有就不收税。计税金额是商品扣掉所有优惠之后的金额。
        税率在下单时记到订单上，之后修改不影响已经下的订单。一张订单只有一个税率，不能按商品分别设置。
      </el-alert>

      <el-button v-perm="'setting:tax:add'" type="success" style="margin-bottom: 12px" @click="openCreate">新增税率规则</el-button>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="地区" min-width="220">
          <template #default="{ row }">{{ area(row) }}<el-tag v-if="!row.province" size="small" type="info" style="margin-left: 8px">整个国家</el-tag></template>
        </el-table-column>
        <el-table-column prop="name" label="税名" min-width="140" />
        <el-table-column label="税率" width="100"><template #default="{ row }">{{ row.rate }}%</template></el-table-column>
        <el-table-column label="计税方式" width="150">
          <template #default="{ row }"><el-tag :type="row.included ? 'primary' : 'warning'">{{ row.included ? '价内税（标价已含）' : '价外税（另加）' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="运费" width="100"><template #default="{ row }">{{ row.shipping_taxable ? '计税' : '不计税' }}</template></el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" :disabled="!hasPerm('setting:tax:edit')" @change="(v) => onStatus(row, v)" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'setting:tax:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'setting:tax:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="form.id ? '编辑税率规则' : '新增税率规则'" width="620px" :close-on-click-modal="false">
      <el-form label-width="110px">
        <el-form-item label="国家 / 地区" required>
          <el-select v-model="form.country_code" filterable allow-create default-first-option placeholder="列表外的可输入两位国家码" style="width: 100%" @change="onCountry">
            <el-option v-for="c in COUNTRIES" :key="c.code" :label="`${c.name}（${c.code}）`" :value="c.code" />
          </el-select>
        </el-form-item>
        <el-form-item label="省 / 州">
          <el-select v-if="isCn" v-model="form.province" filterable clearable placeholder="不选 = 整个国家" style="width: 100%">
            <el-option v-for="p in provinces" :key="p" :label="p" :value="p" />
          </el-select>
          <el-input v-else v-model="form.province" maxlength="100" placeholder="不填 = 整个国家" />
          <div v-if="!isCn" class="note">要和买家在收货地址里填的写法一样才能匹配（不分大小写）。比如买家可能填 California 也可能填 CA，就各加一条规则。</div>
        </el-form-item>
        <el-form-item label="税名" required>
          <el-input v-model="form.name" maxlength="50" placeholder="如 VAT、Sales Tax、GST，会显示给买家" />
        </el-form-item>
        <el-form-item label="税率" required>
          <el-input v-model="form.rate" style="width: 160px"><template #append>%</template></el-input>
        </el-form-item>
        <el-form-item label="计税方式">
          <el-radio-group v-model="form.included">
            <el-radio :value="0">价外税（另加）</el-radio>
            <el-radio :value="1">价内税（标价已含）</el-radio>
          </el-radio-group>
          <div class="note">
            {{ form.included ? '商品标价已经含税，买家付的钱不变，只在结算页和订单上标出其中含多少税。欧洲的 VAT、国内的增值税通常是这种。' : '税在商品价之外另收，结算页多一行税费，计入应付金额。美国的销售税通常是这种。' }}
          </div>
        </el-form-item>
        <el-form-item label="运费">
          <el-switch v-model="form.shipping_taxable" :active-value="1" :inactive-value="0" active-text="计税" inactive-text="不计税" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
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
.note { margin-top: 6px; color: #909399; font-size: 13px; line-height: 1.6; width: 100%; }
</style>
