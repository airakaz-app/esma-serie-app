import json
import logging
import os
import re
import tempfile
import time
import traceback
from datetime import datetime
from pathlib import Path
from urllib.parse import urljoin

import pandas as pd
import requests
from bs4 import BeautifulSoup
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait


HEADERS = {
    "User-Agent": "Mozilla/5.0"
}

LIST_PAGE_URL = "https://n.esheaq.onl/watch/no9k6e3v89/see/"  # replace with your list page

BASE_DIR = Path(__file__).resolve().parent
JSON_FILE = str(BASE_DIR / "results.json")
EXCEL_FILE = str(BASE_DIR / "results.xlsx")
ERROR_LOG_FILE = str(BASE_DIR / "errors.log")
ACTIVITY_LOG_FILE = str(BASE_DIR / "scraper_activity.log")
LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO").upper()

session = requests.Session()
session.headers.update(HEADERS)

logger = logging.getLogger("serie_scraper")


def setup_logging():
    if logger.handlers:
        return

    level = getattr(logging, LOG_LEVEL, logging.INFO)
    logger.setLevel(level)

    formatter = logging.Formatter("%(asctime)s | %(levelname)s | %(message)s")

    stream_handler = logging.StreamHandler()
    stream_handler.setFormatter(formatter)

    file_handler = logging.FileHandler(ACTIVITY_LOG_FILE, encoding="utf-8")
    file_handler.setFormatter(formatter)

    logger.addHandler(stream_handler)
    logger.addHandler(file_handler)
    logger.propagate = False


def format_context(**context) -> str:
    parts = []

    for key, value in context.items():
        if value in ("", None, [], {}):
            continue
        parts.append(f"{key}={value}")

    return " | ".join(parts)


def log_message(level: int, message: str, **context):
    suffix = format_context(**context)
    logger.log(level, f"{message}{' | ' + suffix if suffix else ''}")


def log_info(message: str, **context):
    log_message(logging.INFO, message, **context)


def log_warning(message: str, **context):
    log_message(logging.WARNING, message, **context)


def log_exception(message: str, **context):
    log_message(logging.ERROR, message, **context)


def count_servers(results) -> int:
    return sum(len(item.get("servers", [])) for item in results)


setup_logging()


def build_empty_info_payload():
    return {
        "source_episode_page_url": "",
        "series_page_url": "",
        "title": "",
        "title_url": "",
        "cover_image_url": "",
        "story": "",
        "categories": [],
        "actors": [],
    }


def normalize_info_payload(info):
    normalized = build_empty_info_payload()

    if not isinstance(info, dict):
        return normalized

    for key in normalized:
        value = info.get(key, normalized[key])

        if key in {"categories", "actors"}:
            normalized[key] = value if isinstance(value, list) else []
        else:
            normalized[key] = value if isinstance(value, str) else normalized[key]

    return normalized


def info_has_content(info) -> bool:
    if not isinstance(info, dict):
        return False

    return any(value not in ("", [], {}, None) for value in info.values())


def build_results_payload(episodes, info=None):
    return {
        "episodes": episodes,
        "info": normalize_info_payload(info),
    }


def extract_results_payload(payload):
    if isinstance(payload, list):
        return payload, build_empty_info_payload(), "legacy_list"

    if isinstance(payload, dict):
        episodes = payload.get("episodes", [])
        info = payload.get("info", {})

        if isinstance(episodes, list):
            return episodes, normalize_info_payload(info), "wrapped_object"
        return [], normalize_info_payload(info), "invalid_wrapped_object"

    return [], build_empty_info_payload(), "invalid_payload"


def log_error(message: str):
    with open(ERROR_LOG_FILE, "a", encoding="utf-8") as f:
        f.write(f"[{datetime.now().isoformat()}] {message}\n")

    log_exception("Detailed error saved", details=message.replace("\n", " || "))


def get_soup(url: str) -> BeautifulSoup:
    log_info("HTTP GET start", url=url)
    start = time.perf_counter()

    response = session.get(url, timeout=20)
    response.raise_for_status()
    response.encoding = "utf-8"

    elapsed = f"{time.perf_counter() - start:.2f}s"
    log_info(
        "HTTP GET done",
        url=url,
        status=response.status_code,
        elapsed=elapsed,
        size=len(response.text),
    )
    return BeautifulSoup(response.text, "html.parser")


