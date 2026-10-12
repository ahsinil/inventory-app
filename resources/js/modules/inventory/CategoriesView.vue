<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { Plus, Search, Tags, Ellipsis, ChevronDown, AlertTriangle } from 'lucide-vue-next'

interface Category {
  id: string
  parent_id: string | null
  name: string
  code: string | null
  description: string | null
  products_count?: number
  children?: Category[]
}

const categories = ref<Category[]>([])
const loading = ref(false)
const errorMessage = ref('')
const search = ref('')
let searchTimer: ReturnType<typeof setTimeout> | null = null

const fetchCategories = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await axios.get('/categories', { params: { search: search.value || undefined } })
    categories.value = res.data.data
  } catch (e: any) {
    errorMessage.value = e.response?.data?.message || 'Gagal memuat kategori.'
  } finally {
    loading.value = false
  }
}

const onSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(fetchCategories, 300)
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
const showModal = ref(false)
const editingId = ref<string | null>(null)
const saving = ref(false)
const formErrors = ref<Record<string, string[]>>({})
const form = ref({ name: '', code: '', parent_id: '', description: '' })

const parentOptions = computed(() => categories.value.filter(c => c.id !== editingId.value))

const openCreate = () => {
  editingId.value = null
  form.value = { name: '', code: '', parent_id: '', description: '' }
  formErrors.value = {}
  showModal.value = true
}

const openEdit = (cat: Category) => {
  closeDropdown()
  editingId.value = cat.id
  form.value = {
    name: cat.name,
    code: cat.code ?? '',
    parent_id: cat.parent_id ?? '',
    description: cat.description ?? '',
  }
  formErrors.value = {}
  showModal.value = true
}

const submit = async () => {
  saving.value = true
  formErrors.value = {}
  let hasError = false
  if (!form.value.name.trim()) { formErrors.value.name = ['Nama kategori wajib diisi.']; hasError = true }

  if (hasError) {
    saving.value = false
    return
  }

  const payload = {
    name: form.value.name,
    code: form.value.code || null,
    parent_id: form.value.parent_id || null,
    description: form.value.description || null,
  }
  try {
    if (editingId.value) {
      await axios.put(`/categories/${editingId.value}`, payload)
    } else {
      await axios.post('/categories', payload)
    }
    showModal.value = false
    await fetchCategories()
  } catch (e: any) {
    formErrors.value = e.response?.data?.errors ?? {}
    if (!Object.keys(formErrors.value).length) {
      formErrors.value = { name: [e.response?.data?.message || 'Gagal menyimpan kategori.'] }
    }
  } finally {
    saving.value = false
  }
}

const deleteModal = ref<{ show: boolean; cat: Category | null }>({ show: false, cat: null })

const remove = (cat: Category) => {
  closeDropdown()
  deleteModal.value = { show: true, cat }
}

const executeDelete = async () => {
  const cat = deleteModal.value.cat
  if (!cat) return
  try {
    await axios.delete(`/categories/${cat.id}`)
    deleteModal.value = { show: false, cat: null }
    await fetchCategories()
  } catch (e: any) {
    const msg = e.response?.status === 409
      ? 'Kategori masih memiliki sub-kategori, hapus sub-kategori terlebih dahulu.'
      : (e.response?.data?.message || 'Gagal menghapus kategori.')
    window.alert(msg)
    deleteModal.value.show = false
  }
}

const totalProducts = (cat: Category) =>
  (cat.products_count ?? 0) + (cat.children ?? []).reduce((s, c) => s + (c.products_count ?? 0), 0)

onMounted(() => {
  document.addEventListener('click', closeDropdown)
  fetchCategories()
})

onUnmounted(() => {
  document.removeEventListener('click', closeDropdown)
})
</script>

