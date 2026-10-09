<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { categoryTree, createCategory, updateCategory, setCategoryStatus, deleteCategory } from '@/api/category'
import { languageOptions } from '@/api/language'
import { hasPerm } from '@/utils/perm'
import LangInput from '@/components/LangInput.vue'
import ImageUpload from '@/components/ImageUpload.vue'

const tree = ref([])
const languages = ref([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const [langs, t] = await Promise.all([languageOptions(), categoryTree()])
    languages.value = langs
    tree.value = t
  } finally {
    loading.value = false
  }
}

// 上级分类下拉：带“顶级分类”根节点
function simplify(nodes) {
  return nodes.map((n) => ({ id: n.id, name_text: n.name_text, children: simplify(n.children || []) }))
}
const parentOptions = computed(() => [{ id: 0, name_text: '顶级分类', children: simplify(tree.value) }])

const dialog = ref(false)
const isEdit = ref(false)
const formRef = ref()
const emptyForm = () => ({ id: 0, pid: 0, name: {}, image: '', sort: 0, status: 1 })
const form = reactive(emptyForm())

function openCreate(parent) {
  isEdit.value = false
  Object.assign(form, emptyForm(), { pid: parent ? parent.id : 0 })
  dialog.value = true
}
function openEdit(row) {
  isEdit.value = true
  Object.assign(form, emptyForm(), { id: row.id, pid: row.pid, name: { ...row.name }, image: row.image, sort: row.sort, status: row.status })
  dialog.value = true
}
async function submit() {
  const payload = { pid: form.pid, name: form.name, image: form.image, sort: form.sort, status: form.status }
  if (isEdit.value) await updateCategory(form.id, payload)
  else await createCategory(payload)
  ElMessage.success('保存成功')
  dialog.value = false
  load()
}
async function onStatus(row, val) {
  try {
    await setCategoryStatus(row.id, val)
  } finally {
    load()
  }
}
async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除分类「${row.name_text}」吗？`, '提示', { type: 'warning' })
  await deleteCategory(row.id)
  ElMessage.success('已删除')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <div class="toolbar">
        <el-button v-perm="'goods:category:add'" type="success" @click="openCreate(null)">新增顶级分类</el-button>
        <span style="margin-left: 12px; color: #909399">最多 3 级；排序数字越小越靠前；名称必须填写默认语言，其它语言缺失时回退显示默认语言。</span>
      </div>
      <el-table v-loading="loading" :data="tree" row-key="id" border default-expand-all :tree-props="{ children: 'children' }">
        <el-table-column prop="name_text" label="分类名称" min-width="220" />
        <el-table-column label="图片" width="90">
          <template #default="{ row }"><el-image v-if="row.image" :src="row.image" :preview-src-list="[row.image]" preview-teleported fit="cover" style="width: 40px; height: 40px" /></template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" :disabled="!hasPerm('goods:category:edit')" @change="(v) => onStatus(row, v)" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'goods:category:add'" link type="success" @click="openCreate(row)">新增下级</el-button>
            <el-button v-perm="'goods:category:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'goods:category:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑分类' : '新增分类'" width="580px" destroy-on-close>
      <el-form ref="formRef" :model="form" label-width="90px">
        <el-form-item label="上级分类">
          <el-tree-select v-model="form.pid" :data="parentOptions" node-key="id" check-strictly default-expand-all :props="{ label: 'name_text', children: 'children' }" style="width: 100%" />
        </el-form-item>
        <el-form-item label="分类名称" required><LangInput v-model="form.name" :languages="languages" :maxlength="100" ai field-key="category_name" /></el-form-item>
        <el-form-item label="分类图片"><ImageUpload v-model="form.image" scene="goods" :width="100" :height="100" tip="可选，建议正方形图片" /></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" :max="9999" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="禁用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>
