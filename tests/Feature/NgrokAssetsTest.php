<?php

namespace Tests\Feature;

use App\Support\RequestAwareVite;
use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NgrokAssetsTest extends TestCase
{
    private string $fixtureDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        // Do not depend on npm builds, a running Vite server, or the real public/hot.
        $this->fixtureDirectory = sys_get_temp_dir().'/pelekapro-vite-'.Str::uuid();
        File::ensureDirectoryExists($this->fixtureDirectory.'/build');
        $this->app->usePublicPath($this->fixtureDirectory);

        File::put($this->fixtureDirectory.'/hot', 'http://127.0.0.1:5173');
        File::put($this->fixtureDirectory.'/build/manifest.json', json_encode([
            'resources/css/app.css' => ['src' => 'resources/css/app.css', 'file' => 'assets/test.css', 'isEntry' => true],
            'resources/js/app.js' => ['src' => 'resources/js/app.js', 'file' => 'assets/test.js', 'isEntry' => true],
        ], JSON_THROW_ON_ERROR));

        $fontManifest = [
            'version' => 1,
            'families' => ['test-font' => ['family' => 'Test Font']],
        ];

        File::put($this->fixtureDirectory.'/build/fonts-manifest.json', json_encode([
            ...$fontManifest,
            'preloads' => [['alias' => 'test-font', 'file' => 'assets/test-font.woff2']],
            'style' => ['inline' => '@font-face { font-family: "Test Font"; src: url("/build/assets/test-font.woff2"); }'],
        ], JSON_THROW_ON_ERROR));

        File::put($this->fixtureDirectory.'/fonts-manifest.dev.json', json_encode([
            ...$fontManifest,
            'preloads' => [[
                'alias' => 'test-font',
                'url' => 'http://127.0.0.1:5173/__laravel_vite_plugin__/fonts/test-font.woff2',
            ]],
            'style' => ['inline' => '@font-face { font-family: "Test Font"; src: url("http://127.0.0.1:5173/test-font.woff2"); }'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->fixtureDirectory)) {
                File::deleteDirectory($this->fixtureDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_ngrok_uses_https_same_origin_built_assets_and_fonts_even_when_vite_is_running(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://first.ngrok-free.dev/login');

        $response->assertOk()
            ->assertSee('https://first.ngrok-free.dev/build/assets/test.js', false)
            ->assertSee('https://first.ngrok-free.dev/build/assets/test.css', false)
            ->assertSee('https://first.ngrok-free.dev/build/assets/test-font.woff2', false)
            ->assertSee('action="https://first.ngrok-free.dev/login"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee(':5173', false)
            ->assertDontSee('@vite/client', false)
            ->assertDontSee('http://first.ngrok-free.dev', false);

        $this->assertFileExists($this->fixtureDirectory.'/hot');
        $this->assertSame('http://127.0.0.1:5173', File::get($this->fixtureDirectory.'/hot'));
    }

    public function test_new_ngrok_domains_work_without_changing_configuration_or_deleting_the_hot_file(): void
    {
        config()->set('app.url', 'http://localhost');

        foreach (['first.ngrok-free.dev', 'second.ngrok-free.app'] as $host) {
            $this->get("https://{$host}/login")
                ->assertOk()
                ->assertSee("https://{$host}/build/assets/test.js", false)
                ->assertSee("https://{$host}/build/assets/test-font.woff2", false)
                ->assertDontSee(':5173', false);
        }
    }

    #[DataProvider('localOrigins')]
    public function test_local_http_development_keeps_hot_reload_and_development_fonts(string $origin): void
    {
        $this->get($origin.'/login')
            ->assertOk()
            ->assertSee('http://127.0.0.1:5173/@vite/client', false)
            ->assertSee('http://127.0.0.1:5173/resources/js/app.js', false)
            ->assertSee('http://127.0.0.1:5173/resources/css/app.css', false)
            ->assertSee('http://127.0.0.1:5173/__laravel_vite_plugin__/fonts/test-font.woff2', false)
            ->assertDontSee('/build/assets/test.js', false);
    }

    public static function localOrigins(): array
    {
        return [
            'localhost' => ['http://localhost:8000'],
            'IPv4 loopback' => ['http://127.0.0.1:8000'],
            'IPv6 loopback' => ['http://[::1]:8000'],
        ];
    }

    #[DataProvider('externalOrigins')]
    public function test_all_non_loopback_hosts_use_built_assets(string $origin): void
    {
        $this->get($origin.'/login')
            ->assertOk()
            ->assertSee($origin.'/build/assets/test.js', false)
            ->assertDontSee(':5173', false);
    }

    public static function externalOrigins(): array
    {
        return [
            'LAN phone access' => ['http://192.168.1.20:8000'],
            'hostname containing localhost' => ['https://localhost.example.com'],
            'hostname containing loopback' => ['https://127.0.0.1.example.com'],
        ];
    }

    public function test_local_https_uses_built_assets_instead_of_insecure_hot_reload(): void
    {
        $this->get('https://localhost/login')
            ->assertOk()
            ->assertSee('https://localhost/build/assets/test.js', false)
            ->assertDontSee(':5173', false);
    }

    public function test_local_https_can_use_a_secure_development_server(): void
    {
        File::put($this->fixtureDirectory.'/hot', 'https://localhost:5173');
        $this->app->instance('request', Request::create('https://localhost/login'));

        $this->assertTrue(app(Vite::class)->isRunningHot());
    }

    public function test_local_requests_use_built_assets_when_the_hot_file_is_absent(): void
    {
        File::delete($this->fixtureDirectory.'/hot');

        $this->get('http://localhost/login')
            ->assertOk()
            ->assertSee('http://localhost/build/assets/test.js', false)
            ->assertSee('http://localhost/build/assets/test-font.woff2', false)
            ->assertDontSee(':5173', false);
    }

    public function test_production_never_uses_a_leftover_hot_file_even_on_localhost(): void
    {
        $this->app->instance('env', 'production');

        $this->get('http://localhost/login')
            ->assertOk()
            ->assertSee('http://localhost/build/assets/test.js', false)
            ->assertDontSee(':5173', false);
    }

    public function test_singleton_checks_the_current_request_without_mutating_the_hot_file_path(): void
    {
        $vite = app(Vite::class);
        $this->assertInstanceOf(RequestAwareVite::class, $vite);

        foreach ([
            'http://localhost/login' => true,
            'https://first.ngrok-free.dev/login' => false,
            'http://127.0.0.1:8000/login' => true,
        ] as $url => $expected) {
            $this->app->instance('request', Request::create($url));
            $this->assertSame($vite, app(Vite::class));
            $this->assertSame($expected, $vite->isRunningHot());
            $this->assertSame($this->fixtureDirectory.'/hot', $vite->hotFile());
        }
    }

    public function test_vite_asset_helper_uses_the_same_request_aware_policy(): void
    {
        $vite = app(Vite::class);
        $this->app->instance('request', Request::create('https://first.ngrok-free.dev/login'));
        app('url')->setRequest($this->app->make('request'));

        $this->assertSame('https://first.ngrok-free.dev/build/assets/test.js', $vite->asset('resources/js/app.js'));

        $this->app->instance('request', Request::create('http://localhost/login'));
        $this->assertSame('http://127.0.0.1:5173/resources/js/app.js', $vite->asset('resources/js/app.js'));
    }

    public function test_public_requests_never_fall_back_to_hot_reload_when_the_build_is_missing(): void
    {
        File::delete($this->fixtureDirectory.'/build/manifest.json');
        $this->app->instance('request', Request::create('https://first.ngrok-free.dev/login'));

        $this->expectException(ViteManifestNotFoundException::class);
        app(Vite::class)(['resources/js/app.js']);
    }
}
