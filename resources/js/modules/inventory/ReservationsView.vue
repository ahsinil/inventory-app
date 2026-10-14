<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { MoreHorizontal, Plus, Search, ChevronDown, AlertTriangle } from 'lucide-vue-next'
import DateRangePicker from '../../components/DateRangePicker.vue'
import ProductCombobox from '../../components/forms/ProductCombobox.vue'

const reservations = ref<any[]>([])
const warehouses = ref<any[]>([])
const loading = ref(false)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const from = ref(0)
const to = ref(0)
const search = ref('')
const activeWarehouse = ref('')
const activeStatus = ref('')
const dateFrom = ref('')
const dateTo = ref('')

const showAddModal = ref(false)
const saving = ref(false)
const form = ref({
  warehouse_id: '',
  product_id: '',
  quantity: '',
  remarks: '',
  expires_at: ''
})
const formErrors = ref<Record<string, string[]>>({})
const availableProducts = ref<any[]>([])
const fetchingProducts = ref(false)

watch(() => form.value.warehouse_id, async (newVal) => {
  if (!newVal) {
    availableProducts.value = []
    form.value.product_id = ''
    return
  }
  fetchingProducts.value = true
  form.value.product_id = ''
  try {
    const res = await axios.get('/stock', { params: { warehouse_id: newVal, per_page: 500 } })
    const productMap = new Map()
    const balances = res.data.data?.data || res.data.data || []
    for (const bal of balances) {
      if (bal.product) {
        if (!productMap.has(bal.product.id)) {
          productMap.set(bal.product.id, { ...bal.product, available_qty: parseFloat(bal.quantity) })
        } else {
          productMap.get(bal.product.id).available_qty += parseFloat(bal.quantity)
        }
      }
    }
    availableProducts.value = Array.from(productMap.values())
  } catch (e) {
    console.error('Failed to load available products', e)
  } finally {
    fetchingProducts.value = false
  }
})

const selectedProductUnit = computed(() => {
  if (!form.value.product_id) return ''
  const p = availableProducts.value.find(prod => prod.id === form.value.product_id)
  return p?.unit?.code || p?.unit?.name || ''
})

const stats = ref({
  total_reservations: 0,
  active_reservations: 0,
  pending_review: 0,
  reserved_units: 0
})
let searchTimer: ReturnType<typeof setTimeout> | null = null

const onSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchReservations() }, 300)
}

const onFilterChange = () => {
  page.value = 1
  fetchReservations()
}

const fetchReservations = async () => {
  loading.value = true
  const params = {
    search: search.value || undefined,
    status: activeStatus.value || undefined,
    warehouse_id: activeWarehouse.value || undefined,
    start_date: dateFrom.value || undefined,
    end_date: dateTo.value || undefined
  }

  try {
    const [res, statsRes] = await Promise.all([
      axios.get('/reservations', { params: { ...params, page: page.value } }),
      axios.get('/reports/reservations/stats', { params })
    ])
    reservations.value = res.data.data.data
    lastPage.value = res.data.data.last_page
    total.value = res.data.data.total
    from.value = res.data.data.from ?? 0
    to.value = res.data.data.to ?? 0

    stats.value = statsRes.data.data
  } catch (e) {
    console.error('Failed to fetch reservations', e)
  } finally {
    loading.value = false
  }
}

const fetchInitialData = async () => {
  try {
    const whRes = await axios.get('/warehouses', { params: { per_page: 100 } })
    warehouses.value = whRes.data.data.data || whRes.data.data
  } catch (e) {
    console.error('Failed to load initial data', e)
  }
}

const openAddModal = () => {
  form.value = {
    warehouse_id: '',
    product_id: '',
    quantity: '',
    remarks: '',
    expires_at: ''
  }
  formErrors.value = {}
  showAddModal.value = true
}

