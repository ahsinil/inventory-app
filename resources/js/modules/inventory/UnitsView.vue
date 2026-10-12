<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { Plus, Search, Ellipsis, Scale, AlertTriangle } from 'lucide-vue-next'

interface Unit {
  id: string
  name: string
  code: string
  description: string | null
}

const units = ref<Unit[]>([])
const loading = ref(false)
const errorMessage = ref('')
const search = ref('')
let searchTimer: ReturnType<typeof setTimeout> | null = null

const fetchUnits = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await axios.get('/units', { params: { search: search.value || undefined } })
    units.value = res.data.data
  } catch (e: any) {
    errorMessage.value = e.response?.data?.message || 'Gagal memuat satuan barang.'
  } finally {
    loading.value = false
  }
}

const onSearch = () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(fetchUnits, 300)
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

// Modal Form & Delete
const showModal = ref(false)
const editingId = ref<string | null>(null)
const saving = ref(false)
const formErrors = ref<Record<string, string[]>>({})
const form = ref({ name: '', code: '', description: '' })
const deleteModal = ref<{ show: boolean; unit: Unit | null }>({ show: false, unit: null })

const openCreate = () => {
  editingId.value = null
  form.value = { name: '', code: '', description: '' }
  formErrors.value = {}
  showModal.value = true
}

const openEdit = (u: Unit) => {
  closeDropdown()
  editingId.value = u.id
  form.value = {
    name: u.name,
    code: u.code,
    description: u.description ?? '',
  }
  formErrors.value = {}
  showModal.value = true
}

const submit = async () => {
  saving.value = true
  formErrors.value = {}
  let hasError = false
  if (!form.value.name.trim()) { formErrors.value.name = ['Nama satuan wajib diisi.']; hasError = true }
  if (!form.value.code.trim()) { formErrors.value.code = ['Kode satuan wajib diisi.']; hasError = true }

  if (hasError) {
    saving.value = false
    return
  }

  const payload = {
    name: form.value.name,
    code: form.value.code,
    description: form.value.description || null,
  }
  try {
    if (editingId.value) {
      await axios.put(`/units/${editingId.value}`, payload)
    } else {
      await axios.post('/units', payload)
    }
    showModal.value = false
    await fetchUnits()
  } catch (e: any) {
    formErrors.value = e.response?.data?.errors ?? {}
    if (!Object.keys(formErrors.value).length) {
      formErrors.value = { name: [e.response?.data?.message || 'Gagal menyimpan satuan barang.'] }
    }
  } finally {
    saving.value = false
  }
}

const remove = (u: Unit) => {
  closeDropdown()
  deleteModal.value = { show: true, unit: u }
}

const executeDelete = async () => {
  const u = deleteModal.value.unit
  if (!u) return
  try {
    await axios.delete(`/units/${u.id}`)
    deleteModal.value = { show: false, unit: null }
    await fetchUnits()
  } catch (e: any) {
    window.alert(e.response?.data?.message || 'Gagal menghapus satuan barang.')
    deleteModal.value.show = false
  }
}

onMounted(() => {
  document.addEventListener('click', closeDropdown)
  fetchUnits()
})

onUnmounted(() => {
  document.removeEventListener('click', closeDropdown)
})
</script>

