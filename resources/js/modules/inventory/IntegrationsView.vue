<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import {
  Activity, AlertCircle, AlertTriangle, CheckCircle2, ChevronRight, Clock, Copy,
  Eye, EyeOff, KeyRound, Link2, Loader2, Plus, RefreshCw, RotateCcw,
  Shield, SlidersHorizontal, Trash2, Webhook, X, Zap,
} from 'lucide-vue-next'

/* ── Types ─────────────────────────────────────────── */
interface ServiceCredential {
  id: string
  name: string
  client_type: 'external' | 'internal'
  key_id: string
  scopes: string[]
  is_active: boolean
  last_used_at: string | null
  expires_at: string | null
  created_at: string
}

interface WebhookSubscription {
  id: string
  name: string
  url: string
  events: string[]
  is_active: boolean
  created_at: string
}

interface WebhookDelivery {
  id: string
  event_type: string
  status: 'PENDING' | 'SUCCESS' | 'FAILED'
  http_status: number | null
  attempts: number
  delivered_at: string | null
  created_at: string
}

/* ── Toast ─────────────────────────────────────────── */
const toastMessage = ref('')
const toastType = ref<'success' | 'error'>('success')
const showToast = (msg: string, type: 'success' | 'error' = 'success') => {
  toastMessage.value = msg
  toastType.value = type
  setTimeout(() => { toastMessage.value = '' }, 3500)
}

/* ── Tabs ──────────────────────────────────────────── */
type Tab = 'credentials' | 'webhooks'
const activeTab = ref<Tab>('credentials')

/* ══════════════════════════════════════════════════════
   SERVICE CREDENTIALS
══════════════════════════════════════════════════════ */
const credentials = ref<ServiceCredential[]>([])
const credLoading = ref(false)
const revealedSecret = ref<string | null>(null)   // one-time token after create/rotate
const revealedCredId = ref<string | null>(null)
const showSecret = ref(false)

const AVAILABLE_SCOPES = [
  { value: 'stock:read',          label: 'Stock – Baca' },
  { value: 'products:read',       label: 'Produk – Baca' },
  { value: 'reservations:read',   label: 'Reservasi – Baca' },
  { value: 'reservations:write',  label: 'Reservasi – Tulis' },
  { value: '*',                   label: 'Semua izin (admin)' },
]

const fetchCredentials = async () => {
  credLoading.value = true
  try {
    const { data } = await axios.get('/api/v1/service-credentials')
    credentials.value = data.data?.data ?? data.data ?? []
  } catch {
    showToast('Gagal memuat kredensial.', 'error')
  } finally {
    credLoading.value = false
  }
}

/* Create credential modal */
const showCreateCred = ref(false)
const newCred = ref({ name: '', client_type: 'external', scopes: [] as string[], expires_at: '' })
const createCredLoading = ref(false)

const toggleScope = (scope: string) => {
  const idx = newCred.value.scopes.indexOf(scope)
  if (idx === -1) newCred.value.scopes.push(scope)
  else newCred.value.scopes.splice(idx, 1)
}

const createCredential = async () => {
  if (!newCred.value.name || !newCred.value.scopes.length) {
    showToast('Nama dan minimal satu scope wajib diisi.', 'error'); return
  }
  createCredLoading.value = true
  try {
    const payload: Record<string, unknown> = {
      name: newCred.value.name,
      client_type: newCred.value.client_type,
      scopes: newCred.value.scopes,
    }
    if (newCred.value.expires_at) payload.expires_at = newCred.value.expires_at
    const { data } = await axios.post('/api/v1/service-credentials', payload)
    revealedSecret.value = data.data?.token ?? null
    revealedCredId.value = data.data?.credential?.id ?? null
    showSecret.value = false
    showCreateCred.value = false
    newCred.value = { name: '', client_type: 'external', scopes: [], expires_at: '' }
    await fetchCredentials()
    showToast('Kredensial berhasil dibuat. Simpan token sekarang!', 'success')
  } catch (err: any) {
    showToast(err?.response?.data?.message ?? 'Gagal membuat kredensial.', 'error')
  } finally {
    createCredLoading.value = false
  }
}

/* Rotate */
const rotatingId = ref<string | null>(null)
const rotateCredential = async (cred: ServiceCredential) => {
  if (!confirm(`Rotate secret untuk "${cred.name}"? Token lama akan langsung tidak berlaku.`)) return
  rotatingId.value = cred.id
  try {
    const { data } = await axios.post(`/api/v1/service-credentials/${cred.id}/rotate`)
    revealedSecret.value = data.data?.token ?? null
    revealedCredId.value = cred.id
    showSecret.value = false
    showToast('Token baru dibuat. Simpan sekarang!', 'success')
  } catch {
    showToast('Gagal me-rotate token.', 'error')
  } finally {
    rotatingId.value = null
  }
}

