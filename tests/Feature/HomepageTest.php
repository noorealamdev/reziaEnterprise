<?php

test('the homepage is publicly reachable without logging in', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(config('company.name'))
        ->assertSee(config('company.tagline'));
});

test('the homepage lists the company\'s services and contact details', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Tiffin Supply')
        ->assertSee('Construction Material Supply')
        ->assertSee(config('company.email'))
        ->assertSee(config('company.address'));
});

test('the homepage links to the staff login page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('login'), false);
});
