<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    /**
     * The apps are built from docs/api/openapi.json. If an endpoint, request rule or response shape changes
     * without re-exporting, this fails: run `composer openapi` and commit the new file.
     */
    public function test_the_committed_openapi_document_matches_the_code(): void
    {
        // A separate process with its own migrated database: the exporter reads column types from the schema,
        // and nothing other tests did to the application can leak into the document.
        $fresh = tempnam(sys_get_temp_dir(), 'openapi');
        $database = tempnam(sys_get_temp_dir(), 'openapi-db');
        $env = ['APP_ENV' => 'local', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => ''];

        $migrated = Process::path(base_path())->env($env)->run([PHP_BINARY, 'artisan', 'migrate', '--force']);
        $this->assertTrue($migrated->successful(), $migrated->errorOutput());

        $exported = Process::path(base_path())->env($env)->run([PHP_BINARY, 'artisan', 'scramble:export', '--path='.$fresh]);
        $this->assertTrue($exported->successful(), $exported->errorOutput());
        @unlink($database);

        $committed = json_decode((string) file_get_contents(base_path('../../docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $regenerated = json_decode((string) file_get_contents($fresh), true, flags: JSON_THROW_ON_ERROR);
        @unlink($fresh);

        $this->assertSame($committed, $regenerated, 'docs/api/openapi.json is out of date. Run: composer openapi');
    }

    public function test_the_contract_excludes_development_helpers_and_provider_callbacks(): void
    {
        $paths = array_keys(json_decode((string) file_get_contents(base_path('../../docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR)['paths']);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('/dev/', $path);
            $this->assertStringNotContainsString('/webhooks/', $path);
        }
    }
}
