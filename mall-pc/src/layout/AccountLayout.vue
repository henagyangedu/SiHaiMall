<script setup>
import { useI18n } from 'vue-i18n'

// 会员中心的外壳：左侧菜单 + 右侧内容。订单详情、售后详情等子页面高亮它们所属的菜单项
const { t } = useI18n()
const MENU = [
  { name: 'user', label: 'pc.overview', match: ['user'] },
  { name: 'orders', label: 'user.orders', match: ['orders', 'order-detail', 'refund-apply'] },
  { name: 'refunds', label: 'userMenu.refunds', match: ['refunds', 'refund-detail'] },
  { name: 'coupons', label: 'coupon.title', match: ['coupons'] },
  { name: 'favorites', label: 'fav.title', match: ['favorites'] },
  { name: 'points', label: 'userMenu.points', match: ['points'] },
  { name: 'distribution', label: 'userMenu.distribution', match: ['distribution'] },
  { name: 'profile', label: 'profile.title', match: ['profile'] },
  { name: 'security', label: 'security.title', match: ['security'] },
]
</script>

<template>
  <div class="wrap">
    <aside class="panel menu">
      <router-link v-for="m in MENU" :key="m.name" :to="{ name: m.name }" class="item" :class="{ on: m.match.includes($route.name) }">{{ t(m.label) }}</router-link>
    </aside>
    <section class="content">
      <router-view />
    </section>
  </div>
</template>

<style scoped>
.wrap { display: flex; gap: 16px; align-items: flex-start; }
.menu { width: 200px; flex: none; padding: 12px 0; }
.item { display: block; padding: 12px 24px; }
.item:hover { color: var(--theme); }
.item.on { color: var(--theme); background: var(--el-color-primary-light-9); font-weight: 600; border-right: 3px solid var(--theme); }
.content { flex: 1; min-width: 0; }
</style>
