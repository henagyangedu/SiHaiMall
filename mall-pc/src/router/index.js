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
  { path: '/reset-password', name: 'reset-password', component: () => import('@/pages/ResetPassword.vue') },
  // 资讯 / 公告 / 帮助中心共用一个列表页：?type=article|notice|help
  { path: '/articles', name: 'articles', component: () => import('@/pages/Articles.vue') },
  { path: '/articles/:id(\\d+)', name: 'article-detail', component: () => import('@/pages/ArticleDetail.vue') },
  // 不带 key 列出所有协议，带 key 显示这一份的正文
  { path: '/protocols/:key?', name: 'protocols', component: () => import('@/pages/Protocols.vue') },
  // 会员中心：左侧菜单 + 右侧内容
  {
    path: '/', component: () => import('@/layout/AccountLayout.vue'), meta: { auth: true },
    children: [
      { path: 'user', name: 'user', component: () => import('@/pages/User.vue') },
      { path: 'orders', name: 'orders', component: () => import('@/pages/OrderList.vue') },
      { path: 'orders/:orderNo', name: 'order-detail', component: () => import('@/pages/OrderDetail.vue') },
      { path: 'orders/:orderNo/refund', name: 'refund-apply', component: () => import('@/pages/RefundApply.vue') },
      { path: 'refunds', name: 'refunds', component: () => import('@/pages/RefundList.vue') },
      { path: 'refunds/:refundNo', name: 'refund-detail', component: () => import('@/pages/RefundDetail.vue') },
      { path: 'favorites', name: 'favorites', component: () => import('@/pages/Favorites.vue') },
      { path: 'points', name: 'points', component: () => import('@/pages/Points.vue') },
      { path: 'profile', name: 'profile', component: () => import('@/pages/Profile.vue') },
      { path: 'security', name: 'security', component: () => import('@/pages/Security.vue') },
      { path: 'distribution', name: 'distribution', component: () => import('@/pages/Distribution.vue') },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFound.vue') },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

// 需要登录的页面：没有令牌先去登录，登录后回来
router.beforeEach((to) => {
  if (to.matched.some((r) => r.meta.auth) && !state.token) return { name: 'login', query: { redirect: to.fullPath } }
  return true
})

export default router
