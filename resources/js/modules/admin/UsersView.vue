<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import {
  Plus,
  Search,
  Edit,
  Trash2,
  ShieldCheck,
  UserCheck,
  Users,
  Loader2,
  X,
  ChevronDown,
  CheckCircle2,
  AlertTriangle
} from 'lucide-vue-next'
import axios from 'axios'
import { useAuthStore } from '../../app/stores/auth'

const authStore = useAuthStore()

interface RoleOption {
  name: string
  description: string
}

interface UserItem {
  id: string
  name: string
  email: string
  company_id: string
  created_at: string
  roles: Array<{ id: number; name: string }>
}

const loading = ref(true)
const saving = ref(false)
const users = ref<UserItem[]>([])
const roles = ref<RoleOption[]>([])
const search = ref('')
const filterRole = ref('')

const showModal = ref(false)
const isEditing = ref(false)
const editingUserId = ref<string | null>(null)

const form = ref({
  name: '',
  email: '',
  password: '',
  role: 'WAREHOUSE_STAFF'
})

const showDeleteModal = ref(false)
const userToDelete = ref<UserItem | null>(null)
const deleting = ref(false)

const successMessage = ref<string | null>(null)
const errorMessage = ref<string | null>(null)

const showMessage = (msg: string, isError = false) => {
  if (isError) {
    errorMessage.value = msg
    setTimeout(() => { errorMessage.value = null }, 5000)
  } else {
    successMessage.value = msg
    setTimeout(() => { successMessage.value = null }, 4000)
  }
}

const fetchRoles = async () => {
  try {
    const res = await axios.get('/roles')
    roles.value = res.data.data || []
  } catch (err) {
    console.error('Failed to load roles:', err)
  }
}

const fetchUsers = async () => {
  loading.value = true
  try {
    const params: Record<string, string> = {}
    if (search.value) params.search = search.value
    if (filterRole.value) params.role = filterRole.value

    const res = await axios.get('/users', { params })
    users.value = res.data.data.data || []
  } catch (err) {
    console.error('Failed to load users:', err)
  } finally {
    loading.value = false
  }
}

const openAddModal = () => {
  isEditing.value = false
  editingUserId.value = null
  form.value = {
    name: '',
    email: '',
    password: '',
    role: 'WAREHOUSE_STAFF'
  }
  showModal.value = true
}

const openEditModal = (u: UserItem) => {
  isEditing.value = true
  editingUserId.value = u.id
  form.value = {
    name: u.name,
    email: u.email,
    password: '',
    role: u.roles[0]?.name || 'WAREHOUSE_STAFF'
  }
  showModal.value = true
}

const saveUser = async () => {
  saving.value = true
  try {
    if (isEditing.value && editingUserId.value) {
      await axios.put(`/users/${editingUserId.value}`, {
        name: form.value.name,
        email: form.value.email,
        password: form.value.password || undefined,
        role: form.value.role
      })
      showMessage('Data pengguna berhasil diperbarui!')
    } else {
      await axios.post('/users', {
        name: form.value.name,
        email: form.value.email,
        password: form.value.password,
        role: form.value.role
      })
      showMessage('Pengguna baru berhasil ditambahkan!')
    }
    showModal.value = false
    fetchUsers()
  } catch (err: any) {
    const msg = err.response?.data?.message || err.response?.data?.errors?.email?.[0] || 'Gagal menyimpan data pengguna'
    showMessage(msg, true)
  } finally {
    saving.value = false
  }
}

const confirmDelete = (u: UserItem) => {
  userToDelete.value = u
  showDeleteModal.value = true
}

const deleteUser = async () => {
  if (!userToDelete.value) return
  deleting.value = true
  try {
    await axios.delete(`/users/${userToDelete.value.id}`)
    showMessage('Pengguna berhasil dihapus!')
    showDeleteModal.value = false
    userToDelete.value = null
    fetchUsers()
  } catch (err: any) {
    const msg = err.response?.data?.message || 'Gagal menghapus pengguna'
    showMessage(msg, true)
  } finally {
    deleting.value = false
  }
}

// KPI Statistics
const totalUsersCount = computed(() => users.value.length)
const adminCount = computed(() => {
  return users.value.filter(u => u.roles.some(r => r.name === 'SUPER_ADMIN' || r.name === 'ADMIN')).length
})
const warehouseCount = computed(() => {
  return users.value.filter(u => u.roles.some(r => r.name.includes('WAREHOUSE'))).length
})

