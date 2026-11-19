<?php

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProjectBriefController;

Route::get('/sitemap.xml', function () {
    $settings = SiteSetting::publicSettings();
    $baseUrl = rtrim(config('services.digitalpyme.frontend_url'), '/');
    $slug = trim($settings['seo']['slug'] ?? '', '/');
    $url = $baseUrl.($slug === '' ? '/' : '/'.$slug);
    $lastModified = SiteSetting::query()->find('public')?->updated_at?->toAtomString()
        ?? now()->toAtomString();
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .'<url><loc>'.e($url).'</loc><lastmod>'.e($lastModified).'</lastmod><changefreq>weekly</changefreq><priority>1.0</priority></url>'
        .'</urlset>';

    return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
});

Route::get('/robots.txt', function () {
    return response(
        'User-agent: *'.PHP_EOL
        .'Allow: /'.PHP_EOL
        .'Sitemap: '.url('/sitemap.xml').PHP_EOL,
        200,
        ['Content-Type' => 'text/plain; charset=UTF-8'],
    );
});

Route::get('/', function () {
    return view('welcome');
});

Route::get('/proyectos/{project}/brief', [ProjectBriefController::class, 'show'])
    ->middleware('signed')
    ->name('project-brief.show');
Route::post('/proyectos/{project}/brief', [ProjectBriefController::class, 'store'])
    ->middleware('signed')
    ->name('project-brief.store');
