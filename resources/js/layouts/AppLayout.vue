<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import {
  ArrowDownToLine,
  ArrowLeftRight,
  ArrowUpRight,
  Bell,
  Boxes,
  Building2,
  ChevronDown,
  CircleAlert,
  CircleCheck,
  CircleHelp,
  ClipboardList,
  FileBarChart,
  LayoutDashboard,
  LogOut,
  Menu,
  Package,
  PanelLeft,
  PanelLeftClose,
  Plug,
  Scale,
  Search,
  Settings,
  ShieldCheck,
  SlidersHorizontal,
  Tags,
  Truck,
  User as UserIcon,
  UserCog,
  Users,
  Warehouse,
  X
} from 'lucide-vue-next'
import { useAuthStore } from '../app/stores/auth'

const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()

// Collapsed state (persisted)
const isCollapsed = ref(localStorage.getItem('stockroom_sidebar_collapsed') === 'true')

function toggleCollapse() {
  isCollapsed.value = !isCollapsed.value
  localStorage.setItem('stockroom_sidebar_collapsed', String(isCollapsed.value))
}

type NavItem = {
  label: string
  icon: any
  routeName: string
  badgeKey?: 'reservations' | 'transfers'
}

type NavGroup = {
  label: string
  items: NavItem[]
}

const groups: NavGroup[] = [
  {
    label: 'Ringkasan',
    items: [
      { label: 'Dasbor', icon: LayoutDashboard, routeName: 'Dashboard' },
      { label: 'Laporan & Ekspor', icon: FileBarChart, routeName: 'Reports' },
    ]
  },
  {
    label: 'Inventaris',
    items: [
      { label: 'Katalog Produk', icon: Package, routeName: 'Products' },
      { label: 'Saldo Stok', icon: Boxes, routeName: 'StockOverview' },
      { label: 'Pergerakan Stok', icon: ArrowLeftRight, routeName: 'StockMovements' },
      { label: 'Reservasi', icon: ClipboardList, routeName: 'Reservations', badgeKey: 'reservations' },
      { label: 'Aset Berseri', icon: ShieldCheck, routeName: 'SerializedAssets' },
    ]
  },
  {
    label: 'Operasi Gudang',
    items: [
      { label: 'Penerimaan', icon: ArrowDownToLine, routeName: 'Receiving' },
      { label: 'Transfer Stok', icon: Truck, routeName: 'Transfers', badgeKey: 'transfers' },
      { label: 'Penyesuaian (Opname)', icon: SlidersHorizontal, routeName: 'Adjustments' },
      { label: 'Pengembalian / Retur', icon: ArrowUpRight, routeName: 'Returns' },
    ]
  },
  {
    label: 'Master Data',
    items: [
      { label: 'Kategori', icon: Tags, routeName: 'Categories' },
      { label: 'Satuan (UoM)', icon: Scale, routeName: 'Units' },
      { label: 'Gudang & Lokasi', icon: Warehouse, routeName: 'Warehouses' },
      { label: 'Pemasok / Vendor', icon: Users, routeName: 'Suppliers' },
    ]
  },
  {
    label: 'Sistem & Konfigurasi',
    items: [
      { label: 'Pengguna & Akses', icon: UserCog, routeName: 'Users' },
      { label: 'Integrasi API', icon: Plug, routeName: 'Integrations' },
      { label: 'Pengaturan Sistem', icon: Settings, routeName: 'Settings' },
    ]
  },
]

// Dynamic badges from Dashboard API
const badgeCounts = ref({
  reservations: 0,
  transfers: 0,
  lowStockCount: 0,
})

const lowStockAlerts = ref<any[]>([])

async function loadBadgeCounts() {
  try {
    const res = await axios.get('/dashboard')
    const summary = res.data?.data?.summary
    if (summary) {
      badgeCounts.value.reservations = Number(summary.pending_reservations) || 0
      badgeCounts.value.transfers = Number(summary.pending_transfers) || 0
      badgeCounts.value.lowStockCount = Number(summary.low_stock_count) || 0
    }
    const lowItems = res.data?.data?.low_stock_items
    if (Array.isArray(lowItems)) {
      lowStockAlerts.value = lowItems.slice(0, 5)
    }
  } catch (err) {
    // Graceful fallback
  }
}

const active = computed(() => {
  for (const group of groups) {
    for (const item of group.items) {
      if (item.routeName === route.name) return item.label
    }
  }
  return String(route.name || 'Dasbor')
})

const companyName = computed(() => {
  return authStore.user?.company?.name || 'PT Solusi Inventaris'
})

const companyCode = computed(() => {
  return authStore.user?.company?.code || 'DEFAULT'
})

const companyInitial = computed(() => {
  return companyName.value.trim().charAt(0).toUpperCase() || 'S'
})

const userInitial = computed(() => {
  return authStore.user?.name?.substring(0, 2).toUpperCase() || 'U'
})

const userRole = computed(() => {
  const roles = authStore.user?.roles
  if (Array.isArray(roles) && roles.length > 0) {
    return String(roles[0]).replace(/_/g, ' ')
  }
  return 'Administrator'
})

const mobileOpen = ref(false)

function go(item: NavItem) {
  if (item.routeName) {
    router.push({ name: item.routeName })
  }
  mobileOpen.value = false
  closeAllDropdowns()
}

// Dropdowns state
const showUserMenu = ref(false)
const showWorkspaceMenu = ref(false)
const showNotificationMenu = ref(false)
const showHelpModal = ref(false)
const showCommandPalette = ref(false)
const commandSearch = ref('')
const commandInput = ref<HTMLInputElement | null>(null)

function openCommandPalette() {
  showCommandPalette.value = true
  commandSearch.value = ''
  nextTick(() => {
    commandInput.value?.focus()
  })
}

