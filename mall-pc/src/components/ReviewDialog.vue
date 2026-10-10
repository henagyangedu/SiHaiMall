<script setup>
import { ref, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { createReview, appendReview, uploadImage } from '@/api'

// 评价订单里的一件商品。item 传入要评价的商品行，传 null 表示关闭；append 为 true 时是追评（只有文字和图片，文字必填）
const props = defineProps({ orderNo: { type: String, required: true }, item: { type: Object, default: null }, append: { type: Boolean, default: false } })
const emit = defineEmits(['close', 'done'])
const { t } = useI18n()

const MAX_IMAGES = 6
const form = reactive({ rating: 5, content: '', is_anonymous: false })
const images = ref([])
const uploading = ref(false)
const submitting = ref(false)
const picker = ref(null)

// 每次打开都是一张空白的评价
watch(() => props.item, (i) => {
  if (i) { Object.assign(form, { rating: 5, content: '', is_anonymous: false }); images.value = [] }
})

async function onFiles(e) {
  const files = [...e.target.files].slice(0, MAX_IMAGES - images.value.length)
  e.target.value = '' // 允许再次选择同一张图
  if (!files.length || uploading.value) return
  uploading.value = true
  try {
    // 逐张上传：某一张失败（提示已弹出）就停下，已传好的保留
    for (const f of files) images.value.push((await uploadImage(f, 'review')).url)
  } catch (err) {
    // 提示已由 upload 弹出
  } finally {
    uploading.value = false
  }
}

async function submit() {
  if (submitting.value || uploading.value) return
  if (props.append ? !form.content.trim() : !form.rating) return ElMessage.warning(t(props.append ? 'review.needContent' : 'review.needRating'))
  submitting.value = true
  try {
    if (props.append) {
      await appendReview(props.orderNo, { order_item_id: props.item.item_id, content: form.content.trim(), images: images.value })
    } else {
      await createReview(props.orderNo, {
        order_item_id: props.item.item_id, rating: form.rating, content: form.content.trim(), images: images.value, is_anonymous: form.is_anonymous,
      })
    }
    ElMessage.success(t(props.append ? 'review.appendDone' : 'review.done'))
    emit('done')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <el-dialog :model-value="!!item" :title="t(append ? 'review.appendTitle' : 'review.write')" width="600px" destroy-on-close @close="emit('close')">
    <template v-if="item">
      <div class="goods">
        <img :src="item.image" alt="" class="thumb" />
        <div class="body"><div class="ellipsis-2">{{ item.title }}</div><div class="sub">{{ item.spec_text }}</div></div>
      </div>
      <el-form label-width="90px">
        <el-form-item v-if="!append" :label="t('review.rating')">
          <el-rate v-model="form.rating" :clearable="false" />
          <span class="sub star-text">{{ form.rating ? t(`review.star.${form.rating}`) : '' }}</span>
        </el-form-item>
        <el-form-item :label="t(append ? 'review.appendBtn' : 'review.content')">
          <el-input v-model="form.content" type="textarea" :rows="4" :maxlength="append ? 500 : 1000" show-word-limit :placeholder="t(append ? 'review.appendPh' : 'review.contentPh')" />
        </el-form-item>
        <el-form-item :label="t('pc.chooseImage')">
          <div class="pics">
            <div v-for="(u, i) in images" :key="u" class="pic">
              <el-image :src="u" :preview-src-list="images" :initial-index="i" fit="cover" preview-teleported class="pic-img" />
              <span class="pic-del" @click="images.splice(i, 1)">×</span>
            </div>
            <div v-if="images.length < MAX_IMAGES" v-loading="uploading" class="pic pic-add" @click="picker.click()">＋</div>
            <input ref="picker" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden @change="onFiles" />
          </div>
        </el-form-item>
        <el-form-item v-if="append"><span class="sub">{{ t('review.appendTip') }}</span></el-form-item>
        <el-form-item v-else>
          <el-checkbox v-model="form.is_anonymous">{{ t('review.anonymous') }}</el-checkbox>
          <span class="sub anon-tip">{{ t('review.anonymousTip') }}</span>
        </el-form-item>
      </el-form>
    </template>
    <template #footer>
      <el-button @click="emit('close')">{{ t('common.cancel') }}</el-button>
      <el-button type="primary" :loading="submitting" :disabled="uploading" @click="submit">{{ t(append ? 'review.appendSubmit' : 'review.submit') }}</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.goods { display: flex; gap: 14px; align-items: center; margin-bottom: 18px; }
.thumb { width: 64px; height: 64px; border-radius: 6px; object-fit: cover; background: #f5f6f8; flex: none; }
.body { min-width: 0; }
.star-text { margin-left: 12px; }
.pics { display: flex; flex-wrap: wrap; gap: 10px; }
.pic { position: relative; width: 80px; height: 80px; border-radius: 6px; background: #f5f6f8; }
.pic-img { width: 100%; height: 100%; border-radius: 6px; }
.pic-del { position: absolute; top: -8px; right: -8px; width: 20px; height: 20px; line-height: 18px; text-align: center; border-radius: 50%; background: rgba(0, 0, 0, 0.6); color: #fff; cursor: pointer; }
.pic-add { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 28px; border: 1px dashed #dcdfe6; cursor: pointer; }
.anon-tip { margin-left: 12px; }
</style>
