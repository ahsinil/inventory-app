import { createRouter, createWebHistory, RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes: Array<RouteRecordRaw> = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('../../modules/auth/LoginView.vue'),
    meta: { guestOnly: true }
  },
  {
    path: '/',
    component: () => import('../../layouts/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Dashboard',
        component: () => import('../../modules/dashboard/HomeView.vue'),
      },
      {
        path: 'products',
        name: 'Products',
        component: () => import('../../modules/inventory/ProductsView.vue'),
      },
      {
        path: 'stock',
        name: 'StockOverview',
        component: () => import('../../modules/inventory/StockView.vue'),
      },
      {
        path: 'stock-movements',
        name: 'StockMovements',
        component: () => import('../../modules/inventory/StockMovementsView.vue'),
      },
      {
        path: 'reservations',
        name: 'Reservations',
        component: () => import('../../modules/inventory/ReservationsView.vue'),
      },
      {
        path: 'assets',
        name: 'SerializedAssets',
        component: () => import('../../modules/inventory/AssetsView.vue'),
      },
      {
        path: 'receiving',
        name: 'Receiving',
        component: () => import('../../modules/operations/ReceivingView.vue'),
      },
      {
        path: 'transfers',
        name: 'Transfers',
        component: () => import('../../modules/operations/TransfersView.vue'),
      },
      {
        path: 'adjustments',
        name: 'Adjustments',
        component: () => import('../../modules/operations/AdjustmentsView.vue'),
      },
      {
        path: 'returns',
        name: 'Returns',
        component: () => import('../../modules/operations/ReturnsView.vue'),
      },
      {
        path: 'categories',
        name: 'Categories',
        component: () => import('../../modules/inventory/CategoriesView.vue'),
      },
      {
        path: 'units',
        name: 'Units',
        component: () => import('../../modules/inventory/UnitsView.vue'),
      },
      {
        path: 'warehouses',
        name: 'Warehouses',
        component: () => import('../../modules/inventory/WarehousesView.vue'),
      },
      {
        path: 'suppliers',
        name: 'Suppliers',
        component: () => import('../../modules/inventory/SuppliersView.vue'),
      },
      {
        path: 'reports',
        name: 'Reports',
        component: () => import('../../modules/inventory/ReportsView.vue'),
      },
      {
        path: 'integrations',
        name: 'Integrations',
        component: () => import('../../modules/inventory/IntegrationsView.vue'),
      },
      {
        path: 'users',
        name: 'Users',
        component: () => import('../../modules/admin/UsersView.vue'),
      },
      {
        path: 'settings',
        name: 'Settings',
        component: () => import('../../modules/admin/SettingsView.vue'),
      }
    ]
  }
];

const router = createRouter({
  history: createWebHistory(),
  routes
});

// Guard router
let profileChecked = false;
router.beforeEach(async (to) => {
  const authStore = useAuthStore();

  if (!profileChecked) {
    profileChecked = true;
    await authStore.fetchProfile();
  }
  
  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return { name: 'Login' };
  }
  if (to.meta.guestOnly && authStore.isAuthenticated) {
    return { name: 'Dashboard' };
  }
});

export default router;
