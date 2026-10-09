<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { countries } from '@shared/utils/countries'
import { state } from '@/store'
import { createAddress, updateAddress, getCnDivisions } from '@/api'

// 新增 / 编辑收货地址的弹窗。address 传已有地址表示编辑，不传是新增；保存成功后触发 saved（带地址 ID）
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  address: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'saved'])
const { t } = useI18n()

const empty = () => ({ name: '', phone_area: '86', phone: '', country_code: 'CN', province: '', city: '', district: '', address: '', address2: '', postcode: '', is_default: false })
const form = reactive(empty())
const saving = ref(false)
const countryList = computed(() => countries(state.lang))
const isCN = computed(() => form.country_code === 'CN')

// 中国大陆的省、市必须从标准名单里选（区域定价和运费按省市匹配），其他国家自由填写
const divisions = ref({})
const provinces = computed(() => Object.keys(divisions.value))
const cities = computed(() => divisions.value[form.province] || [])

watch(() => props.modelValue, (open) => {
  if (!open) return
  Object.assign(form, empty())
  const a = props.address
  if (a) {
    Object.keys(form).forEach((k) => { if (a[k] !== undefined && a[k] !== null) form[k] = a[k] })
    form.is_default = !!a.is_default
  }
  if (!provinces.value.length) getCnDivisions().then((d) => { divisions.value = d }).catch(() => {})
})

function onCountry(code) {
  const c = countryList.value.find((x) => x.code === code)
  Object.assign(form, { country_code: code, phone_area: c?.area || form.phone_area, province: '', city: '', district: '' })
}
function onProvince(p) {
  form.province = p
  form.city = cities.value.length === 1 ? cities.value[0] : ''
}

async function submit() {
  if (saving.value) return
  if (!form.name.trim()) return ElMessage.warning(t('address.needName'))
  if (!form.phone.trim()) return ElMessage.warning(t('address.needPhone'))
  if (isCN.value && (!form.province || !form.city)) return ElMessage.warning(t('address.needRegion'))
  if (!form.address.trim()) return ElMessage.warning(t('address.needDetail'))
  saving.value = true
  try {
    const payload = { ...form, is_default: form.is_default ? 1 : 0 }
    const r = props.address ? await updateAddress(props.address.id, payload) : await createAddress(payload)
    ElMessage.success(t('common.saved'))
    emit('update:modelValue', false)
    emit('saved', props.address ? props.address.id : (r?.id || 0))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-dialog :model-value="modelValue" :title="t(address ? 'address.edit' : 'address.add')" width="560px" destroy-on-close @update:model-value="emit('update:modelValue', $event)">
    <el-form label-width="96px">
      <el-form-item :label="t('address.name')" required><el-input v-model="form.name" maxlength="60" /></el-form-item>
      <el-form-item :label="t('address.country')" required>
        <el-select :model-value="form.country_code" filterable style="width: 100%" @change="onCountry">
          <el-option v-for="c in countryList" :key="c.code" :label="c.name" :value="c.code" />
        </el-select>
      </el-form-item>
      <el-form-item :label="t('address.phone')" required>
        <el-input v-model="form.phone" maxlength="30"><template #prepend>+{{ form.phone_area }}</template></el-input>
      </el-form-item>

      <template v-if="isCN">
        <el-form-item :label="t('address.province')" required>
          <el-select :model-value="form.province" filterable :placeholder="t('address.choose')" style="width: 100%" @change="onProvince">
            <el-option v-for="p in provinces" :key="p" :label="p" :value="p" />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('address.city')" required>
          <el-select v-model="form.city" filterable :disabled="!form.province" :placeholder="t('address.choose')" style="width: 100%">
            <el-option v-for="c in cities" :key="c" :label="c" :value="c" />
          </el-select>
        </el-form-item>
      </template>
      <template v-else>
        <el-form-item :label="t('address.province')"><el-input v-model="form.province" maxlength="60" /></el-form-item>
        <el-form-item :label="t('address.city')"><el-input v-model="form.city" maxlength="60" /></el-form-item>
      </template>
      <el-form-item :label="t('address.district')"><el-input v-model="form.district" maxlength="60" /></el-form-item>
      <el-form-item :label="t('address.detail')" required><el-input v-model="form.address" maxlength="255" /></el-form-item>
      <el-form-item :label="t('address.postcode')"><el-input v-model="form.postcode" maxlength="20" style="width: 200px" /></el-form-item>
      <el-form-item label=" "><el-checkbox v-model="form.is_default">{{ t('address.setDefault') }}</el-checkbox></el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</el-button>
      <el-button type="primary" :loading="saving" @click="submit">{{ t('common.save') }}</el-button>
    </template>
  </el-dialog>
</template>
