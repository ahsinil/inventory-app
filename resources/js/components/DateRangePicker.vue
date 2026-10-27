<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { Calendar, ChevronDown } from 'lucide-vue-next'

const props = defineProps<{
  from: string
  to: string
}>()

const emit = defineEmits<{
  (e: 'update:from', value: string): void
  (e: 'update:to', value: string): void
  (e: 'change'): void
}>()

const isOpen = ref(false)
const popover = ref<HTMLElement | null>(null)
const button = ref<HTMLElement | null>(null)
const popoverStyle = ref<{ top: string; left: string; width?: string }>({ top: '0px', left: '0px' })

const localFrom = ref(props.from)
const localTo = ref(props.to)

watch(() => props.from, (val) => localFrom.value = val)
watch(() => props.to, (val) => localTo.value = val)

const activePreset = ref<string>('')

const updatePosition = () => {
  if (!button.value) return
  const rect = button.value.getBoundingClientRect()
  const viewportWidth = window.innerWidth
  const margin = 10 // safe distance from viewport edge

  // Max width that comfortably fits on screen
  const targetWidth = Math.min(340, viewportWidth - (margin * 2))
  const scrollX = window.scrollX || window.pageXOffset || 0
  const scrollY = window.scrollY || window.pageYOffset || 0

  let left = rect.left

  // If aligning left with button causes right edge overflow, align with button right
  if (left + targetWidth > viewportWidth - margin) {
    left = rect.right - targetWidth
  }

  // Clamping to guarantee it never overflows left or right edge of viewport
  if (left < margin) {
    left = margin
  }
  if (left + targetWidth > viewportWidth - margin) {
    left = Math.max(margin, viewportWidth - margin - targetWidth)
  }

  // Check if opening below overflows viewport bottom
  const estimatedHeight = 310
  let top = rect.bottom + 6 + scrollY
  if (rect.bottom + 6 + estimatedHeight > window.innerHeight && rect.top > estimatedHeight + 10) {
    top = rect.top - estimatedHeight - 6 + scrollY
  }

  popoverStyle.value = {
    top: `${top}px`,
    left: `${left + scrollX}px`,
    width: `${targetWidth}px`
  }
}

const toggle = () => {
  isOpen.value = !isOpen.value
  if (isOpen.value) {
    updatePosition()
    nextTick(() => updatePosition())
  }
}

const close = (e: MouseEvent) => {
  if (isOpen.value && popover.value && button.value) {
    if (!popover.value.contains(e.target as Node) && !button.value.contains(e.target as Node)) {
      isOpen.value = false
    }
  }
}

const apply = () => {
  emit('update:from', localFrom.value)
  emit('update:to', localTo.value)
  emit('change')
  isOpen.value = false
}

const setPreset = (preset: string) => {
  activePreset.value = preset
  const today = new Date()
  let f = new Date()
  let t = new Date()

  if (preset === 'today') {
    // f and t are already today
  } else if (preset === 'yesterday') {
    f.setDate(today.getDate() - 1)
    t.setDate(today.getDate() - 1)
  } else if (preset === 'this_month') {
    f = new Date(today.getFullYear(), today.getMonth(), 1)
  } else if (preset === 'past_month') {
    f = new Date(today.getFullYear(), today.getMonth() - 1, 1)
    t = new Date(today.getFullYear(), today.getMonth(), 0)
  } else if (preset === 'past_3_months') {
    f = new Date(today.getFullYear(), today.getMonth() - 3, 1)
  }

  // Format to YYYY-MM-DD accounting for local timezone correctly
  const fmt = (d: Date) => {
    const y = d.getFullYear()
    const m = String(d.getMonth() + 1).padStart(2, '0')
    const day = String(d.getDate()).padStart(2, '0')
    return `${y}-${m}-${day}`
  }

  localFrom.value = fmt(f)
  localTo.value = fmt(t)
  
  apply()
}

const onInputCustom = () => {
  activePreset.value = ''
  apply()
}

const reset = () => {
  activePreset.value = ''
  localFrom.value = ''
  localTo.value = ''
  apply()
}

const formatDisplay = (d: string) => {
  if (!d) return ''
  const parts = d.split('-')
  if (parts.length === 3) {
    return `${parts[2]}.${parts[1]}.${parts[0]}`
  }
  return d
}

const displayText = computed(() => {
  if (props.from && props.to) {
    if (props.from === props.to) {
       return formatDisplay(props.from)
    }
    return `${formatDisplay(props.from)} - ${formatDisplay(props.to)}`
  }
  if (props.from) return `Sejak ${formatDisplay(props.from)}`
  if (props.to) return `Hingga ${formatDisplay(props.to)}`
  return 'Filter Tanggal'
})

const handleWindowChange = () => {
  if (isOpen.value) {
    updatePosition()
  }
}

onMounted(() => {
  document.addEventListener('click', close)
  window.addEventListener('resize', handleWindowChange)
  window.addEventListener('scroll', handleWindowChange, true)
})

onUnmounted(() => {
  document.removeEventListener('click', close)
  window.removeEventListener('resize', handleWindowChange)
  window.removeEventListener('scroll', handleWindowChange, true)
})
</script>

