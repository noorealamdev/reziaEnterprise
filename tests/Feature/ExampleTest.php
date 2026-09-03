<?php

it('shows the public homepage at the root url', function () {
    $response = $this->get('/');

    $response->assertOk()->assertSee(config('company.name'));
});