const getRoleBadgeClass = (roleName: string) => {
  switch (roleName) {
    case 'SUPER_ADMIN': return 'badge-crimson'
    case 'ADMIN': return 'badge-blue'
    case 'INVENTORY_MANAGER': return 'badge-purple'
    case 'WAREHOUSE_MANAGER': return 'badge-orange'
    case 'WAREHOUSE_STAFF': return 'badge-teal'
    case 'AUDITOR': return 'badge-gray'
    default: return 'badge-slate'
  }
}

const formatDateTime = (dateStr: string) => {
  if (!dateStr) return '-'
  return new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric'
  }).format(new Date(dateStr))
}

onMounted(() => {
  fetchRoles()
  fetchUsers()
})
</script>

<template>
  <section class="users-page">
    <!-- Header -->
    <div class="page-heading">
      <div>
        <p class="eyebrow">KONFIGURASI / MANAJEMEN PENGGUNA</p>
        <h1>Manajemen Pengguna & Hak Akses</h1>
        <p class="muted">Kelola akun anggota tim, atur peran otorisasi, dan pantau izin akses sistem inventaris.</p>
      </div>
      <button class="primary-button" @click="openAddModal">
        <Plus :size="16" /> Tambah pengguna
      </button>
    </div>

    <!-- Feedback Alerts -->
    <Transition name="fade">
      <div v-if="successMessage" class="alert-box success">
        <CheckCircle2 :size="16" />
        <span>{{ successMessage }}</span>
      </div>
    </Transition>
    <Transition name="fade">
      <div v-if="errorMessage" class="alert-box error">
        <AlertTriangle :size="16" />
        <span>{{ errorMessage }}</span>
      </div>
    </Transition>

    <!-- 4 Stats Cards -->
    <div class="user-stats-grid">
      <div class="stat-card">
        <span class="stat-label">Total Anggota</span>
        <strong class="stat-val">{{ totalUsersCount }}</strong>
        <span class="stat-sub">Terdaftar di perusahaan</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Administrator</span>
        <strong class="stat-val" style="color: #4e8acc;">{{ adminCount }}</strong>
        <span class="stat-sub">Akses penuh sistem</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Operasional Gudang</span>
        <strong class="stat-val" style="color: #dc9b45;">{{ warehouseCount }}</strong>
        <span class="stat-sub">Manager & Operator</span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Pilihan Peran</span>
        <strong class="stat-val">{{ roles.length }}</strong>
        <span class="stat-sub">Tingkat wewenang RBAC</span>
      </div>
    </div>

    <!-- Table Panel -->
    <div class="panel users-panel">
      <div class="panel-header-toolbar">
        <div class="search-wrap">
          <Search :size="15" style="color: #aaa7b4;" />
          <input
            v-model="search"
            placeholder="Cari nama atau email..."
            @input="fetchUsers"
          />
        </div>

        <div class="filter-wrap">
          <select v-model="filterRole" @change="fetchUsers" class="role-select">
            <option value="">Semua Peran</option>
            <option v-for="r in roles" :key="r.name" :value="r.name">
              {{ r.name }}
            </option>
          </select>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Pengguna</th>
              <th>Email</th>
              <th>Peran / Hak Akses</th>
              <th>Tanggal Terdaftar</th>
              <th style="text-align: right;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="5" style="text-align: center; padding: 30px; color: #aaa7b4;">
                <Loader2 class="spin" :size="20" style="display: inline-block; vertical-align: middle; margin-right: 6px;" />
                Memuat data pengguna...
              </td>
            </tr>
            <tr v-else-if="users.length === 0">
              <td colspan="5" style="text-align: center; padding: 30px; color: #aaa7b4;">
                Tidak ada pengguna yang sesuai dengan pencarian.
              </td>
            </tr>
            <tr v-for="u in users" :key="u.id">
              <td>
                <div class="user-cell">
                  <div class="user-avatar">
                    {{ u.name.substring(0, 2).toUpperCase() }}
                  </div>
                  <div>
                    <strong style="display: block; font-size: 11px; color: #292541;">
                      {{ u.name }}
                      <span v-if="u.id === authStore.user?.id" class="you-badge">(Anda)</span>
                    </strong>
                  </div>
                </div>
              </td>
              <td>
                <span style="font-size: 11px; color: #555164;">{{ u.email }}</span>
              </td>
              <td>
                <span
                  v-for="r in u.roles"
                  :key="r.id"
                  class="role-badge"
                  :class="getRoleBadgeClass(r.name)"
                >
                  {{ r.name }}
                </span>
              </td>
              <td style="color: #858291; font-size: 10px;">
                {{ formatDateTime(u.created_at) }}
              </td>
              <td style="text-align: right;">
                <div class="actions-cell">
                  <button class="action-btn" title="Ubah pengguna" @click="openEditModal(u)">
                    <Edit :size="14" />
                  </button>
                  <button
                    class="action-btn delete"
                    :disabled="u.id === authStore.user?.id"
                    :title="u.id === authStore.user?.id ? 'Tidak bisa menghapus akun sendiri' : 'Hapus pengguna'"
                    @click="confirmDelete(u)"
                  >
                    <Trash2 :size="14" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Form (Tambah / Edit) -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showModal" class="modal-backdrop" @click="showModal = false">
          <div class="modal-card" @click.stop>
            <div class="modal-header">
              <h3 style="margin: 0; font-size: 14px; color: #292541; font-weight: 650;">
                {{ isEditing ? 'Ubah Data Pengguna' : 'Tambah Pengguna Baru' }}
              </h3>
              <button class="icon-btn" @click="showModal = false"><X :size="16" /></button>
            </div>

            <form @submit.prevent="saveUser">
              <div class="modal-body">
                <div class="form-group">
                  <label>Nama Lengkap</label>
                  <input
                    v-model="form.name"
                    required
                    placeholder="Contoh: Budi Santoso"
                  />
                </div>

                <div class="form-group">
                  <label>Alamat Email</label>
                  <input
                    v-model="form.email"
                    type="email"
                    required
                    placeholder="budi@perusahaan.com"
                  />
                </div>

                <div class="form-group">
                  <label>
                    Kata Sandi
                    <small v-if="isEditing" style="font-weight: normal; color: #aaa7b4;">
                      (Kosongkan jika tidak ingin mengubah)
                    </small>
                  </label>
                  <input
                    v-model="form.password"
                    type="password"
                    :required="!isEditing"
                    placeholder="Minimal 6 karakter"
                  />
                </div>

                <div class="form-group">
                  <label>Peran Otorisasi (Role)</label>
                  <select v-model="form.role" class="modal-select">
                    <option v-for="r in roles" :key="r.name" :value="r.name">
                      {{ r.name }} — {{ r.description }}
                    </option>
                  </select>
                </div>
              </div>

              <div class="modal-footer">
                <button type="button" class="secondary-btn" @click="showModal = false">Batal</button>
                <button type="submit" class="primary-button" :disabled="saving">
                  <Loader2 v-if="saving" class="spin" :size="14" />
                  <span>{{ isEditing ? 'Simpan Perubahan' : 'Tambah Pengguna' }}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Delete Confirmation Modal -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showDeleteModal" class="modal-backdrop" @click="showDeleteModal = false">
          <div class="modal-card small" @click.stop>
            <div class="modal-header">
              <div style="display: flex; align-items: center; gap: 8px;">
                <AlertTriangle :size="18" style="color: #bd3c3c;" />
                <h3 style="margin: 0; font-size: 13px; color: #292541;">Konfirmasi Hapus Pengguna</h3>
              </div>
              <button class="icon-btn" @click="showDeleteModal = false"><X :size="16" /></button>
            </div>

            <div class="modal-body" style="font-size: 11px; color: #555164;">
              Apakah Anda yakin ingin menghapus akun <strong>{{ userToDelete?.name }}</strong> ({{ userToDelete?.email }})?
              Tindakan ini tidak dapat dibatalkan.
            </div>

            <div class="modal-footer">
              <button class="secondary-btn" @click="showDeleteModal = false">Batal</button>
              <button
                class="primary-button"
                style="background: #bd3c3c;"
                :disabled="deleting"
                @click="deleteUser"
              >
                <Loader2 v-if="deleting" class="spin" :size="14" />
                <span>{{ deleting ? 'Menghapus...' : 'Ya, Hapus' }}</span>
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </section>
</template>

