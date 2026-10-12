<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { ChevronDown, Ellipsis, Package, Plus, Search, AlertTriangle } from 'lucide-vue-next'

interface Product {
  id: string
  sku: string
  name: string
  description: string | null
  category_id: string | null
  unit_id: string
  has_serial_number: boolean
  minimum_stock: string | number
  cost_price: string | number | null
  selling_price: string | number | null
  currency: string
  stock_total: string | number | null
  category?: { id: string; name: string } | null
  unit?: { id: string; name: string; code: string } | null
}
interface CategoryOption { id: string; name: string; parent?: { name: string } | null }
interface UnitOption { id: string; name: string; code: string }

const products = ref<Product[]>([])
const categories = ref<CategoryOption[]>([])
const units = ref<UnitOption[]>([])
const loading = ref(false)
const errorMessage = ref('')

const search = ref('')
const categoryFilter = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const from = ref(0)
const to = ref(0)
let searchTimer: ReturnType<typeof setTimeout> | null = null

const toastMessage = ref('')
const toastType = ref('error')
const showToast = (msg: string, type: string = 'error') => {
  toastMessage.value = msg
  toastType.value = type
  setTimeout(() => { toastMessage.value = '' }, 3000)
}

const stats = ref({
  totalCatalog: null as number | null,
  totalCategories: null as number | null,
  totalInStock: null as number | null,
  lowStockCount: null as number | null
})

const categoryLabel = (c: CategoryOption) => (c.parent ? `${c.parent.name} › ${c.name}` : c.name)

const fetchProducts = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await axios.get('/products', {
      params: {
        page: page.value,
        search: search.value || undefined,
        category_id: categoryFilter.value || undefined,
      },
    })
    const p = res.data.data
    products.value = p.data
    lastPage.value = p.last_page
    total.value = p.total
    from.value = p.from ?? 0
    to.value = p.to ?? 0
  } catch (e: any) {
    errorMessage.value = e.response?.data?.message || 'Gagal memuat produk.'
  } finally {
    loading.value = false
  }
}

const fetchOptions = async () => {
  try {
    const [cats, us, dash] = await Promise.all([
      axios.get('/categories', { params: { flat: 1 } }),
      axios.get('/units'),
      axios.get('/dashboard')
    ])
    categories.value = cats.data.data
    units.value = us.data.data

    stats.value.totalCatalog = dash.data.data.kpi.total_catalog_products
    stats.value.totalInStock = dash.data.data.kpi.total_products_in_stock
    stats.value.lowStockCount = dash.data.data.kpi.low_stock_count
    stats.value.totalCategories = categories.value.length
  } catch {
    /* opsi gagal dimuat; form tetap bisa tampil */
  }
}

const onSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchProducts() }, 300)
}
const onFilter = () => { page.value = 1; fetchProducts() }
const goTo = (p: number) => {
  if (p < 1 || p > lastPage.value) return
  page.value = p
  fetchProducts()
}

const stockInfo = (p: Product) => {
  const stock = Number(p.stock_total ?? 0)
  const min = Number(p.minimum_stock ?? 0)
  if (stock <= 0) return { stock, status: 'Habis', tone: 'danger' }
  if (stock <= min) return { stock, status: 'Stok menipis', tone: 'warning' }
  return { stock, status: 'Tersedia', tone: 'success' }
}

// Dropdown aksi
const activeDropdown = ref<string | null>(null)
const dropdownX = ref(0)
const dropdownY = ref(0)

const toggleDropdown = (e: MouseEvent, id: string) => {
  if (activeDropdown.value === id) {
    activeDropdown.value = null
  } else {
    activeDropdown.value = id
    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
    dropdownX.value = rect.left - 120
    dropdownY.value = rect.bottom + 5
  }
}

const closeDropdown = () => {
  activeDropdown.value = null
}

