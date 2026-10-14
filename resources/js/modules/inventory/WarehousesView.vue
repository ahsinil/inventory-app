<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { MapPin, Plus, Search, Warehouse as WarehouseIcon, Ellipsis, AlertTriangle, X } from 'lucide-vue-next'
import AppSortableTh from '../../components/ui/AppSortableTh.vue'

defineProps<{ title: string }>()

interface WarehouseItem {
  id: string
  code: string
  name: string
  address: string | null
  is_active: boolean
  locations_count: number
  total_stock: number
}

interface WarehouseLocation {
  id: string
  code: string
  name: string | null
  is_default: boolean
}

const warehouses = ref<WarehouseItem[]>([])
const loading = ref(false)
const search = ref('')
let searchTimer: ReturnType<typeof setTimeout> | null = null

const toastMessage = ref('')
const toastType = ref('error')
const showToast = (msg: string, type: string = 'error') => {
  toastMessage.value = msg
  toastType.value = type
  setTimeout(() => { toastMessage.value = '' }, 3000)
}

const fmt = (n: number) => new Intl.NumberFormat('id-ID').format(n)

const totalCount = computed(() => warehouses.value.length)
const activeCount = computed(() => warehouses.value.filter(w => w.is_active).length)
const totalLocations = computed(() => warehouses.value.reduce((s, w) => s + w.locations_count, 0))
const totalStock = computed(() => warehouses.value.reduce((s, w) => s + w.total_stock, 0))

const sortBy = ref('')
const sortDir = ref<'asc' | 'desc'>('asc')

const handleSort = (field: string, dir: 'asc' | 'desc') => {
  sortBy.value = field
  sortDir.value = dir
}

const sortedWarehouses = computed(() => {
  if (!sortBy.value) return warehouses.value
  const list = [...warehouses.value]
  const field = sortBy.value
  const dir = sortDir.value === 'asc' ? 1 : -1

  return list.sort((a, b) => {
    let valA: any = a[field as keyof WarehouseItem] ?? ''
    let valB: any = b[field as keyof WarehouseItem] ?? ''
    if (typeof valA === 'string') valA = valA.toLowerCase()
    if (typeof valB === 'string') valB = valB.toLowerCase()
    if (valA < valB) return -1 * dir
    if (valA > valB) return 1 * dir
    return 0
  })
})

const fetchWarehouses = async () => {
  loading.value = true
  try {
    const res = await axios.get('/warehouses', { params: { search: search.value || undefined } })
    warehouses.value = res.data.data
  } catch (e: any) {
    showToast(e.response?.data?.message || 'Gagal memuat data gudang', 'error')
  } finally {
    loading.value = false
  }
}

