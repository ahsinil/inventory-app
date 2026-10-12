<script setup lang="ts">
import { ref, onMounted } from 'vue'
import {
  Building2,
  Sliders,
  Shield,
  Save,
  Loader2,
  CheckCircle2,
  AlertTriangle,
  Info
} from 'lucide-vue-next'
import axios from 'axios'
import { useAuthStore } from '../../app/stores/auth'

const authStore = useAuthStore()

const activeTab = ref<'company' | 'inventory' | 'security'>('company')
const loading = ref(true)
const saving = ref(false)

const companyForm = ref({
  name: '',
  code: '',
})

const settingsForm = ref({
  allow_negative_stock: 'false',
  low_stock_notification: 'true',
  default_currency: 'IDR',
  company_address: '',
  company_phone: '',
  company_email: '',
  valuation_method: 'HPP_RERATA',
  timezone: 'Asia/Jakarta'
})

const profileForm = ref({
  name: '',
  email: '',
  current_password: '',
  new_password: '',
  new_password_confirmation: ''
})

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

const fetchSettings = async () => {
  loading.value = true
  try {
    const res = await axios.get('/settings')
    const data = res.data.data
    companyForm.value.name = data.company?.name || ''
    companyForm.value.code = data.company?.code || ''
    settingsForm.value = { ...settingsForm.value, ...data.settings }

    if (authStore.user) {
      profileForm.value.name = authStore.user.name
      profileForm.value.email = authStore.user.email
    }
  } catch (err) {
    console.error('Failed to load settings:', err)
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  saving.value = true
  try {
    await axios.post('/settings', {
      company: companyForm.value,
      settings: settingsForm.value
    })
    showMessage('Pengaturan sistem berhasil diperbarui!')
  } catch (err: any) {
    const msg = err.response?.data?.message || 'Gagal menyimpan pengaturan'
    showMessage(msg, true)
  } finally {
    saving.value = false
  }
}

const saveProfile = async () => {
  saving.value = true
  try {
    const payload: Record<string, string> = {
      name: profileForm.value.name,
      email: profileForm.value.email,
    }
    if (profileForm.value.new_password) {
      payload.current_password = profileForm.value.current_password
      payload.new_password = profileForm.value.new_password
      payload.new_password_confirmation = profileForm.value.new_password_confirmation
    }

    const res = await axios.put('/profile', payload)
    if (authStore.user) {
      authStore.user.name = res.data.data.name
      authStore.user.email = res.data.data.email
    }
    profileForm.value.current_password = ''
    profileForm.value.new_password = ''
    profileForm.value.new_password_confirmation = ''
    showMessage('Profil dan kata sandi berhasil diperbarui!')
  } catch (err: any) {
    const msg = err.response?.data?.message || err.response?.data?.errors?.current_password?.[0] || 'Gagal memperbarui profil'
    showMessage(msg, true)
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchSettings()
})
</script>

<template>
  <section class="settings-page">
    <!-- Header -->
    <div class="page-heading">
      <div>
        <p class="eyebrow">KONFIGURASI / PENGATURAN SISTEM</p>
        <h1>Pengaturan Sistem & Profil</h1>
        <p class="muted">Konfigurasi identitas perusahaan, aturan audit stok, dan keamanan akun pengguna Anda.</p>
      </div>
      <button
        v-if="activeTab !== 'security'"
        class="primary-button"
        :disabled="saving"
        @click="saveSettings"
      >
        <Loader2 v-if="saving" class="spin" :size="16" />
        <Save v-else :size="16" />
        <span>{{ saving ? 'Menyimpan...' : 'Simpan pengaturan' }}</span>
      </button>
      <button
        v-else
        class="primary-button"
        :disabled="saving"
        @click="saveProfile"
      >
        <Loader2 v-if="saving" class="spin" :size="16" />
        <Save v-else :size="16" />
        <span>{{ saving ? 'Menyimpan...' : 'Perbarui akun' }}</span>
      </button>
    </div>

    <!-- Notification Feedback -->
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

    <!-- Main Container with Navigation Tabs -->
    <div class="panel settings-container">
      <div class="tabs-header">
        <button
          class="tab-btn"
          :class="{ active: activeTab === 'company' }"
          @click="activeTab = 'company'"
        >
          <Building2 :size="15" />
          <span>Profil Perusahaan</span>
        </button>

        <button
          class="tab-btn"
          :class="{ active: activeTab === 'inventory' }"
          @click="activeTab = 'inventory'"
        >
          <Sliders :size="15" />
          <span>Aturan Inventaris</span>
        </button>

        <button
          class="tab-btn"
          :class="{ active: activeTab === 'security' }"
          @click="activeTab = 'security'"
        >
          <Shield :size="15" />
          <span>Keamanan & Akun</span>
        </button>
      </div>

      <div v-if="loading" style="padding: 40px; text-align: center; color: #aaa7b4;">
        <Loader2 class="spin" :size="24" style="margin: 0 auto 8px;" />
        <span style="font-size: 11px;">Memuat konfigurasi...</span>
      </div>

      <!-- Tab 1: Profil Perusahaan -->
      <div v-else-if="activeTab === 'company'" class="tab-content">
        <div class="section-title">
          <h3>Identitas Perusahaan</h3>
          <p>Informasi dasar organisasi yang tampil pada cetak dokumen dan laporan.</p>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label>Nama Perusahaan</label>
            <input v-model="companyForm.name" placeholder="PT Nama Perusahaan" />
          </div>

          <div class="form-group">
            <label>Kode Perusahaan / Singkatan</label>
            <input v-model="companyForm.code" placeholder="Contoh: CORP-JKT" />
          </div>

          <div class="form-group full-width">
            <label>Alamat Kantor / Fasilitas Utama</label>
            <input v-model="settingsForm.company_address" placeholder="Alamat lengkap jalan, kota, provinsi" />
          </div>

          <div class="form-group">
            <label>Nomor Telepon Operasional</label>
            <input v-model="settingsForm.company_phone" placeholder="+62 21 0000000" />
          </div>

          <div class="form-group">
            <label>Email Resmi</label>
            <input v-model="settingsForm.company_email" type="email" placeholder="kontak@perusahaan.com" />
          </div>

          <div class="form-group">
            <label>Zona Waktu Default</label>
            <select v-model="settingsForm.timezone" class="form-select">
              <option value="Asia/Jakarta">WIB — Asia/Jakarta (UTC+7)</option>
              <option value="Asia/Makassar">WITA — Asia/Makassar (UTC+8)</option>
              <option value="Asia/Jayapura">WIT — Asia/Jayapura (UTC+9)</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Tab 2: Aturan Inventaris -->
      <div v-else-if="activeTab === 'inventory'" class="tab-content">
        <div class="section-title">
          <h3>Kebijakan & Pengendalian Stok</h3>
          <p>Tentukan parameter validasi transaksi stok fisik dan perhitungan nilai barang.</p>
        </div>

        <div class="rules-list">
          <!-- Allow Negative Stock Rule -->
          <div class="rule-card">
            <div class="rule-info">
              <div class="rule-title-row">
                <strong>Izinkan Saldo Stok Negatif (Allow Negative Stock)</strong>
                <span class="rule-pill" :class="settingsForm.allow_negative_stock === 'true' ? 'warning' : 'safe'">
                  {{ settingsForm.allow_negative_stock === 'true' ? 'Aktif (Berisiko)' : 'Nonaktif (Direkomendasikan)' }}
                </span>
              </div>
              <p>
                Jika dinonaktifkan, sistem akan otomatis menolak transaksi mutasi barang keluar (OUT) jika jumlah fisik di gudang tidak mencukupi untuk mencegah over-drafting stok.
              </p>
            </div>
            <label class="switch">
              <input
                type="checkbox"
                :checked="settingsForm.allow_negative_stock === 'true'"
                @change="settingsForm.allow_negative_stock = ($event.target as HTMLInputElement).checked ? 'true' : 'false'"
              />
              <span class="slider" />
            </label>
          </div>

          <!-- Low Stock Alert Rule -->
          <div class="rule-card">
            <div class="rule-info">
              <div class="rule-title-row">
                <strong>Notifikasi Otomatis Stok Menipis</strong>
                <span class="rule-pill safe">
                  {{ settingsForm.low_stock_notification === 'true' ? 'Aktif' : 'Nonaktif' }}
                </span>
              </div>
              <p>
                Menampilkan peringatan visual pada dasbor dan laporan seketika saldo stok suatu produk berada di bawah batas minimum (*threshold*).
              </p>
            </div>
            <label class="switch">
              <input
                type="checkbox"
                :checked="settingsForm.low_stock_notification === 'true'"
                @change="settingsForm.low_stock_notification = ($event.target as HTMLInputElement).checked ? 'true' : 'false'"
              />
              <span class="slider" />
            </label>
          </div>

          <!-- Valuation Method -->
          <div class="rule-card">
            <div class="rule-info">
              <div class="rule-title-row">
                <strong>Metode Valuasi HPP Default</strong>
              </div>
              <p>
                Metode standar yang digunakan dalam kalkulasi nilai aset inventaris untuk laporan akuntansi operasional.
              </p>
            </div>
            <select v-model="settingsForm.valuation_method" class="form-select" style="width: 220px;">
              <option value="HPP_RERATA">Harga Pokok Rata-rata (Weighted Average)</option>
              <option value="FIFO">FIFO (First In, First Out)</option>
              <option value="STANDAR">Harga Standar Katalog</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Tab 3: Keamanan & Akun Saya -->
      <div v-else-if="activeTab === 'security'" class="tab-content">
        <div class="section-title">
          <h3>Profil Pengguna & Keamanan Kredensial</h3>
          <p>Ubah detail nama profil dan perbarui kata sandi masuk Anda.</p>
        </div>

        <form @submit.prevent="saveProfile" class="profile-form">
          <div class="form-grid">
            <div class="form-group">
              <label>Nama Anda</label>
              <input v-model="profileForm.name" required />
            </div>

            <div class="form-group">
              <label>Alamat Email Login</label>
              <input v-model="profileForm.email" type="email" required />
            </div>
          </div>

          <div class="password-box">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
              <Shield :size="16" style="color: #bd3c3c;" />
              <strong style="font-size: 12px; color: #292541;">Ganti Kata Sandi (Opsional)</strong>
            </div>

            <div class="form-grid">
              <div class="form-group full-width">
                <label>Kata Sandi Saat Ini</label>
                <input
                  v-model="profileForm.current_password"
                  type="password"
                  placeholder="Masukkan kata sandi lama Anda"
                />
              </div>

              <div class="form-group">
                <label>Kata Sandi Baru</label>
                <input
                  v-model="profileForm.new_password"
                  type="password"
                  placeholder="Minimal 6 karakter"
                />
              </div>

              <div class="form-group">
                <label>Ulangi Kata Sandi Baru</label>
                <input
                  v-model="profileForm.new_password_confirmation"
                  type="password"
                  placeholder="Ketik ulang kata sandi baru"
                />
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>

<style scoped>
.settings-page {
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

.settings-container {
  background: white;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  overflow: hidden;
}

.tabs-header {
  display: flex;
  border-bottom: 1px solid #f0eff3;
  background: #fafafd;
  padding: 0 10px;
}

.tab-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 13px 18px;
  border: 0;
  background: transparent;
  color: #858291;
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: all 0.15s;
}

.tab-btn:hover {
  color: #bd3c3c;
}

.tab-btn.active {
  color: #bd3c3c;
  font-weight: 650;
  border-bottom-color: #bd3c3c;
  background: white;
}

.tab-content {
  padding: 24px 28px;
}

.section-title h3 {
  margin: 0;
  font-size: 13px;
  color: #292541;
  font-weight: 650;
}

.section-title p {
  margin: 4px 0 20px;
  font-size: 11px;
  color: #858291;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.form-group.full-width {
  grid-column: 1 / -1;
}

.form-group label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  color: #292541;
  margin-bottom: 6px;
}

