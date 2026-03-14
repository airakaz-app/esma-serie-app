import argparse
import json
import os
import time
import traceback
from typing import List, Tuple

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.remote.webdriver import WebDriver
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait


def build_options(headless: bool) -> Options:
    options = Options()

    if headless:
        options.add_argument('--headless=new')

    options.add_argument('--disable-blink-features=AutomationControlled')
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--window-size=1920,1080')

    binary_location = os.getenv('SCRAPER_CHROME_BINARY', '').strip()
    if binary_location:
        options.binary_location = binary_location

    return options


def parse_webdriver_urls() -> List[str]:
    candidates = [
        os.getenv('SCRAPER_WEBDRIVER_URL', '').strip(),
        *[url.strip() for url in os.getenv('SCRAPER_WEBDRIVER_FALLBACK_URLS', '').split(',')],
    ]

    unique_candidates = []
    for candidate in candidates:
        if candidate and candidate not in unique_candidates:
            unique_candidates.append(candidate)

    return unique_candidates


def parse_webdriver_binary_candidates() -> List[str]:
    configured = os.getenv('SCRAPER_WEBDRIVER_BINARY', '').strip()
    candidates = [configured, 'chromedriver', 'chromium-driver']

    unique_candidates = []
    for candidate in candidates:
        if candidate and candidate not in unique_candidates:
            unique_candidates.append(candidate)

    return unique_candidates


def build_driver(headless: bool) -> Tuple[WebDriver, str]:
    options = build_options(headless)
    errors = []

    for command_executor in parse_webdriver_urls():
        try:
            return webdriver.Remote(command_executor=command_executor, options=options), f'remote:{command_executor}'
        except Exception as exc:
            errors.append(f'remote:{command_executor} => {exc}')

    for binary in parse_webdriver_binary_candidates():
        try:
            service = Service(executable_path=binary)
            return webdriver.Chrome(service=service, options=options), f'local:{binary}'
        except Exception as exc:
            errors.append(f'local:{binary} => {exc}')

    try:
        return webdriver.Chrome(options=options), 'local:selenium-manager'
    except Exception as exc:
        errors.append(f'local:selenium-manager => {exc}')

    raise RuntimeError('Aucun WebDriver disponible. Tentatives: ' + ' | '.join(errors))


def resolve_url(iframe_url: str, timeout: int, headless: bool):
    driver, driver_source = build_driver(headless=headless)

    try:
        wait = WebDriverWait(driver, timeout)
        driver.get(iframe_url)

        wait.until(lambda d: d.execute_script('return document.readyState') == 'complete')

        free_btn = wait.until(EC.element_to_be_clickable((By.ID, 'method_free')))
        free_btn.click()

        time.sleep(1)

        download_btn = wait.until(EC.element_to_be_clickable((By.ID, 'downloadbtn')))
        download_btn.click()

        wait.until(lambda d: d.execute_script('return document.readyState') == 'complete')
        time.sleep(2)

        return {
            'success': True,
            'final_url': driver.current_url,
            'final_html': driver.page_source,
            'error': '',
            'driver_source': driver_source,
        }
    except Exception as exc:
        return {
            'success': False,
            'final_url': '',
            'final_html': '',
            'error': f"{exc}\n{traceback.format_exc()}",
            'driver_source': driver_source,
        }
    finally:
        driver.quit()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--iframe-url', required=True)
    parser.add_argument('--timeout', type=int, default=30)
    parser.add_argument('--headless', default='1')
    args = parser.parse_args()

    try:
        result = resolve_url(
            iframe_url=args.iframe_url,
            timeout=args.timeout,
            headless=args.headless == '1',
        )
    except Exception as exc:
        result = {
            'success': False,
            'final_url': '',
            'final_html': '',
            'error': f"{exc}\n{traceback.format_exc()}",
        }

    print(json.dumps(result, ensure_ascii=False))


if __name__ == '__main__':
    main()