<template>
  <section class="categories-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">KONFIGURASI / KATEGORI</p>
        <h1>Kategori Produk</h1>
        <p class="muted">Kelola pengelompokan produk untuk memudahkan pencarian.</p>
      </div>
      <button class="primary-button" @click="openCreate"><Plus :size="17" /> Tambah Kategori</button>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="product-search" style="display: flex; align-items: center; gap: 8px; width: 300px; color: #aaa7b5; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 0 10px; height: 34px;">
          <Search :size="16" />
          <input v-model="search" @input="onSearch" placeholder="Cari nama kategori..." style="width: 100%; border: 0; outline: 0; background: transparent; color: #48445c; font-size: 11px;" />
        </div>
      </div>

      <p v-if="errorMessage" style="padding: 0 22px 12px; color: #d47770; font-size: 11px;">{{ errorMessage }}</p>
      <p v-else-if="loading && !categories.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px;">Memuat kategori...</p>
      <p v-else-if="!loading && !categories.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px;">Belum ada kategori. Klik "Tambah Kategori" untuk membuat yang pertama.</p>

      <div class="categories-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 18px; padding: 18px 22px 22px;">
        <div v-for="cat in categories" :key="cat.id" class="category-card" style="background: #fff; border: 1px solid #eeedf3; border-radius: 12px; padding: 18px; position: relative; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">

          <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">
            <div style="display: grid; place-items: center; width: 36px; height: 36px; border-radius: 10px; background: #fff2f2; color: #bd3c3c;">
              <Tags :size="18" />
            </div>
            <div style="position: relative;">
              <button class="dots-button" aria-label="Opsi lainnya" style="background: none; border: 0; color: #aaa7b5; cursor: pointer; padding: 4px;" @click.stop="toggleDropdown($event, cat.id)">
                <Ellipsis :size="16" />
              </button>
              <Teleport to="body">
                <div v-if="activeDropdown === cat.id" :style="{ top: dropdownY + 'px', left: dropdownX + 'px' }" style="position: fixed; background: white; border: 1px solid #eeedf3; border-radius: 8px; padding: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 9999; min-width: 150px;" @click.stop>
                  <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #48445c; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fafafd'" onmouseout="this.style.background='none'" @click="openEdit(cat)">Edit Kategori</button>
                  <div style="height: 1px; background: #eeedf3; margin: 4px 0;"></div>
                  <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #d47770; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fff2f2'" onmouseout="this.style.background='none'" @click="remove(cat)">Hapus</button>
                </div>
              </Teleport>
            </div>
          </div>

          <strong style="display: block; color: #292541; font-size: 13px; font-weight: 650; margin-bottom: 6px;">{{ cat.name }}</strong>
          <span style="display: block; color: #858291; font-size: 10px; line-height: 1.4; margin-bottom: 14px; min-height: 28px;">{{ cat.description || 'Tanpa deskripsi' }}</span>

          <div v-if="cat.children && cat.children.length > 0" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; min-height: 22px;">
            <span v-for="child in cat.children" :key="child.id" style="background: #f4f3f7; color: #555164; font-size: 9px; padding: 4px 8px; border-radius: 4px; font-weight: 500; cursor: pointer;" title="Klik untuk ubah" @click="openEdit(child)">{{ child.name }}</span>
          </div>
          <div v-else style="margin-bottom: 16px; min-height: 22px; display: flex; align-items: center;">
            <span style="color: #c4c1cd; font-size: 9px; font-style: italic;">Tidak ada sub-kategori</span>
          </div>

          <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed #eeedf3; padding-top: 14px;">
            <span style="color: #aaa7b4; font-size: 9px; font-weight: 500; background: #fafafd; padding: 4px 6px; border-radius: 4px; border: 1px solid #f0eff3;">{{ cat.code || 'Tanpa kode' }}</span>
            <span class="status-pill neutral" style="font-weight: 600;">{{ totalProducts(cat) }} Produk</span>
          </div>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showModal" class="modal-backdrop" @click="showModal = false">
          <div class="modal-content" @click.stop>
            <div class="modal-header">
              <h2>{{ editingId ? 'Ubah Kategori' : 'Tambah Kategori Baru' }}</h2>
              <p>Kategori dapat dijadikan sub-kategori dengan memilih kategori induk.</p>
            </div>
            <form @submit.prevent="submit" class="modern-form" novalidate>
              <div class="form-group">
                <label>Nama Kategori <span style="color: #d47770;">*</span></label>
                <input v-model="form.name" type="text" placeholder="Cth: Router" :class="{ 'input-error': formErrors.name }" />
                <small v-if="formErrors.name" class="field-error">{{ formErrors.name[0] }}</small>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Kode</label>
                  <input v-model="form.code" type="text" placeholder="Cth: RTR" :class="{ 'input-error': formErrors.code }" />
                  <small v-if="formErrors.code" class="field-error">{{ formErrors.code[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Kategori Induk</label>
                  <div class="select-wrapper">
                    <select v-model="form.parent_id">
                      <option value="">— Tanpa induk —</option>
                      <option v-for="p in parentOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <ChevronDown :size="14" class="select-icon" />
                  </div>
                  <small v-if="formErrors.parent_id" class="field-error">{{ formErrors.parent_id[0] }}</small>
                </div>
              </div>
              <div class="form-group">
                <label>Deskripsi</label>
                <input v-model="form.description" type="text" placeholder="Deskripsi singkat (opsional)" :class="{ 'input-error': formErrors.description }" />
                <small v-if="formErrors.description" class="field-error">{{ formErrors.description[0] }}</small>
              </div>
              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan Kategori' }}</button>
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
            <h2 style="font-size: 16px; color: #292541; margin: 0 0 10px;">Hapus Kategori?</h2>
            <p style="color: #555164; font-size: 13px; margin: 0 0 24px; line-height: 1.6;">
              Anda yakin ingin menghapus kategori <strong style="color: #292541; font-weight: 600;">{{ deleteModal.cat?.name }}</strong>? Tindakan ini tidak dapat dibatalkan.
            </p>
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
.categories-page { display: block; }
.panel-header { padding: 22px 22px 0; display: flex; justify-content: space-between; align-items: flex-start; }
.status-pill.neutral { color: #bd3c3c; background: #fff2f2; padding: 4px 7px; border-radius: 4px; font-size: 9px; display: inline-block; }
.field-error { display: block; color: #d47770; font-size: 10px; margin-top: 5px; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.fade-enter-active .modal-content { transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.fade-enter-from .modal-content { transform: translateY(20px) scale(0.95); opacity: 0; }
.fade-leave-to .modal-content { transform: translateY(10px); opacity: 0; }

.modal-backdrop { position: fixed; inset: 0; background: rgba(41, 37, 65, 0.4); backdrop-filter: blur(4px); display: flex; justify-content: center; align-items: center; z-index: 10000; }
.modal-content { background: #ffffff; border-radius: 14px; width: 100%; max-width: 480px; box-shadow: 0 20px 40px rgba(0,0,0,0.1), 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
.modal-header { padding: 24px 28px 18px; border-bottom: 1px solid #f0eff3; }
.modal-header h2 { font-size: 16px; color: #292541; margin: 0; }
.modal-header p { color: #aaa7b4; font-size: 11px; margin: 6px 0 0; }

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

.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px; padding-top: 18px; border-top: 1px solid #f0eff3; }
.cancel-button { background: white; border: 1px solid #e9e7ef; color: #777486; padding: 10px 16px; border-radius: 7px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
.cancel-button:hover { background: #fafafd; color: #48445c; }
</style>
