<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

class ProductionUrlsTest extends TestCase
{
    public function test_production_urls_use_configured_https_origin_behind_proxy(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://guildhall-test.up.railway.app']);
        (new AppServiceProvider($this->app))->boot();
        $this->assertSame('https://guildhall-test.up.railway.app/login', route('login'));
    }
}