watch(showCommandPalette, (isOpen) => {
  if (isOpen) {
    nextTick(() => {
      commandInput.value?.focus()
    })
  }
})

function closeAllDropdowns() {
  showUserMenu.value = false
  showWorkspaceMenu.value = false
  showNotificationMenu.value = false
}

const handleLogout = async () => {
  closeAllDropdowns()
  await authStore.logout()
  router.push({ name: 'Login' })
}

// Command palette items
const allNavItems = computed(() => {
  const list: { group: string; label: string; icon: any; routeName: string }[] = []
  for (const group of groups) {
    for (const item of group.items) {
      list.push({
        group: group.label,
        label: item.label,
        icon: item.icon,
        routeName: item.routeName
      })
    }
  }
  return list
})

const filteredCommandItems = computed(() => {
  const q = commandSearch.value.trim().toLowerCase()
  if (!q) return allNavItems.value
  return allNavItems.value.filter(item =>
    item.label.toLowerCase().includes(q) ||
    item.group.toLowerCase().includes(q) ||
    item.routeName.toLowerCase().includes(q)
  )
})

function executeCommand(item: { routeName: string }) {
  showCommandPalette.value = false
  commandSearch.value = ''
  router.push({ name: item.routeName })
}

// Global keyboard shortcuts
function handleKeyDown(e: KeyboardEvent) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    if (showCommandPalette.value) {
      showCommandPalette.value = false
    } else {
      openCommandPalette()
    }
  } else if (e.key === 'Escape') {
    showCommandPalette.value = false
    showHelpModal.value = false
    closeAllDropdowns()
  }
}

// Click outside handler
function handleDocumentClick(e: MouseEvent) {
  const target = e.target as HTMLElement
  if (!target.closest('.user-card-wrap') && !target.closest('.user-menu-popover') && !target.closest('.top-user-wrap')) {
    showUserMenu.value = false
  }
  if (!target.closest('.workspace-switcher-wrap') && !target.closest('.workspace-menu-popover')) {
    showWorkspaceMenu.value = false
  }
  if (!target.closest('.notification-trigger-wrap') && !target.closest('.notification-popover')) {
    showNotificationMenu.value = false
  }
}

onMounted(() => {
  loadBadgeCounts()
  window.addEventListener('keydown', handleKeyDown)
  document.addEventListener('click', handleDocumentClick)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown)
  document.removeEventListener('click', handleDocumentClick)
})

const menuDescriptions: Record<string, string> = {
  'Dashboard': 'Halaman utama visualisasi data ringkas tingkat tinggi, statistik metrik penting persediaan, dan grafik pergerakan terbaru.',
  'Reports': 'Pusat penarikan laporan analitik, histori mutasi, pergerakan berkala, serta ekspor file CSV untuk kebutuhan manajemen dan audit.',
  'Products': 'Katalog utama barang. Di sini Anda mendata nama, SKU, barcode, harga pokok, stok minimum, serta kategori dan satuan ukur.',
  'StockOverview': 'Menampilkan agregasi total fisik stok yang tersedia untuk setiap produk, lengkap dengan pemisahan per gudang atau lokasi.',
  'StockMovements': 'Buku besar gudang Anda. Mencatat seluruh riwayat kapan sebuah barang masuk, keluar, atau dipindahkan antar lokasi secara kronologis.',
  'Reservations': 'Digunakan untuk mengunci atau mengalokasikan stok untuk pesanan di masa mendatang agar tidak terjadi kelebihan jual (overselling).',
  'SerializedAssets': 'Pelacakan barang bernilai tinggi dengan Nomor Seri (S/N) unik individual. Memantau posisi alat, status pemeliharaan, dan peminjaman.',
  'Receiving': 'Mencatat penerimaan barang yang baru tiba di gudang dari vendor/pemasok atau Purchase Order (Inbound).',
  'Transfers': 'Mencatat proses pengiriman atau pemindahan fisik barang dari satu fasilitas gudang ke gudang lainnya.',
  'Adjustments': 'Fasilitas untuk proses audit stok (Stock Opname). Digunakan untuk menyesuaikan selisih jumlah fisik di gudang dengan sistem.',
  'Returns': 'Mencatat alur retur barang, baik dari pelanggan yang mengembalikan barang maupun retur kembali ke pemasok.',
  'Categories': 'Pengelompokan jenis produk agar katalog persediaan lebih terstruktur dan mudah dianalisis.',
  'Units': 'Pengaturan basis data satuan ukur (Unit of Measurement) seperti Pcs, Kotak, Meter, Roll, dan lainnya.',
  'Warehouses': 'Manajemen fasilitas gudang cabang dan pemetaan rak atau sub-lokasi penyimpanan spesifik.',
  'Suppliers': 'Menyimpan profil, kontak, alamat, serta informasi detail vendor penyedia stok barang Anda.',
  'Users': 'Manajemen akun pengguna sistem dan penugasan peran otorisasi berbasis wewenang (Role-Based Access Control).',
  'Integrations': 'Pengaturan koneksi API key, kredensial layanan pihak ketiga, dan webhook sinkronisasi sistem eksternal.',
  'Settings': 'Pusat konfigurasi profil perusahaan, aturan pembatasan stok minus, notifikasi stok minimum, dan keamanan akun.'
}

const currentHelpDescription = computed(() => {
  return menuDescriptions[String(route.name)] || 'Halaman ini adalah bagian terintegrasi dari sistem manajemen inventaris Stockroom.'
})
</script>

