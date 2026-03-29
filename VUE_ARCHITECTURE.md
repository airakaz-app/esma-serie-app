# 🎯 Architecture Vue.js - Guide Complet

**Status:** ✅ Refactorisée
**Date:** 28 Mars 2026
**Tech Stack:** Vue 3 Composition API + Composables

---

## 📋 Vue d'Ensemble

L'application scraper a été complètement refactorisée en Vue.js 3 avec une architecture moderne, scalable et maintenable.

### **Avant (JavaScript Vanilla)**
```
- Code mélangé (HTML + JS)
- Pas de réutilisabilité
- Refresh de page requis
- Logs dans la console
- Navigation clunky
```

### **Après (Vue 3 + Composables)**
```
✅ Code organisé et modulaire
✅ Composants réutilisables
✅ Zéro refresh de page
✅ Notifications réactives
✅ UX/DX premium
```

---

## 🏗️ Structure du Projet

```
resources/
├── js/
│   ├── app.js                           (Entry point)
│   │
│   ├── composables/
│   │   ├── useApi.js                   (API calls)
│   │   ├── useNotifications.js         (Toast messages)
│   │   ├── useScraper.js               (Scraper logic)
│   │   └── useCache.js                 (Local storage)
│   │
│   └── components/
│       └── Scraper/
│           ├── ScraperPage.vue         (Main component)
│           └── SerieCard.vue           (Series card)
│
└── views/
    └── scraper_vue.blade.php           (Simplified view)
```

---

## 🎨 Composables (Logique Réutilisable)

### **useApi.js - Appels API**

```javascript
const api = useApi();

// Scraper les séries
const result = await api.scrapeSeries();

// Vider le cache
await api.clearCache();

// Ajouter une série
await api.addSerieToCollection(url, episodeStart, episodeEnd);

// Obtenir le statut du scraping
const status = await api.getScrapeStatus(trackingKey);
```

**Responsabilités:**
- Requêtes HTTP avec CSRF token
- Gestion des erreurs
- État de chargement
- Paramètres de l'API

---

### **useNotifications.js - Notifications**

```javascript
const notifs = useNotifications();

// Afficher des notifications
notifs.success('✅ Succès!');
notifs.error('❌ Erreur!');
notifs.warning('⚠️ Attention!');
notifs.info('ℹ️ Info');

// Gestion manuelle
const id = notifs.addNotification('Message', 'success', 3000);
notifs.removeNotification(id);
notifs.clearAll();

// Lister les notifications
notifs.byType('error'); // Toutes les erreurs
```

**Responsabilités:**
- Toast notifications
- Gestion de l'état
- Auto-dismiss après durée

---

### **useScraper.js - Logique du Scraper**

```javascript
const scraper = useScraper();

// État
scraper.series           // Les séries
scraper.isScraping       // En cours?
scraper.seriesCount      // Nombre
scraper.isEmpty          // Vide?
scraper.cacheInfo        // Info cache
scraper.addingSerieUrl   // En ajout?

// Méthodes
await scraper.loadInitialData();           // Charger initial
await scraper.scrape();                     // Scraper
await scraper.addSeries(url, titre);       // Ajouter
await scraper.clearAllCache();             // Vider cache
scraper.updateCacheInfo();                 // Mettre à jour info
scraper.searchSeries(query);               // Chercher
scraper.filterSeries(predicate);           // Filtrer
```

**Responsabilités:**
- Orchestration du scraping
- Gestion de l'état
- Cache management
- Recherche/filtrage

---

### **useCache.js - Stockage Local**

```javascript
const cache = useCache();

// Obtenir les données
const series = cache.getSeriesData();      // Retourne array ou null

// Sauvegarder
cache.setSeriesData(seriesArray);

// Vérifier
cache.hasData();                           // Booléen

// Âge du cache
cache.getCacheAge();                       // Secondes

// Validité
cache.getValidityPercent();                // 0-100%

// Effacer
cache.clear();
```

