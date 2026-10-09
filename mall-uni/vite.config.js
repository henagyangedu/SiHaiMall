import { defineConfig, loadEnv } from 'vite'
import uni from '@dcloudio/vite-plugin-uni'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    plugins: [uni()],
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    // 新版 sass 对旧接口的弃用提示来自 uni-app 的编译器，与业务代码无关，关掉以免刷屏
    css: { preprocessorOptions: { scss: { silenceDeprecations: ['legacy-js-api'] } } },
    server: {
      host: '127.0.0.1',
      port: 5180,
      // H5 开发时把 /api 转发给后端，浏览器不会遇到跨域
      proxy: {
        '/api': { target: env.VITE_API_PROXY || 'http://127.0.0.1:8000', changeOrigin: true, secure: false },
      },
    },
  }
})
