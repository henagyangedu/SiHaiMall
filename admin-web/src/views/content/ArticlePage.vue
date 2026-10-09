<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  listArticle, getArticle, createArticle, updateArticle, setArticleStatus, setArticleTop, deleteArticle,
  listArticleCategory, createArticleCategory, updateArticleCategory, deleteArticleCategory,
} from '@/api/content'
import { languageOptions } from '@/api/language'
import { hasPerm } from '@/utils/perm'
import LangInput from '@/components/LangInput.vue'
import LangRichText from '@/components/LangRichText.vue'
import ImageUpload from '@/components/ImageUpload.vue'

// 文章、公告、帮助中心三个菜单页共用。type：article | notice | help
const props = defineProps({
  type: { type: String, required: true },
  label: { type: String, required: true },
  // 公告没有分类；帮助中心必须选分类
  hasCategory: { type: Boolean, default: false },
  categoryRequired: { type: Boolean, default: false },
})
const perm = (act) => `content:${props.type}:${act}`

const query = reactive({ keyword: '', category_id: '', status: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)
const languages = ref([])
const categories = ref([])

async function load() {
  loading.value = true
  try {
    const d = await listArticle(props.type, query)
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
async function loadCategories() {
  if (props.hasCategory) categories.value = await listArticleCategory(props.type)
}

// ---- 新增 / 编辑
const dialog = ref(false)
const isEdit = ref(false)
const saving = ref(false)
const emptyForm = () => ({
  id: 0, category_id: null, title: {}, summary: {}, content: {}, seo_title: {}, seo_desc: {},
  cover: '', author: '', is_top: 0, sort: 0, status: 1, published_at: null,
})
const form = reactive(emptyForm())
const enabledCategories = computed(() => categories.value.filter((c) => c.status === 1 || c.id === form.category_id))

function openCreate() {
  isEdit.value = false
  Object.assign(form, emptyForm())
  dialog.value = true
}
async function openEdit(row) {
  const d = await getArticle(props.type, row.id)
  isEdit.value = true
  // 没填过的多语言字段后端返回空数组，统一成对象
  const obj = (v) => (v && !Array.isArray(v) ? { ...v } : {})
  Object.assign(form, emptyForm(), d, {
    category_id: d.category_id || null,
    title: obj(d.title), summary: obj(d.summary), content: obj(d.content), seo_title: obj(d.seo_title), seo_desc: obj(d.seo_desc),
  })
  dialog.value = true
}
async function submit() {
  if (props.categoryRequired && !form.category_id) return ElMessage.warning('请选择分类')
  saving.value = true
  try {
    const payload = { ...form, category_id: form.category_id || 0 }
    delete payload.id
    if (isEdit.value) await updateArticle(props.type, form.id, payload)
    else await createArticle(props.type, payload)
    ElMessage.success('保存成功')
    dialog.value = false
    load()
    loadCategories()
  } finally {
    saving.value = false
  }
}
async function onStatus(row, val) {
  try {
    await setArticleStatus(props.type, row.id, val)
  } finally {
    load()
  }
}
async function onTop(row) {
  await setArticleTop(props.type, row.id, row.is_top ? 0 : 1)
  load()
}
async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除「${row.title}」吗？`, '提示', { type: 'warning' })
  await deleteArticle(props.type, row.id)
  ElMessage.success('已删除')
  load()
  loadCategories()
}

// ---- 分类管理
const catDialog = ref(false)
const catForm = reactive({ id: 0, name: {}, sort: 0, status: 1 })
function editCategory(c) {
  Object.assign(catForm, c ? { id: c.id, name: { ...c.name }, sort: c.sort, status: c.status } : { id: 0, name: {}, sort: 0, status: 1 })
}
async function saveCategory() {
  const payload = { name: catForm.name, sort: catForm.sort, status: catForm.status }
  if (catForm.id) await updateArticleCategory(props.type, catForm.id, payload)
  else await createArticleCategory(props.type, payload)
  ElMessage.success('保存成功')
  editCategory(null)
  loadCategories()
}
async function removeCategory(c) {
  await ElMessageBox.confirm(`确定删除分类「${c.name_text}」吗？`, '提示', { type: 'warning' })
  await deleteArticleCategory(props.type, c.id)
  ElMessage.success('已删除')
  loadCategories()
}

onMounted(async () => {
  load()
  loadCategories()
  try { languages.value = await languageOptions() } catch (e) { /* 无权限时页签为空 */ }
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="标题" clearable style="width: 200px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item v-if="hasCategory">
          <el-select v-model="query.category_id" placeholder="全部分类" clearable style="width: 150px" @change="search">
            <el-option v-for="c in categories" :key="c.id" :label="c.name_text" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="全部状态" clearable style="width: 120px" @change="search">
            <el-option label="已发布" :value="1" /><el-option label="草稿" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="search">查询</el-button>
          <el-button v-perm="perm('add')" type="success" @click="openCreate">新增{{ label }}</el-button>
          <el-button v-if="hasCategory" @click="catDialog = true; editCategory(null)">分类管理</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column v-if="type === 'article'" label="封面" width="84">
          <template #default="{ row }"><el-image v-if="row.cover" :src="row.cover" fit="cover" style="width: 56px; height: 40px" /></template>
        </el-table-column>
        <el-table-column label="标题" min-width="260">
          <template #default="{ row }">
            <el-tag v-if="row.is_top" type="danger" size="small" style="margin-right: 6px">置顶</el-tag>{{ row.title }}
            <div class="sub">已填语言：{{ row.langs.join('、') }}</div>
          </template>
        </el-table-column>
        <el-table-column v-if="hasCategory" prop="category_name" label="分类" width="130" />
        <el-table-column prop="views" label="浏览" width="80" />
        <el-table-column prop="sort" label="排序" width="70" />
        <el-table-column label="发布" width="130">
          <template #default="{ row }">
            <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" :disabled="!hasPerm(perm('edit'))" @change="(v) => onStatus(row, v)" />
            <div v-if="row.scheduled" class="sub" style="color: #e6a23c">定时，未到时间</div>
          </template>
        </el-table-column>
        <el-table-column prop="published_at" label="发布时间" min-width="160" />
        <el-table-column label="操作" width="190" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="perm('edit')" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="perm('edit')" link @click="onTop(row)">{{ row.is_top ? '取消置顶' : '置顶' }}</el-button>
            <el-button v-perm="perm('del')" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="dialog" :title="(isEdit ? '编辑' : '新增') + label" width="1000px" top="4vh" destroy-on-close>
      <el-form :model="form" label-width="90px">
        <el-form-item label="标题" required><LangInput v-model="form.title" :languages="languages" :maxlength="255" ai field-key="title" /></el-form-item>
        <el-form-item v-if="hasCategory" label="分类" :required="categoryRequired">
          <el-select v-model="form.category_id" clearable :placeholder="categoryRequired ? '请选择分类' : '可不选'" style="width: 260px">
            <el-option v-for="c in enabledCategories" :key="c.id" :label="c.name_text" :value="c.id" />
          </el-select>
          <span v-if="!categories.length" class="hint">还没有分类，请先在列表页点“分类管理”添加</span>
        </el-form-item>
        <template v-if="type === 'article'">
          <el-form-item label="封面"><ImageUpload v-model="form.cover" scene="other" :width="160" :height="100" tip="可选，建议 16:10" /></el-form-item>
          <el-form-item label="作者"><el-input v-model="form.author" maxlength="60" style="width: 260px" /></el-form-item>
        </template>
        <el-form-item v-if="type !== 'help'" label="摘要"><LangInput v-model="form.summary" :languages="languages" type="textarea" :maxlength="500" ai field-key="summary" /></el-form-item>
        <el-form-item label="正文" required><LangRichText v-model="form.content" :languages="languages" :height="340" ai field-key="content" /></el-form-item>
        <el-form-item label="发布">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="发布" inactive-text="草稿" />
          <el-date-picker v-model="form.published_at" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" placeholder="发布时间，留空为立即" style="width: 220px; margin-left: 16px" />
          <span class="hint">填将来的时间可以定时发布，到时间前台才显示</span>
        </el-form-item>
        <el-form-item label="置顶与排序">
          <el-switch v-model="form.is_top" :active-value="1" :inactive-value="0" active-text="置顶" />
          <el-input-number v-model="form.sort" :min="0" :max="9999" style="margin-left: 16px" /><span class="hint">越小越靠前</span>
        </el-form-item>
        <el-collapse v-if="type === 'article'" style="margin-left: 90px">
          <el-collapse-item title="SEO 设置（可选，不填时用标题和摘要）">
            <el-form-item label="SEO 标题" label-width="90px"><LangInput v-model="form.seo_title" :languages="languages" :maxlength="255" ai field-key="seo_title" /></el-form-item>
            <el-form-item label="SEO 描述" label-width="90px"><LangInput v-model="form.seo_desc" :languages="languages" type="textarea" :maxlength="500" ai field-key="seo_desc" /></el-form-item>
          </el-collapse-item>
        </el-collapse>
      </el-form>
      <template #footer>
        <span class="hint" style="float: left; margin-left: 0">默认语言的标题和正文必填；其他语言不填时，前台显示默认语言的内容</span>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">保存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="catDialog" :title="label + '分类'" width="720px" destroy-on-close>
      <el-table :data="categories" border size="small" max-height="300">
        <el-table-column prop="name_text" label="分类名称" min-width="160" />
        <el-table-column prop="article_count" label="内容数" width="80" />
        <el-table-column prop="sort" label="排序" width="70" />
        <el-table-column label="状态" width="80"><template #default="{ row }"><el-tag :type="row.status ? 'success' : 'info'" size="small">{{ row.status ? '启用' : '停用' }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <el-button v-perm="perm('edit')" link type="primary" @click="editCategory(row)">编辑</el-button>
            <el-button v-perm="perm('del')" link type="danger" @click="removeCategory(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-divider content-position="left">{{ catForm.id ? '编辑分类' : '新增分类' }}</el-divider>
      <el-form label-width="80px">
        <el-form-item label="名称" required><LangInput v-model="catForm.name" :languages="languages" :maxlength="100" ai field-key="category_name" /></el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="catForm.sort" :min="0" :max="9999" />
          <el-switch v-model="catForm.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" style="margin-left: 16px" />
        </el-form-item>
        <el-form-item>
          <el-button v-perm="perm(catForm.id ? 'edit' : 'add')" type="primary" @click="saveCategory">{{ catForm.id ? '保存修改' : '添加分类' }}</el-button>
          <el-button v-if="catForm.id" @click="editCategory(null)">取消编辑</el-button>
        </el-form-item>
      </el-form>
    </el-dialog>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; }
.hint { margin-left: 12px; color: #909399; font-size: 13px; }
</style>
