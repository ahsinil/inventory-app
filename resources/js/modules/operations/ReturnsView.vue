<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { Plus, Search, Ellipsis, ArrowDownLeft, CheckCircle2, PackageCheck, ScanLine, X } from 'lucide-vue-next'
import DateRangePicker from '../../components/DateRangePicker.vue'
import ProductCombobox from '../../components/forms/ProductCombobox.vue'
import CameraScannerModal from '../../components/forms/CameraScannerModal.vue'
import AppPagination from '../../components/ui/AppPagination.vue'
import AppSortableTh from '../../components/ui/AppSortableTh.vue'

interface Product { id: string; name: string; sku: string; has_serial_number: boolean; unit?: { code: string } }
interface Warehouse { id: string; name: string; code: string }
interface ReturnItem {
  id: string
  product_id: string
  quantity: number
  condition: string
  returned_serial_numbers: string[] | null
  product: Product
}
interface StockReturn {
  id: string
  return_number: string
  warehouse_id: string
  status: string
  reason: string
  returned_by: string | null
  reference_type: string | null
  reference_id: string | null
  created_at: string
  warehouse: Warehouse
  items: ReturnItem[]
}

const returns = ref<StockReturn[]>([])
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
  searchTimer = setTimeout(() => { page.value = 1; fetchReturns() }, 300)
}

