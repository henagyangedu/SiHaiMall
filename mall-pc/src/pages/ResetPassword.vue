<script setup>
import { ref, reactive, computed, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { state } from '@/store'
import { sendSmsCode, sendEmailCode, resetPasswordBySms, resetPasswordByEmail } from '@/api'

const { t } = useI18n()
const router = useRouter()

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
  if (!account) return ElMessage.warning(t('login.needAccount'))
  if (!available.value) return ElMessage.warning(t('auth.resetUnavailable'))
  if (isEmail.value) await sendEmailCode({ email: account, purpose: 'reset' })
  else await sendSmsCode({ mobile: account, purpose: 'reset' })
  ElMessage.success(t('login.codeSent'))
  wait.value = 60
  timer = setInterval(() => { if (--wait.value <= 0) clearInterval(timer) }, 1000)
}
onUnmounted(() => clearInterval(timer))

async function submit() {
  if (submitting.value) return
  const account = form.account.trim()
  if (!account) return ElMessage.warning(t('login.needAccount'))
  if (!available.value) return ElMessage.warning(t('auth.resetUnavailable'))
  if (!form.code.trim()) return ElMessage.warning(t('auth.needCode'))
  if (form.password.length < 6) return ElMessage.warning(t('login.needPassword'))
  submitting.value = true
  try {
    if (isEmail.value) await resetPasswordByEmail({ email: account, code: form.code.trim(), password: form.password })
    else await resetPasswordBySms({ mobile: account, code: form.code.trim(), password: form.password })
    ElMessage.success(t('auth.resetDone'))
    router.replace({ name: 'login' })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="panel box">
    <div class="panel-title">{{ t('auth.resetTitle') }}</div>
    <el-form size="large" @submit.prevent="submit">
      <el-form-item><el-input v-model="form.account" :placeholder="t('auth.resetAccount')" autocomplete="username" /></el-form-item>
      <el-form-item>
        <div class="code-row">
          <el-input v-model="form.code" maxlength="8" :placeholder="t('login.code')" />
          <el-button :disabled="wait > 0" @click="getCode">{{ wait > 0 ? t('login.resend', { n: wait }) : t('login.getCode') }}</el-button>
        </div>
      </el-form-item>
      <el-form-item><el-input v-model="form.password" type="password" show-password :placeholder="t('auth.newPassword')" autocomplete="new-password" @keyup.enter="submit" /></el-form-item>
      <el-button type="primary" class="submit" :loading="submitting" @click="submit">{{ t('auth.resetSubmit') }}</el-button>
    </el-form>
    <div class="back"><router-link :to="{ name: 'login' }" class="link">{{ t('pc.backToLogin') }}</router-link></div>
  </div>
</template>

<style scoped>
.box { width: 420px; margin: 40px auto; padding: 28px 36px 32px; }
.code-row { display: flex; gap: 10px; width: 100%; }
.submit { width: 100%; }
.back { text-align: center; margin-top: 18px; font-size: 13px; }
</style>
