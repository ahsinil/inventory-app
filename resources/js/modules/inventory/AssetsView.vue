<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import axios from 'axios'
import { Html5Qrcode } from 'html5-qrcode'
import { Ellipsis, Plus, Search, ShieldCheck, ChevronDown, X, ScanLine, AlertTriangle } from 'lucide-vue-next'
import DateRangePicker from '../../components/DateRangePicker.vue'

// --- Scanner kamera untuk Nomor Seri ---
const showScanner = ref(false)
const scannerError = ref('')
let scanner: Html5Qrcode | null = null

const stopScanner = async () => {
  if (scanner) {
    try { if (scanner.isScanning) await scanner.stop() } catch (e) { /* abaikan */ }
    try { scanner.clear() } catch (e) { /* abaikan */ }
    scanner = null
  }
  showScanner.value = false
}

const cameraList = ref<{ id: string; label: string }[]>([])
const selectedCameraId = ref('')

const toastMessage = ref('')
const toastType = ref('error')
const showToast = (msg: string, type: string = 'error') => {
  toastMessage.value = msg
  toastType.value = type
  setTimeout(() => { toastMessage.value = '' }, 3000)
}

const runCamera = async () => {
  scannerError.value = ''
  if (scanner) {
    try { if (scanner.isScanning) await scanner.stop() } catch (e) { /* abaikan */ }
  } else {
    scanner = new Html5Qrcode('sn-scanner-region')
  }
  try {
    await scanner.start(
      selectedCameraId.value,
      { fps: 10, qrbox: { width: 260, height: 160 } },
      (text: string) => {
        form.value.serial_number = text.trim()
        stopScanner()
      },
      () => {}
    )
    localStorage.setItem('sn_camera_id', selectedCameraId.value)
  } catch (err: any) {
    scannerError.value = 'Kamera tidak dapat dibuka: ' + String(err?.message || err)
  }
}

const startScanner = async () => {
  scannerError.value = ''
  showScanner.value = true
  await nextTick()
  try {
    if (!navigator.mediaDevices?.getUserMedia) {
      throw new Error('Browser tidak mendukung kamera atau halaman tidak aman (butuh HTTPS/localhost).')
    }
    // Minta izin dulu agar daftar kamera terisi lengkap
    const probe = await Promise.race([
      navigator.mediaDevices.getUserMedia({ video: true }),
      new Promise<never>((_, rej) => setTimeout(() => rej(new Error(
        'TIMEOUT: browser tidak merespons permintaan kamera (tidak ada prompt izin). Cek izin kamera Arc & macOS, atau gunakan tombol "Scan dari Foto" di bawah.'
      )), 8000)),
    ])
    probe.getTracks().forEach(t => t.stop())

    const all = await Html5Qrcode.getCameras()
    if (!all.length) throw new Error('Tidak ada kamera yang terdeteksi.')
    const real = all.filter(c => !/obs|virtual|snap|camo|ndi|manycam/i.test(c.label))
    cameraList.value = real.length ? real : all
    const back = cameraList.value.find(c => /back|rear|environment|belakang/i.test(c.label))
    const saved = localStorage.getItem('sn_camera_id')
    const preferred = cameraList.value.find(c => c.id === saved)
    selectedCameraId.value = (preferred || back || cameraList.value[0]).id
    await runCamera()
  } catch (err: any) {
    const msg = String(err?.message || err)
    scannerError.value = /permission|denied|NotAllowed/i.test(msg)
      ? 'Akses kamera ditolak. Izinkan kamera di pengaturan browser, lalu coba lagi.'
      : (!window.isSecureContext
          ? 'Kamera hanya bisa dipakai lewat HTTPS atau localhost.'
          : 'Kamera tidak dapat dibuka: ' + msg)
  }
}

// Fallback: scan dari foto/file (tanpa izin kamera live)
const scanFromFile = async (e: Event) => {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  scannerError.value = ''
  try {
    if (scanner) { try { if (scanner.isScanning) await scanner.stop() } catch (_) {} }
    const reader = new Html5Qrcode('sn-scanner-region', false)
    const text = await reader.scanFile(file, false)
    form.value.serial_number = text.trim()
    showScanner.value = false
  } catch (err: any) {
    scannerError.value = 'Barcode tidak terbaca pada foto. Coba foto yang lebih jelas dan dekat.'
  } finally {
    (e.target as HTMLInputElement).value = ''
  }
}

