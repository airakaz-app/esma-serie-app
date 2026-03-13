import argparse
import json
import traceback

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait


def build_driver(headless: bool):
    options = Options()

    if headless:
        options.add_argument('--headless=new')

    options.add_argument('--disable-blink-features=AutomationControlled')
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--window-size=1920,1080')

    return webdriver.Chrome(options=options)


def resolve_url(iframe_url: str, timeout: int, headless: bool):
    driver = build_driver(headless=headless)

    try:
        wait = WebDriverWait(driver, timeout)
        driver.get(iframe_url)

        wait.until(lambda d: d.execute_script('return document.readyState') == 'complete')

        free_btn = wait.until(EC.element_to_be_clickable((By.ID, 'method_free')))
        free_btn.click()

        download_btn = wait.until(EC.element_to_be_clickable((By.ID, 'downloadbtn')))
        download_btn.click()

        wait.until(lambda d: d.execute_script('return document.readyState') == 'complete')

        return {
            'success': True,
            'final_url': driver.current_url,
            'final_html': driver.page_source,
            'error': '',
        }
    except Exception as exc:
        return {
            'success': False,
            'final_url': '',
            'final_html': '',
            'error': f"{exc}\n{traceback.format_exc()}",
        }
    finally:
        driver.quit()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--iframe-url', required=True)
    parser.add_argument('--timeout', type=int, default=30)
    parser.add_argument('--headless', default='1')
    args = parser.parse_args()

    result = resolve_url(
        iframe_url=args.iframe_url,
        timeout=args.timeout,
        headless=args.headless == '1',
    )

    print(json.dumps(result, ensure_ascii=False))


if __name__ == '__main__':
    main()
