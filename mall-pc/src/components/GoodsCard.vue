<script setup>
import { useI18n } from 'vue-i18n'

// 商品卡片。g：商品列表接口返回的 { id, title, cover, price, symbol, sales, sold_out }
defineProps({ g: { type: Object, required: true } })
const { t } = useI18n()
</script>

<template>
  <router-link :to="{ name: 'goods-detail', params: { id: g.id } }" class="card">
    <div class="cover">
      <img v-if="g.cover" :src="g.cover" :alt="g.title" loading="lazy" />
      <div v-if="g.sold_out" class="mask">{{ t('home.soldOut') }}</div>
    </div>
    <div class="info">
      <div class="title ellipsis-2">{{ g.title }}</div>
      <div class="row">
        <span class="price">{{ g.symbol }}{{ g.price }}</span>
        <span class="sub">{{ t('home.sold', { n: g.sales }) }}</span>
      </div>
    </div>
  </router-link>
</template>

<style scoped>
.card { display: block; background: #fff; border-radius: 8px; overflow: hidden; border: 1px solid transparent; transition: border-color 0.15s, box-shadow 0.15s; }
.card:hover { border-color: var(--theme); box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08); }
.cover { position: relative; width: 100%; padding-top: 100%; background: #f0f1f3; }
.cover img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.mask { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.45); color: #fff; display: flex; align-items: center; justify-content: center; }
.info { padding: 12px; }
.title { height: 40px; line-height: 20px; }
.row { display: flex; justify-content: space-between; align-items: baseline; margin-top: 8px; }
.price { font-size: 18px; }
</style>