<template>
  <div class="app-shell">
    <!-- SIDEBAR -->
    <aside
      class="sidebar"
      :class="{
        'mobile-open': mobileOpen,
        'collapsed': isCollapsed
      }"
    >
      <!-- BRAND ROW -->
      <div class="brand-row" :class="{ 'is-collapsed': isCollapsed }">
        <div
          class="brand-mark"
          :class="{ 'is-clickable': isCollapsed }"
          :title="isCollapsed ? 'Perluas Sidebar' : companyName"
          @click="isCollapsed && toggleCollapse()"
        >
          <Boxes :size="20" />
        </div>
        <div v-show="!isCollapsed" class="brand-info">
          <strong>Stockroom</strong>
          <span>Platform Inventaris</span>
        </div>
        <div class="brand-actions">
          <!-- Desktop Collapse Toggle -->
          <button
            class="collapse-toggle-btn"
            :title="isCollapsed ? 'Perluas Sidebar' : 'Ciutkan Sidebar'"
            @click="toggleCollapse"
          >
            <PanelLeftClose v-if="!isCollapsed" :size="16" />
            <PanelLeft v-else :size="16" />
          </button>
          <!-- Mobile Drawer Close Button -->
          <button
            class="mobile-close-btn"
            aria-label="Tutup menu"
            @click="mobileOpen = false"
          >
            <X :size="18" />
          </button>
        </div>
      </div>

      <!-- WORKSPACE SWITCHER -->
      <div class="workspace-switcher-wrap">
        <div
          class="workspace-switcher"
          :class="{ 'compact': isCollapsed }"
          :title="isCollapsed ? companyName : undefined"
          @click="showWorkspaceMenu = !showWorkspaceMenu"
        >
          <div class="workspace-icon">{{ companyInitial }}</div>
          <div v-show="!isCollapsed" class="workspace-details">
            <span>Ruang Kerja</span>
            <strong>{{ companyName }}</strong>
          </div>
          <ChevronDown v-show="!isCollapsed" :size="14" class="workspace-chevron" />
        </div>

        <!-- WORKSPACE POPUP -->
        <Transition name="fade">
          <div v-if="showWorkspaceMenu" class="workspace-menu-popover">
            <div class="popover-header">
              <Building2 :size="15" style="color: #bd3c3c;" />
              <div>
                <strong>{{ companyName }}</strong>
                <span>Kode: {{ companyCode }}</span>
              </div>
            </div>
            <div class="popover-divider" />
            <button
              class="popover-item"
              @click="go({ label: 'Pengaturan', icon: Settings, routeName: 'Settings' })"
            >
              <Settings :size="14" />
              <span>Kelola Profil Perusahaan</span>
            </button>
          </div>
        </Transition>
      </div>

      <!-- NAVIGATION AREA -->
      <nav class="nav-area" aria-label="Navigasi utama">
        <div v-for="group in groups" :key="group.label" class="nav-group">
          <p v-show="!isCollapsed" class="group-title">{{ group.label }}</p>
          <div v-show="isCollapsed" class="group-divider" :title="group.label" />

          <button
            v-for="item in group.items"
            :key="item.label"
            class="nav-item"
            :class="{
              active: active === item.label,
              'is-collapsed': isCollapsed
            }"
            :aria-current="active === item.label ? 'page' : undefined"
            :title="isCollapsed ? item.label : undefined"
            @click="go(item)"
          >
            <div class="item-icon-wrap">
              <component :is="item.icon" :size="17" />
              <!-- Collapsed badge dot -->
              <span
                v-if="isCollapsed && item.badgeKey && badgeCounts[item.badgeKey] > 0"
                class="badge-dot"
              />
            </div>
            <span v-show="!isCollapsed" class="item-label">{{ item.label }}</span>
            <!-- Expanded badge pill -->
            <em
              v-if="!isCollapsed && item.badgeKey && badgeCounts[item.badgeKey] > 0"
              class="badge-count"
            >
              {{ badgeCounts[item.badgeKey] }}
            </em>
          </button>
        </div>
      </nav>

      <!-- SIDEBAR FOOTER -->
      <div class="sidebar-footer">
        <!-- HELP CARD -->
        <div
          v-show="!isCollapsed"
          class="help-card"
          @click="showHelpModal = true"
          title="Klik untuk melihat panduan modul ini"
        >
          <CircleHelp :size="16" />
          <div>
            <strong>Panduan Modul</strong>
            <span>{{ active }}</span>
          </div>
          <ArrowUpRight :size="14" />
        </div>

        <!-- USER CARD -->
        <div class="user-card-wrap">
          <div
            class="user-card"
            :class="{ 'compact': isCollapsed }"
            @click="showUserMenu = !showUserMenu"
            :title="isCollapsed ? `${authStore.user?.name} (${userRole})` : undefined"
          >
            <div class="avatar-wrap">
              <div class="avatar">{{ userInitial }}</div>
              <span class="user-online-dot" />
            </div>
            <div v-show="!isCollapsed" class="user-text">
              <strong>{{ authStore.user?.name || 'User' }}</strong>
              <span>{{ userRole }}</span>
            </div>
            <button
              v-show="!isCollapsed"
              class="dots-button"
              aria-label="Menu pengguna"
              @click.stop="showUserMenu = !showUserMenu"
            >
              <ChevronDown :size="15" />
            </button>
          </div>

          <!-- USER DROPDOWN POPOVER -->
          <Transition name="fade">
            <div v-if="showUserMenu" class="user-menu-popover">
              <div class="popover-user-head">
                <div class="avatar lg">{{ userInitial }}</div>
                <div>
                  <strong>{{ authStore.user?.name }}</strong>
                  <span class="user-email">{{ authStore.user?.email }}</span>
                  <span class="role-badge">{{ userRole }}</span>
                </div>
              </div>
              <div class="popover-divider" />
              <button
                class="popover-item"
                @click="go({ label: 'Pengaturan', icon: Settings, routeName: 'Settings' })"
              >
                <UserIcon :size="14" />
                <span>Profil & Keamanan Akun</span>
              </button>
              <button
                class="popover-item"
                @click="showHelpModal = true; showUserMenu = false"
              >
                <CircleHelp :size="14" />
                <span>Bantuan & Panduan Sistem</span>
              </button>
              <div class="popover-divider" />
              <button class="popover-item danger" @click="handleLogout">
                <LogOut :size="14" />
                <span>Keluar dari Sistem</span>
              </button>
            </div>
          </Transition>
        </div>
      </div>
    </aside>

    <!-- MOBILE SCRIM BACKDROP -->
    <button
      v-if="mobileOpen"
      class="scrim"
      aria-label="Tutup sidebar"
      @click="mobileOpen = false"
    />

    <!-- MAIN APP CONTENT -->
    <main class="main-content">
      <!-- TOPBAR -->
      <header class="topbar">
        <button
          class="icon-button menu-toggle"
          aria-label="Buka menu navigasi"
          @click="mobileOpen = true"
        >
          <Menu :size="20" />
        </button>

        <div class="breadcrumbs">
          <span>{{ companyName }}</span>
          <b>/</b>
          <strong>{{ active }}</strong>
        </div>

        <div class="top-actions">
          <!-- SEARCH TRIGGER / COMMAND PALETTE -->
          <div
            class="search-box"
            @click="openCommandPalette"
            title="Buka Navigasi Cepat (⌘K)"
          >
            <Search :size="16" />
            <input
              placeholder="Cari modul atau menu..."
              readonly
              aria-label="Pencarian cepat modul"
            />
            <kbd>⌘ K</kbd>
          </div>

          <!-- HELP BUTTON -->
          <button
            class="icon-button header-btn"
            aria-label="Info modul ini"
            title="Panduan modul ini"
            @click="showHelpModal = true"
          >
            <CircleHelp :size="18" />
          </button>

          <!-- NOTIFICATION BELL & DROPDOWN -->
          <div class="notification-trigger-wrap">
            <button
              class="icon-button notification header-btn"
              aria-label="Notifikasi persediaan"
              title="Notifikasi persediaan"
              @click="showNotificationMenu = !showNotificationMenu"
            >
              <Bell :size="18" />
              <i v-if="badgeCounts.lowStockCount > 0 || badgeCounts.reservations > 0" />
            </button>

            <!-- NOTIFICATION POPOVER -->
            <Transition name="fade">
              <div v-if="showNotificationMenu" class="notification-popover">
                <div class="notification-head">
                  <strong>Pemberitahuan Sistem</strong>
                  <span v-if="badgeCounts.lowStockCount > 0" class="badge-tag">
                    {{ badgeCounts.lowStockCount }} Peringatan
                  </span>
                </div>
                <div class="notification-body">
                  <div
                    v-if="badgeCounts.lowStockCount > 0"
                    class="notify-item warning"
                    @click="go({ label: 'Saldo Stok', icon: Boxes, routeName: 'StockOverview' })"
                  >
                    <CircleAlert :size="16" />
                    <div>
                      <strong>{{ badgeCounts.lowStockCount }} Produk Stok Menipis</strong>
                      <span>Jumlah fisik berada di bawah batas minimum gudang.</span>
                    </div>
                  </div>
                  <div
                    v-if="badgeCounts.reservations > 0"
                    class="notify-item info"
                    @click="go({ label: 'Reservasi', icon: ClipboardList, routeName: 'Reservations' })"
                  >
                    <ClipboardList :size="16" />
                    <div>
                      <strong>{{ badgeCounts.reservations }} Reservasi Aktif</strong>
                      <span>Ada pesanan yang mengalokasikan stok barang.</span>
                    </div>
                  </div>
                  <div
                    v-if="badgeCounts.lowStockCount === 0 && badgeCounts.reservations === 0"
                    class="notify-empty"
                  >
                    <div class="empty-icon-circle">
                      <CircleCheck :size="20" />
                    </div>
                    <p>Semua sistem & stok dalam batas aman.</p>
                  </div>
                </div>
                <div class="notification-foot">
                  <button
                    class="view-all-link"
                    @click="go({ label: 'Dasbor', icon: LayoutDashboard, routeName: 'Dashboard' })"
                  >
                    Lihat Dasbor Ringkasan
                  </button>
                </div>
              </div>
            </Transition>
          </div>

          <!-- TOPBAR USER AVATAR & DROPDOWN -->
          <div class="top-user-wrap">
            <div
              class="top-avatar"
              @click.stop="showUserMenu = !showUserMenu"
              :title="authStore.user?.name"
            >
              {{ userInitial }}
            </div>

            <!-- TOPBAR USER DROPDOWN POPOVER -->
            <Transition name="fade">
              <div v-if="showUserMenu" class="user-menu-popover top-user-popover">
                <div class="popover-user-head">
                  <div class="avatar lg">{{ userInitial }}</div>
                  <div>
                    <strong>{{ authStore.user?.name }}</strong>
                    <span class="user-email">{{ authStore.user?.email }}</span>
                    <span class="role-badge">{{ userRole }}</span>
                  </div>
                </div>
                <div class="popover-divider" />
                <button
                  class="popover-item"
                  @click="go({ label: 'Pengaturan', icon: Settings, routeName: 'Settings' })"
                >
                  <UserIcon :size="14" />
                  <span>Profil & Keamanan Akun</span>
                </button>
                <button
                  class="popover-item"
                  @click="showHelpModal = true; showUserMenu = false"
                >
                  <CircleHelp :size="14" />
                  <span>Bantuan & Panduan Sistem</span>
                </button>
                <div class="popover-divider" />
                <button class="popover-item danger" @click="handleLogout">
                  <LogOut :size="14" />
                  <span>Keluar dari Sistem</span>
                </button>
              </div>
            </Transition>
          </div>
        </div>
      </header>

      <!-- PAGE WRAPPER & ROUTER VIEW -->
      <div class="page-wrap">
        <router-view />
        <footer>
          <span>Stockroom Enterprise v1.0 • {{ companyName }}</span>
          <span>Semua sistem aktif & aman <i class="online-dot" /></span>
        </footer>
      </div>
    </main>

    <!-- HELP MODAL -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showHelpModal" class="modal-backdrop" @click="showHelpModal = false">
          <div class="modal-content help-modal-card" @click.stop>
            <!-- Header -->
            <div class="modal-header">
              <div class="help-header-title">
                <div class="help-icon-badge">
                  <CircleHelp :size="18" />
                </div>
                <div>
                  <h2>Fungsi Modul</h2>
                  <span class="help-subtitle">{{ active }}</span>
                </div>
              </div>
              <button
                class="modal-close-btn"
                aria-label="Tutup panduan"
                @click="showHelpModal = false"
              >
                <X :size="17" />
              </button>
            </div>

            <!-- Body -->
            <div class="help-body">
              <p>{{ currentHelpDescription }}</p>
            </div>

            <!-- Footer -->
            <div class="help-footer">
              <button class="primary-button help-confirm-btn" @click="showHelpModal = false">
                Saya Mengerti
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- COMMAND PALETTE MODAL (⌘K) -->
    <Teleport to="body">
      <Transition name="fade" @after-enter="commandInput?.focus()">
        <div v-if="showCommandPalette" class="modal-backdrop" @click="showCommandPalette = false">
          <div class="modal-content command-palette-card" @click.stop>
            <div class="command-search-head">
              <Search :size="18" style="color: #bd3c3c;" />
              <input
                ref="commandInput"
                v-model="commandSearch"
                placeholder="Ketik nama modul, menu, atau fitur..."
                @keydown.enter="filteredCommandItems[0] && executeCommand(filteredCommandItems[0])"
              />
              <span class="esc-badge">ESC</span>
            </div>
            <div class="command-list">
              <div v-if="filteredCommandItems.length === 0" class="command-empty">
                Tidak ada menu yang sesuai dengan pencarian "{{ commandSearch }}"
              </div>
              <button
                v-for="item in filteredCommandItems"
                :key="item.routeName"
                class="command-item"
                @click="executeCommand(item)"
              >
                <div class="command-icon">
                  <component :is="item.icon" :size="16" />
                </div>
                <div class="command-text">
                  <strong>{{ item.label }}</strong>
                  <span>{{ item.group }}</span>
                </div>
                <span class="jump-pill">Buka</span>
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
/* Transisi Dasar */
.fade-enter-active, .fade-leave-active { transition: opacity .2s ease, transform .2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(-6px); }

