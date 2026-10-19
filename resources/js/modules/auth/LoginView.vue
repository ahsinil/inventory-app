<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../app/stores/auth'
import { ArrowRight, Boxes, Eye, EyeOff, LockKeyhole, Mail } from 'lucide-vue-next'

const router = useRouter()
const authStore = useAuthStore()

const showPassword = ref(false)
const email = ref('')
const password = ref('')
const loading = ref(false)

const onContinue = async () => {
  loading.value = true
  const ok = await authStore.login({ email: email.value, password: password.value })
  loading.value = false
  if (ok) router.push({ name: 'Dashboard' })
}
</script>

<template>
  <main class="login-page">
    <section class="login-visual" aria-label="Ringkasan Stockroom">
      <div class="login-brand"><div class="brand-mark"><Boxes :size="21" /></div><strong>Stockroom</strong></div>
      <div class="login-visual-copy"><span class="eyebrow">Operasi inventaris, diperjelas.</span><h1>Jaga setiap gudang<br /><em>dalam ritme.</em></h1><p>Satu ruang kerja yang tenang untuk tim inventaris, pergerakan, dan pemenuhan.</p></div>
      <div class="login-visual-footer"><span>Meridian Network</span><span>Stockroom v1.0</span></div>
    </section>
    <section class="login-panel"><div class="login-form-wrap">
      <div class="mobile-login-brand"><div class="brand-mark"><Boxes :size="21" /></div><strong>Stockroom</strong></div>
      <span class="eyebrow">Selamat datang kembali</span><h2>Masuk ke ruang kerja Anda</h2><p class="login-subtitle">Masukkan detail Anda untuk melanjutkan ke Stockroom.</p>
      <form class="login-form" @submit.prevent="onContinue">
        <p v-if="authStore.error" role="alert" style="color:#c0392b;font-size:13px;margin:0">{{ authStore.error }}</p>
        <label>Alamat Email
          <input type="email" v-model="email" placeholder="anda@perusahaan.com" />
        </label>
        <label>Kata Sandi
          <div class="password-field">
            <input :type="showPassword ? 'text' : 'password'" v-model="password" placeholder="Masukkan kata sandi Anda" />
            <button type="button" aria-label="Toggle password visibility" @click="showPassword = !showPassword">
              <EyeOff v-if="showPassword" :size="17" />
              <Eye v-else :size="17" />
            </button>
          </div>
        </label>
        <div class="login-options">
          <label class="remember">
            <input type="checkbox" checked /> <span>Tetap masuk</span>
          </label>
          <button type="button" class="forgot-link">Lupa kata sandi?</button>
        </div>
        <button class="login-submit" type="submit" :disabled="loading">
          {{ loading ? 'Masuk...' : 'Masuk' }} <ArrowRight v-if="!loading" :size="17" />
        </button>
      </form>
      <p class="login-footnote">Dengan melanjutkan, Anda menyetujui <a href="#">Syarat Layanan</a> dan <a href="#">Kebijakan Privasi</a> Stockroom.</p>
    </div></section>
  </main>
</template>
