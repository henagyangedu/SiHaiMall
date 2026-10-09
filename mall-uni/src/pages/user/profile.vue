<script setup>
import { reactive, ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getProfile, updateProfile, uploadImage } from '@/api'
import { usePageTitle, toast } from '@/utils/page'

const { t } = useI18n()
usePageTitle('profile.title')

const form = reactive({ nickname: '', avatar: '', gender: 0, birthday: '' })
const member = ref(null)
const saving = ref(false)
const uploading = ref(false)

// 性别：0 保密 1 男 2 女
const genders = computed(() => [0, 1, 2].map((v) => t(`profile.gender${v}`)))
const today = new Date().toISOString().slice(0, 10)

function fill(m) {
  member.value = m
  state.member = m
  Object.assign(form, { nickname: m.nickname || '', avatar: m.avatar || '', gender: m.gender || 0, birthday: m.birthday || '' })
}

// 头像：选图后先上传，再立即保存，不用等点“保存”
function pickAvatar() {
  if (uploading.value) return
  uni.chooseImage({
    count: 1,
    sizeType: ['compressed'],
    success: async (res) => {
      uploading.value = true
      uni.showLoading({ title: t('refund.uploading') })
      try {
        const r = await uploadImage(res.tempFilePaths[0], 'avatar')
        fill(await updateProfile({ avatar: r.url }))
      } catch (e) {
        // 提示已由请求层弹出
      } finally {
        uni.hideLoading()
        uploading.value = false
      }
    },
  })
}

async function save() {
  if (saving.value) return
  const nickname = form.nickname.trim()
  if (!nickname) return toast(t('profile.needNickname'))
  saving.value = true
  try {
    fill(await updateProfile({ nickname, gender: form.gender, birthday: form.birthday || null }))
    toast(t('common.saved'))
  } finally {
    saving.value = false
  }
}

onLoad(async () => {
  fill(await getProfile())
})
</script>

<template>
  <view v-if="member">
    <view class="card list">
      <view class="cell" @click="pickAvatar">
        <text>{{ t('profile.avatar') }}</text>
        <view class="right">
          <image v-if="form.avatar" :src="form.avatar" mode="aspectFill" class="avatar" />
          <view v-else class="avatar ph">{{ (form.nickname || '?').slice(0, 1) }}</view>
          <text class="sub">›</text>
        </view>
      </view>
      <view class="cell">
        <text>{{ t('profile.nickname') }}</text>
        <input v-model="form.nickname" class="input" maxlength="60" :placeholder="t('profile.nickname')" />
      </view>
      <picker :range="genders" :value="form.gender" @change="form.gender = Number($event.detail.value)">
        <view class="cell"><text>{{ t('profile.gender') }}</text><text class="sub">{{ genders[form.gender] }} ›</text></view>
      </picker>
      <picker mode="date" :value="form.birthday" :end="today" @change="form.birthday = $event.detail.value">
        <view class="cell"><text>{{ t('profile.birthday') }}</text><text class="sub">{{ form.birthday || t('profile.notSet') }} ›</text></view>
      </picker>
    </view>

    <view class="card list">
      <view class="cell"><text>{{ t('profile.account') }}</text><text class="sub">{{ member.account }}</text></view>
      <view v-if="member.phone" class="cell"><text>{{ t('profile.phone') }}</text><text class="sub">+{{ member.phone_area }} {{ member.phone }}</text></view>
      <view v-if="member.email" class="cell"><text>{{ t('profile.email') }}</text><text class="sub">{{ member.email }}</text></view>
    </view>

    <view class="btn save" :class="{ disabled: saving }" @click="save">{{ t('common.save') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.list { padding: 0 24rpx; }
.cell { display: flex; justify-content: space-between; align-items: center; min-height: 100rpx; border-bottom: 2rpx solid var(--line); }
.list > :last-child .cell, .list > .cell:last-child { border-bottom: none; }
.right { display: flex; align-items: center; }
.avatar { width: 88rpx; height: 88rpx; border-radius: 50%; margin: 16rpx 16rpx 16rpx 0; background: #f0f1f3; }
.ph { text-align: center; line-height: 88rpx; font-size: 40rpx; color: #909399; }
.input { flex: 1; text-align: right; margin-left: 24rpx; font-size: 28rpx; }
.save { margin: 40rpx 24rpx; }
</style>
