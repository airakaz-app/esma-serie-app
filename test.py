import json
import os
import tempfile
import time
import traceback
from datetime import datetime

import pandas as pd
import requests
from bs4 import BeautifulSoup
from urllib.parse import urljoin

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC


HEADERS = {
    "User-Agent": "Mozilla/5.0"
}

LIST_PAGE_URL = ""  # remplace par ta page liste

JSON_FILE = "results.json"
EXCEL_FILE = "results.xlsx"
ERROR_LOG_FILE = "errors.log"

session = requests.Session()
session.headers.update(HEADERS)


def log_error(message: str):
    with open(ERROR_LOG_FILE, "a", encoding="utf-8") as f:
        f.write(f"[{datetime.now().isoformat()}] {message}\n")


def get_soup(url: str) -> BeautifulSoup:
    r = session.get(url, timeout=20)
    r.raise_for_status()
    r.encoding = "utf-8"
    return BeautifulSoup(r.text, "html.parser")


def safe_save_json(data, filename=JSON_FILE):
    dir_name = os.path.dirname(filename) or "."
    with tempfile.NamedTemporaryFile(
        "w",
        delete=False,
        dir=dir_name,
        encoding="utf-8"
    ) as tmp:
        json.dump(data, tmp, ensure_ascii=False, indent=2)
        temp_name = tmp.name

    os.replace(temp_name, filename)


def flatten_rows(data):
    rows = []

    for item in data:
        title = item.get("title", "")
        page_url = item.get("page_url", "")
        episode_status = item.get("status", "")

        servers = item.get("servers", [])
        if not servers:
            rows.append({
                "title": title,
                "page_url": page_url,
                "episode_status": episode_status,
                "server_name": "",
                "host": "",
                "server_page_url": "",
                "iframe_url": "",
                "click_success": "",
                "final_url": "",
                "server_status": "",
                "retry_count": "",
                "result_title": "",
                "result_h1": "",
                "result_preview": "",
                "error": item.get("error", "")
            })
            continue

        for server in servers:
            rows.append({
                "title": title,
                "page_url": page_url,
                "episode_status": episode_status,
                "server_name": server.get("server_name", ""),
                "host": server.get("host", ""),
                "server_page_url": server.get("server_page_url", ""),
                "iframe_url": server.get("iframe_url", ""),
                "click_success": server.get("click_success", ""),
                "final_url": server.get("final_url", ""),
                "server_status": server.get("status", ""),
                "retry_count": server.get("retry_count", 0),
                "result_title": server.get("result_title", ""),
                "result_h1": server.get("result_h1", ""),
                "result_preview": server.get("result_preview", ""),
                "error": server.get("error", "")
            })

    return rows


def safe_save_excel(data, filename=EXCEL_FILE):
    rows = flatten_rows(data)

    dir_name = os.path.dirname(filename) or "."
    with tempfile.NamedTemporaryFile(
        delete=False,
        dir=dir_name,
        suffix=".xlsx"
    ) as tmp:
        temp_name = tmp.name

    df = pd.DataFrame(rows)

    with pd.ExcelWriter(temp_name, engine="openpyxl") as writer:
        df.to_excel(writer, index=False, sheet_name="results")

    os.replace(temp_name, filename)


def save_progress(results):
    safe_save_json(results, JSON_FILE)
    safe_save_excel(results, EXCEL_FILE)


def load_existing_results(filename=JSON_FILE):
    if not os.path.exists(filename):
        return []

    try:
        with open(filename, "r", encoding="utf-8") as f:
            data = json.load(f)
            return data if isinstance(data, list) else []
    except Exception:
        log_error(f"Erreur lecture {filename}:\n{traceback.format_exc()}")
        return []


def get_episode_links(list_url: str):
    soup = get_soup(list_url)
    episodes = []

    for article in soup.select("article.postEp"):
        a_tag = article.select_one("a[href]")
        title_tag = article.select_one("div.title")

        if not a_tag or not title_tag:
            continue

        title = title_tag.get_text(strip=True)
        page_url = a_tag.get("href", "").strip()

        if not page_url:
            continue

        page_url = page_url.rstrip("/") + "/see/"

        episodes.append({
            "title": title,
            "page_url": page_url
        })

    return episodes