<style scoped>
.users-page {
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

.alert-box {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 11px;
  margin-bottom: 16px;
}

.alert-box.success {
  background: #eaf8f1;
  color: #276749;
  border: 1px solid #c6f6d5;
}

.alert-box.error {
  background: #fff5f5;
  color: #c53030;
  border: 1px solid #feb2b2;
}

.user-stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 18px;
}

.stat-card {
  background: #fff;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  padding: 15px 18px;
}

.stat-label {
  display: block;
  font-size: 11px;
  color: #858291;
}

.stat-val {
  display: block;
  font-size: 22px;
  margin-top: 8px;
  letter-spacing: -0.03em;
  color: #292541;
}

.stat-sub {
  display: block;
  font-size: 10px;
  color: #aaa7b4;
  margin-top: 4px;
}

.users-panel {
  background: white;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  overflow: hidden;
}

.panel-header-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 18px;
  border-bottom: 1px solid #f0eff3;
  gap: 12px;
}

.search-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #fafafd;
  border: 1px solid #e9e7ef;
  border-radius: 6px;
  padding: 6px 10px;
  width: 260px;
}

.search-wrap input {
  border: 0;
  outline: 0;
  background: transparent;
  font-size: 11px;
  color: #292541;
  width: 100%;
}

.role-select {
  border: 1px solid #e9e7ef;
  border-radius: 6px;
  padding: 6px 10px;
  font-size: 11px;
  color: #555164;
  background: white;
  outline: 0;
  cursor: pointer;
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.user-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #eef2f6;
  color: #486581;
  font-weight: 700;
  font-size: 10px;
  display: grid;
  place-items: center;
}

