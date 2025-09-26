<?php

namespace Tests\Feature\Controller;

use App\Package;
use Tests\TestCase;

class PackagesTest extends TestCase
{
    /**
     * Test fetching packages list
     */
    public function testIndex(): void
    {
        // Unauth access not allowed
        $response = $this->get("api/v4/packages");
        $response->assertStatus(401);

        $user = $this->getTestUser('john@kolab.org');

        $packageDomain = Package::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $packageKolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $packageLite = Package::withEnvTenantContext()->where('title', 'lite')->first();

        $response = $this->actingAs($user)->get("api/v4/packages");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(3, $json['list']);
        $this->assertSame(3, $json['count']);
        $this->assertSame('success', $json['status']);
        $this->assertSame('3 packages have been found.', $json['message']);
        $this->assertFalse($json['hasMore']);

        $this->assertSame($packageDomain->id, $json['list'][0]['id']);
        $this->assertSame($packageDomain->title, $json['list'][0]['title']);
        $this->assertSame($packageDomain->name, $json['list'][0]['name']);
        $this->assertSame($packageDomain->description, $json['list'][0]['description']);
        $this->assertSame($packageDomain->isDomain(), $json['list'][0]['isDomain']);
        $this->assertSame($packageDomain->cost(), $json['list'][0]['cost']);

        $this->assertSame($packageKolab->id, $json['list'][1]['id']);
        $this->assertSame($packageKolab->title, $json['list'][1]['title']);
        $this->assertSame($packageKolab->name, $json['list'][1]['name']);
        $this->assertSame($packageKolab->description, $json['list'][1]['description']);
        $this->assertSame($packageKolab->isDomain(), $json['list'][1]['isDomain']);
        $this->assertSame($packageKolab->cost(), $json['list'][1]['cost']);

        $this->assertSame($packageLite->id, $json['list'][2]['id']);
        $this->assertSame($packageLite->title, $json['list'][2]['title']);
        $this->assertSame($packageLite->name, $json['list'][2]['name']);
        $this->assertSame($packageLite->description, $json['list'][2]['description']);
        $this->assertSame($packageLite->isDomain(), $json['list'][2]['isDomain']);
        $this->assertSame($packageLite->cost(), $json['list'][2]['cost']);
    }
}
