<?php

namespace Tests\Unit;

use App\Services\Scraper\BrowserClickService;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BrowserClickServiceTest extends TestCase
{
    public function test_it_skips_webdriver_fallback_when_python_error_is_driver_infrastructure_related(): void
    {
        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('shouldAttemptWebDriverFallback');

        $shouldFallback = $method->invoke(
            $service,
            'RuntimeError: Aucun WebDriver disponible. Tentatives: remote:http://127.0.0.1:9515 => Failed to establish a new connection: [Errno 111] Connection refused'
        );

        $this->assertFalse($shouldFallback);
    }

    public function test_it_keeps_webdriver_fallback_for_generic_python_errors(): void
    {
        $service = new BrowserClickService(new Factory());
        $method = (new ReflectionClass($service))->getMethod('shouldAttemptWebDriverFallback');

        $shouldFallback = $method->invoke($service, 'Unexpected runtime issue during bridge execution');

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
