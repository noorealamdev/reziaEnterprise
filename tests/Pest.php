<?php

use App\Models\ServiceCategory;
use App\Models\TiffinDepartment;
use App\Models\TiffinDepartmentItem;
use App\Models\TiffinItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * ServiceCategory has no factory (it's a fixed seeded lookup, not
 * randomized test data) — this helper creates one directly, satisfying
 * the unique `name`/`invoice_code` columns.
 */
function makeServiceCategory(string $name): ServiceCategory
{
    return ServiceCategory::create([
        'name' => $name,
        'invoice_code' => strtoupper(str()->random(6)),
        'sort_order' => 0,
    ]);
}

/**
 * Assigns a list of items (by name, created if missing) to a Tiffin
 * department for tests, in the given order.
 *
 * @param  array<int, string>  $itemNames
 */
function makeTiffinRecipe(TiffinDepartment $department, array $itemNames): void
{
    $sortOrder = 1;

    foreach ($itemNames as $name) {
        $item = TiffinItem::firstOrCreate(['name' => $name]);

        TiffinDepartmentItem::create([
            'tiffin_department_id' => $department->id,
            'tiffin_item_id' => $item->id,
            'sort_order' => $sortOrder++,
        ]);
    }
}