// Form tambah / ubah
const showAddModal = ref(false)
const showDetailModal = ref(false)
const targetProduct = ref<Product | null>(null)
const editingId = ref<string | null>(null)
const saving = ref(false)
const formErrors = ref<Record<string, string[]>>({})
const emptyForm = () => ({ name: '', sku: '', description: '', category_id: '', unit_id: '', minimum_stock: 0, has_serial_number: false, cost_price: '' as number | '', selling_price: '' as number | '', currency: 'IDR' })
const form = ref(emptyForm())

const openDetail = (p: Product) => {
  closeDropdown()
  targetProduct.value = p
  showDetailModal.value = true
}

const openCreate = () => {
  editingId.value = null
  form.value = emptyForm()
  formErrors.value = {}
  showAddModal.value = true
}

const openEdit = (p: Product) => {
  closeDropdown()
  editingId.value = p.id
  form.value = {
    name: p.name,
    sku: p.sku,
    category_id: p.category_id ?? '',
    unit_id: p.unit_id,
    minimum_stock: Number(p.minimum_stock),
    has_serial_number: !!p.has_serial_number,
    cost_price: p.cost_price !== null && p.cost_price !== undefined ? Number(p.cost_price) : '',
    selling_price: p.selling_price !== null && p.selling_price !== undefined ? Number(p.selling_price) : '',
    currency: p.currency || 'IDR',
  }
  formErrors.value = {}
  showAddModal.value = true
}

const submit = async () => {
  saving.value = true
  formErrors.value = {}
  let hasError = false
  if (!form.value.name.trim()) { formErrors.value.name = ['Nama produk wajib diisi.']; hasError = true }
  if (!form.value.sku.trim()) { formErrors.value.sku = ['SKU wajib diisi.']; hasError = true }
  if (!form.value.unit_id) { formErrors.value.unit_id = ['Pilih satuan produk.']; hasError = true }
  if (form.value.minimum_stock === '' || Number(form.value.minimum_stock) < 0) {
    formErrors.value.minimum_stock = ['Stok minimum tidak valid.']; hasError = true
  }

  if (hasError) {
    saving.value = false
    return
  }

  const payload = {
    ...form.value,
    category_id: form.value.category_id || null,
    cost_price: form.value.cost_price === '' ? null : form.value.cost_price,
    selling_price: form.value.selling_price === '' ? null : form.value.selling_price,
  }
  try {
    if (editingId.value) {
      await axios.put(`/products/${editingId.value}`, payload)
      showToast('Produk berhasil diperbarui.', 'success')
    } else {
      await axios.post('/products', payload)
      showToast('Produk berhasil ditambahkan.', 'success')
    }
    showAddModal.value = false
    await fetchProducts()
  } catch (e: any) {
    formErrors.value = e.response?.data?.errors ?? {}
    if (!Object.keys(formErrors.value).length) {
      formErrors.value = { name: [e.response?.data?.message || 'Gagal menyimpan produk.'] }
    }
  } finally {
    saving.value = false
  }
}

const deleteModal = ref<{ show: boolean; product: Product | null; error?: string }>({ show: false, product: null })

const remove = (p: Product) => {
  closeDropdown()
  deleteModal.value = { show: true, product: p }
}

const executeDelete = async () => {
  const p = deleteModal.value.product
  if (!p) return
  try {
    await axios.delete(`/products/${p.id}`)
    deleteModal.value = { show: false, product: null }
    showToast('Produk berhasil dihapus.', 'success')
    if (products.value.length === 1 && page.value > 1) page.value--
    await fetchProducts()
  } catch (e: any) {
    showToast(e.response?.data?.message || e.response?.data?.error?.message || 'Gagal menghapus produk.', 'error')
    deleteModal.value.show = false
  }
}

const pages = computed(() => Array.from({ length: lastPage.value }, (_, i) => i + 1))

onMounted(() => {
  document.addEventListener('click', closeDropdown)
  fetchProducts()
  fetchOptions()
})

