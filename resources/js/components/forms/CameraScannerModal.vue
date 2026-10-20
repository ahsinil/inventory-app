<script setup lang="ts">
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import {
  Html5Qrcode,
  Html5QrcodeSupportedFormats,
  type CameraDevice,
} from 'html5-qrcode'
import {
  X,
  ScanLine,
  Zap,
  RefreshCw,
  Volume2,
  VolumeX,
  Plus,
  Trash2,
  Check,
  Image as ImageIcon,
  AlertCircle,
  CheckCircle2,
  Target,
  ZoomIn,
  RotateCcw,
  SlidersHorizontal,
  Sparkles,
} from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    mode?: 'single' | 'multi'
    title?: string
    subtitle?: string
    initialValues?: string[]
    targetQuantity?: number
  }>(),
  {
    mode: 'single',
    title: 'Scan Nomor Seri',
    subtitle: 'Arahkan kamera ke barcode nomor seri perangkat.',
    initialValues: () => [],
    targetQuantity: undefined,
  }
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'scan', serialNumber: string): void
  (e: 'complete', serialNumbers: string[]): void
  (e: 'close'): void
}>()

// Unique ID for scanner region element to avoid DOM collision
const regionId = 'sn-reader-' + Math.random().toString(36).substring(2, 9)

// Scanner state
let scanner: Html5Qrcode | null = null
const isScanning = ref(false)
const isStarting = ref(false)
const scannerError = ref('')
const lastScannedText = ref('')
const showSuccessFlash = ref(false)
const duplicateNotice = ref('')
const ignoredMacNotice = ref('')

// Solusi Barcode Bertumpuk:
// Default: Mode Area Luas (karena akurasi pembacaan 100% utuh tanpa memotong garis barcode)
const isFocusedMode = ref(false) // false = Area Luas (default optimal), true = Fokus Sedang (140px)
const ignoreMacAddress = ref(true) // Otomatis abaikan barcode MAC address
const autoCleanPrefix = ref(true) // Otomatis bersihkan prefix "GPON SN:", "S/N:", dll
const showAdvancedSettings = ref(false)

// Zoom capabilities
const hasZoom = ref(false)
const zoomMin = ref(1)
const zoomMax = ref(1)
const currentZoom = ref(1)

interface DetectedCandidate {
  raw: string
  cleaned: string
  label: string
  isSerial: boolean
  isMac: boolean
}

// Live candidates detected in Area Luas
const detectedCandidates = ref<DetectedCandidate[]>([])

// Single mode pending confirmation
const pendingSingleScan = ref<{ raw: string; cleaned: string; label: string } | null>(null)
let autoConfirmTimer: any = null
const autoConfirmCountdown = ref(100)

// Device capabilities
const cameraList = ref<CameraDevice[]>([])
const selectedCameraId = ref('')
const hasTorch = ref(false)
const isTorchOn = ref(false)
const isMuted = ref(false)

// Scanned items in multi mode
const scannedItems = ref<string[]>([])
const manualInput = ref('')

// Initialize scanned items when modal opens
watch(
  () => props.modelValue,
  async (isOpen) => {
    if (isOpen) {
      scannedItems.value = [...(props.initialValues || [])]
      detectedCandidates.value = []
      scannerError.value = ''
      lastScannedText.value = ''
      duplicateNotice.value = ''
      ignoredMacNotice.value = ''
      pendingSingleScan.value = null
      clearAutoConfirmTimer()
      await nextTick()
      startScanner()
    } else {
      stopScanner()
    }
  },
  { immediate: true }
)

// Supported 1D & 2D barcode formats for hardware serial numbers
const supportedFormats = [
  Html5QrcodeSupportedFormats.CODE_128,
  Html5QrcodeSupportedFormats.CODE_39,
  Html5QrcodeSupportedFormats.CODE_93,
  Html5QrcodeSupportedFormats.QR_CODE,
  Html5QrcodeSupportedFormats.DATA_MATRIX,
  Html5QrcodeSupportedFormats.EAN_13,
  Html5QrcodeSupportedFormats.EAN_8,
  Html5QrcodeSupportedFormats.UPC_A,
  Html5QrcodeSupportedFormats.UPC_E,
  Html5QrcodeSupportedFormats.CODABAR,
  Html5QrcodeSupportedFormats.ITF,
]

// Play sound feedback using Web Audio API
const playBeep = (freq: number = 880) => {
  if (isMuted.value) return
  try {
    const AudioCtx = window.AudioContext || (window as any).webkitAudioContext
    if (!AudioCtx) return
    const ctx = new AudioCtx()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.type = 'sine'
    osc.frequency.setValueAtTime(freq, ctx.currentTime)
    gain.gain.setValueAtTime(0.12, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.12)
    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.start()
    osc.stop(ctx.currentTime + 0.12)
  } catch (_) {
    // Ignore
  }
}

// Trigger haptic vibration on mobile devices
const vibrate = (pattern: number | number[] = 70) => {
  try {
    if (navigator?.vibrate) {
      navigator.vibrate(pattern)
    }
  } catch (_) {
    // Ignore
  }
}

// Deteksi apakah teks barcode adalah alamat MAC
const checkIsMac = (text: string): boolean => {
  const t = text.trim()
  if (/^mac[:\s_-]/i.test(t)) return true
  const macRegex = /^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/
  return macRegex.test(t)
}