const onFilterChange = () => {
  page.value = 1
  fetchReturns()
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

const fetchReturns = async () => {
  loading.value = true
  try {
    const res = await axios.get('/returns', { 
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
    returns.value = data.data
    lastPage.value = data.last_page
    total.value = data.total
    fromItem.value = data.from ?? 0
    toItem.value = data.to ?? 0
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat pengembalian', 'error')
  } finally {
    loading.value = false
  }
}

const handleSort = (field: string, dir: 'asc' | 'desc') => {
  sortBy.value = field
  sortDir.value = dir
  page.value = 1
  fetchReturns()
}

const exportCSV = () => {
  if (!returns.value.length) {
    showToast('Tidak ada data untuk diekspor', 'warning')
    return
  }
  
  const headers = ['Nomor', 'Gudang', 'Alasan', 'Referensi Tipe', 'Referensi ID', 'Status', 'Tanggal']
  const rows = returns.value.map(r => [
    `"${r.return_number || ''}"`,
    `"${r.warehouse?.name || ''}"`,
    `"${r.reason || ''}"`,
    `"${r.reference_type || ''}"`,
    `"${r.reference_id || ''}"`,
    `"${r.status || ''}"`,
    `"${formatDate(r.created_at)}"`
  ])
  
  const csvContent = "data:text/csv;charset=utf-8," + 
    [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
    
  const encodedUri = encodeURI(csvContent)
  const link = document.createElement('a')
  link.setAttribute('href', encodedUri)
  link.setAttribute('download', `Pengembalian_Stok_${new Date().toISOString().slice(0,10)}.csv`)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const goTo = (p: number) => {
  if (p < 1 || p > lastPage.value) return
  page.value = p
  fetchReturns()
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
const createForm = ref({ warehouse_id: '', reason: '', returned_by: '', reference_type: '', reference_id: '', items: [{ product_id: '', quantity: 1 }] })

const openCreate = () => {
  createForm.value = { warehouse_id: '', reason: '', returned_by: '', reference_type: '', reference_id: '', items: [{ product_id: '', quantity: 1 }] }
  createErrors.value = {}
  showCreateModal.value = true
}
const addCreateItem = () => { createForm.value.items.push({ product_id: '', quantity: 1 }) }
const removeCreateItem = (idx: number) => { createForm.value.items.splice(idx, 1) }

const submitCreate = async () => {
  saving.value = true
  createErrors.value = {}
  try {
    const payload = {
      ...createForm.value,
      returned_by: createForm.value.returned_by || null,
      reference_type: createForm.value.reference_type || null,
      reference_id: createForm.value.reference_id || null,
    }
    await axios.post('/returns', payload)
    showCreateModal.value = false
    showToast('Draft pengembalian berhasil dibuat', 'success')
    page.value = 1
    await fetchReturns()
  } catch (e: any) {
    createErrors.value = e.response?.data?.errors ?? { general: [e.response?.data?.message || 'Gagal menyimpan.'] }
    showToast('Gagal membuat pengembalian', 'error')
  } finally {
    saving.value = false
  }
}

// POST MODAL
const showPostModal = ref(false)
const posting = ref(false)
const postErrors = ref<string>('')
const activeReturn = ref<StockReturn | null>(null)

interface PostItem {
  product_id: string
  condition: string
  serial_numbers: string
  has_sn: boolean
}
const postForm = ref<{ items: PostItem[] }>({ items: [] })

// Scanner kamera untuk nomor seri retur
const showScanner = ref(false)
const activeScannerItem = ref<PostItem | null>(null)

const openScannerForItem = (item: PostItem) => {
  activeScannerItem.value = item
  showScanner.value = true
}

const handleScannerComplete = (serials: string[]) => {
  if (activeScannerItem.value) {
    activeScannerItem.value.serial_numbers = serials.join(', ')
    showToast(`${serials.length} nomor seri berhasil dimasukkan`, 'success')
  }
}

const openPost = async (r: StockReturn) => {
  closeDropdown()
  activeReturn.value = r
  postErrors.value = ''
  postForm.value.items = []
  
  try {
    const res = await axios.get(`/returns/${r.id}`)
    const fullReturn = res.data.data
    postForm.value.items = fullReturn.items.map((i: any) => ({
      product_id: i.product_id,
      condition: 'GOOD',
      serial_numbers: '',
      has_sn: !!i.product?.has_serial_number
    }))
    showPostModal.value = true
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat detail pengembalian', 'error')
  }
}

const submitPost = async () => {
  if (!activeReturn.value) return
  posting.value = true
  postErrors.value = ''
  try {
    const payload = {
      items: postForm.value.items.map(i => ({
        product_id: i.product_id,
        condition: i.condition,
        serial_numbers: i.has_sn ? i.serial_numbers.split(',').map(s => s.trim()).filter(Boolean) : []
      }))
    }
    await axios.post(`/returns/${activeReturn.value.id}/post`, payload)
    showPostModal.value = false
    showToast('Pengembalian berhasil diproses', 'success')
    await fetchReturns()
  } catch (e: any) {
    postErrors.value = e.response?.data?.message || 'Gagal memproses pengembalian.'
    showToast(postErrors.value, 'error')
  } finally {
    posting.value = false
  }
}

const formatDate = (d: string) => {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
}

onMounted(() => {
  document.addEventListener('click', closeDropdown)
  fetchInitialData().then(fetchReturns)
})
onUnmounted(() => {
  document.removeEventListener('click', closeDropdown)
})
</script>

<template>
  <section class="returns-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">OPERASI / PENGEMBALIAN BARANG</p>
        <h1>Pengembalian Barang</h1>
        <p class="muted">Catat barang yang dikembalikan, lakukan inspeksi kondisi, dan kembalikan ke stok.</p>
      </div>
      <div style="display: flex; gap: 10px;">
        <button class="secondary-button" @click="exportCSV"><ArrowDownLeft :size="16" /> Ekspor laporan</button>
        <button class="primary-button" @click="openCreate"><Plus :size="16" /> Buat Pengembalian</button>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header"></div>
      <div style="padding: 0 19px 17px; display: flex; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 8px; color: #aaa7b5; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 0 10px; height: 34px; max-width: 320px; width: 100%;">
          <Search :size="15" />
          <input v-model="search" @input="onSearch" placeholder="Cari nomor, gudang, alasan..." style="width: 100%; border: 0; outline: 0; background: transparent; color: #48445c; font-size: 11px;" />
        </div>
        <DateRangePicker 
          :from="dateFrom" 
          :to="dateTo" 
          @update:from="dateFrom = $event" 
          @update:to="dateTo = $event" 
          @change="onFilterChange" 
        />
      </div>

      <p v-if="loading && !returns.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px; text-align: center;">Memuat riwayat pengembalian...</p>
      <p v-else-if="!returns.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px; text-align: center;">Belum ada riwayat pengembalian barang.</p>

      <div v-if="returns.length" class="table-scroll" style="overflow-x: auto;">
        <table class="modern-table" style="width: 100%; min-width: 900px; border-collapse: collapse; font-size: 11px;">
          <thead>
            <tr>
              <AppSortableTh field="return_number" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Nomor / Gudang</AppSortableTh>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Alasan / Referensi</th>
              <AppSortableTh field="created_at" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Tanggal</AppSortableTh>
              <th style="padding: 10px 19px; text-align: right; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Item</th>
              <AppSortableTh field="status" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Status</AppSortableTh>
              <th style="padding: 10px 19px; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3; width: 50px;"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in returns" :key="item.id" style="border-bottom: 1px solid #f0eff3;">
              <td style="padding: 12px 19px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                  <div style="width: 32px; height: 32px; border-radius: 8px; background: #f0eff3; color: #555164; display: grid; place-items: center;"><ArrowDownLeft :size="16" /></div>
                  <div>
                    <strong style="display: block; font-size: 12px; color: #292541; font-weight: 600;">{{ item.return_number }}</strong>
                    <span style="display: block; font-size: 11px; color: #777486; margin-top: 2px;">{{ item.warehouse?.name }}</span>
                  </div>
                </div>
              </td>
              <td style="padding: 12px 19px; color: #48445c;">
                <div>{{ item.reason }}</div>
                <div style="color: #aaa7b4; font-size: 10px; margin-top: 2px;" v-if="item.reference_type">{{ item.reference_type }} {{ item.reference_id ? '#'+item.reference_id : '' }}</div>
              </td>
              <td style="padding: 12px 19px; color: #48445c;">
                {{ formatDate(item.created_at) }}
              </td>
              <td style="padding: 12px 19px; color: #48445c; text-align: right; font-weight: 500;">
                {{ item.items?.length || 0 }} SKU
              </td>
              <td style="padding: 12px 19px;">
                <span v-if="item.status === 'PENDING'" style="display: inline-flex; align-items: center; padding: 4px 8px; background: #fff4df; color: #c48735; font-size: 10px; font-weight: 600; border-radius: 4px; line-height: 1;">Draft / Pending</span>
                <span v-else style="display: inline-flex; align-items: center; padding: 4px 8px; background: #eaf8f1; color: #3b9d76; font-size: 10px; font-weight: 600; border-radius: 4px; line-height: 1;">Disetujui</span>
              </td>
              <td style="padding: 12px 19px; text-align: right; position: relative;">
                <button style="background: transparent; border: 0; color: #aaa7b4; cursor: pointer; padding: 4px;" @click.stop="toggleDropdown($event, item.id)">
                  <Ellipsis :size="16" />
                </button>
                <Teleport to="body">
                  <div v-if="activeDropdown === item.id" class="dd" :style="{ top: dropdownY + 'px', left: dropdownX + 'px' }" @click.stop>
                    <button v-if="item.status === 'PENDING'" class="dd-item ok" @click="openPost(item)"><CheckCircle2 :size="14" /> Proses Pengembalian</button>
                    <button v-else class="dd-item" style="color: #aaa7b4;" disabled>Sudah Diproses</button>
                  </div>
                </Teleport>
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

    <!-- CREATE MODAL -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showCreateModal" class="modal-backdrop" @click="showCreateModal = false">
          <div class="modal-content modal-create-return" @click.stop>
            <div class="modal-header">
              <h2>Buat Pengembalian Barang</h2>
              <button class="close-button" @click="showCreateModal = false">&times;</button>
            </div>
            <div class="modal-body">
              <p v-if="createErrors.general" class="delete-error">{{ createErrors.general[0] }}</p>
              
              <div class="return-form-grid">
                <div class="form-group">
                  <label>Gudang Tujuan <span class="req">*</span></label>
                  <select v-model="createForm.warehouse_id" class="form-input">
                    <option value="" disabled>Pilih gudang...</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                  </select>
                  <span class="field-error" v-if="createErrors.warehouse_id">{{ createErrors.warehouse_id[0] }}</span>
                </div>
                <div class="form-group">
                  <label>Alasan Pengembalian <span class="req">*</span></label>
                  <input v-model="createForm.reason" class="form-input" placeholder="Misal: Salah kirim, cacat pabrik..." />
                  <span class="field-error" v-if="createErrors.reason">{{ createErrors.reason[0] }}</span>
                </div>
                <div class="form-group">
                  <label>Dikembalikan Oleh</label>
                  <input v-model="createForm.returned_by" class="form-input" placeholder="Nama pelanggan/pihak ketiga" />
                </div>
                <div class="form-group">
                  <div class="reference-fields-row">
                    <div class="ref-type-field">
                      <label>Tipe Referensi</label>
                      <select v-model="createForm.reference_type" class="form-input">
                        <option value="">Tidak ada</option>
                        <option value="ORDER">Pesanan (Order)</option>
                        <option value="INVOICE">Faktur (Invoice)</option>
                      </select>
                    </div>
                    <div class="ref-id-field">
                      <label>ID Referensi</label>
                      <input v-model="createForm.reference_id" class="form-input" placeholder="Nomor Ref..." :disabled="!createForm.reference_type" />
                    </div>
                  </div>
                </div>
              </div>

              <div class="return-items-section">
                <div class="return-items-header">
                  <h3>Daftar Barang <span class="req">*</span></h3>
                  <button type="button" class="add-item-btn" @click="addCreateItem">+ Tambah Baris</button>
                </div>
                <span class="field-error" v-if="createErrors.items">{{ createErrors.items[0] }}</span>
                
                <div v-for="(item, idx) in createForm.items" :key="idx" class="return-item-card">
                  <div v-if="createForm.items.length > 1" class="return-item-card-header">
                    <span class="return-item-num">Barang #{{ idx + 1 }}</span>
                    <button type="button" class="btn-remove-item" @click="removeCreateItem(idx)" title="Hapus baris">
                      <X :size="13" />
                      <span>Hapus</span>
                    </button>
                  </div>
                  <div class="return-item-card-body">
                    <div class="return-item-product">
                      <ProductCombobox
                        v-model="item.product_id"
                        :products="products"
                        placeholder="Ketik nama produk atau SKU..."
                      />
                    </div>
                    <div class="return-item-qty">
                      <div class="return-qty-field">
                        <input type="number" v-model.number="item.quantity" min="1" placeholder="Qty" />
                        <span class="return-unit-badge">
                          {{ products.find(p => p.id === item.product_id)?.unit?.code || 'QTY' }}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="cancel-button" @click="showCreateModal = false">Batal</button>
              <button type="button" class="primary-button" :disabled="saving" @click="submitCreate">{{ saving ? 'Menyimpan...' : 'Simpan Draft' }}</button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- POST MODAL -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showPostModal && activeReturn" class="modal-backdrop" @click="showPostModal = false">
          <div class="modal-content modal-inspect-return" @click.stop>
            <div class="modal-header">
              <h2>Inspeksi & Proses Pengembalian</h2>
              <button class="close-button" @click="showPostModal = false">&times;</button>
            </div>
            <div class="modal-body">
              <p class="inspection-desc">
                Pastikan Anda memeriksa kondisi fisik barang sebelum memasukkannya kembali ke inventaris. Barang dengan status <strong>DAMAGED</strong> atau <strong>DISPOSED</strong> akan diproses sesuai kondisinya.
              </p>
              <p v-if="postErrors" class="delete-error" style="margin-bottom: 16px;">{{ postErrors }}</p>
              
              <div v-for="(item, idx) in postForm.items" :key="idx" class="inspection-card">
                <div class="inspection-header">
                  <strong class="inspection-name">
                    {{ products.find(p => p.id === item.product_id)?.name || 'Produk' }}
                  </strong>
                  <span class="inspection-qty">Qty: {{ activeReturn.items.find(i => i.product_id === item.product_id)?.quantity || 0 }}</span>
                </div>
                <div class="inspection-fields">
                  <div class="inspection-field">
                    <label>Kondisi Barang</label>
                    <select v-model="item.condition" class="form-input">
                      <option value="GOOD">Bagus (Bisa dijual kembali)</option>
                      <option value="DAMAGED">Rusak (Perlu perbaikan)</option>
                      <option value="DISPOSED">Dibuang (Tidak bisa dipakai)</option>
                    </select>
                  </div>
                  <div class="inspection-field" v-if="item.has_sn">
                    <div class="sn-label-row">
                      <label>Serial Numbers (Pisahkan dgn koma)</label>
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
                    <input v-model="item.serial_numbers" class="form-input" placeholder="SN-1, SN-2..." />
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="cancel-button" @click="showPostModal = false">Batal</button>
              <button type="button" class="primary-button btn-complete-return" :disabled="posting" @click="submitPost">
                {{ posting ? 'Memproses...' : 'Selesaikan Pengembalian' }}
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
      :title="'Scan Nomor Seri Retur: ' + (products.find(p => p.id === activeScannerItem?.product_id)?.name || 'Barang')"
      :subtitle="'Arahkan kamera ke barcode/QR nomor seri barang yang diretur.'"
      :target-quantity="activeReturn?.items?.find(i => i.product_id === activeScannerItem?.product_id)?.quantity"
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
  padding: 3px 8px;
  border: 1px solid #dcd8ec;
  border-radius: 6px;
  background: linear-gradient(135deg, #f4f1ff, #ebe6ff);
  color: #4b3fa0;
  font-weight: 600;
  font-size: 10px;
  cursor: pointer;
  transition: all 0.2s;
}
.sn-scan-btn-small:hover {
  transform: translateY(-1px);
  box-shadow: 0 3px 8px rgba(91, 75, 201, 0.18);
}

.page-heading { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; }
.eyebrow { font-size: 10px; font-weight: 600; color: #aaa7b5; letter-spacing: 0.05em; margin-bottom: 6px; }
.page-heading h1 { font-size: 24px; font-weight: 600; color: #292541; letter-spacing: -0.02em; margin: 0 0 4px; }
.muted { color: #777486; font-size: 13px; margin: 0; }

.panel { background: #fff; border: 1px solid #eeedf3; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }

.dd { position: fixed; background: #fff; border: 1px solid #e9e7ef; border-radius: 8px; padding: 6px; width: 180px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); z-index: 9999; }
.dd-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0; background: transparent; text-align: left; font-size: 12px; color: #48445c; border-radius: 5px; cursor: pointer; transition: all 0.15s; }
.dd-item:hover { background: #f4f3ff; color: #6a5ce8; }
.dd-item.ok:hover { background: #eaf8f1; color: #3b9d76; }

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 14, 27, 0.45);
  backdrop-filter: blur(2px);
  display: grid;
  place-items: center;
  z-index: 100;
  padding: 20px;
}
.modal-content {
  background: #fff;
  border-radius: 12px;
  width: 100%;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  max-height: 90vh;
  box-sizing: border-box;
}
.modal-create-return {
  max-width: 650px;
}
.modal-inspect-return {
  max-width: 600px;
}
.modal-header {
  padding: 18px 24px;
  border-bottom: 1px solid #f0eff3;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-shrink: 0;
}
.modal-header h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: #292541;
}
.close-button {
  background: transparent;
  border: 0;
  font-size: 20px;
  color: #aaa7b5;
  cursor: pointer;
  padding: 0;
  line-height: 1;
}
.close-button:hover {
  color: #292541;
}
.modal-body {
  padding: 24px;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  flex: 1 1 auto;
}
.modal-footer {
  padding: 16px 24px;
  border-top: 1px solid #f0eff3;
  background: #fafafd;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-shrink: 0;
}

.req {
  color: #d47770;
  margin-left: 2px;
}
.form-group {
  margin-bottom: 16px;
}
.form-group label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  color: #48445c;
  margin-bottom: 6px;
}
.form-input {
  width: 100%;
  border: 1px solid #e9e7ef;
  border-radius: 6px;
  padding: 8px 12px;
  font-size: 12px;
  color: #292541;
  background: #fff;
  transition: border-color 0.2s, box-shadow 0.2s;
  height: 38px;
  box-sizing: border-box;
  font-family: inherit;
}
.form-input:focus {
  outline: none;
  border-color: #bd3c3c;
  box-shadow: 0 0 0 3px rgba(189, 60, 60, 0.08);
}
.field-error {
  color: #d47770;
  font-size: 10px;
  margin-top: 4px;
  display: block;
}
.delete-error {
  background: #fdf5f4;
  color: #d47770;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 12px;
  border: 1px solid #fadcd9;
  margin-bottom: 16px;
}

/* Return Form Grid */
.return-form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px 16px;
  margin-bottom: 20px;
}
.reference-fields-row {
  display: flex;
  gap: 10px;
}
.ref-type-field,
.ref-id-field {
  flex: 1;
  min-width: 0;
}
.ref-type-field label,
.ref-id-field label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  color: #48445c;
  margin-bottom: 6px;
}

/* Return Items Section */
.return-items-section {
  margin-bottom: 16px;
}
.return-items-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
.return-items-header h3 {
  font-size: 12px;
  font-weight: 600;
  color: #292541;
  margin: 0;
}
.add-item-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  border: 1px solid #e9e7ef;
  background: #fff;
  color: #48445c;
  border-radius: 6px;
  padding: 5px 10px;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}
.add-item-btn:hover {
  background: #fafafd;
  border-color: #dedbe8;
}

.return-item-card {
  background: #fafafd;
  border: 1px solid #eeedf3;
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 10px;
  transition: all 0.2s;
}
.return-item-card:hover {
  border-color: #dedbe8;
}
.return-item-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 8px;
  margin-bottom: 10px;
  border-bottom: 1px dashed #e9e7ef;
}
.return-item-num {
  font-size: 11px;
  font-weight: 600;
  color: #777486;
}
.btn-remove-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: transparent;
  border: 0;
  color: #d47770;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  padding: 3px 6px;
  border-radius: 4px;
  transition: all 0.15s;
}
.btn-remove-item:hover {
  background: #fff0f0;
}
.return-item-card-body {
  display: flex;
  gap: 10px;
  align-items: center;
}
.return-item-product {
  flex: 1;
  min-width: 0;
}
.return-item-qty {
  width: 140px;
  flex-shrink: 0;
}
.return-qty-field {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #fff;
  border: 1px solid #e9e7ef;
  border-radius: 6px;
  padding: 0 10px;
  height: 38px;
  box-sizing: border-box;
}
.return-qty-field input {
  width: 100%;
  border: 0;
  background: transparent;
  outline: 0;
  font-size: 12px;
  color: #292541;
  flex: 1;
  min-width: 0;
}
.return-unit-badge {
  font-size: 10px;
  font-weight: 600;
  color: #858291;
  text-transform: uppercase;
  flex-shrink: 0;
}

/* Inspection Cards */
.inspection-desc {
  font-size: 12px;
  color: #777486;
  margin: 0 0 16px;
  line-height: 1.5;
}
.inspection-card {
  margin-bottom: 14px;
  padding: 12px;
  border: 1px solid #e9e7ef;
  border-radius: 8px;
  background: #fafafd;
}
.inspection-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}
.inspection-name {
  font-size: 12px;
  color: #292541;
  font-weight: 600;
}
.inspection-qty {
  font-size: 11px;
  color: #777486;
  font-weight: 500;
}
.inspection-fields {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}
.inspection-field {
  flex: 1;
  min-width: 0;
}
.inspection-field label {
  font-size: 10px;
  color: #858291;
  display: block;
  margin-bottom: 4px;
  font-weight: 600;
}
.sn-label-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px;
  gap: 6px;
}
.sn-label-row label {
  margin: 0;
}

/* Buttons */
.cancel-button {
  background: #fff;
  border: 1px solid #e9e7ef;
  color: #777486;
  padding: 9px 16px;
  border-radius: 7px;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  height: 38px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
}
.cancel-button:hover {
  background: #fafafd;
  color: #48445c;
  border-color: #dedbe8;
}
.primary-button {
  background: #bd3c3c;
  border: 1px solid #bd3c3c;
  color: #fff;
  padding: 9px 16px;
  border-radius: 7px;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  height: 38px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
}
.primary-button:hover:not(:disabled) {
  background: #a83232;
  border-color: #a83232;
}
.primary-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.btn-complete-return {
  background: #3b9d76 !important;
  border-color: #3b9d76 !important;
}
.btn-complete-return:hover:not(:disabled) {
  background: #328a67 !important;
  border-color: #328a67 !important;
}

.pagination-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Toast */
.toast-notification {
  position: fixed;
  bottom: 24px;
  right: 24px;
  padding: 12px 20px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 500;
  z-index: 10001;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
  color: #fff;
  max-width: 300px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.toast-notification.success {
  background: #292541;
}
.toast-notification.error {
  background: #d47770;
}
.toast-notification.warning {
  background: #c48735;
}

@media (max-width: 640px) {
  .modal-backdrop {
    padding: 10px;
  }
  .modal-content {
    max-width: 100% !important;
    width: 100% !important;
    border-radius: 14px;
    max-height: 94vh;
    max-height: 94dvh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .modal-header {
    padding: 14px 16px;
    flex-shrink: 0;
  }
  .modal-header h2 {
    font-size: 15px;
  }
  .modal-body {
    padding: 14px 16px;
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }
  .modal-footer {
    padding: 12px 16px;
    flex-shrink: 0;
    flex-direction: column-reverse;
    gap: 8px;
  }
  .modal-footer button {
    width: 100%;
    height: 40px;
    font-size: 12px;
    justify-content: center;
  }
  .return-form-grid {
    grid-template-columns: 1fr;
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 16px;
  }
  .form-group {
    margin-bottom: 0;
  }
  .reference-fields-row {
    display: flex;
    gap: 8px;
  }
  .return-item-card {
    padding: 10px;
  }
  .return-item-card-body {
    flex-direction: column;
    gap: 8px;
  }
  .return-item-product {
    width: 100%;
  }
  .return-item-qty {
    width: 100%;
  }
  .inspection-fields {
    flex-direction: column;
    gap: 10px;
  }
  .inspection-field {
    width: 100%;
  }
}

@media (max-width: 380px) {
  .reference-fields-row {
    flex-direction: column;
    gap: 10px;
  }
}
</style>
