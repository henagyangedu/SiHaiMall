<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useUserStore } from '@/stores/user'
import { listAdmin, createAdmin, updateAdmin, deleteAdmin, resetAdmin } from '@/api/admin'
import { roleOptions } from '@/api/role'

const store = useUserStore()

const query = reactive({ keyword: '', status: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)
const roles = ref([])

async function load() {
  loading.value = true
  try {
    const d = await listAdmin(query)
    list.value = d.list
    total.value = d.total
  } finally {
    loading.value = false
  }
}
function search() {
  query.page = 1
  load()
}

// 角色下拉：超级管理员才能选“超级管理员”
const roleChoices = computed(() => (store.isSuper ? [{ id: 0, name: '超级管理员' }, ...roles.value] : roles.value))

// ---- 新增 / 编辑 ----
const dialog = ref(false)
const isEdit = ref(false)
const formRef = ref()
const emptyForm = () => ({ id: 0, username: '', password: '', nickname: '', phone: '', email: '', role_id: undefined, status: 1 })
const form = reactive(emptyForm())
const rules = computed(() => ({
  username: isEdit.value ? [] : [{ required: true, message: '请输入账号', trigger: 'blur' }, { min: 3, max: 50, message: '3-50 个字符，仅字母数字下划线短横线', trigger: 'blur' }],
  password: isEdit.value ? [] : [{ required: true, message: '请输入密码', trigger: 'blur' }, { min: 8, message: '至少 8 位，且包含字母和数字', trigger: 'blur' }],
  role_id: [{ required: true, message: '请选择角色', trigger: 'change' }],
}))

function openCreate() {
  isEdit.value = false
  Object.assign(form, emptyForm())
  dialog.value = true
}
function openEdit(row) {
  isEdit.value = true
  Object.assign(form, emptyForm(), {
    id: row.id, username: row.username, nickname: row.nickname, phone: row.phone,
    email: row.email, role_id: row.role_id, status: row.status,
  })
  dialog.value = true
}
async function submit() {
  await formRef.value.validate()
  if (isEdit.value) {
    const payload = { nickname: form.nickname, phone: form.phone, email: form.email, role_id: form.role_id, status: form.status }
    if (form.password) payload.password = form.password
    await updateAdmin(form.id, payload)
  } else {
    await createAdmin({ username: form.username, password: form.password, nickname: form.nickname, phone: form.phone, email: form.email, role_id: form.role_id, status: form.status })
  }
  ElMessage.success('保存成功')
  dialog.value = false
  load()
}

// ---- 删除 / 重置密码 ----
async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除管理员「${row.username}」吗？`, '提示', { type: 'warning' })
  await deleteAdmin(row.id)
  ElMessage.success('已删除')
  load()
}
async function onReset(row) {
  const { value } = await ElMessageBox.prompt(`为「${row.username}」设置新密码（至少 8 位，含字母和数字）`, '重置密码', {
    inputType: 'password',
    inputPattern: /^(?=.*[A-Za-z])(?=.*\d).{8,}$/,
    inputErrorMessage: '至少 8 位，且包含字母和数字',
  })
  await resetAdmin(row.id, { password: value })
  ElMessage.success('密码已重置，该账号已强制下线')
}

onMounted(async () => {
  load()
  try { roles.value = await roleOptions() } catch (e) { /* 无角色查看权限时下拉为空 */ }
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item>
          <el-input v-model="query.keyword" placeholder="账号 / 昵称 / 手机号" clearable style="width: 220px" @clear="search" @keyup.enter="search" />
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 120px" @change="search">
            <el-option label="启用" :value="1" />
            <el-option label="禁用" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="search">查询</el-button>
          <el-button v-perm="'setting:admin:add'" type="success" @click="openCreate">新增管理员</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="username" label="账号" min-width="120" />
        <el-table-column prop="nickname" label="昵称" min-width="110" />
        <el-table-column prop="role_name" label="角色" min-width="110" />
        <el-table-column prop="phone" label="手机号" min-width="120" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'">{{ row.status === 1 ? '启用' : '禁用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="last_login_at" label="最后登录" min-width="160" />
        <el-table-column prop="last_login_ip" label="登录IP" min-width="130" />
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'setting:admin:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'setting:admin:reset'" link type="warning" @click="onReset(row)">重置密码</el-button>
            <el-button v-perm="'setting:admin:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.size"
          :total="total"
          :page-sizes="[15, 30, 50]"
          layout="total, sizes, prev, pager, next"
          @current-change="load"
          @size-change="search"
        />
      </div>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑管理员' : '新增管理员'" width="520px" destroy-on-close>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="80px">
        <el-form-item label="账号" prop="username"><el-input v-model="form.username" :disabled="isEdit" /></el-form-item>
        <el-form-item :label="isEdit ? '新密码' : '密码'" prop="password">
          <el-input v-model="form.password" type="password" show-password :placeholder="isEdit ? '不修改请留空' : ''" />
        </el-form-item>
        <el-form-item label="昵称"><el-input v-model="form.nickname" /></el-form-item>
        <el-form-item label="手机号"><el-input v-model="form.phone" /></el-form-item>
        <el-form-item label="邮箱"><el-input v-model="form.email" /></el-form-item>
        <el-form-item label="角色" prop="role_id">
          <el-select v-model="form.role_id" placeholder="请选择角色" style="width: 100%">
            <el-option v-for="r in roleChoices" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="禁用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