// Bersihkan prefix umum (misal "GPON SN: FHTT9DAAFF90" -> "FHTT9DAAFF90")
const cleanPrefix = (text: string): string => {
  let t = text.trim()
  t = t.replace(/^(gpon[\s_-]?sn|s\/?n|serial[\s_-]?number|no[\s_.]?seri|sn)[:\s_-]+/i, '')
  return t.trim()
}

// Validasi barcode cacat/rusak (seperti '"HTN9D7)=90' akibat celah terlalu sempit)
const isCorruptedBarcode = (text: string): boolean => {
  if (!text || text.length < 3) return true
  // Karakter kontrol/artefak parser yang tidak mungkin ada pada nomor seri resmi
  if (/["`~=()[\]{}<>]/.test(text)) {
    return true
  }
  return false
}

// Klasifikasikan barcode yang terdeteksi
const classifyBarcode = (text: string): DetectedCandidate => {
  const raw = text.trim()
  const isMac = checkIsMac(raw)
  const isExplicitSerial = /^(gpon[\s_-]?sn|s\/?n|serial[\s_-]?number|no[\s_.]?seri|sn)[:\s_-]+/i.test(raw)
  const isGeneralSerial = !isMac && /^[A-Z0-9_-]{6,30}$/i.test(raw)
  const isSerial = isExplicitSerial || isGeneralSerial
  const cleaned = autoCleanPrefix.value ? cleanPrefix(raw) : raw

  let label = 'Barcode'
  if (isExplicitSerial) label = 'Nomor Seri (GPON SN)'
  else if (isSerial) label = 'Nomor Seri (S/N)'
  else if (isMac) label = 'Alamat MAC'
  else if (/^\d{6,}/.test(raw)) label = 'Part / Model No'

  return {
    raw,
    cleaned,
    label,
    isSerial,
    isMac,
  }
}

// Check torch capabilities
const updateTorchSupport = () => {
  if (!scanner) {
    hasTorch.value = false
    return
  }
  try {
    const capabilities = scanner.getRunningTrackCameraCapabilities?.()
    hasTorch.value = !!capabilities?.torchFeature?.()?.isSupported()
    isTorchOn.value = !!capabilities?.torchFeature?.()?.value()
  } catch (_) {
    hasTorch.value = false
  }
}

const toggleTorch = async () => {
  if (!scanner || !hasTorch.value) return
  try {
    const capabilities = scanner.getRunningTrackCameraCapabilities()
    const torch = capabilities.torchFeature()
    const nextState = !isTorchOn.value
    await torch.apply(nextState)
    isTorchOn.value = nextState
  } catch (err) {
    console.warn('Gagal mengubah senter:', err)
  }
}

// Check zoom capabilities
const updateZoomSupport = () => {
  if (!scanner) {
    hasZoom.value = false
    return
  }
  try {
    const capabilities = scanner.getRunningTrackCameraCapabilities?.()
    const zoom = capabilities?.zoomFeature?.()
    if (zoom?.isSupported()) {
      hasZoom.value = true
      zoomMin.value = zoom.min()
      zoomMax.value = zoom.max()
      currentZoom.value = zoom.value() || zoom.min()
    } else {
      hasZoom.value = false
    }
  } catch (_) {
    hasZoom.value = false
  }
}

// Cycle zoom: 1x -> 2x -> 3x -> 1x
const cycleZoom = async () => {
  if (!scanner || !hasZoom.value) return
  try {
    const capabilities = scanner.getRunningTrackCameraCapabilities()
    const zoom = capabilities.zoomFeature()
    const maxZ = Math.min(zoomMax.value, 3)
    let nextZoom = currentZoom.value >= maxZ ? zoomMin.value : Math.min(currentZoom.value + 1, maxZ)
    await zoom.apply(nextZoom)
    currentZoom.value = nextZoom
  } catch (err) {
    console.warn('Gagal mengubah zoom:', err)
  }
}

// Switch framing mode: Area Luas (rekomendasi, akurat) vs Area Fokus (140px)
const toggleFramingMode = async () => {
  isFocusedMode.value = !isFocusedMode.value
  if (isScanning.value) {
    await restartScannerWithCurrentCamera()
  }
}

// Switch camera (front / back / external)
const switchCamera = async () => {
  if (cameraList.value.length < 2) return
  const currentIndex = cameraList.value.findIndex(c => c.id === selectedCameraId.value)
  const nextIndex = (currentIndex + 1) % cameraList.value.length
  selectedCameraId.value = cameraList.value[nextIndex].id
  localStorage.setItem('inv_preferred_camera_id', selectedCameraId.value)
  await restartScannerWithCurrentCamera()
}

// Internal runner
const runScannerWithConfig = async (cameraConfig: string | MediaTrackConstraints) => {
  if (!scanner) {
    scanner = new Html5Qrcode(regionId, {
      formatsToSupport: supportedFormats,
      useBarCodeDetectorIfSupported: true,
      verbose: false,
    })
  }

  const scanConfig = {
    fps: 15,
    qrbox: (viewfinderWidth: number, viewfinderHeight: number) => {
      const width = Math.min(Math.round(viewfinderWidth * 0.90), 380)
      // JIKA FOKUS SEDANG: 140px (TIDAK 52px, agar garis barcode dan margin tenang tetap utuh dan akurat)
      // JIKA AREA LUAS (DEFAULT): 220px (akurasi maksimal)
      const height = isFocusedMode.value
        ? 140
        : Math.min(Math.round(viewfinderHeight * 0.65), 230)
      return { width, height }
    },
    aspectRatio: 1.333333,
  }

  await scanner.start(
    cameraConfig,
    scanConfig,
    (decodedText: string) => {
      handleSuccessfulScan(decodedText.trim())
    },
    () => {
      // Frame scan callback
    }
  )

  isScanning.value = true
  updateTorchSupport()
  updateZoomSupport()
}

const restartScannerWithCurrentCamera = async () => {
  scannerError.value = ''
  try {
    if (scanner && scanner.isScanning) {
      await scanner.stop()
    }
    const cameraConfig = selectedCameraId.value || { facingMode: 'environment' }
    await runScannerWithConfig(cameraConfig)
  } catch (err: any) {
    scannerError.value = 'Gagal memuat ulang kamera: ' + (err?.message || String(err))
  }
}

// Start camera scanner
let lastScanTime = 0
const startScanner = async () => {
  if (isStarting.value || isScanning.value) return
  isStarting.value = true
  scannerError.value = ''

  try {
    if (!navigator.mediaDevices?.getUserMedia) {
      throw new Error('Browser tidak mendukung akses kamera atau koneksi tidak aman (HTTPS/localhost).')
    }

    try {
      const devices = await Html5Qrcode.getCameras()
      if (devices && devices.length > 0) {
        const real = devices.filter(c => !/obs|virtual|snap|camo|ndi|manycam/i.test(c.label))
        cameraList.value = real.length ? real : devices

        const savedId = localStorage.getItem('inv_preferred_camera_id')
        const preferred = cameraList.value.find(c => c.id === savedId)
        const backCamera = cameraList.value.find(c => /back|rear|environment|belakang/i.test(c.label))
        selectedCameraId.value = (preferred || backCamera || cameraList.value[0]).id
      }
    } catch (_) {}

    const cameraConfig = selectedCameraId.value || { facingMode: 'environment' }
    await runScannerWithConfig(cameraConfig)

    if (cameraList.value.length <= 1) {
      try {
        const postPermDevices = await Html5Qrcode.getCameras()
        if (postPermDevices.length > 0) {
          const real = postPermDevices.filter(c => !/obs|virtual|snap|camo|ndi|manycam/i.test(c.label))
          cameraList.value = real.length ? real : postPermDevices
        }
      } catch (_) {}
    }
  } catch (err: any) {
    const msg = String(err?.message || err)
    if (/permission|denied|NotAllowed/i.test(msg)) {
      scannerError.value = 'Izin kamera ditolak. Silakan izinkan akses kamera di pengaturan browser Anda, lalu coba lagi.'
    } else if (!window.isSecureContext) {
      scannerError.value = 'Kamera memerlukan koneksi aman (HTTPS atau localhost).'
    } else {
      scannerError.value = 'Kamera tidak dapat diakses: ' + msg
    }
  } finally {
    isStarting.value = false
  }
}

// Stop scanner cleanly
const stopScanner = async () => {
  clearAutoConfirmTimer()
  if (scanner) {
    try {
      if (scanner.isScanning) {
        await scanner.stop()
      }
    } catch (_) {}
    try {
      scanner.clear()
    } catch (_) {}
    scanner = null
  }
  isScanning.value = false
  isStarting.value = false
  hasTorch.value = false
  isTorchOn.value = false
  hasZoom.value = false
}

const clearAutoConfirmTimer = () => {
  if (autoConfirmTimer) {
    clearInterval(autoConfirmTimer)
    autoConfirmTimer = null
  }
}

// Scan handler
const handleSuccessfulScan = (rawText: string) => {
  const text = rawText.trim()
  if (!text) return

  // Filter 1: Cek integritas barcode (abaikan jika pembacaan cacat/corrupted)
  if (isCorruptedBarcode(text)) {
    return
  }

  // Filter 2: Klasifikasikan barcode
  const candidate = classifyBarcode(text)

  // Tambahkan ke daftar kandidat terdeteksi (maks 4 kandidat unik)
  const existingIdx = detectedCandidates.value.findIndex(c => c.raw === candidate.raw)
  if (existingIdx === -1) {
    detectedCandidates.value.unshift(candidate)
    if (detectedCandidates.value.length > 4) {
      detectedCandidates.value.pop()
    }
  }

  // Filter 3: Jika barcode MAC dan opsi ignoreMacAddress aktif, jangan auto-submit
  if (ignoreMacAddress.value && candidate.isMac) {
    ignoredMacNotice.value = `Barcode MAC (${candidate.raw}) terdeteksi dan diabaikan. Mencari GPON SN...`
    setTimeout(() => {
      ignoredMacNotice.value = ''
    }, 2800)
    return
  }

  // Filter 4: Debounce scan berulang
  const now = Date.now()
  if (candidate.cleaned === lastScannedText.value && now - lastScanTime < 1400) {
    return
  }
  lastScanTime = now
  lastScannedText.value = candidate.cleaned

  playBeep(880)
  vibrate(70)
  triggerVisualFlash()

  // Mode Single: Munculkan kartu konfirmasi dengan auto-countdown jika Serial Number
  if (props.mode === 'single') {
    pendingSingleScan.value = {
      raw: candidate.raw,
      cleaned: candidate.cleaned,
      label: candidate.label,
    }

    clearAutoConfirmTimer()
    autoConfirmCountdown.value = 100
    const stepInterval = 120
    autoConfirmTimer = setInterval(() => {
      autoConfirmCountdown.value -= 10
      if (autoConfirmCountdown.value <= 0) {
        confirmSingleScan()
      }
    }, stepInterval)
    return
  }

  // Mode Multi: Tambahkan ke list jika belum ada
  if (scannedItems.value.includes(candidate.cleaned)) {
    duplicateNotice.value = `Nomor seri "${candidate.cleaned}" sudah ada dalam daftar.`
    setTimeout(() => {
      duplicateNotice.value = ''
    }, 2500)
    return
  }

  duplicateNotice.value = ''
  scannedItems.value.push(candidate.cleaned)
}

// Memilih salah satu kandidat yang terdeteksi
const selectCandidate = (cand: DetectedCandidate) => {
  if (props.mode === 'single') {
    pendingSingleScan.value = {
      raw: cand.raw,
      cleaned: cand.cleaned,
      label: cand.label,
    }
    confirmSingleScan()
  } else {
    if (!scannedItems.value.includes(cand.cleaned)) {
      scannedItems.value.push(cand.cleaned)
      playBeep(880)
      vibrate(70)
    }
  }
}

const confirmSingleScan = () => {
  clearAutoConfirmTimer()
  if (pendingSingleScan.value) {
    emit('scan', pendingSingleScan.value.cleaned)
    handleClose()
  }
}

const cancelPendingScan = () => {
  clearAutoConfirmTimer()
  pendingSingleScan.value = null
  lastScannedText.value = ''
}

const triggerVisualFlash = () => {
  showSuccessFlash.value = true
  setTimeout(() => {
    showSuccessFlash.value = false
  }, 450)
}

// Fallback: scan from image file
const scanFromFile = async (event: Event) => {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  scannerError.value = ''

  try {
    if (scanner && scanner.isScanning) {
      try { await scanner.stop() } catch (_) {}
    }
    const reader = new Html5Qrcode(regionId, {
      formatsToSupport: supportedFormats,
      useBarCodeDetectorIfSupported: true,
      verbose: false,
    })
    const result = await reader.scanFile(file, false)
    handleSuccessfulScan(result.trim())
    if (props.mode === 'multi') {
      await startScanner()
    }
  } catch (err: any) {
    scannerError.value = 'Barcode tidak terdeteksi pada gambar. Pastikan foto dekat, tegak lurus, dan pencahayaan cukup.'
  } finally {
    (event.target as HTMLInputElement).value = ''
  }
}

// Manual entry in multi mode
const addManualEntry = () => {
  const val = manualInput.value.trim()
  if (!val) return
  const cleaned = autoCleanPrefix.value ? cleanPrefix(val) : val
  if (scannedItems.value.includes(cleaned)) {
    duplicateNotice.value = `Nomor seri "${cleaned}" sudah ada dalam daftar.`
    setTimeout(() => { duplicateNotice.value = '' }, 2500)
    return
  }
  scannedItems.value.push(cleaned)
  manualInput.value = ''
  playBeep(880)
}

const removeScannedItem = (index: number) => {
  scannedItems.value.splice(index, 1)
}

const clearAllScanned = () => {
  scannedItems.value = []
}

// Commit multi-mode
const handleComplete = () => {
  emit('complete', [...scannedItems.value])
  handleClose()
}

const handleClose = () => {
  stopScanner()
  emit('update:modelValue', false)
  emit('close')
}

onBeforeUnmount(() => {
  stopScanner()
})

const isTargetReached = computed(() => {
  if (!props.targetQuantity) return false
  return scannedItems.value.length >= props.targetQuantity
})
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="modelValue" class="scanner-modal-backdrop" @click="handleClose">
        <div class="scanner-modal-card" @click.stop>
          <!-- Header -->
          <div class="scanner-header">
            <div class="scanner-header-info">
              <div class="scanner-title-row">
                <div class="scanner-icon-bubble">
                  <ScanLine :size="18" />
                </div>
                <div>
                  <h3 class="scanner-title">{{ title }}</h3>
                  <p class="scanner-subtitle">{{ subtitle }}</p>
                </div>
              </div>
            </div>
            <div class="header-right-btns">
              <button
                type="button"
                class="settings-toggle-btn"
                :class="{ active: showAdvancedSettings }"
                @click="showAdvancedSettings = !showAdvancedSettings"
                title="Pengaturan Filter Barcode"
              >
                <SlidersHorizontal :size="16" />
              </button>
              <button type="button" class="scanner-close-btn" @click="handleClose" title="Tutup">
                <X :size="18" />
              </button>
            </div>
          </div>

          <!-- Settings Tray (Collapsible) -->
          <div v-if="showAdvancedSettings" class="stacked-barcode-settings">
            <div class="settings-grid">
              <label class="setting-item">
                <input v-model="ignoreMacAddress" type="checkbox" />
                <span><strong>Abaikan Barcode MAC Address</strong> (Prioritaskan GPON SN / Serial Number)</span>
              </label>

              <label class="setting-item">
                <input v-model="autoCleanPrefix" type="checkbox" />
                <span><strong>Bersihkan Prefix</strong> (Potong awalan "GPON SN:", "S/N:", dll)</span>
              </label>

              <label class="setting-item">
                <input v-model="isFocusedMode" type="checkbox" @change="toggleFramingMode" />
                <span><strong>Area Fokus Sedang (140px)</strong> (Batas kotak lebih terfokus tanpa memotong garis)</span>
              </label>
            </div>
          </div>

          <!-- Multi-scan Target Counter Bar -->
          <div v-if="mode === 'multi'" class="scanner-counter-bar">
            <div class="counter-text">
              <span class="counter-label">Nomor Seri Terpindai:</span>
              <strong class="counter-badge" :class="{ 'target-hit': isTargetReached }">
                {{ scannedItems.length }}
                <span v-if="targetQuantity" class="counter-total"> / {{ targetQuantity }}</span>
              </strong>
            </div>

            <div class="counter-actions">
              <button
                v-if="scannedItems.length > 0"
                type="button"
                class="clear-btn"
                @click="clearAllScanned"
              >
                Hapus Semua
              </button>
            </div>
          </div>

          <!-- Scanner Viewport -->
          <div class="scanner-viewport-wrapper">
            <div :id="regionId" class="scanner-camera-region"></div>

            <!-- Custom Reticle & Overlay UI -->
            <div class="scanner-viewfinder-overlay" :class="{ 'flash-success': showSuccessFlash }">
              <!-- Reticle Guides -->
              <div
                class="reticle-box"
                :class="{ 'focused-frame': isFocusedMode }"
              >
                <div class="corner top-left"></div>
                <div class="corner top-right"></div>
                <div class="corner bottom-left"></div>
                <div class="corner bottom-right"></div>
                <div class="laser-scanner-line"></div>
              </div>

              <!-- Top controls overlay: Area Mode, Zoom, Torch, Flip, Mute -->
              <div class="scanner-top-controls">
                <!-- Framing Mode Toggle Button -->
                <button
                  type="button"
                  class="control-pill-btn"
                  :class="{ active: isFocusedMode }"
                  @click="toggleFramingMode"
                  :title="isFocusedMode ? 'Kembali ke Area Luas (Rekomendasi)' : 'Gunakan Area Fokus Sedang'"
                >
                  <Target :size="14" />
                  <span>{{ isFocusedMode ? 'Area Fokus' : 'Area Luas' }}</span>
                </button>

                <!-- Zoom Button (1x / 2x / 3x) -->
                <button
                  v-if="hasZoom"
                  type="button"
                  class="control-pill-btn"
                  :class="{ active: currentZoom > 1 }"
                  @click="cycleZoom"
                  :title="'Zoom: ' + currentZoom + 'x'"
                >
                  <ZoomIn :size="14" />
                  <span>{{ currentZoom }}x</span>
                </button>

                <!-- Senter / Torch -->
                <button
                  v-if="hasTorch"
                  type="button"
                  class="control-pill-btn"
                  :class="{ active: isTorchOn }"
                  @click="toggleTorch"
                  :title="isTorchOn ? 'Matikan Senter' : 'Nyalakan Senter'"
                >
                  <Zap :size="14" />
                </button>

                <!-- Switch Camera -->
                <button
                  v-if="cameraList.length > 1"
                  type="button"
                  class="control-pill-btn"
                  @click="switchCamera"
                  title="Ganti Kamera"
                >
                  <RefreshCw :size="14" />
                </button>

                <!-- Sound Toggle -->
                <button
                  type="button"
                  class="control-pill-btn"
                  :class="{ muted: isMuted }"
                  @click="isMuted = !isMuted"
                  :title="isMuted ? 'Bunyikan Suara' : 'Bisukan Suara'"
                >
                  <Volume2 v-if="!isMuted" :size="14" />
                  <VolumeX v-else :size="14" />
                </button>
              </div>

              <!-- Live Detected Barcodes Banner / Indicator -->
              <div v-if="lastScannedText && !pendingSingleScan" class="scanned-pill-preview">
                <CheckCircle2 :size="14" class="success-icon" />
                <span>Terdeteksi: <strong>{{ autoCleanPrefix ? cleanPrefix(lastScannedText) : lastScannedText }}</strong></span>
              </div>
            </div>

            <!-- Single Mode Confirmation Drawer -->
            <div v-if="pendingSingleScan" class="single-scan-confirm-overlay">
              <div class="confirm-content">
                <div class="confirm-header">
                  <CheckCircle2 :size="16" class="success-icon" />
                  <span>{{ pendingSingleScan.label }} Terdeteksi:</span>
                </div>
                <div class="confirm-value-row">
                  <strong class="confirm-clean-val">{{ pendingSingleScan.cleaned }}</strong>
                  <span v-if="pendingSingleScan.raw !== pendingSingleScan.cleaned" class="confirm-raw-val">
                    (dari "{{ pendingSingleScan.raw }}")
                  </span>
                </div>
                <!-- Countdown bar for auto-confirm -->
                <div class="confirm-timer-track">
                  <div class="confirm-timer-bar" :style="{ width: autoConfirmCountdown + '%' }"></div>
                </div>
                <div class="confirm-actions">
                  <button type="button" class="btn-rescan" @click="cancelPendingScan">
                    <RotateCcw :size="13" /> Scan Ulang
                  </button>
                  <button type="button" class="btn-accept" @click="confirmSingleScan">
                    <Check :size="14" /> Gunakan Ini
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- MAC Address Ignored Alert -->
          <div v-if="ignoredMacNotice" class="scanner-alert info">
            <AlertCircle :size="16" class="alert-icon" />
            <span>{{ ignoredMacNotice }}</span>
          </div>

          <!-- Duplicate Warning Notice -->
          <div v-if="duplicateNotice" class="scanner-alert warning">
            <AlertCircle :size="16" class="alert-icon" />
            <span>{{ duplicateNotice }}</span>
          </div>

          <!-- Error Alert -->
          <div v-if="scannerError" class="scanner-alert error">
            <AlertCircle :size="16" class="alert-icon" />
            <div class="alert-content">
              <span>{{ scannerError }}</span>
            </div>
          </div>

          <!-- SOLUSI BARCODE BERTUMPUK: Daftar Barcode yang Terdeteksi pada Perangkat -->
          <div v-if="detectedCandidates.length > 0" class="detected-candidates-section">
            <div class="candidates-title-row">
              <Sparkles :size="14" class="sparkle-icon" />
              <span>Barcode Terdeteksi pada Stiker:</span>
              <small>(Klik untuk langsung menggunakan)</small>
            </div>
            <div class="candidates-list">
              <button
                v-for="(cand, cIdx) in detectedCandidates"
                :key="cIdx"
                type="button"
                class="candidate-pill-btn"
                :class="{ 'is-preferred-serial': cand.isSerial, 'is-mac': cand.isMac }"
                @click="selectCandidate(cand)"
                :title="'Gunakan ' + cand.cleaned"
              >
                <div class="candidate-main">
                  <span class="cand-label">{{ cand.label }}:</span>
                  <strong class="cand-val">{{ cand.cleaned }}</strong>
                </div>
                <span class="cand-pick-btn">
                  <Check :size="12" /> Pilih
                </span>
              </button>
            </div>
          </div>

          <!-- Multi-Mode Scanned List Chips & Manual Input -->
          <div v-if="mode === 'multi'" class="scanned-items-section">
            <div class="manual-add-row">
              <input
                v-model="manualInput"
                type="text"
                placeholder="Atau ketik S/N manual..."
                class="manual-input"
                @keydown.enter.prevent="addManualEntry"
              />
              <button
                type="button"
                class="manual-add-btn"
                :disabled="!manualInput.trim()"
                @click="addManualEntry"
              >
                <Plus :size="14" /> Tambah
              </button>
            </div>

            <!-- Scanned chips container -->
            <div class="chips-container" v-if="scannedItems.length > 0">
              <div
                v-for="(sn, idx) in scannedItems"
                :key="idx"
                class="sn-chip"
              >
                <span class="sn-chip-index">{{ idx + 1 }}.</span>
                <span class="sn-chip-text">{{ sn }}</span>
                <button
                  type="button"
                  class="sn-chip-del"
                  @click="removeScannedItem(idx)"
                  title="Hapus"
                >
                  <X :size="12" />
                </button>
              </div>
            </div>
            <div v-else class="chips-empty-notice">
              Arahkan kamera ke stiker perangkat dalam <strong>Area Luas</strong>. Sistem otomatis mendeteksi dan memfilter <strong>GPON SN</strong>.
            </div>
          </div>

          <!-- Footer Actions -->
          <div class="scanner-footer">
            <label class="file-scan-btn" title="Pindai gambar barcode yang tersimpan">
              <ImageIcon :size="15" />
              <span>Scan dari Foto</span>
              <input
                type="file"
                accept="image/*"
                capture="environment"
                hidden
                @change="scanFromFile"
              />
            </label>

            <div class="footer-right-actions">
              <button type="button" class="btn-cancel" @click="handleClose">
                Batal
              </button>
              <button
                v-if="mode === 'multi'"
                type="button"
                class="btn-confirm"
                :disabled="scannedItems.length === 0"
                @click="handleComplete"
              >
                <Check :size="15" />
                <span>Gunakan Seri ({{ scannedItems.length }})</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
/* Backdrop & Modal Container */
.scanner-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(25, 23, 38, 0.72);
  backdrop-filter: blur(8px);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 10005;
  padding: 16px;
}