const submit = async () => {
  saving.value = true
  formErrors.value = {}
  try {
    await axios.post('/reservations', {
      warehouse_id: form.value.warehouse_id,
      expires_at: form.value.expires_at || undefined,
      remarks: form.value.remarks || undefined,
      items: [{
        product_id: form.value.product_id,
        quantity: form.value.quantity
      }]
    })
    showAddModal.value = false
    fetchReservations()
  } catch (e: any) {
    if (e.response?.status === 422) {
      formErrors.value = e.response.data.errors
    } else {
      showToast(e.response?.data?.message || 'Terjadi kesalahan saat menyimpan reservasi.')
    }
  } finally {
    saving.value = false
  }
}

const actionMenuId = ref<string | null>(null)
const menuPos = ref({ x: 0, y: 0 })

const toggleMenu = (event: MouseEvent, id: string) => {
  event.stopPropagation()
  if (actionMenuId.value === id) {
    actionMenuId.value = null
    return
  }
  actionMenuId.value = id
  const target = event.currentTarget as HTMLElement
  const rect = target.getBoundingClientRect()
  menuPos.value = { x: rect.right - 150, y: rect.bottom + 5 }
}

const closeDropdown = () => {
  actionMenuId.value = null
}

const showCancelConfirm = ref(false)
const showEditRemarksModal = ref(false)
const showFulfillConfirm = ref(false)
const showDetailModal = ref(false)
const targetReservation = ref<any>(null)
const editRemarksText = ref('')
const processingAction = ref(false)

const toastMessage = ref('')
const toastType = ref('error')

const showToast = (msg: string, type: string = 'error') => {
  toastMessage.value = msg
  toastType.value = type
  setTimeout(() => { toastMessage.value = '' }, 3000)
}

const openDetailModal = (item: any) => {
  targetReservation.value = item
  showDetailModal.value = true
  closeDropdown()
}

const openFulfillConfirm = (item: any) => {
  targetReservation.value = item
  showFulfillConfirm.value = true
  closeDropdown()
}

const executeFulfill = async () => {
  if (!targetReservation.value) return
  processingAction.value = true
  try {
    const itemsPayload = targetReservation.value.items.map((i: any) => {
      let serials = [];
      if (i.input_serial_numbers) {
        serials = i.input_serial_numbers.split(/[\n,]+/).map((s: string) => s.trim()).filter(Boolean);
      }
      return {
        id: i.id,
        product_id: i.product_id,
        quantity: i.quantity,
        serial_numbers: serials.length ? serials : undefined
      }
    })
    
    await axios.post(`/reservations/${targetReservation.value.id}/fulfill`, { items: itemsPayload })
    showFulfillConfirm.value = false
    targetReservation.value = null
    showToast('Barang berhasil diterbitkan', 'success')
    fetchReservations()
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal menerbitkan barang.')
  } finally {
    processingAction.value = false
  }
}

const openCancelConfirm = (item: any) => {
  targetReservation.value = item
  showCancelConfirm.value = true
  closeDropdown()
}

const executeCancel = async () => {
  if (!targetReservation.value) return
  processingAction.value = true
  try {
    await axios.delete(`/reservations/${targetReservation.value.id}`)
    showCancelConfirm.value = false
    targetReservation.value = null
    showToast('Reservasi berhasil dibatalkan', 'success')
    fetchReservations()
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal membatalkan reservasi.')
  } finally {
    processingAction.value = false
  }
}

const openEditRemarks = (item: any) => {
  targetReservation.value = item
  editRemarksText.value = item.remarks || ''
  showEditRemarksModal.value = true
  closeDropdown()
}

const executeSaveRemarks = async () => {
  if (!targetReservation.value) return
  processingAction.value = true
  try {
    await axios.put(`/reservations/${targetReservation.value.id}`, { remarks: editRemarksText.value })
    showEditRemarksModal.value = false
    targetReservation.value = null
    showToast('Keterangan berhasil disimpan', 'success')
    fetchReservations()
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal menyimpan keterangan.')
  } finally {
    processingAction.value = false
  }
}

const goTo = (p: number) => {
  if (p < 1 || p > lastPage.value) return
  page.value = p
  fetchReservations()
}

const pages = computed(() => Array.from({ length: lastPage.value }, (_, i) => i + 1))

onMounted(() => {
  document.addEventListener('click', closeDropdown)
  fetchInitialData().then(fetchReservations)
})
onUnmounted(() => {
  document.removeEventListener('click', closeDropdown)
})

