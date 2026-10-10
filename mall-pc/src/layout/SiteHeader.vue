<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ShoppingCart, Search, User } from '@element-plus/icons-vue'
import { state, setLang, setCurrency, logout } from '@/store'
import { updateProfile, logoutApi } from '@/api'
import { applyLang } from '@/locale'
import { confirm } from '@/utils/app'
import SiteLogo from '@/components/SiteLogo.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const keyword = ref('')
// 进入搜索结果页时，搜索框里显示当前的关键词
watch(() => route.query.keyword, (k) => { keyword.value = route.name === 'goods' ? String(k || '') : '' }, { immediate: true })

function search() {
  const k = keyword.value.trim()
  router.push({ name: 'goods', query: k ? { keyword: k } : {} })
}

// 语言和币种：本地立即生效；已登录时同步到会员资料，换设备登录后还在
function onLang(code) {
  setLang(code)
  applyLang(code)
  if (state.token) updateProfile({ lang: code }).catch(() => {})
}
function onCurrency(code) {
  setCurrency(code)
  if (state.token) updateProfile({ currency: code }).catch(() => {})
}

async function onCommand(cmd) {
  if (cmd === 'logout') {
    if (!(await confirm(t('user.logoutConfirm')))) return
    await logoutApi().catch(() => {})
    logout()
    router.push({ name: 'home' })
  } else {
    router.push({ name: cmd })
  }
}
</script>

<template>
  <header>
    <!-- 顶部细条：左边语言和币种，右边登录入口和购物车 -->
    <div class="top">
      <div class="container top-inner">
        <div class="side">
          <el-select v-if="state.config.languages.length > 1" :model-value="state.lang" size="small" class="pick" @change="onLang">
            <el-option v-for="l in state.config.languages" :key="l.code" :label="l.name" :value="l.code" />
          </el-select>
          <el-select v-if="state.config.currencies.length > 1" :model-value="state.currency" size="small" class="pick" @change="onCurrency">
            <el-option v-for="c in state.config.currencies" :key="c.code" :label="`${c.code} ${c.symbol}`" :value="c.code" />
          </el-select>
        </div>
        <div class="side">
          <el-dropdown v-if="state.member" @command="onCommand">
            <span class="user"><el-icon><User /></el-icon>{{ t('pc.hello', { name: state.member.nickname }) }} ▾</span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="orders">{{ t('pc.myOrders') }}</el-dropdown-item>
                <el-dropdown-item command="user">{{ t('pc.account') }}</el-dropdown-item>
                <el-dropdown-item command="refunds">{{ t('userMenu.refunds') }}</el-dropdown-item>
                <el-dropdown-item command="logout" divided>{{ t('pc.logout') }}</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
          <router-link v-else :to="{ name: 'login' }" class="user"><el-icon><User /></el-icon>{{ t('pc.loginOrRegister') }}</router-link>
          <router-link :to="{ name: 'cart' }" class="cart">
            <el-badge :value="state.cartCount" :hidden="!state.cartCount" :max="99">
              <span class="cart-link"><el-icon><ShoppingCart /></el-icon>{{ t('cart.title') }}</span>
            </el-badge>
          </router-link>
        </div>
      </div>
    </div>

    <!-- 主导航：标识、栏目、搜索 -->
    <div class="bar">
      <div class="container bar-inner">
        <SiteLogo />
        <nav class="nav">
          <router-link :to="{ name: 'home' }" class="nav-item">{{ t('pc.home') }}</router-link>
          <router-link :to="{ name: 'goods' }" class="nav-item">{{ t('category.all') }}</router-link>
          <router-link :to="{ name: 'articles', query: { type: 'article' } }" class="nav-item" :class="{ on: $route.name === 'articles' && $route.query.type !== 'help' }">{{ t('article.articles') }}</router-link>
          <router-link :to="{ name: 'articles', query: { type: 'help' } }" class="nav-item" :class="{ on: $route.name === 'articles' && $route.query.type === 'help' }">{{ t('article.help') }}</router-link>
        </nav>
        <div class="search">
          <el-input v-model="keyword" :placeholder="t('home.search')" size="large" clearable @keyup.enter="search">
            <template #suffix><el-icon class="search-btn" @click="search"><Search /></el-icon></template>
          </el-input>
        </div>
      </div>
    </div>
  </header>
</template>

<style scoped>
.top { background: var(--cream-deep); }
.top-inner { height: 34px; display: flex; align-items: center; justify-content: space-between; }
.side { display: flex; align-items: center; gap: 20px; }
.pick { width: 112px; }
.pick :deep(.el-select__wrapper) { background: transparent; box-shadow: none !important; padding-left: 0; }
.user, .cart-link { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; color: var(--text); font-size: 13px; outline: none; }
.user:hover, .cart-link:hover { color: var(--theme); }
.cart :deep(.el-badge__content.is-fixed) { top: 2px; right: 2px; transform: translate(100%, -50%); }

.bar { background: var(--bg); border-bottom: 1px solid var(--line); }
.bar-inner { height: 88px; display: flex; align-items: center; }
.nav { display: flex; gap: 44px; margin-left: 72px; }
.nav-item { position: relative; padding: 8px 0; font-size: 16px; letter-spacing: 0.06em; }
.nav-item:hover { color: var(--theme); }
/* 当前栏目：文字变主题色，下面一道短线。资讯和帮助中心是同一个页面的两个页签，靠 on 区分 */
.nav-item.router-link-exact-active, .nav-item.on { color: var(--theme); }
.nav-item.router-link-exact-active::after, .nav-item.on::after { content: ''; position: absolute; left: 0; right: 0; bottom: -2px; height: 2px; border-radius: 1px; background: var(--theme); }
.search { margin-left: auto; width: 320px; }
.search :deep(.el-input__wrapper) { border-radius: 22px; padding: 0 18px; background: var(--cream-deep); box-shadow: 0 0 0 1px var(--line) inset; }
.search :deep(.el-input__wrapper.is-focus) { box-shadow: 0 0 0 1px var(--theme) inset; background: #fff; }
.search-btn { font-size: 18px; color: var(--text); cursor: pointer; }
.search-btn:hover { color: var(--theme); }
</style>
