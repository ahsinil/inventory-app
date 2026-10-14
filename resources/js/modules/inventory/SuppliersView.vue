<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { Plus, Search, Ellipsis, AlertTriangle, Truck, MapPin, Mail, Phone } from 'lucide-vue-next'
import AppPagination from '../../components/ui/AppPagination.vue'
import AppSortableTh from '../../components/ui/AppSortableTh.vue'

interface Supplier {
  id: string
  code: string | null
  name: string
  email: string | null
  phone: string | null
  address: string | null
  is_active: boolean
}

const suppliers = ref<Supplier[]>([])
const loading = ref(false)
const errorMessage = ref('')
const search = ref('')
const sortBy = ref('')
const sortDir = ref<'asc' | 'desc'>('asc')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const from = ref(0)
const to = ref(0)
let searchTimer: ReturnType<typeof setTimeout> | null = null

const fetchSuppliers = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await axios.get('/suppliers', { 
      params: { 
        search: search.value || undefined, 
        page: page.value,
        sort_by: sortBy.value || undefined,
        sort_dir: sortBy.value ? sortDir.value : undefined
      } 
    })
    const data = res.data.data
    suppliers.value = data.data
    lastPage.value = data.last_page
    total.value = data.total
    from.value = data.from ?? 0
    to.value = data.to ?? 0
  } catch (e: any) {
    errorMessage.value = e.response?.data?.message || 'Gagal memuat data pemasok.'
  } finally {
    loading.value = false
  }
}

const handleSort = (field: string, dir: 'asc' | 'desc') => {
  sortBy.value = field
  sortDir.value = dir
  page.value = 1
  fetchSuppliers()
}

const onSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchSuppliers() }, 300)
}

