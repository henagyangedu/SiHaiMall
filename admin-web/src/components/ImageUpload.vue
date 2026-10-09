<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { uploadImage } from '@/api/upload'

// 用法：<ImageUpload v-model="form.cover" scene="goods" />   v-model 是图片完整 URL
const props = defineProps({
  modelValue: { type: String, default: '' },
  scene: { type: String, default: 'other' },
  width: { type: Number, default: 120 },
  height: { type: Number, default: 120 },
  tip: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const loading = ref(false)
const ALLOWED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon']

function beforeUpload(file) {
  if (!ALLOWED.includes(file.type)) {
    ElMessage.error('仅支持 jpg、png、gif、webp、ico 图片')
    return false
  }
  if (file.size > 5 * 1024 * 1024) {
    ElMessage.error('图片不能超过 5MB')
    return false
  }
  return true
}
async function request({ file }) {
  loading.value = true
  try {
    const d = await uploadImage(file, props.scene)
    emit('update:modelValue', d.url)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div v-loading="loading" class="image-upload">
    <el-upload
      class="uploader"
      :style="{ width: width + 'px', height: height + 'px' }"
      :show-file-list="false"
      :http-request="request"
      :before-upload="beforeUpload"
      accept="image/*,.ico"
    >
      <img v-if="modelValue" :src="modelValue" class="preview" />
      <el-icon v-else class="plus"><Plus /></el-icon>
    </el-upload>
    <div class="side">
      <el-button v-if="modelValue" link type="danger" @click="emit('update:modelValue', '')">移除</el-button>
      <div v-if="tip" class="tip">{{ tip }}</div>
    </div>
  </div>
</template>

<style scoped>
.image-upload { display: flex; align-items: flex-end; gap: 12px; }
.uploader { border: 1px dashed #c0c4cc; border-radius: 6px; overflow: hidden; display: flex; align-items: center; justify-content: center; cursor: pointer; background: #fafafa; }
.uploader:hover { border-color: #409eff; }
.uploader :deep(.el-upload) { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
.preview { max-width: 100%; max-height: 100%; object-fit: contain; }
.plus { font-size: 24px; color: #8c939d; }
.tip { color: #909399; font-size: 12px; line-height: 1.5; }
</style>
