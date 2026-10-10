<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ShoppingCart, Search, User, Bell } from '@element-plus/icons-vue'
import { state, setLang, setCurrency, logout } from '@/store'
import { updateProfile, logoutApi } from '@/api'
import { applyLang } from '@/locale'
import { confirm, refreshUnread } from '@/utils/app'
import { searchHistory, addSearchHistory, clearSearchHistory } from '@/utils/search'
import SiteLogo from '@/components/SiteLogo.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const keyword = ref('')
// 进入搜索结果页时，搜索框里显示当前的关键词
watch(() => route.query.keyword, (k) => { keyword.value = route.name === 'goods' ? String(k || '') : '' }, { immediate: true })

// 未读消息数：登录、退出、每次换页面时刷新一次（不做实时推送）
watch(() => [route.fullPath, state.token], refreshUnread, { immediate: true })

// 点开搜索框时在下面展开：搜索历史 + 后台配的热门搜索词；点别处收起。
// 搜索之后焦点还留在输入框里，再点它不会触发 focus，所以点击时也展开
const history = ref(searchHistory())
const dropOpen = ref(false)
const searchBox = ref(null)
function search(word) {
  const k = (typeof word === 'string' ? word : keyword.value).trim()
  keyword.value = k
  dropOpen.value = false
  if (k) history.value = addSearchHistory(k)
  router.push({ name: 'goods', query: k ? { keyword: k } : {} })
}
const onDocDown = (e) => { if (searchBox.value && !searchBox.value.contains(e.target)) dropOpen.value = false }
onMounted(() => document.addEventListener('mousedown', onDocDown))
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocDown))

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
          <router-link v-if="state.member" :to="{ name: 'messages' }" class="cart msg-link">
            <el-badge :value="state.unread" :hidden="!state.unread" :max="99">
              <span class="cart-link"><el-icon><Bell /></el-icon>{{ t('msg.title') }}</span>
            </el-badge>
          </router-link>
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
        <div ref="searchBox" class="search">
          <el-input v-model="keyword" :placeholder="t('home.search')" size="large" clearable @focus="dropOpen = true" @click="dropOpen = true" @keyup.enter="search()">
            <template #suffix><el-icon class="search-btn" @click="search()"><Search /></el-icon></template>
          </el-input>
          <div v-if="dropOpen && (history.length || (state.config.site?.hot_words || []).length)" class="search-drop">
            <template v-if="history.length">
              <div class="words-head"><span>{{ t('search.history') }}</span><a class="words-clear" @click="history = clearSearchHistory()">{{ t('search.clear') }}</a></div>
              <div class="words"><a v-for="w in history" :key="w" class="word" @click="search(w)">{{ w }}</a></div>
            </template>
            <template v-if="(state.config.site?.hot_words || []).length">
              <div class="words-head"><span>{{ t('search.hot') }}</span></div>
              <div class="words"><a v-for="w in state.config.site.hot_words" :key="w" class="word hot" @click="search(w)">{{ w }}</a></div>
            </template>
          </div>
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
.msg-link { margin-right: 28px; }
.cart :deep(.el-badge__content.is-fixed) { top: 2px; right: 2px; transform: translate(100%, -50%); }

.bar { background: var(--bg); border-bottom: 1px solid var(--line); }
.bar-inner { height: 88px; display: flex; align-items: center; }
.nav { display: flex; gap: 44px; margin-left: 72px; }
.nav-item { position: relative; padding: 8px 0; font-size: 16px; letter-spacing: 0.06em; }
.nav-item:hover { color: var(--theme); }
/* 当前栏目：文字变主题色，下面一道短线。资讯和帮助中心是同一个页面的两个页签，靠 on 区分 */
.nav-item.router-link-exact-active, .nav-item.on { color: var(--theme); }
.nav-item.router-link-exact-active::after, .nav-item.on::after { content: ''; position: absolute; left: 0; right: 0; bottom: -2px; height: 2px; border-radius: 1px; background: var(--theme); }
.search { position: relative; margin-left: auto; width: 320px; }
.search-drop { position: absolute; z-index: 20; top: calc(100% + 8px); left: 0; right: 0; padding: 4px 16px 16px; background: #fff; border: 1px solid var(--line); border-radius: var(--radius); box-shadow: 0 8px 24px rgba(41, 43, 39, 0.08); }
.words-head { display: flex; justify-content: space-between; align-items: center; padding: 12px 0 8px; font-size: 13px; font-weight: 600; }
.words-clear { font-weight: 400; color: var(--sub); cursor: pointer; }
.words-clear:hover { color: var(--theme); }
.words { display: flex; flex-wrap: wrap; gap: 8px; }
.word { max-width: 100%; padding: 0 12px; height: 28px; line-height: 28px; border-radius: 14px; background: var(--cream-deep); font-size: 13px; cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.word.hot { background: #fff; box-shadow: 0 0 0 1px var(--line) inset; }
.word:hover { color: var(--theme); }
.search :deep(.el-input__wrapper) { border-radius: 22px; padding: 0 18px; background: var(--cream-deep); box-shadow: 0 0 0 1px var(--line) inset; }
.search :deep(.el-input__wrapper.is-focus) { box-shadow: 0 0 0 1px var(--theme) inset; background: #fff; }
.search-btn { font-size: 18px; color: var(--text); cursor: pointer; }
.search-btn:hover { color: var(--theme); }
</style>