def safe_save_json(data, filename=JSON_FILE, info=None):
    log_info("Saving JSON", filename=filename, episodes=len(data))
    payload = build_results_payload(data, info=info)

    dir_name = os.path.dirname(filename) or "."
    with tempfile.NamedTemporaryFile(
        "w",
        delete=False,
        dir=dir_name,
        encoding="utf-8",
    ) as tmp:
        json.dump(payload, tmp, ensure_ascii=False, indent=2)
        temp_name = tmp.name

    os.replace(temp_name, filename)
    log_info("JSON saved", filename=filename, episodes=len(data))


def flatten_rows(data):
    rows = []

    for item in data:
        title = item.get("title", "")
        page_url = item.get("page_url", "")
        episode_status = item.get("status", "")
        episode_number = item.get("episode_number", "")
        image_url = item.get("image_url", "")

        servers = item.get("servers", [])
        if not servers:
            rows.append({
                "title": title,
                "page_url": page_url,
                "episode_status": episode_status,
                "episode_number": episode_number,
                "image_url": image_url,
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
                "error": item.get("error", ""),
            })
            continue

        for server in servers:
            rows.append({
                "title": title,
                "page_url": page_url,
                "episode_status": episode_status,
                "episode_number": episode_number,
                "image_url": image_url,
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
                "error": server.get("error", ""),
            })

    return rows


def safe_save_excel(data, filename=EXCEL_FILE):
    rows = flatten_rows(data)
    log_info("Saving Excel", filename=filename, rows=len(rows))

    dir_name = os.path.dirname(filename) or "."
    with tempfile.NamedTemporaryFile(
        delete=False,
        dir=dir_name,
        suffix=".xlsx",
    ) as tmp:
        temp_name = tmp.name

    df = pd.DataFrame(rows)

    with pd.ExcelWriter(temp_name, engine="openpyxl") as writer:
        df.to_excel(writer, index=False, sheet_name="results")

    os.replace(temp_name, filename)
    log_info("Excel saved", filename=filename, rows=len(rows))


def save_progress(results, info=None):
    log_info(
        "Saving progress snapshot",
        episodes=len(results),
        servers=count_servers(results),
    )
    safe_save_json(results, JSON_FILE, info=info)
    safe_save_excel(results, EXCEL_FILE)


def load_existing_payload(filename=JSON_FILE):
    if not os.path.exists(filename):
        log_info("No previous results file found", filename=filename)
        return [], build_empty_info_payload()

    try:
        with open(filename, "r", encoding="utf-8") as f:
            payload = json.load(f)

        items, info, payload_format = extract_results_payload(payload)
        log_info(
            "Previous results loaded",
            filename=filename,
            episodes=len(items),
            servers=count_servers(items),
            info_keys=len(info),
            format=payload_format,
        )
        return items, info
    except Exception:
        log_error(f"Erreur lecture {filename}:\n{traceback.format_exc()}")
        return [], build_empty_info_payload()


def load_existing_results(filename=JSON_FILE):
    episodes, _ = load_existing_payload(filename)
    return episodes


def get_episode_links(list_url: str):
    log_info("Collecting episode links", list_url=list_url)
    soup = get_soup(list_url)
    episodes = []
    articles = list(soup.select("article.postEp"))

    for article in reversed(articles):
        a_tag = article.select_one("a[href]")
        title_tag = article.select_one("div.title")

        if not a_tag or not title_tag:
            continue

        link_title = a_tag.get("title", "").strip()
        title = title_tag.get_text(strip=True) or link_title
        page_url = a_tag.get("href", "").strip()

        if not page_url:
            continue

        image_tag = article.select_one(".poster img")
        image_url = ""
        if image_tag:
            for attr_name in ("src", "data-src", "data-lazy-src", "data-original"):
                candidate = image_tag.get(attr_name, "").strip()
                if candidate:
                    image_url = urljoin(list_url, candidate)
                    break

        episode_number = ""
        for span in reversed(article.select(".episodeNum span")):
            span_text = span.get_text(strip=True)
            digits = "".join(char for char in span_text if char.isdigit())
            if digits:
                episode_number = digits
                break

        page_url = urljoin(list_url, page_url).rstrip("/") + "/see/"
        episodes.append({
            "title": title,
            "page_url": page_url,
            "episode_number": episode_number,
            "image_url": image_url,
        })

    log_info(
        "Episode links collected",
        list_url=list_url,
        count=len(episodes),
        order="oldest_to_newest",
    )
    return episodes


def get_series_page_url(episode_page_url: str) -> str:
    normalized_url = episode_page_url.rstrip("/")

    if normalized_url.endswith("/see"):
        normalized_url = normalized_url[:-4]

    return normalized_url + "/"


