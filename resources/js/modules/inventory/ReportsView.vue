<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import {
  BarChart3,
  ChevronDown,
  Download,
  FileText,
  PieChart,
  TrendingUp,
  Loader2,
  CheckCircle2,
  X,
  FileSpreadsheet
} from 'lucide-vue-next'
import axios from 'axios'

defineProps<{ title?: string }>()

interface ReportItem {
  id: string
  name: string
  description: string
  updated: string
  type: string
  typeKey: string
  iconColor: string
}

const reports: ReportItem[] = [
  {
    id: 'rep-val',
    name: 'Valuasi Inventaris',
    description: 'Nilai total stok (HPP & Jual) berdasarkan produk, SKU, dan kategori',
    updated: 'Diperbarui otomatis',
    type: 'Keuangan',
    typeKey: 'valuation',
    iconColor: '#4e8acc'
  },
  {
    id: 'rep-mov',
    name: 'Ringkasan Mutasi Stok',
    description: 'Seluruh riwayat barang masuk (IN), keluar (OUT), dan penyesuaian',
    updated: 'Terkini dari database',
    type: 'Operasional',
    typeKey: 'movements',
    iconColor: '#38a77a'
  },
  {
    id: 'rep-low',
    name: 'Peringatan Stok Menipis',
    description: 'Daftar produk di bawah batas minimum yang membutuhkan restock',
    updated: 'Diperbarui otomatis',
    type: 'Inventaris',
    typeKey: 'low_stock',
    iconColor: '#dc9b45'
  },
  {
    id: 'rep-wh',
    name: 'Saldo & Utilisasi Gudang',
    description: 'Sebaran kuantitas fisik stok per gudang dan per rak lokasi',
    updated: 'Terkini dari database',
    type: 'Kapasitas',
    typeKey: 'stock_balance',
    iconColor: '#bd3c3c'
  },
]

const isLoading = ref(true)
const isLoadingValuation = ref(true)
const stats = ref({
  generated: 128,
  inventoryValue: 0,
  movementVolume: 0,
  accuracy: 0,
  coveragePercent: 0,
  productsWithCost: 0,
  productsWithoutCost: 0,
})

// Chart state
const selectedPeriod = ref('8w')
const showPeriodDropdown = ref(false)
const chartLoading = ref(false)
const chartData = ref<{
  period: string
  max_volume: number
  series: Array<{ label: string; in: number; out: number; volume: number }>
}>({
  period: '8w',
  max_volume: 100,
  series: []
})

// Export state
const showExportModal = ref(false)
const exportingType = ref<string | null>(null)
const exportSuccessMessage = ref<string | null>(null)

const periodLabels: Record<string, string> = {
  '8w': '8 Minggu terakhir',
  '7d': '7 Hari terakhir',
  '6m': '6 Bulan terakhir',
}

const currentPeriodLabel = computed(() => {
  return periodLabels[selectedPeriod.value] || '8 Minggu terakhir'
})

const fetchStats = async () => {
  try {
    const [movRes, valRes] = await Promise.all([
      axios.get('/reports/movements/stats'),
      axios.get('/reports/inventory-valuation'),
    ])

    const { total_in, total_out } = movRes.data.data
    stats.value.movementVolume = (total_in || 0) + (total_out || 0)

    const val = valRes.data.data
    stats.value.inventoryValue = val.total_cost_value || 0
    stats.value.accuracy = val.coverage_percent || 0
    stats.value.coveragePercent = val.coverage_percent || 0
    stats.value.productsWithCost = val.products_with_cost || 0
    stats.value.productsWithoutCost = val.products_without_cost || 0
  } catch (error) {
    console.error('Failed to fetch report stats:', error)
  } finally {
    isLoading.value = false
    isLoadingValuation.value = false
  }
}

const fetchChart = async (period: string) => {
  chartLoading.value = true
  try {
    const res = await axios.get('/reports/chart', { params: { period } })
    chartData.value = res.data.data
  } catch (error) {
    console.error('Failed to fetch report chart:', error)
  } finally {
    chartLoading.value = false
  }
}

