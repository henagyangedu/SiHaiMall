<script setup>
import { reactive, ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { state } from '@/store'
import { getProfile, updateProfile, uploadImage } from '@/api'

const { t } = useI18n()
const form = reactive({ nickname: '', avatar: '', gender: 0, birthday: '' })
const member = ref(null)
const saving = ref(false)
const uploading = ref(false)
const picker = ref(null)

function fill(m) {
  member.value = m
  state.member = m
  Object.assign(form, { nickname: m.nickname || '', avatar: m.avatar || '', gender: m.gender || 0, birthday: m.birthday || '' })
}

// 头像：选图后先上传，再立即保存，不用等点“保存”
async function onAvatar(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file || uploading.value) return
  uploading.value = true
  try {
    const r = await uploadImage(file, 'avatar')
    fill(await updateProfile({ avatar: r.url }))
  } catch (err) {
    // 提示已由请求层弹出
  } finally {
    uploading.value = false
  }
}

async function save() {
  if (saving.value) return
  const nickname = form.nickname.trim()
  if (!nickname) return ElMessage.warning(t('profile.needNickname'))
  saving.value = true
  try {
    fill(await updateProfile({ nickname, gender: form.gender, birthday: form.birthday || null }))
    ElMessage.success(t('common.saved'))
  } finally {
    saving.value = false
  }
}
// 生日不能选今天及以后
const afterToday = (d) => d.getTime() > Date.now() - 86400000

onMounted(async () => {
  fill(await getProfile())
})
</script>

<template>
  <div v-if="member" class="panel">
    <div class="panel-title">{{ t('profile.title') }}</div>
    <el-form label-width="110px" class="form">
      <el-form-item :label="t('profile.avatar')">
        <div v-loading="uploading" class="avatar-box">
          <img v-if="form.avatar" :src="form.avatar" alt="" class="avatar" />
          <div v-else class="avatar ph">{{ (form.nickname || '?').slice(0, 1) }}</div>
        </div>
        <el-button class="change" @click="picker.click()">{{ t('pc.changeAvatar') }}</el-button>
        <input ref="picker" type="file" accept="image/jpeg,image/png,image/webp" hidden @change="onAvatar" />
      </el-form-item>
      <el-form-item :label="t('profile.nickname')" required><el-input v-model="form.nickname" maxlength="60" /></el-form-item>
      <el-form-item :label="t('profile.gender')">
        <el-radio-group v-model="form.gender">
          <!-- 0 保密 1 男 2 女 -->
          <el-radio v-for="v in [0, 1, 2]" :key="v" :value="v">{{ t(`profile.gender${v}`) }}</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item :label="t('profile.birthday')">
        <el-date-picker v-model="form.birthday" type="date" value-format="YYYY-MM-DD" :disabled-date="afterToday" :placeholder="t('profile.notSet')" />
      </el-form-item>
      <el-form-item :label="t('profile.account')"><span class="sub">{{ member.account }}</span></el-form-item>
      <el-form-item v-if="member.phone" :label="t('profile.phone')"><span class="sub">+{{ member.phone_area }} {{ member.phone }}</span></el-form-item>
      <el-form-item v-if="member.email" :label="t('profile.email')"><span class="sub">{{ member.email }}</span></el-form-item>
      <el-form-item><el-button type="primary" :loading="saving" @click="save">{{ t('common.save') }}</el-button></el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.form { max-width: 520px; }
.avatar-box { width: 72px; height: 72px; border-radius: 50%; overflow: hidden; }
.avatar { width: 72px; height: 72px; border-radius: 50%; object-fit: cover; background: #f0f1f3; display: block; }
.ph { text-align: center; line-height: 72px; font-size: 30px; color: var(--sub); }
.change { margin-left: 20px; }
</style>