<template>
  <section class="units-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">KONFIGURASI / SATUAN</p>
        <h1>Satuan Barang</h1>
        <p class="muted">Kelola berbagai satuan ukur atau kemasan untuk produk.</p>
      </div>
      <button class="primary-button" @click="openCreate"><Plus :size="17" /> Tambah Satuan</button>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="product-search" style="display: flex; align-items: center; gap: 8px; width: 300px; color: #aaa7b5; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 6px; padding: 0 10px; height: 34px;">
          <Search :size="16" />
          <input v-model="search" @input="onSearch" placeholder="Cari nama atau kode satuan..." style="width: 100%; border: 0; outline: 0; background: transparent; color: #48445c; font-size: 11px;" />
        </div>
      </div>

      <p v-if="errorMessage" style="padding: 0 22px 12px; color: #d47770; font-size: 11px;">{{ errorMessage }}</p>
      <p v-else-if="loading && !units.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px;">Memuat satuan barang...</p>
      <p v-else-if="!loading && !units.length" style="padding: 24px 22px; color: #aaa7b4; font-size: 11px;">Belum ada satuan barang. Klik "Tambah Satuan" untuk membuat yang pertama.</p>

      <div class="categories-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px; padding: 18px 22px 22px;">
        <div v-for="u in units" :key="u.id" class="category-card" style="background: #fff; border: 1px solid #eeedf3; border-radius: 12px; padding: 18px; position: relative; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">
            <div style="display: grid; place-items: center; width: 36px; height: 36px; border-radius: 10px; background: #f6f5ff; color: #6a5ce8;">
              <Scale :size="18" />
            </div>
            <div style="position: relative;">
              <button class="dots-button" aria-label="Opsi lainnya" style="background: none; border: 0; color: #aaa7b5; cursor: pointer; padding: 4px;" @click.stop="toggleDropdown($event, u.id)">
                <Ellipsis :size="16" />
              </button>
              <Teleport to="body">
                <div v-if="activeDropdown === u.id" :style="{ top: dropdownY + 'px', left: dropdownX + 'px' }" style="position: fixed; background: white; border: 1px solid #eeedf3; border-radius: 8px; padding: 6px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 9999; min-width: 150px;" @click.stop>
                  <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #48445c; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fafafd'" onmouseout="this.style.background='none'" @click="openEdit(u)">Edit Satuan</button>
                  <div style="height: 1px; background: #eeedf3; margin: 4px 0;"></div>
                  <button style="display: block; width: 100%; text-align: left; background: none; border: 0; padding: 9px 12px; font-size: 11px; color: #d47770; border-radius: 5px; cursor: pointer;" onmouseover="this.style.background='#fff2f2'" onmouseout="this.style.background='none'" @click="remove(u)">Hapus</button>
                </div>
              </Teleport>
            </div>
          </div>

          <strong style="display: block; color: #292541; font-size: 14px; font-weight: 650; margin-bottom: 4px;">{{ u.name }}</strong>
          <span style="display: block; color: #6a5ce8; font-size: 10px; font-weight: 600; background: #f6f5ff; padding: 4px 8px; border-radius: 4px; display: inline-block; margin-bottom: 12px;">Kode: {{ u.code }}</span>
          <span style="display: block; color: #858291; font-size: 11px; line-height: 1.5;">{{ u.description || 'Tidak ada deskripsi' }}</span>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showModal" class="modal-backdrop" @click="showModal = false">
          <div class="modal-content" @click.stop>
            <div class="modal-header">
              <h2>{{ editingId ? 'Ubah Satuan Barang' : 'Tambah Satuan Baru' }}</h2>
              <p>Kelola format kemasan atau satuan ukur produk.</p>
            </div>
            <form @submit.prevent="submit" class="modern-form" novalidate>
              <div class="form-row">
                <div class="form-group">
                  <label>Nama Satuan <span style="color: #d47770;">*</span></label>
                  <input v-model="form.name" type="text" placeholder="Cth: Kotak" :class="{ 'input-error': formErrors.name }" />
                  <small v-if="formErrors.name" class="field-error">{{ formErrors.name[0] }}</small>
                </div>
                <div class="form-group">
                  <label>Kode <span style="color: #d47770;">*</span></label>
                  <input v-model="form.code" type="text" placeholder="Cth: BOX" :class="{ 'input-error': formErrors.code }" />
                  <small v-if="formErrors.code" class="field-error">{{ formErrors.code[0] }}</small>
                </div>
              </div>
              <div class="form-group">
                <label>Deskripsi</label>
                <input v-model="form.description" type="text" placeholder="Deskripsi singkat (opsional)" :class="{ 'input-error': formErrors.description }" />
                <small v-if="formErrors.description" class="field-error">{{ formErrors.description[0] }}</small>
              </div>
              <div class="modal-footer">
                <button type="button" class="cancel-button" @click="showModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan Satuan' }}</button>
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
            <h2 style="font-size: 16px; color: #292541; margin: 0 0 10px;">Hapus Satuan?</h2>
            <p style="color: #555164; font-size: 13px; margin: 0 0 24px; line-height: 1.6;">
              Anda yakin ingin menghapus satuan <strong style="color: #292541; font-weight: 600;">{{ deleteModal.unit?.name }}</strong>? Tindakan ini tidak dapat dibatalkan.
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
.units-page { display: block; }
.panel-header { padding: 22px 22px 0; display: flex; justify-content: space-between; align-items: flex-start; }
.field-error { display: block; color: #d47770; font-size: 10px; margin-top: 5px; }

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
.modern-form input { width: 100%; background: #fafafd; border: 1px solid #e9e7ef; border-radius: 8px; padding: 10px 14px; font-size: 12px; color: #48445c; outline: none; transition: all 0.2s; box-sizing: border-box; }
.modern-form input:focus { border-color: #bd3c3c; background: #fff; box-shadow: 0 0 0 3px rgba(189, 60, 60, 0.1); }
.modern-form input.input-error { border-color: #d47770; background: #fffafb; }
.modern-form input.input-error:focus { box-shadow: 0 0 0 3px rgba(212, 119, 112, 0.15); }
.modern-form input::placeholder { color: #c4c1cd; }

.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px; padding-top: 18px; border-top: 1px solid #f0eff3; }
.cancel-button { background: white; border: 1px solid #e9e7ef; color: #777486; padding: 10px 16px; border-radius: 7px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
.cancel-button:hover { background: #fafafd; color: #48445c; }
</style>