const getStatusTone = (status: string) => {
  switch (status) {
    case 'CONFIRMED':
    case 'RESERVED': return 'success';
    case 'DRAFT': return 'warning';
    case 'EXPIRED': return 'danger';
    default: return 'neutral';
  }
}

const getStatusLabel = (status: string) => {
  switch (status) {
    case 'CONFIRMED': return 'Terkonfirmasi';
    case 'RESERVED': return 'Aktif';
    case 'DRAFT': return 'Menunggu';
    case 'EXPIRED': return 'Kedaluwarsa';
    default: return status;
  }
}

const formatDate = (d: string) => {
  if (!d) return '-'
  return new Date(d).toLocaleString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
  <section class="reservations-page">
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="toastMessage" class="toast-notification" :class="toastType">
          {{ toastMessage }}
        </div>
      </Transition>
    </Teleport>
    
    <div class="page-heading">
      <div>
        <p class="eyebrow">INVENTARIS / RESERVASI</p>
        <h1>Reservasi</h1>
        <p class="muted">Lacak stok yang dipesan untuk pesanan dan alur kerja mendatang.</p>
      </div>
      <button class="primary-button" @click="openAddModal"><Plus :size="17" /> Reservasi baru</button>
    </div>
    
    <div class="reservation-summary">
      <div class="summary-card">
        <span>Total reservasi</span>
        <strong>{{ stats.total_reservations.toLocaleString('id-ID') }}</strong>
        <small>Berdasarkan filter</small>
      </div>
      <div class="summary-card">
        <span>Aktif</span>
        <strong class="positive">{{ stats.active_reservations.toLocaleString('id-ID') }}</strong>
        <small>Terkonfirmasi / Aktif</small>
      </div>
      <div class="summary-card">
        <span>Menunggu tinjauan</span>
        <strong class="warning-text">{{ stats.pending_review.toLocaleString('id-ID') }}</strong>
        <small>Butuh perhatian</small>
      </div>
      <div class="summary-card">
        <span>Unit dipesan</span>
        <strong class="purple-text">{{ (stats.reserved_units || 0).toLocaleString('id-ID') }}</strong>
        <small>Item yang dialokasikan</small>
      </div>
    </div>
    
    <div class="panel reservations-panel">
      <div class="panel-header custom-header">
        <div class="header-top">
          <div>
            <h2>Antrean reservasi</h2>
            <p>Komitmen stok menunggu pemenuhan atau rilis.</p>
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
              Semua aktivitas
              <span v-if="activeStatus === ''">{{ stats.total_reservations }}</span>
            </button>
            <button class="tab-button" :class="{ active: activeStatus === 'DRAFT' }" @click="activeStatus = 'DRAFT'; onFilterChange()">
              Menunggu
            </button>
            <button class="tab-button" :class="{ active: activeStatus === 'RESERVED' }" @click="activeStatus = 'RESERVED'; onFilterChange()">
              Aktif
              <span v-if="activeStatus === 'RESERVED'">{{ stats.active_reservations }}</span>
            </button>
            <button class="tab-button" :class="{ active: activeStatus === 'CONFIRMED' }" @click="activeStatus = 'CONFIRMED'; onFilterChange()">
              Terkonfirmasi
            </button>
            <button class="tab-button" :class="{ active: activeStatus === 'EXPIRED' }" @click="activeStatus = 'EXPIRED'; onFilterChange()">
              Kedaluwarsa
            </button>
          </div>

          <div class="rsv-search" style="margin-left: auto;">
            <Search :size="14" class="icon" />
            <input v-model="search" @input="onSearch" placeholder="Cari reservasi..." aria-label="Cari reservasi" />
          </div>
        </div>
      </div>
      
      <div class="table-wrap">
        <table class="reservations-table">
          <thead>
            <tr>
              <th>Reservasi</th>
              <th>Produk</th>
              <th>Gudang</th>
              <th>Kuantitas</th>
              <th>Diminta oleh</th>
              <th>Tanggal</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading && !reservations.length"><td colspan="8" style="text-align:center; padding: 20px;">Memuat data...</td></tr>
            <tr v-else-if="!reservations.length"><td colspan="8" style="text-align:center; padding: 20px;">Tidak ada reservasi ditemukan.</td></tr>
            <tr v-else v-for="item in reservations" :key="item.id" class="table-row">
              <td><strong class="reservation-id">{{ item.reservation_number }}</strong></td>
              <td>
                <div class="reservation-product" v-if="item.items && item.items.length">
                  <strong>{{ item.items[0].product?.name || 'Produk tidak diketahui' }}</strong>
                  <span>{{ item.items[0].product?.sku || '-' }}</span>
                  <span v-if="item.items.length > 1" style="color: #684fc0; margin-top: 2px;">+{{ item.items.length - 1 }} item lainnya</span>
                </div>
                <div class="reservation-product" v-else>
                  <strong>-</strong>
                </div>
              </td>
              <td>{{ item.warehouse?.name || '-' }}</td>
              <td class="reservation-quantity">
                {{ item.items && item.items.length ? (item.items[0].quantity + ' ' + (item.items[0].product?.unit?.symbol || '')) : '-' }}
              </td>
              <td>User ID: {{ item.created_by ? item.created_by.substring(0, 8) : '-' }}</td>
              <td class="muted-cell">{{ formatDate(item.created_at) }}</td>
              <td><span class="status-pill" :class="getStatusTone(item.status)">{{ getStatusLabel(item.status) }}</span></td>
              <td>
                <button class="dots-button" aria-label="Aksi reservasi" @click.stop="toggleMenu($event, item.id)">
                  <MoreHorizontal :size="16" />
                </button>
                <Teleport to="body">
                  <div v-if="actionMenuId === item.id" class="action-menu" :style="{ top: menuPos.y + 'px', left: menuPos.x + 'px' }" @click.stop>
                    <button class="menu-item" @click="openDetailModal(item)">Lihat detail</button>
                    <div class="menu-sep"></div>
                    <template v-if="!['FULFILLED', 'RELEASED', 'EXPIRED'].includes(item.status)">
                      <button class="menu-item" @click="openFulfillConfirm(item)">Terbitkan Barang</button>
                    </template>
                    <button class="menu-item" @click="openEditRemarks(item)">Ubah keterangan</button>
                    <template v-if="!['FULFILLED', 'RELEASED', 'EXPIRED'].includes(item.status)">
                      <div class="menu-sep"></div>
                      <button class="menu-item danger" @click="openCancelConfirm(item)">Batalkan reservasi</button>
                    </template>
                  </div>
                </Teleport>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <div class="products-pagination">
        <span>Menampilkan {{ from }}–{{ to }} dari {{ total }} reservasi</span>
        <div>
          <button class="pagination-button" :disabled="page <= 1" @click="goTo(page - 1)">Sebelumnya</button>
          <button class="pagination-button" v-for="n in pages" :key="n" :class="{ 'active': n === page }" @click="goTo(n)">{{ n }}</button>
          <button class="pagination-button" :disabled="page >= lastPage" @click="goTo(page + 1)">Berikutnya</button>
        </div>
      </div>
    </div>
    
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showAddModal" class="modal-backdrop" @click="showAddModal = false">
          <div class="modal-content" @click.stop>
            <div class="modal-header">
              <h2>Buat Reservasi Baru</h2>
              <p>Alokasikan stok untuk keperluan pesanan atau kegiatan operasional.</p>
            </div>
            <form @submit.prevent="submit" class="modern-form" novalidate>
              <div class="form-row">
                <div class="form-group">
                  <label>Gudang <span class="req">*</span></label>
                  <div class="select-wrapper">
                    <select v-model="form.warehouse_id" :class="{ 'input-error': formErrors.warehouse_id }">
                      <option value="" disabled>Pilih gudang</option>
                      <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                    </select>
                    <ChevronDown :size="15" class="select-icon" />
                  </div>
                  <small v-if="formErrors.warehouse_id" class="field-error">{{ formErrors.warehouse_id[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Tanggal Kedaluwarsa <span style="font-weight: 400; text-transform: none;">(Opsional)</span></label>
                  <input v-model="form.expires_at" type="date" :class="{ 'input-error': formErrors.expires_at }" />
                  <small v-if="formErrors.expires_at" class="field-error">{{ formErrors.expires_at[0] }}</small>
                </div>
              </div>
              
              <div class="form-group">
                <label>Daftar Barang <span class="req">*</span></label>
                <div style="display: flex; gap: 14px;">
                  <div style="flex: 1;">
                    <ProductCombobox
                      v-model="form.product_id"
                      :products="availableProducts"
                      :disabled="!form.warehouse_id || fetchingProducts"
                      :error="!!formErrors['items.0.product_id']"
                      :placeholder="!form.warehouse_id ? 'Pilih gudang terlebih dahulu' : (fetchingProducts ? 'Memuat produk...' : (availableProducts.length === 0 ? 'Tidak ada stok di gudang ini' : 'Ketik nama barang atau SKU...'))"
                    />
                  </div>
                  <div style="width: 130px; position: relative;">
                    <input v-model.number="form.quantity" type="number" min="0" step="any" placeholder="1" :class="{ 'input-error': formErrors['items.0.quantity'] }" :style="{ paddingRight: selectedProductUnit ? '46px' : '14px' }" />
                    <span v-if="selectedProductUnit" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); font-size: 11px; font-weight: 600; color: #aaa7b4; pointer-events: none;">
                      {{ selectedProductUnit }}
                    </span>
                  </div>
                </div>
                <small v-if="formErrors['items.0.product_id'] || formErrors['items.0.quantity']" class="field-error">Harap lengkapi produk dan kuantitas.</small>
              </div>
              
              <div class="form-group">
                <label>Keterangan <span style="font-weight: 400; text-transform: none;">(Opsional)</span></label>
                <input v-model="form.remarks" type="text" placeholder="Catatan reservasi..." :class="{ 'input-error': formErrors.remarks }" />
                <small v-if="formErrors.remarks" class="field-error">{{ formErrors.remarks[0] }}</small>
              </div>
              
              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showAddModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Buat Reservasi' }}</button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <!-- Detail Modal -->
      <Transition name="fade">
        <div v-if="showDetailModal" class="modal-backdrop" @click="showDetailModal = false">
          <div class="modal-content" style="max-width: 580px;" @click.stop>
            <div class="modal-header">
              <h2>Detail Reservasi</h2>
              <p>{{ targetReservation?.reservation_number }}</p>
            </div>
            <div class="modern-form" style="gap: 20px;">
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; background: #fafafd; padding: 18px; border-radius: 8px; border: 1px solid #e9e7ef;">
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Status</span>
                  <span class="status-pill" :class="getStatusTone(targetReservation?.status)" style="margin-top: 6px; display: inline-block;">{{ getStatusLabel(targetReservation?.status) }}</span>
                </div>
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Gudang</span>
                  <strong style="display: block; font-size: 11px; color: #48445c; margin-top: 6px;">{{ targetReservation?.warehouse?.name || '-' }}</strong>
                </div>
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Dibuat Oleh</span>
                  <strong style="display: block; font-size: 11px; color: #48445c; margin-top: 6px;">{{ targetReservation?.created_by ? targetReservation.created_by.substring(0, 8) : '-' }}</strong>
                </div>
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Tanggal</span>
                  <strong style="display: block; font-size: 11px; color: #48445c; margin-top: 6px;">{{ formatDate(targetReservation?.created_at) }}</strong>
                </div>
                <div v-if="targetReservation?.remarks" style="grid-column: span 2; margin-top: 8px;">
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Keterangan</span>
                  <p style="margin: 6px 0 0; font-size: 11px; color: #48445c; line-height: 1.5;">{{ targetReservation.remarks }}</p>
                </div>
              </div>

              <div style="margin-top: 10px;">
                <h3 style="font-size: 12px; color: #292541; margin: 0 0 12px;">Daftar Barang</h3>
                <div style="border: 1px solid #e9e7ef; border-radius: 8px; overflow: hidden;">
                  <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                      <tr>
                        <th style="padding: 10px 14px; text-align: left; font-size: 9px; color: #858291; border-bottom: 1px solid #e9e7ef; background: #fafafd; text-transform: uppercase;">Produk</th>
                        <th style="padding: 10px 14px; text-align: right; font-size: 9px; color: #858291; border-bottom: 1px solid #e9e7ef; background: #fafafd; text-transform: uppercase;">Kuantitas</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(item, idx) in targetReservation?.items" :key="item.id" :style="idx !== targetReservation.items.length - 1 ? 'border-bottom: 1px solid #f3f2f6;' : ''">
                        <td style="padding: 12px 14px;">
                          <strong style="display: block; font-size: 11px; color: #48445c;">{{ item.product?.name }}</strong>
                          <span style="display: block; font-size: 9px; color: #aaa7b4; margin-top: 4px;">{{ item.product?.sku }}</span>
                        </td>
                        <td style="padding: 12px 14px; text-align: right; font-size: 11px; color: #292541; font-weight: 600;">
                          {{ item.quantity }} <span style="color: #aaa7b4; font-weight: 400; font-size: 10px; margin-left: 2px;">{{ item.product?.unit?.symbol }}</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div style="margin-top: 10px;">
                <h3 style="font-size: 12px; color: #292541; margin: 0 0 12px;">Riwayat Singkat (Audit Log)</h3>
                <div style="background: #fffafb; padding: 14px; border: 1px solid #f0eff3; border-radius: 8px; display: flex; flex-direction: column; gap: 10px;">
                  <div style="display: flex; gap: 10px; align-items: flex-start;">
                    <div style="width: 6px; height: 6px; border-radius: 50%; background: #bd3c3c; margin-top: 5px;"></div>
                    <div>
                      <p style="margin: 0; font-size: 11px; color: #48445c;">Reservasi dibuat dengan status <strong style="color: #292541;">DRAFT</strong></p>
                      <span style="font-size: 9px; color: #aaa7b4;">{{ formatDate(targetReservation?.created_at) }}</span>
                    </div>
                  </div>
                  <div v-if="targetReservation?.status !== 'DRAFT'" style="display: flex; gap: 10px; align-items: flex-start;">
                    <div style="width: 6px; height: 6px; border-radius: 50%; background: #3b9d76; margin-top: 5px;"></div>
                    <div>
                      <p style="margin: 0; font-size: 11px; color: #48445c;">Status diubah menjadi <strong style="color: #292541;">{{ targetReservation?.status }}</strong></p>
                      <span style="font-size: 9px; color: #aaa7b4;">{{ formatDate(targetReservation?.updated_at) }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showDetailModal = false">Tutup</button>
              </div>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Fulfill Confirmation Modal -->
      <Transition name="fade">
        <div v-if="showFulfillConfirm" class="modal-backdrop" @click="showFulfillConfirm = false">
          <div class="modal-content" style="max-width: 400px; text-align: center; padding: 32px 28px;" @click.stop>
            <div style="width: 52px; height: 52px; border-radius: 50%; background: #eaf8f1; color: #3b9d76; display: grid; place-items: center; margin: 0 auto 18px;">
              <AlertTriangle :size="24" />
            </div>
            <h2 style="font-size: 16px; color: #292541; margin: 0 0 10px;">Terbitkan Barang?</h2>
            <p style="color: #555164; font-size: 13px; margin: 0 0 16px; line-height: 1.6;">
              Anda yakin ingin menerbitkan barang untuk reservasi <strong style="color: #292541; font-weight: 600;">{{ targetReservation?.reservation_number }}</strong>?
            </p>

            <div v-if="targetReservation?.items?.some(i => i.product?.has_serial_number)" style="text-align: left; margin-bottom: 20px; background: #fafafd; padding: 12px; border-radius: 8px; border: 1px solid #e9e7ef;">
              <p style="font-size: 11px; color: #858291; margin: 0 0 10px;">Beberapa barang membutuhkan Serial Number (pisahkan dengan koma atau enter):</p>
              <div v-for="item in targetReservation.items.filter(i => i.product?.has_serial_number)" :key="item.id" style="margin-bottom: 10px;">
                <label style="display: block; font-size: 10px; font-weight: 600; color: #48445c; margin-bottom: 4px;">{{ item.product?.name }} (Butuh {{ item.quantity }})</label>
                <textarea v-model="item.input_serial_numbers" class="modern-input" placeholder="SN123&#10;SN124..." style="padding: 8px 10px; font-size: 11px; width: 100%; min-height: 80px; resize: vertical; font-family: monospace;"></textarea>
              </div>
            </div>

            <div style="display: flex; gap: 12px; justify-content: center;">
              <button class="cancel-button" style="flex: 1; font-weight: 500;" @click="showFulfillConfirm = false" :disabled="processingAction">Batal</button>
              <button class="primary-button" style="flex: 1; justify-content: center; background: #3b9d76; border-color: #3b9d76;" @click="executeFulfill" :disabled="processingAction">{{ processingAction ? 'Memproses...' : 'Ya, Terbitkan' }}</button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Edit Remarks Modal -->
      <Transition name="fade">
        <div v-if="showEditRemarksModal" class="modal-backdrop" @click="showEditRemarksModal = false">
          <div class="modal-content" style="max-width: 440px;" @click.stop>
            <div class="modal-header">
              <h2>Ubah Keterangan</h2>
              <p>Perbarui catatan untuk reservasi <strong>{{ targetReservation?.reservation_number }}</strong>.</p>
            </div>
            <div class="modern-form">
              <div class="form-group">
                <label>Keterangan <span style="font-weight: 400; text-transform: none;">(Opsional)</span></label>
                <input v-model="editRemarksText" type="text" placeholder="Catatan tambahan..." />
              </div>
              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showEditRemarksModal = false" :disabled="processingAction">Batal</button>
                <button type="button" class="primary-button" @click="executeSaveRemarks" :disabled="processingAction">{{ processingAction ? 'Menyimpan...' : 'Simpan Keterangan' }}</button>
              </div>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Cancel Confirmation Modal -->
      <Transition name="fade">
        <div v-if="showCancelConfirm" class="modal-backdrop" @click="showCancelConfirm = false">
          <div class="modal-content" style="max-width: 360px; text-align: center; padding: 32px 28px;" @click.stop>
            <div style="width: 52px; height: 52px; border-radius: 50%; background: #fff2f2; color: #d47770; display: grid; place-items: center; margin: 0 auto 18px;">
              <AlertTriangle :size="24" />
            </div>
            <h2 style="font-size: 16px; color: #292541; margin: 0 0 10px;">Batalkan Reservasi?</h2>
            <p style="color: #555164; font-size: 13px; margin: 0 0 24px; line-height: 1.6;">
              Anda yakin ingin membatalkan reservasi <strong style="color: #292541; font-weight: 600;">{{ targetReservation?.reservation_number }}</strong>? Statusnya akan berubah menjadi dibatalkan.
            </p>
            <div style="display: flex; gap: 12px; justify-content: center;">
              <button class="cancel-button" style="flex: 1; font-weight: 500;" @click="showCancelConfirm = false" :disabled="processingAction">Batal</button>
              <button class="primary-button" style="flex: 1; justify-content: center;" @click="executeCancel" :disabled="processingAction">{{ processingAction ? 'Memproses...' : 'Ya, Batalkan' }}</button>
            </div>
          </div>
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

.action-menu{position:fixed;background:#fff;border:1px solid #eeedf3;border-radius:8px;padding:6px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;min-width:160px}
.menu-item{display:block;width:100%;text-align:left;background:none;border:0;padding:9px 12px;font-size:11px;color:#48445c;border-radius:5px;cursor:pointer}.menu-item:hover{background:#fafafd}.menu-item.danger{color:#d47770}.menu-item.danger:hover{background:#fff2f2}
.menu-sep{height:1px;background:#eeedf3;margin:4px 0}

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

/* Toast */
.toast-notification { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 8px; font-size: 12px; font-weight: 500; z-index: 10001; box-shadow: 0 10px 30px rgba(0,0,0,0.1); color: #fff; max-width: 300px; display: flex; align-items: center; gap: 10px; }
.toast-notification.success { background: #292541; }
.toast-notification.error { background: #d47770; }
</style>


