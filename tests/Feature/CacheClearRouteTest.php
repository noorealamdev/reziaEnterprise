<?php

use Illuminate\Support\Facades\Artisan;

test('the cache-clear route 404s without a token configured', function () {
    config(['app.cache_clear_token' => null]);

    $this->get('/system/clear-cache/anything')->assertNotFound();
});

test('the cache-clear route 404s with the wrong token', function () {
    config(['app.cache_clear_token' => 'correct-token']);

    $this->get('/system/clear-cache/wrong-token')->assertNotFound();
});

test('the cache-clear route runs the clear/cache commands, never route:cache, and succeeds with the correct token', function () {
    config(['app.cache_clear_token' => 'correct-token']);

    // Mockery rejects any Artisan::call() argument without a matching
    // expectation, so the absence of a route:cache expectation here is
    // itself the assertion that it's never run.
    foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear', 'config:cache', 'view:cache'] as $command) {
        Artisan::shouldReceive('call')->once()->with($command)->andReturn(0);
    }

    $this->get('/system/clear-cache/correct-token')
        ->assertOk()
        ->assertSeeText('Cache cleared and re-cached');
});

test('the storage-link route 404s with the wrong token', function () {
    config(['app.cache_clear_token' => 'correct-token']);

    $this->get('/system/storage-link/wrong-token')->assertNotFound();
});

test('the storage-link route runs storage:link and succeeds with the correct token', function () {
    config(['app.cache_clear_token' => 'correct-token']);

    Artisan::shouldReceive('call')->once()->with('storage:link')->andReturn(0);
    Artisan::shouldReceive('output')->once()->andReturn('The [public/storage] link has been connected to [storage/app/public].');

    $this->get('/system/storage-link/correct-token')
        ->assertOk()
        ->assertSeeText('storage:link ran.');
});
