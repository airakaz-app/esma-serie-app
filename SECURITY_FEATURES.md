# Fonctionnalités de Sécurité & Anonymat du Scraper

Ce document décrit les améliorations de sécurité implémentées pour :
- Éviter la détection par les sites cibles
- Protéger votre anonymat (IP, User-Agent)
- Éviter les blocages (429, 403)
- Gérer les taux de limitation intelligemment

---

## 1. Rotation User-Agent

**Quoi ?** Chaque requête HTTP utilise un User-Agent différent et réaliste.

**Pourquoi ?** Les serveurs détectent les User-Agents suspects (curl, Python, vides...).

**Navigateurs supportés :**
- Chrome (Windows, Mac)
- Firefox (Windows, Mac)
- Safari (Mac)
- Edge (Windows)

**Code :**
```php
$userAgent = ScraperSecurityService::randomUserAgent();
```

---

## 2. Headers HTTP Réalistes

Chaque requête inclut des headers qui imitent un navigateur réel :

```
Accept: text/html,application/xhtml+xml,application/xml;q=0.9,...
Accept-Language: fr-FR,fr;q=0.9,en;q=0.8
Accept-Encoding: gzip, deflate, br
DNT: 1
Connection: keep-alive
Upgrade-Insecure-Requests: 1
Sec-Fetch-Dest: document
Sec-Fetch-Mode: navigate
Sec-Fetch-Site: none
Cache-Control: max-age=0
```

**Code :**
```php
$headers = ScraperSecurityService::realisticHeaders($referer);
```

---

## 3. Rotation Referer

Chaque requête a un referer différent (Google, Bing, Reddit, direct...).

**Quoi ?** Les serveurs voient d'où vient la requête.

**Pourquoi ?** Un scraper sans referer paraît suspect.

```php
$referer = ScraperSecurityService::randomReferer();
// Résultats: Google, Bing, DuckDuckGo, Reddit, Facebook, direct
```

---

## 4. Délais Aléatoires (Anti-Rate-Limit)

Délais variables entre les requêtes (500-2000 ms) pour imiter un humain.

**Avant :**
```php
usleep(1_200_000); // Délai fixe = détectable
```

**Après :**
```php
ScraperSecurityService::randomDelay(500, 2000); // Aléatoire
```

---

## 5. Support Proxy/VPN

**Configuration dans `.env` :**
```bash
SCRAPER_PROXY_URL=http://user:pass@proxy.host:8080
```

**Sans authentification :**
```bash
SCRAPER_PROXY_URL=http://proxy.host:8080
```

**Sans proxy (vide) :**
```bash
SCRAPER_PROXY_URL=
```

**Code :**
```php
$proxy = ScraperSecurityService::proxyConfig();
if ($proxy !== null) {
    $options['proxy'] = $proxyUrl; // Utiliser le proxy
}
```

**Avantages :**
- Change votre adresse IP
- Utile si votre IP est déjà bloquée
- Support de multiples proxies (rotation manuelle)

**Recommandations :**
- Utilisez des proxies résidentiels (moins bloqués que datacenter)
- Peu de requêtes par proxy (rotation fréquente)
- Gratuit/payant selon le besoin

---

## 6. Gestion Intelligente des Blocages

**Détection des erreurs de blocage :**
```php
ScraperSecurityService::isBlockingError($statusCode);
// Retourne true pour: 403, 429, 503, 401
```

**Backoff exponentiel (retry intelligent) :**
```php
// Tentative 1 → attendre 5 sec
// Tentative 2 → attendre 10 sec
// Tentative 3 → attendre 20 sec
$delay = ScraperSecurityService::exponentialBackoff($attemptNumber);
sleep($delay);
```

---

## 7. Session Cookies

**Quoi ?** Les cookies sont persistants entre les requêtes (comme un vrai navigateur).

**Pourquoi ?** Sans cookies, vous semblez faire des requêtes isolées (détectable).

