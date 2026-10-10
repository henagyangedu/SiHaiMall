<script setup>
import { ref, reactive } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getOrder, createReview, uploadImage } from '@/api'
import { usePageTitle, toast } from '@/utils/page'

const { t } = useI18n()
usePageTitle('review.write')

const MAX_IMAGES = 6
const orderNo = ref('')
const item = ref(null)
const form = reactive({ rating: 5, content: '', is_anonymous: false })
const images = ref([])
const uploading = ref(false)
const submitting = ref(false)

function addImages() {
  if (uploading.value || images.value.length >= MAX_IMAGES) return
  uni.chooseImage({
    count: MAX_IMAGES - images.value.length,
    sizeType: ['compressed'],
    success: async (res) => {
      uploading.value = true
      uni.showLoading({ title: t('refund.uploading') })
      try {
        // 逐张上传：某一张失败（提示已弹出）就停下，已传好的保留
        for (const path of res.tempFilePaths.slice(0, MAX_IMAGES - images.value.length)) {
          images.value.push((await uploadImage(path, 'review')).url)
        }
      } catch (e) {
        // 提示已由 upload 弹出
      } finally {
        uni.hideLoading()
        uploading.value = false
      }
    },
  })
}
function preview(i) {
  uni.previewImage({ urls: images.value, current: i })
}

async function submit() {
  if (submitting.value || uploading.value) return
  submitting.value = true
  try {
    await createReview(orderNo.value, {
      order_item_id: item.value.item_id, rating: form.rating, content: form.content.trim(), images: images.value, is_anonymous: form.is_anonymous,
    })
    toast(t('review.done'))
    setTimeout(() => uni.navigateBack(), 600)
  } finally {
    submitting.value = false
  }
}

onLoad(async (q) => {
  orderNo.value = q.order_no || ''
  const order = await getOrder(orderNo.value)
  item.value = order.items.find((i) => i.item_id === Number(q.item_id)) || null
})
</script>

<template>
  <view v-if="item">
    <view class="card goods">
      <image :src="item.image" mode="aspectFill" class="thumb" />
      <view class="body">
        <view class="ellipsis-2">{{ item.title }}</view>
        <view class="sub ellipsis">{{ item.spec_text }}</view>
      </view>
    </view>

    <view v-if="item.can_review" class="card">
      <view class="row">
        <text>{{ t('review.rating') }}</text>
        <view class="stars">
          <text v-for="n in 5" :key="n" class="star" :class="{ on: n <= form.rating }" @click="form.rating = n">★</text>
        </view>
        <text class="sub">{{ t(`review.star.${form.rating}`) }}</text>
      </view>
      <textarea v-model="form.content" class="textarea" maxlength="1000" :placeholder="t('review.contentPh')" />
      <view class="pics">
        <view v-for="(u, i) in images" :key="u" class="pic">
          <image :src="u" mode="aspectFill" class="pic-img" @click="preview(i)" />
          <view class="pic-del" @click.stop="images.splice(i, 1)">×</view>
        </view>
        <view v-if="images.length < MAX_IMAGES" class="pic pic-add" @click="addImages">＋</view>
      </view>
      <view class="row anon" @click="form.is_anonymous = !form.is_anonymous">
        <view class="check" :class="{ on: form.is_anonymous }" />
        <text>{{ t('review.anonymous') }}</text>
        <text class="sub">　{{ t('review.anonymousTip') }}</text>
      </view>
    </view>
    <view v-else class="empty">{{ t(item.reviewed ? 'review.already' : 'review.notYet') }}</view>

    <view v-if="item.can_review" class="btn submit" :class="{ disabled: submitting || uploading }" @click="submit">{{ t('review.submit') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.goods { display: flex; gap: 20rpx; align-items: center; }
.thumb { width: 120rpx; height: 120rpx; border-radius: 12rpx; flex: none; background: #f3ede2; }
.body { min-width: 0; flex: 1; }
.row { display: flex; align-items: center; }
.stars { margin: 0 20rpx; }
.star { font-size: 52rpx; color: #ded6c8; padding: 0 6rpx; }
.star.on { color: #f7ba2a; }
.textarea { width: 100%; height: 220rpx; margin-top: 24rpx; padding: 20rpx; box-sizing: border-box; background: #f3ede2; border-radius: 12rpx; font-size: 28rpx; }
.pics { display: flex; flex-wrap: wrap; gap: 16rpx; margin-top: 24rpx; }
.pic { position: relative; width: 160rpx; height: 160rpx; border-radius: 12rpx; background: #f3ede2; }
.pic-img { width: 100%; height: 100%; border-radius: 12rpx; }
.pic-del { position: absolute; top: -12rpx; right: -12rpx; width: 40rpx; height: 40rpx; line-height: 36rpx; text-align: center; border-radius: 50%; background: rgba(0, 0, 0, 0.6); color: #fff; font-size: 28rpx; }
.pic-add { display: flex; align-items: center; justify-content: center; color: #c9c2b5; font-size: 56rpx; border: 2rpx dashed #ded6c8; box-sizing: border-box; }
.anon { margin-top: 28rpx; }
.check { width: 36rpx; height: 36rpx; border-radius: 50%; border: 2rpx solid #c9c2b5; margin-right: 16rpx; box-sizing: border-box; }
.check.on { border-color: var(--theme); background: var(--theme); box-shadow: inset 0 0 0 6rpx #fff; }
.submit { margin: 40rpx 24rpx; }
</style>
