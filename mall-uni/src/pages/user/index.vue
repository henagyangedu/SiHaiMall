<script setup>
import { computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state, setLang, setCurrency, logout } from '@/store'
import { getProfile, updateProfile, logoutApi } from '@/api'
import { toLogin, needLogin } from '@/utils/request'
import { applyLang } from '@/locale'
import { refreshCartCount } from '@/utils/app'
import { usePageTitle, confirm, nav } from '@/utils/page'

const { t } = useI18n()
usePageTitle('user.title')

const ORDER_TABS = [
  { key: 'unpaid', label: 'order.tabUnpaid' }, { key: 'to_ship', label: 'order.tabToShip' },
  { key: 'shipped', label: 'order.tabShipped' }, { key: 'finished', label: 'order.tabFinished' },
]
const langIndex = computed(() => Math.max(0, state.config.languages.findIndex((l) => l.code === state.lang)))
const curIndex = computed(() => Math.max(0, state.config.currencies.findIndex((c) => c.code === state.currency)))
const curLabel = (c) => `${c.code} ${c.symbol}`

// 语言和币种：本地立即生效；已登录时同步到会员资料，换设备登录后还在
function onLang(e) {
  const l = state.config.languages[Number(e.detail.value)]
  setLang(l.code)
  applyLang(l.code)
  if (state.token) updateProfile({ lang: l.code }).catch(() => {})
}
function onCurrency(e) {
  const c = state.config.currencies[Number(e.detail.value)]
  setCurrency(c.code)
  if (state.token) updateProfile({ currency: c.code }).catch(() => {})
}
async function onLogout() {
  if (!(await confirm(t('user.logoutConfirm')))) return
  await logoutApi().catch(() => {})
  logout()
  refreshCartCount()
}
const orders = (tab = 'all') => needLogin(() => nav(`/pages/order/list?tab=${tab}`))

onShow(async () => {
  if (!state.token) return
  try { state.member = await getProfile() } catch (e) { /* 令牌失效时请求层已清掉登录态 */ }
})
</script>

<template>
  <view>
    <view class="head" @click="state.token ? nav('/pages/user/profile') : toLogin()">
      <image v-if="state.member?.avatar" :src="state.member.avatar" mode="aspectFill" class="avatar" />
      <view v-else class="avatar">{{ (state.member?.nickname || '?').slice(0, 1) }}</view>
      <view v-if="state.member" class="who">
        <view class="nick">{{ state.member.nickname }}</view>
        <view class="meta" @click.stop="nav('/pages/user/points')">{{ t('user.points') }} {{ state.member.points }} ›</view>
      </view>
      <view v-else class="nick">{{ t('user.notLogin') }}</view>
      <text v-if="state.member" class="arrow">›</text>
    </view>

    <view class="card">
      <view class="title" @click="orders()"><text>{{ t('user.orders') }}</text><text class="sub">{{ t('common.all') }} ›</text></view>
      <view class="tabs">
        <view v-for="x in ORDER_TABS" :key="x.key" class="tab" @click="orders(x.key)">{{ t(x.label) }}</view>
      </view>
    </view>

    <view class="card list">
      <view class="cell" @click="needLogin(() => nav('/pages/refund/list'))"><text>{{ t('userMenu.refunds') }}</text><text class="sub">›</text></view>
      <view class="cell" @click="needLogin(() => nav('/pages/user/points'))"><text>{{ t('userMenu.points') }}</text><text class="sub">›</text></view>
      <view class="cell" @click="needLogin(() => nav('/pages/address/list'))"><text>{{ t('user.address') }}</text><text class="sub">›</text></view>
      <view class="cell" @click="needLogin(() => nav('/pages/user/security'))"><text>{{ t('security.title') }}</text><text class="sub">›</text></view>
      <view class="cell" @click="needLogin(() => nav('/pages/distribution/index'))"><text>{{ t('userMenu.distribution') }}</text><text class="sub">›</text></view>
    </view>

    <view class="card list">
      <view class="cell" @click="nav('/pages/article/list?type=article')"><text>{{ t('userMenu.articles') }}</text><text class="sub">›</text></view>
      <view class="cell" @click="nav('/pages/article/list?type=notice')"><text>{{ t('userMenu.notices') }}</text><text class="sub">›</text></view>
      <view class="cell" @click="nav('/pages/article/list?type=help')"><text>{{ t('userMenu.help') }}</text><text class="sub">›</text></view>
      <view class="cell" @click="nav('/pages/protocol/index')"><text>{{ t('userMenu.protocols') }}</text><text class="sub">›</text></view>
      <picker v-if="state.config.languages.length" :range="state.config.languages" range-key="name" :value="langIndex" @change="onLang">
        <view class="cell"><text>{{ t('user.language') }}</text><text class="sub">{{ state.config.languages[langIndex]?.name }} ›</text></view>
      </picker>
      <picker v-if="state.config.currencies.length" :range="state.config.currencies.map(curLabel)" :value="curIndex" @change="onCurrency">
        <view class="cell"><text>{{ t('user.currency') }}</text><text class="sub">{{ state.currency }} ›</text></view>
      </picker>
    </view>

    <view v-if="state.token" class="logout" @click="onLogout">{{ t('user.logout') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.head { background: var(--theme); color: #fff; padding: 60rpx 40rpx; display: flex; align-items: center; }
.avatar { width: 110rpx; height: 110rpx; border-radius: 50%; background: rgba(255, 255, 255, 0.25); text-align: center; line-height: 110rpx; font-size: 48rpx; margin-right: 28rpx; flex: none; }
.who { flex: 1; min-width: 0; }
.arrow { font-size: 40rpx; opacity: 0.8; }
.nick { font-size: 36rpx; font-weight: 600; }
.meta { font-size: 24rpx; opacity: 0.9; margin-top: 6rpx; }
.title { display: flex; justify-content: space-between; font-weight: 600; margin-bottom: 24rpx; }
.tabs { display: flex; }
.tab { flex: 1; text-align: center; color: #606266; font-size: 26rpx; padding: 12rpx 0; }
.list { padding: 0 24rpx; }
.cell { display: flex; justify-content: space-between; align-items: center; height: 100rpx; border-bottom: 2rpx solid var(--line); }
.list > :last-child .cell, .list > .cell:last-child { border-bottom: none; }
.logout { margin: 40rpx 24rpx; background: #fff; border-radius: 16rpx; text-align: center; height: 92rpx; line-height: 92rpx; color: var(--theme); }
</style>