const goTo = (p: number) => {
  if (p < 1 || p > lastPage.value) return
  page.value = p
  fetchSuppliers()
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
const form = ref({ code: '', name: '', email: '', phone: '', address: '', is_active: true })
const deleteModal = ref<{ show: boolean; supplier: Supplier | null; error: string }>({ show: false, supplier: null, error: '' })

const openCreate = () => {
  editingId.value = null
  form.value = { code: '', name: '', email: '', phone: '', address: '', is_active: true }
  formErrors.value = {}
  showModal.value = true
}

const openEdit = (s: Supplier) => {
  closeDropdown()
  editingId.value = s.id
  form.value = { code: s.code ?? '', name: s.name, email: s.email ?? '', phone: s.phone ?? '', address: s.address ?? '', is_active: !!s.is_active }
  formErrors.value = {}
  showModal.value = true
}

const submit = async () => {
  saving.value = true
  formErrors.value = {}
  let hasError = false
  if (!form.value.name.trim()) { formErrors.value.name = ['Nama pemasok wajib diisi.']; hasError = true }

  if (hasError) {
    saving.value = false
    return
  }

  const payload = {
    code: form.value.code || null,
    name: form.value.name,
    email: form.value.email || null,
    phone: form.value.phone || null,
    address: form.value.address || null,
    is_active: form.value.is_active,
  }
  
  try {
    if (editingId.value) {
      await axios.put(`/suppliers/${editingId.value}`, payload)
    } else {
      await axios.post('/suppliers', payload)
    }
    showModal.value = false
    await fetchSuppliers()
  } catch (e: any) {
    formErrors.value = e.response?.data?.errors ?? {}
    if (!Object.keys(formErrors.value).length) {
      formErrors.value = { name: [e.response?.data?.message || 'Gagal menyimpan pemasok.'] }
    }
  } finally {
    saving.value = false
  }
}

const remove = (s: Supplier) => {
  closeDropdown()
  deleteModal.value = { show: true, supplier: s, error: '' }
}

const executeDelete = async () => {
  const s = deleteModal.value.supplier
  if (!s) return
  try {
    await axios.delete(`/suppliers/${s.id}`)
    deleteModal.value = { show: false, supplier: null, error: '' }
    if (suppliers.value.length === 1 && page.value > 1) page.value--
    await fetchSuppliers()
  } catch (e: any) {
    deleteModal.value.error = e.response?.data?.message || e.response?.data?.error?.message || 'Gagal menghapus pemasok.'
  }
}

onMounted(() => {
  document.addEventListener('click', closeDropdown)
  fetchSuppliers()
})
onUnmounted(() => {
  document.removeEventListener('click', closeDropdown)
})
</script>

<template>
  <section class="suppliers-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">KONFIGURASI / PEMASOK</p>
        <h1>Pemasok</h1>
        <p class="muted">Kelola data vendor dan penyedia barang inventaris.</p>
      </div>
      <button class="primary-button" @click="openCreate"><Plus :size="16" /> Tambah pemasok</button>
    </div>

    <div class="panel">
      <div class="panel-header" style="padding-bottom: 17px; display: flex; justify-content: space-between; align-items: flex-start;">
        <div class="product-search" style="display: flex; align-items: center; gap: 8px; width: 300px; color: #aaa7b5; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 0 10px; height: 34px;">
          <Search :size="16" />
          <input v-model="search" @input="onSearch" placeholder="Cari nama, email, atau telepon..." style="width: 100%; border: 0; outline: 0; background: transparent; color: #48445c; font-size: 11px;" />
        </div>
      </div>

      <p v-if="errorMessage" style="padding: 0 22px 12px; color: #d47770; font-size: 11px;">{{ errorMessage }}</p>
      <p v-else-if="loading && !suppliers.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px;">Memuat data pemasok...</p>
      <p v-else-if="!loading && !suppliers.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px;">Belum ada pemasok. Klik "Tambah pemasok" untuk membuat yang pertama.</p>

      <div v-if="suppliers.length" class="table-scroll" style="overflow-x: auto;">
        <table class="modern-table" style="width: 100%; min-width: 900px; border-collapse: collapse; font-size: 11px;">
          <thead>
            <tr>
              <AppSortableTh field="name" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Pemasok</AppSortableTh>
              <AppSortableTh field="email" :current-sort="sortBy" :current-dir="sortDir" @sort="handleSort">Kontak</AppSortableTh>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Alamat</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;">Status</th>
              <th style="padding: 10px 19px; text-align: left; color: #aaa7b4; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #fafafd; border-top: 1px solid #f0eff3; border-bottom: 1px solid #f0eff3;"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in suppliers" :key="item.id" style="border-bottom: 1px solid #f0eff3;">
              <td style="padding: 12px 19px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                  <div style="width: 32px; height: 32px; border-radius: 8px; background: #f4f0ff; color: #6a5ce8; display: grid; place-items: center;"><Truck :size="16" /></div>
                  <div>
                    <strong style="display: block; font-size: 12px; color: #292541; margin-bottom: 2px;">{{ item.name }}</strong>
                    <span style="font-size: 10px; color: #aaa7b4;">{{ item.code || 'Tanpa Kode' }}</span>
                  </div>
                </div>
              </td>
              <td style="padding: 12px 19px; color: #555164;">
                <div style="display: flex; flex-direction: column; gap: 4px;">
                  <span v-if="item.email" style="display: flex; align-items: center; gap: 6px;"><Mail :size="12" style="color: #aaa7b4" /> {{ item.email }}</span>
                  <span v-if="item.phone" style="display: flex; align-items: center; gap: 6px;"><Phone :size="12" style="color: #aaa7b4" /> {{ item.phone }}</span>
                  <span v-if="!item.email && !item.phone" style="color: #aaa7b4; font-style: italic;">Tidak ada kontak</span>
                </div>
              </td>
              <td style="padding: 12px 19px; color: #555164;">
                <span v-if="item.address" style="display: flex; align-items: flex-start; gap: 6px;"><MapPin :size="12" style="color: #aaa7b4; flex-shrink: 0; margin-top: 2px;" /> {{ item.address }}</span>
                <span v-else style="color: #aaa7b4;">-</span>
              </td>
              <td style="padding: 12px 19px;">
                <span :class="item.is_active ? 'success' : 'inactive'" style="padding: 4px 8px; border-radius: 4px; font-size: 9px; font-weight: 600;">
                  {{ item.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
              </td>
              <td style="padding: 12px 19px;">
                <button style="background: none; border: 0; color: #aaa7b5; cursor: pointer; padding: 4px;" @click.stop="toggleDropdown($event, item.id)"><Ellipsis :size="16" /></button>
                <Teleport to="body">
                  <div v-if="activeDropdown === item.id" style="position: fixed; background: #fff; border: 1px solid #eeedf3; border-radius: 8px; padding: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 9999; min-width: 150px;" :style="{ top: dropdownY + 'px', left: dropdownX + 'px' }" @click.stop>
                    <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #48445c; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fafafd'" onmouseout="this.style.background='none'" @click="openEdit(item)">Edit Pemasok</button>
                    <div style="height: 1px; background: #eeedf3; margin: 4px 0;"></div>
                    <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #d47770; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fff2f2'" onmouseout="this.style.background='none'" @click="remove(item)">Hapus</button>
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
        :from="from"
        :to="to"
        @change="goTo"
      >
        <template #info>
          <span>Menampilkan {{ from }}–{{ to }} dari {{ total }} pemasok</span>
        </template>
      </AppPagination>
    </div>

    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showModal" class="modal-backdrop" @click="showModal = false">
          <div class="modal-content" @click.stop>
            <div class="modal-header">
              <h2>{{ editingId ? 'Ubah Data Pemasok' : 'Tambah Pemasok Baru' }}</h2>
              <p>Kelola data dan kontak penyedia barang.</p>
            </div>
            <form @submit.prevent="submit" class="modern-form" novalidate>
              <div class="form-row">
                <div class="form-group">
                  <label>Nama Pemasok <span class="req">*</span></label>
                  <input v-model="form.name" type="text" placeholder="Cth: PT Logistik Sukses" :class="{ 'input-error': formErrors.name }" />
                  <small v-if="formErrors.name" class="field-error">{{ formErrors.name[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Kode</label>
                  <input v-model="form.code" type="text" placeholder="Cth: SUP-01" :class="{ 'input-error': formErrors.code }" />
                  <small v-if="formErrors.code" class="field-error">{{ formErrors.code[0] }}</small>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Email</label>
                  <input v-model="form.email" type="email" placeholder="email@vendor.com" :class="{ 'input-error': formErrors.email }" />
                  <small v-if="formErrors.email" class="field-error">{{ formErrors.email[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Telepon</label>
                  <input v-model="form.phone" type="text" placeholder="+62 8xx" :class="{ 'input-error': formErrors.phone }" />
                  <small v-if="formErrors.phone" class="field-error">{{ formErrors.phone[0] }}</small>
                </div>
              </div>
              <div class="form-group">
                <label>Alamat Lengkap</label>
                <input v-model="form.address" type="text" placeholder="Jalan, Kota..." :class="{ 'input-error': formErrors.address }" />
                <small v-if="formErrors.address" class="field-error">{{ formErrors.address[0] }}</small>
              </div>
              <label class="check-row"><input v-model="form.is_active" type="checkbox" /> Pemasok aktif</label>
              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan Pemasok' }}</button>
              </div>
            </form>
          </div>
        </div>
      </Transition>

      <Transition name="fade">
        <div v-if="deleteModal.show" class="modal-backdrop" @click="deleteModal.show = false">
          <div class="modal-content" style="max-width: 360px; text-align: center; padding: 32px 28px;" @click.stop>
            <div class="alert-circle"><AlertTriangle :size="24" /></div>
            <h2 style="font-size: 16px; color: #292541; margin: 0 0 10px;">Hapus Pemasok?</h2>
            <p style="color: #555164; font-size: 13px; margin: 0 0 24px; line-height: 1.6;">
              Anda yakin ingin menghapus pemasok <strong style="color: #292541; font-weight: 600;">{{ deleteModal.supplier?.name }}</strong>? Tindakan ini tidak dapat dibatalkan.
            </p>
            <p v-if="deleteModal.error" class="delete-error">{{ deleteModal.error }}</p>
            <div style="display: flex; gap: 12px; justify-content: center;">
              <button class="cancel-button" style="flex: 1; font-weight: 500;" @click="deleteModal.show = false">Batal</button>
              <button class="primary-button" style="flex: 1; justify-content: center;" @click="executeDelete">Ya, Hapus</button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </section>
</template>

<style scoped>
.suppliers-page { display: block; }
.panel { overflow: hidden; }
span.success { color: #3b9d76; background: #eaf8f1; }
span.inactive { color: #858291; background: #f0eff3; }
.req { color: #d47770; }
.field-error { display: block; color: #d47770; font-size: 10px; margin-top: 5px; }
.alert-circle { width: 52px; height: 52px; border-radius: 50%; background: #fff2f2; color: #d47770; display: grid; place-items: center; margin: 0 auto 18px; }
.delete-error { color: #d47770; background: #fff2f2; border-radius: 6px; padding: 9px 12px; font-size: 11px; line-height: 1.5; margin: 0 0 18px; }
.check-row { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #48445c; cursor: pointer; }
.check-row input { width: auto !important; margin: 0; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.fade-enter-active .modal-content { transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.fade-enter-from .modal-content { transform: translateY(20px) scale(0.95); opacity: 0; }
.fade-leave-to .modal-content { transform: translateY(10px); opacity: 0; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(41, 37, 65, 0.4); backdrop-filter: blur(4px); display: flex; justify-content: center; align-items: center; z-index: 10000; }
.modal-content { background: #ffffff; border-radius: 14px; width: 100%; max-width: 440px; box-shadow: 0 20px 40px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
.modal-header { padding: 24px 28px 18px; border-bottom: 1px solid #f0eff3; }
.modal-header h2 { font-size: 16px; color: #292541; margin: 0; }
.modal-header p { color: #aaa7b4; font-size: 11px; margin: 6px 0 0; }
.modern-form { padding: 24px 28px; display: flex; flex-direction: column; gap: 18px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group label { display: block; font-size: 10px; font-weight: 600; color: #858291; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
.modern-form input[type="text"], .modern-form input[type="email"] { width: 100%; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 8px; padding: 10px 14px; font-size: 12px; color: #48445c; outline: none; transition: all 0.2s; box-sizing: border-box; }
.modern-form input:focus { border-color: #bd3c3c; background: #fff; box-shadow: 0 0 0 3px rgba(189, 60, 60, 0.1); }
.modern-form input.input-error { border-color: #d47770; background: #fffafb; }
.modern-form input.input-error:focus { box-shadow: 0 0 0 3px rgba(212, 119, 112, 0.15); }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px; padding-top: 18px; border-top: 1px solid #f0eff3; }
.cancel-button { background: white; border: 1px solid #e9e7ef; color: #777486; padding: 10px 16px; border-radius: 7px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
.cancel-button:hover { background: #fafafd; color: #48445c; }
</style>
