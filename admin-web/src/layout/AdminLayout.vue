<script setup>
import { ref, reactive, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@/stores/user'
import { resetRoutes } from '@/router'
import { changePassword } from '@/api/auth'
import SidebarItem from './SidebarItem.vue'

const route = useRoute()
const router = useRouter()
const store = useUserStore()

const menus = computed(() => store.menus.filter((m) => m.visible !== 0))
const activePath = computed(() => route.path)

async function onLogout() {
  await store.logout()
  resetRoutes()
  router.replace('/login')
}

// ---- 修改密码 ----
const pwdVisible = ref(false)
const pwdFormRef = ref()
const pwdForm = reactive({ old_password: '', new_password: '', new_password_confirmation: '' })
const pwdRules = {
  old_password: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '至少 8 位，且包含字母和数字', trigger: 'blur' },
  ],
  new_password_confirmation: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_r, v, cb) => (v === pwdForm.new_password ? cb() : cb(new Error('两次输入不一致'))),
      trigger: 'blur',
    },
  ],
}
function openPwd() {
  Object.assign(pwdForm, { old_password: '', new_password: '', new_password_confirmation: '' })
  pwdVisible.value = true
}
async function submitPwd() {
  await pwdFormRef.value.validate()
  await changePassword({ ...pwdForm })
  ElMessage.success('密码已修改，其它设备已下线')
  pwdVisible.value = false
}
</script>

<template>
  <el-container class="layout">
    <el-aside width="220px" class="aside">
      <div class="logo">商城管理后台</div>
      <el-scrollbar>
        <el-menu :default-active="activePath" router unique-opened background-color="#001529" text-color="#bfcbd9" active-text-color="#409eff">
          <el-menu-item index="/dashboard">
            <el-icon><HomeFilled /></el-icon>
            <template #title>首页</template>
          </el-menu-item>
          <SidebarItem v-for="m in menus" :key="m.id" :item="m" />
        </el-menu>
      </el-scrollbar>
    </el-aside>

    <el-container>
      <el-header class="header">
        <span class="crumb">{{ route.meta.title || '' }}</span>
        <el-dropdown trigger="click">
          <span class="user">
            <el-avatar :size="28" :src="store.info?.avatar || undefined">{{ (store.info?.nickname || store.info?.username || '?').slice(0, 1) }}</el-avatar>
            <span class="name">{{ store.info?.nickname || store.info?.username }}</span>
            <span class="role">{{ store.info?.role_name }}</span>
            <el-icon><ArrowDown /></el-icon>
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item @click="openPwd">修改密码</el-dropdown-item>
              <el-dropdown-item divided @click="onLogout">退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </el-header>
      <el-main class="main">
        <router-view />
      </el-main>
    </el-container>
  </el-container>

  <el-dialog v-model="pwdVisible" title="修改密码" width="420px" destroy-on-close>
    <el-form ref="pwdFormRef" :model="pwdForm" :rules="pwdRules" label-width="90px">
      <el-form-item label="原密码" prop="old_password"><el-input v-model="pwdForm.old_password" type="password" show-password /></el-form-item>
      <el-form-item label="新密码" prop="new_password"><el-input v-model="pwdForm.new_password" type="password" show-password /></el-form-item>
      <el-form-item label="确认新密码" prop="new_password_confirmation"><el-input v-model="pwdForm.new_password_confirmation" type="password" show-password /></el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="pwdVisible = false">取消</el-button>
      <el-button type="primary" @click="submitPwd">确定</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.layout { height: 100%; }
.aside { background: #001529; display: flex; flex-direction: column; }
.logo { height: 56px; line-height: 56px; text-align: center; color: #fff; font-size: 16px; font-weight: 600; }
.aside :deep(.el-menu) { border-right: none; }
.header { display: flex; align-items: center; justify-content: space-between; background: #fff; border-bottom: 1px solid #ebeef5; }
.crumb { font-size: 15px; font-weight: 500; }
.user { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.role { color: #909399; font-size: 12px; }
.main { padding: 0; overflow: auto; }
</style>
