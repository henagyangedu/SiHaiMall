<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ShoppingCart, Search } from '@element-plus/icons-vue'
import { state, setLang, setCurrency, logout } from '@/store'
import { updateProfile, logoutApi } from '@/api'
import { applyLang } from '@/locale'
import { confirm } from '@/utils/app'

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
    <div class="top">
      <div class="container top-inner">
        <span class="sub">{{ state.config.site?.site_desc || '' }}</span>
        <div class="right">
          <el-select v-if="state.config.languages.length > 1" :model-value="state.lang" size="small" class="pick" @change="onLang">
            <el-option v-for="l in state.config.languages" :key="l.code" :label="l.name" :value="l.code" />
          </el-select>
          <el-select v-if="state.config.currencies.length > 1" :model-value="state.currency" size="small" class="pick" @change="onCurrency">
            <el-option v-for="c in state.config.currencies" :key="c.code" :label="`${c.code} ${c.symbol}`" :value="c.code" />
          </el-select>
          <el-dropdown v-if="state.member" @command="onCommand">
            <span class="user">{{ t('pc.hello', { name: state.member.nickname }) }} ▾</span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="orders">{{ t('pc.myOrders') }}</el-dropdown-item>
                <el-dropdown-item command="user">{{ t('pc.account') }}</el-dropdown-item>
                <el-dropdown-item command="refunds">{{ t('userMenu.refunds') }}</el-dropdown-item>
                <el-dropdown-item command="logout" divided>{{ t('pc.logout') }}</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
          <router-link v-else :to="{ name: 'login' }" class="user">{{ t('pc.loginOrRegister') }}</router-link>
        </div>
      </div>
    </div>

    <div class="bar">
      <div class="container bar-inner">
        <router-link :to="{ name: 'home' }" class="logo">
          <img v-if="state.config.site?.site_logo" :src="state.config.site.site_logo" alt="" />
          <span v-else>{{ state.config.site?.site_name || t('tab.home') }}</span>
        </router-link>

        <div class="search">
          <el-input v-model="keyword" :placeholder="t('home.search')" size="large" clearable @keyup.enter="search">
            <template #append><el-button :icon="Search" @click="search">{{ t('pc.searchBtn') }}</el-button></template>
          </el-input>
        </div>

        <router-link :to="{ name: 'cart' }" class="cart">
          <el-badge :value="state.cartCount" :hidden="!state.cartCount" :max="99">
            <el-button size="large" :icon="ShoppingCart">{{ t('cart.title') }}</el-button>
          </el-badge>
        </router-link>
      </div>
    </div>

    <nav class="nav">
      <div class="container">
        <router-link :to="{ name: 'home' }" class="nav-item">{{ t('pc.home') }}</router-link>
        <router-link :to="{ name: 'goods' }" class="nav-item">{{ t('category.all') }}</router-link>
        <router-link :to="{ name: 'articles', query: { type: 'article' } }" class="nav-item">{{ t('article.articles') }}</router-link>
        <router-link :to="{ name: 'articles', query: { type: 'help' } }" class="nav-item">{{ t('article.help') }}</router-link>
      </div>
    </nav>
  </header>
</template>

<style scoped>
.top { background: #fafafa; border-bottom: 1px solid var(--line); }
.top-inner { height: 36px; display: flex; align-items: center; justify-content: space-between; }
.right { display: flex; align-items: center; gap: 12px; }
.pick { width: 120px; }
.user { cursor: pointer; color: var(--text); font-size: 13px; outline: none; }
.user:hover { color: var(--theme); }
.bar { background: #fff; }
.bar-inner { height: 96px; display: flex; align-items: center; gap: 40px; }
.logo { font-size: 26px; font-weight: 700; color: var(--theme); flex: none; max-width: 240px; }
.logo img { max-height: 56px; display: block; }
.search { flex: 1; max-width: 620px; }
.cart { margin-left: auto; }
.nav { background: #fff; border-top: 1px solid var(--line); border-bottom: 2px solid var(--theme); }
.nav-item { display: inline-block; padding: 12px 24px 12px 0; font-size: 15px; }
.nav-item:hover, .nav-item.router-link-exact-active { color: var(--theme); }
</style>
