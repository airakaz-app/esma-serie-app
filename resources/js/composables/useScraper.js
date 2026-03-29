/**
 * Composable pour la logique du scraper
 * Gère le scraping des séries et la progression
 */

import { ref, computed } from 'vue';
import { useApi } from './useApi';
import { useNotifications } from './useNotifications';
import { useCache } from './useCache';

/**
 * Composable pour le scraper
 */
export const useScraper = () => {
    const api = useApi();
    const notifications = useNotifications();
    const cache = useCache();

    // État
    const series = ref([]);
    const isScraping = ref(false);
    const progressPercent = ref(0);
    const progressMessage = ref('');
    const cacheInfo = ref('');
    const addingSerieUrl = ref(null);

    // Computed
    const seriesCount = computed(() => series.value.length);
    const isEmpty = computed(() => series.value.length === 0);
    const hasCache = computed(() => cache.hasData());
    const cacheAge = computed(() => cache.getCacheAge());

    /**
     * Charger les données initiales
     */
    const loadInitialData = async () => {
        // D'abord essayer de charger depuis le cache
        const cached = cache.getSeriesData();
        if (cached && cached.length > 0) {
            series.value = cached;
            updateCacheInfo();
            notifications.info(`${cached.length} séries chargées depuis le cache`);
            return;
        }

        // Sinon, pas de données initiales
        series.value = [];
        updateCacheInfo();
    };

    /**
     * Scraper les séries
     */
    const scrape = async () => {
        if (isScraping.value) return;

        isScraping.value = true;
        progressPercent.value = 0;
        progressMessage.value = 'Connexion au serveur...';

        try {
            const result = await api.scrapeSeries();

            if (!result || !result.success) {
                throw new Error(api.error.value || 'Erreur lors du scraping');
            }

            // Mettre à jour les données
            series.value = result.data.series || [];

            // Sauvegarder en cache
            cache.setSeriesData(series.value);
            updateCacheInfo();

            // Message de succès
            const source = result.data.source === 'cache' ? '(depuis le cache)' : '(scrapées)';
            notifications.success(`${series.value.length} séries trouvées ${source}`);

            progressPercent.value = 100;
            progressMessage.value = 'Terminé!';

        } catch (err) {
            notifications.error(err.message || 'Erreur lors du scraping');
            progressMessage.value = 'Erreur!';
        } finally {
            isScraping.value = false;

            // Cacher la progress bar après 1s
            setTimeout(() => {
                progressPercent.value = 0;
                progressMessage.value = '';
            }, 1000);
        }
    };

    /**
     * Ajouter une série à la collection
     */
    const addSeries = async (url, titre) => {
        addingSerieUrl.value = url;

        try {
            const result = await api.addSerieToCollection(url);

            if (!result || !result.success) {
                throw new Error('Erreur lors de l\'ajout de la série');
            }

            notifications.success(`"${titre}" ajoutée à votre collection!`, 2000);

            // Rediriger vers series-infos après 2s
            setTimeout(() => {
                window.location.href = '/series-infos';
            }, 2000);

        } catch (err) {
            notifications.error(`Impossible d'ajouter la série: ${err.message}`);
        } finally {
            addingSerieUrl.value = null;
        }
    };

    /**
     * Vider le cache
     */
    const clearAllCache = async () => {
        try {
            const result = await api.clearCache();

            if (!result || !result.success) {
                throw new Error('Erreur lors de la suppression du cache');
            }

            // Vider les données
            series.value = [];
            cache.clear();
            updateCacheInfo();

            notifications.success('Cache supprimé avec succès');

        } catch (err) {
            notifications.error(err.message);
        }
    };

    /**
     * Mettre à jour les infos du cache
     */
    const updateCacheInfo = () => {
        const age = cacheAge.value;

        if (!hasCache.value || !age) {
            cacheInfo.value = '';
            return;
        }

        if (age < 60) {
            cacheInfo.value = '📦 À l\'instant';
        } else if (age < 3600) {
            const minutes = Math.floor(age / 60);
            cacheInfo.value = `📦 Il y a ${minutes}m`;
        } else if (age < 86400) {
            const hours = Math.floor(age / 3600);
            cacheInfo.value = `📦 Il y a ${hours}h`;
        } else {
            const days = Math.floor(age / 86400);
            cacheInfo.value = `📦 Il y a ${days}j`;
        }
    };

    /**
     * Rechercher une série dans la liste
     */
    const searchSeries = (query) => {
        if (!query || query.trim() === '') {
            return series.value;
        }

        const q = query.toLowerCase();
        return series.value.filter(s =>
            s.titre.toLowerCase().includes(q) ||
            s.url.toLowerCase().includes(q)
        );
    };

    /**
     * Filtrer les séries
     */
    const filterSeries = (predicate) => {
        return series.value.filter(predicate);
    };

    return {
        // État
        series,
        isScraping,
        progressPercent,
        progressMessage,
        cacheInfo,
        addingSerieUrl,

        // Computed
        seriesCount,
        isEmpty,
        hasCache,
        cacheAge,

        // Méthodes
        loadInitialData,
        scrape,
        addSeries,
        clearAllCache,
        updateCacheInfo,
        searchSeries,
        filterSeries,
    };
};
