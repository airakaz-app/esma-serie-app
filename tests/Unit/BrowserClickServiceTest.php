<?php

namespace Tests\Unit;

use App\Services\Scraper\BrowserClickService;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BrowserClickServiceTest extends TestCase
{
    public function test_it_extracts_a_direct_media_url_from_http_html(): void
    {
        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('extractFinalUrlFromHttpHtml');

        $url = $method->invoke(
            $service,
            '<script>const file = "https://cdn.example.com/video/episode-1.mp4";</script>',
            'https://iframe.example.com/embed/123'
        );

        $this->assertSame('https://cdn.example.com/video/episode-1.mp4', $url);
    }

    public function test_it_builds_a_tokenized_fallback_url_when_no_media_url_is_found(): void
    {
        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('extractFinalUrlFromHttpHtml');

        $url = $method->invoke(
            $service,
            '<script>window.playerToken = "abc123";</script>',
            'https://iframe.example.com/embed/123'
        );

        $this->assertSame('https://iframe.example.com/embed/123?token=abc123', $url);
    }

    public function test_it_resolves_protocol_relative_urls(): void
    {
        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('extractFinalUrlFromHttpHtml');

        $url = $method->invoke(
            $service,
            '<script>const source = "//cdn.example.com/stream/episode-1.m3u8";</script>',
            'https://iframe.example.com/embed/123'
        );

        $this->assertSame('https://cdn.example.com/stream/episode-1.m3u8', $url);
    }

    public function test_it_extracts_method_free_form_step_with_hidden_payload(): void
    {
        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('findFormStepByTriggerId');

        $step = $method->invoke(
            $service,
            '<form action="/dl" method="post"><input type="hidden" name="id" value="abc"><button id="method_free" name="op" value="free">Free Download</button></form>',
            'https://test.live/2t8d9gf58f75.html',
            'method_free'
        );

        $this->assertSame('https://test.live/dl', $step['action']);
        $this->assertSame('POST', $step['method']);
        $this->assertSame([
            'id' => 'abc',
            'op' => 'free',
        ], $step['payload']);
    }

    public function test_it_returns_null_when_trigger_button_does_not_exist(): void
    {
        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('findFormStepByTriggerId');

        $step = $method->invoke(
            $service,
            '<form action="/dl" method="post"><input type="hidden" name="id" value="abc"></form>',
            'https://test.live/2t8d9gf58f75.html',
            'downloadbtn'
        );

        $this->assertNull($step);
    }

    public function test_it_does_not_skip_webdriver_fallback_when_python_error_is_driver_infrastructure_related(): void
    {
        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('shouldAttemptWebDriverFallback');

        $shouldFallback = $method->invoke(
            $service,
            'RuntimeError: Aucun WebDriver disponible. Tentatives: remote:http://127.0.0.1:9515 => Failed to establish a new connection: [Errno 111] Connection refused'
        );

        $this->assertTrue($shouldFallback);
    }


    public function test_it_keeps_webdriver_fallback_for_generic_python_errors(): void
    {
        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('shouldAttemptWebDriverFallback');

        $shouldFallback = $method->invoke($service, 'Unexpected runtime issue during bridge execution');

        $this->assertTrue($shouldFallback);
    }

    public function test_it_does_not_skip_webdriver_fallback_when_selenium_manager_returns_status_code_minus_five(): void
    {
        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('shouldAttemptWebDriverFallback');

        $shouldFallback = $method->invoke(
            $service,
            'RuntimeError: Service /home/user/.cache/selenium/chromedriver/linux64/146.0.7680.80/chromedriver unexpectedly exited. Status code was: -5'
        );

        $this->assertTrue($shouldFallback);
    }

    public function test_it_uses_extended_default_python_timeout_when_not_configured(): void
    {
        config()->set('scraper.python_timeout', 0);
        config()->set('scraper.browser_timeout', 30);

        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('resolvePythonTimeout');

        $timeout = $method->invoke($service);

        $this->assertSame(180.0, $timeout);
    }

    public function test_it_enforces_minimum_safe_python_timeout_when_configured_value_is_too_low(): void
    {
        config()->set('scraper.python_timeout', 60);
        config()->set('scraper.browser_timeout', 30);

        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('resolvePythonTimeout');

        $timeout = $method->invoke($service);

        $this->assertSame(180.0, $timeout);
    }

}
