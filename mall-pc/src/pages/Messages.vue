<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getMessages, readMessage, readAllMessages, deleteMessage } from '@/api'

// 消息中心（站内信）：支付成功、发货、退款、到货提醒等通知，以及商家在后台群发的消息。点一条就标为已读；有关联的订单 / 售后单 / 商品时可以点“查看详情”过去
const { t } = useI18n()
const router = useRouter()
const list = ref([])
const page = reactive({ no: 1, size: 10, total: 0, loading: false })

async function load() {
  page.loading = true
  try {
    const d = await getMessages({ page: page.no, size: page.size })
    list.value = d.list
    page.total = d.total
    state.unread = d.unread
  } finally {
    page.loading = false
  }
}
async function markRead(m) {
  if (m.is_read) return
  m.is_read = true
  state.unread = Math.max(0, state.unread - 1)
  await readMessage(m.id)
}
async function readAll() {
  await readAllMessages()
  list.value.forEach((m) => { m.is_read = true })
  state.unread = 0
}
async function remove(m) {
  await deleteMessage(m.id)
  // 这一页删空了就回到上一页
  if (list.value.length === 1 && page.no > 1) page.no -= 1
  load()
}
// 消息关联的页面；没有关联的返回 null
function target(m) {
  if (m.link_type === 'order' && m.link_value) return { name: 'order-detail', params: { orderNo: m.link_value } }
  if (m.link_type === 'refund' && m.link_value) return { name: 'refund-detail', params: { refundNo: m.link_value } }
  if (m.link_type === 'goods' && m.link_value) return { name: 'goods-detail', params: { id: m.link_value } }
  if (m.link_type === 'withdraw') return { name: 'distribution' }
  // 后台群发的消息还可以跳到文章、领券中心
  if (m.link_type === 'article' && m.link_value) return { name: 'article-detail', params: { id: m.link_value } }
  if (m.link_type === 'coupon') return { name: 'coupons' }
  return null
}
async function open(m) {
  await markRead(m).catch(() => {})
  router.push(target(m))
}

onMounted(load)
</script>

<template>
  <div class="panel">
    <div class="panel-title head">
      <span>{{ t('msg.title') }}<span v-if="state.unread" class="unread-count">{{ t('msg.unreadCount', { n: state.unread }) }}</span></span>
      <el-button v-if="state.unread" size="small" @click="readAll">{{ t('msg.readAll') }}</el-button>
    </div>
    <div v-loading="page.loading" class="rows">
      <div v-for="m in list" :key="m.id" class="msg" :class="{ unread: !m.is_read }" @click="markRead(m)">
        <span class="dot" />
        <div class="body">
          <div class="title">{{ m.title }}</div>
          <div class="content">{{ m.content }}</div>
          <div class="foot">
            <span class="sub">{{ m.created_at }}</span>
            <span class="ops">
              <a v-if="target(m)" class="view" @click.stop="open(m)">{{ t('msg.view') }}</a>
              <a class="remove" @click.stop="remove(m)">{{ t('msg.remove') }}</a>
            </span>
          </div>
        </div>
      </div>
    </div>
    <div v-if="!page.loading && !list.length" class="empty">{{ t('msg.empty') }}</div>
    <div v-if="page.total > page.size" class="pager">
      <el-pagination v-model:current-page="page.no" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="load" />
    </div>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: center; }
.unread-count { margin-left: 12px; font-size: 13px; font-weight: 400; color: var(--theme); }
.rows { min-height: 80px; }
.msg { display: flex; gap: 12px; padding: 16px 0; border-bottom: 1px solid var(--line); cursor: default; }
.msg:last-child { border-bottom: none; }
.dot { flex: none; width: 8px; height: 8px; margin-top: 7px; border-radius: 50%; background: transparent; }
.msg.unread { cursor: pointer; }
.msg.unread .dot { background: var(--theme); }
.body { flex: 1; min-width: 0; }
.title { color: var(--sub); }
.msg.unread .title { color: var(--text); font-weight: 600; }
.content { margin-top: 6px; color: var(--sub); font-size: 13px; line-height: 1.7; white-space: pre-wrap; word-break: break-word; }
.foot { margin-top: 8px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
.ops { display: flex; gap: 20px; }
.view { color: var(--theme); cursor: pointer; }
.remove { color: var(--sub); cursor: pointer; }
.remove:hover { color: var(--theme); }
</style>
