/**
 * Composable pour les notifications
 * Gère les messages de succès, erreur et info
 */

import { ref, computed } from 'vue';

// État global des notifications
const notifications = ref([]);
let notificationId = 0;

/**
 * Composable pour les notifications
 */
export const useNotifications = () => {
    /**
     * Ajoute une notification
     */
    const addNotification = (message, type = 'info', duration = 3000) => {
        const id = notificationId++;
        const notification = {
            id,
            message,
            type, // 'success', 'error', 'info', 'warning'
            visible: true,
        };

        notifications.value.push(notification);

        // Auto-remove après la durée
        if (duration > 0) {
            setTimeout(() => {
                removeNotification(id);
            }, duration);
        }

        return id;
    };

    /**
     * Affiche une notification de succès
     */
    const success = (message, duration = 3000) => {
        return addNotification(`✅ ${message}`, 'success', duration);
    };

    /**
     * Affiche une notification d'erreur
     */
    const error = (message, duration = 5000) => {
        return addNotification(`❌ ${message}`, 'error', duration);
    };

    /**
     * Affiche une notification d'info
     */
    const info = (message, duration = 3000) => {
        return addNotification(`ℹ️ ${message}`, 'info', duration);
    };

    /**
     * Affiche une notification d'avertissement
     */
    const warning = (message, duration = 4000) => {
        return addNotification(`⚠️ ${message}`, 'warning', duration);
    };

    /**
     * Supprime une notification
     */
    const removeNotification = (id) => {
        const index = notifications.value.findIndex(n => n.id === id);
        if (index > -1) {
            notifications.value.splice(index, 1);
        }
    };

    /**
     * Vide toutes les notifications
     */
    const clearAll = () => {
        notifications.value = [];
    };

    /**
     * Nombre de notifications actives
     */
    const count = computed(() => notifications.value.length);

    /**
     * Notifications par type
     */
    const byType = (type) => {
        return notifications.value.filter(n => n.type === type);
    };

    return {
        notifications,
        addNotification,
        success,
        error,
        info,
        warning,
        removeNotification,
        clearAll,
        count,
        byType,
    };
};
