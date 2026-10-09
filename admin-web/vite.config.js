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
      // 开发环境把 /admin-api 和上传的图片 /uploads 转发给 Laravel，浏览器不会遇到跨域，也不用直接访问后端域名
      proxy: Object.fromEntries(['/admin-api', '/uploads'].map((p) => [p, {
        target: env.VITE_API_PROXY || 'http://127.0.0.1:8000',
        changeOrigin: true,
        secure: false,
      }])),
    },
  }
})
