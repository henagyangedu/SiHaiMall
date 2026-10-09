import { createRouter, createWebHashHistory } from 'vue-router'
import { state } from '@/store'

// 用 hash 路由：打包后的静态文件放到任何目录都能用，不需要服务器配置回退规则
const routes = [
  { path: '/', name: 'home', component: () => import('@/pages/Home.vue') },
  // 分类浏览和关键词搜索共用一个列表页：?category_id= / ?keyword=
  { path: '/goods', name: 'goods', component: () => import('@/pages/GoodsList.vue') },
  { path: '/goods/:id(\\d+)', name: 'goods-detail', component: () => import('@/pages/GoodsDetail.vue') },
  { path: '/cart', name: 'cart', component: () => import('@/pages/Cart.vue') },
  { path: '/login', name: 'login', component: () => import('@/pages/Login.vue') },
  { path: '/checkout', name: 'checkout', component: () => import('@/pages/Checkout.vue'), meta: { auth: true } },
  { path: '/orders', name: 'orders', component: () => import('@/pages/OrderList.vue'), meta: { auth: true } },
  { path: '/orders/:orderNo', name: 'order-detail', component: () => import('@/pages/OrderDetail.vue'), meta: { auth: true } },
  { path: '/user', name: 'user', component: () => import('@/pages/User.vue'), meta: { auth: true } },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFound.vue') },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

// 需要登录的页面：没有令牌先去登录，登录后回来
router.beforeEach((to) => {
  if (to.meta.auth && !state.token) return { name: 'login', query: { redirect: to.fullPath } }
  return true
})

export default router
