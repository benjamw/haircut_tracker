<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Controller\AppointmentController;
use App\Controller\AuthController;
use App\Controller\AvailabilityController;
use App\Controller\BookingController;
use App\Controller\DueController;
use App\Controller\HaircutController;
use App\Controller\MeController;
use App\Controller\OptOutController;
use App\Controller\PersonController;
use App\Controller\SlotController;
use App\Controller\UserAdminController;
use App\Controller\WebAuthnController;
use App\Database;
use App\Middleware\AdminAuth;
use App\Middleware\RateLimit;
use App\Middleware\UserAuth;
use App\Support\Env;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

// Load .env for hosts without real env vars (shared hosting). No-op under
// Docker where env is already set. Must run before any Env::get().
\App\Support\Dotenv::load(__DIR__ . '/../.env');

// Refuse to run with an unconfigured JWT signing secret (would silently sign
// sessions with a default). Explicit dev escape hatch: ADMIN_AUTH_DISABLED=1.
if (Env::get('AUTH_SECRET') === '' && Env::get('ADMIN_AUTH_DISABLED') !== '1') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server misconfigured: AUTH_SECRET is required']);
    exit;
}

// All server-side time in one shop-local zone so PHP and MySQL agree.
date_default_timezone_set(Env::get('SHOP_TZ', 'America/Denver'));

$app = AppFactory::create();

// When the API is served under a sub-path (e.g. somedomain.com/api), tell Slim
// so route matching strips it. Empty in dev (served at the domain root).
$basePath = Env::get('API_BASE_PATH');
if ($basePath !== '') {
    $app->setBasePath($basePath);
}

$app->addBodyParsingMiddleware();

// Permissive CORS for local dev (tightened later, in Phase B/C).
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Admin-Token')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
});

// Only expose error details (stack traces) when APP_DEBUG=1 — never in prod.
$app->addErrorMiddleware(Env::get('APP_DEBUG') === '1', true, true);

// Helper to write JSON responses.
$json = static function (Response $response, mixed $data, int $status = 200): Response {
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
};

// Preflight catch-all.
$app->options('/{routes:.+}', static fn(Request $request, Response $response): Response => $response);

// Health check — proves the API is up and can reach the database.
$app->get('/health', static function (Request $request, Response $response) use ($json): Response {
    $status = ['status' => 'ok', 'time' => date('c')];

    try {
        Database::connect()->query('SELECT 1');
        $status['db'] = 'connected';
    } catch (\Throwable $e) {
        // Don't leak connection details publicly; log server-side instead.
        error_log('[health] DB check failed: ' . $e->getMessage());
        $status['db'] = 'error';
        return $json($response, $status, 503);
    }

    return $json($response, $status);
});

// ---- Public routes (Phase B) — no PII, no auth ----
$app->get('/slots', SlotController::class . ':list');
$app->get('/carriers', SlotController::class . ':carriers');
$app->get('/optout', OptOutController::class . ':handle');

// Booking flow — rate limited per IP (anti-abuse for the public URL).
$app->post('/book/start', BookingController::class . ':start')
    ->add(new RateLimit('book_start', 5, 60));   // 5 / minute
$app->post('/book/verify', BookingController::class . ':verify')
    ->add(new RateLimit('book_verify', 12, 60));  // 12 / minute

// User accounts
$app->post('/auth/register', AuthController::class . ':register')->add(new RateLimit('register', 10, 60));
$app->post('/auth/login', AuthController::class . ':login')->add(new RateLimit('login', 10, 60));

// Passkey login (public)
$app->post('/auth/passkey/options', WebAuthnController::class . ':loginOptions')->add(new RateLimit('pk_login', 20, 60));
$app->post('/auth/passkey/verify', WebAuthnController::class . ':loginVerify')->add(new RateLimit('pk_login', 20, 60));

// Logged-in user ("me") — behind Bearer-JWT auth
$app->group('/me', function (RouteCollectorProxy $group): void {
    $group->get('', MeController::class . ':me');
    $group->patch('/profile', MeController::class . ':updateProfile');
    $group->post('/password', MeController::class . ':changePassword');
    $group->post('/book', MeController::class . ':book');
    $group->post('/appointments/{id:[0-9]+}/cancel', MeController::class . ':cancel');
    $group->post('/verify-contact', AuthController::class . ':verifyContact');
    $group->post('/contact/send-code', AuthController::class . ':sendContactCode');
    $group->post('/passkey/options', WebAuthnController::class . ':registerOptions');
    $group->post('/passkey/verify', WebAuthnController::class . ':registerVerify');
})->add(new UserAuth());

// ---- Admin routes (Phase A) — behind the temporary admin-token guard ----
$app->group('/admin', function (RouteCollectorProxy $group): void {
    // People
    $group->get('/persons', PersonController::class . ':list');
    $group->post('/persons', PersonController::class . ':create');
    $group->get('/persons/{id:[0-9]+}', PersonController::class . ':detail');
    $group->patch('/persons/{id:[0-9]+}', PersonController::class . ':update');
    $group->delete('/persons/{id:[0-9]+}', PersonController::class . ':delete');

    // Haircuts
    $group->post('/persons/{id:[0-9]+}/haircuts', HaircutController::class . ':create');
    $group->patch('/haircuts/{hid:[0-9]+}', HaircutController::class . ':update');
    $group->delete('/haircuts/{hid:[0-9]+}', HaircutController::class . ':delete');

    // Due list (headline feature) + "I texted them"
    $group->get('/due', DueController::class . ':list');
    $group->post('/persons/{id:[0-9]+}/mark-contacted', DueController::class . ':markContacted');

    // Appointments
    $group->get('/appointments', AppointmentController::class . ':list');
    $group->post('/appointments/{id:[0-9]+}/cancel', AppointmentController::class . ':cancel');
    $group->post('/appointments/{id:[0-9]+}/record', AppointmentController::class . ':record');

    // Availability — recurring weekly windows
    $group->get('/availability', AvailabilityController::class . ':listWindows');
    $group->post('/availability', AvailabilityController::class . ':createWindow');
    $group->patch('/availability/{id:[0-9]+}', AvailabilityController::class . ':updateWindow');
    $group->delete('/availability/{id:[0-9]+}', AvailabilityController::class . ':deleteWindow');

    // Availability — one-off blocks / custom-hours overrides
    $group->get('/exceptions', AvailabilityController::class . ':listExceptions');
    $group->post('/exceptions', AvailabilityController::class . ':createException');
    $group->delete('/exceptions/{id:[0-9]+}', AvailabilityController::class . ':deleteException');

    // Registered user accounts (block / remove)
    $group->get('/users', UserAdminController::class . ':list');
    $group->patch('/users/{id:[0-9]+}', UserAdminController::class . ':updateStatus');
    $group->delete('/users/{id:[0-9]+}', UserAdminController::class . ':delete');

    // Person merge
    $group->post('/persons/merge', PersonController::class . ':merge');
})->add(new AdminAuth());

$app->run();
