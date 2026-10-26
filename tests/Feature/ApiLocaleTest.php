<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiLocaleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function languages(): array
    {
        return [
            'Arabic' => ['ar', 'ar'],
            'English' => ['en', 'en'],
            'French' => ['fr', 'fr'],
            'French from France' => ['fr-FR,fr;q=0.9', 'fr'],
            'a language the API does not know' => ['de', 'ar'],
        ];
    }

    #[DataProvider('languages')]
    public function test_the_api_answers_in_the_language_the_app_asks_for(string $header, string $locale): void
    {
        $this->getJson('/api/v1/store', ['Accept-Language' => $header])->assertOk();

        $this->assertSame($locale, App::getLocale());
    }
}
