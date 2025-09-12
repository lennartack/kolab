<?php

namespace Tests\Feature\Controller;

use App\Http\Controllers\API\V4\ConfigController;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    /**
     * Test webmail configuration (GET /api/v4/config/webmail)
     */
    public function testWebmail(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $joe = $this->getTestUser('joe@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        $response = $this->get('api/v4/config/webmail');
        $response->assertStatus(401);

        $response = $this->actingAs($john)->get('api/v4/config/webmail');
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(['kolab4', 'groupware'], $json['kolab-configuration-overlays']);
        $this->assertNull($json['debug']);

        // Ned has groupware, activesync and 2FA
        $response = $this->actingAs($ned)->get('api/v4/config/webmail');
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(['kolab4', 'activesync', '2fa', 'groupware'], $json['kolab-configuration-overlays']);

        // Joe has no groupware subscription
        $setting = $joe->settings()->updateOrCreate(['key' => 'debug'], ['value' => 'roundcube,syncroton']);
        $response = $this->actingAs($joe)->get('api/v4/config/webmail');
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(['kolab4'], $json['kolab-configuration-overlays']);
        $this->assertSame($setting->value, $json['debug']);

        // Test that the debug mode expires
        $setting->timestamps = false;
        $setting->updated_at = now()->subHours(ConfigController::DEBUG_TTL + 1);
        $setting->save();
        $response = $this->actingAs($joe)->get('api/v4/config/webmail');
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertNull($json['debug']);
        $this->assertNull($joe->getSetting('debug'));
    }
}