/* Toggle active */
const togglingId = ref<string | null>(null)
const toggleActive = async (cred: ServiceCredential) => {
  togglingId.value = cred.id
  try {
    await axios.put(`/api/v1/service-credentials/${cred.id}`, { is_active: !cred.is_active })
    cred.is_active = !cred.is_active
    showToast(cred.is_active ? 'Kredensial diaktifkan.' : 'Kredensial dinonaktifkan.')
  } catch {
    showToast('Gagal mengubah status.', 'error')
  } finally {
    togglingId.value = null
  }
}

/* Delete */
const deletingId = ref<string | null>(null)
const deleteCred = async (cred: ServiceCredential) => {
  if (!confirm(`Hapus kredensial "${cred.name}"? Tindakan ini tidak dapat dibatalkan.`)) return
  deletingId.value = cred.id
  try {
    await axios.delete(`/api/v1/service-credentials/${cred.id}`)
    credentials.value = credentials.value.filter(c => c.id !== cred.id)
    showToast('Kredensial dihapus.')
  } catch {
    showToast('Gagal menghapus.', 'error')
  } finally {
    deletingId.value = null
  }
}

const copyToken = async (token: string) => {
  try {
    await navigator.clipboard.writeText(token)
    showToast('Token disalin ke clipboard.')
  } catch {
    showToast('Gagal menyalin.', 'error')
  }
}

const dismissSecret = () => {
  revealedSecret.value = null
  revealedCredId.value = null
  showSecret.value = false
}

const activeCredCount = computed(() => credentials.value.filter(c => c.is_active).length)

/* ══════════════════════════════════════════════════════
   WEBHOOKS
══════════════════════════════════════════════════════ */
const webhooks = ref<WebhookSubscription[]>([])
const webhookLoading = ref(false)

const EVENT_OPTIONS = [
  'stock.updated', 'stock.low',
  'product.created', 'product.updated',
  'reservation.created', 'reservation.fulfilled',
  'receipt.posted', 'transfer.shipped', 'transfer.received',
  'adjustment.posted',
]

const fetchWebhooks = async () => {
  webhookLoading.value = true
  try {
    const { data } = await axios.get('/api/v1/webhooks')
    webhooks.value = data.data?.data ?? data.data ?? []
  } catch {
    showToast('Gagal memuat webhook.', 'error')
  } finally {
    webhookLoading.value = false
  }
}

/* Create webhook modal */
const showCreateWebhook = ref(false)
const newWebhook = ref({ name: '', url: '', events: [] as string[] })
const createWebhookLoading = ref(false)

const toggleEvent = (ev: string) => {
  const idx = newWebhook.value.events.indexOf(ev)
  if (idx === -1) newWebhook.value.events.push(ev)
  else newWebhook.value.events.splice(idx, 1)
}

const createWebhook = async () => {
  if (!newWebhook.value.name || !newWebhook.value.url || !newWebhook.value.events.length) {
    showToast('Nama, URL, dan minimal satu event wajib diisi.', 'error'); return
  }
  createWebhookLoading.value = true
  try {
    const { data } = await axios.post('/api/v1/webhooks', newWebhook.value)
    // secret shown once
    const wSecret = data.data?.secret ?? null
    if (wSecret) {
      revealedSecret.value = wSecret
      revealedCredId.value = data.data?.subscription?.id ?? null
      showSecret.value = false
    }
    showCreateWebhook.value = false
    newWebhook.value = { name: '', url: '', events: [] }
    await fetchWebhooks()
    showToast('Webhook berhasil dibuat!' + (wSecret ? ' Simpan signing secret sekarang.' : ''), 'success')
  } catch (err: any) {
    showToast(err?.response?.data?.message ?? 'Gagal membuat webhook.', 'error')
  } finally {
    createWebhookLoading.value = false
  }
}

/* Toggle webhook active */
const togglingWebhookId = ref<string | null>(null)
const toggleWebhookActive = async (wh: WebhookSubscription) => {
  togglingWebhookId.value = wh.id
  try {
    await axios.put(`/api/v1/webhooks/${wh.id}`, { is_active: !wh.is_active })
    wh.is_active = !wh.is_active
    showToast(wh.is_active ? 'Webhook diaktifkan.' : 'Webhook dinonaktifkan.')
  } catch {
    showToast('Gagal mengubah status webhook.', 'error')
  } finally {
    togglingWebhookId.value = null
  }
}

