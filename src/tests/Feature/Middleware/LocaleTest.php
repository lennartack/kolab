<?php

namespace Tests\Feature\Middleware;

use Tests\TestCase;

class LocaleTest extends TestCase
{
    /**
     * Test setting locale using Cookie
     */
    public function testLocaleFromCookie(): void
    {
        $user = $this->getTestUser('john@kolab.org');

        $headers = ['Accept-Language' => '*'];

        // Test with no headers (expect default locale)
        $response = $this->actingAs($user)->withHeaders($headers)->get('api/auth/location');
        $response->assertStatus(200);

        $this->assertTrue(\app()->isLocale(\config('app.locale')));

        // Test with 'language' cookie - invalid language
        $headers['Cookie'] = 'language=unk';
        $response = $this->actingAs($user)->withHeaders($headers)->get('api/auth/location');
        $response->assertStatus(200);

        $this->assertTrue(\app()->isLocale(\config('app.locale')));

        // Test with 'language' cookie - valid API language
        $headers['Cookie'] = 'language=fi';
        $response = $this->actingAs($user)->withHeaders($headers)->get('api/auth/location');
        $response->assertStatus(200);

        $this->assertTrue(\app()->isLocale('fi'));

        // Test with 'language' cookie - invalid UI language
        $headers['Cookie'] = 'language=fi';
        $response = $this->actingAs($user)->withHeaders($headers)->get('dashboard');
        $response->assertStatus(200);

        $this->assertTrue(\app()->isLocale(\config('app.locale')));

        // Test with 'language' cookie - valid UI language
        $headers['Cookie'] = 'language=fr';
        $response = $this->actingAs($user)->withHeaders($headers)->get('dashboard');
        $response->assertStatus(200);

        $this->assertTrue(\app()->isLocale('fr'));
    }

    /**
     * Test setting locale using Accept-Language
     */
    public function testLocaleFromAcceptLanguage(): void
    {
        $user = $this->getTestUser('john@kolab.org');

        $headers = ['Accept-Language' => '*'];

        // Test with no headers (expect default locale)
        $response = $this->actingAs($user)->withHeaders($headers)->get('api/auth/location');
        $response->assertStatus(200);

        $this->assertTrue(\app()->isLocale(\config('app.locale')));

        // Test with 'language' cookie - invalid language
        $headers['Accept-Language'] = 'unk;q=0.9';
        $response = $this->actingAs($user)->withHeaders($headers)->get('api/auth/location');
        $response->assertStatus(200);

        $this->assertTrue(\app()->isLocale(\config('app.locale')));

        // Test with 'language' cookie - valid API language
        $headers['Accept-Language'] = 'fi;q=0.9';
        $response = $this->actingAs($user)->withHeaders($headers)->get('api/auth/location');
        $response->assertStatus(200);

        $this->assertTrue(\app()->isLocale('fi'));

        // Test with 'language' cookie - invalid UI language
        $headers['Accept-Language'] = 'fi;q=0.9';
        $response = $this->actingAs($user)->withHeaders($headers)->get('dashboard');
        $response->assertStatus(200);

        $this->assertTrue(\app()->isLocale(\config('app.locale')));

        // Test with 'language' cookie - valid UI language
        $headers['Accept-Language'] = 'fi;q=0.9, fr;q=0.8';
        $response = $this->actingAs($user)->withHeaders($headers)->get('dashboard');
        $response->assertStatus(200);

        $this->assertTrue(\app()->isLocale('fr'));
    }
}