def extract_background_image_url(style_value: str, base_url: str) -> str:
    match = re.search(r"background-image\s*:\s*url\((['\"]?)(.*?)\1\)", style_value or "", re.IGNORECASE)
    if not match:
        return ""

    return urljoin(base_url, match.group(2).strip())


def get_info_from_first_episode(episodes, current_info):
    if info_has_content(current_info):
        log_info("Info already present, keeping existing payload", keys=",".join(sorted(current_info.keys())))
        return normalize_info_payload(current_info)

    if not episodes:
        log_warning("No episodes available to build info payload")
        return normalize_info_payload(current_info)

    first_episode = episodes[0]
    episode_page_url = first_episode.get("page_url", "").strip()
    if not episode_page_url:
        log_warning("First episode has no page_url, cannot build info payload")
        return normalize_info_payload(current_info)

    series_page_url = get_series_page_url(episode_page_url)
    log_info("Building info payload from first episode", episode_page_url=episode_page_url, series_page_url=series_page_url)

    try:
        soup = get_soup(series_page_url)
    except Exception as exc:
        log_exception("Failed to load series page for info payload", series_page_url=series_page_url, error=str(exc))
        return normalize_info_payload(current_info)

    container = soup.select_one("div.singleSeries")
    if not container:
        log_warning("singleSeries block not found", series_page_url=series_page_url)
        return normalize_info_payload(current_info)

    info_block = container.select_one("div.info") or container
    title_link = info_block.select_one("h1 a")
    title_text = title_link.get_text(" ", strip=True) if title_link else ""
    title_url = ""
    if title_link:
        href = title_link.get("href", "").strip()
        if href:
            title_url = urljoin(series_page_url, href)

    story_node = info_block.select_one("div.story")
    story_text = " ".join(story_node.stripped_strings) if story_node else ""

    cover_node = container.select_one("div.cover div.img")
    cover_image_url = extract_background_image_url(
        cover_node.get("style", "") if cover_node else "",
        series_page_url,
    )

    categories = []
    actors = []
    for tax_node in info_block.select("div.tax"):
        label_node = tax_node.select_one("span")
        label_text = label_node.get_text(" ", strip=True) if label_node else ""
        links = []
        for link in tax_node.select("a[href]"):
            href = link.get("href", "").strip()
            name = link.get_text(" ", strip=True)
            if not href or not name:
                continue
            links.append({
                "name": name,
                "url": urljoin(series_page_url, href),
            })

        has_category_links = any("/category/" in item["url"] for item in links)
        has_actor_links = any("/actor/" in item["url"] for item in links)

        if has_category_links or "تصنيفات" in label_text:
            categories = links
        elif has_actor_links or "الممثلين" in label_text:
            actors = links

    info_payload = {
        "source_episode_page_url": episode_page_url,
        "series_page_url": series_page_url,
        "title": title_text,
        "title_url": title_url,
        "cover_image_url": cover_image_url,
        "story": story_text,
        "categories": categories,
        "actors": actors,
    }

    log_info(
        "Info payload built",
        series_page_url=series_page_url,
        categories=len(categories),
        actors=len(actors),
        has_story=bool(story_text),
    )
    return normalize_info_payload(info_payload)


def get_servers_from_episode_page(page_url: str):
    log_info("Collecting servers from episode page", page_url=page_url)
    soup = get_soup(page_url)
    servers = []
    candidates = soup.select(".serversList li")

    for li in candidates:
        data_src = li.get("data-src", "").strip()
        server_name_tag = li.select_one("span")
        host_tag = li.select_one("em")

        server_name = server_name_tag.get_text(strip=True) if server_name_tag else ""
        host = host_tag.get_text(strip=True) if host_tag else ""

        if data_src and host.lower() == "vdesk":
            servers.append({
                "server_name": server_name,
                "host": host,
                "server_page_url": urljoin(page_url, data_src),
            })

    log_info(
        "Servers collected",
        page_url=page_url,
        candidates=len(candidates),
        kept=len(servers),
    )
    return servers


def clean_iframe_url(iframe_url: str) -> str:
    return iframe_url.replace("embed-", "")


def get_iframe_url(server_page_url: str):
    try:
        log_info("Searching iframe URL", server_page_url=server_page_url)
        soup = get_soup(server_page_url)

        iframes = soup.select("iframe[src]")
        if not iframes:
            iframes = soup.select("noscript iframe[src]")

        for iframe in iframes:
            src = iframe.get("src", "").strip()
            if not src:
                continue

            iframe_url = urljoin(server_page_url, src)
            cleaned_url = clean_iframe_url(iframe_url)
            log_info(
                "Iframe URL found",
                server_page_url=server_page_url,
                iframe_url=cleaned_url,
            )
            return cleaned_url

        log_warning("No iframe found", server_page_url=server_page_url)

    except Exception as exc:
        log_exception(
            "Iframe lookup failed",
            server_page_url=server_page_url,
            error=str(exc),
        )
        log_error(
            f"Erreur get_iframe_url | server_page_url={server_page_url}\n"
            f"{traceback.format_exc()}"
        )

    return None


