<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteSettingsController extends Controller
{
    private const FONTS = ['DM Sans', 'DM Serif Display', 'Inter', 'Lato', 'Manrope', 'Montserrat', 'Playfair Display'];

    public function show()
    {
        return response()->json(SiteSetting::publicSettings());
    }

    public function adminShow()
    {
        return response()->json(SiteSetting::adminSettings());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'seo' => ['sometimes', 'array'],
            'seo.title' => ['sometimes', 'required', 'string', 'max:70'],
            'seo.description' => ['sometimes', 'required', 'string', 'max:160'],
            'seo.slug' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'seo.og_image' => ['sometimes', 'nullable', 'url:http,https', 'max:2000'],
            'brand' => ['sometimes', 'array'],
            'brand.logo_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2000'],
            'contact' => ['sometimes', 'array'],
            'contact.whatsapp_phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[1-9]\d{7,14}$/'],
            'theme' => ['sometimes', 'array'],
            'theme.dark' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.lime' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.cream' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.font_sans' => ['sometimes', 'required', Rule::in(self::FONTS)],
            'theme.font_display' => ['sometimes', 'required', Rule::in(self::FONTS)],
            'coverage' => ['sometimes', 'array'],
            'coverage.digital_mode' => ['sometimes', 'required', Rule::in(['virtual', 'virtual_presencial_lima'])],
            'coverage.shipping_zones' => ['sometimes', 'array', 'max:100'],
            'coverage.shipping_zones.*.location' => ['required', 'string', 'max:120'],
            'coverage.shipping_zones.*.fee' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999'],
            'coverage.shipping_zones.*.delivery_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'coverage.shipping_methods' => ['sometimes', 'array', 'size:4'],
            'coverage.shipping_methods.*.key' => ['required', Rule::in(['recojo', 'estandar', 'express', 'same_day']), 'distinct'],
            'coverage.shipping_methods.*.nombre' => ['required', 'string', 'max:80'],
            'coverage.shipping_methods.*.cost' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999'],
            'coverage.shipping_methods.*.delivery_days' => ['required', 'integer', 'min:0', 'max:365'],
            'coverage.shipping_methods.*.enabled' => ['required', 'boolean'],
            'coverage.free_shipping_threshold' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
            'legal' => ['sometimes', 'array'],
            'legal.privacy' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'legal.privacy_published' => ['sometimes', 'boolean'],
            'legal.warranty' => ['sometimes', 'required', 'string', 'max:5000'],
            'legal.terms' => ['sometimes', 'required', 'string', 'max:5000'],
            'legal.returns' => ['sometimes', 'required', 'string', 'max:5000'],
            'legal.payments' => ['sometimes', 'required', 'string', 'max:5000'],
            'legal.revisions' => ['sometimes', 'required', 'string', 'max:5000'],
            'legal.intellectual_property' => ['sometimes', 'required', 'string', 'max:5000'],
            'legal.promotions' => ['sometimes', 'required', 'string', 'max:5000'],
        ]);

        foreach ($data['coverage']['shipping_zones'] ?? [] as $zone) {
            $location = Str::lower(trim($zone['location']));
            $duplicates = collect($data['coverage']['shipping_zones'])
                ->filter(fn (array $item) => Str::lower(trim($item['location'])) === $location)
                ->count();

            if ($duplicates > 1) {
                throw ValidationException::withMessages([
                    'coverage.shipping_zones' => 'Cada ubicación de cobertura debe aparecer una sola vez.',
                ]);
            }
        }

        return response()->json(SiteSetting::savePublicSettings($data));
    }

    public function coverage(Request $request)
    {
        $data = $request->validate([
            'ubicacion' => ['nullable', 'string', 'max:120'],
            'servicio' => ['nullable', 'string', 'max:255'],
        ]);
        $settings = SiteSetting::publicSettings();
        $isOnlineStore = Str::contains(Str::lower($data['servicio'] ?? ''), ['tienda', 'e-commerce', 'ecommerce']);
        $result = [
            'servicio_digital' => [
                'modalidad' => $settings['coverage']['digital_mode'],
                'detalle' => $settings['coverage']['digital_mode'] === 'virtual'
                    ? 'Atención virtual en todo el Perú.'
                    : 'Atención virtual en todo el Perú y presencial en Lima.',
            ],
        ];

        if ($isOnlineStore) {
            $location = Str::lower(trim($data['ubicacion'] ?? ''));
            $zone = collect($settings['coverage']['shipping_zones'])
                ->first(fn (array $item) => Str::lower(trim($item['location'])) === $location);
            $result['envio'] = $location === ''
                ? ['ubicacion_requerida' => true, 'zonas' => $settings['coverage']['shipping_zones']]
                : ($zone
                    ? [
                        'ubicacion' => $zone['location'],
                        'cubierto' => true,
                        'costo' => (float) $zone['fee'],
                        'plazo_dias' => $zone['delivery_days'] ?? null,
                    ]
                    : ['ubicacion' => $data['ubicacion'], 'cubierto' => false, 'mensaje' => 'Solicita una cotización de envío para esta ubicación.']);
        }

        return response()->json($result);
    }
}
