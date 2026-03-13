# ESMA Serie App - Scraper Laravel automatisé

Ce projet implémente un scraper **100% automatisé** en Laravel, avec persistance SQL et reprise après interruption.

## Fonctionnement

Le flux implémenté est :

1. lire la page liste (`SCRAPER_LIST_PAGE_URL`)
2. extraire les épisodes
3. ouvrir chaque page épisode
4. extraire les serveurs utiles (par défaut `vdesk`)
5. récupérer l'URL d'iframe
6. ouvrir l'iframe via navigateur headless (WebDriver)
7. cliquer sur `#method_free`
8. cliquer sur `#downloadbtn`
9. attendre la stabilisation/redirection
10. récupérer l'URL finale et un résumé HTML
11. sauvegarder en base SQL
12. reprendre où le traitement s'est arrêté

## Architecture

- `app/Console/Commands/ScrapeEpisodesCommand.php` : orchestration complète.
- `app/Services/Scraper/EpisodeListScraper.php` : scraping page liste.
- `app/Services/Scraper/EpisodePageScraper.php` : scraping page épisode + iframe.
- `app/Services/Scraper/BrowserClickService.php` : automation navigateur headless via API WebDriver.
- `app/Services/Scraper/HtmlFetcher.php` : client HTTP.
- `app/Services/Scraper/UrlHelper.php` : normalisation d'URLs relatives.
- `app/Models/Episode.php`, `app/Models/EpisodeServer.php` : persistance de l'état.
- `database/migrations/*episodes*` : schéma SQL.
- `config/scraper.php` : configuration centralisée.

## Base de données

### Table `episodes`
- `title`
- `page_url` (unique)
- `status` (`pending`, `in_progress`, `done`, `error`)
- `error_message`
- `last_scraped_at`

### Table `episode_servers`
- `episode_id` (FK)
- `server_name`, `host`
- `server_page_url` (unique)
- `iframe_url`
- `click_success`
- `final_url`
- `result_title`, `result_h1`, `result_preview`
- `status` (`pending`, `in_progress`, `done`, `error`)
- `retry_count`
- `error_message`
- `last_scraped_at`

## Prérequis

- PHP 8.2+
- Base SQL configurée dans `.env`
- Un WebDriver compatible Chrome (ex: `chromedriver` ou Selenium standalone)
- Un navigateur Chrome/Chromium installé sur l'hôte WebDriver

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Configurer ensuite les variables scraper dans `.env`.

## Configuration

Variables disponibles :

- `SCRAPER_LIST_PAGE_URL`
- `SCRAPER_HTTP_TIMEOUT` (défaut `20`)
- `SCRAPER_BROWSER_TIMEOUT` (défaut `30`)
- `SCRAPER_MAX_RETRIES` (défaut `3`)
- `SCRAPER_HEADLESS` (`true` / `false`)
- `SCRAPER_WEBDRIVER_URL` (défaut `http://127.0.0.1:9515`)
- `SCRAPER_WEBDRIVER_AUTOSTART` (`true` / `false`, défaut `true`)
- `SCRAPER_WEBDRIVER_BINARY` (défaut `chromedriver`)
- `SCRAPER_WEBDRIVER_BOOT_TIMEOUT` (secondes, défaut `8`)
- `SCRAPER_WEBDRIVER_FALLBACK_URLS` (CSV d'URLs testées automatiquement, ex. Selenium `:4444` / `:4444/wd/hub`)
- `SCRAPER_ALLOWED_HOSTS` (CSV, défaut `vdesk`)

## Lancement

Commande principale :

```bash
php artisan scrape:episodes
```

Options :

```bash
php artisan scrape:episodes --limit=10
php artisan scrape:episodes --episode-id=12
php artisan scrape:episodes --only-pending
php artisan scrape:episodes --retry-errors
```

## Reprise après interruption

Le système est conçu pour reprendre proprement :

- les épisodes/serveurs `done` ne sont pas retraités
- si `iframe_url` est déjà connue, le flux reprend directement au navigateur
- les erreurs sont conservées en base (`status=error`, `error_message`)
- `retry_count` est incrémenté à chaque échec serveur
- relancer la commande permet de continuer sans perdre l'état

## Notes techniques

- Le script Python racine est utilisé comme **référence métier** (sélecteurs/ordre du flux), mais l'implémentation est Laravel-first.
- Aucun stockage JSON/Excel n'est utilisé.
- Toute la progression est persistée en SQL.

## Dépannage WebDriver

Si vous obtenez `cURL error 7 ... 127.0.0.1:9515/session`, cela signifie que le service WebDriver n'est pas démarré ou pas joignable à l'URL configurée.

Solutions :

1. Démarrer manuellement un WebDriver (Selenium/Chromedriver) et vérifier `SCRAPER_WEBDRIVER_URL`.
2. Ou activer l'auto-démarrage local :

```env
SCRAPER_WEBDRIVER_AUTOSTART=true
SCRAPER_WEBDRIVER_BINARY=chromedriver
```

Le scraper teste automatiquement plusieurs endpoints WebDriver (`9515`, `4444`, `4444/wd/hub` + URL configurée), puis tente de lancer `chromedriver` si activé, avant d'échouer avec un message détaillant toutes les URLs testées.
