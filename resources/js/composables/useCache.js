/**
 * Composable pour la gestion du cache
 * Stockage persistant via localStorage
 */

const CACHE_KEY = 'scraper_series_cache';
const CACHE_DURATION = 24 * 60 * 60 * 1000; // 24 heures en ms

/**
 * Composable pour le cache
 */
export const useCache = () => {
    /**
     * Obtenir les données du cache
     */
    const getSeriesData = () => {
        try {
            const cached = localStorage.getItem(CACHE_KEY);
            if (!cached) return null;

            const data = JSON.parse(cached);

            // Vérifier si le cache est encore frais
            if (Date.now() - data.timestamp < CACHE_DURATION) {
                return data.series;
            }

            // Cache expiré
            localStorage.removeItem(CACHE_KEY);
            return null;
        } catch (error) {
            console.error('Erreur lecture cache:', error);
            return null;
        }
    };

    /**
     * Sauvegarder les données en cache
     */
    const setSeriesData = (series) => {
        try {
            const data = {
                series: series,
                timestamp: Date.now(),
            };
            localStorage.setItem(CACHE_KEY, JSON.stringify(data));
        } catch (error) {
            console.error('Erreur sauvegarde cache:', error);
        }
    };

    /**
     * Vérifier si le cache contient des données
     */
    const hasData = () => {
        return getSeriesData() !== null;
    };

    /**
     * Obtenir l'âge du cache en secondes
     */
    const getCacheAge = () => {
        try {
            const cached = localStorage.getItem(CACHE_KEY);
            if (!cached) return null;

            const data = JSON.parse(cached);
            const ageMs = Date.now() - data.timestamp;
            const ageSecs = Math.floor(ageMs / 1000);

            // Si expiré, retourner null
            if (ageMs > CACHE_DURATION) {
                return null;
            }

            return ageSecs;
        } catch (error) {
            return null;
        }
    };

    /**
     * Vider le cache
     */
    const clear = () => {
        try {
            localStorage.removeItem(CACHE_KEY);
        } catch (error) {
            console.error('Erreur suppression cache:', error);
        }
    };

    /**
     * Obtenir le pourcentage de validité du cache (0-100)
     */
    const getValidityPercent = () => {
        const age = getCacheAge();
        if (age === null) return 0;

        const percent = 100 - (age / (CACHE_DURATION / 1000)) * 100;
        return Math.max(0, Math.min(100, percent));
    };

    return {
        getSeriesData,
        setSeriesData,
        hasData,
        getCacheAge,
        clear,
        getValidityPercent,
        CACHE_DURATION,
    };
};
