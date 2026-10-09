<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { menuTree, createMenu, updateMenu, deleteMenu } from '@/api/menu'

const tree = ref([])
const loading = ref(false)
const typeText = { 1: '目录', 2: '菜单', 3: '权限点' }
const typeTag = { 1: 'warning', 2: 'success', 3: 'info' }

async function load() {
  loading.value = true
  try {
    tree.value = await menuTree()
  } finally {
    loading.value = false
  }
}

// 上级菜单下拉：只含目录和菜单，并带一个“顶级”根节点
function onlyDirsAndMenus(nodes) {
  return nodes
    .filter((n) => n.type !== 3)
    .map((n) => ({ id: n.id, title_key: n.title_key, children: onlyDirsAndMenus(n.children || []) }))
}
const parentOptions = computed(() => [{ id: 0, title_key: '顶级', children: onlyDirsAndMenus(tree.value) }])

// ---- 新增 / 编辑 ----
const dialog = ref(false)
const isEdit = ref(false)
const formRef = ref()
const emptyForm = () => ({ id: 0, pid: 0, type: 2, title_key: '', icon: '', path: '', component: '', perm: '', sort: 0, visible: 1, status: 1 })
const form = reactive(emptyForm())
const rules = computed(() => ({
  title_key: [{ required: true, message: '请输入菜单名称', trigger: 'blur' }],
  path: form.type === 2 ? [{ required: true, message: '菜单需要填写路由', trigger: 'blur' }] : [],
  perm: form.type !== 1 ? [{ required: true, message: '请填写权限标识，如 goods:list:view', trigger: 'blur' }] : [],
}))

function openCreate(parent) {
  isEdit.value = false
  Object.assign(form, emptyForm(), { pid: parent ? parent.id : 0, type: parent ? (parent.type === 1 ? 2 : 3) : 1 })
  dialog.value = true
}
function openEdit(row) {
  isEdit.value = true
  Object.assign(form, emptyForm(), {
    id: row.id, pid: row.pid, type: row.type, title_key: row.title_key, icon: row.icon, path: row.path,
    component: row.component, perm: row.perm, sort: row.sort, visible: row.visible, status: row.status,
  })
  dialog.value = true
}
async function submit() {
  await formRef.value.validate()
  const { id, ...payload } = form
  if (isEdit.value) await updateMenu(id, payload)
  else await createMenu(payload)
  ElMessage.success('保存成功')
  dialog.value = false
  load()
}
async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除「${row.title_key}」吗？（有下级时需先删除下级）`, '提示', { type: 'warning' })
  await deleteMenu(row.id)
  ElMessage.success('已删除')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <div class="toolbar">
        <el-button v-perm="'super'" type="success" @click="openCreate(null)">新增顶级目录</el-button>
        <span style="margin-left: 12px; color: #909399">菜单规则的增删改仅超级管理员可操作；内置菜单由系统初始化维护。</span>
      </div>
      <el-table v-loading="loading" :data="tree" row-key="id" border default-expand-all :tree-props="{ children: 'children' }">
        <el-table-column prop="title_key" label="名称" min-width="200" />
        <el-table-column label="类型" width="90">
          <template #default="{ row }"><el-tag :type="typeTag[row.type]">{{ typeText[row.type] }}</el-tag></template>
        </el-table-column>
        <el-table-column prop="perm" label="权限标识" min-width="200" />
        <el-table-column prop="path" label="路由" min-width="170" />
        <el-table-column prop="icon" label="图标" width="100" />
        <el-table-column prop="sort" label="排序" width="70" />
        <el-table-column label="显示" width="70">
          <template #default="{ row }">{{ row.visible === 1 ? '是' : '否' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }"><el-tag :type="row.status === 1 ? 'success' : 'danger'">{{ row.status === 1 ? '启用' : '禁用' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.type !== 3" v-perm="'super'" link type="success" @click="openCreate(row)">新增下级</el-button>
            <el-button v-perm="'super'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'super'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑菜单' : '新增菜单'" width="560px" destroy-on-close>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="上级">
          <el-tree-select v-model="form.pid" :data="parentOptions" node-key="id" check-strictly default-expand-all :props="{ label: 'title_key', children: 'children' }" style="width: 100%" />
        </el-form-item>
        <el-form-item label="类型">
          <el-radio-group v-model="form.type">
            <el-radio :value="1">目录</el-radio>
            <el-radio :value="2">菜单</el-radio>
            <el-radio :value="3">权限点（按钮）</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="名称" prop="title_key"><el-input v-model="form.title_key" /></el-form-item>
        <el-form-item v-if="form.type !== 3" label="图标"><el-input v-model="form.icon" placeholder="Element Plus 图标名，如 Goods、Setting" /></el-form-item>
        <el-form-item v-if="form.type !== 3" label="路由" prop="path"><el-input v-model="form.path" placeholder="如 /goods/list" /></el-form-item>
        <el-form-item v-if="form.type === 2" label="页面组件"><el-input v-model="form.component" placeholder="views 下的目录，如 goods/list；留空则显示“开发中”" /></el-form-item>
        <el-form-item v-if="form.type !== 1" label="权限标识" prop="perm"><el-input v-model="form.perm" placeholder="模块:资源:动作，如 goods:list:view" /></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" :max="9999" /></el-form-item>
        <el-form-item v-if="form.type !== 3" label="侧边栏显示"><el-switch v-model="form.visible" :active-value="1" :inactive-value="0" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="禁用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
