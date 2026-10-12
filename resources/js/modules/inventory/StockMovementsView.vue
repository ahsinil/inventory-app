<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { ArrowDownToLine, ArrowUpRight, ArrowLeftRight, Download, Filter, Search } from 'lucide-vue-next'
import DateRangePicker from '../../components/DateRangePicker.vue'

const movements = ref<any[]>([])
const warehouses = ref<any[]>([])
const categories = ref<any[]>([])
const loading = ref(false)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const fromItem = ref(0)
const toItem = ref(0)
const search = ref('')
const activeWarehouse = ref('')
const activeCategory = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const stats = ref({
  total_in: 0,
  total_out: 0,
  transfer_count: 0
})
let searchTimer: ReturnType<typeof setTimeout> | null = null
const activeFilter = ref('all')

const toastMessage = ref('')
const toastType = ref('error')
const showToast = (msg: string, type: string = 'error') => {
  toastMessage.value = msg
  toastType.value = type
  setTimeout(() => { toastMessage.value = '' }, 3000)
}

const onSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchMovements() }, 300)
}

const onFilterChange = () => {
  page.value = 1
  fetchMovements()
}

const fetchMovements = async () => {
  loading.value = true
  const params = {
    search: search.value || undefined,
    type: activeFilter.value !== 'all' ? activeFilter.value : undefined,
    warehouse_id: activeWarehouse.value || undefined,
    category_id: activeCategory.value || undefined,
    start_date: dateFrom.value || undefined,
    end_date: dateTo.value || undefined
  }

  try {
    const [res, statsRes] = await Promise.all([
      axios.get('/reports/movements', { params: { ...params, page: page.value } }),
      axios.get('/reports/movements/stats', { params })
    ])
    movements.value = res.data.data.data
    lastPage.value = res.data.data.last_page
    total.value = res.data.data.total
    fromItem.value = res.data.data.from ?? 0
    toItem.value = res.data.data.to ?? 0

    stats.value = statsRes.data.data
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat pergerakan stok', 'error')
  } finally {
    loading.value = false
  }
}

