<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('mount dispatches a success toast for a flashed status message and clears it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    session()->flash('status', 'Purchase updated.');

    Volt::test('layout.toast-notifications')
        ->assertDispatched('toast', message: 'Purchase updated.', type: 'success');

    expect(session('status'))->toBeNull();
});

test('mount dispatches an error toast for a flashed error message and clears it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    session()->flash('error', 'Something went wrong.');

    Volt::test('layout.toast-notifications')
        ->assertDispatched('toast', message: 'Something went wrong.', type: 'error');

    expect(session('error'))->toBeNull();
});

test('poll does not redispatch a toast that mount already picked up', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    session()->flash('status', 'Purchase updated.');

    Volt::test('layout.toast-notifications')
        ->assertDispatched('toast')
        ->call('poll')
        ->assertNotDispatched('toast');
});

test('poll picks up a status flashed after mount, for same-page modal actions', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Volt::test('layout.toast-notifications')
        ->assertNotDispatched('toast');

    session()->flash('status', 'Sale recorded.');

    $component->call('poll')
        ->assertDispatched('toast', message: 'Sale recorded.', type: 'success');
});

test('mount does not dispatch a toast when there is no flash message', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('layout.toast-notifications')
        ->assertNotDispatched('toast');
});
