import { defineStore } from 'pinia';
import axios from 'axios';

// Configure default axios
axios.defaults.baseURL = '/api/v1';
axios.defaults.withCredentials = true; // Important for Sanctum

interface User {
  id: string;
  name: string;
  email: string;
  company_id: string;
  company?: {
    id: string;
    name: string;
    code: string;
  } | null;
  roles: string[];
  permissions: string[];
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as User | null,
    isAuthenticated: false,
    loading: false,
    error: null as string | null
  }),
  
  actions: {
    async fetchProfile() {
      try {
        const response = await axios.get('/profile');
        this.user = response.data.data;
        this.isAuthenticated = true;
      } catch (e) {
        this.user = null;
        this.isAuthenticated = false;
      }
    },
    
    async login(credentials: { email: string, password: string }) {
      this.loading = true;
      this.error = null;
      try {
        // Fetch CSRF cookie first (handled by Laravel web routes, usually /sanctum/csrf-cookie)
        await axios.get('/sanctum/csrf-cookie', { baseURL: '' });
        
        const response = await axios.post('/login', credentials);
        this.user = response.data.data.user;
        this.isAuthenticated = true;
        return true;
      } catch (e: any) {
        this.error = e.response?.data?.message || 'Login failed';
        return false;
      } finally {
        this.loading = false;
      }
    },
    
    async logout() {
      try {
        await axios.post('/logout');
      } finally {
        this.user = null;
        this.isAuthenticated = false;
      }
    }
  }
});
