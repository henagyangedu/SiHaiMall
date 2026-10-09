<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { adjustGoodsStock } from '@/api/goods'

// goods: { id, title, stock }；sku（多规格时）: { id, label, stock }。调整成功后触发 done
const props = defineProps({
  modelValue: Boolean,
  goods: { type: Object, default: null },
  sku: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'done'])

const form = reactive({ change: 0, remark: '' })
const saving = ref(false)
const current = computed(() => (props.sku ? props.sku.stock : props.goods?.stock) || 0)

watch(() => props.modelValue, (v) => {
  if (v) Object.assign(form, { change: 0, remark: '' })
})

async function submit() {
  if (!form.change) {
    ElMessage.warning('调整数量不能为 0')
    return
  }
  saving.value = true
  try {
    const data = { change: form.change, remark: form.remark }
    if (props.sku) data.sku_id = props.sku.id
    await adjustGoodsStock(props.goods.id, data)
    ElMessage.success('库存已调整')
    emit('update:modelValue', false)
    emit('done')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-dialog :model-value="modelValue" title="调整库存" width="440px" append-to-body @update:model-value="emit('update:modelValue', $event)">
    <el-form label-width="90px">
      <el-form-item label="商品">{{ goods?.title }}</el-form-item>
      <el-form-item v-if="sku" label="规格">{{ sku.label }}</el-form-item>
      <el-form-item label="当前库存"><b>{{ current }}</b></el-form-item>
      <el-form-item label="调整数量">
        <el-input-number v-model="form.change" :min="-99999999" :max="99999999" />
        <div class="hint">正数为增加，负数为减少；调整后库存不能小于 0</div>
      </el-form-item>
      <el-form-item label="调整后"><b>{{ current + (form.change || 0) }}</b></el-form-item>
      <el-form-item label="备注"><el-input v-model="form.remark" maxlength="250" placeholder="如：盘点、入库、报损" /></el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="emit('update:modelValue', false)">取消</el-button>
      <el-button type="primary" :loading="saving" @click="submit">确定</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.hint { width: 100%; color: #909399; font-size: 12px; }
</style>
