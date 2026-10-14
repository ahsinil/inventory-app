<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { Plus, Search, Ellipsis, ArrowRightLeft, Send, CheckCircle2, Trash2 } from 'lucide-vue-next'
import DateRangePicker from '../../components/DateRangePicker.vue'
import ProductCombobox from '../../components/forms/ProductCombobox.vue'

interface Product { id: string; name: string; sku: string; has_serial_number: boolean; unit?: { code: string } }
interface Warehouse { id: string; name: string; code: string }
interface Location { id: string; code: string; name: string; is_default: boolean }
interface TransferItem {
  id: string
  product_id: string
  requested_quantity: number
  sent_quantity: number
  received_quantity: number
  product: Product
}
interface Transfer {
  id: string
  transfer_number: string
  source_warehouse_id: string
  destination_warehouse_id: string
  status: string
  expected_date: string | null
  source_warehouse: Warehouse
  destination_warehouse: Warehouse
  items: TransferItem[]
}

const transfers = ref<Transfer[]>([])
const warehouses = ref<Warehouse[]>([])
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
  searchTimer = setTimeout(() => { page.value = 1; fetchTransfers() }, 300)
}

const onFilterChange = () => {
  page.value = 1
  fetchTransfers()
}

const pages = computed(() => Array.from({ length: lastPage.value }, (_, i) => i + 1))

const fetchInitialData = async () => {
  try {
    const [whRes, prdRes] = await Promise.all([
      axios.get('/warehouses', { params: { per_page: 100 } }),
      axios.get('/products', { params: { per_page: 200 } })
    ])
    warehouses.value = whRes.data.data.data || whRes.data.data
    products.value = prdRes.data.data.data || prdRes.data.data
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat data awal', 'error')
  }
}

const fetchTransfers = async () => {
  loading.value = true
  try {
    const res = await axios.get('/transfers', { 
      params: { 
        page: page.value,
        search: search.value || undefined,
        start_date: dateFrom.value || undefined,
        end_date: dateTo.value || undefined
      } 
    })
    const data = res.data.data
    transfers.value = data.data
    lastPage.value = data.last_page
    total.value = data.total
    fromItem.value = data.from ?? 0
    toItem.value = data.to ?? 0
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat transfer', 'error')
  } finally {
    loading.value = false
  }
}