/* Sticky & Responsive Sidebar */
.sidebar {
  width: 256px;
  flex: 0 0 256px;
  height: 100vh;
  max-height: 100vh;
  position: sticky;
  top: 0;
  background: #ffffff;
  border-right: 1px solid #eeedf3;
  padding: 18px 12px 14px;
  display: flex;
  flex-direction: column;
  z-index: 30;
  transition: width 0.22s cubic-bezier(0.4, 0, 0.2, 1), flex 0.22s cubic-bezier(0.4, 0, 0.2, 1);
  overflow: visible;
  box-sizing: border-box;
}

.sidebar.collapsed {
  width: 74px;
  flex: 0 0 74px;
  padding: 18px 8px 14px;
}

/* Brand Row */
.brand-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 2px 6px 14px;
  color: #292541;
  flex-shrink: 0;
  transition: all 0.2s ease;
}

.sidebar.collapsed .brand-row {
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 2px 0 12px;
}

.brand-mark {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  display: grid;
  place-items: center;
  background: #bd3c3c;
  color: #ffffff;
  box-shadow: 0 4px 10px rgba(189, 60, 60, 0.25);
  flex-shrink: 0;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.brand-mark.is-clickable {
  cursor: pointer;
}

.brand-mark.is-clickable:hover {
  transform: scale(1.05);
  box-shadow: 0 6px 14px rgba(189, 60, 60, 0.35);
}

.sidebar.collapsed .brand-mark {
  margin: 0 auto;
}

.brand-info {
  flex: 1;
  min-width: 0;
}

.brand-info strong {
  display: block;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: #292541;
  line-height: 1.2;
}

.brand-info span {
  display: block;
  color: #9a98a8;
  font-size: 10px;
  margin-top: 2px;
}

.brand-actions {
  display: flex;
  align-items: center;
  margin-left: auto;
}

.sidebar.collapsed .brand-actions {
  margin: 0 auto;
  width: 100%;
  justify-content: center;
}

.collapse-toggle-btn {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  color: #9a98a8;
  border: 1px solid transparent;
  display: grid;
  place-items: center;
  cursor: pointer;
  background: transparent;
  transition: all 0.15s ease;
  padding: 0;
}

.collapse-toggle-btn:hover {
  background: #f7f6fa;
  color: #48445c;
  border-color: #eeedf3;
}

.sidebar.collapsed .collapse-toggle-btn {
  width: 32px;
  height: 28px;
  background: #f7f6fa;
  border: 1px solid #eeedf3;
  color: #6e6b82;
  border-radius: 6px;
}

.sidebar.collapsed .collapse-toggle-btn:hover {
  background: #edeaf5;
  color: #292541;
  border-color: #dddbe6;
}

.mobile-close-btn {
  display: none !important;
  width: 28px;
  height: 28px;
  border-radius: 6px;
  color: #9a98a8;
  border: 0;
  background: transparent;
  place-items: center;
  cursor: pointer;
  padding: 0;
}

.mobile-close-btn:hover {
  background: #f7f6fa;
  color: #48445c;
}

/* Workspace Switcher */
.workspace-switcher-wrap {
  position: relative;
  margin: 0 0 10px 0;
  flex-shrink: 0;
}

.workspace-switcher {
  margin: 0 !important;
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 8px 9px;
  border: 1px solid #eeedf3;
  border-radius: 9px;
  background: #fafafd;
  cursor: pointer;
  transition: all 0.15s ease;
}

.workspace-switcher:hover {
  background: #ffffff;
  border-color: #dfdce7;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}

.workspace-switcher.compact {
  justify-content: center;
  padding: 8px 0;
}

.workspace-icon {
  width: 26px;
  height: 26px;
  border-radius: 7px;
  background: #fff0f0;
  color: #bd3c3c;
  display: grid;
  place-items: center;
  font-weight: 700;
  font-size: 11px;
  flex-shrink: 0;
}

.workspace-details {
  flex: 1;
  min-width: 0;
}

.workspace-details span {
  display: block;
  color: #a09dad;
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 600;
}

.workspace-details strong {
  display: block;
  font-size: 11px;
  color: #292541;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 1px;
}

.workspace-chevron {
  color: #aaa7b8;
  margin-left: auto;
  flex-shrink: 0;
}

/* Workspace Popover */
.workspace-menu-popover {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  width: 236px;
  background: #ffffff;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  box-shadow: 0 8px 24px rgba(41, 37, 65, 0.09);
  padding: 8px;
  z-index: 100;
}

.popover-header {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 6px 8px;
}

.popover-header strong {
  display: block;
  font-size: 11px;
  color: #292541;
}

.popover-header span {
  display: block;
  font-size: 10px;
  color: #9a98a8;
}

/* Nav Area with Slim Smooth Scroll */
.nav-area {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overflow-x: hidden;
  padding-right: 2px;
  margin-right: -2px;
  scrollbar-width: thin;
  scrollbar-color: #eae9f0 transparent;
}

.nav-area::-webkit-scrollbar {
  width: 4px;
}

.nav-area::-webkit-scrollbar-thumb {
  background: #eae9f0;
  border-radius: 4px;
}

.nav-group {
  margin-bottom: 14px;
}

.nav-group:first-child {
  margin-top: 4px;
}

.group-title,
.nav-group p {
  color: #aaa8b7;
  font-size: 9.5px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 700;
  margin: 0 8px 6px !important;
}

.group-divider {
  height: 1px;
  background: #f0eff4;
  margin: 8px 8px;
}

.nav-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  border: 0;
  color: #7b788c;
  background: transparent;
  padding: 8px 9px;
  border-radius: 7px;
  font-size: 11.5px;
  text-align: left;
  margin: 2px 0;
  cursor: pointer;
  transition: all 0.15s ease;
  position: relative;
}

.nav-item:hover {
  background: #fff6f6;
  color: #bd3c3c;
}

.nav-item.active {
  background: #fff0f0;
  color: #bd3c3c;
  font-weight: 600;
}

.nav-item.active::before {
  content: '';
  position: absolute;
  left: 0;
  top: 6px;
  bottom: 6px;
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: #bd3c3c;
}

.nav-item.is-collapsed {
  justify-content: center;
  padding: 9px 0;
}

.nav-item.is-collapsed.active::before {
  left: 2px;
}

.item-icon-wrap {
  position: relative;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.item-label {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
}

.badge-count {
  font-style: normal;
  margin-left: auto;
  color: #bd3c3c;
  font-size: 10px;
  font-weight: 700;
  background: #ffdcdc;
  border-radius: 12px;
  padding: 1px 6px;
}

.badge-dot {
  position: absolute;
  top: -2px;
  right: -2px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #bd3c3c;
  border: 1px solid #ffffff;
}

/* Sidebar Footer */
.sidebar-footer {
  flex-shrink: 0;
  margin-top: auto;
  padding-top: 10px;
  border-top: 1px solid #f2f1f5;
}

.help-card {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #fff8f8;
  color: #bd3c3c;
  border: 1px solid #fde7e7;
  border-radius: 8px;
  padding: 8px 9px;
  margin-bottom: 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.help-card:hover {
  background: #fff0f0;
  border-color: #f8d3d3;
}

.help-card div {
  flex: 1;
  min-width: 0;
}

.help-card strong {
  display: block;
  font-size: 11px;
  color: #bd3c3c;
}

.help-card span {
  display: block;
  color: #8c829e;
  font-size: 9.5px;
  margin-top: 1px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* User Card */
.user-card-wrap {
  position: relative;
}

.user-card {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 7px 8px;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.15s ease;
  background: #fafafd;
  border: 1px solid #eeedf3;
}

.user-card:hover {
  background: #ffffff;
  border-color: #dfdce7;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}

.user-card.compact {
  justify-content: center;
  padding: 7px 0;
  border-color: transparent;
  background: transparent;
}

.avatar-wrap {
  position: relative;
  flex-shrink: 0;
}

.avatar {
  width: 29px;
  height: 29px;
  border-radius: 50%;
  background: #e8d7cb;
  color: #6a4f3c;
  display: grid;
  place-items: center;
  font-weight: 700;
  font-size: 10px;
}

.avatar.lg {
  width: 38px;
  height: 38px;
  font-size: 13px;
}

.user-online-dot {
  position: absolute;
  bottom: 0;
  right: 0;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #39a478;
  border: 1.5px solid #ffffff;
}

.user-text {
  flex: 1;
  min-width: 0;
}

.user-text strong {
  display: block;
  font-size: 11px;
  color: #292541;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.user-text span {
  display: block;
  font-size: 9.5px;
  color: #9a98a8;
  text-transform: capitalize;
  margin-top: 1px;
}

.dots-button {
  background: transparent;
  border: 0;
  color: #aaa7b5;
  cursor: pointer;
  display: grid;
  place-items: center;
  padding: 4px;
}

/* User Popover */
.user-menu-popover {
  position: absolute;
  bottom: calc(100% + 8px);
  left: 0;
  width: 238px;
  background: #ffffff;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  box-shadow: 0 10px 28px rgba(41, 37, 65, 0.12);
  padding: 8px;
  z-index: 100;
}

.popover-user-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 6px 8px;
}

.popover-user-head strong {
  display: block;
  font-size: 12px;
  color: #292541;
}

.user-email {
  display: block;
  font-size: 10px;
  color: #8c889a;
  margin-top: 1px;
  word-break: break-all;
}

.role-badge {
  display: inline-block;
  font-size: 9px;
  color: #bd3c3c;
  background: #fff0f0;
  border-radius: 4px;
  padding: 2px 5px;
  font-weight: 700;
  margin-top: 4px;
}

.popover-divider {
  height: 1px;
  background: #f0eff4;
  margin: 6px 0;
}

.popover-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 8px;
  border: 0;
  background: transparent;
  padding: 7px 8px;
  border-radius: 6px;
  font-size: 11px;
  color: #555164;
  cursor: pointer;
  text-align: left;
  transition: all 0.12s ease;
}

.popover-item:hover {
  background: #fafafd;
  color: #bd3c3c;
}

.popover-item.danger {
  color: #bd3c3c;
}

.popover-item.danger:hover {
  background: #fff4f4;
}

/* Topbar Header Elements */
.header-btn {
  width: 32px;
  height: 32px;
  border-radius: 7px;
  display: grid;
  place-items: center;
  cursor: pointer;
  transition: all 0.15s ease;
}

.header-btn:hover {
  background: #f4f3f8;
  color: #48445c;
}

.notification-trigger-wrap {
  position: relative;
}

/* Notification Popover */
.notification-popover {
  position: absolute;
  top: calc(100% + 10px);
  right: -30px;
  width: 290px;
  background: #ffffff;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  box-shadow: 0 10px 32px rgba(41, 37, 65, 0.12);
  padding: 10px 12px;
  z-index: 100;
}

.notification-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 8px;
  border-bottom: 1px solid #f2f1f6;
}

.notification-head strong {
  font-size: 12px;
  color: #292541;
}

.badge-tag {
  font-size: 9.5px;
  color: #d97070;
  background: #fff0f0;
  border-radius: 10px;
  padding: 2px 7px;
  font-weight: 700;
}

.notification-body {
  padding: 8px 0;
  max-height: 240px;
  overflow-y: auto;
}

.notify-item {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 8px 6px;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.12s ease;
}

.notify-item:hover {
  background: #fafafd;
}

.notify-item.warning svg {
  color: #dc706f;
  flex-shrink: 0;
  margin-top: 1px;
}

.notify-item.info svg {
  color: #bd3c3c;
  flex-shrink: 0;
  margin-top: 1px;
}

.notify-item strong {
  display: block;
  font-size: 11px;
  color: #292541;
}

.notify-item span {
  display: block;
  font-size: 9.5px;
  color: #8c889a;
  margin-top: 2px;
}

.notify-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 22px 14px 18px;
  color: #8c889a;
  font-size: 11px;
}

.empty-icon-circle {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #eaf8f1;
  color: #38a77a;
  display: grid;
  place-items: center;
  margin-bottom: 10px;
  box-shadow: 0 2px 6px rgba(56, 167, 122, 0.15);
}

.notify-empty p {
  margin: 0;
  line-height: 1.45;
  color: #676375;
  font-size: 11.5px;
  max-width: 220px;
  text-align: center;
}

.notification-foot {
  border-top: 1px solid #f2f1f6;
  padding-top: 8px;
  text-align: center;
}

.view-all-link {
  border: 0;
  background: none;
  color: #bd3c3c;
  font-size: 10.5px;
  font-weight: 600;
  cursor: pointer;
}

/* Modals */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(41, 37, 65, 0.45);
  backdrop-filter: blur(2px);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 10000;
}

