<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  Plus,
  ChevronDown,
  Package,
  Boxes,
  AlertTriangle,
  Clock,
  ArrowDownToLine,
  Truck,
  SlidersHorizontal,
  ArrowUpRight,
  Loader2,
  DollarSign,
  CheckCircle2,
  Warehouse as WarehouseIcon
} from 'lucide-vue-next'
import axios from 'axios'
import { useAuthStore } from '../../app/stores/auth'

const authStore = useAuthStore()
const router = useRouter()

interface WarehouseOption {
  id: string
  code: string
  name: string
}

interface MonthlyMovement {
  key: string
  label: string
  in: number
  out: number
}

interface LowStockItem {
  id: string
  sku: string
  name: string
  category_name: string
  unit_name: string
  quantity: number
  minimum_stock: number
  percentage: number
  is_low_stock: boolean
  status: string
}

interface RecentMovement {
  id: string
  type: string
  quantity: string
  balance_after: string
  remarks: string | null
  reference_type: string | null
  reference_id: string | null
  created_at: string
  product?: { id: string; sku: string; name: string }
  warehouse?: { id: string; name: string; code: string }
  warehouse_location?: { id: string; code: string; name: string }
  user?: { id: string; name: string }
}

interface DashboardKPI {
  total_stock_value: number
  total_physical_stock: number
  total_products_in_stock: number
  total_catalog_products: number
  pending_transfers: number
  pending_reservations: number
  low_stock_count: number
}

const loading = ref(true)
const selectedWarehouse = ref<string>('')
const showWarehouseDropdown = ref(false)
const showQuickActions = ref(false)

const kpi = ref<DashboardKPI>({
  total_stock_value: 0,
  total_physical_stock: 0,
  total_products_in_stock: 0,
  total_catalog_products: 0,
  pending_transfers: 0,
  pending_reservations: 0,
  low_stock_count: 0
})

const monthlyMovements = ref<MonthlyMovement[]>([])
const lowStockItems = ref<LowStockItem[]>([])
const recentMovements = ref<RecentMovement[]>([])
const warehouses = ref<WarehouseOption[]>([])

const currentDateFormatted = computed(() => {
  return new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric'
  }).format(new Date()).toUpperCase()
})

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 11) return 'Selamat pagi'
  if (hour < 15) return 'Selamat siang'
  if (hour < 18) return 'Selamat sore'
  return 'Selamat malam'
})

const selectedWarehouseName = computed(() => {
  if (!selectedWarehouse.value) return 'Semua gudang'
  const wh = warehouses.value.find(w => w.id === selectedWarehouse.value)
  return wh ? wh.name : 'Semua gudang'
})

const fetchDashboardData = async () => {
  loading.value = true
  try {
    const params = selectedWarehouse.value ? { warehouse_id: selectedWarehouse.value } : {}
    const res = await axios.get('/dashboard', { params })
    const data = res.data.data
    kpi.value = data.kpi || kpi.value
    monthlyMovements.value = data.monthly_movements || []
    lowStockItems.value = data.low_stock_items || []
    recentMovements.value = data.recent_movements || []
    warehouses.value = data.warehouses || []
  } catch (err) {
    console.error('Failed to load dashboard data:', err)
  } finally {
    loading.value = false
  }
}

const selectWarehouse = (whId: string) => {
  selectedWarehouse.value = whId
  showWarehouseDropdown.value = false
  fetchDashboardData()
}

// Chart calculations
const chartMax = computed(() => {
  if (!monthlyMovements.value.length) return 100
  const maxVal = Math.max(
    ...monthlyMovements.value.map(m => Math.max(m.in, m.out)),
    10
  )
  // Round up to nice number
  if (maxVal <= 50) return 50
  if (maxVal <= 100) return 100
  if (maxVal <= 200) return 200
  return Math.ceil(maxVal / 100) * 100
})

const formatNumber = (num: number) => {
  return new Intl.NumberFormat('id-ID').format(num)
}

const formatCurrency = (num: number) => {
  if (!num || num === 0) return 'Rp 0'
  if (num >= 1_000_000_000) {
    return `Rp ${(num / 1_000_000_000).toFixed(2)}M`
  }
  if (num >= 1_000_000) {
    return `Rp ${(num / 1_000_000).toFixed(1)}Jt`
  }
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(num)
}

const formatDateTime = (dateStr: string) => {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  return new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(d)
}

