<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { Boxes, ChevronDown, FileBarChart, Package, Search } from 'lucide-vue-next'
import AppPagination from '../../components/ui/AppPagination.vue'
import AppSortableTh from '../../components/ui/AppSortableTh.vue'

interface StockBalance {
  id: string
  product_id: string
  warehouse_id: string
  warehouse_location_id: string
  quantity: string | number
  product?: { id: string; name: string; sku: string; minimum_stock: string | number; unit?: { code: string } }
  warehouse?: { id: string; name: string; code: string }
  warehouse_location?: { id: string; name: string }
}

interface Warehouse {
  id: string
  name: string
  code: string
}

const balances = ref<StockBalance[]>([])
const warehouses = ref<Warehouse[]>([])
const loading = ref(false)

const toastMessage = ref('')
const toastType = ref('error')
const showToast = (msg: string, type: string = 'error') => {
  toastMessage.value = msg
  toastType.value = type
  setTimeout(() => { toastMessage.value = '' }, 3000)
}

const lastSynced = ref('')

const search = ref('')
const warehouseFilter = ref('')
const sortBy = ref('')
const sortDir = ref<'asc' | 'desc'>('desc')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const from = ref(0)
const to = ref(0)
let searchTimer: ReturnType<typeof setTimeout> | null = null

const stats = ref({
  totalProducts: null as number | null,
  lowStockCount: null as number | null,
  pendingTransfers: null as number | null
})

const fetchBalances = async () => {
  loading.value = true
  try {
    const res = await axios.get('/stock', {
      params: {
        page: page.value,
        search: search.value || undefined,
        warehouse_id: warehouseFilter.value || undefined,
        sort_by: sortBy.value || undefined,
        sort_dir: sortBy.value ? sortDir.value : undefined,
      },
    })
    const p = res.data.data
    balances.value = p.data
    lastPage.value = p.last_page
    total.value = p.total
    from.value = p.from ?? 0
    to.value = p.to ?? 0
    lastSynced.value = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat saldo stok.', 'error')
  } finally {
    loading.value = false
  }
}

const handleSort = (field: string, dir: 'asc' | 'desc') => {
  sortBy.value = field
  sortDir.value = dir
  page.value = 1
  fetchBalances()
}

const exportCSV = () => {
  if (!balances.value.length) {
    showToast('Tidak ada data untuk diekspor', 'warning')
    return
  }
  
  const headers = ['Produk', 'SKU', 'Gudang', 'Lokasi', 'Kuantitas', 'Satuan', 'Status']
  const rows = balances.value.map(b => [
    `"${b.product?.name || ''}"`,
    `"${b.product?.sku || ''}"`,
    `"${b.warehouse?.name || ''}"`,
    `"${b.warehouse_location?.name || '-'}"`,
    b.quantity,
    `"${b.product?.unit?.code || ''}"`,
    `"${stockInfo(b).status}"`
  ])
  
  const csvContent = "data:text/csv;charset=utf-8," + 
    [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
    
  const encodedUri = encodeURI(csvContent)
  const link = document.createElement('a')
  link.setAttribute('href', encodedUri)
  link.setAttribute('download', `Laporan_Stok_${new Date().toISOString().slice(0,10)}.csv`)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const fetchOptions = async () => {
  try {
    const whRes = await axios.get('/warehouses')
    warehouses.value = whRes.data.data.data || whRes.data.data
  } catch (e) {
    console.error('Failed to fetch warehouses', e)
  }
  
  try {
    const dashboardRes = await axios.get('/dashboard')
    stats.value.totalProducts = dashboardRes.data.data.kpi.total_products_in_stock
    stats.value.lowStockCount = dashboardRes.data.data.kpi.low_stock_count
    stats.value.pendingTransfers = dashboardRes.data.data.kpi.pending_transfers
  } catch (e) {
    console.error('Failed to fetch dashboard KPI', e)
  }
}

const onSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchBalances() }, 300)
}
const onFilter = () => { page.value = 1; fetchBalances() }
const goTo = (p: number) => {
  if (p < 1 || p > lastPage.value) return
  page.value = p
  fetchBalances()
}

const stockInfo = (b: StockBalance) => {
  const stock = Number(b.quantity ?? 0)
  const min = Number(b.product?.minimum_stock ?? 0)
  if (stock <= 0) return { status: 'Habis', tone: 'danger' }
  if (stock <= min) return { status: 'Menipis', tone: 'warning' }
  return { status: 'Tersedia', tone: 'success' }
}

const pages = computed(() => Array.from({ length: lastPage.value }, (_, i) => i + 1))

onMounted(() => {
  fetchBalances()
  fetchOptions()
})
</script>

