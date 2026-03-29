# 📋 Architecture du Scraper - Documentation Technique

## 🏗️ Architecture Générale

```
┌──────────────────────────────────────────────────────────────┐
│                     Frontend (Blade + JS)                     │
│              resources/views/scraper/index.blade.php          │
└────────────────────────┬─────────────────────────────────────┘
                         │
                 ┌───────┴───────┐
                 │               │
            ┌────▼──────┐    ┌──▼────────┐
            │ GET /     │    │ POST /api │
            │ scraper   │    │ /scraper  │
            └─────┬──────┘    └──┬───────┘
                  │              │
         ┌────────▼──────────────▼──────────┐
         │    ScraperController             │
         │  app/Http/Controllers/           │
         │  ScraperController.php           │
         └────────┬───────────┬─────────────┘
                  │           │
          ┌───────▼──┐   ┌───▼────────────┐
          │  Cache   │   │ ExternalSeries │
          │  Laravel │   │ ScraperService │
          │          │   │                │
          │          │   └───┬────────────┘
          └──────┬───┘       │
                 │      ┌────▼──────────┐
                 │      │ n.esheaq.onl   │
                 │      │ (External API) │
                 │      └────────────────┘
                 │
          ┌──────▼─────────────┐
          │   Response JSON    │
          │  {series: [...]}   │
          └────────────────────┘
```

---

## 📁 Structure des Fichiers

```
app/
├── Services/
│   └── Scraper/
│       └── ExternalSeriesScraperService.php  [Logique scraping]
│
└── Http/
    └── Controllers/
        └── ScraperController.php              [Routes & endpoints]

routes/
└── web.php                                    [Routes définition]

resources/
└── views/
    └── scraper/
        └── index.blade.php                    [Vue HTML]
```

---

## 🔧 Composants Détaillés

### **1. ExternalSeriesScraperService**
**Localisation**: `app/Services/Scraper/ExternalSeriesScraperService.php`

**Responsabilités**:
- Scraper les séries du site externe
- Gérer la pagination
- Extraire les données (titre, image, URL)
- Éviter les doublons
- Logging des erreurs

**Méthodes Principales**:
```php
public function scrapeAllSeries(callable $onProgress = null): array
    └─ Scrape toutes les séries avec callback de progression

private function scrapePage(string $url): array
    └─ Scrape une seule page

private function findAllSeriesPageUrl(): ?string
    └─ Trouve l'URL de la page "جميع المسلسلات"

private function findNextPageUrl(string $currentUrl): ?string
    └─ Trouve le lien vers la page suivante
```

**Configuration**:
```php
private const BASE_URL = 'https://n.esheaq.onl';  // Site source
private const MAX_PAGES = 50;                      // Limite pagination
private const REQUEST_TIMEOUT = 30;                // Timeout requête
private const DELAY_BETWEEN_REQUESTS = 500;       // Délai respectueux
```

**Dépendances**:
- `Illuminate\Support\Facades\Http` (Laravel HTTP client)
- `Symfony\Component\DomCrawler\Crawler` (Parsing HTML)
- `Illuminate\Support\Facades\Log` (Logging)

### **2. ScraperController**
**Localisation**: `app/Http/Controllers/ScraperController.php`

**Responsabilités**:
- Gérer les routes du scraper
- Utiliser le service de scraping
- Gérer le cache
- Retourner les JSON responses

**Endpoints**:

#### `GET /scraper`
```
Affiche la page du scraper
Retourne: Vue Blade avec données initiales du cache si disponibles
```

#### `POST /api/scraper/scrape`
```
Lance le scraping ou retourne les données en cache
Content-Type: application/json
X-CSRF-TOKEN: Requis

Response:
{
    "success": true,
    "series": [{titre, url, image}, ...],
    "total": 200,
    "source": "scraped" | "cache"
}

Erreur (500):
{
    "success": false,
    "error": "Message d'erreur",
    "message": "Erreur lors du scraping des séries"
}
```

#### `POST /api/scraper/clear-cache`
```
Vide le cache
Content-Type: application/json
X-CSRF-TOKEN: Requis

Response:
{
    "success": true,
    "message": "Cache supprimé avec succès"
}
```

### **3. Vue Blade**
**Localisation**: `resources/views/scraper/index.blade.php`

**Responsabilités**:
- Afficher l'interface utilisateur
- Appeler les endpoints API
- Afficher les résultats en grille
- Gérer l'affichage du cache

**Variables Blade**:
- `$initialSeries`: Séries du cache (array)
- `$isCached`: Booléen indiquant si les données sont en cache
- `$cacheExpiresAt`: Timestamp d'expiration du cache

---

## 🔄 Flux d'Execution

### **Chargement Initial**
```
1. User visite /scraper
2. ScraperController@index est appelé
3. Cache est vérifié (Cache::get())
4. Vue est retournée avec données initiales
5. Page s'affiche (peut afficher 0 séries ou données du cache)
```

