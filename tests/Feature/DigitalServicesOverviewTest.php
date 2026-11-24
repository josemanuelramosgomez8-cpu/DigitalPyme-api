<?php

namespace Tests\Feature;

use Tests\TestCase;

class DigitalServicesOverviewTest extends TestCase
{
    public function test_home_page_renders_digitalpyme_services_and_platform_comparison(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Desarrollo web')
            ->assertSee('Tiendas online')
            ->assertSee('Automatización de procesos')
            ->assertSee('Gestión de redes sociales')
            ->assertSee('WordPress + WooCommerce')
            ->assertSee('Shopify')
            ->assertSee('PrestaShop')
            ->assertSee('Magento Open Source / Adobe Commerce')
            ->assertSee('un CMS propietario');
    }

    public function test_home_page_renders_information_management_cycle_and_benefits(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Almacenar')
            ->assertSee('Estructurar')
            ->assertSee('Enriquecer')
            ->assertSee('Actualizar')
            ->assertSee('Consistencia')
            ->assertSee('Experiencia del cliente')
            ->assertSee('Eficiencia operativa')
            ->assertSee('servicios, contenidos y catálogos');
    }
}
