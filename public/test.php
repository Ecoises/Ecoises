<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);
$user = \App\Models\User::first();
Auth::login($user);

$req = Illuminate\Http\Request::create('/api/content/colombia-un-viaje-por-nuestros-tesoros-naturales', 'GET');
$req->setUserResolver(function() use ($user) { return $user; });
$res = app(\App\Http\Controllers\Api\EducationalContentController::class)->show('colombia-un-viaje-por-nuestros-tesoros-naturales');
echo json_encode($res->getData()->lessons[3]->activities);