**Responsabilités:**
- localStorage management
- Validation du cache (24h)
- Sérialisation/désérialisation

---

## 🧩 Composants Vue

### **ScraperPage.vue - Composant Principal**

Tout ce qui était en JavaScript vanilla est maintenant ici:
- Navigation
- Header
- Controls
- Progress bar
- Statistics
- Series grid
- Empty state
- Notifications

**Props:** Aucune (root component)
**Emits:** Aucune
**Uses:** Tous les composables

```vue
<template>
  <div class="scraper-container">
    <!-- Navigation, Header, Controls -->
    <!-- Notifications, Progress Bar -->
    <!-- Statistics -->
    <!-- Series Grid with SerieCard -->
    <!-- Empty State -->
  </div>
</template>

<script setup>
import { useScraper } from '@/composables/useScraper';
import { useNotifications } from '@/composables/useNotifications';
import SerieCard from './SerieCard.vue';

const scraper = useScraper();
const { notifications } = useNotifications();

onMounted(() => scraper.loadInitialData());
</script>
```

---

### **SerieCard.vue - Composant Réutilisable**

Représente une seule série.

**Props:**
```javascript
{
  serie: {              // Object required
    titre: string,
    url: string,
    image: string
  },
  index: number        // Pour l'animation
}
```

**Fonctionnalités:**
- Image avec fallback
- 3 boutons d'action au hover
- Animations
- Gestion erreurs d'image

```vue
<template>
  <div class="serie-card">
    <div class="serie-image-wrapper">
      <img :src="serie.image" />
      <div class="serie-overlay">
        <div class="serie-title-overlay">{{ serie.titre }}</div>
        <div class="serie-actions">
          <button @click="openSerie">🎬 Play</button>
          <button @click="handleAddSerie">➕ Plus</button>
          <button @click="showInfo">ℹ️ Info</button>
        </div>
      </div>
    </div>
    <div class="serie-title">{{ serie.titre }}</div>
  </div>
</template>
```

---

## 🔄 Flux de Données

```
┌─────────────────────────────────────────────┐
│         ScraperPage.vue (Root)              │
│  ┌───────────────────────────────────────┐  │
│  │  useScraper()                         │  │
│  │  ├─ series[]                          │  │
│  │  ├─ isScraping: boolean               │  │
│  │  ├─ progressPercent: number           │  │
│  │  ├─ methods: scrape(), addSeries()    │  │
│  │  └─ useApi(), useNotifications()      │  │
│  └───────────────────────────────────────┘  │
│                     ↓                        │
│  ┌───────────────────────────────────────┐  │
│  │  SerieCard.vue × N                    │  │
│  │  ├─ serie: Object (prop)              │  │
│  │  ├─ index: Number (prop)              │  │
│  │  └─ emits: add-serie, open, info      │  │
│  └───────────────────────────────────────┘  │
│                                             │
│  ┌───────────────────────────────────────┐  │
│  │  Notifications (Global)               │  │
│  │  ├─ useNotifications()                │  │
│  │  └─ Show toast messages               │  │
│  └───────────────────────────────────────┘  │
└─────────────────────────────────────────────┘
       ↓ (API calls)
   Laravel API
   ├─ /api/scraper/scrape
   ├─ /api/scraper/clear-cache
   └─ /series-infos/scrape
```

---

## 🎯 Flux d'Utilisation (Sans Refresh)

```
1. Page charge
   ↓
2. ScraperPage.vue monte (onMounted)
   ↓
3. loadInitialData() appelée
   ├─ useCache().getSeriesData()
   └─ Affiche séries (ou vide)
   ↓
4. User clique sur "تحديث"
   ↓
5. scrape() appelée
   ├─ useApi().scrapeSeries() → fetch /api/scraper/scrape
   ├─ series.value = result.series (réactif!)
   ├─ useCache().setSeriesData()
   └─ useNotifications().success()
   ↓
6. SerieCard.vue se rerendre (réactif)
   ├─ Affiche les nouvelles séries
   └─ Animations
   ↓
7. User hover une carte
   ↓
8. User clique ➕
   ↓
9. addSeries(url, titre) appelée
   ├─ useApi().addSerieToCollection()
   ├─ Valide + Lance scraping
   └─ Redirection /series-infos (2s)
   ↓
10. Pas de refresh du tout! ✅
```