.form-group input,
.form-select {
  width: 100%;
  padding: 8px 11px;
  border: 1px solid #e2e1ea;
  border-radius: 6px;
  font-size: 11px;
  color: #292541;
  outline: 0;
  box-sizing: border-box;
  background: white;
}

.form-group input:focus,
.form-select:focus {
  border-color: #bd3c3c;
}

/* Rules list */
.rules-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.rule-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 16px 20px;
  border: 1px solid #eeedf3;
  border-radius: 10px;
  background: #fdfdfd;
}

.rule-info {
  flex: 1;
}

.rule-title-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.rule-title-row strong {
  font-size: 12px;
  color: #292541;
}

.rule-pill {
  font-size: 9px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 4px;
}

.rule-pill.safe {
  background: #eaf8f1;
  color: #276749;
}

.rule-pill.warning {
  background: #fffaf0;
  color: #dd6b20;
}

.rule-info p {
  margin: 6px 0 0;
  font-size: 10px;
  color: #858291;
  line-height: 1.4;
}

/* Toggle Switch */
.switch {
  position: relative;
  display: inline-block;
  width: 40px;
  height: 22px;
  flex-shrink: 0;
}

.switch input {
  opacity: 0;
  width: 0;
  height: 0;
}

.slider {
  position: absolute;
  cursor: pointer;
  inset: 0;
  background-color: #e2e1ea;
  transition: 0.2s;
  border-radius: 24px;
}

.slider:before {
  position: absolute;
  content: "";
  height: 16px;
  width: 16px;
  left: 3px;
  bottom: 3px;
  background-color: white;
  transition: 0.2s;
  border-radius: 50%;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

input:checked + .slider {
  background-color: #bd3c3c;
}

input:checked + .slider:before {
  transform: translateX(18px);
}

/* Password Box */
.password-box {
  margin-top: 24px;
  padding: 20px;
  background: #fafafd;
  border: 1px solid #eeedf3;
  border-radius: 10px;
}

@media (max-width: 768px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
  .rule-card {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
