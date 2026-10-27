<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { Search, X, ChevronDown, Check, PackageOpen } from 'lucide-vue-next'

export interface ProductItem {
  id: string
  name: string
  sku?: string
  has_serial_number?: boolean
  unit?: { code?: string; name?: string }
  available_qty?: number
  stock?: number
}

const props = withDefaults(
  defineProps<{
    modelValue?: string
    products: ProductItem[]
    placeholder?: string
    disabled?: boolean
    error?: boolean
  }>(),
  {
    modelValue: '',
    placeholder: 'Ketik nama barang atau SKU...',
    disabled: false,
    error: false,
  }
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  (e: 'select', product: ProductItem | null): void
}>()

const inputRef = ref<HTMLInputElement | null>(null)
const dropdownRef = ref<HTMLElement | null>(null)
const listRef = ref<HTMLElement | null>(null)

const isOpen = ref(false)
const searchQuery = ref('')
const selectedIndex = ref(-1)
const dropdownStyle = ref<Record<string, string>>({})

const selectedProduct = computed(() => {
  if (!props.modelValue) return null
  return props.products.find((p) => p.id === props.modelValue) || null
})

// Sync search query when modelValue changes externally
watch(
  () => props.modelValue,
  (val) => {
    if (val) {
      const match = props.products.find((p) => p.id === val)
      if (match) {
        searchQuery.value = match.name
      }
    } else {
      searchQuery.value = ''
    }
  },
  { immediate: true }
)

// In case products load asynchronously after modelValue is already set
watch(
  () => props.products,
  (list) => {
    if (props.modelValue && (!searchQuery.value || searchQuery.value === '')) {
      const match = list.find((p) => p.id === props.modelValue)
      if (match) {
        searchQuery.value = match.name
      }
    }
  }
)

const filteredProducts = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  const isCurrentSelection = selectedProduct.value && selectedProduct.value.name.toLowerCase() === q
  if (!q || isCurrentSelection) {
    return props.products.slice(0, 50)
  }
  return props.products
    .filter((p) => {
      const name = p.name ? p.name.toLowerCase() : ''
      const sku = p.sku ? p.sku.toLowerCase() : ''
      return name.includes(q) || sku.includes(q)
    })
    .slice(0, 50)
})

const updatePosition = () => {
  if (!inputRef.value || !isOpen.value) return
  const rect = inputRef.value.getBoundingClientRect()
  const spaceBelow = window.innerHeight - rect.bottom
  const dropdownHeight = 250

  const screenW = window.innerWidth
  const padding = 12
  const targetWidth = Math.max(rect.width, 280)
  const finalWidth = Math.min(targetWidth, screenW - padding * 2)
  let leftPos = rect.left
  if (leftPos + finalWidth > screenW - padding) {
    leftPos = Math.max(padding, screenW - padding - finalWidth)
  }

  if (spaceBelow < dropdownHeight && rect.top > dropdownHeight) {
    dropdownStyle.value = {
      position: 'fixed',
      bottom: `${window.innerHeight - rect.top + 4}px`,
      left: `${leftPos}px`,
      width: `${finalWidth}px`,
      zIndex: '10005',
    }
  } else {
    dropdownStyle.value = {
      position: 'fixed',
      top: `${rect.bottom + 4}px`,
      left: `${leftPos}px`,
      width: `${finalWidth}px`,
      zIndex: '10005',
    }
  }
}

const openDropdown = () => {
  if (props.disabled) return
  isOpen.value = true
  selectedIndex.value = -1
  nextTick(() => {
    updatePosition()
    if (inputRef.value) {
      inputRef.value.select()
    }
  })
}

const closeDropdown = () => {
  isOpen.value = false
  selectedIndex.value = -1
  if (selectedProduct.value) {
    searchQuery.value = selectedProduct.value.name
  } else if (!props.modelValue) {
    searchQuery.value = ''
  }
}

