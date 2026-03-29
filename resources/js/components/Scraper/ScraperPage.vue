<template>
  <div class="scraper-container">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
      <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="/series-infos">
          📺 CinéHub
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
          <div class="navbar-nav ms-auto">
            <a class="nav-link" href="/series-infos">📺 مكتبتي</a>
            <a class="nav-link active" href="#scraper">🔍 اكتشف</a>
            <button @click="logout" class="nav-link btn btn-link">🚪 خروج</button>
          </div>
        </div>
      </div>
    </nav>

    <div class="container py-5">
      <!-- Header -->
      <header>
        <h1>🎬 جميع المسلسلات</h1>
        <p>اكتشف أفضل مسلسلات الدراما والإثارة</p>
      </header>

      <!-- Notifications -->
      <transition-group name="notification" tag="div" class="notifications-container">
        <div
          v-for="notif in notifications"
          :key="notif.id"
          :class="['notification', `notification-${notif.type}`]"
        >
          {{ notif.message }}
        </div>
      </transition-group>

      <!-- Controls -->
      <div class="controls">
        <button
          @click="scraper.scrape()"
          :disabled="scraper.isScraping"
          class="btn-action"
        >
          <i class="fas fa-rocket"></i> تحديث المسلسلات
        </button>
        <button
          @click="scraper.clearAllCache()"
          :disabled="scraper.isScraping"
          class="btn-action btn-clear"
        >
          <i class="fas fa-trash-alt"></i> مسح الذاكرة
        </button>
        <a href="/series-infos" class="btn-action" style="text-decoration: none;">
          <i class="fas fa-heart"></i> مفضلاتي
        </a>
      </div>

      <div v-if="scraper.cacheInfo" class="cache-info">
        {{ scraper.cacheInfo }}
      </div>

      <!-- Progress Bar -->
      <transition name="fade">
        <div v-if="scraper.isScraping" class="loading-container">
          <div class="spinner"></div>
          <div class="progress-container">
            <div class="progress" role="progressbar">
              <div
                class="progress-bar progress-bar-striped progress-bar-animated"
                :style="{ width: scraper.progressPercent + '%' }"
              ></div>
            </div>
            <div class="progress-text">
              {{ scraper.progressMessage }}
            </div>
          </div>
        </div>
      </transition>

      <!-- Stats -->
      <div class="stats">
        <div class="stat">
          <div class="stat-number">{{ scraper.seriesCount }}</div>
          <div class="stat-label">مسلسل متاح</div>
        </div>
      </div>

      <!-- Grid -->
      <transition-group v-if="scraper.seriesCount > 0" name="grid" tag="div" class="grid">
        <SerieCard
          v-for="(serie, index) in scraper.series"
          :key="`${serie.url}-${serie.titre}`"
          :serie="serie"
          :index="index"
        />
      </transition-group>

      <!-- Empty State -->
      <div v-else class="empty-state">
        <div class="empty-state-icon">🍿</div>
        <h2>لا توجد مسلسلات بعد</h2>
        <p>اضغط على زر "تحديث المسلسلات" لاكتشاف المحتوى الجديد</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { useScraper } from '@/composables/useScraper';
import { useNotifications } from '@/composables/useNotifications';
import SerieCard from './SerieCard.vue';

const scraper = useScraper();
const { notifications } = useNotifications();

/**
 * Chargement initial
 */
onMounted(async () => {
  await scraper.loadInitialData();
});

/**
 * Se déconnecter
 */
const logout = () => {
  const form = document.querySelector('form[action*="logout"]');
  if (form) form.submit();
};
</script>

<style scoped>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

html, body {
  height: 100%;
}

.scraper-container {
  background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 100%);
  min-height: 100vh;
  direction: rtl;
  font-family: 'Segoe UI', 'Helvetica Neue', sans-serif;
  color: #fff;
}

/* Navbar Netflix Style */
.navbar-custom {
  background: linear-gradient(180deg, rgba(0,0,0,0.9) 0%, rgba(0,0,0,0.7) 100%);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
}