def get_servers_from_episode_page(page_url: str):
    soup = get_soup(page_url)
    servers = []

    for li in soup.select(".serversList li"):
        data_src = li.get("data-src", "").strip()
        server_name_tag = li.select_one("span")
        host_tag = li.select_one("em")

        server_name = server_name_tag.get_text(strip=True) if server_name_tag else ""
        host = host_tag.get_text(strip=True) if host_tag else ""

        if data_src and host.lower() == "vdesk":
            servers.append({
                "server_name": server_name,
                "host": host,
                "server_page_url": urljoin(page_url, data_src)
            })

    return servers


def clean_iframe_url(iframe_url: str) -> str:
    return iframe_url.replace("embed-", "")


def get_iframe_url(server_page_url: str):
    try:
        soup = get_soup(server_page_url)

        iframes = soup.select("iframe[src]")
        if not iframes:
            iframes = soup.select("noscript iframe[src]")

        for iframe in iframes:
            src = iframe.get("src", "").strip()
            if not src:
                continue

            iframe_url = urljoin(server_page_url, src)
            return clean_iframe_url(iframe_url)

    except Exception as e:
        print(f"    Erreur récupération iframe: {e}")
        log_error(
            f"Erreur get_iframe_url | server_page_url={server_page_url}\n"
            f"{traceback.format_exc()}"
        )

    return None


def build_driver(headless: bool = True):
    options = Options()

    if headless:
        options.add_argument("--headless=new")

    options.add_argument("--disable-blink-features=AutomationControlled")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--window-size=1920,1080")

    driver = webdriver.Chrome(options=options)
    return driver


def click_download_buttons(iframe_url: str, timeout: int = 25):
    driver = build_driver(headless=True)

    try:
        driver.get(iframe_url)
        wait = WebDriverWait(driver, timeout)

        wait.until(lambda d: d.execute_script("return document.readyState") == "complete")

        free_btn = wait.until(
            EC.element_to_be_clickable((By.ID, "method_free"))
        )
        free_btn.click()

        time.sleep(1)

        download_btn = wait.until(
            EC.element_to_be_clickable((By.ID, "downloadbtn"))
        )
        download_btn.click()

        wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
        time.sleep(2)

        final_html = driver.page_source
        final_url = driver.current_url

        return {
            "success": True,
            "final_url": final_url,
            "final_html": final_html,
            "error": ""
        }

    except Exception as e:
        log_error(
            f"Erreur click_download_buttons | iframe_url={iframe_url}\n"
            f"{traceback.format_exc()}"
        )
        return {
            "success": False,
            "final_url": "",
            "final_html": "",
            "error": str(e)
        }

    finally:
        driver.quit()


def summarize_html(html: str):
    soup = BeautifulSoup(html, "html.parser")

    title = soup.title.get_text(strip=True) if soup.title else ""

    h1_tag = soup.select_one("h1")
    h1_text = h1_tag.get_text(strip=True) if h1_tag else ""

    text_preview = soup.get_text(" ", strip=True)
    if len(text_preview) > 400:
        text_preview = text_preview[:400] + "..."

    return {
        "result_title": title,
        "result_h1": h1_text,
        "result_preview": text_preview
    }


def find_existing_episode(results, page_url: str):
    for item in results:
        if item.get("page_url") == page_url:
            return item
    return None


def find_existing_server(episode_result, server_page_url: str):
    for server in episode_result.get("servers", []):
        if server.get("server_page_url") == server_page_url:
            return server
    return None


