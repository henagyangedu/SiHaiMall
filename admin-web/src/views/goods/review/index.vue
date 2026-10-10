<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listReview, setReviewStatus, replyReview } from '@/api/review'

const query = reactive({ keyword: '', rating: '', status: '', replied: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listReview(query)
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

// 隐藏 / 恢复显示
async function toggle(row) {
  const hide = row.status === 1
  if (hide) await ElMessageBox.confirm('隐藏后买家在商品页看不到这条评价，评价数和好评率也不再计入它。之后可以恢复显示。', '隐藏评价', { type: 'warning' })
  await setReviewStatus(row.id, hide ? 0 : 1)
  ElMessage.success(hide ? '已隐藏' : '已恢复显示')
  load()
}

// 商家回复：原评价和追评各回复一次，append 表示这次回复的是追评
const dialog = reactive({ show: false, row: null, text: '', saving: false, append: false })
function openReply(row, append = false) {
  Object.assign(dialog, { show: true, row, text: append ? row.append.reply : row.reply, saving: false, append })
}
async function submitReply() {
  dialog.saving = true
  try {
    await replyReview(dialog.row.id, dialog.text.trim(), dialog.append)
    ElMessage.success(dialog.text.trim() ? '已回复' : '已删除回复')
    dialog.show = false
    load()
  } finally {
    dialog.saving = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="评价或追评内容 / 商品名称 / 订单号" clearable style="width: 260px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.rating" placeholder="星级" clearable style="width: 110px" @change="search">
            <el-option v-for="n in [5, 4, 3, 2, 1]" :key="n" :label="`${n} 星`" :value="n" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="显示状态" clearable style="width: 120px" @change="search">
            <el-option label="显示中" :value="1" /><el-option label="已隐藏" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.replied" placeholder="回复" clearable style="width: 110px" @change="search">
            <el-option label="已回复" :value="1" /><el-option label="未回复" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="商品" min-width="220">
          <template #default="{ row }">
            <div>{{ row.goods_title || `商品 #${row.goods_id}` }}</div>
            <div class="sub">{{ row.spec_text }}</div>
            <div class="sub">订单 {{ row.order_no }}</div>
          </template>
        </el-table-column>
        <el-table-column label="评价" min-width="360">
          <template #default="{ row }">
            <el-rate :model-value="row.rating" disabled />
            <div class="content">{{ row.content || '（没有填写文字）' }}</div>
            <div v-if="row.images.length" class="pics">
              <el-image v-for="(u, i) in row.images" :key="i" :src="u" :preview-src-list="row.images" :initial-index="i" preview-teleported fit="cover" class="pic" />
            </div>
            <div v-if="row.reply" class="reply"><b>商家回复：</b>{{ row.reply }}</div>
            <div v-if="row.append" class="append">
              <div class="sub">追评　{{ row.append.created_at }}（{{ row.append.days > 0 ? `${row.append.days} 天后` : '当天' }}）</div>
              <div class="content">{{ row.append.content }}</div>
              <div v-if="row.append.images.length" class="pics">
                <el-image v-for="(u, i) in row.append.images" :key="i" :src="u" :preview-src-list="row.append.images" :initial-index="i" preview-teleported fit="cover" class="pic" />
              </div>
              <div v-if="row.append.reply" class="reply"><b>商家回复：</b>{{ row.append.reply }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="会员" min-width="130">
          <template #default="{ row }">{{ row.member }}<el-tag v-if="row.is_anonymous" size="small" type="info" style="margin-left: 6px">匿名</el-tag></template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }"><el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '显示中' : '已隐藏' }}</el-tag></template>
        </el-table-column>
        <el-table-column prop="created_at" label="评价时间" min-width="160" />
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'goods:review:reply'" link type="primary" @click="openReply(row)">{{ row.reply ? '改回复' : '回复' }}</el-button>
            <el-button v-if="row.append" v-perm="'goods:review:reply'" link type="primary" @click="openReply(row, true)">{{ row.append.reply ? '改追评回复' : '回复追评' }}</el-button>
            <el-button v-perm="'goods:review:edit'" link :type="row.status === 1 ? 'danger' : 'success'" @click="toggle(row)">{{ row.status === 1 ? '隐藏' : '恢复' }}</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="dialog.show" :title="dialog.append ? '回复追评' : '回复评价'" width="520px" destroy-on-close>
      <div v-if="dialog.row" class="quote">
        <template v-if="dialog.append"><div class="sub">追评</div><div>{{ dialog.row.append.content }}</div></template>
        <template v-else>
          <el-rate :model-value="dialog.row.rating" disabled />
          <div>{{ dialog.row.content || '（没有填写文字）' }}</div>
        </template>
      </div>
      <el-input v-model="dialog.text" type="textarea" :rows="4" maxlength="500" show-word-limit :placeholder="`回复会显示在这条${dialog.append ? '追评' : '评价'}下面，所有买家都能看到。留空保存表示删除回复`" />
      <template #footer>
        <el-button @click="dialog.show = false">取消</el-button>
        <el-button type="primary" :loading="dialog.saving" @click="submitReply">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; }
.content { line-height: 1.6; white-space: pre-wrap; word-break: break-all; }
.pics { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.pic { width: 48px; height: 48px; border-radius: 4px; }
.reply { margin-top: 6px; padding: 6px 10px; background: #f5f7fa; border-radius: 4px; font-size: 13px; }
.append { margin-top: 8px; padding-top: 8px; border-top: 1px dashed #dcdfe6; }
.quote { margin-bottom: 12px; padding: 10px 12px; background: #f5f7fa; border-radius: 4px; line-height: 1.6; }
</style>
