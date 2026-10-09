# gm_admin_v1：后台登录 / 角色菜单权限 / 操作日志

## 安装步骤
1. 先备份项目，再把本包的 app/、config/、database/、lang/、routes/ 合并复制到项目根目录。
   （config/cors.php 如果项目里已有同名文件会被覆盖；它只是把 CORS 路径加上 admin-api/*）
2. bootstrap/app.php：本包里是 bootstrap/app.php.new，
   - 如果你的 bootstrap/app.php 还是框架默认内容，直接用 .new 的内容替换即可；
   - 如果你已经改过，请逐段对照合并（路由 then 里的 admin-api / notify、alias、SetLocale、异常渲染）。
3. 执行：
   php artisan config:clear
   php artisan migrate                              # 新增 gm_admin_token 表
   php artisan db:seed --class=AdminInitSeeder      # 同步菜单 + 创建 admin，终端会显示一次初始密码
4. 检查路由：php artisan route:list --path=admin-api

## 验证（任选工具：Apifox / Postman / curl）
- GET  http://127.0.0.1:8000/api/ping                     → code 0
- POST http://127.0.0.1:8000/admin-api/auth/login         body(JSON): {"username":"admin","password":"初始密码"}
  返回 data.token，之后所有请求带头：Authorization: Bearer <token>
- GET  /admin-api/auth/info    → 管理员信息 + menus 菜单树 + perms（超管为 ["*"]）
- GET  /admin-api/menu/tree    → 完整菜单树
- POST /admin-api/role         body: {"name":"客服","menu_ids":[...]}
- POST /admin-api/admin        body: {"username":"kefu01","password":"Abcd1234","role_id":1}
- GET  /admin-api/log/list     → 看到刚才的写操作记录（password 已脱敏为 ***）

## 说明
- 本包用自建令牌（gm_admin_token，只存 sha256，不存明文），不依赖 Sanctum。
- 权限校验：路由上 ->middleware('perm:模块:资源:动作')，多个权限逗号分隔为"任一即可"，perm:super 仅超管。
- 防提权：非超管不能授予自己没有的菜单，不能改自己所属角色/状态，不能操作超级管理员账号。
- 菜单规则的增删改仅超管；内置菜单由 MenuSeeder 维护，新增模块时在其中追加后重新执行 db:seed --class=MenuSeeder。
- 所有代码未在真实环境运行过，遇到报错把完整信息发我。
