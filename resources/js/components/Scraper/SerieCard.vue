<template>
  <div class="serie-card" :style="{ animationDelay: `${index * 0.05}s` }">
    <div class="serie-image-wrapper">
      <img
        :src="serie.image"
        :alt="serie.titre"
        class="serie-image"
        @error="handleImageError"
      >
      <div class="serie-overlay">
        <div class="serie-title-overlay">{{ serie.titre }}</div>
        <div class="serie-actions">
          <button
            class="action-btn"
            title="Regarder"
            @click.prevent.stop="openSerie"
          >
            <i class="fas fa-play"></i>
          </button>
          <button
            class="action-btn"
            title="Ajouter à ma collection"
            @click.prevent.stop="handleAddSerie"
            :disabled="isAdding"
          >
            <i class="fas fa-plus"></i>
          </button>
          <button
            class="action-btn"
            title="Plus d'infos"
            @click.prevent.stop="showInfo"
          >
            <i class="fas fa-info-circle"></i>
          </button>
        </div>
      </div>
    </div>
    <div class="serie-title">{{ serie.titre }}</div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useScraper } from '@/composables/useScraper';
import { useNotifications } from '@/composables/useNotifications';

const props = defineProps({
  serie: {
    type: Object,
    required: true,
    validator: (value) => {
      return value.titre && value.url && value.image;
    },
  },
  index: {
    type: Number,
    default: 0,
  },
});

const scraper = useScraper();
const notifications = useNotifications();
const isAdding = ref(false);

// Computed
const isCurrentlyAdding = computed(() => scraper.addingSerieUrl.value === props.serie.url);

/**
 * Gérer l'erreur de chargement d'image
 */
const handleImageError = (event) => {
  event.target.style.background = '#ccc';
  event.target.style.display = 'none';
};

/**
 * Ouvrir la série dans un nouvel onglet
 */
const openSerie = () => {
  window.open(props.serie.url, '_blank');
};

/**
 * Ajouter la série à la collection
 */
const handleAddSerie = async () => {
  isAdding.value = true;
  await scraper.addSeries(props.serie.url, props.serie.titre);
  isAdding.value = false;
};

/**
 * Afficher les infos
 */
const showInfo = () => {
  notifications.info(`📺 ${props.serie.titre}`);
};
</script>

<style scoped>
.serie-card {
  position: relative;
  border-radius: 8px;
  overflow: hidden;
  cursor: pointer;
  text-decoration: none;
  color: inherit;
  display: flex;
  flex-direction: column;
  transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
  transform: scale(1);
  z-index: 1;
  opacity: 0;
  animation: cardAppear 0.6s ease-out forwards;
}

@keyframes cardAppear {
  from {
    opacity: 0;
    transform: scale(0.8) translateY(20px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

.serie-card:hover {
  transform: scale(1.08);
  z-index: 10;
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.8);
}

.serie-image-wrapper {
  position: relative;
  width: 100%;
  aspect-ratio: 2 / 3;
  overflow: hidden;
  background: linear-gradient(135deg, #2a2a2a 0%, #1a1a1a 100%);
}

.serie-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: all 0.4s ease;
}

.serie-card:hover .serie-image {
  transform: scale(1.05) brightness(0.7);
}

/* Overlay on Hover */
.serie-overlay {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  top: 0;
  background: linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.8) 70%, rgba(0,0,0,0.95) 100%);
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: 20px;
  opacity: 0;
  transition: opacity 0.3s ease;
}

.serie-card:hover .serie-overlay {
  opacity: 1;
}

.serie-title-overlay {
  color: white;
  font-size: 1.1em;
  font-weight: 700;
  margin-bottom: 12px;
  line-height: 1.3;
  text-shadow: 0 2px 4px rgba(0,0,0,0.5);
}

.serie-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-start;
  opacity: 0;
  transform: translateY(10px);
  transition: all 0.3s ease 0.1s;
}

.serie-card:hover .serie-actions {
  opacity: 1;
  transform: translateY(0);
}

.action-btn {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 2px solid white;
  background: transparent;
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
  font-size: 0.8em;
}

.action-btn:hover:not(:disabled) {
  background: white;
  color: #1a1a1a;
  transform: scale(1.1);
}

.action-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.serie-title {
  padding: 12px;
  text-align: center;
  font-size: 0.9em;
  color: rgba(255,255,255,0.9);
  line-height: 1.4;
  font-weight: 600;
  background: rgba(0,0,0,0.3);
  min-height: 50px;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
