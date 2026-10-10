<script setup>
import { useI18n } from 'vue-i18n'
import { Plus } from '@element-plus/icons-vue'

// 商品卡片。g：商品列表接口返回的 { id, title, subtitle, cover, price, symbol, sales, sold_out, multi_spec }
defineProps({ g: { type: Object, required: true } })
const { t } = useI18n()
</script>

<template>
  <router-link :to="{ name: 'goods-detail', params: { id: g.id } }" class="card">
    <div class="cover">
      <img v-if="g.cover" :src="g.cover" :alt="g.title" loading="lazy" />
      <span v-if="g.multi_spec" class="tag">{{ t('pc.multiSpec') }}</span>
      <div v-if="g.sold_out" class="mask">{{ t('home.soldOut') }}</div>
    </div>
    <div class="info">
      <div class="title ellipsis">{{ g.title }}</div>
      <div class="desc ellipsis">{{ g.subtitle }}</div>
      <div class="row">
        <div>
          <!-- 多规格商品显示的是最低价 -->
          <span class="price">{{ g.symbol }}{{ g.price }}</span><span v-if="g.multi_spec" class="from">{{ t('pc.priceFrom') }}</span>
          <div class="sold">{{ t('home.sold', { n: g.sales }) }}</div>
        </div>
        <span class="add"><el-icon><Plus /></el-icon></span>
      </div>
    </div>
  </router-link>
</template>

<style scoped>
.card { display: block; background: #fff; border-radius: var(--radius); overflow: hidden; transition: transform 0.18s, box-shadow 0.18s; }
.card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(41, 43, 39, 0.1); }
.cover { position: relative; width: 100%; padding-top: 78%; background: var(--cream-deep); }
.cover img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.tag { position: absolute; left: 12px; top: 12px; padding: 3px 10px; border-radius: 12px; background: rgba(41, 43, 39, 0.55); color: #fff; font-size: 12px; }
.mask { position: absolute; inset: 0; background: rgba(41, 43, 39, 0.45); color: #fff; display: flex; align-items: center; justify-content: center; }
.info { padding: 16px 18px 18px; }
.title { font-size: 15px; font-weight: 600; }
.desc { margin-top: 6px; min-height: 18px; color: var(--sub); font-size: 12px; }
.row { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 12px; }
.price { color: var(--theme); font-size: 19px; font-weight: 700; }
.from { margin-left: 4px; color: var(--theme); font-size: 12px; }
.sold { margin-top: 6px; color: var(--sub); font-size: 12px; }
.add { width: 34px; height: 34px; border-radius: 50%; background: var(--theme); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 16px; flex: none; transition: background 0.15s; }
.card:hover .add { background: var(--theme-dark); }
</style>