const selectItem = (product: ProductItem) => {
  emit('update:modelValue', product.id)
  emit('select', product)
  searchQuery.value = product.name
  isOpen.value = false
  selectedIndex.value = -1
}

const clearSelection = (e?: MouseEvent) => {
  if (e) e.stopPropagation()
  emit('update:modelValue', '')
  emit('select', null)
  searchQuery.value = ''
  selectedIndex.value = -1
  isOpen.value = true
  nextTick(() => {
    inputRef.value?.focus()
    updatePosition()
  })
}

const onInput = () => {
  if (!isOpen.value) {
    isOpen.value = true
  }
  selectedIndex.value = 0
  nextTick(updatePosition)
}

const onKeyDown = (e: KeyboardEvent) => {
  if (props.disabled) return

  if (e.key === 'ArrowDown') {
    e.preventDefault()
    if (!isOpen.value) {
      openDropdown()
      return
    }
    if (filteredProducts.value.length > 0) {
      selectedIndex.value = (selectedIndex.value + 1) % filteredProducts.value.length
      scrollToIndex(selectedIndex.value)
    }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    if (!isOpen.value) {
      openDropdown()
      return
    }
    if (filteredProducts.value.length > 0) {
      selectedIndex.value = (selectedIndex.value - 1 + filteredProducts.value.length) % filteredProducts.value.length
      scrollToIndex(selectedIndex.value)
    }
  } else if (e.key === 'Enter') {
    if (isOpen.value && selectedIndex.value >= 0 && filteredProducts.value[selectedIndex.value]) {
      e.preventDefault()
      selectItem(filteredProducts.value[selectedIndex.value])
    }
  } else if (e.key === 'Escape') {
    if (isOpen.value) {
      e.preventDefault()
      closeDropdown()
    }
  }
}

const scrollToIndex = (index: number) => {
  nextTick(() => {
    if (!listRef.value) return
    const el = listRef.value.children[index] as HTMLElement | undefined
    if (el) {
      el.scrollIntoView({ block: 'nearest' })
    }
  })
}

const handleGlobalScroll = (e: Event) => {
  if (!isOpen.value) return
  if (dropdownRef.value && dropdownRef.value.contains(e.target as Node)) {
    return
  }
  updatePosition()
}

const handleClickOutside = (e: MouseEvent | PointerEvent) => {
  if (!isOpen.value) return
  const target = e.target as Node
  if (
    inputRef.value && !inputRef.value.contains(target) &&
    dropdownRef.value && !dropdownRef.value.contains(target)
  ) {
    closeDropdown()
  }
}

onMounted(() => {
  window.addEventListener('scroll', handleGlobalScroll, true)
  window.addEventListener('resize', updatePosition)
  document.addEventListener('pointerdown', handleClickOutside)
})

onUnmounted(() => {
  window.removeEventListener('scroll', handleGlobalScroll, true)
  window.removeEventListener('resize', updatePosition)
  document.removeEventListener('pointerdown', handleClickOutside)
})
</script>