onBeforeUnmount(() => { stopScanner() })

const assets = ref<any[]>([])
const stats = ref({ total_assets: 0, in_stock: 0, assigned: 0, maintenance: 0 })
const warehouses = ref<any[]>([])

const loading = ref(false)
const search = ref('')
const activeWarehouse = ref('')
const activeStatus = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const page = ref(1)
const total = ref(0)
const lastPage = ref(1)
const from = ref(0)
const to = ref(0)

const showAddModal = ref(false)
const editingId = ref<string | null>(null)
const saving = ref(false)
const formErrors = ref<Record<string, string[]>>({})
const products = ref<any[]>([])
const locations = ref<any[]>([])
const form = ref({
  product_id: '',
  warehouse_id: '',
  warehouse_location_id: '',
  serial_number: '',
  status: 'In stock',
  remarks: ''
})

const openAddModal = async () => {
  editingId.value = null
  form.value = { product_id: '', warehouse_id: '', warehouse_location_id: '', serial_number: '', status: 'In stock', remarks: '' }
  formErrors.value = {}
  showAddModal.value = true
  if (products.value.length === 0) {
    try {
      const res = await axios.get('/products', { params: { per_page: 500 } })
      products.value = res.data.data?.data || res.data.data || []
    } catch (e) {
      console.error(e)
    }
  }
}

const openEdit = async (asset: any) => {
  closeDropdown()
  editingId.value = asset.id
  form.value = {
    product_id: asset.product_id,
    warehouse_id: asset.warehouse_location?.warehouse_id || '',
    warehouse_location_id: asset.warehouse_location_id,
    serial_number: asset.serial_number,
    status: asset.status,
    remarks: asset.remarks || ''
  }
  formErrors.value = {}
  showAddModal.value = true
  
  if (products.value.length === 0) {
    try {
      const res = await axios.get('/products', { params: { per_page: 500 } })
      products.value = res.data.data?.data || res.data.data || []
    } catch (e) {}
  }
  
  if (form.value.warehouse_id) {
    try {
      const res = await axios.get(`/warehouses/${form.value.warehouse_id}/locations`)
      locations.value = res.data.data || []
    } catch (e) {
      locations.value = []
    }
  }
}

const showDeleteConfirm = ref(false)
const assetToDelete = ref<any>(null)
const deleting = ref(false)

const confirmRemoveAsset = (asset: any) => {
  assetToDelete.value = asset
  showDeleteConfirm.value = true
  closeDropdown()
}

const removeAsset = async () => {
  if (!assetToDelete.value) return
  deleting.value = true
  try {
    await axios.delete(`/assets/${assetToDelete.value.id}`)
    showDeleteConfirm.value = false
    assetToDelete.value = null
    showToast('Aset berhasil dihapus', 'success')
    fetchAssets()
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal menghapus aset', 'error')
  } finally {
    deleting.value = false
  }
}

watch(() => form.value.warehouse_id, async (newVal) => {
  if (!newVal) {
    locations.value = []
    form.value.warehouse_location_id = ''
    return
  }
  try {
    const res = await axios.get(`/warehouses/${newVal}/locations`)
    locations.value = res.data.data || []
    if (locations.value.length === 1) {
      form.value.warehouse_location_id = locations.value[0].id
    }
  } catch (e) {
    locations.value = []
  }
})

const saveAsset = async () => {
  saving.value = true
  formErrors.value = {}
  try {
    if (editingId.value) {
      await axios.put(`/assets/${editingId.value}`, form.value)
      showToast('Aset berhasil diperbarui', 'success')
    } else {
      await axios.post('/assets', form.value)
      showToast('Aset berhasil didaftarkan', 'success')
    }
    showAddModal.value = false
    fetchAssets()
  } catch (e: any) {
    formErrors.value = e.response?.data?.errors || { general: [e.response?.data?.message || 'Gagal menyimpan aset.'] }
    showToast('Gagal menyimpan aset', 'error')
  } finally {
    saving.value = false
  }
}

const activeDropdown = ref<string | null>(null)
const dropdownX = ref(0)
const dropdownY = ref(0)

