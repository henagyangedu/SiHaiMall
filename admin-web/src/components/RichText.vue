<script setup>
import '@wangeditor/editor/dist/css/style.css'
import { ref, shallowRef, watch, onBeforeUnmount } from 'vue'
import { Editor, Toolbar } from '@wangeditor/editor-for-vue'
import { ElMessage } from 'element-plus'
import { uploadImage } from '@/api/upload'

const props = defineProps({
  modelValue: { type: String, default: '' },
  height: { type: Number, default: 360 },
})
const emit = defineEmits(['update:modelValue'])

const editorRef = shallowRef()
const html = ref(props.modelValue || '')
const EMPTY = '<p><br></p>'

const toolbarConfig = { excludeKeys: ['group-video', 'insertTable', 'codeBlock', 'fullScreen'] }
const editorConfig = {
  placeholder: '请输入内容…',
  MENU_CONF: {
    uploadImage: {
      async customUpload(file, insertFn) {
        try {
          const d = await uploadImage(file, 'editor')
          insertFn(d.url, d.name || '', d.url)
        } catch (e) {
          ElMessage.error('图片上传失败')
        }
      },
    },
  },
}

watch(html, (v) => emit('update:modelValue', v === EMPTY ? '' : v))
watch(() => props.modelValue, (v) => {
  if ((v || '') !== (html.value === EMPTY ? '' : html.value)) html.value = v || ''
})

function onCreated(editor) {
  editorRef.value = editor
}
onBeforeUnmount(() => {
  const editor = editorRef.value
  if (editor) editor.destroy()
})
</script>

<template>
  <div class="rich">
    <Toolbar :editor="editorRef" :default-config="toolbarConfig" mode="default" class="bar" />
    <Editor v-model="html" :default-config="editorConfig" mode="default" :style="{ height: height + 'px', overflowY: 'hidden' }" @on-created="onCreated" />
  </div>
</template>

<style scoped>
.rich { width: 100%; border: 1px solid #dcdfe6; border-radius: 4px; z-index: 10; }
.bar { border-bottom: 1px solid #dcdfe6; }
</style>