/* Delete webhook */
const deletingWebhookId = ref<string | null>(null)
const deleteWebhook = async (wh: WebhookSubscription) => {
  if (!confirm(`Hapus webhook "${wh.name}"?`)) return
  deletingWebhookId.value = wh.id
  try {
    await axios.delete(`/api/v1/webhooks/${wh.id}`)
    webhooks.value = webhooks.value.filter(w => w.id !== wh.id)
    showToast('Webhook dihapus.')
  } catch {
    showToast('Gagal menghapus webhook.', 'error')
  } finally {
    deletingWebhookId.value = null
  }
}

/* Delivery logs drawer */
const selectedWebhook = ref<WebhookSubscription | null>(null)
const deliveries = ref<WebhookDelivery[]>([])
const deliveryLoading = ref(false)
const replayingId = ref<string | null>(null)

const openDeliveries = async (wh: WebhookSubscription) => {
  selectedWebhook.value = wh
  deliveries.value = []
  deliveryLoading.value = true
  try {
    const { data } = await axios.get(`/api/v1/webhooks/${wh.id}/deliveries`)
    deliveries.value = data.data?.data ?? data.data ?? []
  } catch {
    showToast('Gagal memuat log pengiriman.', 'error')
  } finally {
    deliveryLoading.value = false
  }
}

const replayDelivery = async (delivery: WebhookDelivery) => {
  replayingId.value = delivery.id
  try {
    await axios.post(`/api/v1/webhook-deliveries/${delivery.id}/replay`)
    showToast('Pengiriman ulang dijadwalkan.')
    if (selectedWebhook.value) await openDeliveries(selectedWebhook.value)
  } catch {
    showToast('Gagal replay pengiriman.', 'error')
  } finally {
    replayingId.value = null
  }
}