const toggleDropdown = (e: MouseEvent, id: string) => {
  if (activeDropdown.value === id) {
    activeDropdown.value = null
  } else {
    activeDropdown.value = id
    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
    dropdownX.value = rect.left - 120
    dropdownY.value = rect.bottom + 5
  }
}
const closeDropdown = () => activeDropdown.value = null
document.addEventListener('click', closeDropdown)
onBeforeUnmount(() => { document.removeEventListener('click', closeDropdown) })

let searchTimeout: any = null
const onSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    page.value = 1
    fetchAssets()
  }, 300)
}

const onFilterChange = () => {
  page.value = 1
  fetchAssets()
}

const fetchInitialData = async () => {
  try {
    const whRes = await axios.get('/warehouses', { params: { per_page: 100 } })
    warehouses.value = whRes.data.data?.data || whRes.data.data || []
  } catch (e) {
    console.error('Failed to load warehouses', e)
  }
}

const fetchAssets = async () => {
  loading.value = true
  try {
    const [statsRes, listRes] = await Promise.all([
      axios.get('/reports/assets/stats', { params: { warehouse_id: activeWarehouse.value, start_date: dateFrom.value, end_date: dateTo.value } }),
      axios.get('/assets', { params: { page: page.value, search: search.value, status: activeStatus.value, warehouse_id: activeWarehouse.value, start_date: dateFrom.value, end_date: dateTo.value } })
    ])
    stats.value = statsRes.data.data
    const data = listRes.data.data
    assets.value = data.data
    total.value = data.total
    lastPage.value = data.last_page
    to.value = data.to || 0
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat aset', 'error')
  } finally {
    loading.value = false
  }
}

