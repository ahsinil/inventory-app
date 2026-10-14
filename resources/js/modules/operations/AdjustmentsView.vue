<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { Plus, Search, Ellipsis, ClipboardCheck, CheckCircle2, X } from 'lucide-vue-next'
import DateRangePicker from '../../components/DateRangePicker.vue'
import ProductCombobox from '../../components/forms/ProductCombobox.vue'
import AppPagination from '../../components/ui/AppPagination.vue'
import AppSortableTh from '../../components/ui/AppSortableTh.vue'

interface Product { id: string; name: string; sku: string; unit?: { code: string } }
interface Warehouse { id: string; name: string; code: string }
interface Location { id: string; code: string; name: string; is_default: boolean }
interface AdjItem {
  id: string
  product_id: string
  warehouse_location_id: string
  system_quantity: number
  actual_quantity: number
  difference: number
  product?: Product
  warehouse_location?: { code: string; name: string }
}
interface Adjustment {
  id: string
  adjustment_number: string
  warehouse_id: string
  status: string
  reason: string
  remarks: string | null
  created_at: string
  warehouse?: Warehouse
  items: AdjItem[]
}

const adjustments = ref<Adjustment[]>([])
const warehouses = ref<Warehouse[]>([])
const products = ref<Product[]>([])
const loading = ref(false)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const fromItem = ref(0)
const toItem = ref(0)
const search = ref('')
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
  searchTimer = setTimeout(() => { page.value = 1; fetchAdjustments() }, 300)
}

const onFilterChange = () => {
  page.value = 1
  fetchAdjustments()
}

const pages = computed(() => Array.from({ length: lastPage.value }, (_, i) => i + 1))

const statusLabel = (s: string) => ({ DRAFT: 'DRAFT', POSTED: 'SUDAH DIPOSTING' } as Record<string, string>)[s] || s
const unitOf = (id: string) => products.value.find(p => p.id === id)?.unit?.code || 'pcs'

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

const fetchAdjustments = async () => {
  loading.value = true
  try {
    const res = await axios.get('/adjustments', { 
      params: { 
        page: page.value,
        search: search.value || undefined,
        start_date: dateFrom.value || undefined,
        end_date: dateTo.value || undefined,
        sort_by: sortBy.value || undefined,
        sort_dir: sortBy.value ? sortDir.value : undefined
      } 
    })
    adjustments.value = res.data.data.data
    lastPage.value = res.data.data.last_page
    total.value = res.data.data.total
    fromItem.value = res.data.data.from ?? 0
    toItem.value = res.data.data.to ?? 0
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat penyesuaian', 'error')
  } finally {
    loading.value = false
  }
}

const handleSort = (field: string, dir: 'asc' | 'desc') => {
  sortBy.value = field
  sortDir.value = dir
  page.value = 1
  fetchAdjustments()
}

