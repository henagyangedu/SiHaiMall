<script setup>
import { computed } from 'vue'
import * as Icons from '@element-plus/icons-vue'

const props = defineProps({ item: { type: Object, required: true } })

const children = computed(() => (props.item.children || []).filter((c) => c.visible !== 0))
const iconName = computed(() => (props.item.icon && Icons[props.item.icon] ? props.item.icon : ''))
</script>

<template>
  <el-menu-item v-if="item.type === 2" :index="item.path">
    <el-icon v-if="iconName"><component :is="iconName" /></el-icon>
    <template #title>{{ item.title_key }}</template>
  </el-menu-item>
  <el-sub-menu v-else-if="children.length" :index="item.path">
    <template #title>
      <el-icon v-if="iconName"><component :is="iconName" /></el-icon>
      <span>{{ item.title_key }}</span>
    </template>
    <SidebarItem v-for="c in children" :key="c.id" :item="c" />
  </el-sub-menu>
</template>
