# 管理后台（Vue 3 + Element Plus + Vite）

四海商城 SihaiMall 的管理后台。

```bash
npm install
npm run dev      # http://127.0.0.1:5173
npm run build    # 产物在 dist/
```

开发时接口请求由开发服务器转发给后端，后端地址在 `.env.development` 的 `VITE_API_PROXY`；本机的后端地址不一样时，在同目录建一个 `.env.development.local` 覆盖它。

菜单是后端下发的，页面按菜单的路径加载 `src/views/<路径>/index.vue`。

整体的安装和部署见仓库根目录的 [README](../README.md) 和 [部署说明](../docs/部署说明.md)。
