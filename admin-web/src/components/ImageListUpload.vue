<script setup>
import { ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, ArrowLeftBold, ArrowRightBold, Close } from '@element-plus/icons-vue'
import { uploadImage } from '@/api/upload'

// 用法：<ImageListUpload v-model="form.images" :max="9" scene="goods" />   v-model 是图片 URL 数组，第一张为主图
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  max: { type: Number, default: 9 },
  scene: { type: String, default: 'goods' },
  size: { type: Number, default: 100 },
})
const emit = defineEmits(['update:modelValue'])

const list = ref([...(props.modelValue || [])])
const uploading = ref(0)
watch(() => props.modelValue, (v) => { list.value = [...(v || [])] })

const ALLOWED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
function sync() {
  emit('update:modelValue', [...list.value])
}
function beforeUpload(file) {
  if (!ALLOWED.includes(file.type)) {
    ElMessage.error('仅支持 jpg、png、gif、webp 图片')
    return false
  }
  if (file.size > 5 * 1024 * 1024) {
    ElMessage.error('图片不能超过 5MB')
    return false
  }
  if (list.value.length + uploading.value >= props.max) {
    ElMessage.warning(`最多上传 ${props.max} 张`)
    return false
  }
  return true
}
async function request({ file }) {
  uploading.value++
  try {
    const d = await uploadImage(file, props.scene)
    list.value.push(d.url)
    sync()
  } finally {
    uploading.value--
  }
}
function move(i, dir) {
  const j = i + dir
  if (j < 0 || j >= list.value.length) return
  ;[list.value[i], list.value[j]] = [list.value[j], list.value[i]]
  sync()
}
function remove(i) {
  list.value.splice(i, 1)
  sync()
}
</script>

<template>
  <div class="img-list">
    <div v-for="(url, i) in list" :key="url + i" class="item" :style="{ width: size + 'px', height: size + 'px' }">
      <img :src="url" />
      <span v-if="i === 0" class="main">主图</span>
      <div class="bar">
        <el-icon @click="move(i, -1)"><ArrowLeftBold /></el-icon>
        <el-icon @click="remove(i)"><Close /></el-icon>
        <el-icon @click="move(i, 1)"><ArrowRightBold /></el-icon>
      </div>
    </div>
    <el-upload
      v-if="list.length < max"
      v-loading="uploading > 0"
      class="adder"
      :style="{ width: size + 'px', height: size + 'px' }"
      :show-file-list="false"
      :http-request="request"
      :before-upload="beforeUpload"
      multiple
      accept="image/*"
    >
      <el-icon class="plus"><Plus /></el-icon>
    </el-upload>
    <div class="tip">已传 {{ list.length }}/{{ max }} 张，第一张为主图，用 ← → 调整顺序</div>
  </div>
</template>

<style scoped>
.img-list { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; }
.item { position: relative; border: 1px solid #dcdfe6; border-radius: 6px; overflow: hidden; background: #fafafa; }
.item img { width: 100%; height: 100%; object-fit: contain; display: block; }
.main { position: absolute; left: 0; top: 0; background: #409eff; color: #fff; font-size: 12px; padding: 0 6px; border-bottom-right-radius: 6px; }
.bar { position: absolute; left: 0; right: 0; bottom: 0; display: none; justify-content: space-around; background: rgba(0, 0, 0, .55); color: #fff; padding: 3px 0; cursor: pointer; }
.item:hover .bar { display: flex; }
.adder { border: 1px dashed #c0c4cc; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; background: #fafafa; }
.adder:hover { border-color: #409eff; }
.adder :deep(.el-upload) { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
.plus { font-size: 24px; color: #8c939d; }
.tip { width: 100%; color: #909399; font-size: 12px; }
</style>