const exportCSV = () => {
  if (!assets.value.length) {
    showToast('Tidak ada data aset untuk diekspor', 'warning')
    return
  }
  
  const headers = ['Serial Number', 'Produk', 'SKU', 'Gudang', 'Lokasi', 'Status', 'Catatan']
  const rows = assets.value.map(a => [
    `"${a.serial_number || ''}"`,
    `"${a.product?.name || ''}"`,
    `"${a.product?.sku || ''}"`,
    `"${a.warehouse_location?.warehouse?.name || ''}"`,
    `"${a.warehouse_location?.name || ''}"`,
    `"${a.status || ''}"`,
    `"${a.remarks || ''}"`
  ])
  
  const csvContent = "data:text/csv;charset=utf-8," + 
    [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
    
  const encodedUri = encodeURI(csvContent)
  const link = document.createElement('a')
  link.setAttribute('href', encodedUri)
  link.setAttribute('download', `Aset_Berseri_${new Date().toISOString().slice(0,10)}.csv`)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const goTo = (p: number) => {
  if (p < 1 || p > lastPage.value) return
  page.value = p
  fetchAssets()
}

const pages = computed(() => Array.from({ length: lastPage.value }, (_, i) => i + 1))

onMounted(() => {
  fetchInitialData().then(fetchAssets)
})

const getStatusTone = (status: string) => {
  if (!status) return 'neutral'
  const lower = status.toLowerCase()
  if (lower.includes('in stock') || lower.includes('tersedia')) return 'success'
  if (lower.includes('assigned') || lower.includes('ditugaskan')) return 'assigned'
  if (lower.includes('maintenance') || lower.includes('pemeliharaan')) return 'warning'
  return 'neutral'
}

const formatDate = (d: string) => {
  if (!d) return '-'
  return new Date(d).toLocaleString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
  <section class="assets-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">INVENTARIS / ASET BERSERI</p>
        <h1>Aset berseri</h1>
        <p class="muted">Lacak peralatan yang dapat diidentifikasi secara individu di seluruh gudang Anda.</p>
      </div>
      <div style="display: flex; gap: 10px;">
        <button class="secondary-button" @click="exportCSV"><Download :size="17" /> Ekspor laporan</button>
        <button class="primary-button" @click="openAddModal"><Plus :size="17" /> Daftar aset</button>
      </div>
    </div>
    
    <div class="asset-summary">
      <div class="summary-card">
        <span>Total aset</span>
        <strong>{{ (stats.total_assets || 0).toLocaleString('id-ID') }}</strong>
        <small>Semua peralatan terdaftar</small>
      </div>
      <div class="summary-card">
        <span>Tersedia</span>
        <strong class="positive">{{ (stats.in_stock || 0).toLocaleString('id-ID') }}</strong>
        <small>Tersedia untuk ditugaskan</small>
      </div>
      <div class="summary-card">
        <span>Ditugaskan</span>
        <strong class="assigned-text">{{ (stats.assigned || 0).toLocaleString('id-ID') }}</strong>
        <small>Saat ini digunakan</small>
      </div>
      <div class="summary-card">
        <span>Pemeliharaan</span>
        <strong class="warning-text">{{ (stats.maintenance || 0).toLocaleString('id-ID') }}</strong>
        <small>Perlu perhatian</small>
      </div>
    </div>
    
    <div class="panel assets-panel">
      <div class="panel-header custom-header">
        <div class="header-top">
          <div>
            <h2>Daftar aset</h2>
            <p>Setiap barang berseri dengan gudang dan statusnya saat ini.</p>
          </div>
          
          <div class="header-filters">
            <div class="select-wrap">
              <select v-model="activeWarehouse" @change="onFilterChange" aria-label="Filter gudang">
                <option value="">Semua Gudang</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
              </select>
              <ChevronDown :size="14" class="icon" />
            </div>
            
            <DateRangePicker 
              :from="dateFrom" 
              :to="dateTo" 
              @update:from="dateFrom = $event" 
              @update:to="dateTo = $event" 
              @change="onFilterChange" 
            />
          </div>
        </div>
        
        <div class="tabs-row">
          <div style="display: flex; gap: 4px; align-items: center;">
            <button class="tab-button" :class="{ active: activeStatus === '' }" @click="activeStatus = ''; onFilterChange()">
              Semua status
              <span v-if="activeStatus === ''">{{ stats.total_assets }}</span>
            </button>
            <button class="tab-button" :class="{ active: activeStatus === 'In stock' }" @click="activeStatus = 'In stock'; onFilterChange()">
              Tersedia
              <span v-if="activeStatus === 'In stock'">{{ stats.in_stock }}</span>
            </button>
            <button class="tab-button" :class="{ active: activeStatus === 'Assigned' }" @click="activeStatus = 'Assigned'; onFilterChange()">
              Ditugaskan
              <span v-if="activeStatus === 'Assigned'">{{ stats.assigned }}</span>
            </button>
            <button class="tab-button" :class="{ active: activeStatus === 'Maintenance' }" @click="activeStatus = 'Maintenance'; onFilterChange()">
              Pemeliharaan
              <span v-if="activeStatus === 'Maintenance'">{{ stats.maintenance }}</span>
            </button>
          </div>
          
          <div class="rsv-search" style="margin-left: auto;">
            <Search :size="14" class="icon" />
            <input v-model="search" @input="onSearch" placeholder="Cari aset..." aria-label="Cari aset" />
          </div>
        </div>
      </div>
      
      <div class="table-wrap">
        <table class="assets-table">
          <thead>
            <tr>
              <th>Aset</th>
              <th>Produk</th>
              <th>Gudang</th>
              <th>Status</th>
              <th>Terakhir dilihat</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading && !assets.length"><td colspan="6" style="text-align:center; padding: 20px;">Memuat data...</td></tr>
            <tr v-else-if="!assets.length"><td colspan="6" style="text-align:center; padding: 20px;">Tidak ada aset ditemukan.</td></tr>
            <tr v-else v-for="asset in assets" :key="asset.id">
              <td>
                <div class="asset-name">
                  <div class="asset-icon"><ShieldCheck :size="15" /></div>
                  <div>
                    <strong>{{ asset.product?.name || 'Unknown' }}</strong>
                    <span>{{ asset.serial_number }}</span>
                  </div>
                </div>
              </td>
              <td>{{ asset.product?.name || '-' }} ({{ asset.product?.sku || '-' }})</td>
              <td>{{ asset.warehouse_location?.warehouse?.name || '-' }} ({{ asset.warehouse_location?.code || '-' }})</td>
              <td><span class="status-pill" :class="getStatusTone(asset.status)">{{ asset.status }}</span></td>
              <td class="muted-cell">{{ formatDate(asset.updated_at) }}</td>
              <td style="position: relative;">
                <button class="dots-button" aria-label="Aksi aset" @click.stop="toggleDropdown($event, asset.id)">
                  <Ellipsis :size="16" />
                </button>
                <Teleport to="body">
                  <div v-if="activeDropdown === asset.id" :style="{ top: dropdownY + 'px', left: dropdownX + 'px' }" style="position: fixed; background: white; border: 1px solid #eeedf3; border-radius: 8px; padding: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 9999; min-width: 150px;" @click.stop>
                    <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #48445c; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fafafd'" onmouseout="this.style.background='none'" @click="openEdit(asset)">Edit Aset</button>
                    <div style="height: 1px; background: #eeedf3; margin: 4px 0;"></div>
                    <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #d47770; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fff2f2'" onmouseout="this.style.background='none'" @click="confirmRemoveAsset(asset)">Hapus</button>
                  </div>
                </Teleport>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <div class="products-pagination">
        <span>Menampilkan {{ from }}–{{ to }} dari {{ total }} aset</span>
        <div>
          <button class="pagination-button" :disabled="page <= 1" @click="goTo(page - 1)">Sebelumnya</button>
          <button class="pagination-button" v-for="n in pages" :key="n" :class="{ 'active': n === page }" @click="goTo(n)">{{ n }}</button>
          <button class="pagination-button" :disabled="page >= lastPage" @click="goTo(page + 1)">Berikutnya</button>
        </div>
      </div>
    </div>
    
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showScanner" class="modal-backdrop scanner-backdrop" @click="stopScanner">
          <div class="modal-content scanner-card" @click.stop>
            <div class="modal-header">
              <h2>Scan Nomor Seri</h2>
              <p>Arahkan kamera ke barcode atau QR code pada perangkat.</p>
            </div>
            <div id="sn-scanner-region" class="scanner-region"></div>
            <div v-if="cameraList.length > 1" class="scanner-actions" style="padding-bottom:12px">
              <select v-model="selectedCameraId" @change="runCamera" style="width:100%;padding:8px;border:1px solid #dcd8ec;border-radius:8px">
                <option v-for="c in cameraList" :key="c.id" :value="c.id">{{ c.label || c.id }}</option>
              </select>
            </div>
            <div v-if="scannerError" class="field-error scanner-error">{{ scannerError }}</div>
            <div class="scanner-actions">
              <label class="sn-scan-btn" style="padding:8px 16px;margin-right:auto">
                <ScanLine :size="16" /> Scan dari Foto
                <input type="file" accept="image/*" capture="environment" hidden @change="scanFromFile" />
              </label>
              <button type="button" class="sn-scan-btn" @click="stopScanner"><X :size="16" /> Tutup</button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showAddModal" class="modal-backdrop" @click="showAddModal = false">
          <div class="modal-content" @click.stop>
            <div class="modal-header">
              <h2>{{ editingId ? 'Edit Aset' : 'Daftarkan Aset Baru' }}</h2>
              <p>{{ editingId ? 'Ubah informasi aset di bawah ini.' : 'Tambahkan peralatan berseri tunggal ke inventaris.' }}</p>
            </div>
            
            <form @submit.prevent="saveAsset" class="modern-form">
              <div v-if="formErrors.general" class="field-error" style="margin-bottom: 10px; font-size: 12px; background: #fffafb; padding: 10px; border: 1px solid #d47770; border-radius: 6px;">{{ formErrors.general[0] }}</div>
              
              <div class="form-group">
                <label>Nomor Seri (S/N) <span class="req">*</span></label>
                <div class="sn-input-row">
                  <input v-model="form.serial_number" type="text" placeholder="Ketik atau scan S/N..." :class="{ 'input-error': formErrors.serial_number }" />
                  <button type="button" class="sn-scan-btn" title="Scan dengan kamera" @click="startScanner"><ScanLine :size="16" /> Scan</button>
                </div>
                <small v-if="formErrors.serial_number" class="field-error">{{ formErrors.serial_number[0] }}</small>
              </div>

              <div class="form-group">
                <label>Produk (Tipe Peralatan) <span class="req">*</span></label>
                <div class="select-wrapper">
                  <select v-model="form.product_id" :class="{ 'input-error': formErrors.product_id }">
                    <option value="" disabled>Pilih Produk...</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} ({{ p.sku }})</option>
                  </select>
                  <ChevronDown :size="15" class="select-icon" />
                </div>
                <small v-if="formErrors.product_id" class="field-error">{{ formErrors.product_id[0] }}</small>
              </div>
              
              <div class="form-row">
                <div class="form-group">
                  <label>Gudang Lokasi <span class="req">*</span></label>
                  <div class="select-wrapper">
                    <select v-model="form.warehouse_id" :class="{ 'input-error': formErrors.warehouse_id || formErrors.warehouse_location_id }">
                      <option value="" disabled>Pilih Gudang...</option>
                      <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                    </select>
                    <ChevronDown :size="15" class="select-icon" />
                  </div>
                </div>
                <div class="form-group">
                  <label>Rak / Zona <span class="req">*</span></label>
                  <div class="select-wrapper">
                    <select v-model="form.warehouse_location_id" :disabled="!form.warehouse_id || !locations.length" :class="{ 'input-error': formErrors.warehouse_location_id }">
                      <option value="" disabled>{{ !form.warehouse_id ? 'Pilih gudang dulu' : (locations.length === 0 ? 'Tidak ada zona' : 'Pilih Zona...') }}</option>
                      <option v-for="loc in locations" :key="loc.id" :value="loc.id">{{ loc.name || loc.code }}</option>
                    </select>
                    <ChevronDown :size="15" class="select-icon" />
                  </div>
                  <small v-if="formErrors.warehouse_location_id" class="field-error">{{ formErrors.warehouse_location_id[0] }}</small>
                </div>
              </div>
              
              <div class="form-row">
                <div class="form-group">
                  <label>Status <span class="req">*</span></label>
                  <div class="select-wrapper">
                    <select v-model="form.status" :class="{ 'input-error': formErrors.status }">
                      <option value="In stock">In stock (Tersedia)</option>
                      <option value="Assigned">Assigned (Ditugaskan)</option>
                      <option value="Maintenance">Maintenance (Pemeliharaan)</option>
                    </select>
                    <ChevronDown :size="15" class="select-icon" />
                  </div>
                  <small v-if="formErrors.status" class="field-error">{{ formErrors.status[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Keterangan <span style="font-weight: 400; text-transform: none;">(Opsional)</span></label>
                  <input v-model="form.remarks" type="text" placeholder="Catatan tambahan..." :class="{ 'input-error': formErrors.remarks }" />
                  <small v-if="formErrors.remarks" class="field-error">{{ formErrors.remarks[0] }}</small>
                </div>
              </div>
              
              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showAddModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving">{{ saving ? 'Menyimpan...' : (editingId ? 'Simpan' : 'Daftarkan Aset') }}</button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showDeleteConfirm" class="modal-backdrop" @click="showDeleteConfirm = false">
          <div class="modal-content" style="max-width: 360px; text-align: center; padding: 32px 28px;" @click.stop>
            <div style="width: 52px; height: 52px; border-radius: 50%; background: #fff2f2; color: #d47770; display: grid; place-items: center; margin: 0 auto 18px;">
              <AlertTriangle :size="24" />
            </div>
            <h2 style="font-size: 16px; color: #292541; margin: 0 0 10px;">Hapus Aset?</h2>
            <p style="color: #555164; font-size: 13px; margin: 0 0 24px; line-height: 1.6;">
              Anda yakin ingin menghapus aset dengan nomor seri <strong style="color: #292541; font-weight: 600;">{{ assetToDelete?.serial_number }}</strong>? Tindakan ini tidak dapat dibatalkan.
            </p>
            <div style="display: flex; gap: 12px; justify-content: center;">
              <button class="cancel-button" style="flex: 1; font-weight: 500;" @click="showDeleteConfirm = false" :disabled="deleting">Batal</button>
              <button class="primary-button" style="flex: 1; justify-content: center;" @click="removeAsset" :disabled="deleting">{{ deleting ? 'Menghapus...' : 'Ya, Hapus' }}</button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="fade">
        <div v-if="toastMessage" class="toast-notification" :class="toastType">
          {{ toastMessage }}
        </div>
      </Transition>
    </Teleport>
  </section>
</template>

<style scoped>
/* Modal Transition */
.fade-enter-active,.fade-leave-active{transition:opacity .25s ease}.fade-enter-from,.fade-leave-to{opacity:0}
.fade-enter-active .modal-content{transition:all .3s cubic-bezier(.34,1.56,.64,1)}.fade-enter-from .modal-content{transform:translateY(20px) scale(.95);opacity:0}.fade-leave-to .modal-content{transform:translateY(10px);opacity:0}

/* Modal Styles */
.modal-backdrop{position:fixed;inset:0;background:rgba(41,37,65,.4);backdrop-filter:blur(4px);display:flex;justify-content:center;align-items:center;z-index:10000}
.modal-content{background:#fff;border-radius:14px;width:100%;max-width:520px;box-shadow:0 20px 40px rgba(0,0,0,.1),0 1px 3px rgba(0,0,0,.05);overflow:hidden}
.modal-header{position:relative;padding:24px 28px 18px;border-bottom:1px solid #f0eff3}.modal-header h2{font-size:16px;color:#292541;margin:0}.modal-header p{color:#aaa7b4;font-size:11px;margin:6px 0 0}
.modern-form{padding:24px 28px;display:flex;flex-direction:column;gap:18px}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group label{display:block;font-size:10px;font-weight:600;color:#858291;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.modern-form input[type=text],.modern-form input[type=number],.modern-form input[type=date],.modern-form select{width:100%;background:#fafafd;border:1px solid #e9e7ef;border-radius:8px;padding:10px 14px;font-size:12px;color:#48445c;outline:none;transition:all .2s;box-sizing:border-box}
.modern-form input:focus,.modern-form select:focus{border-color:#bd3c3c;background:#fff;box-shadow:0 0 0 3px rgba(189,60,60,.1)}
.modern-form .input-error{border-color:#d47770;background:#fffafb}.modern-form .input-error:focus{box-shadow:0 0 0 3px rgba(212,119,112,.15)}
.modern-form input::placeholder{color:#aaa7b4}
.modern-form select{appearance:none;padding-right:36px;color:#48445c}
.select-wrapper{position:relative;width:100%}
.select-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#aaa7b4;pointer-events:none}
.req{color:#d47770}
.field-error{display:block;color:#d47770;font-size:10px;margin-top:5px}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:10px;padding-top:18px;border-top:1px solid #f0eff3}
.cancel-button{background:#fff;border:1px solid #e9e7ef;color:#777486;padding:10px 16px;border-radius:7px;font-size:11px;cursor:pointer;transition:all .2s}.cancel-button:hover{background:#fafafd;color:#48445c}

.assets-page { display:block; }
.asset-summary { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:18px; }
.asset-summary > div { background:#fff; border:1px solid #eeedf3; border-radius:10px; padding:15px 18px; }
.asset-summary span,.asset-summary strong,.asset-summary small { display:block; }
.asset-summary span { color:#858291; font-size:11px; }
.asset-summary strong { font-size:22px; margin-top:9px; letter-spacing:-.04em; }
.asset-summary small { color:#aaa7b4; font-size:9px; margin-top:6px; }
.asset-summary .positive { color:#3ca579; }
.assigned-text { color:#bd3c3c; }
.warning-text { color:#c48735; }

.custom-header { display: flex; flex-direction: column; gap: 24px; padding: 24px 28px 18px; border-bottom: 1px solid #f0eff3; }
.header-top { display: flex; justify-content: space-between; align-items: center; gap: 16px; width: 100%; }
.header-top h2 { font-size: 15px; color: #48445c; margin: 0 0 6px; letter-spacing: -0.01em; }
.header-top p { margin: 0; color: #aaa7b4; font-size: 11px; }
.header-filters { display: flex; gap: 12px; align-items: center; margin-left: auto; }
.rsv-search, .select-wrap { position: relative; }
.rsv-search input { width: 240px; padding: 8px 12px 8px 32px; border: 1px solid #e9e7ef; border-radius: 6px; font-size: 11px; background: #fafafd; outline: none; color: #48445c; transition: all 0.2s; font-family: inherit; }
.rsv-search input:focus { border-color: #d0ced8; background: #fff; }
.rsv-search input::placeholder { color: #c4c1cd; }
.rsv-search .icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #aaa7b4; }
.select-wrap select { width: 150px; padding: 8px 28px 8px 12px; border: 1px solid #e9e7ef; border-radius: 6px; font-size: 11px; background: #fafafd; color: #777486; appearance: none; outline: none; transition: all 0.2s; cursor: pointer; font-family: inherit; }
.select-wrap select:focus { border-color: #d0ced8; background: #fff; }
.select-wrap .icon { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #aaa7b4; pointer-events: none; }

.tabs-row { display: flex; align-items: center; justify-content: space-between; width: 100%; }
.tab-button { background: none; border: none; color: #aaa7b4; font-size: 11px; font-weight: 600; padding: 6px 14px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s; font-family: inherit; }
.tab-button:hover { color: #48445c; background: #fafafd; }
.tab-button.active { background: #fff2f2; color: #bd3c3c; }
.tab-button.active span { background: transparent; color: #bd3c3c; font-size: 10px; font-weight: 700; margin-left: 2px; }

.assets-panel { overflow:hidden; }
.assets-table { min-width:980px; width:100%; border-collapse:collapse; font-size:10px; }
.assets-table th,.assets-table td { padding-left:19px; padding-right:19px; }
.assets-table th { text-align:left; color:#aaa7b4; font-size:9px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; background:#fafafd; padding-top:10px; padding-bottom:10px; border-top:1px solid #f0eff3; border-bottom:1px solid #f0eff3; }
.assets-table td { padding-top:13px; padding-bottom:13px; border-bottom:1px solid #f3f2f6; white-space:nowrap; color:#555164; }
.assets-table tr:last-child td { border-bottom:0; }
.asset-name { display:flex; align-items:center; gap:10px; }
.asset-name strong,.asset-name span { display:block; }
.asset-name strong { color:#292541; font-size:11px; }
.asset-name span { color:#aaa7b4; font-size:9px; margin-top:4px; }
.asset-icon { width:28px; height:28px; display:grid; place-items:center; color:#bd3c3c; background:#fff2f2; border-radius:7px; }
.status-pill { padding:4px 7px; border-radius:4px; font-size:9px; }
.status-pill.success { color:#3b9d76; background:#eaf8f1; }
.status-pill.assigned { color:#bd3c3c; background:#fff2f2; }
.status-pill.warning { color:#c48735; background:#fff4df; }
.status-pill.neutral { color:#777486; background:#f0eff3; }
.muted-cell { color:#aaa7b2; }
.dots-button { color:#aaa7b5; background:none; border:0; cursor:pointer; }
.products-pagination { display:flex; align-items:center; justify-content:space-between; padding:14px 19px; color:#aaa7b4; font-size:10px; border-top: 1px solid #f0eff3; }
.products-pagination > div { display:flex; gap:4px; }
.pagination-button { border:1px solid #e9e7ef; background:#fff; color:#858291; border-radius:5px; padding:6px 9px; font-size:10px; cursor:pointer; }
.pagination-button:disabled { opacity: 0.5; cursor: not-allowed; }
.pagination-button.active { color:#fff; background:#bd3c3c; border-color:#bd3c3c; }

@media (max-width:700px) {
  .asset-summary { grid-template-columns:repeat(2,1fr); }
  .asset-summary > div { padding:14px; }
  .asset-summary strong { font-size:19px; }
  .header-top { flex-direction:column; align-items:flex-start; }
  .header-filters { margin-left:0; width:100%; flex-direction:column; align-items:stretch; }
  .rsv-search input, .select-wrap select { width:100%; }
  .products-pagination { align-items:flex-start; gap:12px; flex-direction:column; padding:14px; }
}
.sn-input-row{display:flex;gap:8px;align-items:stretch}
.sn-input-row input{flex:1;min-width:0}
.sn-scan-btn{display:inline-flex;align-items:center;gap:6px;padding:0 14px;height:auto;border:1px solid #dcd8ec;border-radius:8px;background:linear-gradient(135deg,#f4f1ff,#ebe6ff);color:#4b3fa0;font-weight:600;font-size:13px;cursor:pointer;transition:all .2s}
.sn-scan-btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(91,75,201,.2)}
.scanner-backdrop{z-index:10001}
.scanner-card{max-width:440px;width:92vw}
.scanner-region{width:100%;min-height:240px;border-radius:12px;overflow:hidden;background:#14122b;margin:12px 24px;width:calc(100% - 48px)}
.scanner-region video{width:100%!important;object-fit:cover}
.scanner-error{margin-bottom:10px;font-size:12px;background:#fffafb;padding:10px;border:1px solid #d47770;border-radius:6px}
.scanner-actions{display:flex;justify-content:flex-end;padding:0 24px 20px}
.scanner-error{margin-left:24px;margin-right:24px}
.scanner-actions .sn-scan-btn{padding:8px 16px}

/* Toast */
.toast-notification { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 8px; font-size: 12px; font-weight: 500; z-index: 10001; box-shadow: 0 10px 30px rgba(0,0,0,0.1); color: #fff; max-width: 300px; display: flex; align-items: center; gap: 10px; }
.toast-notification.success { background: #292541; }
.toast-notification.error { background: #d47770; }
.toast-notification.warning { background: #c48735; }
</style>
