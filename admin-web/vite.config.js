import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    plugins: [vue()],
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    server: {
		host:'127.0.0.1',
      port: 5173,
      // 开发环境把 /admin-api 转发给 Laravel，浏览器不会遇到跨域
      proxy: {
        '/admin-api': {
          target: env.VITE_API_PROXY || 'http://127.0.0.1:8000',
          changeOrigin: true,
          secure: false,
        },
      },
    },
  }
})
