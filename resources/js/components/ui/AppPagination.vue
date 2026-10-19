<script setup lang="ts">
import { computed } from 'vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'

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

/**
 * Concise pagination numbers:
 * - When lastPage <= 5: show all pages [1..lastPage]
 * - When lastPage > 5:
 *   - Near beginning (page <= 3): [1, 2, 3, '...', lastPage]
 *   - Near end (page >= lastPage - 2): [1, '...', lastPage - 2, lastPage - 1, lastPage]
 *   - In middle: [1, '...', page, '...', lastPage]
 * Always <= 5 items so it never overflows or stretches too wide.
 */
const visiblePages = computed<(number | string)[]>(() => {
  const current = props.page
  const total = props.lastPage

  if (total <= 1) return [1]

  if (total <= 5) {
    return Array.from({ length: total }, (_, i) => i + 1)
  }

  // Near start
  if (current <= 3) {
    return [1, 2, 3, '...', total]
  }

  // Near end
  if (current >= total - 2) {
    return [1, '...', total - 2, total - 1, total]
  }

  // In the middle
  return [1, '...', current, '...', total]
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
        class="pagination-nav-button prev-btn"
        :disabled="page <= 1"
        title="Halaman Sebelumnya"
        @click="goTo(page - 1)"
      >
        <ChevronLeft :size="14" class="nav-icon" />
        <span class="nav-text">Sebelumnya</span>
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
        class="pagination-nav-button next-btn"
        :disabled="page >= lastPage"
        title="Halaman Berikutnya"
        @click="goTo(page + 1)"
      >
        <span class="nav-text">Berikutnya</span>
        <ChevronRight :size="14" class="nav-icon" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.app-pagination-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 14px 18px;
  color: #858291;
  font-size: 11px;
  border-top: 1px solid #f0eff3;
  gap: 10px;
  width: 100%;
  box-sizing: border-box;
  text-align: center;
}

.pagination-info {
  color: #aaa7b4;
  font-size: 11px;
  font-weight: 500;
  text-align: center;
}

.pagination-controls {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  flex-wrap: wrap;
}

.pagination-pages {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
}

.pagination-nav-button {
  display: inline-flex;
  align-items: center;
  gap: 4px;
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
  white-space: nowrap;
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
  min-width: 20px;
  height: 28px;
  color: #aaa7b4;
  font-size: 12px;
  font-weight: 600;
  user-select: none;
}

@media (min-width: 768px) {
  .app-pagination-container {
    position: relative;
    flex-direction: row;
    justify-content: center;
    padding: 14px 20px;
  }

  .pagination-info {
    position: absolute;
    left: 20px;
    text-align: left;
  }

  .pagination-controls {
    margin: 0 auto;
  }
}

@media (max-width: 380px) {
  .pagination-nav-button {
    padding: 5px 6px;
    font-size: 10px;
  }

  .pagination-num-button {
    min-width: 26px;
    height: 26px;
    font-size: 10.5px;
  }
}
</style>