.scanner-modal-card {
  background: #ffffff;
  border-radius: 18px;
  width: 100%;
  max-width: 520px;
  max-height: 94vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 24px 60px rgba(18, 15, 36, 0.28), 0 2px 8px rgba(0, 0, 0, 0.08);
  overflow: hidden;
  animation: modalScaleUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes modalScaleUp {
  from {
    opacity: 0;
    transform: scale(0.96) translateY(12px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

/* Header */
.scanner-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 22px;
  border-bottom: 1px solid #f0eff3;
}

.scanner-title-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.scanner-icon-bubble {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: linear-gradient(135deg, #f4f1ff, #ebe6ff);
  color: #5544b8;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.scanner-title {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #292541;
  letter-spacing: -0.01em;
}

.scanner-subtitle {
  margin: 2px 0 0;
  font-size: 11px;
  color: #858291;
}

.header-right-btns {
  display: flex;
  align-items: center;
  gap: 6px;
}

.settings-toggle-btn {
  background: transparent;
  border: 1px solid #e2e0ea;
  color: #858291;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  cursor: pointer;
  transition: all 0.2s;
}

.settings-toggle-btn:hover,
.settings-toggle-btn.active {
  background: #f4f1ff;
  border-color: #5544b8;
  color: #5544b8;
}

.scanner-close-btn {
  background: transparent;
  border: none;
  color: #858291;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  cursor: pointer;
  transition: all 0.2s;
}

.scanner-close-btn:hover {
  background: #f4f3f7;
  color: #292541;
}

/* Stacked Barcode Settings Tray */
.stacked-barcode-settings {
  background: #f7f6fd;
  border-bottom: 1px solid #e7e4f5;
  padding: 12px 22px;
  animation: fadeIn 0.2s ease;
}

.settings-grid {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.setting-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  color: #48445c;
  cursor: pointer;
}

.setting-item input[type="checkbox"] {
  accent-color: #5544b8;
  cursor: pointer;
}

/* Multi-scan Counter Bar */
.scanner-counter-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 22px;
  background: #fbfbfe;
  border-bottom: 1px solid #eeecf4;
  font-size: 12px;
}

.counter-text {
  display: flex;
  align-items: center;
  gap: 8px;
}

.counter-label {
  color: #635f75;
  font-weight: 500;
}

.counter-badge {
  background: #ebe7fa;
  color: #4b3fa0;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
}

.counter-badge.target-hit {
  background: #e6f6ee;
  color: #228b58;
}

.counter-total {
  font-weight: 500;
  opacity: 0.8;
}

.clear-btn {
  background: none;
  border: none;
  color: #c43838;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  padding: 4px 6px;
  border-radius: 4px;
}

.clear-btn:hover {
  background: #fff0f0;
}

/* Viewport Area */
.scanner-viewport-wrapper {
  position: relative;
  background: #0f0e1c;
  width: 100%;
  min-height: 260px;
  max-height: 330px;
  overflow: hidden;
  display: flex;
  justify-content: center;
  align-items: center;
}

.scanner-camera-region {
  width: 100% !important;
  height: 100% !important;
  min-height: 260px;
  background: #0f0e1c;
}

:deep(.scanner-camera-region video) {
  width: 100% !important;
  height: 100% !important;
  object-fit: cover !important;
}

:deep(#qr-shaded-region) {
  border-color: rgba(0, 0, 0, 0.48) !important;
}

/* Viewfinder Overlay */
.scanner-viewfinder-overlay {
  position: absolute;
  inset: 0;
  pointer-events: none;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 12px;
  transition: background 0.3s;
}

.scanner-viewfinder-overlay.flash-success {
  background: rgba(59, 157, 118, 0.28);
}

/* Reticle Guides (Area Luas 220px vs Fokus 140px) */
.reticle-box {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 82%;
  height: 64%;
  max-width: 360px;
  max-height: 220px;
  pointer-events: none;
  transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.reticle-box.focused-frame {
  height: 140px !important;
  max-height: 140px !important;
}

.corner {
  position: absolute;
  width: 20px;
  height: 20px;
  border-color: #8b7bf7;
  border-style: solid;
  border-width: 0;
}

.corner.top-left {
  top: 0;
  left: 0;
  border-top-width: 3px;
  border-left-width: 3px;
  border-top-left-radius: 8px;
}

.corner.top-right {
  top: 0;
  right: 0;
  border-top-width: 3px;
  border-right-width: 3px;
  border-top-right-radius: 8px;
}

.corner.bottom-left {
  bottom: 0;
  left: 0;
  border-bottom-width: 3px;
  border-left-width: 3px;
  border-bottom-left-radius: 8px;
}

.corner.bottom-right {
  bottom: 0;
  right: 0;
  border-bottom-width: 3px;
  border-right-width: 3px;
  border-bottom-right-radius: 8px;
}

/* Laser scan animation */
.laser-scanner-line {
  position: absolute;
  left: 4px;
  right: 4px;
  height: 2px;
  background: linear-gradient(90deg, transparent, #ff4d4d, #ff8080, #ff4d4d, transparent);
  box-shadow: 0 0 10px rgba(255, 77, 77, 0.9), 0 0 18px rgba(255, 128, 128, 0.7);
  animation: laserScan 2.4s ease-in-out infinite alternate;
}

@keyframes laserScan {
  0% {
    top: 6%;
    opacity: 0.3;
  }
  20% {
    opacity: 0.95;
  }
  80% {
    opacity: 0.95;
  }
  100% {
    top: 92%;
    opacity: 0.3;
  }
}

/* Top Floating Controls */
.scanner-top-controls {
  position: relative;
  display: flex;
  justify-content: flex-end;
  gap: 6px;
  pointer-events: auto;
  z-index: 2;
}

.control-pill-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(22, 20, 36, 0.75);
  backdrop-filter: blur(8px);
  color: #ffffff;
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 18px;
  padding: 5px 10px;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}

.control-pill-btn:hover {
  background: rgba(55, 49, 90, 0.9);
  border-color: rgba(255, 255, 255, 0.4);
}

.control-pill-btn.active {
  background: #5544b8;
  color: #ffffff;
  border-color: #8b7bf7;
}

.control-pill-btn.muted {
  opacity: 0.6;
}

/* Scan Preview Pill */
.scanned-pill-preview {
  align-self: center;
  background: rgba(18, 16, 28, 0.9);
  backdrop-filter: blur(8px);
  color: #ffffff;
  border: 1px solid rgba(59, 157, 118, 0.5);
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 11px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  pointer-events: auto;
  animation: pillPop 0.25s ease-out;
}

.success-icon {
  color: #3b9d76;
}

/* Single Mode Confirmation Drawer */
.single-scan-confirm-overlay {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: rgba(18, 16, 30, 0.96);
  backdrop-filter: blur(10px);
  padding: 16px 20px;
  border-top: 2px solid #3b9d76;
  z-index: 10;
  animation: slideUp 0.2s ease-out;
}

@keyframes slideUp {
  from {
    transform: translateY(100%);
  }
  to {
    transform: translateY(0);
  }
}

.confirm-header {
  display: flex;
  align-items: center;
  gap: 6px;
  color: #aaa7b4;
  font-size: 11px;
  font-weight: 500;
}

.confirm-value-row {
  margin: 6px 0 10px;
  display: flex;
  align-items: baseline;
  gap: 8px;
  flex-wrap: wrap;
}

.confirm-clean-val {
  font-size: 17px;
  color: #ffffff;
  font-family: monospace;
  letter-spacing: 0.04em;
}

.confirm-raw-val {
  font-size: 11px;
  color: #858291;
  font-family: monospace;
}

.confirm-timer-track {
  width: 100%;
  height: 3px;
  background: rgba(255, 255, 255, 0.12);
  border-radius: 2px;
  overflow: hidden;
  margin-bottom: 12px;
}

.confirm-timer-bar {
  height: 100%;
  background: #3b9d76;
  transition: width 0.1s linear;
}

.confirm-actions {
  display: flex;
  gap: 10px;
}

.btn-rescan {
  flex: 1;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: #ffffff;
  padding: 7px 12px;
  border-radius: 7px;
  font-size: 11px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  cursor: pointer;
}

.btn-rescan:hover {
  background: rgba(255, 255, 255, 0.15);
}

.btn-accept {
  flex: 1;
  background: #3b9d76;
  border: 1px solid #3b9d76;
  color: #ffffff;
  padding: 7px 12px;
  border-radius: 7px;
  font-size: 11px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  cursor: pointer;
}

.btn-accept:hover {
  background: #328765;
}

/* Solusi Barcode Bertumpuk: Tray Kandidat Terdeteksi */
.detected-candidates-section {
  padding: 10px 18px;
  background: #fbfaff;
  border-bottom: 1px solid #eeeaf8;
}

.candidates-title-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 600;
  color: #4b3fa0;
  margin-bottom: 6px;
}

.candidates-title-row small {
  color: #858291;
  font-weight: 400;
  margin-left: 4px;
}

.sparkle-icon {
  color: #7b68ee;
}

.candidates-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.candidate-pill-btn {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 7px 12px;
  background: #ffffff;
  border: 1px solid #e2ddf4;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
  text-align: left;
}

.candidate-pill-btn:hover {
  background: #f4f1ff;
  border-color: #7b68ee;
  transform: translateX(2px);
}

.candidate-pill-btn.is-preferred-serial {
  border-color: #5544b8;
  background: #faf8ff;
  box-shadow: 0 2px 6px rgba(85, 68, 184, 0.08);
}

.candidate-pill-btn.is-mac {
  opacity: 0.75;
}

.candidate-main {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.cand-label {
  font-size: 10px;
  font-weight: 600;
  color: #858291;
  text-transform: uppercase;
}

.candidate-pill-btn.is-preferred-serial .cand-label {
  color: #5544b8;
}

.cand-val {
  font-size: 13px;
  color: #292541;
  font-family: monospace;
}

.cand-pick-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  font-weight: 700;
  background: #ece7fb;
  color: #4b3fa0;
  padding: 3px 8px;
  border-radius: 6px;
}

.candidate-pill-btn.is-preferred-serial .cand-pick-btn {
  background: #5544b8;
  color: #ffffff;
}

/* Alerts */
.scanner-alert {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 16px;
  font-size: 11px;
  margin: 10px 20px 0;
  border-radius: 8px;
}

.scanner-alert.error {
  background: #fff5f5;
  color: #c43838;
  border: 1px solid #ffd4d4;
}

.scanner-alert.warning {
  background: #fffaf0;
  color: #b26c07;
  border: 1px solid #ffe8b3;
}

.scanner-alert.info {
  background: #f4f1ff;
  color: #4b3fa0;
  border: 1px solid #dcd8ec;
}

.alert-icon {
  flex-shrink: 0;
}

/* Multi-Mode Chips & Manual Add */
.scanned-items-section {
  padding: 12px 22px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-height: 180px;
  overflow-y: auto;
}

.manual-add-row {
  display: flex;
  gap: 8px;
}

.manual-input {
  flex: 1;
  background: #fafafd;
  border: 1px solid #e9e7ef;
  border-radius: 8px;
  padding: 7px 12px;
  font-size: 12px;
  color: #48445c;
  outline: none;
}

.manual-input:focus {
  border-color: #bd3c3c;
  background: #ffffff;
}

.manual-add-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 0 12px;
  background: #f4f3f8;
  border: 1px solid #e2e0ea;
  border-radius: 8px;
  font-size: 11px;
  font-weight: 600;
  color: #48445c;
  cursor: pointer;
  transition: all 0.2s;
}

.manual-add-btn:hover:not(:disabled) {
  background: #e9e7f2;
}

.manual-add-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.chips-container {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  max-height: 105px;
  overflow-y: auto;
  padding: 2px 0;
}

.sn-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #f4f2fb;
  border: 1px solid #e0dcf4;
  color: #3f3579;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-family: monospace;
}

.sn-chip-index {
  color: #8980b3;
  font-size: 10px;
}

.sn-chip-text {
  font-weight: 600;
}

.sn-chip-del {
  background: transparent;
  border: none;
  color: #8980b3;
  cursor: pointer;
  display: grid;
  place-items: center;
  padding: 1px;
  border-radius: 3px;
}

.sn-chip-del:hover {
  background: #e0daf5;
  color: #bd3c3c;
}

.chips-empty-notice {
  font-size: 11px;
  color: #aaa7b4;
  text-align: center;
  padding: 8px;
}

/* Footer Actions */
.scanner-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 22px 18px;
  border-top: 1px solid #f0eff3;
  background: #ffffff;
}

.file-scan-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #635f75;
  font-size: 11px;
  font-weight: 600;
  background: #fafafd;
  border: 1px solid #e5e3ed;
  padding: 8px 12px;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.file-scan-btn:hover {
  background: #f2f0f8;
  color: #292541;
}

.footer-right-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-cancel {
  background: #ffffff;
  border: 1px solid #e9e7ef;
  color: #777486;
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-cancel:hover {
  background: #fafafd;
  color: #48445c;
}

.btn-confirm {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #3b9d76;
  border: 1px solid #3b9d76;
  color: #ffffff;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-confirm:hover:not(:disabled) {
  background: #328765;
  border-color: #328765;
  transform: translateY(-1px);
}

.btn-confirm:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Modal Transition */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
