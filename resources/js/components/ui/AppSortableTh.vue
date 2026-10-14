<template>
  <th
    :class="['app-sortable-th', { 'is-active': isActive, 'is-sortable': sortable }]"
    :style="{ textAlign: align }"
    @click="handleClick"
  >
    <div class="th-inner" :style="{ justifyContent: align === 'right' ? 'flex-end' : align === 'center' ? 'center' : 'flex-start' }">
      <span class="th-label">
        <slot>{{ label }}</slot>
      </span>
      <span v-if="sortable" class="sort-icon-indicator" :class="{ 'is-active': isActive }">
        <ArrowUp v-if="isActive && currentDir === 'asc'" :size="12" class="sort-icon icon-active" />
        <ArrowDown v-else-if="isActive && currentDir === 'desc'" :size="12" class="sort-icon icon-active" />
        <ArrowUpDown v-else :size="12" class="sort-icon icon-neutral" />
      </span>
    </div>
  </th>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { ArrowUp, ArrowDown, ArrowUpDown } from 'lucide-vue-next'

const props = withDefaults(defineProps<{
  field: string
  currentSort?: string
  currentDir?: 'asc' | 'desc' | string
  align?: 'left' | 'center' | 'right'
  sortable?: boolean
  label?: string
}>(), {
  currentSort: '',
  currentDir: 'asc',
  align: 'left',
  sortable: true,
  label: ''
})

const emit = defineEmits<{
  (e: 'sort', field: string, dir: 'asc' | 'desc'): void
}>()

const isActive = computed(() => props.sortable && props.currentSort === props.field)

const handleClick = () => {
  if (!props.sortable) return
  if (props.currentSort === props.field) {
    emit('sort', props.field, props.currentDir === 'asc' ? 'desc' : 'asc')
  } else {
    emit('sort', props.field, 'asc')
  }
}
</script>

<style scoped>
.app-sortable-th {
  padding: 10px 19px;
  color: #aaa7b4;
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  background: #fafafd;
  border-top: 1px solid #f0eff3;
  border-bottom: 1px solid #f0eff3;
  transition: background-color 0.15s ease, color 0.15s ease;
  white-space: nowrap;
}

.app-sortable-th.is-sortable {
  cursor: pointer;
  user-select: none;
}

.app-sortable-th.is-sortable:hover {
  background: #f4f3f9;
  color: #292541;
}

.app-sortable-th.is-active {
  color: #bd3c3c;
  background: #faf4f4;
}

.th-inner {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  width: 100%;
}

.th-label {
  display: inline-block;
}

.sort-icon-indicator {
  display: inline-flex;
  align-items: center;
  opacity: 0.5;
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.app-sortable-th.is-sortable:hover .sort-icon-indicator {
  opacity: 0.9;
}

.sort-icon-indicator.is-active {
  opacity: 1;
  color: #bd3c3c;
}

.sort-icon.icon-active {
  color: #bd3c3c;
}

.sort-icon.icon-neutral {
  color: #b5b2c0;
}
</style>