.modal-content {
  background: #ffffff;
  border-radius: 12px;
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.12);
  overflow: hidden;
}

.help-modal-card {
  max-width: 460px;
  width: 92%;
  border-radius: 14px;
  background: #ffffff;
  box-shadow: 0 24px 50px rgba(41, 37, 65, 0.18), 0 2px 8px rgba(0, 0, 0, 0.05);
  overflow: hidden;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #f0eff4;
  background: #ffffff;
}

.help-header-title {
  display: flex;
  align-items: center;
  gap: 12px;
}

.help-icon-badge {
  width: 36px;
  height: 36px;
  border-radius: 9px;
  background: #fff0f0;
  color: #bd3c3c;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.help-header-title h2 {
  font-size: 14px;
  font-weight: 700;
  margin: 0;
  color: #292541;
  line-height: 1.2;
}

.help-subtitle {
  display: block;
  font-size: 11px;
  color: #8c889a;
  margin-top: 2px;
}

.modal-close-btn {
  width: 30px;
  height: 30px;
  border-radius: 7px;
  border: 0;
  background: transparent;
  color: #9a98a8;
  display: grid;
  place-items: center;
  cursor: pointer;
  transition: all 0.15s ease;
  padding: 0;
}

.modal-close-btn:hover {
  background: #f5f4f8;
  color: #292541;
}