onUnmounted(() => {
  document.removeEventListener('click', closeDropdown)
})
</script>

<template>
  <section class="products-page">
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="toastMessage" class="toast-notification" :class="toastType">
          {{ toastMessage }}
        </div>
      </Transition>
    </Teleport>

    <div class="page-heading">
      <div>
        <p class="eyebrow">INVENTARIS / PRODUK</p>
        <h1>Katalog Produk</h1>
        <p class="muted">Kelola detail, harga, dan varian produk.</p>
      </div>
      <button class="primary-button" @click="openCreate"><Plus :size="17" /> Tambah produk</button>
    </div>
    
    <div class="balance-summary">
      <div>
        <span>Total katalog produk</span>
        <strong>{{ stats.totalCatalog ?? '-' }}</strong>
        <small>Semua produk terdaftar</small>
      </div>
      <div>
        <span>Total produk di stok</span>
        <strong class="total-stock">{{ stats.totalInStock ?? '-' }}</strong>
        <small>Produk yang tersedia</small>
      </div>
      <div>
        <span>Stok menipis</span>
        <strong class="summary-warning">{{ stats.lowStockCount ?? '-' }}</strong>
        <small>Perlu restock</small>
      </div>
      <div>
        <span>Total kategori</span>
        <strong>{{ stats.totalCategories ?? '-' }}</strong>
        <small>Kategori aktif</small>
      </div>
    </div>

    <div class="panel products-panel">
      
      <div class="products-toolbar" style="padding: 19px 17px; display: flex; gap: 10px;">
        <div class="product-search" style="display: flex; align-items: center; gap: 8px; flex: 1; color: #aaa7b5; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 0 10px; height: 34px;">
          <Search :size="16" />
          <input v-model="search" @input="onSearch" placeholder="Cari nama produk atau SKU..." style="width: 100%; border: 0; outline: 0; background: transparent; color: #48445c; font-size: 11px;" />
        </div>
        <div class="select-wrapper" style="min-width: 200px;">
          <select v-model="categoryFilter" @change="onFilter" style="width: 100%; height: 34px; appearance: none; border: 1px solid #e9e7ef; background: #fff; border-radius: 6px; padding: 0 30px 0 11px; color: #777486; font-size: 11px; outline: none;">
            <option value="">Semua kategori</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ categoryLabel(c) }}</option>
          </select>
          <ChevronDown :size="14" class="select-icon" />
        </div>
      </div>
      
      <div class="table-wrap" style="overflow-x: auto; padding-bottom: 120px; margin-bottom: -120px;">
        <table class="products-table" style="width: 100%; min-width: 900px; border-collapse: collapse; font-size: 10px;">
          <thead>
            <tr>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Produk</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Kategori</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Satuan / Stok Min.</th>
              <th style="padding: 10px 19px; text-align: right; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">HPP / Harga Jual</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Stok Total</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Status</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="errorMessage"><td colspan="5" style="padding: 24px 19px; color: #d47770;">{{ errorMessage }}</td></tr>
            <tr v-else-if="!loading && !products.length"><td colspan="5" style="padding: 24px 19px; color: #aaa7b4;">Belum ada produk yang cocok.</td></tr>
            <tr v-for="product in products" :key="product.id">
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6;">
                <div class="product-name" style="display: flex; align-items: center; gap: 10px;">
                  <div class="product-icon" style="display: grid; place-items: center; width: 28px; height: 28px; border-radius: 7px; background: #fff2f2; color: #bd3c3c;">
                    <Package :size="16" />
                  </div>
                  <div>
                    <strong style="display: block; color: #292541; font-size: 11px;">{{ product.name }}</strong>
                    <span style="display: block; color: #aaa7b4; font-size: 9px; margin-top: 4px;">{{ product.sku }}</span>
                  </div>
                </div>
              </td>
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6; color: #555164;">{{ product.category?.name ?? '—' }}</td>
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6; color: #555164;">{{ product.unit?.name ?? '—' }} / min. {{ Number(product.minimum_stock) }}</td>
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6; text-align: right;">
                <div v-if="product.cost_price !== null && product.cost_price !== undefined">
                  <strong style="display: block; font-size: 11px; color: #292541;">{{ Number(product.cost_price).toLocaleString('id-ID', { style: 'currency', currency: product.currency || 'IDR', maximumFractionDigits: 0 }) }}</strong>
                  <span v-if="product.selling_price" style="display: block; font-size: 9px; color: #3ca579; margin-top: 3px;">Jual: {{ Number(product.selling_price).toLocaleString('id-ID', { style: 'currency', currency: product.currency || 'IDR', maximumFractionDigits: 0 }) }}</span>
                </div>
                <span v-else style="font-size: 10px; color: #c4c1cd;">Belum diisi</span>
              </td>
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6; color: #292541; font-weight: 600;">{{ stockInfo(product).stock }}</td>
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6;">
                <span :class="['status-pill', stockInfo(product).tone]" style="padding: 4px 7px; border-radius: 4px; font-size: 9px;">
                  {{ stockInfo(product).status }}
                </span>
              </td>
              <td style="padding: 13px 19px; border-bottom: 1px solid #f3f2f6; position: relative;">
                <button class="dots-button" aria-label="Opsi lainnya" style="background: none; border: 0; color: #aaa7b5; cursor: pointer;" @click.stop="toggleDropdown($event, product.id)">
                  <Ellipsis :size="16" />
                </button>
                <Teleport to="body">
                  <div v-if="activeDropdown === product.id" :style="{ top: dropdownY + 'px', left: dropdownX + 'px' }" style="position: fixed; background: white; border: 1px solid #eeedf3; border-radius: 8px; padding: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 9999; min-width: 150px;" @click.stop>
                    <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #48445c; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fafafd'" onmouseout="this.style.background='none'" @click="openDetail(product)">Lihat Detail</button>
                    <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #48445c; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fafafd'" onmouseout="this.style.background='none'" @click="openEdit(product)">Edit Produk</button>
                    <div style="height: 1px; background: #eeedf3; margin: 4px 0;"></div>
                    <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #d47770; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fff2f2'" onmouseout="this.style.background='none'" @click="remove(product)">Hapus</button>
                  </div>
                </Teleport>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <div class="products-pagination" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 19px; color: #aaa7b4; font-size: 10px;">
        <span>Menampilkan {{ from }}–{{ to }} dari {{ total }} produk</span>
        <div style="display: flex; gap: 4px;">
          <button class="pagination-button" :disabled="page <= 1" style="border: 1px solid #e9e7ef; background: #fff; color: #858291; border-radius: 5px; padding: 6px 9px; font-size: 10px; cursor: pointer;" @click="goTo(page - 1)">Sebelumnya</button>
          <button v-for="n in pages" :key="n" class="pagination-button" :style="n === page ? 'border: 1px solid #bd3c3c; background: #bd3c3c; color: #fff; border-radius: 5px; padding: 6px 9px; font-size: 10px; cursor: pointer;' : 'border: 1px solid #e9e7ef; background: #fff; color: #858291; border-radius: 5px; padding: 6px 9px; font-size: 10px; cursor: pointer;'" @click="goTo(n)">{{ n }}</button>
          <button class="pagination-button" :disabled="page >= lastPage" style="border: 1px solid #e9e7ef; background: #fff; color: #858291; border-radius: 5px; padding: 6px 9px; font-size: 10px; cursor: pointer;" @click="goTo(page + 1)">Berikutnya</button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showAddModal" class="modal-backdrop" @click="showAddModal = false">
          <div class="modal-content" @click.stop>
            <div class="modal-header">
              <h2>{{ editingId ? 'Ubah Produk' : 'Tambah Produk Baru' }}</h2>
              <p>Masukkan detail informasi produk ke dalam inventaris.</p>
            </div>
            <form @submit.prevent="submit" class="modern-form" novalidate>
              <div class="form-group">
                <label>Nama Produk <span style="color: #d47770;">*</span></label>
                <input v-model="form.name" type="text" placeholder="Cth: Router Huawei EG8145" :class="{ 'input-error': formErrors.name }" />
                <small v-if="formErrors.name" class="field-error">{{ formErrors.name[0] }}</small>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>SKU <span style="color: #d47770;">*</span></label>
                  <input v-model="form.sku" type="text" placeholder="Cth: RTR-HW-EG8145" :class="{ 'input-error': formErrors.sku }" />
                  <small v-if="formErrors.sku" class="field-error">{{ formErrors.sku[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Kategori</label>
                  <div class="select-wrapper">
                    <select v-model="form.category_id" :class="{ 'input-error': formErrors.category_id }">
                      <option value="">— Tanpa kategori —</option>
                      <option v-for="c in categories" :key="c.id" :value="c.id">{{ categoryLabel(c) }}</option>
                    </select>
                    <ChevronDown :size="14" class="select-icon" />
                  </div>
                  <small v-if="formErrors.category_id" class="field-error">{{ formErrors.category_id[0] }}</small>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Satuan <span style="color: #d47770;">*</span></label>
                  <div class="select-wrapper">
                    <select v-model="form.unit_id" :class="{ 'input-error': formErrors.unit_id }">
                      <option value="" disabled>Pilih satuan</option>
                      <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }} ({{ u.code }})</option>
                    </select>
                    <ChevronDown :size="14" class="select-icon" />
                  </div>
                  <small v-if="formErrors.unit_id" class="field-error">{{ formErrors.unit_id[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Stok Minimum <span style="color: #d47770;">*</span></label>
                  <input v-model.number="form.minimum_stock" type="number" min="0" step="any" placeholder="0" :class="{ 'input-error': formErrors.minimum_stock }" />
                  <small v-if="formErrors.minimum_stock" class="field-error">{{ formErrors.minimum_stock[0] }}</small>
                </div>
              </div>
              <label class="check-row">
                <input v-model="form.has_serial_number" type="checkbox" />
                <span>Produk memakai nomor seri (serial number)</span>
              </label>

              <!-- Harga -->
              <div style="border-top: 1px solid #f0eff3; padding-top: 18px; margin-top: 4px;">
                <p style="font-size: 10px; font-weight: 600; color: #858291; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 14px;">Informasi Harga <span style="font-size: 9px; color: #aaa7b4; font-weight: 400; text-transform: none;">(opsional)</span></p>
                <div class="form-row">
                  <div class="form-group">
                    <label>Harga Pokok (HPP)</label>
                    <div class="input-with-prefix">
                      <span>Rp</span>
                      <input v-model.number="form.cost_price" type="number" min="0" step="any" placeholder="0" :class="{ 'input-error': formErrors.cost_price }" />
                    </div>
                    <small v-if="formErrors.cost_price" class="field-error">{{ formErrors.cost_price[0] }}</small>
                  </div>
                  <div class="form-group">
                    <label>Harga Jual</label>
                    <div class="input-with-prefix">
                      <span>Rp</span>
                      <input v-model.number="form.selling_price" type="number" min="0" step="any" placeholder="0" :class="{ 'input-error': formErrors.selling_price }" />
                    </div>
                    <small v-if="formErrors.selling_price" class="field-error">{{ formErrors.selling_price[0] }}</small>
                  </div>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showAddModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan Produk' }}</button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
      <Transition name="fade">
        <div v-if="deleteModal.show" class="modal-backdrop" @click="deleteModal.show = false">
          <div class="modal-content" style="max-width: 360px; text-align: center; padding: 32px 28px;" @click.stop>
            <div style="width: 52px; height: 52px; border-radius: 50%; background: #fff2f2; color: #d47770; display: grid; place-items: center; margin: 0 auto 18px;">
              <AlertTriangle :size="24" />
            </div>
            <h2 style="font-size: 16px; color: #292541; margin: 0 0 10px;">Hapus Produk?</h2>
            <p style="color: #555164; font-size: 13px; margin: 0 0 24px; line-height: 1.6;">
              Anda yakin ingin menghapus produk <strong style="color: #292541; font-weight: 600;">{{ deleteModal.product?.name }}</strong>? Tindakan ini tidak dapat dibatalkan.
            </p>
            <div style="display: flex; gap: 12px; justify-content: center;">
              <button class="cancel-button" style="flex: 1; font-weight: 500;" @click="deleteModal.show = false">Batal</button>
              <button class="primary-button" style="flex: 1; justify-content: center;" @click="executeDelete">Ya, Hapus</button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Detail Modal -->
      <Transition name="fade">
        <div v-if="showDetailModal" class="modal-backdrop" @click="showDetailModal = false">
          <div class="modal-content" style="max-width: 580px;" @click.stop>
            <div class="modal-header">
              <h2>Detail Produk</h2>
              <p>{{ targetProduct?.sku }}</p>
            </div>
            <div class="modern-form" style="gap: 20px;">
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; background: #fafafd; padding: 18px; border-radius: 8px; border: 1px solid #e9e7ef;">
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Nama Produk</span>
                  <strong style="display: block; font-size: 11px; color: #48445c; margin-top: 6px;">{{ targetProduct?.name || '-' }}</strong>
                </div>
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Kategori</span>
                  <strong style="display: block; font-size: 11px; color: #48445c; margin-top: 6px;">{{ targetProduct?.category?.name || '-' }}</strong>
                </div>
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Total Stok</span>
                  <strong style="display: block; font-size: 11px; color: #48445c; margin-top: 6px;">{{ Number(targetProduct?.stock_total || 0) }} {{ targetProduct?.unit?.code || targetProduct?.unit?.name }}</strong>
                </div>
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Stok Minimum</span>
                  <strong style="display: block; font-size: 11px; color: #48445c; margin-top: 6px;">{{ Number(targetProduct?.minimum_stock || 0) }}</strong>
                </div>
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Harga Pokok (HPP)</span>
                  <strong v-if="targetProduct?.cost_price" style="display: block; font-size: 11px; color: #48445c; margin-top: 6px;">{{ Number(targetProduct.cost_price).toLocaleString('id-ID', { style: 'currency', currency: targetProduct.currency || 'IDR', maximumFractionDigits: 0 }) }}</strong>
                  <span v-else style="display: block; font-size: 11px; color: #c4c1cd; margin-top: 6px;">—</span>
                </div>
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Harga Jual</span>
                  <strong v-if="targetProduct?.selling_price" style="display: block; font-size: 11px; color: #3ca579; margin-top: 6px;">{{ Number(targetProduct.selling_price).toLocaleString('id-ID', { style: 'currency', currency: targetProduct.currency || 'IDR', maximumFractionDigits: 0 }) }}</strong>
                  <span v-else style="display: block; font-size: 11px; color: #c4c1cd; margin-top: 6px;">—</span>
                </div>
                <div>
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Pelacakan Serial</span>
                  <span class="status-pill" :class="targetProduct?.has_serial_number ? 'success' : 'muted'" style="margin-top: 6px; display: inline-block;">
                    {{ targetProduct?.has_serial_number ? 'Ya' : 'Tidak' }}
                  </span>
                </div>
                <div v-if="targetProduct?.description" style="grid-column: span 2; margin-top: 8px;">
                  <span style="display: block; font-size: 9px; color: #aaa7b4; text-transform: uppercase; font-weight: 600;">Deskripsi</span>
                  <p style="margin: 6px 0 0; font-size: 11px; color: #48445c; line-height: 1.5;">{{ targetProduct.description }}</p>
                </div>
              </div>
              
              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showDetailModal = false">Tutup</button>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </section>
</template>

<style scoped>
.products-page { display: block; }
.status-pill.success { color: #3b9d76; background: #eaf8f1; }
.status-pill.warning { color: #c48735; background: #fff4df; }
.status-pill.danger { color: #d47770; background: #ffeaeb; }
.status-pill.neutral { color: #bd3c3c; background: #fff2f2; }
.field-error { display: block; color: #d47770; font-size: 10px; margin-top: 5px; }
.check-row { display: flex; align-items: center; gap: 10px; font-size: 11px; color: #555164; cursor: pointer; }
.check-row input { width: auto !important; }
.products-toolbar .select-wrapper select:focus { border-color: #bd3c3c; }
.pagination-button:disabled { opacity: 0.5; cursor: not-allowed; }
.panel-header { padding-bottom: 17px; display: flex; justify-content: space-between; align-items: flex-start; }

/* Modal Transition */
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.fade-enter-active .modal-content { transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.fade-enter-from .modal-content { transform: translateY(20px) scale(0.95); opacity: 0; }
.fade-leave-to .modal-content { transform: translateY(10px); opacity: 0; }

/* Modal Styles */
.modal-backdrop { position: fixed; inset: 0; background: rgba(41, 37, 65, 0.4); backdrop-filter: blur(4px); display: flex; justify-content: center; align-items: center; z-index: 10000; }
.modal-content { background: #ffffff; border-radius: 14px; width: 100%; max-width: 480px; box-shadow: 0 20px 40px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
.modal-header { padding: 24px 28px 18px; border-bottom: 1px solid #f0eff3; }
.modal-header h2 { font-size: 16px; color: #292541; margin: 0; }
.modal-header p { color: #aaa7b4; font-size: 11px; margin: 6px 0 0; }

/* Modern Form */
.modern-form { padding: 24px 28px; display: flex; flex-direction: column; gap: 18px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group label { display: block; font-size: 10px; font-weight: 600; color: #858291; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
.modern-form input, .modern-form select { width: 100%; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 8px; padding: 10px 14px; font-size: 12px; color: #48445c; outline: none; transition: all 0.2s; box-sizing: border-box; }
.modern-form input:focus, .modern-form select:focus { border-color: #bd3c3c; background: #fff; box-shadow: 0 0 0 3px rgba(189, 60, 60, 0.1); }
.modern-form input.input-error, .modern-form select.input-error { border-color: #d47770; background: #fffafb; }
.modern-form input.input-error:focus, .modern-form select.input-error:focus { box-shadow: 0 0 0 3px rgba(212, 119, 112, 0.15); }
.modern-form input::placeholder { color: #c4c1cd; }

.select-wrapper { position: relative; }
.select-wrapper select { appearance: none; padding-right: 36px; }
.select-icon { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #aaa7b4; pointer-events: none; }

.input-with-prefix { position: relative; display: flex; align-items: center; }
.input-with-prefix span { position: absolute; left: 14px; color: #aaa7b4; font-size: 12px; pointer-events: none; }
.input-with-prefix input { padding-left: 36px; }

.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px; padding-top: 18px; border-top: 1px solid #f0eff3; }
.cancel-button { background: white; border: 1px solid #e9e7ef; color: #777486; padding: 10px 16px; border-radius: 7px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
.cancel-button:hover { background: #fafafd; color: #48445c; }

/* Toast */
.toast-notification { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 8px; font-size: 12px; font-weight: 500; z-index: 10001; box-shadow: 0 10px 30px rgba(0,0,0,0.1); color: #fff; max-width: 300px; display: flex; align-items: center; gap: 10px; }
.toast-notification.success { background: #292541; }
.toast-notification.error { background: #d47770; }
</style>
