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
  const app = createApp(App)
  // 接口返回业务错误时，请求层已经弹过提示并以 {code, msg} 拒绝；页面里不必每处都 catch，这里统一接住，不当成程序错误
  app.config.errorHandler = (err) => {
    if (err && typeof err === 'object' && 'code' in err && 'msg' in err) return
    console.error(err)
  }
  app.use(ElementPlus).use(router).use(i18n).mount('#app')
})