const handleClickOutside = (e: MouseEvent) => {
  const target = e.target as HTMLElement
  if (!target.closest('.warehouse-selector-wrap')) {
    showWarehouseDropdown.value = false
  }
  if (!target.closest('.quick-actions-wrap')) {
    showQuickActions.value = false
  }
}

onMounted(() => {
  fetchDashboardData()
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <section class="dashboard-page">
    <!-- Header -->
    <div class="page-heading">
      <div class="heading-text">
        <p class="eyebrow">{{ currentDateFormatted }}</p>
        <h1>{{ greeting }}, <span>{{ authStore.user?.name || 'Administrator' }}</span></h1>
        <p class="muted">Berikut ringkasan operasional dan status inventaris Anda hari ini.</p>
      </div>
      
      <!-- Quick Actions Button & Dropdown -->
      <div class="quick-actions-wrap">
        <button class="primary-button quick-action-btn" @click.stop="showQuickActions = !showQuickActions">
          <Plus :size="16" />
          <span>Operasi baru</span>
          <ChevronDown :size="14" class="chevron-icon" :class="{ 'rotate': showQuickActions }" />
        </button>

        <Transition name="fade">
          <div v-if="showQuickActions" class="quick-actions-menu">
            <div class="quick-menu-header">Pilih Operasi</div>
            <button class="quick-menu-item" @click="router.push('/receiving'); showQuickActions = false">
              <div class="item-icon green"><ArrowDownToLine :size="15" /></div>
              <div>
                <strong>Penerimaan Barang</strong>
                <span>Barang masuk dari supplier</span>
              </div>
            </button>
            <button class="quick-menu-item" @click="router.push('/transfers'); showQuickActions = false">
              <div class="item-icon blue"><Truck :size="15" /></div>
              <div>
                <strong>Transfer Gudang</strong>
                <span>Pindahkan stok antar fasilitas</span>
              </div>
            </button>
            <button class="quick-menu-item" @click="router.push('/adjustments'); showQuickActions = false">
              <div class="item-icon purple"><SlidersHorizontal :size="15" /></div>
              <div>
                <strong>Penyesuaian Stok</strong>
                <span>Stock opname & koreksi selisih</span>
              </div>
            </button>
            <button class="quick-menu-item" @click="router.push('/products'); showQuickActions = false">
              <div class="item-icon red"><Package :size="15" /></div>
              <div>
                <strong>Tambah Produk</strong>
                <span>Daftarkan katalog barang baru</span>
              </div>
            </button>
          </div>
        </Transition>
      </div>
    </div>

    <!-- 4 KPI Cards -->
    <section class="metric-grid">
      <!-- Card 1: Total Nilai Stok -->
      <div class="metric-card">
        <div class="metric-card-top">
          <div class="metric-label">Total nilai stok (HPP)</div>
          <div class="metric-icon blue">
            <DollarSign :size="16" />
          </div>
        </div>
        <div class="metric-value">
          <span v-if="loading"><Loader2 class="spin" :size="20" /></span>
          <span v-else>{{ formatCurrency(kpi.total_stock_value) }}</span>
        </div>
        <div class="metric-detail">
          <span>{{ kpi.total_products_in_stock }}</span> produk fisik aktif
        </div>
      </div>

      <!-- Card 2: Barang Tersedia -->
      <div class="metric-card">
        <div class="metric-card-top">
          <div class="metric-label">Barang tersedia (Fisik)</div>
          <div class="metric-icon green">
            <Package :size="16" />
          </div>
        </div>
        <div class="metric-value">
          <span v-if="loading"><Loader2 class="spin" :size="20" /></span>
          <span v-else>{{ formatNumber(kpi.total_physical_stock) }} <small class="unit-text">unit</small></span>
        </div>
        <div class="metric-detail">
          <span>{{ kpi.total_catalog_products }}</span> total katalog terdaftar
        </div>
      </div>

      <!-- Card 3: Operasi Berjalan -->
      <div class="metric-card">
        <div class="metric-card-top">
          <div class="metric-label">Operasi menunggu</div>
          <div class="metric-icon orange">
            <Clock :size="16" />
          </div>
        </div>
        <div class="metric-value">
          <span v-if="loading"><Loader2 class="spin" :size="20" /></span>
          <span v-else>{{ kpi.pending_transfers + kpi.pending_reservations }} <small class="unit-text">proses</small></span>
        </div>
        <div class="metric-detail">
          <span>{{ kpi.pending_transfers }}</span> transfer, <span>{{ kpi.pending_reservations }}</span> reservasi
        </div>
      </div>

      <!-- Card 4: Peringatan Stok Menipis -->
      <div class="metric-card">
        <div class="metric-card-top">
          <div class="metric-label">Peringatan stok menipis</div>
          <div class="metric-icon" :class="kpi.low_stock_count > 0 ? 'red' : 'green'">
            <AlertTriangle v-if="kpi.low_stock_count > 0" :size="16" />
            <CheckCircle2 v-else :size="16" />
          </div>
        </div>
        <div class="metric-value">
          <span v-if="loading"><Loader2 class="spin" :size="20" /></span>
          <span v-else :style="{ color: kpi.low_stock_count > 0 ? '#bd3c3c' : 'inherit' }">
            {{ kpi.low_stock_count }} <small class="unit-text">produk</small>
          </span>
        </div>
        <div class="metric-detail" :class="{ 'alert-detail': kpi.low_stock_count > 0 }">
          <span v-if="kpi.low_stock_count > 0">{{ kpi.low_stock_count }} item</span>
          <span v-else style="color: #39a478;">Kondisi aman</span>
          {{ kpi.low_stock_count > 0 ? 'di bawah batas minimum' : 'stok mencukupi' }}
        </div>
      </div>
    </section>

    <!-- Main Content Grid -->
    <section class="content-grid">
      <!-- Left Panel: Monthly Movements Chart -->
      <div class="panel stock-panel">
        <div class="panel-header">
          <div class="panel-title-wrap">
            <h2>Pergerakan stok bulanan</h2>
            <p>Stok masuk (Inbound) vs Stok keluar (Outbound)</p>
          </div>

          <!-- Warehouse Dropdown Filter -->
          <div class="warehouse-selector-wrap">
            <button class="select-button" @click.stop="showWarehouseDropdown = !showWarehouseDropdown">
              <WarehouseIcon :size="13" style="color: #bd3c3c;" />
              <span class="wh-btn-label">{{ selectedWarehouseName }}</span>
              <ChevronDown :size="14" class="chevron-icon" :class="{ 'rotate': showWarehouseDropdown }" />
            </button>

            <Transition name="fade">
              <div v-if="showWarehouseDropdown" class="warehouse-dropdown">
                <button
                  class="warehouse-option"
                  :class="{ active: selectedWarehouse === '' }"
                  @click="selectWarehouse('')"
                >
                  Semua gudang (Konsolidasi)
                </button>
                <button
                  v-for="wh in warehouses"
                  :key="wh.id"
                  class="warehouse-option"
                  :class="{ active: selectedWarehouse === wh.id }"
                  @click="selectWarehouse(wh.id)"
                >
                  {{ wh.name }} <small>({{ wh.code }})</small>
                </button>
              </div>
            </Transition>
          </div>
        </div>

        <!-- Chart Graphic -->
        <div class="chart-wrap">
          <div class="chart-canvas">
            <div class="chart-labels">
              <span>{{ chartMax }}</span>
              <span>{{ Math.round(chartMax * 0.75) }}</span>
              <span>{{ Math.round(chartMax * 0.5) }}</span>
              <span>{{ Math.round(chartMax * 0.25) }}</span>
              <span>0</span>
            </div>
            
            <div class="chart">
              <div class="grid-lines">
                <div class="grid-line" />
                <div class="grid-line" />
                <div class="grid-line" />
                <div class="grid-line" />
                <div class="grid-line baseline" />
              </div>

              <div class="bars">
                <div
                  v-for="col in monthlyMovements"
                  :key="col.key"
                  class="bar-column"
                >
                  <!-- Tooltip -->
                  <div class="bar-tooltip">
                    <strong>{{ col.label }}</strong>
                    <span>Masuk: {{ col.in }} unit</span>
                    <span>Keluar: {{ col.out }} unit</span>
                  </div>

                  <!-- Outbound Bar (Secondary Pink/Lilac) -->
                  <div
                    class="bar reserved"
                    :style="{ height: `${Math.min(100, (col.out / chartMax) * 100)}%` }"
                  />
                  <!-- Inbound Bar (Primary Crimson/Purple) -->
                  <div
                    class="bar onhand"
                    :style="{ height: `${Math.min(100, (col.in / chartMax) * 100)}%` }"
                  />
                  <span>{{ col.label }}</span>
                </div>
              </div>
            </div>
          </div>

          <div class="chart-legend">
            <span><i class="legend-dot purple" />Stok Masuk (Inbound)</span>
            <span><i class="legend-dot lilac" />Stok Keluar (Outbound)</span>
            <strong class="chart-footnote">Data aktual sistem</strong>
          </div>
        </div>
      </div>

      <!-- Right Panel: Low Stock Alerts -->
      <div class="panel alerts-panel">
        <div class="panel-header">
          <div>
            <h2>Peringatan stok menipis</h2>
            <p>Barang di bawah batas minimum</p>
          </div>
          <span class="status-pill" :class="kpi.low_stock_count > 0 ? 'red-pill' : 'neutral'">
            {{ kpi.low_stock_count }} item
          </span>
        </div>

        <div class="alert-list">
          <div v-if="loading" style="padding: 24px; text-align: center; color: #aaa7b4;">
            <Loader2 class="spin" :size="20" style="margin: 0 auto 8px;" />
            <span style="font-size: 11px;">Memuat status stok...</span>
          </div>

          <div v-else-if="lowStockItems.length === 0" style="padding: 30px 14px; text-align: center; color: #858291;">
            <CheckCircle2 :size="28" style="color: #38a77a; margin: 0 auto 8px;" />
            <strong style="display: block; font-size: 12px; color: #292541;">Semua Stok Aman</strong>
            <span style="font-size: 10px; color: #aaa7b4;">Tidak ada produk yang berada di bawah ambang batas minimum saat ini.</span>
          </div>

          <div
            v-for="item in lowStockItems"
            :key="item.id"
            class="alert-item"
          >
            <div class="product-icon">
              <Package :size="16" />
            </div>
            <div class="alert-copy">
              <strong>{{ item.name }}</strong>
              <span>SKU: {{ item.sku }} • Min: {{ item.minimum_stock }} {{ item.unit_name }}</span>
              <div class="progress">
                <i :style="{ width: `${item.percentage}%`, background: item.quantity <= 0 ? '#bd3c3c' : '#dc9b45' }" />
              </div>
            </div>
            <div class="stock-count">
              <strong>{{ item.quantity }} {{ item.unit_name }}</strong>
              <span :style="{ color: item.quantity <= 0 ? '#bd3c3c' : '#dc9b45' }">{{ item.status }}</span>
            </div>
          </div>
        </div>

        <button class="outline-button alert-action-btn" @click="router.push('/products')">
          Kelola katalog & batas minimum
        </button>
      </div>
    </section>

    <!-- Bottom Panel: Recent Movements Activity Table & Mobile Cards -->
    <section class="panel activity-panel">
      <div class="panel-header activity-panel-header">
        <div>
          <h2>Aktivitas Pergerakan Terbaru</h2>
          <p>Daftar riwayat mutasi masuk, keluar, dan penyesuaian stok terkini</p>
        </div>
        <button class="text-button" @click="router.push('/stock-movements')">
          Lihat semua mutasi <ArrowUpRight :size="14" />
        </button>
      </div>

      <!-- Desktop Table View -->
      <div class="table-wrap desktop-table-wrap">
        <table>
          <thead>
            <tr>
              <th>Tipe</th>
              <th>Referensi / Catatan</th>
              <th>Produk & SKU</th>
              <th>Gudang & Lokasi</th>
              <th>Perubahan</th>
              <th>Sisa Stok</th>
              <th>Waktu</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="7" style="text-align: center; padding: 24px; color: #aaa7b4;">
                <Loader2 class="spin" :size="18" style="display: inline-block; vertical-align: middle; margin-right: 6px;" />
                Memuat riwayat pergerakan...
              </td>
            </tr>
            <tr v-else-if="recentMovements.length === 0">
              <td colspan="7" style="text-align: center; padding: 24px; color: #aaa7b4;">
                Belum ada aktivitas mutasi stok yang tercatat.
              </td>
            </tr>
            <tr v-for="mov in recentMovements" :key="mov.id">
              <td>
                <div class="activity-type">
                  <i
                    class="activity-dot"
                    :class="{
                      green: mov.type === 'IN',
                      red: mov.type === 'OUT',
                      blue: mov.type === 'TRANSFER',
                      purple: mov.type === 'ADJUSTMENT'
                    }"
                  />
                  <strong>{{ mov.type === 'IN' ? 'Masuk' : mov.type === 'OUT' ? 'Keluar' : mov.type === 'TRANSFER' ? 'Transfer' : 'Penyesuaian' }}</strong>
                </div>
              </td>
              <td>
                <span class="reference">{{ mov.remarks || mov.reference_id || '-' }}</span>
              </td>
              <td>
                <div style="line-height: 1.3;">
                  <strong style="color: #292541;">{{ mov.product?.name || '-' }}</strong>
                  <div class="muted-cell" style="font-size: 9px;">SKU: {{ mov.product?.sku || '-' }}</div>
                </div>
              </td>
              <td>
                <div style="line-height: 1.3;">
                  <span>{{ mov.warehouse?.name || '-' }}</span>
                  <div class="muted-cell" style="font-size: 9px;">Lokasi: {{ mov.warehouse_location?.code || mov.warehouse_location?.name || '-' }}</div>
                </div>
              </td>
              <td>
                <span :class="['quantity', mov.type === 'OUT' ? 'negative' : '']">
                  {{ mov.type === 'OUT' ? '-' : '+' }}{{ mov.quantity }}
                </span>
              </td>
              <td>
                <span style="font-weight: 600; color: #49455a;">{{ mov.balance_after }}</span>
              </td>
              <td class="muted-cell">
                {{ formatDateTime(mov.created_at) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile Activity Cards View -->
      <div class="mobile-activity-list">
        <div v-if="loading" class="mobile-state">
          <Loader2 class="spin" :size="20" style="margin: 0 auto 8px;" />
          <span>Memuat riwayat pergerakan...</span>
        </div>
        <div v-else-if="recentMovements.length === 0" class="mobile-state">
          <span>Belum ada aktivitas mutasi stok yang tercatat.</span>
        </div>
        <div
          v-for="mov in recentMovements"
          :key="mov.id"
          class="mobile-movement-card"
        >
          <div class="mm-top">
            <div class="activity-type">
              <i
                class="activity-dot"
                :class="{
                  green: mov.type === 'IN',
                  red: mov.type === 'OUT',
                  blue: mov.type === 'TRANSFER',
                  purple: mov.type === 'ADJUSTMENT'
                }"
              />
              <strong>{{ mov.type === 'IN' ? 'Masuk' : mov.type === 'OUT' ? 'Keluar' : mov.type === 'TRANSFER' ? 'Transfer' : 'Penyesuaian' }}</strong>
            </div>
            <span class="mm-time">{{ formatDateTime(mov.created_at) }}</span>
          </div>

          <div class="mm-body">
            <div class="mm-info">
              <strong class="mm-product-name">{{ mov.product?.name || '-' }}</strong>
              <span class="mm-sku">SKU: {{ mov.product?.sku || '-' }}</span>
            </div>
            <div class="mm-qty" :class="{ negative: mov.type === 'OUT' }">
              {{ mov.type === 'OUT' ? '-' : '+' }}{{ mov.quantity }}
            </div>
          </div>

          <div class="mm-meta">
            <div class="mm-location">
              <span>{{ mov.warehouse?.name || '-' }}</span>
              <span v-if="mov.warehouse_location?.code || mov.warehouse_location?.name" class="mm-subloc">
                ({{ mov.warehouse_location?.code || mov.warehouse_location?.name }})
              </span>
            </div>
            <div class="mm-balance">
              Sisa: <strong>{{ mov.balance_after }}</strong>
            </div>
          </div>

          <div v-if="mov.remarks || mov.reference_id" class="mm-ref">
            <span>Ref: {{ mov.remarks || mov.reference_id }}</span>
          </div>
        </div>
      </div>
    </section>
  </section>
</template>

<style scoped>
.dashboard-page {
  animation: fadeIn 0.15s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.red-pill {
  color: #bd3c3c;
  background: #fff0f0;
  border: 1px solid #fedcdc;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 10px;
  font-weight: 600;
}

/* Quick Actions Dropdown */
.quick-actions-wrap {
  position: relative;
}

.quick-action-btn {
  white-space: nowrap;
}

.chevron-icon {
  transition: transform 0.2s ease;
}

.chevron-icon.rotate {
  transform: rotate(180deg);
}

.quick-actions-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 6px);
  width: 250px;
  background: white;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  box-shadow: 0 10px 25px rgba(41, 37, 65, 0.1);
  z-index: 50;
  overflow: hidden;
}

.quick-menu-header {
  padding: 10px 14px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #aaa7b4;
  background: #fafafd;
  border-bottom: 1px solid #f0eff3;
}

.quick-menu-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: transparent;
  border: 0;
  border-bottom: 1px solid #f5f4f8;
  text-align: left;
  cursor: pointer;
  transition: background 0.15s;
}

