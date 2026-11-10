<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientMilestoneController;
use App\Http\Controllers\CotizacionController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PortfolioProjectController;
use App\Http\Controllers\ProjectCapacityController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDocumentController;
use App\Http\Controllers\ProjectMessageController;
use App\Http\Controllers\ProjectPaymentController;
use App\Http\Controllers\PublicQuoteController;
use App\Http\Controllers\PublicQuoteVerificationController;
use App\Http\Controllers\ServiceCategoryController;
use App\Http\Controllers\ServiceReviewController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\SiteSettingsController;
use App\Http\Controllers\StoreLogisticsController;
use App\Http\Controllers\StoreCouponController;
use App\Http\Controllers\TestimonioController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Público
Route::post('/leads', [LeadController::class, 'store'])->middleware('throttle:5,1');
Route::post('/propuestas/codigo', [PublicQuoteVerificationController::class, 'sendCode'])->middleware('throttle:3,1');
Route::post('/propuestas/verificar-codigo', [PublicQuoteVerificationController::class, 'verifyCode'])->middleware('throttle:5,1');
Route::post('/propuestas', [PublicQuoteController::class, 'store'])->middleware('throttle:5,1');
Route::get('/servicios', [ServicioController::class, 'publicos']);
Route::get('/servicios/{slug}', [ServicioController::class, 'show'])->where('slug', '[a-z0-9-]+');
Route::get('/servicios/{servicio}/resenas', [ServiceReviewController::class, 'index'])->whereNumber('servicio');
Route::post('/servicios/{servicio}/resenas', [ServiceReviewController::class, 'store'])->middleware('throttle:5,1')->whereNumber('servicio');
Route::get('/resenas', [ServiceReviewController::class, 'publicos']);
Route::get('/categorias-servicios', [ServiceCategoryController::class, 'publicIndex']);
Route::get('/portafolio', [PortfolioProjectController::class, 'publicos']);
Route::get('/testimonios', [TestimonioController::class, 'publicos']);
Route::get('/site-settings', [SiteSettingsController::class, 'show']);
Route::get('/cobertura', [SiteSettingsController::class, 'coverage']);
Route::get('/agenda/horarios', [AgendaController::class, 'slots']);
Route::post('/agenda/reservas', [AgendaController::class, 'reserve'])->middleware('throttle:5,1');
Route::post('/payments/mercadopago/webhook', [ProjectPaymentController::class, 'mercadoPagoWebhook'])->middleware('throttle:60,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/client/login', [AuthController::class, 'clientLogin'])->middleware('throttle:5,1');
Route::post('/client/register', [ClientController::class, 'register'])->middleware('throttle:5,1');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::get('/store/products', [StoreLogisticsController::class, 'publicProducts']);
Route::get('/store/products/{storeProduct}/image', [StoreLogisticsController::class, 'publicProductImage']);
Route::get('/store/shipping-options', [StoreLogisticsController::class, 'shippingOptions']);
Route::get('/store/payment-options', [StoreLogisticsController::class, 'paymentOptions']);