.help-body {
  padding: 22px 22px 20px;
  line-height: 1.65;
  color: #48445c;
  font-size: 13px;
  background: #ffffff;
}

.help-body p {
  margin: 0;
}

.help-footer {
  padding: 14px 20px;
  background: #fafafd;
  border-top: 1px solid #f0eff4;
  display: flex;
}

.help-confirm-btn {
  width: 100%;
  height: 38px;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
  border-radius: 8px;
  background: #bd3c3c;
  color: #ffffff;
  border: 0;
  cursor: pointer;
  box-shadow: 0 4px 12px rgba(189, 60, 60, 0.22);
  transition: background 0.15s ease;
}

.help-confirm-btn:hover {
  background: #a83232;
}

/* Command Palette Modal */
.command-palette-card {
  max-width: 520px;
  width: 92%;
  border-radius: 12px;
}

.command-search-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 18px;
  border-bottom: 1px solid #eeedf3;
}

.command-search-head input {
  flex: 1;
  border: 0;
  outline: 0;
  font-size: 13px;
  color: #292541;
  background: transparent;
}

.esc-badge {
  font-size: 9px;
  color: #9a98a8;
  background: #f2f1f6;
  border-radius: 4px;
  padding: 2px 6px;
  font-weight: 600;
}

.command-list {
  max-height: 340px;
  overflow-y: auto;
  padding: 8px;
}