const exportCSV = () => {
  if (!movements.value.length) {
    showToast('Tidak ada data pergerakan stok untuk diekspor', 'warning')
    return
  }

  const headers = ['ID Pergerakan', 'Tipe', 'SKU', 'Produk', 'Gudang', 'Lokasi', 'Kuantitas', 'Dilakukan Oleh', 'Tanggal']
  const rows = movements.value.map(m => [
    `"${m.id.substring(0, 8).toUpperCase()}"`,
    `"${formatType(m.reference_type, m.type)}"`,
    `"${m.product?.sku || ''}"`,
    `"${m.product?.name || ''}"`,
    `"${m.warehouse?.name || ''}"`,
    `"${m.warehouse_location?.code || ''}"`,
    getQtyLabel(m.quantity),
    `"${m.user?.name || ''}"`,
    `"${formatDate(m.created_at)}"`
  ])

  const csvContent = "data:text/csv;charset=utf-8," + 
    [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
    
  const encodedUri = encodeURI(csvContent)
  const link = document.createElement('a')
  link.setAttribute('href', encodedUri)
  link.setAttribute('download', `Pergerakan_Stok_${new Date().toISOString().slice(0,10)}.csv`)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const setFilter = (f: string) => {
  activeFilter.value = f
  page.value = 1
  fetchMovements()
}

const goTo = (p: number) => {
  if (p < 1 || p > lastPage.value) return
  page.value = p
  fetchMovements()
}

const pages = computed(() => Array.from({ length: lastPage.value }, (_, i) => i + 1))

const formatDate = (d: string) => {
  if (!d) return '-'
  return new Date(d).toLocaleString('id-ID', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const formatType = (refType: string, dbType: string) => {
  if (refType === 'App\\Models\\StockTransfer') return 'Transfer'
  if (refType === 'App\\Models\\Receipt') return 'Penerimaan'
  if (refType === 'App\\Models\\StockAdjustment') return 'Penyesuaian'
  if (refType === 'App\\Models\\StockReturn') return 'Retur'
  return dbType === 'IN' ? 'Penerimaan' : 'Pengeluaran'
}

const getDirection = (refType: string, dbType: string) => {
  if (refType === 'App\\Models\\StockTransfer') return 'transfer'
  return dbType === 'IN' ? 'in' : 'out'
}

const getQtyLabel = (qty: string) => {
  const n = Number(qty)
  return n > 0 ? `+${n}` : `${n}`
}

const fetchInitialData = async () => {
  try {
    const [whRes, catRes] = await Promise.all([
      axios.get('/warehouses', { params: { per_page: 100 } }),
      axios.get('/categories', { params: { flat: 1 } })
    ])
    warehouses.value = whRes.data.data.data || whRes.data.data
    categories.value = catRes.data.data.data || catRes.data.data
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat gudang dan kategori', 'error')
  }
}

onMounted(() => {
  fetchInitialData().then(fetchMovements)
})
</script>

<template>
  <section class="movements-page">
    <div class="page-heading">
      <div><p class="eyebrow">INVENTARIS / PERGERAKAN STOK</p><h1>Pergerakan stok</h1><p class="muted">Lacak setiap perubahan inventaris di seluruh gudang Anda.</p></div>
      <button class="secondary-button" @click="exportCSV"><Download :size="17" /> Ekspor laporan</button>
    </div>
    <div class="movement-summary-grid">
      <div class="panel movement-stat"><span class="stat-icon green"><ArrowDownToLine :size="18" /></span><div><p>Total diterima</p><strong>+{{ stats.total_in.toLocaleString('id-ID') }} <small>unit</small></strong><span class="positive">Berdasarkan filter</span></div></div>
      <div class="panel movement-stat"><span class="stat-icon red"><ArrowUpRight :size="18" /></span><div><p>Total dikeluarkan</p><strong>-{{ Math.abs(stats.total_out).toLocaleString('id-ID') }} <small>unit</small></strong><span class="negative">Berdasarkan filter</span></div></div>
      <div class="panel movement-stat"><span class="stat-icon blue"><ArrowLeftRight :size="18" /></span><div><p>Transfer</p><strong>{{ stats.transfer_count.toLocaleString('id-ID') }} <small>perpindahan</small></strong><span class="neutral">Berdasarkan filter</span></div></div>
    </div>
    <div class="panel movement-panel">
      <div class="panel-toolbar">
        <div><h2>Log aktivitas</h2><p>Transaksi dan penyesuaian inventaris terbaru</p></div>
        <div class="toolbar-actions">
          <DateRangePicker 
            :from="dateFrom" 
            :to="dateTo" 
            @update:from="dateFrom = $event" 
            @update:to="dateTo = $event" 
            @change="onFilterChange" 
          />
          <select v-model="activeWarehouse" @change="onFilterChange" class="filter-select">
            <option value="">Semua Gudang</option>
            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
          </select>
          <select v-model="activeCategory" @change="onFilterChange" class="filter-select">
            <option value="">Semua Kategori</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
      </div>
      <div class="filter-pills">
        <div style="display: flex; gap: 8px;">
          <button class="filter-pill" :class="{ active: activeFilter === 'all' }" @click="setFilter('all')">Semua aktivitas <span>{{ activeFilter === 'all' ? total : '' }}</span></button>
          <button class="filter-pill" :class="{ active: activeFilter === 'RECEIPT' }" @click="setFilter('RECEIPT')">Penerimaan</button>
          <button class="filter-pill" :class="{ active: activeFilter === 'ISSUE' }" @click="setFilter('ISSUE')">Pengeluaran</button>
          <button class="filter-pill" :class="{ active: activeFilter === 'TRANSFER' }" @click="setFilter('TRANSFER')">Transfer</button>
        </div>
        <div style="margin-left: auto;">
          <label class="table-search"><Search :size="16" /><input v-model="search" @input="onSearch" placeholder="Cari pergerakan..." aria-label="Cari pergerakan" /></label>
        </div>
      </div>
      <div class="table-scroll">
        <table class="movement-table">
          <thead>
            <tr><th>ID Pergerakan</th><th>Tipe</th><th>Produk</th><th>Gudang / Rak</th><th>Kuantitas</th><th>Dilakukan oleh</th><th>Tanggal & waktu</th></tr>
          </thead>
          <tbody>
            <tr v-if="loading && !movements.length"><td colspan="7" style="text-align:center; padding: 20px;">Memuat data...</td></tr>
            <tr v-else-if="!movements.length"><td colspan="7" style="text-align:center; padding: 20px;">Tidak ada pergerakan stok.</td></tr>
            <tr v-else v-for="movement in movements" :key="movement.id">
              <td><strong class="movement-id">{{ movement.id.substring(0, 8).toUpperCase() }}</strong></td>
              <td>
                <span class="movement-type" :class="getDirection(movement.reference_type, movement.type)">
                  <ArrowDownToLine v-if="getDirection(movement.reference_type, movement.type) === 'in'" :size="13" />
                  <ArrowUpRight v-else-if="getDirection(movement.reference_type, movement.type) === 'out'" :size="13" />
                  <ArrowLeftRight v-else :size="13" />
                  {{ formatType(movement.reference_type, movement.type) }}
                </span>
              </td>
              <td><strong>{{ movement.product?.name }}</strong><small>{{ movement.product?.sku }}</small></td>
              <td>
                {{ movement.warehouse?.name || '-' }}
                <small v-if="movement.warehouse_location?.code">{{ movement.warehouse_location?.code }}</small>
              </td>
              <td>
                <strong :class="getDirection(movement.reference_type, movement.type) === 'in' ? 'quantity-in' : (getDirection(movement.reference_type, movement.type) === 'out' ? 'quantity-out' : '')">
                  {{ getQtyLabel(movement.quantity) }}
                </strong>
              </td>
              <td>{{ movement.user?.name || '-' }}</td>
              <td>{{ formatDate(movement.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="table-footer">
        <span>Menampilkan {{ fromItem }}–{{ toItem }} dari {{ total }} pergerakan</span>
        <div style="display: flex; gap: 4px;">
          <button :disabled="page <= 1" @click="goTo(page - 1)">Sebelumnya</button>
          <button v-for="n in pages" :key="n" :class="{ 'current-page': n === page }" @click="goTo(n)">{{ n }}</button>
          <button :disabled="page >= lastPage" @click="goTo(page + 1)">Berikutnya</button>
        </div>
      </div>
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
.movement-summary-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:18px; }
.movement-stat { display:flex; gap:14px; align-items:flex-start; padding:17px 18px; }
.stat-icon { display:grid; place-items:center; width:33px; height:33px; border-radius:8px; }.stat-icon svg { width:16px; }.stat-icon.green { color:#38a77a; background:#eaf8f1; }.stat-icon.red { color:#d97070; background:#fff0f0; }.stat-icon.blue { color:#4e8acc; background:#edf5fc; }
.movement-stat p { margin:0 0 12px; color:#858291; font-size:11px; }.movement-stat strong { display:block; font-size:24px; font-weight:720; letter-spacing:-.04em; color:#292541; }.movement-stat small { font-size:12px; font-weight:normal; margin-left:2px; color:#aaa7b2; }.movement-stat span:not(.stat-icon) { font-size:10px; margin-top:8px; display:block; }.positive,.quantity-in { color:#3ca579; font-weight:700; }.negative,.quantity-out { color:#dc706f; font-weight:700; }.neutral { color:#aaa7b2; }
.movement-panel { overflow:hidden; }.panel-toolbar { display:flex; justify-content:space-between; align-items:flex-start; padding:18px 19px 0; gap:15px; }.panel-toolbar h2 { margin:0 0 5px; font-size:13px; color:#292541; letter-spacing:-.01em; }.panel-toolbar p { margin:0; color:#aaa7b3; font-size:10px; }.toolbar-actions { display:flex; gap:9px; }.table-search { display:flex; align-items:center; gap:8px; border:1px solid #eeedf3; background:#fafafd; border-radius:8px; padding:0 9px; color:#aaa7b5; height:34px; }.table-search input { width:170px; border:0; outline:0; padding:0; background:transparent; color:#48445c; font:inherit; font-size:11px; }.filter-select { border:1px solid #eeedf3; background:#fff; color:#777486; border-radius:6px; padding:0 9px; font:inherit; font-size:10px; height:34px; outline:none; cursor:pointer; }.filter-button,.secondary-button { display:flex; align-items:center; gap:7px; border:1px solid #e9e7ef; background:#fff; color:#777486; border-radius:6px; padding:0 9px; font:inherit; font-size:10px; cursor:pointer; height:34px; transition:all 0.2s; }.filter-button:hover,.secondary-button:hover { background:#fafafd; }.filter-pills { display:flex; gap:8px; align-items:center; justify-content:space-between; padding:18px 19px 16px; border-bottom:1px solid #f0eff3; }.filter-pill { border:0; background:transparent; color:#aaa7b4; font:inherit; font-size:10px; padding:7px 10px; border-radius:6px; cursor:pointer; font-weight:500; }.filter-pill.active { color:#bd3c3c; background:#fff2f2; }.filter-pill span { margin-left:5px; color:inherit; opacity:0.7; }.table-scroll { overflow-x:auto; }.movement-table { width:100%; min-width:950px; border-collapse:collapse; font-size:10px; }.movement-table th { padding:10px 19px; text-align:left; color:#aaa7b4; font-size:9px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; background:#fafafd; border-top:1px solid #f0eff3; border-bottom:1px solid #f0eff3; }.movement-table td { padding:13px 19px; border-bottom:1px solid #f3f2f6; color:#555164; white-space:nowrap; }.movement-table td strong { display:block; color:#48445c; font-size:11px; margin-bottom:2px; }.movement-table td small { display:block; color:#aaa7b4; font-size:9px; }.movement-id { color:#292541 !important; }.movement-type { display:inline-flex; align-items:center; gap:5px; font-size:9px; padding:4px 7px; border-radius:4px; }.movement-type.in { color:#3b9d76; background:#eaf8f1; }.movement-type.out { color:#d97070; background:#fff0f0; }.movement-type.transfer { color:#4e8acc; background:#edf5fc; }.table-footer { display:flex; align-items:center; justify-content:space-between; padding:14px 19px; color:#aaa7b4; font-size:10px; border-top:1px solid #f0eff3; }.table-footer div { display:flex; gap:4px; }.table-footer button { border:1px solid #e9e7ef; background:#fff; color:#858291; border-radius:5px; padding:6px 9px; font:inherit; cursor:pointer; transition:all 0.2s; }.table-footer button:not(:disabled):hover { border-color:#bd3c3c; color:#bd3c3c; }.table-footer button.current-page { background:#bd3c3c; color:white; border-color:#bd3c3c; }.table-footer button.current-page:hover { color:white; }.table-footer button:disabled { opacity:.5; cursor:not-allowed; }
@media (max-width:800px) { .movement-summary-grid { grid-template-columns:1fr; }.panel-toolbar { align-items:flex-start; flex-direction:column; padding:14px; }.toolbar-actions { width:100%; }.table-search { flex:1; }.table-search input { width:100%; }.filter-pills { overflow-x:auto; padding:14px; }.table-footer { align-items:flex-start; flex-direction:column; gap:12px; } }

/* Toast */
.toast-notification { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 8px; font-size: 12px; font-weight: 500; z-index: 10001; box-shadow: 0 10px 30px rgba(0,0,0,0.1); color: #fff; max-width: 300px; display: flex; align-items: center; gap: 10px; }
.toast-notification.success { background: #292541; }
.toast-notification.error { background: #d47770; }
.toast-notification.warning { background: #c48735; }
</style>