<template>
  <div class="product-combobox-wrap">
    <div
      class="combobox-control"
      :class="{
        'is-open': isOpen,
        'has-value': !!modelValue,
        'is-error': error,
        'is-disabled': disabled
      }"
      :title="selectedProduct ? `${selectedProduct.name}${selectedProduct.sku ? ` (${selectedProduct.sku})` : ''}` : ''"
      @click="openDropdown"
    >
      <Search :size="14" class="search-icon" />

      <input
        ref="inputRef"
        type="text"
        class="combobox-input"
        :value="searchQuery"
        :placeholder="placeholder"
        :disabled="disabled"
        autocomplete="off"
        spellcheck="false"
        @focus="openDropdown"
        @input="(e) => { searchQuery = (e.target as HTMLInputElement).value; onInput() }"
        @keydown="onKeyDown"
      />

      <div class="combobox-actions" @click.stop>
        <span
          v-if="selectedProduct?.sku && !isOpen"
          class="sku-tag"
          :title="`SKU: ${selectedProduct.sku}`"
        >
          {{ selectedProduct.sku }}
        </span>

        <button
          v-if="modelValue || searchQuery"
          type="button"
          class="clear-btn"
          title="Hapus pilihan"
          @click="clearSelection"
        >
          <X :size="13" />
        </button>

        <button
          type="button"
          class="chevron-btn"
          :class="{ rotated: isOpen }"
          @click="isOpen ? closeDropdown() : openDropdown()"
        >
          <ChevronDown :size="14" />
        </button>
      </div>
    </div>

    <!-- Dropdown List Teleported to Body -->
    <Teleport to="body">
      <Transition name="combo-fade">
        <div
          v-if="isOpen"
          ref="dropdownRef"
          class="combobox-dropdown"
          :style="dropdownStyle"
          @click.stop
        >
          <div class="dropdown-header">
            <span class="dropdown-header-title">
              {{ filteredProducts.length }} Produk Tersedia
            </span>
            <span v-if="searchQuery && !selectedProduct" class="search-hint">
              Kata kunci: "{{ searchQuery }}"
            </span>
          </div>

          <div ref="listRef" class="dropdown-list">
            <div
              v-for="(prod, idx) in filteredProducts"
              :key="prod.id"
              class="dropdown-item"
              :class="{
                'is-active': idx === selectedIndex,
                'is-selected': prod.id === modelValue
              }"
              @mouseenter="selectedIndex = idx"
              @click="selectItem(prod)"
            >
              <div class="item-main">
                <div class="item-name-row">
                  <span class="item-name">{{ prod.name }}</span>
                  <span v-if="prod.has_serial_number" class="sn-badge" title="Produk Membutuhkan Serial Number">
                    SN
                  </span>
                </div>
                <div class="item-sub-row">
                  <span v-if="prod.sku" class="item-sku">SKU: {{ prod.sku }}</span>
                  <span v-if="prod.available_qty !== undefined" class="item-stock">
                    Stok: {{ prod.available_qty }}
                  </span>
                </div>
              </div>

              <div class="item-meta">
                <span class="unit-badge">
                  {{ prod.unit?.code || prod.unit?.name || 'pcs' }}
                </span>
                <Check v-if="prod.id === modelValue" :size="14" class="check-icon" />
              </div>
            </div>

            <div v-if="filteredProducts.length === 0" class="dropdown-empty">
              <PackageOpen :size="24" class="empty-icon" />
              <p class="empty-title">Produk tidak ditemukan</p>
              <p class="empty-desc">Tidak ada produk dengan nama atau SKU "{{ searchQuery }}".</p>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.product-combobox-wrap {
  position: relative;
  width: 100%;
  min-width: 0;
  container-type: inline-size;
}

.combobox-control {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  min-width: 0;
  background: #fafafd;
  border: 1px solid #e9e7ef;
  border-radius: 8px;
  padding: 6px 10px;
  min-height: 38px;
  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  box-sizing: border-box;
  cursor: text;
}

.combobox-control:hover {
  border-color: #d8d5e5;
  background: #ffffff;
}

.combobox-control.is-open,
.combobox-control:focus-within {
  border-color: #bd3c3c;
  background: #ffffff;
  box-shadow: 0 0 0 3px rgba(189, 60, 60, 0.1);
}

.combobox-control.is-error {
  border-color: #d47770;
  background: #fffafb;
}

.combobox-control.is-error:focus-within {
  box-shadow: 0 0 0 3px rgba(212, 119, 112, 0.15);
}

.combobox-control.is-disabled {
  opacity: 0.6;
  cursor: not-allowed;
  background: #f5f4f8;
}

.search-icon {
  color: #aaa7b4;
  flex-shrink: 0;
}