.command-empty {
  padding: 24px;
  text-align: center;
  color: #8c889a;
  font-size: 12px;
}

.command-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 11px;
  border: 0;
  background: transparent;
  padding: 9px 12px;
  border-radius: 8px;
  cursor: pointer;
  text-align: left;
  transition: all 0.12s ease;
}

.command-item:hover {
  background: #fff5f5;
}

.command-icon {
  width: 30px;
  height: 30px;
  border-radius: 7px;
  background: #f7f6fb;
  color: #6d6980;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.command-item:hover .command-icon {
  background: #ffebeb;
  color: #bd3c3c;
}

.command-text {
  flex: 1;
  min-width: 0;
}

.command-text strong {
  display: block;
  font-size: 12px;
  color: #292541;
}

.command-text span {
  display: block;
  font-size: 10px;
  color: #9a98a8;
}

.jump-pill {
  font-size: 10px;
  color: #bd3c3c;
  background: #ffecec;
  padding: 2px 7px;
  border-radius: 4px;
  font-weight: 600;
}

.top-user-wrap {
  position: relative;
}

.top-user-popover {
  position: absolute;
  top: calc(100% + 10px);
  right: 0;
  left: auto;
  bottom: auto;
  width: 240px;
  z-index: 120;
}

@media (max-width: 768px) {
  .sidebar {
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    bottom: 0 !important;
    width: 280px !important;
    max-width: 85vw !important;
    flex: none !important;
    transform: translateX(-100%) !important;
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
    z-index: 1000 !important;
    box-shadow: none !important;
    height: 100vh !important;
    max-height: 100vh !important;
    overflow-y: auto !important;
  }

  .sidebar.mobile-open {
    transform: translateX(0) !important;
    box-shadow: 0 0 40px rgba(0, 0, 0, 0.3) !important;
  }

  /* Keep full drawer layout on mobile even if collapsed was saved in desktop */
  .sidebar.collapsed {
    width: 280px !important;
    max-width: 85vw !important;
    padding: 18px 12px 14px !important;
    flex: none !important;
  }

  .sidebar.collapsed .brand-row {
    flex-direction: row !important;
    align-items: center !important;
    justify-content: flex-start !important;
    padding: 2px 6px 14px !important;
    gap: 10px !important;
  }

  .sidebar.collapsed .brand-info,
  .sidebar.collapsed .workspace-details,
  .sidebar.collapsed .workspace-chevron,
  .sidebar.collapsed .item-label,
  .sidebar.collapsed .help-card,
  .sidebar.collapsed .dots-button {
    display: flex !important;
  }

  .sidebar.collapsed .group-title {
    display: block !important;
  }

  .sidebar.collapsed .user-text {
    display: flex !important;
    flex-direction: column !important;
  }

  .sidebar.collapsed .group-divider {
    display: none !important;
  }

  .collapse-toggle-btn {
    display: none !important;
  }

  .mobile-close-btn {
    display: grid !important;
  }

  .scrim {
    display: block !important;
    position: fixed !important;
    inset: 0 !important;
    z-index: 999 !important;
    background: rgba(41, 37, 65, 0.45) !important;
    backdrop-filter: blur(2px) !important;
    border: 0 !important;
    cursor: pointer !important;
  }

  .main-content {
    width: 100% !important;
    min-width: 0 !important;
    flex: 1 1 100% !important;
  }

  .topbar {
    height: 58px !important;
    padding: 0 14px !important;
    gap: 8px !important;
  }

  .menu-toggle {
    display: grid !important;
    width: 32px !important;
    height: 32px !important;
    border-radius: 6px !important;
    place-items: center !important;
    cursor: pointer !important;
  }

  .breadcrumbs {
    font-size: 11px !important;
    min-width: 0 !important;
    overflow: hidden !important;
    white-space: nowrap !important;
  }

  .breadcrumbs span {
    max-width: 80px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    display: inline-block !important;
    vertical-align: bottom !important;
  }

  .breadcrumbs strong {
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
  }

  .top-actions {
    gap: 6px !important;
    margin-left: auto !important;
    flex-shrink: 0 !important;
  }

  .search-box {
    width: 32px !important;
    height: 32px !important;
    padding: 0 !important;
    border: 0 !important;
    background: transparent !important;
    display: grid !important;
    place-items: center !important;
    cursor: pointer !important;
  }

  .search-box input,
  .search-box kbd {
    display: none !important;
  }

  .header-btn {
    width: 32px !important;
    height: 32px !important;
  }

  .top-avatar {
    width: 30px !important;
    height: 30px !important;
    font-size: 10.5px !important;
    flex-shrink: 0 !important;
  }

  .page-wrap {
    padding: 18px 14px 22px !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
  }

  .notification-popover {
    right: -8px !important;
    width: 290px !important;
    max-width: calc(100vw - 24px) !important;
  }

  .top-user-popover {
    right: 0 !important;
    width: 240px !important;
    max-width: calc(100vw - 20px) !important;
  }

  footer {
    flex-direction: column !important;
    gap: 6px !important;
    text-align: center !important;
    align-items: center !important;
    padding: 18px 0 24px !important;
    font-size: 9.5px !important;
  }
}
</style>