.navbar-brand {
  font-size: 1.8em !important;
  font-weight: 900 !important;
  background: linear-gradient(90deg, #e50914 0%, #ff6b6b 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  letter-spacing: 2px;
}

.nav-link {
  color: rgba(255,255,255,0.7) !important;
  font-weight: 600;
  transition: all 0.3s ease;
  margin-left: 15px;
}

.nav-link:hover,
.nav-link.active {
  color: #fff !important;
  text-shadow: 0 0 10px rgba(229, 9, 20, 0.5);
}

/* Header */
header {
  text-align: center;
  margin: 50px 0 40px;
  padding: 0 20px;
}

header h1 {
  font-size: clamp(2em, 5vw, 3.5em);
  font-weight: 900;
  text-shadow: 0 4px 20px rgba(229, 9, 20, 0.5);
  letter-spacing: 1px;
  margin-bottom: 10px;
  background: linear-gradient(90deg, #fff 0%, #e8e8e8 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

header p {
  font-size: 1.1em;
  color: rgba(255,255,255,0.6);
  letter-spacing: 0.5px;
}

/* Notifications Container */
.notifications-container {
  position: fixed;
  top: 80px;
  right: 20px;
  z-index: 1000;
  max-width: 400px;
}

.notification {
  margin-bottom: 10px;
  padding: 12px 20px;
  border-radius: 8px;
  animation: slideInRight 0.3s ease;
  font-weight: 500;
}

.notification-success {
  background: rgba(34, 197, 94, 0.2);
  color: #22c55e;
  border-left: 4px solid #22c55e;
}

.notification-error {
  background: rgba(220, 20, 60, 0.2);
  color: #ff6b6b;
  border-left: 4px solid #dc143c;
}

.notification-info {
  background: rgba(59, 130, 246, 0.2);
  color: #3b82f6;
  border-left: 4px solid #3b82f6;
}

.notification-warning {
  background: rgba(245, 158, 11, 0.2);
  color: #fbbf24;
  border-left: 4px solid #f59e0b;
}

@keyframes slideInRight {
  from {
    transform: translateX(400px);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

/* Controls */
.controls {
  display: flex;
  gap: 15px;
  justify-content: center;
  margin-bottom: 40px;
  flex-wrap: wrap;
  padding: 0 20px;
}

.btn-action {
  padding: 12px 28px;
  border-radius: 6px;
  border: none;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.3s ease;
  font-size: 1em;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  white-space: nowrap;
}

.btn-action:not(.btn-clear) {
  background: linear-gradient(90deg, #e50914 0%, #ff6b6b 100%);
  color: white;
  box-shadow: 0 8px 24px rgba(229, 9, 20, 0.35);
}

.btn-action:not(.btn-clear):hover:not(:disabled) {
  transform: translateY(-3px);
  box-shadow: 0 12px 32px rgba(229, 9, 20, 0.5);
  background: linear-gradient(90deg, #ff4757 0%, #ff6b6b 100%);
}

.btn-action:not(.btn-clear):active:not(:disabled) {
  transform: translateY(-1px);
}

.btn-clear {
  background: rgba(255, 255, 255, 0.1);
  color: white;
  border: 2px solid rgba(255, 255, 255, 0.3);
  backdrop-filter: blur(10px);
}

.btn-clear:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.2);
  border-color: rgba(255, 255, 255, 0.6);
  box-shadow: 0 8px 24px rgba(255, 255, 255, 0.2);
}

.btn-action:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-action i {
  margin-right: 8px;
}

/* Cache Info */
.cache-info {
  text-align: center;
  color: rgba(255,255,255,0.6);
  font-size: 0.95em;
  margin-top: 15px;
  letter-spacing: 0.5px;
}

/* Loading */
.loading-container {
  text-align: center;
  margin: 40px 0;
}

.spinner {
  border: 4px solid rgba(229, 9, 20, 0.2);
  border-top: 4px solid #e50914;
  border-radius: 50%;
  width: 60px;
  height: 60px;
  animation: spin 1s linear infinite;
  margin: 0 auto 20px;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.progress-container {
  max-width: 500px;
  margin: 0 auto;
}

.progress {
  background: rgba(255,255,255,0.1);
  border-radius: 10px;
  height: 8px;
  overflow: hidden;
}

.progress-bar {
  background: linear-gradient(90deg, #e50914 0%, #ff6b6b 100%);
  height: 100%;
}

.progress-text {
  color: rgba(255,255,255,0.7);
  font-size: 0.9em;
  margin-top: 12px;
  letter-spacing: 0.5px;
}

/* Stats */
.stats {
  display: flex;
  justify-content: center;
  gap: 40px;
  margin: 40px 0;
  color: white;
  flex-wrap: wrap;
  padding: 30px 20px;
  background: rgba(255,255,255,0.05);
  border-radius: 12px;
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255,255,255,0.1);
}

.stat {
  text-align: center;
}

.stat-number {
  font-size: 3em;
  font-weight: 900;
  color: #e50914;
  text-shadow: 0 0 20px rgba(229, 9, 20, 0.5);
}

.stat-label {
  font-size: 1em;
  color: rgba(255,255,255,0.7);
  margin-top: 8px;
  letter-spacing: 1px;
}

/* Grid */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 15px;
  margin: 40px 20px;
  padding: 0;
}

/* Empty State */
.empty-state {
  text-align: center;
  color: rgba(255,255,255,0.8);
  padding: 60px 20px;
}

.empty-state-icon {
  font-size: 5em;
  margin-bottom: 20px;
  opacity: 0.5;
}

.empty-state h2 {
  font-size: 2em;
  margin-bottom: 10px;
  color: rgba(255,255,255,0.7);
}

.empty-state p {
  font-size: 1.1em;
  color: rgba(255,255,255,0.5);
}

/* Scrollbar */
::-webkit-scrollbar {
  width: 12px;
}

::-webkit-scrollbar-track {
  background: rgba(0,0,0,0.5);
}

::-webkit-scrollbar-thumb {
  background: #e50914;
  border-radius: 6px;
}

::-webkit-scrollbar-thumb:hover {
  background: #ff6b6b;
}

/* Responsive */
@media (max-width: 1200px) {
  .grid {
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
  }
}

@media (max-width: 768px) {
  header h1 {
    font-size: 2.2em;
  }

  .grid {
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px;
    margin: 30px 10px;
  }

  .controls {
    gap: 10px;
    margin-bottom: 30px;
  }

  .btn-action {
    padding: 10px 16px;
    font-size: 0.85em;
  }

  .stats {
    gap: 20px;
    padding: 20px 10px;
  }

  .stat-number {
    font-size: 2.2em;
  }

  .notifications-container {
    right: 10px;
    max-width: calc(100% - 20px);
  }
}

@media (max-width: 480px) {
  header {
    margin: 30px 0 30px;
  }

  header h1 {
    font-size: 1.8em;
  }

  header p {
    font-size: 0.9em;
  }

  .grid {
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 8px;
    margin: 20px 5px;
  }

  .controls {
    gap: 8px;
    flex-direction: column;
    margin-bottom: 20px;
  }

  .btn-action {
    width: 100%;
    padding: 10px 12px;
    font-size: 0.75em;
  }

  .stats {
    flex-direction: column;
    gap: 15px;
    padding: 15px 10px;
  }

  .stat-number {
    font-size: 2em;
  }

  .notifications-container {
    position: static;
    max-width: 100%;
    margin: 0 0 20px 0;
  }
}

/* Animations */
.fade-enter-active, .fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from, .fade-leave-to {
  opacity: 0;
}

.notification-enter-active,
.notification-leave-active {
  transition: all 0.3s ease;
}

.notification-enter-from,
.notification-leave-to {
  transform: translateX(400px);
  opacity: 0;
}
</style>
