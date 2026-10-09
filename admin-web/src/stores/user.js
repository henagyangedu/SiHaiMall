import { defineStore } from 'pinia'
import { login as loginApi, getInfo, logout as logoutApi } from '@/api/auth'
import { TOKEN_KEY } from '@/api/request'

export const useUserStore = defineStore('user', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || '',
    info: null,
    loaded: false,
  }),
  getters: {
    menus: (s) => s.info?.menus || [],
    perms: (s) => s.info?.perms || [],
    isSuper: (s) => !!s.info?.is_super,
  },
  actions: {
    async login(form) {
      const data = await loginApi(form)
      this.token = data.token
      localStorage.setItem(TOKEN_KEY, data.token)
      this.loaded = false // 让路由守卫重新拉取菜单并注册路由
    },
    async fetchInfo() {
      this.info = await getInfo()
      this.loaded = true
    },
    async logout() {
      if (this.token) {
        try { await logoutApi() } catch (e) { /* 令牌已失效也要继续退出 */ }
      }
      this.clear()
    },
    clear() {
      this.token = ''
      this.info = null
      this.loaded = false
      localStorage.removeItem(TOKEN_KEY)
    },
  },
})