// Solo con sesión iniciada
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => $request->user());

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::get('/admin/dashboard', [AdminDashboardController::class, 'show']);
        Route::get('/admin/site-settings', [SiteSettingsController::class, 'adminShow']);
        Route::get('/admin/store', [StoreLogisticsController::class, 'adminIndex']);
        Route::post('/admin/store/coupons', [StoreCouponController::class, 'store']);
        Route::put('/admin/store/coupons/{storeCoupon}', [StoreCouponController::class, 'update']);
        Route::delete('/admin/store/coupons/{storeCoupon}', [StoreCouponController::class, 'destroy']);
        Route::post('/admin/store/products', [StoreLogisticsController::class, 'storeProduct']);
        Route::put('/admin/store/products/{storeProduct}', [StoreLogisticsController::class, 'updateProduct']);
        Route::post('/admin/store/products/{storeProduct}/image', [StoreLogisticsController::class, 'uploadProductImage']);
        Route::delete('/admin/store/products/{storeProduct}/image', [StoreLogisticsController::class, 'deleteProductImage']);
        Route::delete('/admin/store/products/{storeProduct}', [StoreLogisticsController::class, 'deleteProduct']);
        Route::post('/admin/store/products/{storeProduct}/movements', [StoreLogisticsController::class, 'addStockMovement']);
        Route::patch('/admin/store/orders/{storeOrder}', [StoreLogisticsController::class, 'updateOrder']);
        Route::patch('/admin/store/orders/{storeOrder}/payment', [StoreLogisticsController::class, 'updatePaymentStatus']);
        Route::get('/admin/store/orders/{storeOrder}/receipt', [StoreLogisticsController::class, 'adminReceipt']);
        Route::patch('/admin/store/returns/{storeReturn}', [StoreLogisticsController::class, 'updateReturn']);
        Route::put('/admin/site-settings', [SiteSettingsController::class, 'update']);
        Route::get('/leads', [LeadController::class, 'index']);
        Route::patch('/leads/{lead}', [LeadController::class, 'update']);
        Route::post('/leads/{lead}/client', [ClientController::class, 'convertLead']);
        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/clients/{client}', [ClientController::class, 'show']);
        Route::patch('/clients/{client}', [ClientController::class, 'update']);
        Route::patch('/clients/{client}/status', [ClientController::class, 'updateStatus']);
        Route::post('/clients/{client}/password', [ClientController::class, 'updatePassword']);
        Route::post('/clients/{client}/password/reset-link', [ClientController::class, 'sendPasswordResetLink'])->middleware('throttle:3,1');

        Route::get('/leads/{lead}/cotizaciones', [CotizacionController::class, 'index']);
        Route::post('/leads/{lead}/cotizaciones', [CotizacionController::class, 'store']);
        Route::patch('/cotizaciones/{cotizacion}', [CotizacionController::class, 'update']);
        Route::post('/cotizaciones/{cotizacion}/reenviar', [CotizacionController::class, 'resend']);

        Route::get('/admin/agenda/horarios', [AgendaController::class, 'adminSlots']);
        Route::post('/admin/agenda/horarios', [AgendaController::class, 'storeSlot']);
        Route::get('/admin/agenda/reservas', [AgendaController::class, 'adminReservations']);
        Route::delete('/admin/agenda/reservas/{reservation}', [AgendaController::class, 'cancelReservation']);
        Route::get('/admin/inventario/proyectos', [ProjectCapacityController::class, 'show']);
        Route::put('/admin/inventario/proyectos', [ProjectCapacityController::class, 'update']);

        Route::get('/admin/servicios', [ServicioController::class, 'index']);
        Route::post('/admin/servicios', [ServicioController::class, 'store']);
        Route::put('/admin/servicios/{servicio}', [ServicioController::class, 'update']);
        Route::delete('/admin/servicios/{servicio}', [ServicioController::class, 'destroy']);
        Route::get('/admin/categorias-servicios', [ServiceCategoryController::class, 'index']);
        Route::post('/admin/categorias-servicios', [ServiceCategoryController::class, 'store']);
        Route::put('/admin/categorias-servicios/{serviceCategory}', [ServiceCategoryController::class, 'update']);
        Route::delete('/admin/categorias-servicios/{serviceCategory}', [ServiceCategoryController::class, 'destroy']);
        Route::post('/admin/subcategorias-servicios', [ServiceCategoryController::class, 'storeSubcategory']);
        Route::put('/admin/subcategorias-servicios/{serviceSubcategory}', [ServiceCategoryController::class, 'updateSubcategory']);
        Route::delete('/admin/subcategorias-servicios/{serviceSubcategory}', [ServiceCategoryController::class, 'destroySubcategory']);
        Route::get('/admin/resenas-servicios', [ServiceReviewController::class, 'adminIndex']);
        Route::patch('/admin/resenas-servicios/{serviceReview}', [ServiceReviewController::class, 'moderate']);
        Route::delete('/admin/resenas-servicios/{serviceReview}', [ServiceReviewController::class, 'destroy']);

        Route::get('/admin/portafolio', [PortfolioProjectController::class, 'index']);
        Route::post('/admin/portafolio', [PortfolioProjectController::class, 'store']);
        Route::put('/admin/portafolio/{portfolioProject}', [PortfolioProjectController::class, 'update']);
        Route::delete('/admin/portafolio/{portfolioProject}', [PortfolioProjectController::class, 'destroy']);

        Route::get('/admin/testimonios', [TestimonioController::class, 'index']);
        Route::post('/admin/testimonios', [TestimonioController::class, 'store']);
        Route::put('/admin/testimonios/{testimonio}', [TestimonioController::class, 'update']);
        Route::delete('/admin/testimonios/{testimonio}', [TestimonioController::class, 'destroy']);

        Route::get('/admin/projects', [ProjectController::class, 'index']);
        Route::post('/admin/projects', [ProjectController::class, 'store']);
        Route::patch('/admin/projects/{project}', [ProjectController::class, 'update']);
        Route::get('/admin/projects/{project}/milestones', [ProjectController::class, 'adminMilestones']);
        Route::post('/admin/projects/{project}/milestones', [ProjectController::class, 'adminStoreMilestone']);
        Route::patch('/admin/projects/{project}/milestones/{milestone}', [ProjectController::class, 'adminUpdateMilestone']);
        Route::get('/admin/projects/{project}/documents', [ProjectDocumentController::class, 'adminIndex']);
        Route::post('/admin/projects/{project}/documents', [ProjectDocumentController::class, 'adminStore']);
        Route::get('/admin/projects/{project}/documents/{document}/download', [ProjectDocumentController::class, 'adminDownload']);
        Route::delete('/admin/projects/{project}/documents/{document}', [ProjectDocumentController::class, 'adminDestroy']);
        Route::get('/admin/projects/{project}/messages', [ProjectMessageController::class, 'adminIndex']);
        Route::post('/admin/projects/{project}/messages', [ProjectMessageController::class, 'adminStore']);
        Route::get('/admin/payments', [ProjectPaymentController::class, 'adminIndex']);
        Route::patch('/admin/payments/{payment}', [ProjectPaymentController::class, 'adminUpdateStatus']);
    });

    Route::middleware(EnsureClient::class)->prefix('client')->group(function () {
        Route::put('/password', [AuthController::class, 'clientChangePassword'])->name('client.password');
        Route::get('/profile', [ClientController::class, 'profile']);
        Route::post('/store/coupons/validate', [StoreCouponController::class, 'validateForCheckout'])->middleware('throttle:10,1');
        Route::get('/store/orders', [StoreLogisticsController::class, 'clientOrders']);
        Route::post('/store/orders', [StoreLogisticsController::class, 'checkout']);
        Route::post('/store/orders/{storeOrder}/payments/card', [StoreLogisticsController::class, 'startCardPayment'])->middleware('throttle:5,1');
        Route::post('/store/orders/{storeOrder}/receipt', [StoreLogisticsController::class, 'uploadClientReceipt'])->middleware('throttle:10,1');
        Route::get('/store/orders/{storeOrder}/receipt', [StoreLogisticsController::class, 'clientReceipt']);
        Route::post('/store/orders/{storeOrder}/returns', [StoreLogisticsController::class, 'clientReturn']);
        Route::get('/projects', [ProjectController::class, 'clientIndex']);
        Route::get('/projects/{project}', [ProjectController::class, 'clientShow']);
        Route::post('/projects/{project}/milestones/{milestone}/approve', [ClientMilestoneController::class, 'approve']);
        Route::get('/projects/{project}/payments', [ProjectPaymentController::class, 'clientIndex']);
        Route::post('/projects/{project}/payments/transfers', [ProjectPaymentController::class, 'startTransfer']);
        Route::post('/projects/{project}/payments/card', [ProjectPaymentController::class, 'startCardPayment']);
        Route::get('/projects/{project}/payments/{payment}/receipt', [ProjectPaymentController::class, 'clientReceipt']);
        Route::post('/projects/{project}/payments/{payment}/receipt', [ProjectDocumentController::class, 'clientStorePaymentReceipt']);
        Route::get('/projects/{project}/documents', [ProjectDocumentController::class, 'clientIndex']);
        Route::post('/projects/{project}/documents', [ProjectDocumentController::class, 'clientStore']);
        Route::get('/projects/{project}/documents/{document}/download', [ProjectDocumentController::class, 'clientDownload']);
        Route::get('/projects/{project}/messages', [ProjectMessageController::class, 'clientIndex']);
        Route::post('/projects/{project}/messages', [ProjectMessageController::class, 'clientStore']);
    });
});