def scrape_all(list_page_url: str):
    results = load_existing_results(JSON_FILE)
    episodes = get_episode_links(list_page_url)

    print(f"Épisodes trouvés : {len(episodes)}")
    print("-" * 80)

    for ep_index, episode in enumerate(episodes, start=1):
        print(f"[{ep_index}] {episode['title']}")
        print(f"Page épisode : {episode['page_url']}")

        episode_result = find_existing_episode(results, episode["page_url"])

        if not episode_result:
            episode_result = {
                "title": episode["title"],
                "page_url": episode["page_url"],
                "status": "in_progress",
                "servers": [],
                "error": ""
            }
            results.append(episode_result)
            save_progress(results)

        if episode_result.get("status") == "done":
            print("  Épisode déjà terminé, ignoré.")
            print("-" * 80)
            continue

        try:
            servers = get_servers_from_episode_page(episode["page_url"])
        except Exception as e:
            print(f"  Erreur serveurs : {e}")
            episode_result["error"] = str(e)
            log_error(
                f"Erreur get_servers_from_episode_page | episode={episode['title']} | "
                f"url={episode['page_url']}\n{traceback.format_exc()}"
            )
            save_progress(results)
            print("-" * 80)
            continue

        print(f"  Serveurs trouvés (vdesk seulement) : {len(servers)}")

        for server_index, server in enumerate(servers, start=1):
            print(f"  - Serveur {server_index}: {server['server_name']} | {server['host']}")
            print(f"    URL serveur : {server['server_page_url']}")

            server_result = find_existing_server(episode_result, server["server_page_url"])

            if not server_result:
                server_result = {
                    "server_name": server["server_name"],
                    "host": server["host"],
                    "server_page_url": server["server_page_url"],
                    "iframe_url": "",
                    "click_success": False,
                    "final_url": "",
                    "result_title": "",
                    "result_h1": "",
                    "result_preview": "",
                    "status": "pending",
                    "retry_count": 0,
                    "error": ""
                }
                episode_result["servers"].append(server_result)
                save_progress(results)

            if server_result.get("status") == "done":
                print("    Serveur déjà traité, ignoré.")
                continue

            iframe_url = server_result.get("iframe_url", "")
            click_success = server_result.get("click_success", False)
            final_url = server_result.get("final_url", "")
            result_title = server_result.get("result_title", "")
            result_h1 = server_result.get("result_h1", "")
            result_preview = server_result.get("result_preview", "")
            error_msg = ""

            try:
                server_result["status"] = "in_progress"
                save_progress(results)

                if not iframe_url:
                    iframe_url = get_iframe_url(server["server_page_url"]) or ""
                    server_result["iframe_url"] = iframe_url

                print(f"    Iframe : {iframe_url if iframe_url else 'non trouvé'}")

                if iframe_url and not final_url:
                    click_result = click_download_buttons(iframe_url=iframe_url, timeout=25)

                    click_success = click_result.get("success", False)
                    final_url = click_result.get("final_url", "")
                    final_html = click_result.get("final_html", "")
                    error_msg = click_result.get("error", "")

                    server_result["click_success"] = click_success
                    server_result["final_url"] = final_url
                    server_result["error"] = error_msg

                    if click_success and final_html:
                        summary = summarize_html(final_html)
                        result_title = summary["result_title"]
                        result_h1 = summary["result_h1"]
                        result_preview = summary["result_preview"]

                        server_result["result_title"] = result_title
                        server_result["result_h1"] = result_h1
                        server_result["result_preview"] = result_preview

                        print("    Clics OK")
                        print(f"    URL finale : {final_url or 'vide'}")
                        print(f"    Titre final : {result_title or 'vide'}")
                    else:
                        print(f"    Échec clics : {error_msg or 'erreur inconnue'}")

                if server_result.get("final_url"):
                    server_result["status"] = "done"
                else:
                    server_result["status"] = "error"

                save_progress(results)
                print("    Sauvegarde intermédiaire OK")

            except Exception as e:
                error_msg = str(e)
                server_result["error"] = error_msg
                server_result["retry_count"] = server_result.get("retry_count", 0) + 1
                server_result["status"] = "error"

                save_progress(results)

                print(f"    Erreur : {error_msg}")
                log_error(
                    f"Erreur traitement serveur | episode={episode['title']} | "
                    f"server_url={server['server_page_url']}\n{traceback.format_exc()}"
                )

        all_done = all(
            s.get("status") == "done"
            for s in episode_result.get("servers", [])
        ) if episode_result.get("servers") else False

        episode_result["status"] = "done" if all_done else "in_progress"

        try:
            save_progress(results)
            print("  Résultats épisode sauvegardés.")
        except Exception as e:
            print(f"  Erreur sauvegarde épisode : {e}")
            log_error(
                f"Erreur sauvegarde épisode | episode={episode['title']}\n"
                f"{traceback.format_exc()}"
            )

        print("-" * 80)

    return results


def main():
    if not LIST_PAGE_URL:
        print("Merci de définir LIST_PAGE_URL.")
        return

    try:
        data = scrape_all(LIST_PAGE_URL)
        save_progress(data)
        print(f"Fichiers créés / mis à jour : {JSON_FILE}, {EXCEL_FILE}")
    except KeyboardInterrupt:
        print("Script interrompu par l'utilisateur.")
        print("Les résultats déjà sauvegardés ont été conservés.")
    except Exception as e:
        print(f"Erreur fatale : {e}")
        log_error(f"Erreur fatale\n{traceback.format_exc()}")


if __name__ == "__main__":
    main()