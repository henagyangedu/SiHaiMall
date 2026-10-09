<script setup>
import { ref, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { User, Lock } from '@element-plus/icons-vue'
import { useUserStore } from '@/stores/user'

const route = useRoute()
const router = useRouter()
const store = useUserStore()

const formRef = ref()
const loading = ref(false)
const form = reactive({ username: '', password: '' })
const rules = {
  username: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
}

async function submit() {
  await formRef.value.validate()
  loading.value = true
  try {
    await store.login({ ...form })
    router.replace(typeof route.query.redirect === 'string' ? route.query.redirect : '/')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login">
    <el-card class="box" shadow="always">
      <h2>商城管理后台</h2>
      <el-form ref="formRef" :model="form" :rules="rules" size="large" @keyup.enter="submit">
        <el-form-item prop="username">
          <el-input v-model="form.username" placeholder="账号" :prefix-icon="User" autofocus />
        </el-form-item>
        <el-form-item prop="password">
          <el-input v-model="form.password" type="password" show-password placeholder="密码" :prefix-icon="Lock" />
        </el-form-item>
        <el-button type="primary" :loading="loading" style="width: 100%" @click="submit">登 录</el-button>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.login { height: 100%; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #001529, #1f4e79); }
.box { width: 380px; }
h2 { text-align: center; margin: 0 0 24px; }
</style>
