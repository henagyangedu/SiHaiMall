<script setup>
import { ref, reactive, computed, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { state, setToken } from '@/store'
import { login, register, sendSmsCode, sendEmailCode } from '@/api'
import { refreshCartCount } from '@/utils/app'
import { usePageTitle, toast } from '@/utils/page'

const { t } = useI18n()
const mode = ref('login') // login | register
usePageTitle(() => t(mode.value === 'login' ? 'login.title' : 'login.register'))

const form = reactive({ account: '', password: '', nickname: '', code: '', invite_code: '' })
const submitting = ref(false)

const isEmail = computed(() => form.account.includes('@'))
const isPhone = computed(() => /^\d{6,15}$/.test(form.account.trim()))
// 注册时是否需要验证码：手机号看短信开关，邮箱看邮箱验证开关
const needCode = computed(() => mode.value === 'register'
  && ((isPhone.value && state.config.sms_enabled) || (isEmail.value && state.config.email_verify_enabled)))

// 获取验证码的倒计时
const wait = ref(0)
let timer = null
async function getCode() {
  if (wait.value > 0) return
  const account = form.account.trim()
  if (!account) return toast(t('login.needAccount'))
  if (isEmail.value) await sendEmailCode({ email: account, purpose: 'register' })
  else await sendSmsCode({ mobile: account, purpose: 'register' })
  toast(t('login.codeSent'))
  wait.value = 60
  timer = setInterval(() => { if (--wait.value <= 0) clearInterval(timer) }, 1000)
}
onUnmounted(() => clearInterval(timer))

async function submit() {
  const account = form.account.trim()
  if (!account) return toast(t('login.needAccount'))
  if (form.password.length < 6) return toast(t('login.needPassword'))
  submitting.value = true
  try {
    const data = mode.value === 'login'
      ? await login({ account, password: form.password })
      : await register({
        account, password: form.password, nickname: form.nickname.trim(),
        ...(needCode.value ? { code: form.code.trim() } : {}),
        ...(form.invite_code.trim() ? { invite_code: form.invite_code.trim() } : {}),
      })
    setToken(data.token, data.info)
    refreshCartCount()
    toast(t('login.welcome'))
    // 回到进入登录页之前的页面；直接打开登录页时回首页
    setTimeout(() => (getCurrentPages().length > 1 ? uni.navigateBack() : uni.switchTab({ url: '/pages/index/index' })), 400)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <view class="page">
    <view class="tabs">
      <text class="tab" :class="{ on: mode === 'login' }" @click="mode = 'login'">{{ t('login.title') }}</text>
      <text class="tab" :class="{ on: mode === 'register' }" @click="mode = 'register'">{{ t('login.register') }}</text>
    </view>

    <view class="form">
      <input v-model="form.account" class="input" :placeholder="t('login.account')" />
      <input v-model="form.password" class="input" password :placeholder="t('login.password')" />
      <template v-if="mode === 'register'">
        <view v-if="needCode" class="code-row">
          <input v-model="form.code" class="input" style="flex: 1" type="number" maxlength="8" :placeholder="t('login.code')" />
          <view class="code-btn" :class="{ off: wait > 0 }" @click="getCode">{{ wait > 0 ? t('login.resend', { n: wait }) : t('login.getCode') }}</view>
        </view>
        <input v-model="form.nickname" class="input" maxlength="60" :placeholder="t('login.nickname')" />
        <input v-model="form.invite_code" class="input" maxlength="20" :placeholder="t('login.inviteCode')" />
      </template>

      <view class="btn" :class="{ disabled: submitting }" style="margin-top: 40rpx" @click="!submitting && submit()">
        {{ t(mode === 'login' ? 'login.submitLogin' : 'login.submitRegister') }}
      </view>
      <view class="switch" @click="mode = mode === 'login' ? 'register' : 'login'">{{ t(mode === 'login' ? 'login.toRegister' : 'login.toLogin') }}</view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { background: #fff; min-height: 100vh; padding: 60rpx 48rpx; box-sizing: border-box; }
.tabs { display: flex; gap: 48rpx; margin-bottom: 48rpx; }
.tab { font-size: 34rpx; color: var(--sub); padding-bottom: 12rpx; border-bottom: 6rpx solid transparent; }
.tab.on { color: var(--text); font-weight: 600; border-bottom-color: var(--theme); font-size: 40rpx; }
.input { height: 96rpx; border-bottom: 2rpx solid var(--line); font-size: 30rpx; margin-bottom: 12rpx; }
.code-row { display: flex; align-items: center; gap: 20rpx; }
.code-btn { color: var(--theme); font-size: 26rpx; white-space: nowrap; }
.code-btn.off { color: var(--sub); }
.switch { text-align: center; color: var(--sub); margin-top: 32rpx; font-size: 26rpx; }
</style>