.combobox-input {
  flex: 1;
  min-width: 0;
  border: none !important;
  outline: none !important;
  background: transparent !important;
  padding: 0 !important;
  font-size: 11px !important;
  color: #292541 !important;
  font-weight: 500 !important;
  box-shadow: none !important;
  text-overflow: ellipsis;
  overflow: hidden;
  white-space: nowrap;
}

.combobox-input::placeholder {
  color: #aaa7b4;
  font-weight: 400;
}

.combobox-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  min-width: 0;
}

.sku-tag {
  font-size: 9px;
  font-weight: 600;
  color: #555164;
  background: #eeedf3;
  padding: 2px 6px;
  border-radius: 4px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  letter-spacing: 0.02em;
  max-width: 90px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex-shrink: 1;
}

@container (max-width: 320px) {
  .sku-tag {
    max-width: 60px;
  }
}

@container (max-width: 250px) {
  .sku-tag {
    display: none;
  }
}

@media (max-width: 640px) {
  .sku-tag {
    max-width: 70px;
  }
}

@media (max-width: 380px) {
  .sku-tag {
    display: none;
  }
}

.clear-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: none;
  background: #e9e7ef;
  color: #777486;
  cursor: pointer;
  transition: all 0.15s;
  padding: 0;
}

.clear-btn:hover {
  background: #d47770;
  color: #ffffff;
}

.chevron-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  color: #aaa7b4;
  cursor: pointer;
  padding: 2px;
  transition: transform 0.2s;
}

.chevron-btn.rotated {
  transform: rotate(180deg);
  color: #bd3c3c;
}

.combobox-dropdown {
  background: #ffffff;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  box-shadow: 0 12px 30px rgba(41, 37, 65, 0.12), 0 4px 12px rgba(0, 0, 0, 0.06);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.dropdown-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
  background: #fafafd;
  border-bottom: 1px solid #f0eff3;
  font-size: 10px;
}

.dropdown-header-title {
  font-weight: 600;
  color: #858291;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.search-hint {
  color: #bd3c3c;
  font-weight: 500;
}

.dropdown-list {
  max-height: 220px;
  overflow-y: auto;
  padding: 4px;
}

.dropdown-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 10px;
  border-radius: 7px;
  cursor: pointer;
  transition: background 0.12s;
  gap: 12px;
}

.dropdown-item:hover,
.dropdown-item.is-active {
  background: #f6f5fa;
}

.dropdown-item.is-selected {
  background: #fdf5f5;
}

.dropdown-item.is-selected .item-name {
  color: #bd3c3c;
}

.item-main {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}

.item-name-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.item-name {
  font-size: 11px;
  font-weight: 600;
  color: #292541;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sn-badge {
  font-size: 9px;
  font-weight: 700;
  color: #c48735;
  background: #fff4df;
  border: 1px solid rgba(196, 135, 53, 0.2);
  padding: 1px 4px;
  border-radius: 3px;
}

.item-sub-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.item-sku {
  font-size: 10px;
  color: #858291;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.item-stock {
  font-size: 9px;
  font-weight: 700;
  color: #3b9d76;
  background: #eaf8f1;
  padding: 1px 5px;
  border-radius: 4px;
}

.item-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.unit-badge {
  font-size: 9px;
  font-weight: 600;
  color: #555164;
  background: #f0eff3;
  padding: 2px 6px;
  border-radius: 4px;
  text-transform: uppercase;
}

.check-icon {
  color: #bd3c3c;
}

.dropdown-empty {
  padding: 24px 16px;
  text-align: center;
  color: #aaa7b4;
}

.empty-icon {
  margin: 0 auto 8px;
  color: #c4c1cd;
}

.empty-title {
  font-size: 11px;
  font-weight: 600;
  color: #555164;
  margin: 0 0 2px;
}

.empty-desc {
  font-size: 10px;
  color: #aaa7b4;
  margin: 0;
}

.combo-fade-enter-active,
.combo-fade-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}

.combo-fade-enter-from,
.combo-fade-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(0.98);
}
</style>
