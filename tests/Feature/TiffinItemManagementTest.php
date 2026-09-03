<?php

use App\Models\JobEntry;
use App\Models\TiffinDepartment;
use App\Models\TiffinDepartmentItem;
use App\Models\TiffinItem;
use App\Models\User;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/tiffin-items')->assertRedirect('/login');
});

test('a department can be created and edited', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('tiffin-items.department-manager')
        ->call('startCreate')
        ->set('name', 'Cutting')
        ->call('save')
        ->assertHasNoErrors();

    $department = TiffinDepartment::where('name', 'Cutting')->first();
    expect($department)->not->toBeNull();

    Volt::test('tiffin-items.department-manager')
        ->call('startEdit', $department->id)
        ->set('name', 'Cutting Floor')
        ->call('save')
        ->assertHasNoErrors();

    expect($department->fresh()->name)->toBe('Cutting Floor');
});

test('department name must be unique', function () {
    $user = User::factory()->create();
    TiffinDepartment::create(['name' => 'Swing']);
    $this->actingAs($user);

    Volt::test('tiffin-items.department-manager')
        ->call('startCreate')
        ->set('name', 'Swing')
        ->call('save')
        ->assertHasErrors(['name']);
});

test('a department cannot be deleted while it has items assigned', function () {
    $user = User::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    makeTiffinRecipe($swing, ['Banana']);
    $this->actingAs($user);

    Volt::test('tiffin-items.department-manager')->call('confirmDelete', $swing->id);

    $this->assertDatabaseHas('tiffin_departments', ['id' => $swing->id]);
});

test('an unused department can be deleted', function () {
    $user = User::factory()->create();
    $department = TiffinDepartment::create(['name' => 'Unused']);
    $this->actingAs($user);

    Volt::test('tiffin-items.department-manager')
        ->call('confirmDelete', $department->id)
        ->call('delete');

    $this->assertDatabaseMissing('tiffin_departments', ['id' => $department->id]);
});

test('a tiffin item can be created and edited', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('tiffin-items.item-catalog-manager')
        ->call('startCreate')
        ->set('name', 'Yogurt')
        ->set('unit_label', 'cup')
        ->call('save')
        ->assertHasNoErrors();

    $item = TiffinItem::where('name', 'Yogurt')->first();
    expect($item)->not->toBeNull();
    expect($item->unit_label)->toBe('cup');

    Volt::test('tiffin-items.item-catalog-manager')
        ->call('startEdit', $item->id)
        ->set('unit_label', 'bowl')
        ->call('save')
        ->assertHasNoErrors();

    expect($item->fresh()->unit_label)->toBe('bowl');
});

test('tiffin item name must be unique', function () {
    $user = User::factory()->create();
    TiffinItem::create(['name' => 'Egg']);
    $this->actingAs($user);

    Volt::test('tiffin-items.item-catalog-manager')
        ->call('startCreate')
        ->set('name', 'Egg')
        ->call('save')
        ->assertHasErrors(['name']);
});

test('a tiffin item cannot be deleted while assigned to a department', function () {
    $user = User::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    makeTiffinRecipe($swing, ['Banana']);
    $banana = TiffinItem::where('name', 'Banana')->first();
    $this->actingAs($user);

    Volt::test('tiffin-items.item-catalog-manager')->call('confirmDelete', $banana->id);

    $this->assertDatabaseHas('tiffin_items', ['id' => $banana->id]);
});

test('an unassigned tiffin item can be deleted', function () {
    $user = User::factory()->create();
    $item = TiffinItem::create(['name' => 'Yogurt']);
    $this->actingAs($user);

    Volt::test('tiffin-items.item-catalog-manager')
        ->call('confirmDelete', $item->id)
        ->call('delete');

    $this->assertDatabaseMissing('tiffin_items', ['id' => $item->id]);
});

test('an item can be added to a department', function () {
    $user = User::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    $banana = TiffinItem::create(['name' => 'Banana']);
    $this->actingAs($user);

    Volt::test('tiffin-items.department-recipe-manager')
        ->call('startAdd', $swing->id)
        ->set('tiffin_item_id', $banana->id)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('tiffin_department_items', [
        'tiffin_department_id' => $swing->id,
        'tiffin_item_id' => $banana->id,
        'sort_order' => 1,
    ]);
});

test('the item picker only offers items not already assigned to the department', function () {
    $user = User::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    makeTiffinRecipe($swing, ['Banana']);
    TiffinItem::create(['name' => 'Egg']);
    $this->actingAs($user);

    $component = Volt::test('tiffin-items.department-recipe-manager')
        ->call('startAdd', $swing->id);

    $availableNames = $component->viewData('availableItemsByDepartment')[$swing->id]
        ->pluck('name')
        ->all();

    expect($availableNames)->toBe(['Egg']);
});

test('removing an item with recorded job entries is blocked, otherwise it succeeds', function () {
    $user = User::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    makeTiffinRecipe($swing, ['Banana', 'Egg']);
    $bananaRow = TiffinDepartmentItem::whereHas('tiffinItem', fn ($query) => $query->where('name', 'Banana'))->first();
    $eggRow = TiffinDepartmentItem::whereHas('tiffinItem', fn ($query) => $query->where('name', 'Egg'))->first();

    JobEntry::factory()->create([
        'tiffin_department_id' => $swing->id,
        'supply_type' => 'Banana',
    ]);

    $this->actingAs($user);

    Volt::test('tiffin-items.department-recipe-manager')->call('confirmRemove', $bananaRow->id);

    $this->assertDatabaseHas('tiffin_department_items', ['id' => $bananaRow->id]);

    Volt::test('tiffin-items.department-recipe-manager')
        ->call('confirmRemove', $eggRow->id)
        ->call('remove');

    $this->assertDatabaseMissing('tiffin_department_items', ['id' => $eggRow->id]);
});

test('reordering swaps sort order between two items', function () {
    $user = User::factory()->create();
    $swing = TiffinDepartment::create(['name' => 'Swing']);
    makeTiffinRecipe($swing, ['Banana', 'Bread']);
    $banana = TiffinDepartmentItem::whereHas('tiffinItem', fn ($query) => $query->where('name', 'Banana'))->first();
    $bread = TiffinDepartmentItem::whereHas('tiffinItem', fn ($query) => $query->where('name', 'Bread'))->first();

    expect($banana->sort_order)->toBe(1);
    expect($bread->sort_order)->toBe(2);

    $this->actingAs($user);

    Volt::test('tiffin-items.department-recipe-manager')->call('moveDown', $banana->id);

    expect($banana->fresh()->sort_order)->toBe(2);
    expect($bread->fresh()->sort_order)->toBe(1);
});
