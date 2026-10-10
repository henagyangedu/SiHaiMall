<script setup>
import { ref, reactive, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getAddress, createAddress, updateAddress, getCnDivisions } from '@/api'
import { countries } from '@/utils/countries'
import { usePageTitle, toast } from '@/utils/page'

const { t } = useI18n()
const id = ref(0)
usePageTitle(() => t(id.value ? 'address.edit' : 'address.add'))

const form = reactive({
  name: '', phone_area: '86', phone: '', country_code: 'CN', province: '', city: '', district: '', address: '', address2: '', postcode: '', is_default: false,
})
const saving = ref(false)
const countryList = computed(() => countries(state.lang))
const countryIndex = computed(() => Math.max(0, countryList.value.findIndex((c) => c.code === form.country_code)))
const isCN = computed(() => form.country_code === 'CN')

// 中国大陆的省、市必须从标准名单里选（区域定价和运费按省市匹配），其他国家自由填写
const divisions = ref({})
const provinces = computed(() => Object.keys(divisions.value))
const cities = computed(() => divisions.value[form.province] || [])

function onCountry(e) {
  const c = countryList.value[Number(e.detail.value)]
  if (c.code === form.country_code) return
  Object.assign(form, { country_code: c.code, phone_area: c.area, province: '', city: '', district: '' })
}
function onProvince(e) {
  form.province = provinces.value[Number(e.detail.value)]
  form.city = cities.value.length === 1 ? cities.value[0] : ''
}
function onCity(e) {
  form.city = cities.value[Number(e.detail.value)]
}

async function submit() {
  if (saving.value) return
  if (!form.name.trim()) return toast(t('address.needName'))
  if (!form.phone.trim()) return toast(t('address.needPhone'))
  if (isCN.value && (!form.province || !form.city)) return toast(t('address.needRegion'))
  if (!form.address.trim()) return toast(t('address.needDetail'))
  saving.value = true
  try {
    const payload = { ...form, is_default: form.is_default ? 1 : 0 }
    if (id.value) await updateAddress(id.value, payload)
    else await createAddress(payload)
    toast(t('common.saved'))
    setTimeout(() => uni.navigateBack(), 400)
  } finally {
    saving.value = false
  }
}

onLoad(async (q) => {
  id.value = Number(q.id || 0)
  getCnDivisions().then((d) => { divisions.value = d }).catch(() => {})
  if (id.value) {
    const a = await getAddress(id.value)
    Object.keys(form).forEach((k) => { if (a[k] !== undefined && a[k] !== null) form[k] = a[k] })
    form.is_default = !!a.is_default
  }
})
</script>

<template>
  <view class="page">
    <view class="card">
      <view class="row"><text class="label">{{ t('address.name') }}</text><input v-model="form.name" class="input" maxlength="60" /></view>
      <view class="row">
        <text class="label">{{ t('address.phone') }}</text>
        <text class="area">+{{ form.phone_area }}</text><input v-model="form.phone" class="input" type="number" maxlength="30" />
      </view>
      <view class="row">
        <text class="label">{{ t('address.country') }}</text>
        <picker class="input" :range="countryList" range-key="name" :value="countryIndex" @change="onCountry"><view>{{ countryList[countryIndex].name }}</view></picker>
      </view>

      <template v-if="isCN">
        <view class="row">
          <text class="label">{{ t('address.province') }}</text>
          <picker class="input" :range="provinces" @change="onProvince"><view :class="{ ph: !form.province }">{{ form.province || t('address.choose') }}</view></picker>
        </view>
        <view class="row">
          <text class="label">{{ t('address.city') }}</text>
          <picker class="input" :range="cities" :disabled="!form.province" @change="onCity"><view :class="{ ph: !form.city }">{{ form.city || t('address.choose') }}</view></picker>
        </view>
      </template>
      <template v-else>
        <view class="row"><text class="label">{{ t('address.province') }}</text><input v-model="form.province" class="input" maxlength="60" /></view>
        <view class="row"><text class="label">{{ t('address.city') }}</text><input v-model="form.city" class="input" maxlength="60" /></view>
      </template>

      <view class="row"><text class="label">{{ t('address.district') }}</text><input v-model="form.district" class="input" maxlength="60" /></view>
      <view class="row"><text class="label">{{ t('address.detail') }}</text><input v-model="form.address" class="input" maxlength="255" /></view>
      <view class="row"><text class="label">{{ t('address.postcode') }}</text><input v-model="form.postcode" class="input" maxlength="20" /></view>
      <view class="row last" @click="form.is_default = !form.is_default">
        <text class="label wide">{{ t('address.setDefault') }}</text>
        <view class="switch" :class="{ on: form.is_default }"><view class="dot" /></view>
      </view>
    </view>
    <view class="btn submit" :class="{ disabled: saving }" @click="submit">{{ t('common.save') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.row { display: flex; align-items: center; min-height: 96rpx; border-bottom: 2rpx solid var(--line); }
.row.last { border-bottom: none; justify-content: space-between; }
.label { width: 180rpx; flex: none; color: #6a665e; }
.label.wide { width: auto; }
.area { color: var(--sub); margin-right: 12rpx; }
.input { flex: 1; min-width: 0; }
.ph { color: #c9c2b5; }
.switch { width: 84rpx; height: 48rpx; border-radius: 24rpx; background: #ded6c8; padding: 4rpx; box-sizing: border-box; }
.switch.on { background: var(--theme); }
.dot { width: 40rpx; height: 40rpx; border-radius: 50%; background: #fff; transition: transform 0.2s; }
.switch.on .dot { transform: translateX(36rpx); }
.submit { margin: 40rpx 24rpx; }
</style>
