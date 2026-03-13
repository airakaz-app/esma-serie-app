# ESMA Serie App - Scraper Laravel automatisé

Ce projet implémente un scraper **100% automatisé** en Laravel, avec persistance SQL et reprise après interruption.

## Fonctionnement

Le flux implémenté est :

1. lire la page liste (`SCRAPER_LIST_PAGE_URL`)
2. extraire les épisodes
3. ouvrir chaque page épisode
4. extraire les serveurs utiles (par défaut `vdesk`)
5. récupérer l'URL d'iframe
6. ouvrir l'iframe via navigateur headless (bridge Python Selenium, fallback WebDriver HTTP)
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
- `app/Services/Scraper/BrowserClickService.php` : automation navigateur headless (stratégie Python Selenium + fallback WebDriver HTTP).
- `browser_click.py` : bridge Selenium robuste alignée sur le flux de référence.
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
- Python 3 + package `selenium` installés localement (`pip install selenium`)
- Un navigateur Chrome/Chromium installé localement (Selenium Manager gère automatiquement le driver)
- Optionnel: un endpoint WebDriver externe (chromedriver/selenium) si vous gardez le mode fallback

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
- `SCRAPER_BROWSER_STRATEGY` (`auto`, `python`, `webdriver`)
- `SCRAPER_PYTHON_BINARY` (défaut `python3`)
- `SCRAPER_PYTHON_SCRIPT` (défaut `browser_click.py`)
- `SCRAPER_PYTHON_TIMEOUT` (défaut `60`)
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

## Dépannage navigateur

Par défaut, utilisez `SCRAPER_BROWSER_STRATEGY=auto` :

1. tentative Python Selenium locale (même approche que `test.py`)
2. fallback WebDriver HTTP existant si Python échoue

Configuration recommandée :

```env
SCRAPER_BROWSER_STRATEGY=python
SCRAPER_PYTHON_BINARY=python3
SCRAPER_PYTHON_SCRIPT=browser_click.py
SCRAPER_HEADLESS=true
```

Si vous forcez `SCRAPER_BROWSER_STRATEGY=webdriver`, le scraper conserve la logique WebDriver HTTP (endpoints `9515`, `4444`, `4444/wd/hub` + auto-start chromedriver si activé).

En cas d'échec, consultez `storage/logs/laravel.log` (et `/tmp/scraper-chromedriver.log` uniquement pour le mode `webdriver`).
