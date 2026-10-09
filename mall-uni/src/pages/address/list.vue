<script setup>
import { ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getAddresses, deleteAddress } from '@/api'
import { countryName } from '@/utils/countries'
import { usePageTitle, confirm, toast, nav } from '@/utils/page'

const { t } = useI18n()
usePageTitle('address.list')

const list = ref([])
const loaded = ref(false)
// 从结算页进来是“选择地址”模式：点一条就带回去
const selecting = ref(false)

async function load() {
  list.value = await getAddresses()
  loaded.value = true
}
function onTap(a) {
  if (!selecting.value) return nav(`/pages/address/edit?id=${a.id}`)
  state.pickedAddressId = a.id
  uni.navigateBack()
}
async function onDelete(a) {
  if (!(await confirm(t('address.deleteConfirm')))) return
  await deleteAddress(a.id)
  toast(t('common.deleted'))
  load()
}
const full = (a) => [countryName(a.country_code, state.lang), a.province, a.city, a.district, a.address, a.address2].filter(Boolean).join(' ')

onLoad((q) => { selecting.value = q.select === '1' })
onShow(load)
</script>

<template>
  <view class="page">
    <view v-for="a in list" :key="a.id" class="card" @click="onTap(a)">
      <view>
        <text class="name">{{ a.name }}</text><text class="sub">　+{{ a.phone_area }} {{ a.phone }}</text>
        <text v-if="a.is_default" class="tag">{{ t('address.default') }}</text>
      </view>
      <view class="sub">{{ full(a) }}</view>
      <view class="ops" @click.stop>
        <text class="op" @click="nav(`/pages/address/edit?id=${a.id}`)">{{ t('common.edit') }}</text>
        <text class="op" @click="onDelete(a)">{{ t('common.delete') }}</text>
      </view>
    </view>
    <view v-if="loaded && !list.length" class="empty">{{ t('address.empty') }}</view>

    <view class="bar safe-bottom"><view class="btn" @click="nav('/pages/address/edit')">＋ {{ t('address.add') }}</view></view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding-bottom: 180rpx; }
.name { font-weight: 600; font-size: 30rpx; }
.tag { color: var(--theme); border: 2rpx solid var(--theme); border-radius: 8rpx; padding: 0 10rpx; font-size: 22rpx; margin-left: 16rpx; }
.ops { text-align: right; margin-top: 12rpx; }
.op { color: #606266; margin-left: 32rpx; font-size: 26rpx; }
.bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; padding: 16rpx 24rpx; }
</style>
