<script setup>
import { ref, reactive, nextTick, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listRole, getRole, createRole, updateRole, deleteRole } from '@/api/role'
import { menuTree } from '@/api/menu'

const list = ref([])
const total = ref(0)
const loading = ref(false)
const query = reactive({ page: 1, size: 15 })

async function load() {
  loading.value = true
  try {
    const d = await listRole(query)
    list.value = d.list
    total.value = d.total
  } finally {
    loading.value = false
  }
}

// ---- 菜单树（授权用） ----
const tree = ref([])
const parentMap = {}
function buildMap(nodes, pid = 0) {
  nodes.forEach((n) => {
    parentMap[n.id] = pid
    if (n.children) buildMap(n.children, n.id)
  })
}
function collect(node, out = []) {
  out.push(node.id)
  ;(node.children || []).forEach((c) => collect(c, out))
  return out
}
function ancestorsOf(id) {
  const r = []
  let p = parentMap[id]
  while (p) {
    r.push(p)
    p = parentMap[p]
  }
  return r
}

const treeRef = ref()
let pendingKeys = []

// 严格模式（父子不联动）+ 手动联动：勾选 => 子级全选 + 祖先补选；取消 => 子级全取消
function onCheck(data, state) {
  const set = new Set(state.checkedKeys)
  const checked = set.has(data.id)
  collect(data).forEach((id) => (checked ? set.add(id) : set.delete(id)))
  if (checked) ancestorsOf(data.id).forEach((id) => set.add(id))
  treeRef.value.setCheckedKeys([...set])
}
function onOpened() {
  treeRef.value && treeRef.value.setCheckedKeys(pendingKeys)
}

// ---- 新增 / 编辑 ----
const dialog = ref(false)
const isEdit = ref(false)
const formRef = ref()
const form = reactive({ id: 0, name: '', remark: '', status: 1 })
const rules = { name: [{ required: true, message: '请输入角色名称', trigger: 'blur' }] }

async function ensureTree() {
  if (!tree.value.length) {
    tree.value = await menuTree()
    buildMap(tree.value)
  }
}
async function openCreate() {
  await ensureTree()
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', remark: '', status: 1 })
  pendingKeys = []
  dialog.value = true
}
async function openEdit(row) {
  await ensureTree()
  const d = await getRole(row.id)
  isEdit.value = true
  Object.assign(form, { id: d.id, name: d.name, remark: d.remark, status: d.status })
  pendingKeys = d.menu_ids
  dialog.value = true
}
async function submit() {
  await formRef.value.validate()
  const payload = {
    name: form.name,
    remark: form.remark,
    status: form.status,
    menu_ids: treeRef.value.getCheckedKeys(false),
  }
  if (isEdit.value) await updateRole(form.id, payload)
  else await createRole(payload)
  ElMessage.success('保存成功')
  dialog.value = false
  load()
}
async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除角色「${row.name}」吗？`, '提示', { type: 'warning' })
  await deleteRole(row.id)
  ElMessage.success('已删除')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <div class="toolbar">
        <el-button v-perm="'setting:role:add'" type="success" @click="openCreate">新增角色</el-button>
      </div>
      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="角色名称" min-width="140" />
        <el-table-column prop="remark" label="备注" min-width="200" />
        <el-table-column prop="admins_count" label="管理员数" width="100" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'">{{ row.status === 1 ? '启用' : '禁用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" min-width="160" />
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'setting:role:edit'" link type="primary" @click="openEdit(row)">编辑 / 授权</el-button>
            <el-button v-perm="'setting:role:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="load" />
      </div>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑角色' : '新增角色'" width="560px" destroy-on-close @opened="onOpened">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="80px">
        <el-form-item label="角色名称" prop="name"><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="禁用" /></el-form-item>
        <el-form-item label="菜单权限">
          <div class="tree-box">
            <el-tree
              ref="treeRef"
              :data="tree"
              node-key="id"
              show-checkbox
              check-strictly
              default-expand-all
              :props="{ label: 'title_key', children: 'children' }"
              @check="onCheck"
            />
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.tree-box { width: 100%; max-height: 360px; overflow: auto; border: 1px solid #dcdfe6; border-radius: 4px; padding: 8px; }
</style>