def build_driver(headless: bool = True):
    log_info("Starting Chrome driver", headless=headless)
    options = Options()

    if headless:
        options.add_argument("--headless=new")

    options.add_argument("--disable-blink-features=AutomationControlled")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--window-size=1920,1080")

    return webdriver.Chrome(options=options)


def click_download_buttons(iframe_url: str, timeout: int = 25):
    log_info("Opening iframe in Selenium", iframe_url=iframe_url, timeout=timeout)
    driver = build_driver(headless=True)

    try:
        driver.get(iframe_url)
        wait = WebDriverWait(driver, timeout)
        log_info("Iframe page opened", iframe_url=iframe_url)

        wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
        log_info("DOM ready after first load", iframe_url=iframe_url)

        free_btn = wait.until(EC.element_to_be_clickable((By.ID, "method_free")))
        free_btn.click()
        log_info("Free button clicked", iframe_url=iframe_url)

        time.sleep(1)

        download_btn = wait.until(EC.element_to_be_clickable((By.ID, "downloadbtn")))
        download_btn.click()
        log_info("Download button clicked", iframe_url=iframe_url)

        wait.until(lambda d: d.execute_script("return document.readyState") == "complete")
        time.sleep(2)

        final_html = driver.page_source
        final_url = driver.current_url
        log_info("Selenium navigation completed", iframe_url=iframe_url, final_url=final_url)

        return {
            "success": True,
            "final_url": final_url,
            "final_html": final_html,
            "error": "",
        }

    except Exception as exc:
        log_exception(
            "Selenium click sequence failed",
            iframe_url=iframe_url,
            error=str(exc),
        )
        log_error(
            f"Erreur click_download_buttons | iframe_url={iframe_url}\n"
            f"{traceback.format_exc()}"
        )
        return {
            "success": False,
            "final_url": "",
            "final_html": "",
            "error": str(exc),
        }

    finally:
        log_info("Closing Chrome driver", iframe_url=iframe_url)
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
        "result_preview": text_preview,
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
    log_info("Scrape start", list_page_url=list_page_url)
    results, info = load_existing_payload(JSON_FILE)
    info_was_empty = not info_has_content(info)
    episodes = get_episode_links(list_page_url)
    info = get_info_from_first_episode(episodes, info)

    if info_was_empty and info_has_content(info):
        log_info("Persisting newly built info payload", info_keys=len(info))
        save_progress(results, info)

    log_info(
        "Episodes to process",
        count=len(episodes),
        existing_episodes=len(results),
        existing_servers=count_servers(results),
    )
    log_info("-" * 80)

    for ep_index, episode in enumerate(episodes, start=1):
        log_info(
            "Episode processing start",
            index=ep_index,
            total=len(episodes),
            title=episode["title"],
            page_url=episode["page_url"],
        )

        episode_result = find_existing_episode(results, episode["page_url"])

        if not episode_result:
            episode_result = {
                "title": episode["title"],
                "page_url": episode["page_url"],
                "episode_number": episode.get("episode_number", ""),
                "image_url": episode.get("image_url", ""),
                "status": "in_progress",
                "servers": [],
                "error": "",
            }
            results.append(episode_result)
            log_info("Episode added to progress file", title=episode["title"], page_url=episode["page_url"])
            save_progress(results, info)
        else:
            episode_result["episode_number"] = episode.get("episode_number", episode_result.get("episode_number", ""))
            episode_result["image_url"] = episode.get("image_url", episode_result.get("image_url", ""))

        if episode_result.get("status") == "done":
            log_info("Episode already done, skipping", title=episode["title"])
            log_info("-" * 80)
            continue

        try:
            servers = get_servers_from_episode_page(episode["page_url"])
        except Exception as exc:
            log_exception("Server list extraction failed", title=episode["title"], error=str(exc))
            episode_result["error"] = str(exc)
            log_error(
                f"Erreur get_servers_from_episode_page | episode={episode['title']} | "
                f"url={episode['page_url']}\n{traceback.format_exc()}"
            )
            save_progress(results, info)
            log_info("-" * 80)
            continue

        log_info("Servers ready for processing", title=episode["title"], count=len(servers))

        for server_index, server in enumerate(servers, start=1):
            log_info(
                "Server processing start",
                episode=episode["title"],
                index=server_index,
                total=len(servers),
                server_name=server["server_name"],
                host=server["host"],
                server_page_url=server["server_page_url"],
            )

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
                    "error": "",
                }
                episode_result["servers"].append(server_result)
                log_info(
                    "Server added to progress file",
                    episode=episode["title"],
                    server_page_url=server["server_page_url"],
                )
                save_progress(results, info)

            if server_result.get("status") == "done":
                log_info(
                    "Server already done, skipping",
                    episode=episode["title"],
                    server_page_url=server["server_page_url"],
                )
                continue

            iframe_url = server_result.get("iframe_url", "")
            final_url = server_result.get("final_url", "")
            error_msg = ""

            try:
                server_result["status"] = "in_progress"
                log_info(
                    "Server status updated",
                    episode=episode["title"],
                    server_page_url=server["server_page_url"],
                    status=server_result["status"],
                )
                save_progress(results, info)

                if not iframe_url:
                    iframe_url = get_iframe_url(server["server_page_url"]) or ""
                    server_result["iframe_url"] = iframe_url

                log_info(
                    "Iframe state",
                    episode=episode["title"],
                    server_page_url=server["server_page_url"],
                    iframe_url=iframe_url or "not_found",
                )

                if iframe_url and not final_url:
                    log_info(
                        "Launching click sequence",
                        episode=episode["title"],
                        iframe_url=iframe_url,
                    )
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
                        server_result["result_title"] = summary["result_title"]
                        server_result["result_h1"] = summary["result_h1"]
                        server_result["result_preview"] = summary["result_preview"]

                        log_info(
                            "Click sequence succeeded",
                            episode=episode["title"],
                            final_url=final_url or "empty",
                            result_title=summary["result_title"] or "empty",
                            result_h1=summary["result_h1"] or "empty",
                        )
                    else:
                        log_warning(
                            "Click sequence failed",
                            episode=episode["title"],
                            iframe_url=iframe_url,
                            error=error_msg or "unknown_error",
                        )

                server_result["status"] = "done" if server_result.get("final_url") else "error"
                log_info(
                    "Server status finalized",
                    episode=episode["title"],
                    server_page_url=server["server_page_url"],
                    status=server_result["status"],
                    has_final_url=bool(server_result.get("final_url")),
                )

                save_progress(results, info)
                log_info(
                    "Intermediate save completed",
                    episode=episode["title"],
                    server_page_url=server["server_page_url"],
                )

            except Exception as exc:
                server_result["error"] = str(exc)
                server_result["retry_count"] = server_result.get("retry_count", 0) + 1
                server_result["status"] = "error"

                save_progress(results, info)

                log_exception(
                    "Server processing error",
                    episode=episode["title"],
                    server_page_url=server["server_page_url"],
                    error=str(exc),
                    retry_count=server_result["retry_count"],
                )
                log_error(
                    f"Erreur traitement serveur | episode={episode['title']} | "
                    f"server_url={server['server_page_url']}\n{traceback.format_exc()}"
                )

        all_done = all(
            server.get("status") == "done"
            for server in episode_result.get("servers", [])
        ) if episode_result.get("servers") else False

        episode_result["status"] = "done" if all_done else "in_progress"
        log_info(
            "Episode status finalized",
            title=episode["title"],
            status=episode_result["status"],
            all_done=all_done,
        )

        try:
            save_progress(results, info)
            log_info("Episode results saved", title=episode["title"])
        except Exception as exc:
            log_exception("Episode save failed", title=episode["title"], error=str(exc))
            log_error(
                f"Erreur sauvegarde episode | episode={episode['title']}\n"
                f"{traceback.format_exc()}"
            )

        log_info("-" * 80)

    return results, info


def main():
    if not LIST_PAGE_URL:
        log_warning("LIST_PAGE_URL is empty")
        return

    try:
        log_info("Main start", list_page_url=LIST_PAGE_URL)
        data, info = scrape_all(LIST_PAGE_URL)
        save_progress(data, info)
        log_info(
            "Main completed",
            json_file=JSON_FILE,
            excel_file=EXCEL_FILE,
            episodes=len(data),
            info_keys=len(info),
            servers=count_servers(data),
        )
    except KeyboardInterrupt:
        log_warning("Script interrupted by user")
        log_info("Existing saved results were preserved")
    except Exception as exc:
        log_exception("Fatal error", error=str(exc))
        log_error(f"Erreur fatale\n{traceback.format_exc()}")


if __name__ == "__main__":
    main()
