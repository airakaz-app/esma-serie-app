/**
 * Composable pour les appels API
 * Gère toutes les requêtes HTTP vers le serveur Laravel
 */

import { ref } from 'vue';

const API_BASE = '/api';
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

/**
 * Utilitaire pour récupérer le token CSRF
 */
const getCsrfToken = () => CSRF_TOKEN;

/**
 * Effectue une requête fetch générique avec gestion d'erreurs
 */
const fetchApi = async (endpoint, options = {}) => {
    const url = `${API_BASE}${endpoint}`;
    const defaultOptions = {
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
        },
    };

    const config = { ...defaultOptions, ...options };

    try {
        const response = await fetch(url, config);
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || data.error || `HTTP ${response.status}`);
        }

        return { success: true, data, status: response.status };
    } catch (error) {
        return { success: false, error: error.message };
    }
};

/**
 * Composable pour les appels API
 */
export const useApi = () => {
    const loading = ref(false);
    const error = ref(null);

    /**
     * Scraper les séries
     */
    const scrapeSeries = async () => {
        loading.value = true;
        error.value = null;

        const result = await fetchApi('/scraper/scrape', {
            method: 'POST',
        });

        loading.value = false;

        if (!result.success) {
            error.value = result.error;
            return null;
        }

        return result.data;
    };

    /**
     * Vider le cache
     */
    const clearCache = async () => {
        loading.value = true;
        error.value = null;

        const result = await fetchApi('/scraper/clear-cache', {
            method: 'POST',
        });

        loading.value = false;

        if (!result.success) {
            error.value = result.error;
            return null;
        }

        return result.data;
    };

    /**
     * Ajouter une série à la collection
     */
    const addSerieToCollection = async (url, episodeStart = null, episodeEnd = null) => {
        loading.value = true;
        error.value = null;

        const formData = new FormData();
        formData.append('list_page_url', url);
        if (episodeStart) formData.append('episode_start', episodeStart);
        if (episodeEnd) formData.append('episode_end', episodeEnd);

        try {
            const response = await fetch('/series-infos/scrape', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: formData,
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Erreur lors de l\'ajout de la série');
            }

            loading.value = false;
            return { success: true, data };
        } catch (err) {
            error.value = err.message;
            loading.value = false;
            return { success: false, error: err.message };
        }
    };

    /**
     * Obtenir le statut du scraping
     */
    const getScrapeStatus = async (trackingKey) => {
        try {
            const response = await fetch(`/series-infos/scrape-status/${trackingKey}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Erreur lors de la récupération du statut');
            }

            return { success: true, data };
        } catch (err) {
            return { success: false, error: err.message };
        }
    };

    return {
        loading,
        error,
        scrapeSeries,
        clearCache,
        addSerieToCollection,
        getScrapeStatus,
    };
};
