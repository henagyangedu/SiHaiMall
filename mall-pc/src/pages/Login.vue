<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { state, setToken } from '@/store'
import { login, register, loginBySms, sendSmsCode, sendEmailCode, getProtocols } from '@/api'
import { refreshCartCount } from '@/utils/app'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const mode = ref('login') // login | register
// 登录方式：密码，或短信验证码（后台开启短信后才有）
const bySms = ref(false)
const form = reactive({ account: '', password: '', nickname: '', code: '', invite_code: '' })
const submitting = ref(false)
const protocols = ref([])

const isEmail = computed(() => form.account.includes('@'))
const isPhone = computed(() => /^\d{6,15}$/.test(form.account.trim()))
const smsLogin = computed(() => mode.value === 'login' && bySms.value)
// 什么时候要填验证码：短信登录；或注册时手机号开了短信验证、邮箱开了邮箱验证
const needCode = computed(() => smsLogin.value || (mode.value === 'register'
  && ((isPhone.value && state.config.sms_enabled) || (isEmail.value && state.config.email_verify_enabled))))

// 获取验证码的倒计时
const wait = ref(0)
let timer = null
async function getCode() {
  if (wait.value > 0) return
  const account = form.account.trim()
  if (!account) return ElMessage.warning(t(smsLogin.value ? 'auth.needMobile' : 'login.needAccount'))
  const purpose = smsLogin.value ? 'login' : 'register'
  if (!smsLogin.value && isEmail.value) await sendEmailCode({ email: account, purpose })
  else await sendSmsCode({ mobile: account, purpose })
  ElMessage.success(t('login.codeSent'))
  wait.value = 60
  timer = setInterval(() => { if (--wait.value <= 0) clearInterval(timer) }, 1000)
}
onUnmounted(() => clearInterval(timer))

function switchMode(m) {
  mode.value = m
  form.code = ''
}

// 登录后只回到站内地址，避免被带参数的链接引到别处
function backTo() {
  const r = String(route.query.redirect || '')
  return r.startsWith('/') && !r.startsWith('//') ? r : '/'
}

async function submit() {
  if (submitting.value) return
  const account = form.account.trim()
  if (!account) return ElMessage.warning(t(smsLogin.value ? 'auth.needMobile' : 'login.needAccount'))
  if (!smsLogin.value && form.password.length < 6) return ElMessage.warning(t('login.needPassword'))
  if (needCode.value && !form.code.trim()) return ElMessage.warning(t('auth.needCode'))
  submitting.value = true
  try {
    let data
    if (smsLogin.value) {
      data = await loginBySms({ mobile: account, code: form.code.trim() })
    } else if (mode.value === 'login') {
      data = await login({ account, password: form.password })
    } else {
      data = await register({
        account, password: form.password, nickname: form.nickname.trim(),
        ...(needCode.value ? { code: form.code.trim() } : {}),
        ...(form.invite_code.trim() ? { invite_code: form.invite_code.trim() } : {}),
      })
    }
    setToken(data.token, data.info)
    refreshCartCount()
    ElMessage.success(t('login.welcome'))
    router.replace(backTo())
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  if (state.token) return router.replace(backTo())
  // 通过邀请链接进来：直接进注册并带上邀请码
  if (route.query.invite) { mode.value = 'register'; form.invite_code = String(route.query.invite) }
  // 没有填写协议时不显示“注册即表示同意”
  getProtocols().then((d) => { protocols.value = d.filter((p) => ['user_agreement', 'privacy_policy'].includes(p.key)) }).catch(() => {})
})
</script>

<template>
  <div class="panel box">
    <el-tabs :model-value="mode" stretch @tab-change="switchMode">
      <el-tab-pane :label="t('login.title')" name="login" />
      <el-tab-pane :label="t('login.register')" name="register" />
    </el-tabs>

    <el-form size="large" @submit.prevent="submit">
      <el-form-item>
        <el-input v-model="form.account" :placeholder="t(smsLogin ? 'auth.mobile' : 'login.account')" autocomplete="username" />
      </el-form-item>
      <el-form-item v-if="!smsLogin">
        <el-input v-model="form.password" type="password" show-password :placeholder="t('login.password')" :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" @keyup.enter="submit" />
      </el-form-item>
      <el-form-item v-if="needCode">
        <div class="code-row">
          <el-input v-model="form.code" maxlength="8" :placeholder="t('login.code')" @keyup.enter="submit" />
          <el-button :disabled="wait > 0" @click="getCode">{{ wait > 0 ? t('login.resend', { n: wait }) : t('login.getCode') }}</el-button>
        </div>
      </el-form-item>
      <template v-if="mode === 'register'">
        <el-form-item><el-input v-model="form.nickname" maxlength="60" :placeholder="t('login.nickname')" /></el-form-item>
        <el-form-item><el-input v-model="form.invite_code" maxlength="20" :placeholder="t('login.inviteCode')" /></el-form-item>
      </template>

      <div v-if="mode === 'login'" class="links">
        <a v-if="state.config.sms_enabled" class="link" @click="bySms = !bySms; form.code = ''">{{ t(bySms ? 'auth.pwdLogin' : 'auth.smsLogin') }}</a>
        <span v-else />
        <router-link :to="{ name: 'reset-password' }" class="link">{{ t('auth.forgot') }}</router-link>
      </div>

      <el-button type="primary" class="submit" :loading="submitting" @click="submit">{{ t(mode === 'login' ? 'login.submitLogin' : 'login.submitRegister') }}</el-button>
    </el-form>

    <div v-if="mode === 'register' && protocols.length" class="agree sub">
      {{ t('login.agree') }}
      <router-link v-for="p in protocols" :key="p.key" :to="{ name: 'protocols', params: { key: p.key } }" target="_blank" class="link"> {{ p.title }}</router-link>
    </div>

    <div class="switch">
      <a class="link" @click="switchMode(mode === 'login' ? 'register' : 'login')">{{ t(mode === 'login' ? 'login.toRegister' : 'login.toLogin') }}</a>
    </div>
  </div>
</template>

<style scoped>
.box { width: 420px; margin: 40px auto; padding: 28px 36px 32px; }
.code-row { display: flex; gap: 10px; width: 100%; }
.links { display: flex; justify-content: space-between; margin: -6px 0 14px; font-size: 13px; }
.agree { text-align: center; margin-top: 14px; }
.submit { width: 100%; }
.switch { text-align: center; margin-top: 18px; font-size: 13px; }
</style>
