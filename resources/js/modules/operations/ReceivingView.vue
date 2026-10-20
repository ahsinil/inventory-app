<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { Plus, Search, Ellipsis, AlertTriangle, ArrowDownToLine, PackageOpen, CheckCircle2, Trash2, Eye, Building2, Warehouse as WarehouseIcon, Calendar, Check, X, ScanLine } from 'lucide-vue-next'
import DateRangePicker from '../../components/DateRangePicker.vue'
import ProductCombobox from '../../components/forms/ProductCombobox.vue'
import CameraScannerModal from '../../components/forms/CameraScannerModal.vue'
import AppPagination from '../../components/ui/AppPagination.vue'
import AppSortableTh from '../../components/ui/AppSortableTh.vue'

interface Product { id: string; name: string; sku: string; has_serial_number: boolean; unit?: { code: string } }
interface Warehouse { id: string; name: string; code: string }
interface Supplier { id: string; name: string }
interface ReceiptItem {
  id: string
  product_id: string
  expected_quantity: number
  received_quantity: number
  product: Product
}
interface Receipt {
  id: string
  receipt_number: string
  warehouse_id: string
  supplier_id: string | null
  status: string
  expected_date: string | null
  received_date: string | null
  remarks: string | null
  warehouse: Warehouse
  items: ReceiptItem[]
}

const receipts = ref<Receipt[]>([])
const warehouses = ref<Warehouse[]>([])
const suppliers = ref<Supplier[]>([])
const products = ref<Product[]>([])

const loading = ref(false)
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const fromItem = ref(0)
const toItem = ref(0)
const dateFrom = ref('')
const dateTo = ref('')
const sortBy = ref('')
const sortDir = ref<'asc' | 'desc'>('asc')
let searchTimer: ReturnType<typeof setTimeout> | null = null

const toastMessage = ref('')
const toastType = ref('error')
const showToast = (msg: string, type: string = 'error') => {
  toastMessage.value = msg
  toastType.value = type
  setTimeout(() => { toastMessage.value = '' }, 3000)
}

const onSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchReceipts() }, 300)
}

const onFilterChange = () => {
  page.value = 1
  fetchReceipts()
}

const pages = computed(() => Array.from({ length: lastPage.value }, (_, i) => i + 1))

const fetchInitialData = async () => {
  try {
    const [whRes, supRes, prdRes] = await Promise.all([
      axios.get('/warehouses', { params: { per_page: 100 } }),
      axios.get('/suppliers', { params: { per_page: 100 } }),
      axios.get('/products', { params: { per_page: 200 } })
    ])
    warehouses.value = whRes.data.data.data || whRes.data.data
    suppliers.value = supRes.data.data.data || supRes.data.data
    products.value = prdRes.data.data.data || prdRes.data.data
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat data awal', 'error')
  }
}

const fetchReceipts = async () => {
  loading.value = true
  try {
    const res = await axios.get('/receipts', { 
      params: { 
        page: page.value,
        search: search.value || undefined,
        start_date: dateFrom.value || undefined,
        end_date: dateTo.value || undefined,
        sort_by: sortBy.value || undefined,
        sort_dir: sortBy.value ? sortDir.value : undefined
      } 
    })
    const data = res.data.data
    receipts.value = data.data
    lastPage.value = data.last_page
    total.value = data.total
    fromItem.value = data.from ?? 0
    toItem.value = data.to ?? 0
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat penerimaan', 'error')
  } finally {
    loading.value = false
  }
}

const handleSort = (field: string, dir: 'asc' | 'desc') => {
  sortBy.value = field
  sortDir.value = dir
  page.value = 1
  fetchReceipts()
}