.you-badge {
  font-size: 9px;
  color: #38a77a;
  font-weight: 500;
  margin-left: 4px;
}

.role-badge {
  display: inline-block;
  padding: 3px 7px;
  border-radius: 4px;
  font-size: 9px;
  font-weight: 600;
}

.badge-crimson { background: #fff0f0; color: #bd3c3c; border: 1px solid #fcdada; }
.badge-blue { background: #edf5fc; color: #3182ce; border: 1px solid #bee3f8; }
.badge-purple { background: #f3effe; color: #6b46c1; border: 1px solid #e9d8fd; }
.badge-orange { background: #fffaf0; color: #dd6b20; border: 1px solid #feebc8; }
.badge-teal { background: #e6fffa; color: #2c7a7b; border: 1px solid #b2f5ea; }
.badge-gray { background: #edf2f7; color: #4a5568; border: 1px solid #e2e8f0; }
.badge-slate { background: #f7fafc; color: #718096; border: 1px solid #edf2f7; }

.actions-cell {
  display: flex;
  gap: 6px;
  justify-content: flex-end;
}

.action-btn {
  width: 28px;
  height: 28px;
  border: 1px solid #e9e7ef;
  border-radius: 6px;
  background: white;
  color: #777486;
  display: grid;
  place-items: center;
  cursor: pointer;
  transition: all 0.15s;
}

.action-btn:hover {
  background: #f7f6fc;
  color: #bd3c3c;
  border-color: #f7dede;
}

.action-btn.delete:hover:not(:disabled) {
  background: #fff5f5;
  color: #bd3c3c;
  border-color: #feb2b2;
}

.action-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

/* Modal styles */
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
  max-width: 460px;
  background: white;
  border-radius: 12px;
  box-shadow: 0 15px 35px rgba(41, 37, 65, 0.15);
  overflow: hidden;
}

.modal-card.small {
  max-width: 400px;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 1px solid #f0eff3;
}

.modal-body {
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.form-group label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  color: #292541;
  margin-bottom: 6px;
}

.form-group input,
.modal-select {
  width: 100%;
  padding: 8px 11px;
  border: 1px solid #e2e1ea;
  border-radius: 6px;
  font-size: 11px;
  color: #292541;
  outline: 0;
  box-sizing: border-box;
}

.form-group input:focus,
.modal-select:focus {
  border-color: #bd3c3c;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 14px 20px;
  border-top: 1px solid #f0eff3;
  background: #fafafd;
}

.secondary-btn {
  padding: 8px 14px;
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

@media (max-width: 800px) {
  .user-stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