<template>
  <section class="stock-balance-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">INVENTARIS / SALDO</p>
        <h1>Saldo Stok</h1>
        <p class="muted">Lihat inventaris yang tersedia di seluruh gudang.</p>
      </div>
      <button class="primary-button" @click="exportCSV"><FileBarChart :size="17" /> Ekspor laporan</button>
    </div>
    
    <div class="balance-summary">
      <div>
        <span>Total produk di stok</span>
        <strong>{{ stats.totalProducts ?? '-' }}</strong>
        <small>Berdasarkan KPI dashboard</small>
      </div>
      <div>
        <span>Transfer tertunda</span>
        <strong class="total-stock">{{ stats.pendingTransfers ?? '-' }}</strong>
        <small>Sedang diproses</small>
      </div>
      <div>
        <span>Stok menipis</span>
        <strong class="summary-warning">{{ stats.lowStockCount ?? '-' }}</strong>
        <small>Perlu perhatian</small>
      </div>
      <div>
        <span>Terakhir disinkronisasi</span>
        <strong>{{ lastSynced || '-' }}</strong>
        <small>Semua gudang</small>
      </div>
    </div>
    
    <div class="panel products-panel">
      <div class="products-toolbar" style="padding: 19px 17px; display: flex; gap: 10px;">
        <div class="product-search" style="display: flex; align-items: center; gap: 8px; flex: 1; color: #aaa7b5; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 0 10px; height: 34px;">
          <Search :size="16" />
          <input v-model="search" @input="onSearch" placeholder="Cari..." style="width: 100%; border: 0; outline: 0; background: transparent; color: #48445c; font-size: 11px;" />
        </div>
        <div class="select-wrapper" style="min-width: 200px;">
          <select v-model="warehouseFilter" @change="onFilter" style="width: 100%; height: 34px; appearance: none; border: 1px solid #e9e7ef; background: #fff; border-radius: 6px; padding: 0 30px 0 11px; color: #777486; font-size: 11px; outline: none;">
            <option value="">Semua gudang</option>
            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
          </select>
          <ChevronDown :size="14" class="select-icon" />
        </div>
      </div>
      
      <div class="table-wrap" style="overflow-x: auto; padding-bottom: 20px;">
        <table class="products-table balance-table" style="width: 100%; min-width: 900px; border-collapse: collapse; font-size: 10px;">
          <thead>
            <tr>
              <AppSortableTh field="product" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Produk</AppSortableTh>
              <AppSortableTh field="warehouse" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Gudang</AppSortableTh>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Lokasi</th>
              <AppSortableTh field="quantity" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Kuantitas</AppSortableTh>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading && !balances.length"><td colspan="5" style="padding: 24px 19px; color: #aaa7b4; text-align: center;">Memuat data...</td></tr>
            <tr v-else-if="!balances.length"><td colspan="5" style="padding: 24px 19px; color: #aaa7b4; text-align: center;">Belum ada saldo stok.</td></tr>
            <tr v-for="b in balances" :key="b.id">
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6;">
                <div class="product-name" style="display: flex; align-items: center; gap: 10px;">
                  <div class="product-icon" style="display: grid; place-items: center; width: 28px; height: 28px; border-radius: 7px; background: #f0f4ff; color: #4361ee;">
                    <Package :size="16" />
                  </div>
                  <div>
                    <strong style="display: block; color: #292541; font-size: 11px;">{{ b.product?.name }}</strong>
                    <span style="display: block; color: #aaa7b4; font-size: 9px; margin-top: 4px;">{{ b.product?.sku }}</span>
                  </div>
                </div>
              </td>
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6; color: #555164;">{{ b.warehouse?.name }}</td>
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6; color: #555164;">{{ b.warehouse_location?.name || '—' }}</td>
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6; color: #292541; font-weight: 600;">
                {{ Number(b.quantity) }} {{ b.product?.unit?.code }}
              </td>
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6;">
                <span :class="['status-pill', stockInfo(b).tone]" style="padding: 4px 7px; border-radius: 4px; font-size: 9px;">
                  {{ stockInfo(b).status }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <AppPagination
        :page="page"
        :last-page="lastPage"
        :total="total"
        :from="from"
        :to="to"
        @change="goTo"
      >
        <template #info>
          <span>Menampilkan {{ from }}–{{ to }} dari {{ total }} item</span>
        </template>
      </AppPagination>
    </div>

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
.stock-balance-page { display: block; }
.status-pill.success { color: #3b9d76; background: #eaf8f1; }
.status-pill.warning { color: #c48735; background: #fff4df; }
.status-pill.danger { color: #d47770; background: #ffeaeb; }
.pagination-button:disabled { opacity: 0.5; cursor: not-allowed; }
.select-wrapper { position: relative; }
.select-wrapper select { appearance: none; padding-right: 36px; }
.select-icon { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #aaa7b4; pointer-events: none; }

/* Toast */
.toast-notification { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 8px; font-size: 12px; font-weight: 500; z-index: 10001; box-shadow: 0 10px 30px rgba(0,0,0,0.1); color: #fff; max-width: 300px; display: flex; align-items: center; gap: 10px; }
.toast-notification.success { background: #292541; }
.toast-notification.error { background: #d47770; }
.toast-notification.warning { background: #c48735; }
</style>