**Code (implémenté automatiquement) :**
```php
$jar = new CookieJar(); // Stocke les cookies
$response = $this->http->withOptions(['cookies' => $jar])->get($url);
// Les cookies sont réutilisés pour les prochaines requêtes
```

---

## Comparaison : Avant/Après

| Aspect | Avant | Après |
|--------|-------|-------|
| **User-Agent** | Fixe (curl-like) | Aléatoire (Firefox, Chrome...) |
| **Headers** | Minimaux | Complets & réalistes |
| **Referer** | Constant | Aléatoire |
| **Délais** | Fixe (1.2s) | Aléatoire (0.5-2s) |
| **Proxy** | Non supporté | Optionnel via env |
| **Cookies** | Oui | Oui + persistant |
| **IP** | Visible | Masquable avec proxy |

---

## Configuration Recommandée

### 1. Sans Proxy (Base)
```bash
# .env
SCRAPER_PROXY_URL=
```
- ✅ Requis minimum (User-Agent, headers, délais)
- ❌ Votre IP reste visible
- ✅ Moins de latence

### 2. Avec Proxy (Sécurisé)
```bash
# .env
SCRAPER_PROXY_URL=http://user:pass@proxy.example.com:8080
```
- ✅ IP masquée
- ✅ Rotatable (changer l'env)
- ❌ Latence plus élevée
- ❌ Coût (proxy payant)

### 3. Production (Robuste)
```bash
# .env
SCRAPER_PROXY_URL=http://rotating-proxy.service:8080
# Proxy rotatif qui change l'IP automatiquement
```

---

## Logs & Debugging

Les logs incluent désormais :
- User-Agent utilisé
- Referer
- Statut HTTP
- Si un blocage a été détecté
- Délais d'attente

**Exemple :**
```
BrowserClick: URL vidéo vérifiée et accessible.
  final_url: https://s3.vdesk.live:8080/files/abc/video.mp4
  strategy: method_free
  http_status: 206
  content_type: video/mp4
  content_size: 524288000
```

---

## Limitations & Considérations

### Ce que la sécurité NE peut PAS faire :
- ❌ Contourner Cloudflare (c'est du JavaScript rendering)
- ❌ Contourner les CAPTCHAs (requires human interaction)
- ❌ Contourner les géo-blocages IP (proxy résidentiel + rotation nécessaire)

### Risques à connaître :
- 🔴 Abuser du scraping = IP bloquée définitivement
- 🔴 Trop de requêtes = compte bannit
- 🔴 Proxy public = vos requêtes visibles par un tiers

### Bonnes pratiques :
- ✅ Respecter `robots.txt`
- ✅ Délais généreux entre requêtes
- ✅ Cache des résultats (ne pas re-scraper)
- ✅ Monitorer les 403/429 (signe de blocage)
- ✅ Rotation de proxy si bloqué
- ✅ Lire les conditions d'utilisation du site

---

## Exemples d'Utilisation

### Example 1 : Scraping simple (sans proxy)
```bash
php artisan scrape:episodes --list-page-url="https://..."
# Utilise rotation User-Agent + délais aléatoires automatiquement
```

### Example 2 : Scraping sécurisé (avec proxy)
```bash
# 1. Configurer .env
echo "SCRAPER_PROXY_URL=http://proxy.ip:8080" >> .env

# 2. Lancer le scraping
php artisan scrape:episodes --list-page-url="https://..."
```

### Example 3 : Retry avec vérification d'URLs
```bash
# Bouton "Retry erreurs" (frontend)
# → Re-vérifie les URLs existantes (HEAD check)
# → Re-scrape les URLs cassées/manquantes
# Tout automatiquement avec la sécurité activée
```

---

## Futur (À faire)

- [ ] Rotation de proxy automatique (pool de proxies)
- [ ] Cache des résultats (réduire les requêtes)
- [ ] Détection & contournement de Cloudflare
- [ ] Support SOCKS5 (Tor)
- [ ] Rotation de DNS
