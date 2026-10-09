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

// 全局注册 Element Plus 图标，菜单的 icon 字段直接写图标名（如 Goods、Setting）
for (const [name, comp] of Object.entries(Icons)) {
  app.component(name, comp)
}

app.use(createPinia())
app.use(router)
app.use(ElementPlus, { locale: zhCn })
setupPermDirective(app)
app.mount('#app')
