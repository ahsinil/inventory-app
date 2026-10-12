<template>
  <div class="dashboard-layout">
    <aside class="sidebar">
      <div class="logo">
        <h2>Inventaris</h2>
      </div>
      <nav class="nav-menu">
        <router-link to="/" class="nav-item" active-class="active" exact>Dasbor</router-link>
        <router-link to="/products" class="nav-item" active-class="active">Produk</router-link>
        <router-link to="/warehouses" class="nav-item" active-class="active">Gudang</router-link>
        <router-link to="/stock" class="nav-item" active-class="active">Ringkasan Stok</router-link>
        <router-link to="/assets" class="nav-item" active-class="active">Aset Berseri</router-link>
        <router-link to="/reports" class="nav-item" active-class="active">Laporan</router-link>
        <router-link to="/integrations" class="nav-item" active-class="active">Integrasi</router-link>
      </nav>
      <div class="user-info">
        <div class="user-details">
          <p class="user-name">{{ authStore.user?.name }}</p>
          <p class="user-role">{{ authStore.user?.roles[0] || 'Pengguna' }}</p>
        </div>
        <button @click="handleLogout" class="logout-btn" :disabled="authStore.loading">
          Keluar
        </button>
      </div>
    </aside>
    
    <main class="main-content">
      <header class="topbar">
        <h1>Sistem Inventaris</h1>
      </header>
      
      <div class="content">
        <router-view></router-view>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../app/stores/auth';

const authStore = useAuthStore();
const router = useRouter();

const handleLogout = async () => {
  await authStore.logout();
  router.push({ name: 'Login' });
};
</script>

<style scoped>
.dashboard-layout {
  display: flex;
  min-height: 100vh;
}

.sidebar {
  width: 250px;
  background-color: #111827;
  color: white;
  display: flex;
  flex-direction: column;
}

.logo {
  padding: 1.5rem;
  border-bottom: 1px solid #374151;
}

.logo h2 {
  margin: 0;
  font-size: 1.25rem;
  letter-spacing: 0.05em;
}

.nav-menu {
  flex: 1;
  padding: 1.5rem 0;
}

.nav-item {
  display: block;
  padding: 0.75rem 1.5rem;
  color: #d1d5db;
  text-decoration: none;
  transition: background-color 0.15s, color 0.15s;
}

.nav-item:hover, .nav-item.active {
  background-color: #1f2937;
  color: white;
}

.user-info {
  padding: 1.5rem;
  border-top: 1px solid #374151;
}

.user-details {
  margin-bottom: 1rem;
}

.user-name {
  margin: 0;
  font-weight: 500;
  font-size: 0.875rem;
}

.user-role {
  margin: 0;
  font-size: 0.75rem;
  color: #9ca3af;
  margin-top: 0.25rem;
}

.logout-btn {
  width: 100%;
  padding: 0.5rem;
  background-color: transparent;
  border: 1px solid #4b5563;
  color: #d1d5db;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s;
}

.logout-btn:hover {
  background-color: #374151;
  color: white;
}

.main-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  background-color: #f3f4f6;
}

.topbar {
  background-color: white;
  padding: 1rem 2rem;
  border-bottom: 1px solid #e5e7eb;
}

.topbar h1 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 500;
  color: #111827;
}

.content {
  padding: 2rem;
  flex: 1;
}

.welcome-card {
  background-color: white;
  padding: 2rem;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.welcome-card h2 {
  margin-top: 0;
  color: #111827;
}

.welcome-card p {
  color: #4b5563;
  margin-bottom: 0;
}
</style>
