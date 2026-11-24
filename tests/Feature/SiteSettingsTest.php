<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Servicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_site_settings_return_the_current_brand_defaults(): void
    {
        $this->getJson('/api/site-settings')
            ->assertOk()
            ->assertJsonPath('seo.title', 'DigitalPyme | Agencia digital para pymes en Lima')
            ->assertJsonPath('legal.privacy_published', false)
            ->assertJsonPath('legal.privacy', null)
            ->assertJsonPath('theme.dark', '#102c2c')
            ->assertJsonPath('theme.lime', '#c9f45b')
            ->assertJsonPath('theme.cream', '#f6f4ed')
            ->assertJsonPath('contact.whatsapp_phone', null)
            ->assertJsonPath('coverage.digital_mode', 'virtual_presencial_lima');
    }

    public function test_unpublished_privacy_draft_is_visible_to_admin_but_not_public(): void
    {
        $draft = 'Borrador privado con datos de contacto.';
        \App\Models\SiteSetting::savePublicSettings([
            'legal' => [
                'privacy' => $draft,
                'privacy_published' => false,
            ],
        ]);

        $this->getJson('/api/site-settings')
            ->assertOk()
            ->assertJsonPath('legal.privacy', null)
            ->assertJsonPath('legal.privacy_published', false);

        $this->getJson('/api/admin/site-settings')->assertUnauthorized();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->getJson('/api/admin/site-settings')
            ->assertOk()
            ->assertJsonPath('legal.privacy', $draft)
            ->assertJsonPath('legal.privacy_published', false);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->putJson('/api/admin/site-settings', [
                'seo' => ['title' => 'DigitalPyme'],
            ])
            ->assertOk()
            ->assertJsonPath('legal.privacy', $draft);

        $this->getJson('/api/site-settings')
            ->assertOk()
            ->assertJsonPath('legal.privacy', null);
    }

    public function test_only_admin_can_update_seo_theme_brand_and_coverage_settings(): void
    {
        $payload = [
            'seo' => [
                'title' => 'Agencia DigitalPyme Lima',
                'description' => 'Páginas web y tiendas para empresas peruanas.',
                'slug' => 'agencia-digital-lima',
                'og_image' => 'https://digitalpyme.pe/social.jpg',
            ],
            'brand' => ['logo_url' => 'https://digitalpyme.pe/logo.svg'],
            'contact' => ['whatsapp_phone' => '+51987654321'],
            'legal' => [
                'privacy' => 'Política de privacidad revisada.',
                'privacy_published' => true,
            ],
            'theme' => [
                'dark' => '#123456',
                'lime' => '#abcdef',
                'cream' => '#fefefe',
                'font_sans' => 'Inter',
                'font_display' => 'Playfair Display',
            ],
            'coverage' => [
                'digital_mode' => 'virtual',
                'shipping_zones' => [
                    ['location' => 'Lima', 'fee' => 12.5, 'delivery_days' => 2],
                    ['location' => 'Arequipa', 'fee' => 25, 'delivery_days' => 4],
                ],
            ],
        ];

        $this->actingAs(User::factory()->create())
            ->putJson('/api/admin/site-settings', $payload)
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->putJson('/api/admin/site-settings', $payload)
            ->assertOk()
            ->assertJsonPath('seo.slug', 'agencia-digital-lima')
            ->assertJsonPath('brand.logo_url', 'https://digitalpyme.pe/logo.svg')
            ->assertJsonPath('contact.whatsapp_phone', '+51987654321')
            ->assertJsonPath('legal.privacy', 'Política de privacidad revisada.')
            ->assertJsonPath('legal.privacy_published', true)
            ->assertJsonPath('theme.font_display', 'Playfair Display')
            ->assertJsonPath('coverage.shipping_zones.0.fee', 12.5);

        $this->getJson('/api/site-settings')
            ->assertOk()
            ->assertJsonPath('seo.title', 'Agencia DigitalPyme Lima')
            ->assertJsonPath('contact.whatsapp_phone', '+51987654321')
            ->assertJsonPath('legal.privacy', 'Política de privacidad revisada.')
            ->assertJsonPath('legal.privacy_published', true)
            ->assertJsonPath('coverage.digital_mode', 'virtual');
    }

    public function test_invalid_setting_values_and_duplicate_shipping_zones_are_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->putJson('/api/admin/site-settings', [
                'seo' => ['slug' => '../admin'],
                'contact' => ['whatsapp_phone' => 'not-a-phone'],
                'theme' => ['dark' => 'not-a-color'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seo.slug', 'contact.whatsapp_phone', 'theme.dark']);

        $this->actingAs($admin)
            ->putJson('/api/admin/site-settings', [
                'coverage' => [
                    'shipping_zones' => [
                        ['location' => 'Lima', 'fee' => 10],
                        ['location' => ' lima ', 'fee' => 12],
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['coverage.shipping_zones']);
    }

    public function test_public_coverage_returns_configured_shipping_cost_and_digital_service_mode(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->putJson('/api/admin/site-settings', [
                'coverage' => [
                    'digital_mode' => 'virtual',
                    'shipping_zones' => [
                        ['location' => 'Lima', 'fee' => 12.5, 'delivery_days' => 2],
                    ],
                ],
            ])
            ->assertOk();

        $this->getJson('/api/cobertura?servicio=Tiendas%20en%20linea&ubicacion=lima')
            ->assertOk()
            ->assertJsonPath('servicio_digital.modalidad', 'virtual')
            ->assertJsonPath('envio.cubierto', true)
            ->assertJsonPath('envio.costo', 12.5)
            ->assertJsonPath('envio.plazo_dias', 2);

        $this->getJson('/api/cobertura?servicio=Tiendas%20en%20linea&ubicacion=Cusco')
            ->assertOk()
            ->assertJsonPath('envio.cubierto', false);

        $this->getJson('/api/cobertura?servicio=Pagina%20web')
            ->assertOk()
            ->assertJsonMissingPath('envio')
            ->assertJsonPath('servicio_digital.detalle', 'Atención virtual en todo el Perú.');

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->putJson('/api/admin/site-settings', [
                'coverage' => ['shipping_zones' => []],
            ])
            ->assertOk()
            ->assertJsonPath('coverage.shipping_zones', []);

        $this->getJson('/api/cobertura?servicio=Tiendas%20en%20linea&ubicacion=Lima')
            ->assertOk()
            ->assertJsonPath('envio.cubierto', false);
    }

    public function test_sitemap_and_robots_use_the_configured_frontend_url_and_slug(): void
    {
        config(['services.digitalpyme.frontend_url' => 'https://digitalpyme.pe']);
        Servicio::create([
            'nombre' => 'Optimización web',
            'descripcion' => 'Servicio de prueba para el sitemap.',
            'slug' => 'optimizacion-web',
            'activo' => true,
        ]);
        Servicio::create([
            'nombre' => 'Servicio pausado',
            'slug' => 'servicio-pausado',
            'activo' => false,
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->putJson('/api/admin/site-settings', [
                'seo' => ['slug' => 'agencia-digital-lima'],
            ])
            ->assertOk();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertSee('https://digitalpyme.pe/agencia-digital-lima', false)
            ->assertSee('https://digitalpyme.pe/precios', false)
            ->assertSee('https://digitalpyme.pe/catalogo-servicios', false)
            ->assertSee('https://digitalpyme.pe/servicios/optimizacion-web', false)
            ->assertDontSee('<loc>https://digitalpyme.pe/servicios</loc>', false)
            ->assertDontSee('https://digitalpyme.pe/portafolio', false)
            ->assertDontSee('https://digitalpyme.pe/servicios/servicio-pausado', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: https://digitalpyme.pe/sitemap.xml');
    }
}
