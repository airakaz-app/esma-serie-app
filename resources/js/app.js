import './bootstrap';
import { createApp } from 'vue';
import ScraperPage from './components/Scraper/ScraperPage.vue';

// Créer l'application Vue
const app = createApp({
    components: {
        ScraperPage,
    },
    template: '<ScraperPage />',
});

// Monter l'application
app.mount('#app');
