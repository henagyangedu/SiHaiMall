# 商城管理后台（Vue3 + Element Plus + Vite）

## 运行
1. 安装 Node.js 20 LTS 或更高版本（node -v 检查）。
2. 在本目录执行：
   npm install
   npm run dev
3. 浏览器打开 http://localhost:5173 ，用 admin 和初始密码登录。

## 连接后端
- 开发环境通过 Vite 代理把 /admin-api 转发给 Laravel，不会有跨域问题。
- 代理地址在 .env.development 的 VITE_API_PROXY：
  - 用 php artisan serve：保持 http://127.0.0.1:8000
  - 用 ServBay 域名：改成你的站点地址（如 http://你的域名），改完重启 npm run dev
- 生产环境：同域部署保持 VITE_API_BASE=/admin-api；前后端分域则改成完整地址，并在后端 .env 设置 CORS_ALLOWED_ORIGINS。
- 构建：npm run build，产物在 dist/，用 Nginx 托管并配置 history 模式回退到 index.html。

## 结构说明
- src/api：接口封装；request.js 统一处理 code（0 成功 / 401 跳登录 / 其它弹错）。
- src/router：静态路由只有登录和首页；其余页面由 /auth/info 返回的菜单动态注册。
- 页面对应关系：菜单的 component 字段 = src/views/<component>/index.vue，没有对应文件的菜单显示“开发中”。
  新增页面时：1) 在 MenuSeeder 或“菜单规则”里加菜单；2) 在 src/views 下建同名目录的 index.vue。
- 按钮权限：v-perm="'setting:admin:add'"（多个用逗号，任一即可；'super' 仅超管）。前端隐藏只是体验优化，真正校验在后端。
- 已完成页面：登录、首页、管理员、角色（含菜单授权）、菜单规则、操作日志、修改密码。

## 注意
- 本项目代码未在真实环境运行过，npm install 的依赖版本由 npm 解析；若报错请把完整信息发我。