.quick-menu-item:last-child {
  border-bottom: 0;
}

.quick-menu-item:hover {
  background: #fbfbfe;
}

.item-icon {
  width: 28px;
  height: 28px;
  border-radius: 7px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.item-icon.green { background: #eaf8f1; color: #38a77a; }
.item-icon.blue { background: #edf5fc; color: #4e8acc; }
.item-icon.purple { background: #f3effe; color: #7f60dc; }
.item-icon.red { background: #fff0f0; color: #bd3c3c; }

.quick-menu-item strong {
  display: block;
  font-size: 11px;
  color: #292541;
}

.quick-menu-item span {
  display: block;
  font-size: 9px;
  color: #aaa7b4;
  margin-top: 1px;
}

/* Warehouse Dropdown */
.warehouse-selector-wrap {
  position: relative;
}

.select-button {
  display: flex;
  align-items: center;
  gap: 8px;
  background: white;
  border: 1px solid #e9e7ef;
  border-radius: 6px;
  padding: 7px 11px;
  color: #777486;
  font-size: 11px;
  cursor: pointer;
  transition: border-color 0.15s ease;
}

.select-button:hover {
  border-color: #d8d6e2;
}

.wh-btn-label {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 180px;
}

.warehouse-dropdown {
  position: absolute;
  right: 0;
  top: calc(100% + 6px);
  min-width: 210px;
  background: white;
  border: 1px solid #eeedf3;
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(41, 37, 65, 0.1);
  z-index: 50;
  overflow: hidden;
}

.warehouse-option {
  width: 100%;
  padding: 9px 13px;
  border: 0;
  background: transparent;
  text-align: left;
  font-size: 11px;
  color: #555164;
  border-bottom: 1px solid #f5f4f8;
  cursor: pointer;
  transition: all 0.15s;
}

.warehouse-option:last-child {
  border-bottom: 0;
}

.warehouse-option:hover {
  background: #f9f8fc;
  color: #bd3c3c;
}

.warehouse-option.active {
  background: #fff4f4;
  color: #bd3c3c;
  font-weight: 650;
}

.warehouse-option small {
  color: #aaa7b4;
  font-size: 9px;
}

.fade-enter-active, .fade-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.fade-enter-from, .fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

/* Metric Cards - Modern Flex Layout */
.metric-card {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 130px;
  padding: 16px 18px;
  background: white;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  box-sizing: border-box;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.metric-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 14px rgba(41, 37, 65, 0.05);
}

.metric-card-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
}

.metric-card-top .metric-label {
  flex: 1;
  font-size: 11px;
  line-height: 1.35;
  color: #858291;
  margin: 0;
  word-break: break-word;
}

.metric-card-top .metric-icon {
  position: static;
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  margin-top: -2px;
}

.metric-card-top .metric-icon.blue { color: #4e8acc; background: #edf5fc; }
.metric-card-top .metric-icon.green { color: #38a77a; background: #eaf8f1; }
.metric-card-top .metric-icon.orange { color: #dc9b45; background: #fff6e8; }
.metric-card-top .metric-icon.red { color: #d97070; background: #fff0f0; }

.metric-value {
  font-size: 22px;
  font-weight: 720;
  letter-spacing: -0.03em;
  margin-top: 10px;
  color: #292541;
  line-height: 1.2;
  word-break: break-word;
}

.unit-text {
  font-size: 12px;
  font-weight: normal;
  color: #858291;
}

.metric-detail {
  font-size: 10px;
  color: #aaa7b2;
  margin-top: 8px;
  line-height: 1.35;
}

.metric-detail span {
  color: #39a478;
  font-weight: 700;
  margin-right: 4px;
}

.metric-detail.alert-detail span {
  color: #dc706f;
}

/* Full-height responsive chart styles */
.content-grid {
  align-items: stretch;
}

.stock-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.alerts-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.alerts-panel .alert-list {
  flex: 1;
}

.alerts-panel .alert-action-btn {
  margin: 10px 16px 14px;
  width: calc(100% - 32px);
  height: 33px;
  font-size: 10.5px;
  font-weight: 600;
  cursor: pointer;
  border-radius: 6px;
}

.chart-wrap {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 22px 20px 16px;
  min-height: 290px;
}

.chart-canvas {
  flex: 1;
  display: flex;
  position: relative;
  min-height: 210px;
}

.chart-labels {
  height: calc(100% - 24px);
  width: 34px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  color: #b2afbc;
  font-size: 9px;
  user-select: none;
  padding: 0;
  margin: 0;
}

.chart {
  flex: 1;
  height: 100%;
  margin-left: 14px;
  position: relative;
}

.grid-lines {
  position: absolute;
  inset: 5px 0 24px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  pointer-events: none;
  background: none;
}

.grid-line {
  width: 100%;
  border-top: 1px dashed #eeedf3;
}

.grid-line.baseline {
  border-top: 1px solid #e2e1ea;
}

.bars {
  position: absolute;
  inset: 5px 0 0;
  height: auto;
  display: flex;
  align-items: flex-end;
  justify-content: space-around;
  gap: 8px;
  z-index: 1;
}

.bar-column {
  height: 100%;
  width: 36px;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  position: relative;
  cursor: pointer;
  transition: transform 0.15s ease;
}

.bar-column:hover {
  transform: translateY(-2px);
}

.bar-column span {
  position: absolute;
  bottom: 0;
  height: 20px;
  line-height: 20px;
  color: #aaa7b5;
  font-size: 10px;
  text-align: center;
  font-weight: 500;
  width: 100%;
}

.bar {
  min-height: 4px;
  border-radius: 4px 4px 0 0;
  position: absolute;
  bottom: 24px;
  transition: height 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.bar.onhand {
  width: 18px;
  background: #bd3c3c;
  box-shadow: 0 -2px 8px rgba(189, 60, 60, 0.25);
}

.bar.reserved {
  width: 11px;
  background: #f4c4c4;
  transform: translateX(8px);
  z-index: 2;
}

.bar-tooltip {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%) translateY(4px);
  background: #292541;
  color: white;
  padding: 5px 9px;
  border-radius: 6px;
  font-size: 9px;
  white-space: nowrap;
  pointer-events: none;
  opacity: 0;
  transition: all 0.15s ease;
  z-index: 20;
  box-shadow: 0 4px 12px rgba(41, 37, 65, 0.2);
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.bar-column:hover .bar-tooltip {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}

.chart-legend {
  border-top: 1px solid #f0eff3;
  padding-top: 14px;
  display: flex;
  gap: 18px;
  align-items: center;
  color: #85818e;
  font-size: 10px;
  margin-top: 14px;
}

.chart-footnote {
  color: #aaa7b4;
  font-weight: 400;
  margin-left: auto;
}

/* Activity Panel & Movement Cards */
.activity-panel-header {
  border-bottom: 1px solid #f0eff3;
  padding-bottom: 14px;
}

.desktop-table-wrap {
  display: block;
  overflow-x: auto;
}

.mobile-activity-list {
  display: none;
  padding: 12px 14px 14px;
  flex-direction: column;
  gap: 10px;
}

.mobile-movement-card {
  background: #fafafd;
  border: 1px solid #eeedf3;
  border-radius: 9px;
  padding: 12px 13px;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.mobile-movement-card:hover {
  background: #f6f5fc;
  border-color: #e2e0ed;
}

.mm-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 7px;
  border-bottom: 1px solid #f0eff4;
  margin-bottom: 8px;
}

.mm-time {
  font-size: 9.5px;
  color: #aaa7b2;
}

.mm-body {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.mm-info {
  flex: 1;
  min-width: 0;
}

.mm-product-name {
  display: block;
  font-size: 12px;
  font-weight: 650;
  color: #292541;
  line-height: 1.3;
}

.mm-sku {
  display: block;
  font-size: 9.5px;
  color: #8c889a;
  margin-top: 2px;
}

.mm-qty {
  font-size: 13px;
  font-weight: 700;
  color: #3ca579;
  white-space: nowrap;
  flex-shrink: 0;
}

.mm-qty.negative {
  color: #d96f6b;
}

.mm-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 8px;
  font-size: 10px;
  color: #777486;
}

.mm-location {
  display: flex;
  align-items: center;
  gap: 4px;
  color: #555164;
  font-size: 10px;
}

.mm-subloc {
  color: #8c889a;
  font-size: 9.5px;
}

.mm-balance {
  font-size: 10px;
  color: #777486;
}

.mm-balance strong {
  color: #292541;
  font-weight: 650;
}

.mm-ref {
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px dashed #eeedf3;
  font-size: 9.5px;
  color: #8c889a;
}

.mobile-state {
  text-align: center;
  padding: 24px 14px;
  color: #aaa7b4;
  font-size: 11px;
}

/* RESPONSIVE MEDIA QUERIES */
@media (max-width: 768px) {
  .page-heading {
    flex-direction: column;
    align-items: stretch;
    gap: 14px;
    margin-bottom: 18px;
  }

  .heading-text h1 {
    font-size: 20px;
    line-height: 1.25;
  }

  .heading-text h1 span {
    font-size: 18px;
  }

  .eyebrow {
    font-size: 9px;
    letter-spacing: 0.1em;
    margin-bottom: 5px;
  }

  .muted {
    font-size: 11px;
    margin-top: 5px;
  }

  .quick-actions-wrap {
    width: 100%;
  }

  .quick-action-btn {
    width: 100%;
    height: 42px;
    justify-content: center;
    font-size: 12.5px;
    border-radius: 8px;
  }

  .quick-actions-menu {
    width: 100%;
    left: 0;
    right: 0;
    top: calc(100% + 6px);
    box-shadow: 0 12px 30px rgba(41, 37, 65, 0.16);
    border-radius: 10px;
    z-index: 60;
  }

  .metric-grid {
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 14px;
  }

  .metric-card {
    min-height: 114px;
    padding: 13px 13px 12px;
    border-radius: 9px;
  }

  .metric-card-top .metric-label {
    font-size: 10.5px;
    line-height: 1.3;
  }

  .metric-card-top .metric-icon {
    width: 28px;
    height: 28px;
    border-radius: 7px;
  }

  .metric-card-top .metric-icon svg {
    width: 14px;
    height: 14px;
  }

  .metric-value {
    font-size: 17.5px;
    margin-top: 6px;
    letter-spacing: -0.02em;
  }

  .unit-text {
    font-size: 11px;
  }

  .metric-detail {
    font-size: 9.5px;
    margin-top: 6px;
    line-height: 1.3;
  }

  .content-grid {
    grid-template-columns: 1fr;
    gap: 14px;
    margin-bottom: 14px;
  }

  .panel-header {
    flex-wrap: wrap;
    gap: 10px;
    padding: 14px 14px 0;
  }

  .panel-title-wrap {
    flex: 1;
    min-width: 160px;
  }

  .warehouse-selector-wrap {
    width: 100%;
  }

  .select-button {
    width: 100%;
    justify-content: space-between;
    height: 36px;
    padding: 0 12px;
    font-size: 11px;
  }

  .wh-btn-label {
    max-width: none;
    flex: 1;
    text-align: left;
  }

  .warehouse-dropdown {
    width: 100%;
    left: 0;
    right: 0;
    top: calc(100% + 4px);
  }

  .chart-wrap {
    padding: 14px 12px 12px;
    min-height: 240px;
  }

  .chart-canvas {
    min-height: 175px;
  }

  .chart-labels {
    width: 28px;
    font-size: 8.5px;
  }

  .chart {
    margin-left: 10px;
  }

  .bars {
    gap: 4px;
  }

  .bar-column {
    min-width: 0;
    flex: 1;
    max-width: 44px;
  }

  .bar-column span {
    font-size: 9px;
  }

  .bar.onhand {
    width: 13px;
    border-radius: 3px 3px 0 0;
  }

  .bar.reserved {
    width: 8px;
    transform: translateX(6px);
    border-radius: 3px 3px 0 0;
  }

  .chart-legend {
    flex-wrap: wrap;
    gap: 8px 12px;
    padding-top: 10px;
    margin-top: 10px;
    font-size: 9.5px;
  }

  .chart-footnote {
    width: 100%;
    margin-left: 0;
    font-size: 9px;
    margin-top: 2px;
  }

  .alerts-panel .alert-list {
    padding: 10px 14px 4px;
  }

  .alerts-panel .alert-action-btn {
    margin: 8px 14px 14px;
    width: calc(100% - 28px);
    height: 36px;
    font-size: 11px;
  }

  .desktop-table-wrap {
    display: none;
  }

  .mobile-activity-list {
    display: flex;
  }

  .activity-panel-header {
    padding: 14px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
  }
}

@media (max-width: 350px) {
  .metric-grid {
    grid-template-columns: 1fr;
  }

  .metric-card {
    min-height: 96px;
  }
}
</style>