const exportCSV = () => {
  if (!warehouses.value.length) {
    showToast('Tidak ada data untuk diekspor', 'warning')
    return
  }
  
  const headers = ['Kode', 'Nama Gudang', 'Alamat', 'Status', 'Jumlah Lokasi', 'Total Stok']
  const rows = warehouses.value.map(w => [
    `"${w.code || ''}"`,
    `"${w.name || ''}"`,
    `"${w.address || ''}"`,
    `"${w.is_active ? 'Aktif' : 'Nonaktif'}"`,
    `"${w.locations_count || 0}"`,
    `"${w.total_stock || 0}"`
  ])
  
  const csvContent = "data:text/csv;charset=utf-8," + 
    [headers.join(','), ...rows.map(r => r.join(','))].join('\n')
    
  const encodedUri = encodeURI(csvContent)
  const link = document.createElement('a')
  link.setAttribute('href', encodedUri)
  link.setAttribute('download', `Daftar_Gudang_${new Date().toISOString().slice(0,10)}.csv`)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

const onSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(fetchWarehouses, 300)
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
const closeDropdown = () => { activeDropdown.value = null }

// Modal Form & Delete
const showModal = ref(false)
const editingId = ref<string | null>(null)
const saving = ref(false)
const formErrors = ref<Record<string, string[]>>({})
const form = ref({ code: '', name: '', address: '', is_active: true })
const deleteModal = ref<{ show: boolean; warehouse: WarehouseItem | null; error: string }>({ show: false, warehouse: null, error: '' })

const openCreate = () => {
  editingId.value = null
  form.value = { code: '', name: '', address: '', is_active: true }
  formErrors.value = {}
  showModal.value = true
}

const openEdit = (w: WarehouseItem) => {
  closeDropdown()
  editingId.value = w.id
  form.value = { code: w.code, name: w.name, address: w.address ?? '', is_active: !!w.is_active }
  formErrors.value = {}
  showModal.value = true
}

const submit = async () => {
  saving.value = true
  formErrors.value = {}
  let hasError = false
  if (!form.value.name.trim()) { formErrors.value.name = ['Nama gudang wajib diisi.']; hasError = true }
  if (!form.value.code.trim()) { formErrors.value.code = ['Kode gudang wajib diisi.']; hasError = true }
  if (hasError) {
    saving.value = false
    return
  }

  const payload = {
    code: form.value.code,
    name: form.value.name,
    address: form.value.address || null,
    is_active: form.value.is_active,
  }
  try {
    if (editingId.value) {
      await axios.put(`/warehouses/${editingId.value}`, payload)
      showToast('Gudang berhasil diperbarui', 'success')
    } else {
      await axios.post('/warehouses', payload)
      showToast('Gudang berhasil ditambahkan', 'success')
    }
    showModal.value = false
    await fetchWarehouses()
  } catch (e: any) {
    formErrors.value = e.response?.data?.errors ?? {}
    if (!Object.keys(formErrors.value).length) {
      formErrors.value = { name: [e.response?.data?.message || 'Gagal menyimpan gudang.'] }
      showToast('Gagal menyimpan gudang', 'error')
    }
  } finally {
    saving.value = false
  }
}

const remove = (w: WarehouseItem) => {
  closeDropdown()
  deleteModal.value = { show: true, warehouse: w, error: '' }
}

const executeDelete = async () => {
  const w = deleteModal.value.warehouse
  if (!w) return
  try {
    await axios.delete(`/warehouses/${w.id}`)
    deleteModal.value = { show: false, warehouse: null, error: '' }
    showToast('Gudang berhasil dihapus', 'success')
    await fetchWarehouses()
  } catch (e: any) {
    deleteModal.value.error = e.response?.data?.message || e.response?.data?.error?.message || 'Gagal menghapus gudang.'
    showToast(deleteModal.value.error, 'error')
  }
}

// Lokasi Gudang
const locModal = ref<{ show: boolean; warehouse: WarehouseItem | null; locations: WarehouseLocation[]; loading: boolean; form: { code: string; name: string; is_default: boolean }; editingId: string | null; errors: Record<string, string[]> }>({
  show: false, warehouse: null, locations: [], loading: false, form: { code: '', name: '', is_default: false }, editingId: null, errors: {}
})
const locDeleteId = ref<string | null>(null)
const locDeleteError = ref('')

const openLocations = async (w: WarehouseItem) => {
  closeDropdown()
  locModal.value = { show: true, warehouse: w, locations: [], loading: true, form: { code: '', name: '', is_default: false }, editingId: null, errors: {} }
  locDeleteId.value = null
  locDeleteError.value = ''
  await fetchLocations(w.id)
}

const fetchLocations = async (wId: string) => {
  try {
    const res = await axios.get(`/warehouses/${wId}/locations`)
    locModal.value.locations = res.data.data
  } catch (e) {
    //
  } finally {
    locModal.value.loading = false
  }
}

const editLocation = (l: WarehouseLocation) => {
  locModal.value.editingId = l.id
  locModal.value.form = { code: l.code, name: l.name ?? '', is_default: !!l.is_default }
  locModal.value.errors = {}
  locDeleteId.value = null
}

const resetLocForm = () => {
  locModal.value.editingId = null
  locModal.value.form = { code: '', name: '', is_default: false }
  locModal.value.errors = {}
  locDeleteId.value = null
}

const saveLocation = async () => {
  const wId = locModal.value.warehouse?.id
  if (!wId) return
  
  locModal.value.errors = {}
  if (!locModal.value.form.code.trim()) {
    locModal.value.errors = { code: ['Kode lokasi wajib diisi.'] }
    return
  }

  const payload = { ...locModal.value.form }
  try {
    if (locModal.value.editingId) {
      await axios.put(`/warehouses/${wId}/locations/${locModal.value.editingId}`, payload)
    } else {
      await axios.post(`/warehouses/${wId}/locations`, payload)
      showToast('Lokasi berhasil ditambahkan', 'success')
    }
    resetLocForm()
    await fetchLocations(wId)
    await fetchWarehouses()
  } catch (e: any) {
    locModal.value.errors = e.response?.data?.errors ?? { code: [e.response?.data?.message || 'Gagal menyimpan.'] }
    showToast('Gagal menyimpan lokasi', 'error')
  }
}

const promptDeleteLocation = (l: WarehouseLocation) => {
  locDeleteId.value = l.id
  locDeleteError.value = ''
}

const confirmDeleteLocation = async () => {
  const wId = locModal.value.warehouse?.id
  const lId = locDeleteId.value
  if (!wId || !lId) return
  try {
    await axios.delete(`/warehouses/${wId}/locations/${lId}`)
    locDeleteId.value = null
    showToast('Lokasi berhasil dihapus', 'success')
    await fetchLocations(wId)
    await fetchWarehouses()
  } catch (e: any) {
    locDeleteError.value = e.response?.data?.message || 'Gagal menghapus lokasi.'
    showToast(locDeleteError.value, 'error')
  }
}

onMounted(() => {
  document.addEventListener('click', closeDropdown)
  fetchWarehouses()
})
onUnmounted(() => {
  document.removeEventListener('click', closeDropdown)
})
</script>

<template>
  <section class="warehouses-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">INVENTARIS / KONFIGURASI / GUDANG</p>
        <h1>Gudang</h1>
        <p class="muted">Kelola lokasi gudang dan pantau stok di setiap gudang.</p>
      </div>
      <div style="display: flex; gap: 10px;">
        <button class="secondary-button" @click="exportCSV"><MapPin :size="16" /> Ekspor Laporan</button>
        <button class="primary-button" @click="openCreate"><Plus :size="16" /> Tambah gudang</button>
      </div>
    </div>

    <div class="warehouse-summary">
      <div><span>Total gudang</span><strong>{{ fmt(totalCount) }} <small>gudang</small></strong></div>
      <div><span>Gudang aktif</span><strong>{{ fmt(activeCount) }} <small>operasional</small></strong></div>
      <div><span>Total lokasi</span><strong>{{ fmt(totalLocations) }} <small>lokasi</small></strong></div>
      <div><span>Total stok</span><strong>{{ fmt(totalStock) }} <small>unit</small></strong></div>
    </div>

    <div class="panel warehouses-panel">
      <div class="panel-header">
      </div>
      
      <div class="warehouses-toolbar">
        <label class="product-search"><Search :size="15" /><input v-model="search" @input="onSearch" placeholder="Cari nama, kode, atau alamat..." aria-label="Cari gudang" /></label>
      </div>

      <p v-if="loading && !warehouses.length" class="state-text">Memuat data gudang...</p>
      <p v-else-if="!warehouses.length" class="state-text">Belum ada gudang. Klik "Tambah gudang" untuk membuat yang pertama.</p>

      <div v-if="warehouses.length" class="table-scroll">
        <table class="warehouses-table">
          <thead>
            <tr>
              <AppSortableTh field="name" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Gudang</AppSortableTh>
              <AppSortableTh field="address" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Alamat</AppSortableTh>
              <AppSortableTh field="locations_count" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Lokasi</AppSortableTh>
              <AppSortableTh field="total_stock" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Stok</AppSortableTh>
              <AppSortableTh field="is_active" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Status</AppSortableTh>
              <th style="padding: 10px 19px; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3; width: 40px;"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in sortedWarehouses" :key="item.id">
              <td>
                <div class="warehouse-name">
                  <div class="warehouse-icon"><WarehouseIcon :size="15" /></div>
                  <div><strong>{{ item.name }}</strong><span>{{ item.code }}</span></div>
                </div>
              </td>
              <td><span class="location-cell"><MapPin :size="13" />{{ item.address || '-' }}</span></td>
              <td>{{ fmt(item.locations_count) }}</td>
              <td><strong>{{ fmt(item.total_stock) }}</strong> <span class="muted-cell">unit</span></td>
              <td><span class="status-pill" :class="item.is_active ? 'success' : 'inactive'">{{ item.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
              <td>
                <button class="dots-button" aria-label="Opsi lainnya" @click.stop="toggleDropdown($event, item.id)"><Ellipsis :size="16" /></button>
                <Teleport to="body">
                  <div v-if="activeDropdown === item.id" class="action-menu" :style="{ top: dropdownY + 'px', left: dropdownX + 'px' }" @click.stop>
                    <button class="menu-item" @click="openLocations(item)">Kelola Lokasi</button>
                    <button class="menu-item" @click="openEdit(item)">Edit Gudang</button>
                    <div class="menu-sep"></div>
                    <button class="menu-item danger" @click="remove(item)">Hapus</button>
                  </div>
                </Teleport>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="warehouses-footer"><span>Menampilkan {{ fmt(totalCount) }} gudang</span></div>
    </div>

    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showModal" class="modal-backdrop" @click="showModal = false">
          <div class="modal-content" @click.stop>
            <div class="modal-header">
              <h2>{{ editingId ? 'Ubah Gudang' : 'Tambah Gudang Baru' }}</h2>
              <p>Kelola informasi dasar gudang.</p>
              <button type="button" class="close-icon-btn" aria-label="Tutup" @click="showModal = false"><X :size="18" /></button>
            </div>
            <form @submit.prevent="submit" class="modern-form" novalidate>
              <div class="form-row">
                <div class="form-group">
                  <label>Nama Gudang <span class="req">*</span></label>
                  <input v-model="form.name" type="text" placeholder="Cth: Gudang Pusat" :class="{ 'input-error': formErrors.name }" />
                  <small v-if="formErrors.name" class="field-error">{{ formErrors.name[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Kode <span class="req">*</span></label>
                  <input v-model="form.code" type="text" placeholder="Cth: WH-01" :class="{ 'input-error': formErrors.code }" />
                  <small v-if="formErrors.code" class="field-error">{{ formErrors.code[0] }}</small>
                </div>
              </div>
              <div class="form-group">
                <label>Alamat</label>
                <input v-model="form.address" type="text" placeholder="Alamat gudang (opsional)" :class="{ 'input-error': formErrors.address }" />
                <small v-if="formErrors.address" class="field-error">{{ formErrors.address[0] }}</small>
              </div>
              <label class="check-row"><input v-model="form.is_active" type="checkbox" /> Gudang aktif</label>
              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan Gudang' }}</button>
              </div>
            </form>
          </div>
        </div>
      </Transition>

      <Transition name="fade">
        <div v-if="deleteModal.show" class="modal-backdrop" @click="deleteModal.show = false">
          <div class="modal-content" style="max-width: 360px; text-align: center; padding: 32px 28px;" @click.stop>
            <div class="alert-circle"><AlertTriangle :size="24" /></div>
            <h2 style="font-size: 16px; color: #292541; margin: 0 0 10px;">Hapus Gudang?</h2>
            <p style="color: #555164; font-size: 13px; margin: 0 0 24px; line-height: 1.6;">
              Anda yakin ingin menghapus gudang <strong style="color: #292541; font-weight: 600;">{{ deleteModal.warehouse?.name }}</strong>? Tindakan ini tidak dapat dibatalkan.
            </p>
            <p v-if="deleteModal.error" class="delete-error">{{ deleteModal.error }}</p>
            <div style="display: flex; gap: 12px; justify-content: center;">
              <button class="cancel-button" style="flex: 1; font-weight: 500;" @click="deleteModal.show = false">Batal</button>
              <button class="primary-button" style="flex: 1; justify-content: center;" @click="executeDelete">Ya, Hapus</button>
            </div>
          </div>
        </div>
      </Transition>

      <Transition name="fade">
        <div v-if="locModal.show" class="modal-backdrop" @click="locModal.show = false">
          <div class="modal-content loc-modal" style="max-width: 600px;" @click.stop>
            <div class="modal-header">
              <h2>Lokasi di {{ locModal.warehouse?.name }}</h2>
              <p>Kelola rak, bin, atau area spesifik dalam gudang ini.</p>
              <button type="button" class="close-icon-btn" aria-label="Tutup" @click="locModal.show = false"><X :size="18" /></button>
            </div>
            <div class="loc-body" style="display: flex; flex-direction: column; min-height: 200px; max-height: 500px;">
              <div class="loc-list" style="flex: 1; overflow-y: auto; padding: 0 28px; background: #fafafd;">
                <p v-if="locModal.loading" style="padding: 20px 0; color: #aaa7b4; font-size: 11px;">Memuat lokasi...</p>
                <div v-else-if="locModal.locations.length === 0" style="padding: 40px 0; text-align: center; color: #aaa7b4; font-size: 11px;">
                  Gudang ini belum memiliki lokasi tercatat.
                </div>
                <div v-else style="display: flex; flex-direction: column; gap: 8px; padding: 20px 0;">
                  <div v-for="l in locModal.locations" :key="l.id" class="loc-item" :class="{ 'loc-editing': locModal.editingId === l.id }" style="display: flex; justify-content: space-between; align-items: center; background: #fff; border: 1px solid #e9e7ef; padding: 12px 16px; border-radius: 8px; transition: all 0.2s;">
                    <div>
                      <strong style="display: block; font-size: 12px; color: #292541;">{{ l.code }} <span v-if="l.is_default" class="status-pill success" style="margin-left: 6px;">Utama</span></strong>
                      <span style="font-size: 11px; color: #858291;">{{ l.name || 'Tidak ada deskripsi' }}</span>
                      <p v-if="locDeleteId === l.id && locDeleteError" class="delete-error" style="margin-top: 8px; margin-bottom: 0;">{{ locDeleteError }}</p>
                    </div>
                    <div style="display: flex; gap: 6px;">
                      <template v-if="locDeleteId === l.id">
                        <button class="cancel-button" style="padding: 6px 10px;" @click="locDeleteId = null">Batal</button>
                        <button class="primary-button danger-btn" style="padding: 6px 10px; background: #d47770; color: #fff; border: 0;" @click="confirmDeleteLocation">Konfirmasi Hapus</button>
                      </template>
                      <template v-else>
                        <button class="cancel-button" style="padding: 6px 10px;" @click="editLocation(l)">Edit</button>
                        <button class="cancel-button" style="padding: 6px 10px; color: #d47770;" @click="promptDeleteLocation(l)">Hapus</button>
                      </template>
                    </div>
                  </div>
                </div>
              </div>
              <div class="loc-form" style="padding: 20px 28px; background: #fff; border-top: 1px solid #f0eff3;">
                <h3 style="font-size: 12px; color: #292541; margin: 0 0 12px;">{{ locModal.editingId ? 'Edit Lokasi' : 'Tambah Lokasi Baru' }}</h3>
                <div style="display: flex; gap: 12px; align-items: flex-start;">
                  <div style="flex: 1;">
                    <input v-model="locModal.form.code" type="text" placeholder="Kode (Cth: RAK-01)" :class="{ 'input-error': locModal.errors.code }" style="width: 100%; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 9px 12px; font-size: 11px; outline: none;" />
                    <small v-if="locModal.errors.code" class="field-error">{{ locModal.errors.code[0] }}</small>
                  </div>
                  <div style="flex: 1.5;">
                    <input v-model="locModal.form.name" type="text" placeholder="Nama/Deskripsi (opsional)" style="width: 100%; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 9px 12px; font-size: 11px; outline: none;" />
                  </div>
                  <label class="check-row" style="margin-top: 8px;"><input v-model="locModal.form.is_default" type="checkbox" /> Utama</label>
                  <button class="primary-button" style="padding: 9px 16px;" @click="saveLocation">{{ locModal.editingId ? 'Simpan' : 'Tambah' }}</button>
                  <button v-if="locModal.editingId" class="cancel-button" style="padding: 9px 12px;" @click="resetLocForm">Batal</button>
                </div>
              </div>
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
.warehouse-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}.warehouse-summary>div{background:#fff;border:1px solid #eeedf3;border-radius:10px;padding:15px 18px}.warehouse-summary span,.warehouse-summary strong{display:block}.warehouse-summary span{color:#858291;font-size:11px}.warehouse-summary strong{font-size:22px;margin-top:9px;letter-spacing:-.04em}.warehouse-summary small{font-size:10px;color:#aaa7b4;font-weight:500}
.warehouses-panel{overflow:hidden}.warehouses-toolbar{display:flex;gap:10px;padding:0 19px 17px}.warehouses-toolbar .product-search{max-width:300px}
.warehouses-table{min-width:900px}.warehouses-table th,.warehouses-table td{padding-left:19px;padding-right:19px}.warehouses-table td{color:#292541}
.warehouse-name{display:flex;align-items:center;gap:10px}.warehouse-name strong,.warehouse-name span{display:block}.warehouse-name strong{font-size:11px}.warehouse-name span{color:#aaa7b4;font-size:9px;margin-top:4px}
.warehouse-icon{width:29px;height:29px;border-radius:7px;display:grid;place-items:center;color:#bd3c3c;background:#fff2f2}
.location-cell{display:flex;align-items:center;gap:6px;color:#777486}
.status-pill{padding:4px 7px;border-radius:4px;font-size:9px;white-space:nowrap}.status-pill.success{color:#3b9d76;background:#eaf8f1}.status-pill.inactive{color:#858291;background:#f0eff3}
.warehouses-footer{display:flex;justify-content:space-between;align-items:center;padding:14px 19px;color:#aaa7b4;font-size:10px}
.dots-button{background:none;border:0;color:#aaa7b5;cursor:pointer;padding:4px}
.state-text{padding:24px 22px;color:#aaa7b4;font-size:11px}.state-text.error{color:#d47770;padding:0 22px 12px}
.action-menu{position:fixed;background:#fff;border:1px solid #eeedf3;border-radius:8px;padding:6px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;min-width:150px}
.menu-item{display:block;width:100%;text-align:left;background:none;border:0;padding:9px 12px;font-size:11px;color:#48445c;border-radius:5px;cursor:pointer}.menu-item:hover{background:#fafafd}.menu-item.danger{color:#d47770}.menu-item.danger:hover{background:#fff2f2}
.menu-sep{height:1px;background:#eeedf3;margin:4px 0}
.req{color:#d47770}
.field-error{display:block;color:#d47770;font-size:10px;margin-top:5px}
.alert-circle{width:52px;height:52px;border-radius:50%;background:#fff2f2;color:#d47770;display:grid;place-items:center;margin:0 auto 18px}
.delete-error{color:#d47770;background:#fff2f2;border-radius:6px;padding:9px 12px;font-size:11px;line-height:1.5;margin:0 0 18px}
.check-row{display:flex;align-items:center;gap:8px;font-size:12px;color:#48445c;cursor:pointer}.check-row input{width:auto!important;margin:0}

.fade-enter-active,.fade-leave-active{transition:opacity .25s ease}.fade-enter-from,.fade-leave-to{opacity:0}
.fade-enter-active .modal-content{transition:all .3s cubic-bezier(.34,1.56,.64,1)}.fade-enter-from .modal-content{transform:translateY(20px) scale(.95);opacity:0}.fade-leave-to .modal-content{transform:translateY(10px);opacity:0}
.modal-backdrop{position:fixed;inset:0;background:rgba(41,37,65,.4);backdrop-filter:blur(4px);display:flex;justify-content:center;align-items:center;z-index:10000}
.modal-content{background:#fff;border-radius:14px;width:100%;max-width:440px;box-shadow:0 20px 40px rgba(0,0,0,.1),0 1px 3px rgba(0,0,0,.05);overflow:hidden}
.modal-header{position:relative;padding:24px 28px 18px;border-bottom:1px solid #f0eff3}.modal-header h2{font-size:16px;color:#292541;margin:0}.modal-header p{color:#aaa7b4;font-size:11px;margin:6px 0 0}
.close-icon-btn{position:absolute;top:20px;right:20px;background:none;border:0;color:#aaa7b4;cursor:pointer;padding:4px;border-radius:6px;transition:all .2s;display:grid;place-items:center}.close-icon-btn:hover{background:#fafafd;color:#48445c}
.modern-form{padding:24px 28px;display:flex;flex-direction:column;gap:18px}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group label{display:block;font-size:10px;font-weight:600;color:#858291;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.modern-form input[type=text]{width:100%;background:#fafafd;border:1px solid #e9e7ef;border-radius:8px;padding:10px 14px;font-size:12px;color:#48445c;outline:none;transition:all .2s;box-sizing:border-box}
.modern-form input[type=text]:focus{border-color:#bd3c3c;background:#fff;box-shadow:0 0 0 3px rgba(189,60,60,.1)}
.modern-form input.input-error{border-color:#d47770;background:#fffafb}.modern-form input.input-error:focus{box-shadow:0 0 0 3px rgba(212,119,112,.15)}
.modern-form input::placeholder{color:#c4c1cd}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:10px;padding-top:18px;border-top:1px solid #f0eff3}
.cancel-button{background:#fff;border:1px solid #e9e7ef;color:#777486;padding:10px 16px;border-radius:7px;font-size:11px;cursor:pointer;transition:all .2s}.cancel-button:hover{background:#fafafd;color:#48445c}

/* Toast */
.toast-notification { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 8px; font-size: 12px; font-weight: 500; z-index: 10001; box-shadow: 0 10px 30px rgba(0,0,0,0.1); color: #fff; max-width: 300px; display: flex; align-items: center; gap: 10px; }
.toast-notification.success { background: #292541; }
.toast-notification.error { background: #d47770; }
.toast-notification.warning { background: #c48735; }
</style>

<style>
.warehouses-page .product-search{display:flex;align-items:center;gap:8px;flex:1;color:#aaa7b5;background:#fafafd;border:1px solid #e9e7ef;border-radius:6px;padding:0 10px;height:34px}.warehouses-page .product-search input{width:100%;border:0;outline:0;background:transparent;color:#48445c;font-size:11px}
@media (max-width:700px){.warehouse-summary{grid-template-columns:repeat(2,1fr)}.warehouse-summary>div{padding:14px}.warehouse-summary strong{font-size:19px}.warehouses-toolbar{padding:0 14px 14px}.warehouses-toolbar .product-search{max-width:none}.warehouses-footer{padding:14px}}
</style>