const exportCSV = () => {
  if (!adjustments.value.length) {
    showToast('Tidak ada data untuk diekspor', 'warning')
    return
  }
  
  const headers = ['Nomor', 'Gudang', 'Alasan', 'Status', 'Catatan', 'Tanggal']
  const rows = adjustments.value.map(a => [
    `"${a.adjustment_number || ''}"`,
    `"${a.warehouse?.name || ''}"`,
    `"${a.reason || ''}"`,
    `"${a.status || ''}"`,
    `"${a.remarks || ''}"`,
    `"${formatDate(a.created_at)}"`
  ])
  
  const csvContent = "data:text/csv;charset=utf-8," + 
    [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
    
  const encodedUri = encodeURI(csvContent)
  const link = document.createElement('a')
  link.setAttribute('href', encodedUri)
  link.setAttribute('download', `Penyesuaian_Stok_${new Date().toISOString().slice(0,10)}.csv`)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const goTo = (p: number) => {
  if (p < 1 || p > lastPage.value) return
  page.value = p
  fetchAdjustments()
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
interface FormItem { product_id: string; warehouse_location_id: string; actual_quantity: number | string }
const emptyItem = (): FormItem => ({ product_id: '', warehouse_location_id: '', actual_quantity: 0 })
const reasons = ['Stok Opname', 'Barang Rusak', 'Barang Hilang', 'Koreksi Input', 'Lainnya']

const showCreateModal = ref(false)
const saving = ref(false)
const createErrors = ref<Record<string, string[]>>({})
const createForm = ref({ warehouse_id: '', reason: 'Stok Opname', remarks: '', items: [emptyItem()] })
const locations = ref<Location[]>([])
const balances = ref<Record<string, number>>({}) // key: product_id|location_id

const openCreate = () => {
  createForm.value = { warehouse_id: '', reason: 'Stok Opname', remarks: '', items: [emptyItem()] }
  createErrors.value = {}
  locations.value = []
  balances.value = {}
  showCreateModal.value = true
}

watch(() => createForm.value.warehouse_id, async (id) => {
  locations.value = []
  balances.value = {}
  createForm.value.items = [emptyItem()]
  if (!id) return
  try {
    const [locRes, stockRes] = await Promise.all([
      axios.get(`/warehouses/${id}/locations`),
      axios.get('/stock', { params: { warehouse_id: id, per_page: 500 } })
    ])
    locations.value = locRes.data.data.data || locRes.data.data || []
    const map: Record<string, number> = {}
    const rows = stockRes.data.data.data || stockRes.data.data || []
    rows.forEach((b: any) => { map[`${b.product_id}|${b.warehouse_location_id}`] = Number(b.quantity) })
    balances.value = map
    const def = locations.value.find(l => l.is_default)?.id || locations.value[0]?.id || ''
    createForm.value.items.forEach(i => { i.warehouse_location_id = def })
  } catch (e: any) {
    createErrors.value = { general: ['Gagal memuat lokasi/stok gudang.'] }
    showToast('Gagal memuat lokasi/stok gudang', 'error')
  }
})

const systemQty = (i: FormItem) => i.product_id && i.warehouse_location_id ? (balances.value[`${i.product_id}|${i.warehouse_location_id}`] ?? 0) : 0
const diffOf = (i: FormItem) => i.actual_quantity === '' ? null : Number(i.actual_quantity) - systemQty(i)
const fmtDiff = (d: number) => (d > 0 ? '+' : '') + d

const addItem = () => {
  const def = locations.value.find(l => l.is_default)?.id || locations.value[0]?.id || ''
  createForm.value.items.push({ ...emptyItem(), warehouse_location_id: def })
}
const removeItem = (idx: number) => { if (createForm.value.items.length > 1) createForm.value.items.splice(idx, 1) }

const submitCreate = async () => {
  saving.value = true
  createErrors.value = {}
  const payload = {
    warehouse_id: createForm.value.warehouse_id,
    reason: createForm.value.reason,
    remarks: createForm.value.remarks || null,
    items: createForm.value.items.map(i => ({
      product_id: i.product_id,
      warehouse_location_id: i.warehouse_location_id,
      system_quantity: systemQty(i),
      actual_quantity: i.actual_quantity === '' ? null : Number(i.actual_quantity)
    }))
  }
  try {
    await axios.post('/adjustments', payload)
    showCreateModal.value = false
    showToast('Draft penyesuaian berhasil dibuat', 'success')
    page.value = 1
    await fetchAdjustments()
  } catch (e: any) {
    createErrors.value = e.response?.data?.errors ?? { general: [e.response?.data?.message || 'Gagal menyimpan.'] }
    showToast('Gagal membuat penyesuaian', 'error')
  } finally {
    saving.value = false
  }
}

// DETAIL MODAL
const showDetail = ref(false)
const detail = ref<Adjustment | null>(null)
const openDetail = (a: Adjustment) => { closeDropdown(); detail.value = a; showDetail.value = true }

// POST
const posting = ref(false)
const postError = ref('')
const confirmPost = ref<Adjustment | null>(null)

const openConfirmPost = (a: Adjustment) => {
  closeDropdown()
  confirmPost.value = a
  postError.value = ''
}

const postAdjustment = async () => {
  if (!confirmPost.value) return
  posting.value = true
  postError.value = ''
  try {
    await axios.post(`/adjustments/${confirmPost.value.id}/post`)
    confirmPost.value = null
    showDetail.value = false
    showToast('Penyesuaian berhasil diposting', 'success')
    await fetchAdjustments()
  } catch (e: any) {
    postError.value = e.response?.data?.message || 'Gagal memposting penyesuaian.'
    showToast(postError.value, 'error')
  } finally {
    posting.value = false
  }
}

const formatDate = (d: string | null) => {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' })
}
const totalDiff = (a: Adjustment) => a.items.reduce((s, i) => s + Number(i.difference), 0)

onMounted(() => {
  document.addEventListener('click', closeDropdown)
  fetchInitialData().then(fetchAdjustments)
})
onUnmounted(() => document.removeEventListener('click', closeDropdown))
</script>

<template>
  <section class="adjustments-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">INVENTARIS / OPERASI / PENYESUAIAN</p>
        <h1>Penyesuaian Stok</h1>
        <p class="muted">Koreksi selisih stok sistem dengan jumlah fisik di gudang.</p>
      </div>
      <div style="display: flex; gap: 10px;">
        <button class="secondary-button" @click="exportCSV"><ClipboardCheck :size="17" /> Ekspor laporan</button>
        <button class="primary-button" @click="openCreate"><Plus :size="17" /> Penyesuaian baru</button>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
      </div>
      <div class="adj-toolbar" style="gap: 10px;">
        <div class="product-search"><Search :size="15" /><input v-model="search" @input="onSearch" placeholder="Cari nomor, alasan, gudang..." aria-label="Cari" /></div>
        <DateRangePicker 
          :from="dateFrom" 
          :to="dateTo" 
          @update:from="dateFrom = $event" 
          @update:to="dateTo = $event" 
          @change="onFilterChange" 
        />
      </div>

      <p v-if="loading && !adjustments.length" class="state-msg" style="text-align: center;">Memuat penyesuaian...</p>
      <p v-else-if="!adjustments.length" class="state-msg" style="text-align: center;">Belum ada riwayat penyesuaian stok.</p>

      <div v-if="adjustments.length" class="table-scroll">
        <table class="adj-table">
          <thead>
            <tr>
              <AppSortableTh field="adjustment_number" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Nomor / Tgl</AppSortableTh>
              <AppSortableTh field="warehouse" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Gudang</AppSortableTh>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Alasan</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Item</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Selisih</th>
              <AppSortableTh field="status" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Status</AppSortableTh>
              <th style="padding: 10px 19px; text-align: right; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in adjustments" :key="a.id">
              <td>
                <div class="adj-name">
                  <div class="adj-icon"><ClipboardCheck :size="16" /></div>
                  <div><strong>{{ a.adjustment_number }}</strong><span>{{ formatDate(a.created_at) }}</span></div>
                </div>
              </td>
              <td><strong>{{ a.warehouse?.name || '-' }}</strong></td>
              <td>{{ a.reason }}</td>
              <td>{{ a.items.length }} barang</td>
              <td><span :class="totalDiff(a) > 0 ? 'pos' : totalDiff(a) < 0 ? 'neg' : ''">{{ fmtDiff(totalDiff(a)) }}</span></td>
              <td><span class="status-pill" :class="a.status === 'POSTED' ? 'success' : 'warning'">{{ statusLabel(a.status) }}</span></td>
              <td style="text-align:right;">
                <button class="dots-button" aria-label="Aksi" @click.stop="toggleDropdown($event, a.id)"><Ellipsis :size="16" /></button>
                <Teleport to="body">
                  <div v-if="activeDropdown === a.id" class="dd" :style="{ top: dropdownY + 'px', left: dropdownX + 'px' }" @click.stop>
                    <button class="dd-item" @click="openDetail(a)"><ClipboardCheck :size="14" /> Lihat Detail</button>
                    <button v-if="a.status !== 'POSTED'" class="dd-item ok" @click="openConfirmPost(a)"><CheckCircle2 :size="14" /> Posting Penyesuaian</button>
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

    <Teleport to="body">
      <!-- MODAL CREATE -->
      <Transition name="fade">
        <div v-if="showCreateModal" class="modal-backdrop" @click="showCreateModal = false">
          <div class="modal-content" style="max-width: 640px;" @click.stop>
            <div class="modal-header">
              <button type="button" class="close-x" aria-label="Tutup" @click="showCreateModal = false"><X :size="18" /></button>
              <h2>Penyesuaian Stok Baru</h2>
              <p>Masukkan jumlah fisik; selisih dihitung otomatis dari stok sistem.</p>
            </div>
            <form @submit.prevent="submitCreate" class="modern-form" style="max-height: 75vh; overflow-y: auto;" novalidate>
              <p v-if="createErrors.general" class="delete-error">{{ createErrors.general[0] }}</p>

              <div class="form-row">
                <div class="form-group">
                  <label>Gudang <span class="req">*</span></label>
                  <select v-model="createForm.warehouse_id" :class="{ 'input-error': createErrors.warehouse_id }">
                    <option value="" disabled>Pilih Gudang...</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                  </select>
                  <small v-if="createErrors.warehouse_id" class="field-error">{{ createErrors.warehouse_id[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Alasan <span class="req">*</span></label>
                  <select v-model="createForm.reason">
                    <option v-for="r in reasons" :key="r" :value="r">{{ r }}</option>
                  </select>
                </div>
              </div>

              <div class="form-group">
                <label>Catatan</label>
                <input v-model="createForm.remarks" type="text" placeholder="Opsional" />
              </div>

              <div class="form-group" v-if="createForm.warehouse_id">
                <label style="display:flex; justify-content:space-between; align-items:center;">
                  <span>Daftar Barang <span class="req">*</span></span>
                  <button type="button" class="link-btn" @click="addItem">+ Tambah Baris</button>
                </label>

                <div v-for="(item, idx) in createForm.items" :key="idx" class="item-card">
                  <div class="item-top">
                    <div style="flex: 1;">
                      <ProductCombobox
                        v-model="item.product_id"
                        :products="products"
                        placeholder="Ketik nama produk atau SKU..."
                      />
                    </div>
                    <button type="button" class="rm-btn" aria-label="Hapus baris" @click="removeItem(idx)"><X :size="15" /></button>
                  </div>
                  <div class="item-bottom">
                    <select v-model="item.warehouse_location_id" class="grow">
                      <option value="" disabled>Pilih Rak...</option>
                      <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.code }} - {{ l.name }}</option>
                    </select>
                    <div class="qty-box">
                      <span class="sys">Sistem: {{ systemQty(item) }}</span>
                      <input v-model="item.actual_quantity" type="number" step="0.01" min="0" placeholder="Fisik" />
                      <span class="unit">{{ item.product_id ? unitOf(item.product_id) : 'pcs' }}</span>
                    </div>
                  </div>
                  <div v-if="diffOf(item) !== null" class="diff-line" :class="diffOf(item)! > 0 ? 'pos' : diffOf(item)! < 0 ? 'neg' : ''">
                    Selisih: {{ fmtDiff(diffOf(item)!) }} {{ item.product_id ? unitOf(item.product_id) : '' }}
                  </div>
                </div>
                <small v-if="createErrors.items" class="field-error">{{ createErrors.items[0] }}</small>
              </div>

              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showCreateModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving || !createForm.warehouse_id">{{ saving ? 'Menyimpan...' : 'Simpan sebagai Draft' }}</button>
              </div>
            </form>
          </div>
        </div>
      </Transition>

      <!-- MODAL DETAIL -->
      <Transition name="fade">
        <div v-if="showDetail && detail" class="modal-backdrop" @click="showDetail = false">
          <div class="modal-content" style="max-width: 600px;" @click.stop>
            <div class="modal-header">
              <button type="button" class="close-x" aria-label="Tutup" @click="showDetail = false"><X :size="18" /></button>
              <h2>{{ detail.adjustment_number }}</h2>
              <p>{{ detail.warehouse?.name }} · {{ detail.reason }} · {{ statusLabel(detail.status) }}</p>
            </div>
            <div class="modern-form" style="max-height: 70vh; overflow-y: auto;">
              <p v-if="detail.remarks" class="muted" style="margin:0;">Catatan: {{ detail.remarks }}</p>
              <div v-for="i in detail.items" :key="i.id" class="item-card">
                <div style="display:flex; justify-content:space-between; gap:10px;">
                  <div>
                    <strong style="font-size:13px; color:#292541;">{{ i.product?.name }}</strong>
                    <span style="display:block; font-size:10px; color:#aaa7b4; margin-top:3px;">Rak: {{ i.warehouse_location?.code || '-' }}</span>
                  </div>
                  <div style="text-align:right; font-size:11px; color:#555164;">
                    <div>Sistem: {{ Number(i.system_quantity) }} → Fisik: {{ Number(i.actual_quantity) }} {{ i.product?.unit?.code }}</div>
                    <strong :class="Number(i.difference) > 0 ? 'pos' : Number(i.difference) < 0 ? 'neg' : ''">{{ fmtDiff(Number(i.difference)) }}</strong>
                  </div>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showDetail = false">Tutup</button>
                <button v-if="detail.status !== 'POSTED'" type="button" class="primary-button" :disabled="posting" style="background:#3b9d76; border-color:#3b9d76;" @click="openConfirmPost(detail)">{{ posting ? 'Memproses...' : 'Posting Penyesuaian' }}</button>
              </div>
            </div>
          </div>
        </div>
      </Transition>

      <!-- MODAL CONFIRM POST -->
      <Transition name="fade">
        <div v-if="confirmPost" class="modal-backdrop" @click="confirmPost = null">
          <div class="modal-content" style="max-width: 400px; text-align: center; padding: 30px 24px;" @click.stop>
            <div style="width: 50px; height: 50px; border-radius: 50%; background: #f4f3ff; color: #6a5ce8; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
              <ClipboardCheck :size="24" />
            </div>
            <h2 style="font-size: 18px; color: #292541; margin: 0 0 8px;">Posting Penyesuaian?</h2>
            <p style="color: #777486; font-size: 12px; margin: 0 0 24px; line-height: 1.5;">
              Anda akan memposting <strong>{{ confirmPost.adjustment_number }}</strong>.
              <br/>Stok akan langsung disesuaikan dan aksi ini <strong>tidak bisa dibatalkan</strong>.
            </p>
            <p v-if="postError" class="delete-error" style="text-align: left;">{{ postError }}</p>
            <div style="display: flex; gap: 10px; justify-content: center;">
              <button type="button" class="cancel-button" style="flex: 1; text-align: center; justify-content: center;" @click="confirmPost = null">Batal</button>
              <button type="button" class="primary-button" style="flex: 1; text-align: center; justify-content: center;" :disabled="posting" @click="postAdjustment()">
                {{ posting ? 'Memproses...' : 'Ya, Posting' }}
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
  </section>
</template>

<style scoped>
.adjustments-page { display: block; }
.panel { overflow: hidden; }
.state-msg { padding: 24px 22px; color: #aaa7b4; font-size: 11px; margin: 0; }
.adj-toolbar { display: flex; padding: 0 19px 17px; }
.adj-toolbar .product-search { display: flex; align-items: center; gap: 8px; color: #aaa7b5; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 0 10px; height: 34px; max-width: 320px; width: 100%; }
.adj-toolbar input { width: 100%; border: 0; outline: 0; background: transparent; color: #48445c; font-size: 11px; }
.table-scroll { overflow-x: auto; }
.adj-table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 11px; }
.adj-table th { text-align: left; padding: 10px 19px; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3; }
.adj-table td { padding: 12px 19px; color: #555164; border-bottom: 1px solid #f3f2f6; white-space: nowrap; }
.adj-table tr:last-child td { border-bottom: 0; }
.adj-name { display: flex; align-items: center; gap: 10px; }
.adj-name strong { display: block; font-size: 12px; color: #292541; margin-bottom: 2px; }
.adj-name span { font-size: 10px; color: #aaa7b4; }
.adj-icon { width: 32px; height: 32px; border-radius: 8px; background: #fff2f2; color: #bd3c3c; display: grid; place-items: center; }
.status-pill { padding: 4px 8px; border-radius: 4px; font-size: 9px; font-weight: 600; }
.status-pill.success { color: #3b9d76; background: #eaf8f1; }
.status-pill.warning { color: #c48735; background: #fff4df; }
.dots-button { background: none; border: 0; color: #aaa7b5; cursor: pointer; padding: 4px; }
.pos { color: #3b9d76; font-weight: 600; }
.neg { color: #bd3c3c; font-weight: 600; }
.pager { display: flex; justify-content: flex-end; gap: 4px; padding: 14px 19px; border-top: 1px solid #f0eff3; }
.pager button { padding: 6px 10px; border-radius: 6px; border: 1px solid #eeedf3; background: #fff; color: #48445c; font-size: 11px; cursor: pointer; }
.pager button:disabled { opacity: .5; cursor: not-allowed; }

.dd { position: fixed; background: #fff; border: 1px solid #eeedf3; border-radius: 8px; padding: 6px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 9999; min-width: 190px; }
.dd-item { display: flex; align-items: center; gap: 6px; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #555164; border-radius: 5px; cursor: pointer; }
.dd-item:hover { background: #fafafd; }
.dd-item.ok { color: #3b9d76; }
.dd-item.ok:hover { background: #eaf8f1; }

.req { color: #d47770; }
.field-error { display: block; color: #d47770; font-size: 10px; margin-top: 5px; }
.delete-error { color: #d47770; background: #fff2f2; border-radius: 6px; padding: 9px 12px; font-size: 11px; line-height: 1.5; margin: 0 0 8px; }
.link-btn { background: none; border: 0; color: #bd3c3c; font-size: 10px; cursor: pointer; font-weight: 600; }

.item-card { padding: 12px 14px; border: 1px solid #eeedf3; border-radius: 10px; margin-bottom: 10px; background: #fff; display: flex; flex-direction: column; gap: 10px; }
.item-top, .item-bottom { display: flex; gap: 10px; align-items: center; }
.grow { flex: 1; }
.rm-btn { background: none; border: 0; color: #d47770; cursor: pointer; display: grid; place-items: center; padding: 4px; }
.qty-box { display: flex; align-items: center; gap: 8px; background: #fafafd; border: 1px solid #e9e7ef; padding: 4px 12px; border-radius: 8px; transition: border-color .2s; }
.qty-box:hover, .qty-box:focus-within { border-color: #bd3c3c; }
.qty-box .sys { font-size: 10px; color: #aaa7b4; white-space: nowrap; }
.qty-box .unit { color: #aaa7b4; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.modern-form .qty-box input { border: 0; background: transparent; width: 64px; text-align: right; padding: 4px 0; font-size: 14px; font-weight: 600; color: #292541; box-shadow: none; }
.diff-line { font-size: 11px; color: #858291; }

.fade-enter-active, .fade-leave-active { transition: opacity .25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.fade-enter-active .modal-content { transition: all .3s cubic-bezier(.34,1.56,.64,1); }
.fade-enter-from .modal-content { transform: translateY(20px) scale(.95); opacity: 0; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(41,37,65,.4); backdrop-filter: blur(4px); display: flex; justify-content: center; align-items: center; z-index: 10000; }
.modal-content { background: #fff; border-radius: 14px; width: 100%; max-width: 440px; box-shadow: 0 20px 40px rgba(0,0,0,.1), 0 1px 3px rgba(0,0,0,.05); overflow: hidden; }
.modal-header { position: relative; padding: 24px 28px 18px; border-bottom: 1px solid #f0eff3; }
.modal-header h2 { font-size: 16px; color: #292541; margin: 0; }
.modal-header p { color: #aaa7b4; font-size: 11px; margin: 6px 0 0; }
.close-x { position: absolute; top: 16px; right: 16px; background: none; border: 0; color: #aaa7b4; cursor: pointer; padding: 4px; border-radius: 6px; display: grid; place-items: center; }
.close-x:hover { background: #f4f3f7; color: #48445c; }
.modern-form { padding: 24px 28px; display: flex; flex-direction: column; gap: 14px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group label { display: block; font-size: 10px; font-weight: 600; color: #858291; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
.modern-form input, .modern-form select { width: 100%; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 8px; padding: 10px 14px; font-size: 12px; color: #48445c; outline: none; transition: all .2s; box-sizing: border-box; }
.modern-form input:focus, .modern-form select:focus { border-color: #bd3c3c; background: #fff; box-shadow: 0 0 0 3px rgba(189,60,60,.1); }
.modern-form input.input-error, .modern-form select.input-error { border-color: #d47770; background: #fffafb; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px; padding-top: 18px; border-top: 1px solid #f0eff3; }
.cancel-button { background: #fff; border: 1px solid #e9e7ef; color: #777486; padding: 10px 16px; border-radius: 7px; font-size: 11px; cursor: pointer; transition: all .2s; }
.cancel-button:hover { background: #fafafd; color: #48445c; }
.pagination-button:disabled { opacity: 0.5; cursor: not-allowed; }

/* Toast */
.toast-notification { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 8px; font-size: 12px; font-weight: 500; z-index: 10001; box-shadow: 0 10px 30px rgba(0,0,0,0.1); color: #fff; max-width: 300px; display: flex; align-items: center; gap: 10px; }
.toast-notification.success { background: #292541; }
.toast-notification.error { background: #d47770; }
.toast-notification.warning { background: #c48735; }
</style>