### **Scraping (Clic sur le bouton)**
```
1. JavaScript envoie POST /api/scraper/scrape
2. ScraperController@scrape est appelé
3. Vérifie si cache existe et est frais
   ├─ OUI: Retourne les données en cache
   └─ NON: Lance ExternalSeriesScraperService->scrapeAllSeries()
4. ExternalSeriesScraperService:
   ├─ Trouve l'URL de la page "جميع المسلسلات"
   ├─ Scrape page 1
   ├─ Extrait les séries (titre, image, URL)
   ├─ Cherche lien page suivante
   ├─ Délai respectueux (500ms)
   ├─ Scrape page 2, 3, 4... jusqu'à 50
   └─ Retourne tableau complet
5. Données sauvegardées en cache (24h)
6. Réponse JSON retournée
7. JavaScript affiche la grille
```

### **Vider le Cache**
```
1. User clique sur "Vider le cache"
2. Confirmation demandée
3. POST /api/scraper/clear-cache
4. Cache supprimé (Cache::forget())
5. Vue redevient vide
6. Message de succès
```

---

## 📊 Gestion du Cache

**Clés Cache**:
```php
'external_series_data'           // Les données des séries
'external_series_data_expires_at' // Timestamp d'expiration
```

**Durée**: 24 heures

**Validité**:
- Cache est considéré frais jusqu'à 24h
- Chaque scraping renouvelle le cache

**Stockage**:
- Par défaut: Fichier (storage/framework/cache/)
- Configurable: Redis, Memcached, etc.

---

## 🛡️ Gestion des Erreurs

### **Au niveau du Service**
```php
try {
    // Scraping logique
} catch (\Exception $e) {
    Log::error('Erreur scraping', ['error' => $e->getMessage()]);
    throw $e; // Re-throw au contrôleur
}
```

### **Au niveau du Contrôleur**
```php
try {
    $series = $scraperService->scrapeAllSeries();
} catch (\Exception $e) {
    return response()->json([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);
}
```

### **Au niveau du Frontend**
```javascript
try {
    const response = await fetch('/api/scraper/scrape', {...});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error);
} catch (error) {
    showError(`❌ ${error.message}`);
}
```

---

## 🔐 Sécurité

✅ **CSRF Protection**:
- Token `X-CSRF-TOKEN` requis pour POST
- Laravel middleware automatique

✅ **Authentification**:
- Routes protégées par middleware 'auth'
- Utilisateurs non connectés redirigés

✅ **SQL Injection**:
- Pas de requête DB directe (seulement cache)
- Pas de risque

✅ **XSS**:
- Échappement HTML dans la vue Blade
- Utilisation de `{{ }}` qui échappe automatiquement

✅ **Rate Limiting**:
- Délai 500ms entre les requêtes au site externe
- Respectueux du serveur

---

## 📈 Performances

### **Optimisations Implémentées**
1. **Cache 24h**: Évite de re-scraper trop souvent
2. **Pagination Lazy**: Une page à la fois
3. **Délai Respectueux**: 500ms entre pages
4. **HTML Parser Efficace**: DomCrawler de Symfony

### **Métriques**
- Premier scraping: ~5-10 minutes (50 pages)
- Scraping ultérieurs: < 1 sec (depuis cache)
- Chargement page: < 500ms

---

## 🧪 Tests

### **Test Manuel**
```bash
# 1. Serveur Laravel démarré
php artisan serve

# 2. Accès à la page
http://localhost:8000/scraper

# 3. Connecté (authentifié)

# 4. Clic sur "بدء"
# Observer la progression et les résultats
```

### **Test API (cURL)**
```bash
# Scraper
curl -X POST http://localhost:8000/api/scraper/scrape \
  -H "X-CSRF-TOKEN: TOKEN" \
  -H "Content-Type: application/json"

# Clear cache
curl -X POST http://localhost:8000/api/scraper/clear-cache \
  -H "X-CSRF-TOKEN: TOKEN" \
  -H "Content-Type: application/json"
```

---

## 🔧 Maintenance

### **Modifier le Site Source**
Si l'URL change:
```php
// app/Services/Scraper/ExternalSeriesScraperService.php
private const BASE_URL = 'https://nouveau-url.onl';
```

### **Modifier la Sélection CSS**
Si la structure HTML change:
```php
// Dans scrapePage()
$crawler->filter('article')->each(function (Crawler $article) {
    // Adapter les selecteurs CSS
});
```

### **Modifier la Durée du Cache**
```php
// app/Http/Controllers/ScraperController.php
private const CACHE_DURATION = 48 * 60 * 60; // 48 heures
```

---

## 📝 Logs

Les logs sont enregistrés dans `storage/logs/laravel.log`:

```
[2026-03-28 10:30:00] local.INFO: Début du scraping des séries externes
[2026-03-28 10:30:02] local.INFO: Page trouvée {"url":"..."}
[2026-03-28 10:30:05] local.INFO: Page 1 scrapée {"count":20}
[2026-03-28 10:32:00] local.INFO: Scraping complété {"total_series":200,"pages":10}
```

---

## 🚀 Déploiement

### **Dépendances**
```
illuminate/support
symfony/dom-crawler
```

Installer:
```bash
composer require symfony/dom-crawler
```

### **Configuration Serveur**
- PHP 8.0+
- Laravel 9+
- cURL activé (pour Http::get)
- Cache configuré (fichier, Redis, Memcached)

### **Fichiers à Déployer**
```
app/Services/Scraper/ExternalSeriesScraperService.php
app/Http/Controllers/ScraperController.php
routes/web.php (modifications)
resources/views/scraper/index.blade.php
```

---

**Architecture Complète et Maintenable ✅**
