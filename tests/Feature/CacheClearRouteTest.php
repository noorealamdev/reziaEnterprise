<?php

use Illuminate\Support\Facades\Artisan;

test('the system route clears/rebuilds caches, links storage, and never runs route:cache', function () {
    // Mockery rejects any Artisan::call() argument without a matching
    // expectation, so the absence of a route:cache expectation here is
    // itself the assertion that it's never run.
    foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear', 'storage:link', 'config:cache', 'view:cache'] as $command) {
        Artisan::shouldReceive('call')->once()->with($command)->andReturn(0);
    }

    $this->get('/system/clear')
        ->assertOk()
        ->assertSeeText('Cache cleared and re-cached')
        ->assertSeeText('storage:link ran');
});
