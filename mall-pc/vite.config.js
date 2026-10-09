import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    plugins: [vue()],
    // 打包后用相对路径引用资源，放在站点的子目录下也能用
    base: './',
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
        // 界面文案、国家列表与手机端（mall-uni）共用同一份，不另抄一套
        '@shared': fileURLToPath(new URL('../mall-uni/src', import.meta.url)),
      },
    },
    server: {
      host: '127.0.0.1',
      port: 5190,
      fs: { allow: ['..'] },
      // 开发时把 /api 和上传的图片 /uploads 转发给后端，浏览器不会遇到跨域，也不用直接访问后端域名
      proxy: Object.fromEntries(['/api', '/uploads'].map((p) => [p, {
        target: env.VITE_API_PROXY || 'http://127.0.0.1:8000', changeOrigin: true, secure: false,
      }])),
    },
  }
})
