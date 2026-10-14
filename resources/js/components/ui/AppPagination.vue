<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    page: number
    lastPage: number
    total?: number
    from?: number
    to?: number
    siblingCount?: number
  }>(),
  {
    total: 0,
    from: 0,
    to: 0,
    siblingCount: 1,
  }
)

const emit = defineEmits<{
  (e: 'change', page: number): void
  (e: 'update:page', page: number): void
}>()

const visiblePages = computed<(number | string)[]>(() => {
  const current = props.page
  const total = props.lastPage
  const sibling = props.siblingCount

  if (total <= 1) return [1]

  // If total pages is small (e.g. <= 7), show all pages
  const totalNumbers = sibling * 2 + 5
  if (total <= totalNumbers) {
    return Array.from({ length: total }, (_, i) => i + 1)
  }

  const leftSibling = Math.max(current - sibling, 1)
  const rightSibling = Math.min(current + sibling, total)

  const showLeftEllipsis = leftSibling > 2
  const showRightEllipsis = rightSibling < total - 1

  if (!showLeftEllipsis && showRightEllipsis) {
    const leftItemCount = 3 + 2 * sibling
    const leftRange = Array.from({ length: leftItemCount }, (_, i) => i + 1)
    return [...leftRange, '...', total]
  }

  if (showLeftEllipsis && !showRightEllipsis) {
    const rightItemCount = 3 + 2 * sibling
    const rightRange = Array.from({ length: rightItemCount }, (_, i) => total - rightItemCount + i + 1)
    return [1, '...', ...rightRange]
  }

  // Show both ellipses
  const middleRange = Array.from({ length: rightSibling - leftSibling + 1 }, (_, i) => leftSibling + i)
  return [1, '...', ...middleRange, '...', total]
})

const goTo = (p: number | string) => {
  if (typeof p !== 'number') return
  if (p < 1 || p > props.lastPage || p === props.page) return
  emit('update:page', p)
  emit('change', p)
}
</script>

<template>
  <div class="app-pagination-container">
    <div class="pagination-info">
      <slot name="info">
        <span v-if="total > 0">
          Menampilkan {{ from }}–{{ to }} dari {{ total }} data
        </span>
        <span v-else>
          Menampilkan 0 data
        </span>
      </slot>
    </div>

    <div class="pagination-controls">
      <button
        type="button"
        class="pagination-nav-button"
        :disabled="page <= 1"
        title="Halaman Sebelumnya"
        @click="goTo(page - 1)"
      >
        Sebelumnya
      </button>

      <div class="pagination-pages">
        <template v-for="(p, idx) in visiblePages" :key="idx">
          <span v-if="p === '...'" class="pagination-ellipsis">
            &hellip;
          </span>
          <button
            v-else
            type="button"
            class="pagination-num-button"
            :class="{ active: p === page }"
            :title="`Halaman ${p}`"
            @click="goTo(p)"
          >
            {{ p }}
          </button>
        </template>
      </div>

      <button
        type="button"
        class="pagination-nav-button"
        :disabled="page >= lastPage"
        title="Halaman Berikutnya"
        @click="goTo(page + 1)"
      >
        Berikutnya
      </button>
    </div>
  </div>
</template>

<style scoped>
.app-pagination-container {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 19px;
  color: #858291;
  font-size: 11px;
  border-top: 1px solid #f0eff3;
  flex-wrap: wrap;
  gap: 12px;
}

.pagination-info {
  color: #aaa7b4;
  font-size: 10px;
  font-weight: 500;
}

.pagination-controls {
  display: flex;
  align-items: center;
  gap: 6px;
}

.pagination-pages {
  display: flex;
  align-items: center;
  gap: 4px;
}

.pagination-nav-button {
  border: 1px solid #e9e7ef;
  background: #ffffff;
  color: #555164;
  border-radius: 6px;
  padding: 5px 10px;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s ease;
  user-select: none;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
}

.pagination-nav-button:hover:not(:disabled) {
  border-color: #d8d5e5;
  background: #fafafd;
  color: #292541;
}

.pagination-nav-button:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  border-color: #f0eff3;
  background: #fafafd;
}

.pagination-num-button {
  border: 1px solid #e9e7ef;
  background: #ffffff;
  color: #555164;
  border-radius: 6px;
  min-width: 28px;
  height: 28px;
  padding: 0 6px;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
  user-select: none;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
}

.pagination-num-button:hover:not(.active) {
  border-color: #bd3c3c;
  color: #bd3c3c;
  background: #fffafa;
}

.pagination-num-button.active {
  border-color: #bd3c3c;
  background: #bd3c3c;
  color: #ffffff;
  font-weight: 700;
  box-shadow: 0 2px 4px rgba(189, 60, 60, 0.25);
}

.pagination-ellipsis {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 24px;
  height: 28px;
  color: #aaa7b4;
  font-size: 13px;
  font-weight: 600;
  user-select: none;
}
</style>