const changePeriod = (period: string) => {
  selectedPeriod.value = period
  showPeriodDropdown.value = false
  fetchChart(period)
}

const downloadReport = async (typeKey: string, customName?: string) => {
  exportingType.value = typeKey
  exportSuccessMessage.value = null
  try {
    const response = await axios.get('/reports/export', {
      params: { type: typeKey },
      responseType: 'blob',
    })

    const blob = new Blob([response.data], { type: 'text/csv;charset=utf-8;' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    const filename = `laporan-${typeKey}-${new Date().toISOString().slice(0, 10)}.csv`
    link.setAttribute('download', filename)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)

    exportSuccessMessage.value = `Berhasil mengunduh ${customName || 'Laporan'} (.CSV)`
    setTimeout(() => {
      exportSuccessMessage.value = null
    }, 4000)
  } catch (error) {
    console.error('Failed to export report:', error)
    alert('Gagal mengekspor laporan. Silakan coba lagi.')
  } finally {
    exportingType.value = null
  }
}

const formatNumber = (num: number) => {
  return new Intl.NumberFormat('id-ID').format(num)
}

const formatCurrency = (num: number) => {
  if (num >= 1_000_000_000) {
    return `Rp ${(num / 1_000_000_000).toFixed(2)}M`
  }
  if (num >= 1_000_000) {
    return `Rp ${(num / 1_000_000).toFixed(1)}Jt`
  }
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(num)
}

const handleClickOutside = (e: MouseEvent) => {
  const target = e.target as HTMLElement
  if (!target.closest('.period-select-wrap')) {
    showPeriodDropdown.value = false
  }
}

onMounted(() => {
  fetchStats()
  fetchChart('8w')
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <section class="reports-page">
    <!-- Header -->
    <div class="page-heading">
      <div>
        <p class="eyebrow">INVENTARIS / KONFIGURASI / LAPORAN</p>
        <h1>Laporan & Analisis</h1>
        <p class="muted">Tinjau performa inventaris, riwayat mutasi, dan unduh laporan resmi dalam format CSV / Excel.</p>
      </div>
      <button class="primary-button" @click="showExportModal = true">
        <Download :size="16" /> Ekspor laporan
      </button>
    </div>

    <!-- Notification Alert when Export succeeds -->
    <Transition name="fade">
      <div v-if="exportSuccessMessage" class="export-alert">
        <CheckCircle2 :size="16" style="color: #38a77a;" />
        <span>{{ exportSuccessMessage }}</span>
      </div>
    </Transition>

    <!-- Summary Metrics -->
    <div class="report-summary">
      <div>
        <span>Nilai inventaris (HPP)</span>
        <strong v-if="isLoadingValuation"><Loader2 class="spin" :size="16" /></strong>
        <strong v-else-if="stats.inventoryValue > 0">{{ formatCurrency(stats.inventoryValue) }}</strong>
        <strong v-else style="font-size: 14px; color: #c4c1cd;">Harga belum diisi</strong>
        <em class="positive" v-if="stats.productsWithCost > 0">{{ stats.productsWithCost }} produk tervaluasi</em>
        <em class="purple-text" v-else>Isi HPP di menu Produk</em>
      </div>

      <div>
        <span>Volume pergerakan</span>
        <strong v-if="isLoading"><Loader2 class="spin" :size="16" /></strong>
        <strong v-else>{{ formatNumber(stats.movementVolume) }} <small>unit</small></strong>
        <em class="positive">Akumulasi pergerakan mutasi</em>
      </div>

      <div>
        <span>Kelengkapan harga</span>
        <strong v-if="isLoadingValuation"><Loader2 class="spin" :size="16" /></strong>
        <strong v-else>{{ stats.coveragePercent }} <small>% produk</small></strong>
        <em v-if="stats.productsWithoutCost > 0" class="purple-text">{{ stats.productsWithoutCost }} produk belum ada HPP</em>
        <em v-else class="positive">Semua produk terisi</em>
      </div>

      <div>
        <span>Format ekspor aktif</span>
        <strong style="color: #292541;">CSV / Excel</strong>
        <em class="positive">Kompatibel Spreadsheet UTF-8</em>
      </div>
    </div>

    <!-- Chart & Quick Reports Grid -->
    <div class="report-grid">
      <!-- Left Panel: Interactive Report Chart -->
      <div class="panel report-chart">
        <div class="panel-header">
          <div>
            <h2>Volume Pergerakan Stok</h2>
            <p>Perbandingan barang masuk (Inbound) vs barang keluar (Outbound)</p>
          </div>

          <!-- Period Filter Dropdown -->
          <div class="period-select-wrap" style="position: relative;">
            <button class="select-button" @click.stop="showPeriodDropdown = !showPeriodDropdown">
              <span>{{ currentPeriodLabel }}</span>
              <ChevronDown :size="14" />
            </button>

            <Transition name="fade">
              <div v-if="showPeriodDropdown" class="period-dropdown">
                <button
                  class="period-option"
                  :class="{ active: selectedPeriod === '8w' }"
                  @click="changePeriod('8w')"
                >
                  8 Minggu terakhir (Mingguan)
                </button>
                <button
                  class="period-option"
                  :class="{ active: selectedPeriod === '7d' }"
                  @click="changePeriod('7d')"
                >
                  7 Hari terakhir (Harian)
                </button>
                <button
                  class="period-option"
                  :class="{ active: selectedPeriod === '6m' }"
                  @click="changePeriod('6m')"
                >
                  6 Bulan terakhir (Bulanan)
                </button>
              </div>
            </Transition>
          </div>
        </div>

        <!-- Interactive Report Chart -->
        <div class="report-chart-container">
          <!-- Y-Axis Values -->
          <div class="report-y-axis">
            <span>{{ chartData.max_volume }}</span>
            <span>{{ Math.round(chartData.max_volume * 0.75) }}</span>
            <span>{{ Math.round(chartData.max_volume * 0.5) }}</span>
            <span>{{ Math.round(chartData.max_volume * 0.25) }}</span>
            <span>0</span>
          </div>

          <!-- Chart Surface & Columns -->
          <div class="report-chart-surface">
            <!-- Grid Lines -->
            <div class="report-grid-lines">
              <div class="report-grid-line" />
              <div class="report-grid-line" />
              <div class="report-grid-line" />
              <div class="report-grid-line" />
              <div class="report-grid-line baseline" />
            </div>

            <!-- Columns Row -->
            <div v-if="!chartLoading" class="report-bars-row">
              <div
                v-for="col in chartData.series"
                :key="col.label"
                class="report-col-item"
              >
                <!-- Tooltip on hover -->
                <div class="report-tooltip">
                  <strong class="tooltip-heading">{{ col.label }}</strong>
                  <div class="tooltip-stat">
                    <span class="tooltip-dot red" />
                    <span>Masuk:</span>
                    <strong>{{ formatNumber(col.in) }} unit</strong>
                  </div>
                  <div class="tooltip-stat">
                    <span class="tooltip-dot green" />
                    <span>Keluar:</span>
                    <strong>{{ formatNumber(col.out) }} unit</strong>
                  </div>
                  <div class="tooltip-divider" />
                  <div class="tooltip-stat total">
                    <span>Total:</span>
                    <strong>{{ formatNumber(col.volume) }} unit</strong>
                  </div>
                </div>

                <!-- Zero activity indicator dot -->
                <span
                  v-if="col.in === 0 && col.out === 0"
                  class="report-zero-indicator"
                  title="Tidak ada pergerakan"
                />

                <!-- Outbound Bar (Green) -->
                <div
                  v-if="col.out > 0"
                  class="report-bar-out"
                  :style="{ height: `${Math.max(4, Math.min(100, (col.out / chartData.max_volume) * 100))}%` }"
                />

                <!-- Inbound Bar (Crimson) -->
                <div
                  v-if="col.in > 0"
                  class="report-bar-in"
                  :style="{ height: `${Math.max(4, Math.min(100, (col.in / chartData.max_volume) * 100))}%` }"
                />

                <!-- X-Axis Label -->
                <span class="report-col-label">{{ col.label }}</span>
              </div>
            </div>

            <!-- Loading Spinner -->
            <div v-else class="report-chart-loading">
              <Loader2 class="spin" :size="24" style="color: #bd3c3c;" />
            </div>
          </div>
        </div>

        <div class="chart-legend">
          <span><i class="legend-dot red" />Stok Masuk (Inbound)</span>
          <span><i class="legend-dot green" />Stok Keluar (Outbound)</span>
          <strong class="chart-legend-info">Data aktual sistem • {{ currentPeriodLabel }}</strong>
        </div>
      </div>

      <!-- Right Panel: Quick Reports -->
      <div class="panel quick-reports">
        <div class="panel-header">
          <div>
            <h2>Laporan Cepat</h2>
            <p>Klik untuk langsung mengunduh (.CSV)</p>
          </div>
          <PieChart :size="18" class="panel-icon" />
        </div>

        <button
          v-for="report in reports"
          :key="report.id"
          class="quick-report"
          :disabled="exportingType === report.typeKey"
          @click="downloadReport(report.typeKey, report.name)"
        >
          <div class="quick-icon" :style="{ color: report.iconColor }">
            <Loader2 v-if="exportingType === report.typeKey" class="spin" :size="15" />
            <FileSpreadsheet v-else :size="15" />
          </div>
          <div style="flex: 1; min-width: 0;">
            <strong>{{ report.name }}</strong>
            <span>{{ report.description }}</span>
          </div>
          <Download :size="15" style="color: #aaa7b4; flex-shrink: 0;" />
        </button>
      </div>
    </div>

    <!-- Bottom Panel: Report Register Table -->
    <div class="panel report-register">
      <div class="panel-header">
        <div>
          <h2>Pustaka Laporan Resmi</h2>
          <p>Daftar modul laporan siap ekspor untuk arsip manajemen dan audit</p>
        </div>
      </div>

      <div class="table-scroll">
        <table class="reports-table">
          <thead>
            <tr>
              <th>Laporan</th>
              <th>Kategori</th>
              <th>Status Pembaruan</th>
              <th>Format</th>
              <th style="text-align: right;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="report in reports" :key="report.id">
              <td>
                <div class="report-name">
                  <div class="report-file" :style="{ color: report.iconColor }">
                    <FileText :size="15" />
                  </div>
                  <div>
                    <strong>{{ report.name }}</strong>
                    <span>{{ report.description }}</span>
                  </div>
                </div>
              </td>
              <td>
                <span class="category-pill">{{ report.type }}</span>
              </td>
              <td style="color: #777486; font-size: 10px;">
                {{ report.updated }}
              </td>
              <td>
                <span class="format-pill">CSV / Excel</span>
              </td>
              <td style="text-align: right;">
                <button
                  class="action-download-btn"
                  :disabled="exportingType === report.typeKey"
                  @click="downloadReport(report.typeKey, report.name)"
                >
                  <Loader2 v-if="exportingType === report.typeKey" class="spin" :size="13" />
                  <Download v-else :size="13" />
                  <span>{{ exportingType === report.typeKey ? 'Mengunduh...' : 'Unduh CSV' }}</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="reports-footer">
        <span>Menampilkan {{ reports.length }} laporan resmi terdaftar</span>
        <span><BarChart3 :size="14" /> Ekspor CSV UTF-8 aktif • Kompatibel dengan Excel & Google Sheets</span>
      </div>
    </div>

    <!-- Export Modal Dialog -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showExportModal" class="modal-backdrop" @click="showExportModal = false">
          <div class="modal-card" @click.stop>
            <div class="modal-header">
              <div style="display: flex; align-items: center; gap: 8px;">
                <Download :size="18" style="color: #bd3c3c;" />
                <h3 style="margin: 0; font-size: 14px; font-weight: 650; color: #292541;">Ekspor Laporan Inventaris</h3>
              </div>
              <button class="icon-btn" @click="showExportModal = false"><X :size="16" /></button>
            </div>

            <div class="modal-body">
              <p style="font-size: 11px; color: #777486; margin: 0 0 16px;">
                Pilih format data yang ingin Anda unduh. Seluruh laporan diekspor dalam format <strong>CSV (Comma-Separated Values)</strong> dengan enkripsi UTF-8 sehingga dapat langsung dibuka menggunakan Microsoft Excel, Apple Numbers, atau Google Sheets.
              </p>

              <div class="export-options-list">
                <div
                  v-for="rep in reports"
                  :key="rep.id"
                  class="export-option-card"
                  @click="downloadReport(rep.typeKey, rep.name); showExportModal = false;"
                >
                  <div class="export-card-icon" :style="{ color: rep.iconColor }">
                    <FileSpreadsheet :size="18" />
                  </div>
                  <div style="flex: 1;">
                    <strong style="display: block; font-size: 12px; color: #292541;">{{ rep.name }}</strong>
                    <span style="display: block; font-size: 10px; color: #858291; margin-top: 2px;">{{ rep.description }}</span>
                  </div>
                  <button class="download-mini-btn" :disabled="exportingType === rep.typeKey">
                    <Download :size="14" />
                  </button>
                </div>
              </div>
            </div>

            <div class="modal-footer">
              <button class="secondary-btn" @click="showExportModal = false">Tutup</button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </section>
</template>

<style scoped>
.reports-page {
  animation: fadeIn 0.15s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}

.export-alert {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #eaf8f1;
  color: #276749;
  border: 1px solid #c6f6d5;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 11px;
  margin-bottom: 16px;
}

.report-summary {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 18px;
}

.report-summary > div {
  background: #fff;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  padding: 16px 18px;
}

.report-summary span,
.report-summary strong,
.report-summary em {
  display: block;
}

.report-summary span {
  color: #858291;
  font-size: 11px;
}

.report-summary strong {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 22px;
  margin-top: 9px;
  letter-spacing: -0.04em;
  min-height: 26px;
}

.report-summary small {
  font-size: 11px;
  color: #aaa7b4;
  font-weight: 500;
}

.report-summary em {
  font-style: normal;
  font-size: 9px;
  margin-top: 7px;
}

.purple-text {
  color: #bd3c3c;
}

.positive {
  color: #3ca579;
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.report-grid {
  display: grid;
  grid-template-columns: 1.55fr 1fr;
  gap: 18px;
  margin-bottom: 18px;
}

.panel-header {
  padding-bottom: 16px;
}

.report-chart,
.quick-reports,
.report-register {
  overflow: hidden;
}

/* Period Dropdown */
.period-dropdown {
  position: absolute;
  right: 0;
  top: 100%;
  margin-top: 6px;
  min-width: 200px;
  background: white;
  border: 1px solid #eeedf3;
  border-radius: 8px;
  box-shadow: 0 8px 20px rgba(41, 37, 65, 0.08);
  z-index: 50;
  overflow: hidden;
}

.period-option {
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

.period-option:last-child {
  border-bottom: 0;
}

.period-option:hover {
  background: #f9f8fc;
  color: #bd3c3c;
}

.period-option.active {
  background: #fff4f4;
  color: #bd3c3c;
  font-weight: 650;
}

/* Interactive Report Chart Container */
.report-chart-container {
  height: 240px;
  display: flex;
  padding: 10px 20px 6px;
  position: relative;
  box-sizing: border-box;
}

.report-y-axis {
  width: 36px;
  height: calc(100% - 26px);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  color: #aaa7b4;
  font-size: 9.5px;
  user-select: none;
  padding-right: 8px;
  text-align: right;
  flex-shrink: 0;
  box-sizing: border-box;
}

.report-chart-surface {
  position: relative;
  flex: 1;
  height: 100%;
  min-width: 0;
}

.report-grid-lines {
  position: absolute;
  inset: 6px 0 26px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  pointer-events: none;
}

.report-grid-line {
  width: 100%;
  border-top: 1px dashed #eeedf3;
}

.report-grid-line.baseline {
  border-top: 1px solid #e0dfea;
}

.report-bars-row {
  position: absolute;
  inset: 6px 0 0;
  display: flex;
  align-items: stretch;
  justify-content: space-around;
  gap: 8px;
  z-index: 2;
}

.report-col-item {
  height: 100%;
  flex: 1;
  max-width: 54px;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  cursor: pointer;
  transition: transform 0.15s ease;
}

.report-col-item:hover {
  transform: translateY(-2px);
}

.report-col-label {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 22px;
  line-height: 22px;
  text-align: center;
  color: #8c889a;
  font-size: 10px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  user-select: none;
}

.report-col-item:hover .report-col-label {
  color: #bd3c3c;
  font-weight: 650;
}

.report-bar-in {
  position: absolute;
  bottom: 26px;
  width: 18px;
  border-radius: 4px 4px 0 0;
  background: linear-gradient(180deg, #d84b4b, #bd3c3c);
  box-shadow: 0 -2px 6px rgba(189, 60, 60, 0.25);
  transition: height 0.35s cubic-bezier(0.4, 0, 0.2, 1);
  min-height: 0;
}

.report-bar-out {
  position: absolute;
  bottom: 26px;
  width: 12px;
  border-radius: 4px 4px 0 0;
  background: #39a478;
  transform: translateX(8px);
  z-index: 3;
  transition: height 0.35s cubic-bezier(0.4, 0, 0.2, 1);
  min-height: 0;
}

.report-zero-indicator {
  position: absolute;
  bottom: 24.5px;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: #d4d2dd;
  pointer-events: none;
}

.report-tooltip {
  position: absolute;
  bottom: calc(100% - 10px);
  left: 50%;
  transform: translateX(-50%) translateY(4px);
  background: #1f1b2e;
  color: #ffffff;
  padding: 8px 11px;
  border-radius: 7px;
  font-size: 10px;
  white-space: nowrap;
  pointer-events: none;
  opacity: 0;
  transition: all 0.15s ease;
  z-index: 30;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22);
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 130px;
}

.report-col-item:hover .report-tooltip {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}

.tooltip-heading {
  font-size: 11px;
  font-weight: 700;
  margin-bottom: 2px;
  color: #ffffff;
}

.tooltip-stat {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 9.5px;
  color: #b5b1c4;
}

.tooltip-stat strong {
  margin-left: auto;
  color: #ffffff;
}

.tooltip-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  display: inline-block;
}

.tooltip-dot.red { background: #ef7d7d; }
.tooltip-dot.green { background: #6ee7b7; }

.tooltip-divider {
  height: 1px;
  background: rgba(255, 255, 255, 0.12);
  margin: 3px 0;
}

.tooltip-stat.total {
  color: #ffffff;
  font-weight: 600;
}

.report-chart-loading {
  display: grid;
  place-items: center;
  height: 100%;
}

.chart-legend {
  display: flex;
  align-items: center;
  gap: 18px;
  padding: 12px 20px 14px;
  color: #716d80;
  font-size: 11px;
  border-top: 1px solid #f0eff4;
}

.legend-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 6px;
  vertical-align: middle;
}

.legend-dot.red { background: #bd3c3c; }
.legend-dot.green { background: #39a478; }

.chart-legend-info {
  margin-left: auto;
  color: #aaa7b4;
  font-weight: 400;
  font-size: 10.5px;
}

.legend {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  margin-right: 6px;
  background: #bd3c3c;
}

.legend.green {
  background: #54b58d;
}

/* Quick Reports */
.quick-reports .panel-header {
  border-bottom: 1px solid #efedf3;
}

.panel-icon {
  color: #bd3c3c;
}

.quick-report {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 13px 18px;
  background: transparent;
  border: 0;
  border-bottom: 1px solid #f0eef4;
  text-align: left;
  color: #aaa7b4;
  cursor: pointer;
  transition: background 0.15s;
}

.quick-report:hover {
  background: #faf9fd;
}

.quick-report strong,
.quick-report span {
  display: block;
}

.quick-report strong {
  color: #292541;
  font-size: 11px;
}

.quick-report span {
  font-size: 9px;
  margin-top: 3px;
  color: #858291;
}

.quick-icon {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: #f7f6fb;
  flex-shrink: 0;
}

/* Reports Table */
.report-register {
  margin-bottom: 18px;
}

.reports-table {
  min-width: 800px;
}

.reports-table th,
.reports-table td {
  padding-left: 19px;
  padding-right: 19px;
}

.reports-table td {
  color: #292541;
}

.report-name {
  display: flex;
  align-items: center;
  gap: 12px;
}

.report-file {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 8px;
  background: #faf9fd;
  flex-shrink: 0;
}

.report-name strong,
.report-name span {
  display: block;
}

.report-name strong {
  font-size: 11px;
}

.report-name span {
  font-size: 9px;
  color: #aaa7b4;
  margin-top: 3px;
}

.category-pill,
.format-pill {
  font-size: 9px;
  padding: 4px 7px;
  border-radius: 4px;
  background: #fceef1;
  color: #bd3c3c;
}

.format-pill {
  color: #777486;
  background: #f2f2f5;
}

.action-download-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 11px;
  border-radius: 6px;
  border: 1px solid #e9e7ef;
  background: #fff;
  color: #555164;
  font-size: 10px;
  cursor: pointer;
  transition: all 0.15s;
}

.action-download-btn:hover {
  background: #fff5f5;
  color: #bd3c3c;
  border-color: #f7dede;
}

.reports-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 19px;
  color: #aaa7b4;
  font-size: 10px;
}

.reports-footer span:last-child {
  display: flex;
  align-items: center;
  gap: 5px;
}

/* Modal */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(41, 37, 65, 0.45);
  backdrop-filter: blur(2px);
  z-index: 100;
  display: grid;
  place-items: center;
  padding: 16px;
}

.modal-card {
  width: 100%;
  max-width: 480px;
  background: white;
  border-radius: 12px;
  box-shadow: 0 15px 35px rgba(41, 37, 65, 0.15);
  overflow: hidden;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #f0eff3;
}

.modal-body {
  padding: 20px;
}

.export-options-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.export-option-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid #eeedf3;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.15s;
}

.export-option-card:hover {
  border-color: #bd3c3c;
  background: #fff8f8;
}

.export-card-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: #f7f6fb;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.download-mini-btn {
  border: 0;
  background: transparent;
  color: #aaa7b4;
  cursor: pointer;
  padding: 4px;
}

.export-option-card:hover .download-mini-btn {
  color: #bd3c3c;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  padding: 14px 20px;
  border-top: 1px solid #f0eff3;
  background: #fafafd;
}

.secondary-btn {
  padding: 8px 16px;
  border: 1px solid #e9e7ef;
  background: white;
  color: #555164;
  border-radius: 6px;
  font-size: 11px;
  cursor: pointer;
}

.secondary-btn:hover {
  background: #f7f6fb;
}

.icon-btn {
  border: 0;
  background: transparent;
  color: #aaa7b4;
  cursor: pointer;
  display: grid;
  place-items: center;
}

.icon-btn:hover {
  color: #292541;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

@media (max-width: 850px) {
  .report-grid {
    grid-template-columns: 1fr;
  }
  .report-summary {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
