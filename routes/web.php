<?php

use App\Models\SiteSetting;
use App\Models\Servicio;
use App\Models\StoreProduct;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProjectBriefController;
use App\Http\Controllers\PaymentConfirmationController;

Route::get('/sitemap.xml', function () {
    $settings = SiteSetting::publicSettings();
    $baseUrl = rtrim(config('services.digitalpyme.frontend_url'), '/');
    $slug = trim($settings['seo']['slug'] ?? '', '/');
    $siteLastModified = SiteSetting::query()->find('public')?->updated_at?->toAtomString()
        ?? now()->toAtomString();
    $lastModifiedFor = fn ($timestamp) => $timestamp
        ? \Illuminate\Support\Carbon::parse($timestamp)->toAtomString()
        : $siteLastModified;
    $pages = [
        [
            'url' => $baseUrl.($slug === '' ? '/' : '/'.$slug),
            'lastmod' => $siteLastModified,
            'priority' => '1.0',
        ],
        [
            'url' => $baseUrl.'/precios',
            'lastmod' => $lastModifiedFor(Servicio::query()->where('activo', true)->max('updated_at')),
            'priority' => '0.9',
        ],
        [
            'url' => $baseUrl.'/catalogo-servicios',
            'lastmod' => $lastModifiedFor(Servicio::query()->where('activo', true)->max('updated_at')),
            'priority' => '0.9',
        ],
        [
            'url' => $baseUrl.'/tienda',
            'lastmod' => $lastModifiedFor(StoreProduct::query()->where('activo', true)->where('stock', '>', 0)->max('updated_at')),
            'priority' => '0.8',
        ],
    ];

    foreach (Servicio::query()->where('activo', true)->whereNotNull('slug')->get(['slug', 'updated_at']) as $service) {
        $pages[] = [
            'url' => $baseUrl.'/servicios/'.rawurlencode($service->slug),
            'lastmod' => $lastModifiedFor($service->updated_at),
            'priority' => '0.8',
        ];
    }

    $escape = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $entries = collect($pages)->unique('url')->map(fn (array $page) =>
        '<url><loc>'.$escape($page['url']).'</loc><lastmod>'.$escape($page['lastmod'])
        .'</lastmod><changefreq>weekly</changefreq><priority>'.$page['priority'].'</priority></url>'
    )->implode('');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .$entries
        .'</urlset>';

    return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
});

Route::get('/robots.txt', function () {
    $frontendUrl = rtrim(config('services.digitalpyme.frontend_url'), '/');

    return response(
        'User-agent: *'.PHP_EOL
        .'Allow: /'.PHP_EOL
        .'Sitemap: '.$frontendUrl.'/sitemap.xml'.PHP_EOL,
        200,
        ['Content-Type' => 'text/plain; charset=UTF-8'],
    );
});

Route::get('/pagos/proyectos/{payment}/comprobante', [PaymentConfirmationController::class, 'showProject'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('payment-confirmations.project.show');
Route::get('/pagos/proyectos/{payment}/comprobante/pdf', [PaymentConfirmationController::class, 'projectPdf'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('payment-confirmations.project.pdf');
Route::post('/pagos/proyectos/{payment}/comprobante/correo', [PaymentConfirmationController::class, 'resendProjectEmail'])
    ->middleware(['signed', 'throttle:3,1'])
    ->name('payment-confirmations.project.email');
Route::get('/pagos/pedidos/{storeOrder}/comprobante', [PaymentConfirmationController::class, 'showOrder'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('payment-confirmations.order.show');
Route::get('/pagos/pedidos/{storeOrder}/comprobante/pdf', [PaymentConfirmationController::class, 'orderPdf'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('payment-confirmations.order.pdf');
Route::post('/pagos/pedidos/{storeOrder}/comprobante/correo', [PaymentConfirmationController::class, 'resendOrderEmail'])
    ->middleware(['signed', 'throttle:3,1'])
    ->name('payment-confirmations.order.email');

Route::get('/', function () {
    return view('welcome');
});

Route::get('/proyectos/{project}/brief', [ProjectBriefController::class, 'show'])
    ->middleware('signed')
    ->name('project-brief.show');
Route::post('/proyectos/{project}/brief', [ProjectBriefController::class, 'store'])
    ->middleware('signed')
    ->name('project-brief.store');