---

## 🚀 Avantages de la Nouvelle Architecture

### **Réutilisabilité**
```javascript
// Utiliser useScraper() n'importe où
const scraper = useScraper();
const series = scraper.searchSeries('Breaking Bad');
```

### **Maintenabilité**
```javascript
// Changer la logique API? Modifier useApi.js
// Changer le style des notifications? Modifier useNotifications.js
// Ajouter une série card? Créer SerieCard2.vue
```

### **Testabilité**
```javascript
// Chaque composable peut être testé indépendamment
describe('useScraper', () => {
  it('should scrape series', async () => {
    const scraper = useScraper();
    await scraper.scrape();
    expect(scraper.seriesCount).toBeGreaterThan(0);
  });
});
```

### **Extensibilité**
```javascript
// Ajouter une nouvelle feature? Créer un nouveau composable
const useFavorites = () => {
  // Logic pour les favoris
};

// Intégrer dans ScraperPage.vue
const favorites = useFavorites();
```

---

## 📱 Features Vue.js Utilisées

### **Composition API**
```javascript
import { ref, computed, onMounted } from 'vue';

const series = ref([]);
const count = computed(() => series.value.length);

onMounted(() => {
  // Initialisation
});
```

### **Reactivité**
```javascript
// Changer series.value
// Automatiquement rerendre le template
series.value = newSeries; // ✅ Réactif!
```

### **Transitions**
```vue
<transition name="fade">
  <div v-if="isScraping">Loading...</div>
</transition>

<transition-group name="grid" tag="div">
  <SerieCard v-for="serie in series" :key="serie.url" />
</transition-group>
```

### **Directives**
```vue
v-if="condition"
v-for="item in items"
v-bind:prop="value"
v-on:click="handler"
@click="handler"
:style="{ width: 100 + '%' }"
:class="['active', { selected: isSelected }]"
```

---

## 🔧 Maintenance Guide

### **Ajouter une Feature**

1. **Créer un composable** (si logique réutilisable)
```javascript
// composables/useMyFeature.js
export const useMyFeature = () => {
  const state = ref('value');
  const method = () => { /* logic */ };
  return { state, method };
};
```

2. **Utiliser dans le composant**
```vue
<script setup>
const { state, method } = useMyFeature();
</script>
```

### **Ajouter une Notification**
```javascript
const { success, error, info } = useNotifications();
success('Feature ajoutée!');
```

### **Ajouter une Requête API**
```javascript
// Dans useApi.js
const myMethod = async () => {
  return await fetchApi('/endpoint', { method: 'POST' });
};

// Dans le composant
const result = await api.myMethod();
```

---

## 🎉 Résumé

| Aspect | Avant | Après |
|--------|-------|-------|
| **Structure** | Vanilla JS | Vue 3 + Composables |
| **Réutilisabilité** | Faible | Excellente |
| **Maintenabilité** | Difficile | Facile |
| **Réactivité** | Manuelle | Automatique |
| **Testabilité** | Difficile | Facile |
| **Code Quality** | Moyen | Professionnel |
| **DX** | Acceptable | Excellent |
| **Performance** | Bonne | Optimisée |
| **Scalabilité** | Limitée | Excellente |

---

## 📚 Ressources

- [Vue 3 Docs](https://vuejs.org/)
- [Composition API](https://vuejs.org/guide/extras/composition-api-faq.html)
- [Composables](https://vuejs.org/guide/reusability/composables.html)

---

**Architecture professionnelle, moderne et maintenable! 🎯**
