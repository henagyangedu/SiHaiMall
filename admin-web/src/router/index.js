import { createRouter, createWebHistory } from 'vue-router'
import { useUserStore } from '@/stores/user'
import AdminLayout from '@/layout/AdminLayout.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'Login', component: () => import('@/views/login/index.vue'), meta: { title: '登录' } },
    {
      path: '/',
      name: 'Layout',
      component: AdminLayout,
      redirect: '/dashboard',
      children: [
        { path: 'dashboard', name: 'Dashboard', component: () => import('@/views/dashboard/index.vue'), meta: { title: '首页' } },
      ],
    },
  ],
})

// 菜单对应的页面：views/<menu.component>/index.vue；找不到则显示“开发中”
const modules = import.meta.glob('../views/**/index.vue')
let added = []

function flatten(menus, out = []) {
  for (const m of menus) {
    if (m.type === 2 && m.path) out.push(m)
    if (m.children && m.children.length) flatten(m.children, out)
  }
  return out
}

export function registerRoutes(menus) {
  for (const m of flatten(menus)) {
    const name = `m${m.id}`
    if (router.hasRoute(name)) continue
    const loader = modules[`../views/${m.component}/index.vue`] || (() => import('@/views/common/Developing.vue'))
    router.addRoute('Layout', { path: m.path, name, component: loader, meta: { title: m.title_key } })
    added.push(name)
  }
  if (!router.hasRoute('NotFound')) {
    router.addRoute({ path: '/:pathMatch(.*)*', name: 'NotFound', component: () => import('@/views/error/NotFound.vue') })
    added.push('NotFound')
  }
}

export function resetRoutes() {
  added.forEach((n) => router.hasRoute(n) && router.removeRoute(n))
  added = []
}

router.beforeEach(async (to) => {
  const store = useUserStore()
  document.title = (to.meta.title ? to.meta.title + ' - ' : '') + '商城管理后台'

  if (to.path === '/login') return store.token ? '/' : true
  if (!store.token) return { path: '/login', query: { redirect: to.fullPath } }

  if (!store.loaded) {
    try {
      await store.fetchInfo()
      registerRoutes(store.menus)
      return { path: to.fullPath, replace: true } // 路由刚注册完，重新匹配一次
    } catch (e) {
      store.clear()
      return '/login'
    }
  }
  return true
})

export default router