/* ── Utils ─────────────────────────────────────────── */
const fmtDate = (d: string | null) => {
  if (!d) return '–'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const scopeLabel = (s: string) => AVAILABLE_SCOPES.find(x => x.value === s)?.label ?? s

/* ── Init ──────────────────────────────────────────── */
onMounted(() => {
  fetchCredentials()
  fetchWebhooks()
})
</script>

<template>
  <section class="integ-page">
    <!-- Toast -->
    <Teleport to="body">
      <div v-if="toastMessage" class="integ-toast" :class="toastType">
        <CheckCircle2 v-if="toastType === 'success'" :size="15" />
        <AlertCircle v-else :size="15" />
        {{ toastMessage }}
      </div>
    </Teleport>

    <!-- One-time secret banner -->
    <Teleport to="body">
      <div v-if="revealedSecret" class="secret-overlay" @click.self="dismissSecret">
        <div class="secret-modal">
          <div class="secret-modal-head">
            <div class="secret-icon"><Shield :size="20" /></div>
            <div>
              <h3>Simpan Token Ini Sekarang</h3>
              <p>Token ini hanya ditampilkan sekali dan tidak dapat dipulihkan.</p>
            </div>
            <button class="secret-close" @click="dismissSecret"><X :size="16" /></button>
          </div>
          <div class="secret-token-wrap">
            <code class="secret-token">{{ showSecret ? revealedSecret : revealedSecret.replace(/./g, '•') }}</code>
            <div class="secret-actions">
              <button class="sac-btn" @click="showSecret = !showSecret">
                <Eye v-if="!showSecret" :size="14" /> <EyeOff v-else :size="14" />
                {{ showSecret ? 'Sembunyikan' : 'Tampilkan' }}
              </button>
              <button class="sac-btn primary" @click="copyToken(revealedSecret!)">
                <Copy :size="14" /> Salin
              </button>
            </div>
          </div>
          <p class="secret-warn"><AlertTriangle :size="13" /> Setelah menutup dialog ini, token tidak dapat dilihat lagi.</p>
          <button class="secret-dismiss-btn" @click="dismissSecret">Saya sudah menyimpan token</button>
        </div>
      </div>
    </Teleport>

    <!-- Page header -->
    <div class="page-heading">
      <div>
        <p class="eyebrow">KONFIGURASI / KONEKSI</p>
        <h1>Integrasi</h1>
        <p>Kelola kredensial API dan webhook yang menghubungkan Stockroom ke sistem eksternal.</p>
      </div>
    </div>

    <!-- Stats row -->
    <div class="integ-stats">
      <div class="istat">
        <span><KeyRound :size="15" /></span>
        <div>
          <strong>{{ activeCredCount }}</strong>
          <small>Kunci aktif</small>
        </div>
      </div>
      <div class="istat">
        <span><Webhook :size="15" /></span>
        <div>
          <strong>{{ webhooks.filter(w => w.is_active).length }}</strong>
          <small>Webhook aktif</small>
        </div>
      </div>
      <div class="istat">
        <span><Activity :size="15" /></span>
        <div>
          <strong>{{ credentials.length + webhooks.length }}</strong>
          <small>Total endpoint</small>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="integ-tabs">
      <button :class="['tab-btn', { active: activeTab === 'credentials' }]" @click="activeTab = 'credentials'">
        <KeyRound :size="14" /> Kredensial API
      </button>
      <button :class="['tab-btn', { active: activeTab === 'webhooks' }]" @click="activeTab = 'webhooks'">
        <Webhook :size="14" /> Webhook
      </button>
    </div>

    <!-- ══ TAB: CREDENTIALS ══════════════════════════════ -->
    <div v-show="activeTab === 'credentials'" class="tab-panel">
      <div class="panel-header">
        <div>
          <h2>Kunci Layanan (Service Credentials)</h2>
          <p>Token autentikasi untuk sistem eksternal seperti billing, FTTH, atau integrasi kustom.</p>
        </div>
        <button class="primary-button" @click="showCreateCred = true">
          <Plus :size="15" /> Buat Kredensial
        </button>
      </div>

      <!-- Loading -->
      <div v-if="credLoading" class="integ-loading">
        <Loader2 :size="22" class="spin" /> Memuat…
      </div>

      <!-- Empty -->
      <div v-else-if="!credentials.length" class="integ-empty">
        <KeyRound :size="32" />
        <p>Belum ada kredensial. Buat kunci pertama untuk mengintegrasikan sistem eksternal.</p>
        <button class="primary-button" @click="showCreateCred = true"><Plus :size="14" /> Buat Kredensial</button>
      </div>

      <!-- List -->
      <div v-else class="cred-list">
        <div v-for="cred in credentials" :key="cred.id" class="cred-row" :class="{ inactive: !cred.is_active }">
          <div class="cred-left">
            <div class="cred-icon" :class="cred.client_type">
              <Shield :size="16" />
            </div>
            <div class="cred-info">
              <div class="cred-name-row">
                <strong>{{ cred.name }}</strong>
                <span class="cred-type-tag">{{ cred.client_type }}</span>
                <span class="status-pill" :class="cred.is_active ? 'green' : 'gray'">
                  <CheckCircle2 v-if="cred.is_active" :size="11" />
                  {{ cred.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
              </div>
              <code class="key-id">{{ cred.key_id }}</code>
              <div class="cred-meta-row">
                <span v-for="scope in cred.scopes" :key="scope" class="scope-tag">{{ scopeLabel(scope) }}</span>
              </div>
              <div class="cred-dates">
                <span><Clock :size="11" /> Dibuat {{ fmtDate(cred.created_at) }}</span>
                <span v-if="cred.last_used_at"><Zap :size="11" /> Terakhir dipakai {{ fmtDate(cred.last_used_at) }}</span>
                <span v-if="cred.expires_at" class="expires"><AlertTriangle :size="11" /> Kedaluwarsa {{ fmtDate(cred.expires_at) }}</span>
              </div>
            </div>
          </div>
          <div class="cred-actions">
            <button
              class="action-btn"
              :disabled="togglingId === cred.id"
              @click="toggleActive(cred)"
              :title="cred.is_active ? 'Nonaktifkan' : 'Aktifkan'"
            >
              <Loader2 v-if="togglingId === cred.id" :size="14" class="spin" />
              <SlidersHorizontal v-else :size="14" />
            </button>
            <button
              class="action-btn"
              :disabled="rotatingId === cred.id"
              @click="rotateCredential(cred)"
              title="Rotate token"
            >
              <Loader2 v-if="rotatingId === cred.id" :size="14" class="spin" />
              <RotateCcw v-else :size="14" />
            </button>
            <button
              class="action-btn danger"
              :disabled="deletingId === cred.id"
              @click="deleteCred(cred)"
              title="Hapus"
            >
              <Loader2 v-if="deletingId === cred.id" :size="14" class="spin" />
              <Trash2 v-else :size="14" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ TAB: WEBHOOKS ════════════════════════════════ -->
    <div v-show="activeTab === 'webhooks'" class="tab-panel">
      <div class="panel-header">
        <div>
          <h2>Webhook Subscriptions</h2>
          <p>Kirim notifikasi real-time ke endpoint Anda ketika event terjadi di Stockroom.</p>
        </div>
        <button class="primary-button" @click="showCreateWebhook = true">
          <Plus :size="15" /> Buat Webhook
        </button>
      </div>

      <div v-if="webhookLoading" class="integ-loading">
        <Loader2 :size="22" class="spin" /> Memuat…
      </div>

      <div v-else-if="!webhooks.length" class="integ-empty">
        <Webhook :size="32" />
        <p>Belum ada webhook. Hubungkan sistem Anda untuk menerima event secara real-time.</p>
        <button class="primary-button" @click="showCreateWebhook = true"><Plus :size="14" /> Buat Webhook</button>
      </div>

      <div v-else class="cred-list">
        <div v-for="wh in webhooks" :key="wh.id" class="cred-row" :class="{ inactive: !wh.is_active }">
          <div class="cred-left">
            <div class="cred-icon webhook-icon">
              <Webhook :size="16" />
            </div>
            <div class="cred-info">
              <div class="cred-name-row">
                <strong>{{ wh.name }}</strong>
                <span class="status-pill" :class="wh.is_active ? 'green' : 'gray'">
                  <CheckCircle2 v-if="wh.is_active" :size="11" />
                  {{ wh.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
              </div>
              <code class="key-id">{{ wh.url }}</code>
              <div class="cred-meta-row">
                <span v-for="ev in wh.events" :key="ev" class="scope-tag event-tag">{{ ev }}</span>
              </div>
              <div class="cred-dates">
                <span><Clock :size="11" /> Dibuat {{ fmtDate(wh.created_at) }}</span>
              </div>
            </div>
          </div>
          <div class="cred-actions">
            <button class="action-btn" @click="openDeliveries(wh)" title="Log pengiriman">
              <Activity :size="14" />
            </button>
            <button
              class="action-btn"
              :disabled="togglingWebhookId === wh.id"
              @click="toggleWebhookActive(wh)"
            >
              <Loader2 v-if="togglingWebhookId === wh.id" :size="14" class="spin" />
              <SlidersHorizontal v-else :size="14" />
            </button>
            <button
              class="action-btn danger"
              :disabled="deletingWebhookId === wh.id"
              @click="deleteWebhook(wh)"
            >
              <Loader2 v-if="deletingWebhookId === wh.id" :size="14" class="spin" />
              <Trash2 v-else :size="14" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODAL: Create Credential ═════════════════════ -->
    <Teleport to="body">
      <div v-if="showCreateCred" class="modal-overlay" @click.self="showCreateCred = false">
        <div class="integ-modal">
          <div class="integ-modal-head">
            <div><KeyRound :size="17" /> Buat Kredensial Baru</div>
            <button @click="showCreateCred = false"><X :size="16" /></button>
          </div>
          <div class="integ-modal-body">
            <label class="form-label">
              Nama <span class="req">*</span>
              <input v-model="newCred.name" class="form-input" placeholder="cth. Billing Service" />
            </label>
            <label class="form-label">
              Tipe Klien
              <select v-model="newCred.client_type" class="form-input">
                <option value="external">Eksternal</option>
                <option value="internal">Internal</option>
              </select>
            </label>
            <div class="form-label">
              Scope Akses <span class="req">*</span>
              <div class="scope-grid">
                <label v-for="s in AVAILABLE_SCOPES" :key="s.value" class="scope-check">
                  <input type="checkbox" :checked="newCred.scopes.includes(s.value)" @change="toggleScope(s.value)" />
                  {{ s.label }}
                </label>
              </div>
            </div>
            <label class="form-label">
              Kedaluwarsa (opsional)
              <input v-model="newCred.expires_at" type="datetime-local" class="form-input" />
            </label>
          </div>
          <div class="integ-modal-foot">
            <button class="ghost-btn" @click="showCreateCred = false">Batal</button>
            <button class="primary-button" :disabled="createCredLoading" @click="createCredential">
              <Loader2 v-if="createCredLoading" :size="14" class="spin" />
              <Plus v-else :size="14" />
              Buat Kredensial
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ══ MODAL: Create Webhook ═════════════════════════ -->
    <Teleport to="body">
      <div v-if="showCreateWebhook" class="modal-overlay" @click.self="showCreateWebhook = false">
        <div class="integ-modal">
          <div class="integ-modal-head">
            <div><Webhook :size="17" /> Buat Webhook Baru</div>
            <button @click="showCreateWebhook = false"><X :size="16" /></button>
          </div>
          <div class="integ-modal-body">
            <label class="form-label">
              Nama <span class="req">*</span>
              <input v-model="newWebhook.name" class="form-input" placeholder="cth. Order Sync" />
            </label>
            <label class="form-label">
              URL Endpoint <span class="req">*</span>
              <input v-model="newWebhook.url" class="form-input" placeholder="https://your-app.com/webhooks" />
            </label>
            <div class="form-label">
              Events <span class="req">*</span>
              <div class="scope-grid events-grid">
                <label v-for="ev in EVENT_OPTIONS" :key="ev" class="scope-check">
                  <input type="checkbox" :checked="newWebhook.events.includes(ev)" @change="toggleEvent(ev)" />
                  {{ ev }}
                </label>
              </div>
            </div>
          </div>
          <div class="integ-modal-foot">
            <button class="ghost-btn" @click="showCreateWebhook = false">Batal</button>
            <button class="primary-button" :disabled="createWebhookLoading" @click="createWebhook">
              <Loader2 v-if="createWebhookLoading" :size="14" class="spin" />
              <Plus v-else :size="14" />
              Buat Webhook
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ══ DRAWER: Delivery Logs ════════════════════════ -->
    <Teleport to="body">
      <div v-if="selectedWebhook" class="modal-overlay" @click.self="selectedWebhook = null">
        <div class="integ-modal deliveries-modal">
          <div class="integ-modal-head">
            <div><Activity :size="17" /> Log — {{ selectedWebhook.name }}</div>
            <button @click="selectedWebhook = null"><X :size="16" /></button>
          </div>
          <div class="integ-modal-body deliveries-body">
            <div v-if="deliveryLoading" class="integ-loading"><Loader2 :size="18" class="spin" /> Memuat log…</div>
            <div v-else-if="!deliveries.length" class="integ-empty small">
              <Activity :size="24" /><p>Belum ada pengiriman tercatat.</p>
            </div>
            <div v-else class="delivery-list">
              <div v-for="d in deliveries" :key="d.id" class="delivery-row">
                <span class="delivery-status" :class="d.status.toLowerCase()">
                  <CheckCircle2 v-if="d.status === 'SUCCESS'" :size="13" />
                  <AlertCircle v-else-if="d.status === 'FAILED'" :size="13" />
                  <Clock v-else :size="13" />
                  {{ d.status }}
                </span>
                <div class="delivery-meta">
                  <strong>{{ d.event_type }}</strong>
                  <small>HTTP {{ d.http_status ?? '–' }} · {{ d.attempts }} percobaan · {{ fmtDate(d.created_at) }}</small>
                </div>
                <button
                  class="action-btn"
                  :disabled="replayingId === d.id"
                  @click="replayDelivery(d)"
                  title="Kirim ulang"
                >
                  <Loader2 v-if="replayingId === d.id" :size="13" class="spin" />
                  <RefreshCw v-else :size="13" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
/* ── Page ──────────────────────────────────────────── */
.integ-page { display: block; }

/* ── Toast ─────────────────────────────────────────── */
.integ-toast {
  position: fixed; bottom: 24px; right: 24px; z-index: 9999;
  display: flex; align-items: center; gap: 8px;
  padding: 11px 18px; border-radius: 10px;
  font-size: 12px; font-weight: 500;
  box-shadow: 0 4px 24px rgba(0,0,0,.14);
  animation: toast-in .22s ease;
  pointer-events: none;
}
.integ-toast.success { background: #eaf8f1; color: #2d7a56; border: 1px solid #b6ebd1; }
.integ-toast.error   { background: #fff2f2; color: #c0392b; border: 1px solid #fbc5c5; }
@keyframes toast-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* ── Stats ─────────────────────────────────────────── */
.integ-stats {
  display: flex; gap: 14px; margin-bottom: 22px;
}
.istat {
  display: flex; align-items: center; gap: 12px;
  background: #fff; border: 1px solid #eeedf3; border-radius: 10px;
  padding: 13px 18px; flex: 1;
}
.istat span {
  display: grid; place-items: center; width: 34px; height: 34px;
  border-radius: 8px; background: #f4f5f8; color: #6e6b82;
}
.istat strong { display: block; font-size: 20px; font-weight: 700; letter-spacing: -.04em; color: #292541; }
.istat small  { display: block; font-size: 10px; color: #aaa7b4; margin-top: 2px; }

/* ── Tabs ──────────────────────────────────────────── */
.integ-tabs {
  display: flex; gap: 4px; margin-bottom: 18px;
  border-bottom: 1px solid #eeedf3; padding-bottom: 0;
}
.tab-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 16px; border: 0; background: transparent;
  font: inherit; font-size: 12px; font-weight: 500;
  color: #aaa7b4; cursor: pointer; border-radius: 8px 8px 0 0;
  position: relative; bottom: -1px;
  border-bottom: 2px solid transparent;
  transition: color .18s, border-color .18s;
}
.tab-btn:hover { color: #6e6b82; }
.tab-btn.active { color: #bd3c3c; border-bottom-color: #bd3c3c; background: #fff; }

/* ── Tab panel ─────────────────────────────────────── */
.tab-panel { background: #fff; border: 1px solid #eeedf3; border-radius: 12px; overflow: hidden; }
.panel-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  padding: 18px 20px 16px; border-bottom: 1px solid #f0eff3;
}
.panel-header h2 { margin: 0; font-size: 13.5px; color: #292541; }
.panel-header p  { margin: 4px 0 0; font-size: 11px; color: #858291; }

/* ── Loading / Empty ───────────────────────────────── */
.integ-loading {
  display: flex; align-items: center; justify-content: center; gap: 10px;
  padding: 48px; font-size: 13px; color: #aaa7b4;
}
.integ-empty {
  display: flex; flex-direction: column; align-items: center;
  gap: 12px; padding: 56px 24px; color: #c4c1d0; text-align: center;
}
.integ-empty p { font-size: 12px; color: #aaa7b4; margin: 0; max-width: 320px; }
.integ-empty.small { padding: 32px; }

/* ── Credential rows ───────────────────────────────── */
.cred-list { display: flex; flex-direction: column; }
.cred-row {
  display: flex; align-items: flex-start; gap: 16px;
  padding: 16px 20px; border-bottom: 1px solid #f4f3f8;
  transition: background .15s;
}
.cred-row:last-child { border-bottom: 0; }
.cred-row:hover { background: #fafafd; }
.cred-row.inactive { opacity: .6; }

.cred-left { display: flex; gap: 14px; flex: 1; min-width: 0; }

.cred-icon {
  display: grid; place-items: center; flex-shrink: 0;
  width: 36px; height: 36px; border-radius: 8px;
  background: #f0edff; color: #6c47d8;
}
.cred-icon.internal  { background: #fff0e8; color: #d07a25; }
.cred-icon.webhook-icon { background: #e8f4ff; color: #2d7db5; }

.cred-info { flex: 1; min-width: 0; }
.cred-name-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 5px; }
.cred-name-row strong { font-size: 12.5px; color: #292541; }

.cred-type-tag {
  font-size: 9px; font-weight: 600; padding: 2px 6px;
  border-radius: 3px; background: #f0f0f6; color: #6e6b82; text-transform: uppercase;
}

.status-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 8px; border-radius: 4px; font-size: 9px; font-weight: 600;
}
.status-pill.green { background: #eaf8f1; color: #2d7a56; }
.status-pill.gray  { background: #f0f0f6; color: #aaa7b4; }

.key-id {
  display: block; font-size: 10px; font-family: 'Fira Code', monospace;
  color: #7c7992; background: #f6f5fb; border-radius: 4px;
  padding: 3px 7px; margin-bottom: 8px; width: fit-content; max-width: 100%;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.cred-meta-row { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 8px; }
.scope-tag {
  font-size: 9px; padding: 2px 7px; border-radius: 3px;
  background: #f0edff; color: #6c47d8; font-weight: 500;
}
.event-tag { background: #e8f4ff; color: #2d7db5; }

.cred-dates { display: flex; flex-wrap: wrap; gap: 10px; }
.cred-dates span { display: inline-flex; align-items: center; gap: 4px; font-size: 9px; color: #aaa7b4; }
.cred-dates .expires { color: #d07a25; }

.cred-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.action-btn {
  display: grid; place-items: center; width: 30px; height: 30px;
  border: 1px solid #eeedf3; border-radius: 7px; background: #fff;
  color: #858291; cursor: pointer; transition: all .15s;
}
.action-btn:hover { border-color: #d0cede; background: #f8f7fc; color: #292541; }
.action-btn.danger:hover { border-color: #f5c5c5; background: #fff2f2; color: #c0392b; }
.action-btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Modals ────────────────────────────────────────── */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(18,15,36,.45);
  backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
  z-index: 2000; padding: 24px;
}
.integ-modal {
  background: #fff; border-radius: 14px;
  width: 100%; max-width: 500px;
  box-shadow: 0 20px 60px rgba(0,0,0,.18);
  display: flex; flex-direction: column; max-height: 90vh;
  animation: modal-in .22s ease;
}
@keyframes modal-in { from { opacity: 0; transform: translateY(16px) scale(.97); } to { opacity: 1; transform: none; } }

.deliveries-modal { max-width: 640px; }

.integ-modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px; border-bottom: 1px solid #f0eff3;
  font-size: 13px; font-weight: 600; color: #292541;
}
.integ-modal-head div { display: flex; align-items: center; gap: 8px; }
.integ-modal-head button {
  border: 0; background: transparent; color: #aaa7b4; cursor: pointer;
  display: grid; place-items: center; padding: 4px;
  border-radius: 6px; transition: background .15s;
}
.integ-modal-head button:hover { background: #f0eff3; color: #6e6b82; }

.integ-modal-body {
  padding: 20px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 14px;
}
.deliveries-body { gap: 0; padding: 0; }

.integ-modal-foot {
  display: flex; align-items: center; justify-content: flex-end; gap: 10px;
  padding: 14px 20px; border-top: 1px solid #f0eff3;
}

/* ── Form elements in modal ────────────────────────── */
.form-label { display: flex; flex-direction: column; gap: 6px; font-size: 11px; font-weight: 600; color: #6e6b82; }
.form-label .req { color: #bd3c3c; }
.form-input {
  padding: 9px 12px; border: 1px solid #dddbe6; border-radius: 8px;
  font: inherit; font-size: 12px; color: #292541;
  background: #fafafd; outline: none; transition: border-color .15s;
}
.form-input:focus { border-color: #bd3c3c; }

.scope-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 4px;
}
.events-grid { grid-template-columns: 1fr 1fr; }
.scope-check {
  display: flex; align-items: center; gap: 7px;
  font-size: 11px; font-weight: 400; color: #4a4762; cursor: pointer;
  padding: 7px 10px; border-radius: 7px; background: #f6f5fb;
  border: 1px solid transparent; transition: all .14s;
}
.scope-check:has(input:checked) { background: #f0edff; border-color: #c4b3ff; color: #5833c8; }
.scope-check input { accent-color: #6c47d8; width: 13px; height: 13px; }

.ghost-btn {
  padding: 8px 14px; border: 1px solid #dddbe6; border-radius: 8px;
  background: transparent; font: inherit; font-size: 12px; color: #6e6b82;
  cursor: pointer; transition: all .15s;
}
.ghost-btn:hover { background: #f6f5fb; }

/* ── One-time Secret Modal ─────────────────────────── */
.secret-overlay {
  position: fixed; inset: 0; background: rgba(18,15,36,.6);
  backdrop-filter: blur(6px);
  display: flex; align-items: center; justify-content: center;
  z-index: 3000; padding: 24px;
}
.secret-modal {
  background: #fff; border-radius: 16px; width: 100%; max-width: 480px;
  padding: 24px; box-shadow: 0 24px 64px rgba(0,0,0,.22);
  animation: modal-in .22s ease;
}
.secret-modal-head {
  display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;
}
.secret-icon {
  display: grid; place-items: center; width: 40px; height: 40px; flex-shrink: 0;
  border-radius: 10px; background: #fff0e8; color: #d07a25;
}
.secret-modal-head h3 { margin: 0 0 4px; font-size: 14px; color: #292541; }
.secret-modal-head p  { margin: 0; font-size: 11px; color: #858291; }
.secret-close {
  margin-left: auto; border: 0; background: transparent; cursor: pointer;
  color: #aaa7b4; padding: 4px; border-radius: 6px; display: grid; place-items: center;
}
.secret-close:hover { background: #f0eff3; }

.secret-token-wrap {
  background: #0f0e1a; border-radius: 10px; padding: 16px;
  display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px;
}
.secret-token {
  font-family: 'Fira Code', monospace; font-size: 11px; color: #a8e6c8;
  word-break: break-all; line-height: 1.6; display: block;
}
.secret-actions { display: flex; gap: 8px; }
.sac-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 12px; border: 1px solid rgba(255,255,255,.15); border-radius: 7px;
  background: rgba(255,255,255,.08); color: #d4d1e8;
  font: inherit; font-size: 11px; cursor: pointer; transition: all .15s;
}
.sac-btn:hover { background: rgba(255,255,255,.14); }
.sac-btn.primary { background: #22c55e22; border-color: #22c55e55; color: #4ade80; }
.sac-btn.primary:hover { background: #22c55e33; }

.secret-warn {
  display: flex; align-items: center; gap: 6px;
  font-size: 10px; color: #d07a25; margin: 0 0 16px;
  padding: 9px 12px; border-radius: 8px; background: #fff7ed; border: 1px solid #fed7aa;
}
.secret-dismiss-btn {
  display: block; width: 100%; padding: 10px;
  background: #292541; color: #fff; border: 0; border-radius: 9px;
  font: inherit; font-size: 12px; font-weight: 600; cursor: pointer;
  transition: background .15s;
}
.secret-dismiss-btn:hover { background: #3d3858; }

/* ── Delivery rows ─────────────────────────────────── */
.delivery-list { display: flex; flex-direction: column; }
.delivery-row {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 20px; border-bottom: 1px solid #f4f3f8;
}
.delivery-row:last-child { border-bottom: 0; }
.delivery-status {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 9px; border-radius: 5px; font-size: 9px; font-weight: 700;
  white-space: nowrap; flex-shrink: 0;
}
.delivery-status.success { background: #eaf8f1; color: #2d7a56; }
.delivery-status.failed  { background: #fff2f2; color: #c0392b; }
.delivery-status.pending { background: #fef9ec; color: #d07a25; }
.delivery-meta { flex: 1; }
.delivery-meta strong { display: block; font-size: 11px; color: #292541; }
.delivery-meta small   { display: block; font-size: 9px; color: #aaa7b4; margin-top: 2px; }

/* ── Spin ──────────────────────────────────────────── */
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Responsive ────────────────────────────────────── */
@media (max-width: 680px) {
  .integ-stats { flex-direction: column; }
  .scope-grid, .events-grid { grid-template-columns: 1fr; }
  .cred-row { flex-wrap: wrap; }
  .cred-actions { width: 100%; justify-content: flex-end; }
}
</style>