const exportCSV = () => {
  if (!receipts.value.length) {
    showToast('Tidak ada data untuk diekspor', 'warning')
    return
  }
  
  const headers = ['Nomor', 'Gudang', 'Pemasok', 'Status', 'Tgl Diharapkan', 'Tgl Diterima', 'Catatan']
  const rows = receipts.value.map(r => [
    `"${r.receipt_number || ''}"`,
    `"${r.warehouse?.name || ''}"`,
    `"${r.supplier_id ? suppliers.value.find(s => s.id === r.supplier_id)?.name || '-' : '-'}"`,
    `"${r.status || ''}"`,
    `"${formatDate(r.expected_date)}"`,
    `"${formatDate(r.received_date)}"`,
    `"${r.remarks || ''}"`
  ])
  
  const csvContent = "data:text/csv;charset=utf-8," + 
    [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
    
  const encodedUri = encodeURI(csvContent)
  const link = document.createElement('a')
  link.setAttribute('href', encodedUri)
  link.setAttribute('download', `Penerimaan_Stok_${new Date().toISOString().slice(0,10)}.csv`)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const goTo = (p: number) => {
  if (p < 1 || p > lastPage.value) return
  page.value = p
  fetchReceipts()
}

// Dropdown
const activeDropdown = ref<string | null>(null)
const dropdownX = ref(0)
const dropdownY = ref(0)
const toggleDropdown = (e: MouseEvent, id: string) => {
  if (activeDropdown.value === id) { activeDropdown.value = null; return }
  activeDropdown.value = id
  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
  dropdownX.value = rect.left - 120
  dropdownY.value = rect.bottom + 5
}
const closeDropdown = () => { activeDropdown.value = null }

// CREATE MODAL
const showCreateModal = ref(false)
const saving = ref(false)
const createErrors = ref<Record<string, string[]>>({})
const createForm = ref({ warehouse_id: '', supplier_id: '', expected_date: '', remarks: '', items: [{ product_id: '', expected_quantity: 1 }] })

const openCreate = () => {
  createForm.value = { warehouse_id: '', supplier_id: '', expected_date: '', remarks: '', items: [{ product_id: '', expected_quantity: 1 }] }
  createErrors.value = {}
  showCreateModal.value = true
}
const addCreateItem = () => { createForm.value.items.push({ product_id: '', expected_quantity: 1 }) }
const removeCreateItem = (idx: number) => { 
  if (createForm.value.items.length > 1) {
    createForm.value.items.splice(idx, 1) 
  } else {
    createForm.value.items[0] = { product_id: '', expected_quantity: 1 }
  }
}

const submitCreate = async () => {
  saving.value = true
  createErrors.value = {}
  if (!createForm.value.warehouse_id) { 
    createErrors.value.warehouse_id = ['Pilih gudang tujuan.']
    saving.value = false
    return 
  }
  if (createForm.value.items.length === 0) { 
    createErrors.value.items = ['Tambahkan minimal 1 barang.']
    saving.value = false
    return 
  }
  const hasEmptyProduct = createForm.value.items.some(i => !i.product_id)
  if (hasEmptyProduct) {
    createErrors.value.items = ['Pastikan setiap baris telah memilih barang.']
    saving.value = false
    return
  }
  const hasInvalidQty = createForm.value.items.some(i => !i.expected_quantity || Number(i.expected_quantity) <= 0)
  if (hasInvalidQty) {
    createErrors.value.items = ['Jumlah Qty setiap barang harus lebih dari 0.']
    saving.value = false
    return
  }

  const payload = {
    ...createForm.value,
    supplier_id: createForm.value.supplier_id || null,
    expected_date: createForm.value.expected_date || null
  }
  try {
    await axios.post('/receipts', payload)
    showCreateModal.value = false
    showToast('Draft penerimaan berhasil dibuat', 'success')
    page.value = 1
    await fetchReceipts()
  } catch (e: any) {
    createErrors.value = e.response?.data?.errors ?? { general: [e.response?.data?.message || 'Gagal menyimpan.'] }
    showToast('Gagal membuat penerimaan', 'error')
  } finally {
    saving.value = false
  }
}

// DETAIL MODAL
const showDetailModal = ref(false)
const loadingDetail = ref(false)
const detailReceipt = ref<any | null>(null)

const openDetail = async (r: Receipt) => {
  closeDropdown()
  showDetailModal.value = true
  loadingDetail.value = true
  detailReceipt.value = r

  try {
    const res = await axios.get(`/receipts/${r.id}`)
    detailReceipt.value = res.data.data
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat rincian penerimaan', 'error')
  } finally {
    loadingDetail.value = false
  }
}

// POST (PROCESS) MODAL
const showPostModal = ref(false)
const posting = ref(false)
const postErrors = ref<string>('')
const activeReceipt = ref<Receipt | null>(null)
const postForm = ref<{ items: { product_id: string; received_quantity: number; serial_numbers: string; has_sn: boolean }[] }>({ items: [] })

// Scanner kamera untuk nomor seri
const showScanner = ref(false)
const activeScannerItem = ref<{ product_id: string; received_quantity: number; serial_numbers: string; has_sn: boolean } | null>(null)

const openScannerForItem = (item: any) => {
  activeScannerItem.value = item
  showScanner.value = true
}

const handleScannerComplete = (serials: string[]) => {
  if (activeScannerItem.value) {
    activeScannerItem.value.serial_numbers = serials.join(', ')
    showToast(`${serials.length} nomor seri berhasil dimasukkan`, 'success')
  }
}

const openPost = async (r: Receipt) => {
  closeDropdown()
  activeReceipt.value = r
  postErrors.value = ''
  postForm.value.items = []
  
  try {
    const res = await axios.get(`/receipts/${r.id}`)
    const fullReceipt = res.data.data
    postForm.value.items = fullReceipt.items.map((i: any) => ({
      product_id: i.product_id,
      received_quantity: Number(i.expected_quantity), // default to expected
      serial_numbers: '',
      has_sn: !!i.product?.has_serial_number
    }))
    showPostModal.value = true
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat detail penerimaan', 'error')
  }
}

const submitPost = async () => {
  if (!activeReceipt.value) return
  posting.value = true
  postErrors.value = ''

  const payload = {
    items: postForm.value.items.map(i => ({
      product_id: i.product_id,
      received_quantity: i.received_quantity,
      serial_numbers: i.has_sn ? i.serial_numbers.split(',').map(s => s.trim()).filter(Boolean) : []
    }))
  }

  try {
    await axios.post(`/receipts/${activeReceipt.value.id}/post`, payload)
    showPostModal.value = false
    showToast('Penerimaan berhasil diproses', 'success')
    await fetchReceipts()
  } catch (e: any) {
    postErrors.value = e.response?.data?.message || 'Gagal memproses penerimaan.'
    showToast(postErrors.value, 'error')
  } finally {
    posting.value = false
  }
}

const formatDate = (d: string | null) => {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' })
}

onMounted(() => {
  document.addEventListener('click', closeDropdown)
  fetchInitialData().then(fetchReceipts)
})
onUnmounted(() => {
  document.removeEventListener('click', closeDropdown)
})
</script>

<template>
  <section class="receiving-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">OPERASI / PENERIMAAN BARANG</p>
        <h1>Penerimaan Barang</h1>
        <p class="muted">Catat barang masuk dari pemasok ke gudang Anda.</p>
      </div>
      <button class="primary-button" @click="openCreate"><Plus :size="16" /> Buat Penerimaan</button>
    </div>

    <div class="panel">
      <div class="panel-header"></div>
      <div style="padding: 0 19px 17px; display: flex; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 8px; color: #aaa7b5; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 0 10px; height: 34px; max-width: 320px; width: 100%;">
          <Search :size="15" />
          <input v-model="search" @input="onSearch" placeholder="Cari nomor, gudang, supplier..." style="width: 100%; border: 0; outline: 0; background: transparent; color: #48445c; font-size: 11px;" />
        </div>
        <DateRangePicker 
          :from="dateFrom" 
          :to="dateTo" 
          @update:from="dateFrom = $event" 
          @update:to="dateTo = $event" 
          @change="onFilterChange" 
        />
      </div>

      <p v-if="loading && !receipts.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px; text-align: center;">Memuat riwayat penerimaan...</p>
      <p v-else-if="!receipts.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px; text-align: center;">Belum ada riwayat penerimaan barang.</p>

      <div v-if="receipts.length" class="table-scroll" style="overflow-x: auto;">
        <table class="modern-table" style="width: 100%; min-width: 900px; border-collapse: collapse; font-size: 11px;">
          <thead>
            <tr>
              <AppSortableTh field="receipt_number" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Nomor / Gudang</AppSortableTh>
              <AppSortableTh field="supplier" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Pemasok</AppSortableTh>
              <AppSortableTh field="expected_date" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Tgl Estimasi / Terima</AppSortableTh>
              <AppSortableTh field="status" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Status</AppSortableTh>
              <th style="padding: 10px 19px; text-align: right; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in receipts" :key="item.id" style="border-bottom: 1px solid #f0eff3;">
              <td style="padding: 12px 19px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                  <div style="width: 32px; height: 32px; border-radius: 8px; background: #f0eff3; color: #555164; display: grid; place-items: center;"><ArrowDownToLine :size="16" /></div>
                  <div style="cursor: pointer;" @click="openDetail(item)" title="Klik untuk melihat detail penerimaan">
                    <strong style="display: block; font-size: 12px; color: #292541; margin-bottom: 2px;" class="receipt-link">{{ item.receipt_number }}</strong>
                    <span style="font-size: 10px; color: #aaa7b4;">{{ item.warehouse?.name }}</span>
                  </div>
                </div>
              </td>
              <td style="padding: 12px 19px; color: #555164;">
                {{ suppliers.find(s => s.id === item.supplier_id)?.name || item.supplier?.name || '-' }}
              </td>
              <td style="padding: 12px 19px; color: #555164;">
                <div style="display: flex; flex-direction: column; gap: 4px;">
                  <span style="color: #858291;">Est: {{ formatDate(item.expected_date) }}</span>
                  <span style="color: #292541;">Rcv: {{ formatDate(item.received_date) }}</span>
                </div>
              </td>
              <td style="padding: 12px 19px;">
                <span :class="{ 'status-pill success': item.status === 'POSTED', 'status-pill warning': item.status === 'EXPECTED' }" style="padding: 4px 8px; border-radius: 4px; font-size: 9px; font-weight: 600;">
                  {{ item.status === 'POSTED' ? 'DITERIMA' : 'DIJADWALKAN' }}
                </span>
              </td>
              <td style="padding: 10px 19px; text-align: right;">
                <div style="display: flex; align-items: center; justify-content: flex-end; gap: 6px;">
                  <button 
                    type="button"
                    class="btn-detail-action" 
                    title="Lihat Detail Penerimaan"
                    @click.stop="openDetail(item)"
                  >
                    <Eye :size="13" />
                    <span>Detail</span>
                  </button>

                  <button 
                    v-if="item.status === 'EXPECTED'" 
                    type="button"
                    class="btn-process-action"
                    title="Proses Penerimaan Fisik"
                    @click.stop="openPost(item)"
                  >
                    <CheckCircle2 :size="13" />
                    <span>Proses</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <AppPagination
        :page="page"
        :last-page="lastPage"
        :total="total"
        :from="fromItem"
        :to="toItem"
        @change="goTo"
      />
    </div>

    <!-- MODAL CREATE -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showCreateModal" class="modal-backdrop" @click="showCreateModal = false">
          <div class="modal-content" style="max-width: 650px;" @click.stop>
            <div class="modal-header">
              <h2>Buat Penerimaan Baru</h2>
              <p>Rencanakan barang yang akan masuk ke gudang.</p>
            </div>
            <form @submit.prevent="submitCreate" class="modern-form" style="max-height: 80vh; overflow-y: auto;" novalidate>
              <p v-if="createErrors.general" class="field-error">{{ createErrors.general[0] }}</p>
              
              <div class="form-row">
                <div class="form-group">
                  <label>Tujuan Gudang <span class="req">*</span></label>
                  <select v-model="createForm.warehouse_id" :class="{ 'input-error': createErrors.warehouse_id }">
                    <option value="" disabled>Pilih Gudang...</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                  </select>
                  <small v-if="createErrors.warehouse_id" class="field-error">{{ createErrors.warehouse_id[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Pemasok (Opsional)</label>
                  <select v-model="createForm.supplier_id">
                    <option value="">Tanpa Pemasok</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                  </select>
                </div>
              </div>

              <div class="form-group">
                <label>Tanggal Estimasi Datang</label>
                <input v-model="createForm.expected_date" type="date" />
              </div>

              <div class="form-group" style="margin-top: 6px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 8px;">
                  <div>
                    <label style="margin-bottom: 2px;">Daftar Barang Diterima <span class="req">*</span></label>
                    <span style="font-size: 10px; color: #858291;">Ketik nama atau SKU untuk mencari barang</span>
                  </div>
                  <button type="button" @click="addCreateItem" class="btn-add-item">
                    <Plus :size="13" /> Tambah Baris
                  </button>
                </div>

                <div class="items-table-container">
                  <div class="items-header-row">
                    <span class="col-product">BARANG / PRODUK (KETIK NAMA ATAU SKU)</span>
                    <span class="col-qty">QTY</span>
                    <span class="col-action"></span>
                  </div>

                  <div
                    v-for="(item, idx) in createForm.items"
                    :key="idx"
                    class="item-row"
                  >
                    <div class="col-product">
                      <ProductCombobox
                        v-model="item.product_id"
                        :products="products"
                        :error="!!createErrors[`items.${idx}.product_id`] || (!!createErrors.items && !item.product_id)"
                        placeholder="Ketik nama produk atau SKU..."
                      />
                    </div>
                    <div class="col-qty">
                      <div class="qty-input-wrapper">
                        <input
                          v-model="item.expected_quantity"
                          type="number"
                          step="0.01"
                          min="0.01"
                          placeholder="0"
                          class="qty-field"
                        />
                        <span class="qty-unit">
                          {{ products.find(p => p.id === item.product_id)?.unit?.code || 'pcs' }}
                        </span>
                      </div>
                    </div>
                    <div class="col-action">
                      <button
                        type="button"
                        class="btn-remove-row"
                        title="Hapus baris"
                        @click="removeCreateItem(idx)"
                      >
                        <Trash2 :size="13" />
                      </button>
                    </div>
                  </div>
                </div>
                <small v-if="createErrors.items" class="field-error" style="margin-top: 6px;">{{ createErrors.items[0] }}</small>
              </div>

              <div class="form-group">
                <label>Catatan (Opsional)</label>
                <input v-model="createForm.remarks" type="text" placeholder="Referensi dokumen / memo..." />
              </div>

              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showCreateModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan Draft' }}</button>
              </div>
            </form>
          </div>
        </div>
      </Transition>

      <!-- MODAL POST / PROCESS -->
      <Transition name="fade">
        <div v-if="showPostModal" class="modal-backdrop" @click="showPostModal = false">
          <div class="modal-content" style="max-width: 600px;" @click.stop>
            <div class="modal-header">
              <h2>Proses Penerimaan Fisik</h2>
              <p>Pindai atau masukkan jumlah barang riil yang diterima untuk <strong>{{ activeReceipt?.receipt_number }}</strong>.</p>
            </div>
            <form @submit.prevent="submitPost" class="modern-form" style="max-height: 80vh; overflow-y: auto;" novalidate>
              <p v-if="postErrors" class="delete-error">{{ postErrors }}</p>
              
              <div v-for="(item, idx) in postForm.items" :key="idx" style="padding: 16px 18px; border: 1px solid #eeedf3; border-radius: 10px; margin-bottom: 12px; background: #fff; display: flex; flex-direction: column; gap: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                  <div>
                    <strong style="display: block; font-size: 13px; color: #292541; margin-bottom: 4px;">
                      {{ products.find(p => p.id === item.product_id)?.name }}
                    </strong>
                    <span v-if="item.has_sn" class="status-pill warning" style="font-size: 9px; display: inline-block;">Butuh SN</span>
                  </div>
                  
                  <div style="display: flex; align-items: center; gap: 8px; background: #fafafd; border: 1px solid #e9e7ef; padding: 6px 12px; border-radius: 8px; transition: all 0.2s;" onmouseover="this.style.borderColor='#bd3c3c'" onmouseout="this.style.borderColor='#e9e7ef'">
                    <input v-model="item.received_quantity" type="number" step="0.01" min="0" style="border: 0; background: transparent; width: 60px; text-align: right; outline: none; font-size: 14px; font-weight: 600; color: #292541; padding: 0;" />
                    <span style="color: #aaa7b4; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                      {{ products.find(p => p.id === item.product_id)?.unit?.code || 'pcs' }}
                    </span>
                  </div>
                </div>
                
                <div v-if="item.has_sn" style="border-top: 1px dashed #e9e7ef; padding-top: 12px;">
                  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-size: 10px; font-weight: 600; color: #858291; text-transform: uppercase; margin: 0;">Nomor Seri (Pisahkan dengan koma)</label>
                    <button
                      type="button"
                      class="sn-scan-btn-small"
                      title="Pindai nomor seri dengan kamera perangkat"
                      @click="openScannerForItem(item)"
                    >
                      <ScanLine :size="13" />
                      <span>Scan Kamera</span>
                    </button>
                  </div>
                  <input v-model="item.serial_numbers" type="text" placeholder="Contoh: SN-001, SN-002, SN-003" style="width: 100%; padding: 10px 14px; border: 1px solid #e9e7ef; border-radius: 8px; font-size: 12px; background: #fafafd; outline: none; color: #48445c;" />
                  <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px;">
                    <small style="color: #aaa7b4; font-size: 9px;">Jumlah seri harus persis sama dengan Qty Diterima.</small>
                    <small
                      v-if="item.serial_numbers"
                      style="font-size: 9px; font-weight: 600;"
                      :style="{ color: item.serial_numbers.split(',').map((s: string) => s.trim()).filter(Boolean).length === Number(item.received_quantity) ? '#3b9d76' : '#bd3c3c' }"
                    >
                      {{ item.serial_numbers.split(',').map((s: string) => s.trim()).filter(Boolean).length }} / {{ item.received_quantity }} terisi
                    </small>
                  </div>
                </div>
              </div>

              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showPostModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="posting" style="background: #3b9d76; border-color: #3b9d76;">{{ posting ? 'Memproses...' : 'Selesaikan Penerimaan' }}</button>
              </div>
            </form>
          </div>
        </div>
      </Transition>

      <!-- MODAL DETAIL PENERIMAAN -->
      <Transition name="fade">
        <div v-if="showDetailModal" class="modal-backdrop" @click="showDetailModal = false">
          <div class="modal-content" style="max-width: 720px; width: 95%;" @click.stop>
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center;">
              <div>
                <div style="display: flex; align-items: center; gap: 10px;">
                  <h2>{{ detailReceipt?.receipt_number || 'Detail Penerimaan' }}</h2>
                  <span 
                    :class="{ 'status-pill success': detailReceipt?.status === 'POSTED', 'status-pill warning': detailReceipt?.status === 'EXPECTED' }" 
                    style="padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.04em;"
                  >
                    {{ detailReceipt?.status === 'POSTED' ? 'SUDAH DITERIMA' : 'DIJADWALKAN' }}
                  </span>
                </div>
                <p style="margin-top: 4px; font-size: 11px; color: #858291;">
                  Rincian data barang masuk, kuantitas fisik riil, dan tujuan gudang.
                </p>
              </div>
              <button 
                type="button" 
                class="btn-close-modal" 
                title="Tutup" 
                @click="showDetailModal = false"
              >
                <X :size="18" />
              </button>
            </div>

            <div class="modal-body-scroll" style="max-height: 75vh; overflow-y: auto; padding: 22px 28px;">
              <div v-if="loadingDetail && !detailReceipt?.items?.length" style="padding: 40px; text-align: center; color: #aaa7b4; font-size: 12px;">
                Memuat rincian penerimaan...
              </div>

              <div v-else style="display: flex; flex-direction: column; gap: 20px;">
                <!-- Summary Info Cards Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                  <div class="detail-info-card">
                    <span class="detail-card-label">
                      <WarehouseIcon :size="13" /> Gudang Tujuan
                    </span>
                    <strong class="detail-card-value">
                      {{ detailReceipt?.warehouse?.name || '-' }}
                    </strong>
                    <span v-if="detailReceipt?.warehouse?.code" class="detail-card-sub">
                      Kode: {{ detailReceipt.warehouse.code }}
                    </span>
                  </div>

                  <div class="detail-info-card">
                    <span class="detail-card-label">
                      <Building2 :size="13" /> Pemasok / Vendor
                    </span>
                    <strong class="detail-card-value">
                      {{ detailReceipt?.supplier?.name || suppliers.find(s => s.id === detailReceipt?.supplier_id)?.name || 'Tanpa Pemasok' }}
                    </strong>
                    <span v-if="detailReceipt?.supplier?.email" class="detail-card-sub">
                      {{ detailReceipt.supplier.email }}
                    </span>
                  </div>

                  <div class="detail-info-card">
                    <span class="detail-card-label">
                      <Calendar :size="13" /> Tanggal
                    </span>
                    <strong class="detail-card-value">
                      {{ detailReceipt?.status === 'POSTED' ? (formatDate(detailReceipt?.received_date) || '-') : (formatDate(detailReceipt?.expected_date) || '-') }}
                    </strong>
                    <span class="detail-card-sub">
                      {{ detailReceipt?.status === 'POSTED' ? 'Tanggal Barang Diterima' : 'Estimasi Barang Datang' }}
                    </span>
                  </div>
                </div>

                <!-- Remarks / Notes if present -->
                <div v-if="detailReceipt?.remarks" style="background: #fafafd; border: 1px dashed #e4e2ee; border-radius: 8px; padding: 10px 14px; font-size: 11px; color: #555164;">
                  <strong style="color: #292541; margin-right: 6px;">Catatan / Referensi:</strong>
                  {{ detailReceipt.remarks }}
                </div>

                <!-- Items Section -->
                <div>
                  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="font-size: 12px; font-weight: 700; color: #292541; text-transform: uppercase; letter-spacing: 0.04em; margin: 0;">
                      Daftar Barang ({{ detailReceipt?.items?.length || 0 }} Item)
                    </h3>
                  </div>

                  <div class="detail-table-wrapper">
                    <table class="detail-table">
                      <thead>
                        <tr>
                          <th style="width: 36px; text-align: center;">No</th>
                          <th>Produk / Barang</th>
                          <th style="text-align: right; width: 120px;">Qty Diharapkan</th>
                          <th style="text-align: right; width: 120px;">Qty Diterima</th>
                          <th style="width: 80px; text-align: center;">Satuan</th>
                          <th style="width: 100px; text-align: center;">Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(it, idx) in detailReceipt?.items" :key="it.id || idx">
                          <td style="text-align: center; color: #aaa7b4; font-size: 10px;">{{ idx + 1 }}</td>
                          <td>
                            <div style="display: flex; flex-direction: column; gap: 2px;">
                              <span style="font-size: 12px; font-weight: 600; color: #292541;">
                                {{ it.product?.name || products.find(p => p.id === it.product_id)?.name || 'Produk' }}
                              </span>
                              <div style="display: flex; align-items: center; gap: 6px;">
                                <span class="sku-tag" style="font-size: 9px;">
                                  {{ it.product?.sku || products.find(p => p.id === it.product_id)?.sku || '-' }}
                                </span>
                                <span v-if="it.product?.has_serial_number" class="sn-badge" style="font-size: 8px; padding: 1px 4px;">
                                  Serial Number
                                </span>
                              </div>
                            </div>
                          </td>
                          <td style="text-align: right; font-weight: 600; color: #777486; font-size: 12px;">
                            {{ Number(it.expected_quantity) }}
                          </td>
                          <td style="text-align: right; font-weight: 700; font-size: 13px;" :style="{ color: detailReceipt?.status === 'POSTED' ? '#3b9d76' : '#292541' }">
                            {{ it.received_quantity !== null && it.received_quantity !== undefined ? Number(it.received_quantity) : '-' }}
                          </td>
                          <td style="text-align: center;">
                            <span class="unit-badge">
                              {{ it.product?.unit?.code || products.find(p => p.id === it.product_id)?.unit?.code || 'pcs' }}
                            </span>
                          </td>
                          <td style="text-align: center;">
                            <span 
                              v-if="detailReceipt?.status === 'POSTED'" 
                              class="status-pill success" 
                              style="font-size: 9px; padding: 2px 6px; display: inline-flex; align-items: center; gap: 3px;"
                            >
                              <Check :size="10" /> Selesai
                            </span>
                            <span 
                              v-else 
                              class="status-pill warning" 
                              style="font-size: 9px; padding: 2px 6px;"
                            >
                              Menunggu
                            </span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <div class="modal-footer" style="padding: 16px 28px; background: #fafafd; border-top: 1px solid #f0eff3;">
              <button type="button" class="cancel-button" @click="showDetailModal = false">Tutup</button>
              <button 
                v-if="detailReceipt?.status === 'EXPECTED'" 
                type="button" 
                class="primary-button" 
                style="background: #3b9d76; border-color: #3b9d76;"
                @click="showDetailModal = false; openPost(detailReceipt)"
              >
                <CheckCircle2 :size="14" /> Lanjutkan Proses Penerimaan
              </button>
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

    <CameraScannerModal
      v-model="showScanner"
      mode="multi"
      :title="'Scan Nomor Seri: ' + (products.find(p => p.id === activeScannerItem?.product_id)?.name || 'Barang')"
      :subtitle="'Arahkan kamera ke barcode/QR nomor seri perangkat (Target: ' + (activeScannerItem?.received_quantity || 0) + ' item).'"
      :target-quantity="Number(activeScannerItem?.received_quantity || 0)"
      :initial-values="activeScannerItem?.serial_numbers ? activeScannerItem.serial_numbers.split(',').map((s: string) => s.trim()).filter(Boolean) : []"
      @complete="handleScannerComplete"
    />
  </section>
</template>

<style scoped>
.sn-scan-btn-small {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border: 1px solid #dcd8ec;
  border-radius: 6px;
  background: linear-gradient(135deg, #f4f1ff, #ebe6ff);
  color: #4b3fa0;
  font-weight: 600;
  font-size: 11px;
  cursor: pointer;
  transition: all 0.2s;
}
.sn-scan-btn-small:hover {
  transform: translateY(-1px);
  box-shadow: 0 3px 8px rgba(91, 75, 201, 0.18);
}

.receiving-page { display: block; }
.panel { overflow: hidden; }
.status-pill.success { color: #3b9d76; background: #eaf8f1; }
.status-pill.warning { color: #c48735; background: #fff4df; }
.req { color: #d47770; }
.field-error { display: block; color: #d47770; font-size: 10px; margin-top: 5px; }
.delete-error { color: #d47770; background: #fff2f2; border-radius: 6px; padding: 9px 12px; font-size: 11px; line-height: 1.5; margin: 0 0 18px; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.fade-enter-active .modal-content { transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.fade-enter-from .modal-content { transform: translateY(20px) scale(0.95); opacity: 0; }
.fade-leave-to .modal-content { transform: translateY(10px); opacity: 0; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(41, 37, 65, 0.4); backdrop-filter: blur(4px); display: flex; justify-content: center; align-items: center; z-index: 10000; }
.modal-content { background: #ffffff; border-radius: 14px; width: 100%; max-width: 440px; box-shadow: 0 20px 40px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
.modal-header { padding: 24px 28px 18px; border-bottom: 1px solid #f0eff3; }
.modal-header h2 { font-size: 16px; color: #292541; margin: 0; }
.modal-header p { color: #aaa7b4; font-size: 11px; margin: 6px 0 0; }
.modern-form { padding: 24px 28px; display: flex; flex-direction: column; gap: 14px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group label { display: block; font-size: 10px; font-weight: 600; color: #858291; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
.modern-form input, .modern-form select { width: 100%; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 8px; padding: 10px 14px; font-size: 12px; color: #48445c; outline: none; transition: all 0.2s; box-sizing: border-box; }
.modern-form input:focus, .modern-form select:focus { border-color: #bd3c3c; background: #fff; box-shadow: 0 0 0 3px rgba(189, 60, 60, 0.1); }
.modern-form input.input-error, .modern-form select.input-error { border-color: #d47770; background: #fffafb; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px; padding-top: 18px; border-top: 1px solid #f0eff3; }
.cancel-button { background: white; border: 1px solid #e9e7ef; color: #777486; padding: 10px 16px; border-radius: 7px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
.cancel-button:hover { background: #fafafd; color: #48445c; }
.pagination-button:disabled { opacity: 0.5; cursor: not-allowed; }

.btn-add-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f4f3fb;
  border: 1px solid #e3e0f3;
  color: #6a5ce8;
  font-size: 11px;
  font-weight: 600;
  padding: 5px 10px;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.btn-add-item:hover {
  background: #eceaf9;
  border-color: #6a5ce8;
}

.items-table-container {
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: #fafafd;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  padding: 10px;
}

.items-header-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 4px 6px;
  border-bottom: 1px solid #ecebf2;
  font-size: 9px;
  font-weight: 700;
  color: #858291;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.item-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.col-product {
  flex: 1;
  min-width: 0;
}

.col-qty {
  width: 110px;
  flex-shrink: 0;
}

.col-action {
  width: 28px;
  flex-shrink: 0;
  display: flex;
  justify-content: center;
}

.qty-input-wrapper {
  display: flex;
  align-items: center;
  background: #ffffff;
  border: 1px solid #e9e7ef;
  border-radius: 8px;
  padding: 0 8px;
  height: 38px;
  transition: all 0.2s;
  box-sizing: border-box;
}

.qty-input-wrapper:focus-within {
  border-color: #bd3c3c;
  box-shadow: 0 0 0 3px rgba(189, 60, 60, 0.1);
}

.qty-field {
  width: 100%;
  border: none !important;
  outline: none !important;
  background: transparent !important;
  padding: 0 !important;
  font-size: 12px !important;
  color: #292541 !important;
  font-weight: 600 !important;
  text-align: right;
  box-shadow: none !important;
}

.qty-unit {
  font-size: 10px;
  font-weight: 600;
  color: #858291;
  margin-left: 6px;
  text-transform: uppercase;
  user-select: none;
}

.btn-remove-row {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 6px;
  border: 1px solid transparent;
  background: transparent;
  color: #c4c1cd;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-remove-row:hover {
  background: #fff2f2;
  border-color: #f7d2d2;
  color: #d47770;
}

.receipt-link {
  transition: color 0.15s ease;
}

.receipt-link:hover {
  color: #bd3c3c !important;
  text-decoration: underline;
}

.btn-detail-action {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #ffffff;
  border: 1px solid #e2e0eb;
  color: #48445c;
  font-size: 11px;
  font-weight: 600;
  padding: 5px 10px;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
}

.btn-detail-action:hover {
  background: #f7f6fd;
  border-color: #6a5ce8;
  color: #6a5ce8;
}

.btn-process-action {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #eaf8f1;
  border: 1px solid #c7eed9;
  color: #2b8460;
  font-size: 11px;
  font-weight: 600;
  padding: 5px 10px;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-process-action:hover {
  background: #3b9d76;
  border-color: #3b9d76;
  color: #ffffff;
}

.btn-close-modal {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid transparent;
  background: transparent;
  color: #858291;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-close-modal:hover {
  background: #f0eff3;
  color: #292541;
}

.detail-info-card {
  background: #fafafd;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.detail-card-label {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 10px;
  font-weight: 700;
  color: #858291;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.detail-card-value {
  font-size: 13px;
  color: #292541;
  font-weight: 600;
}

.detail-card-sub {
  font-size: 10px;
  color: #aaa7b4;
}

.detail-table-wrapper {
  background: #ffffff;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  overflow: hidden;
}

.detail-table {
  width: 100%;
  border-collapse: collapse;
}

.detail-table th {
  background: #fafafd;
  color: #858291;
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 10px 14px;
  border-bottom: 1px solid #eeedf3;
  text-align: left;
}

.detail-table td {
  padding: 12px 14px;
  border-bottom: 1px solid #f6f5fa;
  font-size: 11px;
}

.detail-table tr:last-child td {
  border-bottom: none;
}

@media (max-width: 640px) {
  .modal-backdrop {
    padding: 12px;
  }
  .modal-content {
    max-width: 100% !important;
    width: 100% !important;
    border-radius: 14px;
    max-height: 92vh;
    overflow-y: auto;
  }
  .form-row {
    grid-template-columns: 1fr;
    gap: 12px;
  }
  .items-table-header {
    display: none;
  }
  .item-row {
    flex-wrap: wrap;
    background: #fafafd;
    border: 1px solid #e9e7ef;
    border-radius: 8px;
    padding: 10px;
    gap: 8px;
  }
  .col-product {
    width: 100%;
    flex: 1 1 100%;
  }
  .col-qty {
    flex: 1;
    width: auto;
  }
  .col-action {
    flex-shrink: 0;
  }
  .modal-footer {
    flex-direction: column-reverse;
    gap: 8px;
  }
  .modal-footer button {
    width: 100%;
    justify-content: center;
  }
}
</style>
