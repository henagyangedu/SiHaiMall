import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import * as Icons from '@element-plus/icons-vue'
import 'element-plus/dist/index.css'
import App from './App.vue'
import router from './router'
import { setupPermDirective } from './directives/perm'
import './styles/index.css'

const app = createApp(App)

// 接口出错时，请求层（api/request.js）已经弹过提示，并以后端的 {code, msg} 或 axios 的错误对象拒绝。
// 页面里不必每处都 catch，这里统一接住，不让它们作为“未处理的错误”出现在控制台；真正的程序错误照常报出来
// 确认框点了取消或关闭时，组件库以 'cancel' / 'close' 拒绝，同样不算错误
const handled = (e) => e === 'cancel' || e === 'close'
  || (!!e && typeof e === 'object' && (('code' in e && 'msg' in e) || e.isAxiosError === true))
app.config.errorHandler = (err) => {
  if (!handled(err)) console.error(err)
}
window.addEventListener('unhandledrejection', (e) => {
  if (handled(e.reason)) e.preventDefault()
})

// 全局注册 Element Plus 图标，菜单的 icon 字段直接写图标名（如 Goods、Setting）
for (const [name, comp] of Object.entries(Icons)) {
  app.component(name, comp)
}

app.use(createPinia())
app.use(router)
app.use(ElementPlus, { locale: zhCn })
setupPermDirective(app)
app.mount('#app')