<template>
  <div class="drp-wrapper">
    <button ref="button" type="button" class="drp-button" :class="{ active: isOpen, 'has-val': props.from || props.to }" @click="toggle">
      <Calendar :size="16" class="icon-cal" />
      <span class="drp-text">{{ displayText }}</span>
      <ChevronDown :size="16" class="icon-chev" />
    </button>

    <Teleport to="body">
      <Transition name="drp-fade">
        <div v-if="isOpen" ref="popover" class="drp-popover" :style="popoverStyle">
          <div class="drp-inputs">
            <div class="drp-field">
              <label>Dari</label>
              <div class="drp-input-wrap">
                <Calendar :size="14" class="input-icon" />
                <input type="date" v-model="localFrom" @change="onInputCustom" />
              </div>
            </div>
            <div class="drp-dash"></div>
            <div class="drp-field">
              <label>Sampai</label>
              <div class="drp-input-wrap">
                <Calendar :size="14" class="input-icon" />
                <input type="date" v-model="localTo" @change="onInputCustom" />
              </div>
            </div>
          </div>

          <div class="drp-presets">
            <button type="button" class="preset-btn" :class="{ active: activePreset === 'today' }" @click="setPreset('today')">Hari Ini</button>
            <button type="button" class="preset-btn" :class="{ active: activePreset === 'yesterday' }" @click="setPreset('yesterday')">Kemarin</button>
            <button type="button" class="preset-btn" :class="{ active: activePreset === 'this_month' }" @click="setPreset('this_month')">Bulan Ini</button>
            <button type="button" class="preset-btn" :class="{ active: activePreset === 'past_month' }" @click="setPreset('past_month')">Bulan Lalu</button>
            <button type="button" class="preset-btn" :class="{ active: activePreset === 'past_3_months' }" @click="setPreset('past_3_months')">3 Bulan Terakhir</button>
            
            <button type="button" class="preset-btn reset-btn" @click="reset">Reset Filter</button>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.drp-wrapper {
  position: relative;
  display: inline-block;
  font-family: inherit;
}

.drp-button {
  display: flex;
  align-items: center;
  gap: 7px;
  background: #fff;
  border: 1px solid #e9e7ef;
  border-radius: 6px;
  padding: 0 9px;
  height: 34px;
  font-size: 10px;
  color: #777486;
  cursor: pointer;
  transition: all 0.2s;
  outline: none;
  font-family: inherit;
  box-sizing: border-box;
  min-width: 0;
  max-width: 100%;
}
.drp-button.has-val, .drp-button.active {
  color: #bd3c3c;
  background: #fff2f2;
  border-color: #fadcd9;
}
.drp-button:hover {
  background: #fafafd;
}
.drp-button .icon-cal {
  flex-shrink: 0;
}
.drp-button .icon-chev {
  margin-left: 2px;
  flex-shrink: 0;
}
.drp-text {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}

.drp-popover {
  position: absolute;
  background: #fff;
  border: 1px solid #f0eff3;
  border-radius: 8px;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
  padding: 14px 16px;
  width: 340px;
  max-width: calc(100vw - 20px);
  box-sizing: border-box;
  container-type: inline-size;
  z-index: 99999;
}

.drp-inputs {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  margin-bottom: 14px;
}

.drp-field {
  flex: 1;
  min-width: 0;
}
.drp-field label {
  display: block;
  font-size: 10px;
  color: #858291;
  margin-bottom: 6px;
  font-weight: 600;
}
.drp-input-wrap {
  position: relative;
  display: flex;
  align-items: center;
  width: 100%;
}
.drp-input-wrap .input-icon {
  position: absolute;
  left: 8px;
  color: #aaa7b5;
  pointer-events: none;
}
.drp-input-wrap input {
  width: 100%;
  border: 1px solid #e9e7ef;
  border-radius: 6px;
  padding: 7px 8px 7px 24px;
  font-size: 11px;
  color: #48445c;
  outline: none;
  background: #fafafd;
  box-sizing: border-box;
  transition: all 0.2s;
  min-width: 0;
  font-family: inherit;
}
.drp-input-wrap input:focus {
  border-color: #bd3c3c;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(189, 60, 60, 0.1);
}

.drp-dash {
  width: 10px;
  height: 1px;
  background: #d0ced8;
  margin-bottom: 15px;
  flex-shrink: 0;
}

@container (max-width: 280px) {
  .drp-inputs {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
  }
  .drp-dash {
    display: none;
  }
}

.drp-presets {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.preset-btn {
  background: #fff;
  border: 1px solid #e9e7ef;
  border-radius: 6px;
  padding: 8px 10px;
  text-align: center;
  font-size: 10px;
  color: #777486;
  cursor: pointer;
  transition: all 0.2s;
  font-weight: 500;
}
.preset-btn:hover {
  background: #fff2f2;
  border-color: #fadcd9;
  color: #bd3c3c;
}
.preset-btn.active {
  background: #bd3c3c;
  border-color: #bd3c3c;
  color: #fff;
}

.reset-btn {
  margin-top: 4px;
  border-color: #fadcd9;
  color: #d47770;
}
.reset-btn:hover {
  background: #fff2f2;
  color: #bd3c3c;
  border-color: #bd3c3c;
}

.drp-fade-enter-active,
.drp-fade-leave-active {
  transition: opacity 0.2s, transform 0.2s;
}
.drp-fade-enter-from,
.drp-fade-leave-to {
  opacity: 0;
  transform: translateY(-5px);
}
</style>
