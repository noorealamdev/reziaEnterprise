<?php

use App\Models\Company;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use App\Models\User;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/service-categories')->assertRedirect('/login');
});

test('a service category can be created and edited', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('service-categories.category-manager')
        ->call('startCreate')
        ->set('name', 'Security Guard Supply')
        ->set('invoice_code', 'SEC')
        ->set('unit_label', 'guards')
        ->call('save')
        ->assertHasNoErrors();

    $category = ServiceCategory::where('name', 'Security Guard Supply')->first();
    expect($category)->not->toBeNull();
    expect($category->invoice_code)->toBe('SEC');
    expect($category->unit_label)->toBe('guards');

    Volt::test('service-categories.category-manager')
        ->call('startEdit', $category->id)
        ->set('name', 'Security Guard Services')
        ->call('save')
        ->assertHasNoErrors();

    expect($category->fresh()->name)->toBe('Security Guard Services');
});

test('a new category is immediately assignable to a company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('service-categories.category-manager')
        ->call('startCreate')
        ->set('name', 'Security Guard Supply')
        ->set('invoice_code', 'SEC')
        ->call('save');

    $category = ServiceCategory::where('name', 'Security Guard Supply')->first();

    Volt::test('companies.company-form')
        ->assertSee('Security Guard Supply')
        ->set('name', 'New Factory Ltd')
        ->set('code', 'NFL')
        ->set('serviceCategoryIds', [$category->id])
        ->call('save')
        ->assertHasNoErrors();

    $company = Company::where('code', 'NFL')->first();
    expect($company->serviceCategories()->pluck('service_categories.id')->all())->toBe([$category->id]);
});

test('category name and invoice code must be unique', function () {
    $user = User::factory()->create();
    ServiceCategory::create(['name' => 'Diesel Oil Supply', 'invoice_code' => 'Diesel', 'sort_order' => 1]);
    $this->actingAs($user);

    Volt::test('service-categories.category-manager')
        ->call('startCreate')
        ->set('name', 'Diesel Oil Supply')
        ->set('invoice_code', 'NEW')
        ->call('save')
        ->assertHasErrors(['name']);

    Volt::test('service-categories.category-manager')
        ->call('startCreate')
        ->set('name', 'Something Else')
        ->set('invoice_code', 'Diesel')
        ->call('save')
        ->assertHasErrors(['invoice_code']);
});

test('a category cannot be deleted while a company is subscribed to it', function () {
    $user = User::factory()->create();
    $category = makeServiceCategory('Security Guard Supply');
    $company = Company::factory()->create();
    $company->serviceCategories()->attach($category->id);
    $this->actingAs($user);

    Volt::test('service-categories.category-manager')->call('confirmDelete', $category->id);

    $this->assertDatabaseHas('service_categories', ['id' => $category->id]);
});

test('a category cannot be deleted while it has job entries', function () {
    $user = User::factory()->create();
    $category = makeServiceCategory('Security Guard Supply');
    JobEntry::factory()->create(['service_category_id' => $category->id]);
    $this->actingAs($user);

    Volt::test('service-categories.category-manager')->call('confirmDelete', $category->id);

    $this->assertDatabaseHas('service_categories', ['id' => $category->id]);
});

test('an unused category can be deleted', function () {
    $user = User::factory()->create();
    $category = makeServiceCategory('Security Guard Supply');
    $this->actingAs($user);

    Volt::test('service-categories.category-manager')
        ->call('confirmDelete', $category->id)
        ->call('delete');

    $this->assertDatabaseMissing('service_categories', ['id' => $category->id]);
});

test('categories can be reordered', function () {
    $user = User::factory()->create();
    $first = makeServiceCategory('Alpha');
    $first->update(['sort_order' => 1]);
    $second = makeServiceCategory('Beta');
    $second->update(['sort_order' => 2]);
    $this->actingAs($user);

    Volt::test('service-categories.category-manager')->call('moveDown', $first->id);

    expect($first->fresh()->sort_order)->toBe(2);
    expect($second->fresh()->sort_order)->toBe(1);
});
