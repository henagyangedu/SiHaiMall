import { hasPerm } from '@/utils/perm'

// 用法：<el-button v-perm="'setting:admin:add'">新增</el-button>
// 注意：前端隐藏只是体验优化，真正的权限校验在后端接口。
export function setupPermDirective(app) {
  app.directive('perm', {
    mounted(el, binding) {
      if (!hasPerm(binding.value)) el.parentNode && el.parentNode.removeChild(el)
    },
  })
}