const exportCSV = () => {
  if (!transfers.value.length) {
    showToast('Tidak ada data untuk diekspor', 'warning')
    return
  }
  
  const headers = ['Nomor Transfer', 'Gudang Asal', 'Gudang Tujuan', 'Status', 'Tgl Diharapkan']
  const rows = transfers.value.map(t => [
    `"${t.transfer_number || ''}"`,
    `"${t.source_warehouse?.name || ''}"`,
    `"${t.destination_warehouse?.name || ''}"`,
    `"${t.status || ''}"`,
    `"${formatDate(t.expected_date)}"`
  ])
  
  const csvContent = "data:text/csv;charset=utf-8," + 
    [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
    
  const encodedUri = encodeURI(csvContent)
  const link = document.createElement('a')
  link.setAttribute('href', encodedUri)
  link.setAttribute('download', `Transfer_Stok_${new Date().toISOString().slice(0,10)}.csv`)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const goTo = (p: number) => {
  if (p < 1 || p > lastPage.value) return
  page.value = p
  fetchTransfers()
}

// Dropdown
const activeDropdown = ref<string | null>(null)
const dropdownX = ref(0)
const dropdownY = ref(0)
const toggleDropdown = (e: MouseEvent, id: string) => {
  if (activeDropdown.value === id) { activeDropdown.value = null; return }
  activeDropdown.value = id
  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
  dropdownX.value = rect.left - 150
  dropdownY.value = rect.bottom + 5
}
const closeDropdown = () => { activeDropdown.value = null }

// CREATE MODAL
const showCreateModal = ref(false)
const saving = ref(false)
const createErrors = ref<Record<string, string[]>>({})
const createForm = ref({ source_warehouse_id: '', destination_warehouse_id: '', expected_date: '', remarks: '', items: [{ product_id: '', requested_quantity: 1 }] })

const openCreate = () => {
  createForm.value = { source_warehouse_id: '', destination_warehouse_id: '', expected_date: '', remarks: '', items: [{ product_id: '', requested_quantity: 1 }] }
  createErrors.value = {}
  showCreateModal.value = true
}
const addCreateItem = () => { createForm.value.items.push({ product_id: '', requested_quantity: 1 }) }
const removeCreateItem = (idx: number) => { 
  if (createForm.value.items.length > 1) {
    createForm.value.items.splice(idx, 1) 
  } else {
    createForm.value.items[0] = { product_id: '', requested_quantity: 1 }
  }
}

const submitCreate = async () => {
  saving.value = true
  createErrors.value = {}
  if (!createForm.value.source_warehouse_id) {
    createErrors.value.source_warehouse_id = ['Pilih gudang asal.']
    saving.value = false
    return
  }
  if (!createForm.value.destination_warehouse_id) {
    createErrors.value.destination_warehouse_id = ['Pilih gudang tujuan.']
    saving.value = false
    return
  }
  if (createForm.value.source_warehouse_id === createForm.value.destination_warehouse_id) {
    createErrors.value.destination_warehouse_id = ['Gudang tujuan tidak boleh sama dengan gudang asal.']
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
  const hasInvalidQty = createForm.value.items.some(i => !i.requested_quantity || Number(i.requested_quantity) <= 0)
  if (hasInvalidQty) {
    createErrors.value.items = ['Jumlah Qty setiap barang harus lebih dari 0.']
    saving.value = false
    return
  }

  try {
    await axios.post('/transfers', createForm.value)
    showCreateModal.value = false
    showToast('Draft transfer berhasil dibuat', 'success')
    page.value = 1
    await fetchTransfers()
  } catch (e: any) {
    createErrors.value = e.response?.data?.errors ?? { general: [e.response?.data?.message || 'Gagal menyimpan.'] }
    showToast('Gagal membuat transfer', 'error')
  } finally {
    saving.value = false
  }
}

// ACTION MODAL (SHIP / RECEIVE)
const showActionModal = ref(false)
const actionType = ref<'SHIP' | 'RECEIVE'>('SHIP')
const actioning = ref(false)
const actionErrors = ref<string>('')
const activeTransfer = ref<Transfer | null>(null)
const locations = ref<Location[]>([])

interface ActionItem {
  product_id: string
  quantity: number
  warehouse_location_id: string
  serial_numbers: string
  has_sn: boolean
}
const actionForm = ref<{ items: ActionItem[] }>({ items: [] })

const openAction = async (t: Transfer, type: 'SHIP' | 'RECEIVE') => {
  closeDropdown()
  activeTransfer.value = t
  actionType.value = type
  actionErrors.value = ''
  actionForm.value.items = []
  locations.value = []
  
  try {
    // Fetch detailed transfer to get items
    const res = await axios.get(`/transfers/${t.id}`)
    const fullTransfer = res.data.data
    
    // Fetch locations of the respective warehouse
    const targetWhId = type === 'SHIP' ? t.source_warehouse_id : t.destination_warehouse_id
    const locRes = await axios.get(`/warehouses/${targetWhId}/locations`)
    locations.value = locRes.data.data || []
    const defaultLoc = locations.value.find(l => l.is_default)?.id || (locations.value.length > 0 ? locations.value[0].id : '')

    actionForm.value.items = fullTransfer.items.map((i: any) => ({
      product_id: i.product_id,
      quantity: type === 'SHIP' ? Number(i.requested_quantity) : Number(i.sent_quantity),
      warehouse_location_id: defaultLoc,
      serial_numbers: '',
      has_sn: !!i.product?.has_serial_number
    }))
    
    showActionModal.value = true
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat detail transfer atau lokasi gudang', 'error')
  }
}

const submitAction = async () => {
  if (!activeTransfer.value) return
  actioning.value = true
  actionErrors.value = ''

  const payload = {
    items: actionForm.value.items.map(i => ({
      product_id: i.product_id,
      [actionType.value === 'SHIP' ? 'sent_quantity' : 'received_quantity']: i.quantity,
      warehouse_location_id: i.warehouse_location_id || null,
      serial_numbers: i.has_sn ? i.serial_numbers.split(',').map(s => s.trim()).filter(Boolean) : []
    }))
  }

  const endpoint = `/transfers/${activeTransfer.value.id}/${actionType.value === 'SHIP' ? 'ship' : 'receive'}`

  try {
    await axios.post(endpoint, payload)
    showActionModal.value = false
    showToast('Transfer berhasil diproses', 'success')
    await fetchTransfers()
  } catch (e: any) {
    actionErrors.value = e.response?.data?.message || 'Gagal memproses transfer.'
    showToast(actionErrors.value, 'error')
  } finally {
    actioning.value = false
  }
}

const formatDate = (d: string | null) => {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' })
}

onMounted(() => {
  document.addEventListener('click', closeDropdown)
  fetchInitialData().then(fetchTransfers)
})
onUnmounted(() => {
  document.removeEventListener('click', closeDropdown)
})
</script>

<template>
  <section class="transfers-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">OPERASI / TRANSFER STOK</p>
        <h1>Transfer Antar Gudang</h1>
        <p class="muted">Pindahkan barang antar lokasi gudang Anda.</p>
      </div>
      <div style="display: flex; gap: 10px;">
        <button class="secondary-button" @click="exportCSV"><ArrowRightLeft :size="16" /> Ekspor laporan</button>
        <button class="primary-button" @click="openCreate"><Plus :size="16" /> Buat Transfer</button>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header"></div>
      <div style="padding: 0 19px 17px; display: flex; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 8px; color: #aaa7b5; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 0 10px; height: 34px; max-width: 320px; width: 100%;">
          <Search :size="15" />
          <input v-model="search" @input="onSearch" placeholder="Cari nomor, gudang asal, gudang tujuan..." style="width: 100%; border: 0; outline: 0; background: transparent; color: #48445c; font-size: 11px;" />
        </div>
        <DateRangePicker 
          :from="dateFrom" 
          :to="dateTo" 
          @update:from="dateFrom = $event" 
          @update:to="dateTo = $event" 
          @change="onFilterChange" 
        />
      </div>

      <p v-if="loading && !transfers.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px; text-align: center;">Memuat riwayat transfer...</p>
      <p v-else-if="!transfers.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px; text-align: center;">Belum ada riwayat transfer barang.</p>

      <div v-if="transfers.length" class="table-scroll" style="overflow-x: auto;">
        <table class="modern-table" style="width: 100%; min-width: 900px; border-collapse: collapse; font-size: 11px;">
          <thead>
            <tr>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; background: #fafafd; border-y: 1px solid #f0eff3;">Nomor / Tgl</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; background: #fafafd; border-y: 1px solid #f0eff3;">Asal (Sumber)</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; background: #fafafd; border-y: 1px solid #f0eff3;">Tujuan</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; background: #fafafd; border-y: 1px solid #f0eff3;">Status</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; background: #fafafd; border-y: 1px solid #f0eff3;"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in transfers" :key="item.id" style="border-bottom: 1px solid #f0eff3;">
              <td style="padding: 12px 19px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                  <div style="width: 32px; height: 32px; border-radius: 8px; background: #f0eff3; color: #555164; display: grid; place-items: center;"><ArrowRightLeft :size="16" /></div>
                  <div>
                    <strong style="display: block; font-size: 12px; color: #292541; margin-bottom: 2px;">{{ item.transfer_number }}</strong>
                    <span style="font-size: 10px; color: #aaa7b4;">{{ formatDate(item.expected_date) }}</span>
                  </div>
                </div>
              </td>
              <td style="padding: 12px 19px; color: #555164;"><strong>{{ item.source_warehouse?.name }}</strong></td>
              <td style="padding: 12px 19px; color: #555164;"><strong>{{ item.destination_warehouse?.name }}</strong></td>
              <td style="padding: 12px 19px;">
                <span class="status-pill" :class="{ 'warning': item.status === 'APPROVED', 'info': item.status === 'IN_TRANSIT', 'success': item.status === 'COMPLETED' }" style="padding: 4px 8px; border-radius: 4px; font-size: 9px; font-weight: 600;">
                  {{ item.status === 'APPROVED' ? 'SIAP KIRIM' : item.status === 'IN_TRANSIT' ? 'DALAM PERJALANAN' : 'SELESAI' }}
                </span>
              </td>
              <td style="padding: 12px 19px; text-align: right;">
                <button v-if="item.status === 'APPROVED' || item.status === 'IN_TRANSIT'" style="background: none; border: 0; color: #aaa7b5; cursor: pointer; padding: 4px;" @click.stop="toggleDropdown($event, item.id)"><Ellipsis :size="16" /></button>
                <Teleport to="body">
                  <div v-if="activeDropdown === item.id" style="position: fixed; background: #fff; border: 1px solid #eeedf3; border-radius: 8px; padding: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 9999; min-width: 170px;" :style="{ top: dropdownY + 'px', left: dropdownX + 'px' }" @click.stop>
                    <button v-if="item.status === 'APPROVED'" style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #c48735; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fff4df'" onmouseout="this.style.background='none'" @click="openAction(item, 'SHIP')">
                      <Send :size="14" style="display:inline-block; vertical-align:middle; margin-right:6px;" /> Kirim Transfer
                    </button>
                    <button v-if="item.status === 'IN_TRANSIT'" style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #3b9d76; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#eaf8f1'" onmouseout="this.style.background='none'" @click="openAction(item, 'RECEIVE')">
                      <CheckCircle2 :size="14" style="display:inline-block; vertical-align:middle; margin-right:6px;" /> Terima Fisik
                    </button>
                  </div>
                </Teleport>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="products-pagination" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 19px; color: #aaa7b4; font-size: 10px; border-top: 1px solid #f0eff3;">
        <span>Menampilkan {{ fromItem }}–{{ toItem }} dari {{ total }} data</span>
        <div style="display: flex; gap: 4px;">
          <button class="pagination-button" :disabled="page <= 1" style="border: 1px solid #e9e7ef; background: #fff; color: #858291; border-radius: 5px; padding: 6px 9px; font-size: 10px; cursor: pointer;" @click="goTo(page - 1)">Sebelumnya</button>
          <button v-for="n in pages" :key="n" class="pagination-button" :style="n === page ? 'border: 1px solid #bd3c3c; background: #bd3c3c; color: #fff; border-radius: 5px; padding: 6px 9px; font-size: 10px; cursor: pointer;' : 'border: 1px solid #e9e7ef; background: #fff; color: #858291; border-radius: 5px; padding: 6px 9px; font-size: 10px; cursor: pointer;'" @click="goTo(n)">{{ n }}</button>
          <button class="pagination-button" :disabled="page >= lastPage" style="border: 1px solid #e9e7ef; background: #fff; color: #858291; border-radius: 5px; padding: 6px 9px; font-size: 10px; cursor: pointer;" @click="goTo(page + 1)">Berikutnya</button>
        </div>
      </div>
    </div>

    <!-- MODAL CREATE -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showCreateModal" class="modal-backdrop" @click="showCreateModal = false">
          <div class="modal-content" style="max-width: 650px;" @click.stop>
            <div class="modal-header">
              <h2>Buat Transfer Antar Gudang</h2>
              <p>Rencanakan perpindahan stok ke gudang lain.</p>
            </div>
            <form @submit.prevent="submitCreate" class="modern-form" style="max-height: 80vh; overflow-y: auto;" novalidate>
              <p v-if="createErrors.general" class="field-error">{{ createErrors.general[0] }}</p>
              
              <div class="form-row">
                <div class="form-group">
                  <label>Gudang Asal <span class="req">*</span></label>
                  <select v-model="createForm.source_warehouse_id" :class="{ 'input-error': createErrors.source_warehouse_id }">
                    <option value="" disabled>Pilih Sumber...</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                  </select>
                  <small v-if="createErrors.source_warehouse_id" class="field-error">{{ createErrors.source_warehouse_id[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Gudang Tujuan <span class="req">*</span></label>
                  <select v-model="createForm.destination_warehouse_id" :class="{ 'input-error': createErrors.destination_warehouse_id }">
                    <option value="" disabled>Pilih Tujuan...</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id" :disabled="w.id === createForm.source_warehouse_id">{{ w.name }}</option>
                  </select>
                  <small v-if="createErrors.destination_warehouse_id" class="field-error">{{ createErrors.destination_warehouse_id[0] }}</small>
                </div>
              </div>

              <div class="form-group" style="margin-top: 6px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 8px;">
                  <div>
                    <label style="margin-bottom: 2px;">Daftar Barang Transfer <span class="req">*</span></label>
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
                          v-model="item.requested_quantity"
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

              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showCreateModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Buat Transfer' }}</button>
              </div>
            </form>
          </div>
        </div>
      </Transition>

      <!-- MODAL ACTION (SHIP / RECEIVE) -->
      <Transition name="fade">
        <div v-if="showActionModal" class="modal-backdrop" @click="showActionModal = false">
          <div class="modal-content" style="max-width: 600px;" @click.stop>
            <div class="modal-header">
              <h2>{{ actionType === 'SHIP' ? 'Kirim Fisik Barang' : 'Terima Fisik Barang' }}</h2>
              <p>Masukkan lokasi spesifik dan kuantitas untuk transfer <strong>{{ activeTransfer?.transfer_number }}</strong>.</p>
            </div>
            <form @submit.prevent="submitAction" class="modern-form" style="max-height: 80vh; overflow-y: auto;" novalidate>
              <p v-if="actionErrors" class="delete-error">{{ actionErrors }}</p>
              
              <div v-for="(item, idx) in actionForm.items" :key="idx" style="padding: 16px 18px; border: 1px solid #eeedf3; border-radius: 10px; margin-bottom: 12px; background: #fff; display: flex; flex-direction: column; gap: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                  <div>
                    <strong style="display: block; font-size: 13px; color: #292541; margin-bottom: 4px;">
                      {{ products.find(p => p.id === item.product_id)?.name }}
                    </strong>
                    <span v-if="item.has_sn" class="status-pill warning" style="font-size: 9px; display: inline-block;">Butuh SN</span>
                  </div>
                  
                  <div style="display: flex; align-items: center; gap: 8px; background: #fafafd; border: 1px solid #e9e7ef; padding: 6px 12px; border-radius: 8px; transition: all 0.2s;" onmouseover="this.style.borderColor='#bd3c3c'" onmouseout="this.style.borderColor='#e9e7ef'">
                    <input v-model="item.quantity" type="number" step="0.01" min="0" style="border: 0; background: transparent; width: 60px; text-align: right; outline: none; font-size: 14px; font-weight: 600; color: #292541; padding: 0;" />
                    <span style="color: #aaa7b4; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                      {{ products.find(p => p.id === item.product_id)?.unit?.code || 'pcs' }}
                    </span>
                  </div>
                </div>

                <div class="form-group" style="margin-top: 4px;">
                  <label>Lokasi (Rak) {{ actionType === 'SHIP' ? 'Sumber' : 'Tujuan' }}</label>
                  <select v-model="item.warehouse_location_id" style="width: 100%; padding: 8px 12px; border: 1px solid #e9e7ef; border-radius: 6px; background: #fafafd; font-size: 11px;">
                    <option value="" disabled>Pilih Lokasi...</option>
                    <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.code }} - {{ l.name }} {{ l.is_default ? '(Utama)' : '' }}</option>
                  </select>
                </div>
                
                <div v-if="item.has_sn" style="border-top: 1px dashed #e9e7ef; padding-top: 12px;">
                  <label style="display: block; font-size: 10px; font-weight: 600; color: #858291; text-transform: uppercase; margin-bottom: 6px;">Nomor Seri (Pisahkan dengan koma)</label>
                  <input v-model="item.serial_numbers" type="text" placeholder="Contoh: SN-001, SN-002, SN-003" style="width: 100%; padding: 10px 14px; border: 1px solid #e9e7ef; border-radius: 8px; font-size: 12px; background: #fafafd; outline: none; color: #48445c;" />
                  <small style="color: #aaa7b4; font-size: 9px; display: block; margin-top: 6px;">Jumlah seri harus persis sama dengan Qty.</small>
                </div>
              </div>

              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showActionModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="actioning" :style="actionType === 'RECEIVE' ? 'background: #3b9d76; border-color: #3b9d76;' : 'background: #c48735; border-color: #c48735;'">
                  {{ actioning ? 'Memproses...' : (actionType === 'SHIP' ? 'Konfirmasi Pengiriman' : 'Konfirmasi Penerimaan') }}
                </button>
              </div>
            </form>
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
.transfers-page { display: block; }
.panel { overflow: hidden; }
.status-pill.success { color: #3b9d76; background: #eaf8f1; }
.status-pill.warning { color: #c48735; background: #fff4df; }
.status-pill.info { color: #2e62b4; background: #eef4fc; }
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

/* Toast */
.toast-notification { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 8px; font-size: 12px; font-weight: 500; z-index: 10001; box-shadow: 0 10px 30px rgba(0,0,0,0.1); color: #fff; max-width: 300px; display: flex; align-items: center; gap: 10px; }
.toast-notification.success { background: #292541; }
.toast-notification.error { background: #d47770; }
.toast-notification.warning { background: #c48735; }

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
</style>
