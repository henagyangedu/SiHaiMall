<script setup>
import { ref, reactive, computed, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { sendSmsCode, sendEmailCode, resetPasswordBySms, resetPasswordByEmail } from '@/api'
import { usePageTitle, toast } from '@/utils/page'

const { t } = useI18n()
usePageTitle('auth.resetTitle')

const form = reactive({ account: '', code: '', password: '' })
const submitting = ref(false)
const isEmail = computed(() => form.account.includes('@'))
// 邮箱找回要后台开启邮箱验证，手机找回要开启短信；都没开时只能联系客服
const available = computed(() => (isEmail.value ? state.config.email_verify_enabled : state.config.sms_enabled))

const wait = ref(0)
let timer = null
async function getCode() {
  if (wait.value > 0) return
  const account = form.account.trim()
  if (!account) return toast(t('login.needAccount'))
  if (!available.value) return toast(t('auth.resetUnavailable'))
  if (isEmail.value) await sendEmailCode({ email: account, purpose: 'reset' })
  else await sendSmsCode({ mobile: account, purpose: 'reset' })
  toast(t('login.codeSent'))
  wait.value = 60
  timer = setInterval(() => { if (--wait.value <= 0) clearInterval(timer) }, 1000)
}
onUnmounted(() => clearInterval(timer))

async function submit() {
  if (submitting.value) return
  const account = form.account.trim()
  if (!account) return toast(t('login.needAccount'))
  if (!available.value) return toast(t('auth.resetUnavailable'))
  if (!form.code.trim()) return toast(t('auth.needCode'))
  if (form.password.length < 6) return toast(t('login.needPassword'))
  submitting.value = true
  try {
    if (isEmail.value) await resetPasswordByEmail({ email: account, code: form.code.trim(), password: form.password })
    else await resetPasswordBySms({ mobile: account, code: form.code.trim(), password: form.password })
    toast(t('auth.resetDone'))
    setTimeout(() => uni.navigateBack(), 800)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <view class="page">
    <input v-model="form.account" class="input" :placeholder="t('auth.resetAccount')" />
    <view class="code-row">
      <input v-model="form.code" class="input" style="flex: 1" type="number" maxlength="8" :placeholder="t('login.code')" />
      <view class="code-btn" :class="{ off: wait > 0 }" @click="getCode">{{ wait > 0 ? t('login.resend', { n: wait }) : t('login.getCode') }}</view>
    </view>
    <input v-model="form.password" class="input" password :placeholder="t('auth.newPassword')" />
    <view class="btn" :class="{ disabled: submitting }" style="margin-top: 40rpx" @click="submit">{{ t('auth.resetSubmit') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.page { background: #fff; min-height: 100vh; padding: 40rpx 48rpx; box-sizing: border-box; }
.input { height: 96rpx; border-bottom: 2rpx solid var(--line); font-size: 30rpx; margin-bottom: 12rpx; }
.code-row { display: flex; align-items: center; gap: 20rpx; }
.code-btn { color: var(--theme); font-size: 26rpx; white-space: nowrap; }
.code-btn.off { color: var(--sub); }
</style>
