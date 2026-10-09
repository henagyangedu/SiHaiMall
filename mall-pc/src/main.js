import { createApp } from 'vue'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import App from './App.vue'
import router from './router'
import { i18n } from './locale'
import { bootstrap } from './utils/app'
import './styles/main.css'

// 先取启动配置（语言、币种、登录态）再挂载，页面一出来就是正确的语言和价格
bootstrap().finally(() => {
  createApp(App).use(ElementPlus).use(router).use(i18n).mount('#app')
})
