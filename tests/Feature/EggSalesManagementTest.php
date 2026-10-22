<?php

use App\Models\EggSaleTransaction;
use App\Models\RolePermission;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Livewire\Volt\Volt;

test('guests are redirected to login', function () {
    $this->get('/egg-sales')->assertRedirect('/login');
});

test('a received entry raises the stock balance and a sale lowers it', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->call('startReceived')
        ->set('quantity', '500')
        ->call('save')
        ->assertHasNoErrors();

    Volt::test('egg-sales.sales-manager')
        ->call('startSold')
        ->set('quantity', '150')
        ->set('rate', '14')
        ->set('description', 'Rahim Store')
        ->call('save')
        ->assertHasNoErrors();

    expect(EggSaleTransaction::quantityBalance())->toBe(350.0);

    $component = Volt::test('egg-sales.sales-manager');
    expect($component->viewData('totalRevenue'))->toBe(2100.0);
    $component->assertSee('Rahim Store')->assertSee('Received');
});

test('more sold than ever received shows a negative balance instead of blocking the entry', function () {
    $this->actingAs(User::factory()->create());
    EggSaleTransaction::factory()->received()->create(['quantity' => 100]);

    Volt::test('egg-sales.sales-manager')
        ->call('startSold')
        ->set('quantity', '250')
        ->set('rate', '14')
        ->set('description', 'Corner Bakery')
        ->call('save')
        ->assertHasNoErrors();

    expect(EggSaleTransaction::quantityBalance())->toBe(-150.0);
});

test('a received entry can optionally record a rate, which computes its value', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->call('startReceived')
        ->set('quantity', '500')
        ->call('save')
        ->assertHasNoErrors();

    $withoutRate = EggSaleTransaction::sole();
    expect($withoutRate->rate)->toBeNull();
    expect($withoutRate->amount)->toBeNull();

    Volt::test('egg-sales.sales-manager')
        ->call('startReceived')
        ->set('quantity', '300')
        ->set('rate', '11.5')
        ->call('save')
        ->assertHasNoErrors();

    $withRate = EggSaleTransaction::where('quantity', 300)->sole();
    expect((float) $withRate->rate)->toBe(11.5);
    expect((float) $withRate->amount)->toBe(3450.0);
});

test('a sale needs a description and a rate but a received entry defaults its note and has no amount', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->call('startSold')
        ->set('quantity', '50')
        ->call('save')
        ->assertHasErrors(['description', 'rate']);

    Volt::test('egg-sales.sales-manager')
        ->call('startReceived')
        ->set('quantity', '50')
        ->call('save')
        ->assertHasNoErrors();

    $entry = EggSaleTransaction::sole();
    expect($entry->description)->toBe('Received');
    expect($entry->amount)->toBeNull();
});

test('type and quantity are validated', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->call('startSold')
        ->set('quantity', '0')
        ->set('rate', '10')
        ->set('description', 'X')
        ->call('save')
        ->assertHasErrors(['quantity']);

    Volt::test('egg-sales.sales-manager')
        ->call('startSold')
        ->set('type', 'gifted')
        ->set('quantity', '10')
        ->set('rate', '10')
        ->set('description', 'X')
        ->call('save')
        ->assertHasErrors(['type']);

    expect(EggSaleTransaction::count())->toBe(0);
});

test('the stock balance and revenue stay all-time while the period totals follow the filters', function () {
    $this->actingAs(User::factory()->create());
    EggSaleTransaction::factory()->received()->create(['quantity' => 1000, 'transaction_date' => '2026-08-05']);
    EggSaleTransaction::factory()->create(['quantity' => 300, 'amount' => 4200, 'transaction_date' => '2026-09-05']);
    EggSaleTransaction::factory()->received()->create(['quantity' => 500, 'transaction_date' => '2026-09-06']);

    $september = Volt::test('egg-sales.sales-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '9');

    expect($september->viewData('receivedInView'))->toBe(500.0);
    expect($september->viewData('soldInView'))->toBe(300.0);
    expect($september->viewData('revenueInView'))->toBe(4200.0);
    expect($september->viewData('quantityBalance'))->toBe(1200.0);
    expect($september->viewData('totalRevenue'))->toBe(4200.0);
});

test('the week and month sold summaries are always this week/month, regardless of the active filters', function () {
    $this->actingAs(User::factory()->create());

    // Far enough before today that it falls outside both the current
    // calendar week and the current calendar month, however today lands
    // within its own month (at least ~26 days earlier, always > 7 days).
    $previousMonth = now()->startOfMonth()->subMonthNoOverflow()->addDays(2);

    EggSaleTransaction::factory()->create(['quantity' => 40, 'amount' => 560, 'transaction_date' => now()]);
    EggSaleTransaction::factory()->create(['quantity' => 15, 'amount' => 210, 'transaction_date' => $previousMonth]);

    // A filter narrowed to a completely different scope must not affect
    // these two headline numbers.
    $component = Volt::test('egg-sales.sales-manager')
        ->set('yearFilter', (string) $previousMonth->year)
        ->set('monthFilter', (string) $previousMonth->month);

    expect($component->viewData('weekSoldQuantity'))->toBe(40.0);
    expect($component->viewData('weekSoldRevenue'))->toBe(560.0);
    expect($component->viewData('monthSoldQuantity'))->toBe(40.0);
    expect($component->viewData('monthSoldRevenue'))->toBe(560.0);
});

test('a payment raises paid-in and lowers what Akter still owes, without touching the egg balance', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->call('startSold')
        ->set('quantity', '200')
        ->set('rate', '14')
        ->set('description', 'Rahim Store')
        ->call('save')
        ->assertHasNoErrors();

    expect(EggSaleTransaction::outstandingDue())->toBe(2800.0);

    Volt::test('egg-sales.sales-manager')
        ->call('startPayment')
        ->set('amount', '1000')
        ->call('save')
        ->assertHasNoErrors();

    expect(EggSaleTransaction::totalPaidIn())->toBe(1000.0);
    expect(EggSaleTransaction::outstandingDue())->toBe(1800.0);
    // Money changing hands isn't an egg leaving stock.
    expect(EggSaleTransaction::quantityBalance())->toBe(-200.0);
});

test('a payment needs only an amount — quantity, rate and description are all optional', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->call('startPayment')
        ->call('save')
        ->assertHasErrors(['amount']);

    Volt::test('egg-sales.sales-manager')
        ->call('startPayment')
        ->set('amount', '2500')
        ->call('save')
        ->assertHasNoErrors();

    $entry = EggSaleTransaction::sole();
    expect($entry->type)->toBe('payment');
    expect((float) $entry->quantity)->toBe(0.0);
    expect($entry->rate)->toBeNull();
    expect($entry->description)->toBe('Payment');
});

test('outstanding due clamps at zero when payments exceed what was sold', function () {
    $this->actingAs(User::factory()->create());
    EggSaleTransaction::factory()->create(['quantity' => 100, 'amount' => 1400]);
    EggSaleTransaction::factory()->payment()->create(['amount' => 2000]);

    expect(EggSaleTransaction::outstandingDue())->toBe(0.0);
});

test('the type filter can narrow the ledger down to payments only', function () {
    $this->actingAs(User::factory()->create());
    EggSaleTransaction::factory()->payment()->count(2)->create();
    EggSaleTransaction::factory()->count(3)->create();

    expect(Volt::test('egg-sales.sales-manager')->set('yearFilter', '')->set('typeFilter', 'payment')->viewData('transactions'))->toHaveCount(2);
});

test('recording a payment shows a toast alert', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->call('startPayment')
        ->set('amount', '1500')
        ->call('save')
        ->assertDispatched('toast', message: 'Payment recorded.', type: 'success');
});

test('editing and deleting an entry recalculates the balance', function () {
    $this->actingAs(User::factory()->create());
    $received = EggSaleTransaction::factory()->received()->create(['quantity' => 1000]);
    $sold = EggSaleTransaction::factory()->create(['quantity' => 400, 'amount' => 5000]);

    Volt::test('egg-sales.sales-manager')
        ->call('startEdit', $sold->id)
        ->set('quantity', '250')
        ->call('save')
        ->assertHasNoErrors();
    expect(EggSaleTransaction::quantityBalance())->toBe(750.0);

    Volt::test('egg-sales.sales-manager')
        ->call('confirmDelete', $received->id)
        ->call('delete');
    expect(EggSaleTransaction::quantityBalance())->toBe(-250.0);
});

test('a user with only egg_sales.view sees the ledger but cannot add, edit or delete', function () {
    $staff = User::factory()->staff()->create();
    RolePermission::create(['role' => UserRole::Staff->value, 'permission' => Permission::EggSalesView->value]);
    $entry = EggSaleTransaction::factory()->create();

    $this->actingAs($staff)->get('/egg-sales')->assertOk();

    Volt::test('egg-sales.sales-manager')
        ->assertDontSee('+ Record Sale')
        ->assertDontSee('+ Received')
        ->assertDontSee('+ Record Payment')
        ->call('startSold')
        ->set('quantity', '10')
        ->set('rate', '10')
        ->set('description', 'X')
        ->call('save')
        ->assertForbidden();

    Volt::test('egg-sales.sales-manager')->call('startEdit', $entry->id)->call('save')->assertForbidden();
    Volt::test('egg-sales.sales-manager')->call('confirmDelete', $entry->id)->call('delete')->assertForbidden();
});

test('an accountant without the egg sales permissions is forbidden from the page', function () {
    $this->actingAs(User::factory()->accountant()->create())
        ->get('/egg-sales')
        ->assertForbidden();
});

test('the type filter narrows the ledger to received or sold entries', function () {
    $this->actingAs(User::factory()->create());
    EggSaleTransaction::factory()->received()->count(2)->create();
    EggSaleTransaction::factory()->count(3)->create();

    expect(Volt::test('egg-sales.sales-manager')->set('yearFilter', '')->set('typeFilter', 'received')->viewData('transactions'))->toHaveCount(2);
    expect(Volt::test('egg-sales.sales-manager')->set('yearFilter', '')->set('typeFilter', 'sold')->viewData('transactions'))->toHaveCount(3);
    expect(Volt::test('egg-sales.sales-manager')->set('yearFilter', '')->set('typeFilter', 'nonsense')->viewData('transactions'))->toHaveCount(5);
});

test('search matches the description or the remarks, case-insensitively', function () {
    $this->actingAs(User::factory()->create());
    EggSaleTransaction::factory()->create(['description' => 'Rahim Store', 'remarks' => null]);
    EggSaleTransaction::factory()->create(['description' => 'Corner Bakery', 'remarks' => 'delivered to Rahim lane']);
    EggSaleTransaction::factory()->create(['description' => 'Local Market Vendor', 'remarks' => null]);

    $found = Volt::test('egg-sales.sales-manager')->set('yearFilter', '')->set('search', 'rahim');
    expect($found->viewData('transactions')->pluck('description')->all())
        ->toEqualCanonicalizing(['Rahim Store', 'Corner Bakery']);
});

test('quick range shows only entries within the last N days, counting today', function () {
    $this->actingAs(User::factory()->create());
    EggSaleTransaction::factory()->create(['transaction_date' => now()->toDateString()]);
    EggSaleTransaction::factory()->create(['transaction_date' => now()->subDays(6)->toDateString()]);
    EggSaleTransaction::factory()->create(['transaction_date' => now()->subDays(7)->toDateString()]);
    EggSaleTransaction::factory()->create(['transaction_date' => now()->subDays(40)->toDateString()]);

    expect(Volt::test('egg-sales.sales-manager')->set('rangeFilter', '1')->viewData('transactions'))->toHaveCount(1);
    expect(Volt::test('egg-sales.sales-manager')->set('rangeFilter', '7')->viewData('transactions'))->toHaveCount(2);
    expect(Volt::test('egg-sales.sales-manager')->set('rangeFilter', '30')->viewData('transactions'))->toHaveCount(3);
});

test('a custom from-to range is inclusive and either end can be left open', function () {
    $this->actingAs(User::factory()->create());
    foreach (['2026-09-01', '2026-09-10', '2026-09-20'] as $date) {
        EggSaleTransaction::factory()->create(['transaction_date' => $date]);
    }

    expect(Volt::test('egg-sales.sales-manager')->set('dateFrom', '2026-09-10')->set('dateTo', '2026-09-20')->viewData('transactions'))->toHaveCount(2);
    expect(Volt::test('egg-sales.sales-manager')->set('dateFrom', '2026-09-11')->viewData('transactions'))->toHaveCount(1);
    expect(Volt::test('egg-sales.sales-manager')->set('dateTo', '2026-09-10')->viewData('transactions'))->toHaveCount(2);
    expect(Volt::test('egg-sales.sales-manager')->set('dateFrom', 'garbage')->viewData('transactions'))->toHaveCount(3);
});

test('the date scopes are mutually exclusive and the totals are labelled with the active one', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '9')
        ->assertSee('September 2026')
        ->set('rangeFilter', '7')
        ->assertSet('yearFilter', '')
        ->assertSet('monthFilter', '')
        ->assertSee('last 7 days')
        ->set('dateFrom', '2026-09-01')
        ->assertSet('rangeFilter', '')
        ->set('dateTo', '2026-09-19')
        ->assertSee('01 Sep 2026 – 19 Sep 2026')
        ->set('yearFilter', '2026')
        ->assertSet('dateFrom', '')
        ->assertSet('dateTo', '');
});

test('filters combine, and the totals follow them while the balance stays all-time', function () {
    $this->actingAs(User::factory()->create());
    EggSaleTransaction::factory()->received()->create(['quantity' => 1000, 'description' => 'Received', 'transaction_date' => '2026-09-01']);
    EggSaleTransaction::factory()->create(['quantity' => 200, 'amount' => 2800, 'description' => 'Rahim Store', 'transaction_date' => '2026-09-02']);
    EggSaleTransaction::factory()->create(['quantity' => 300, 'amount' => 4200, 'description' => 'Corner Bakery', 'transaction_date' => '2026-09-03']);

    $view = Volt::test('egg-sales.sales-manager')
        ->set('yearFilter', '')
        ->set('typeFilter', 'sold')
        ->set('search', 'rahim');

    expect($view->viewData('transactions'))->toHaveCount(1);
    expect($view->viewData('soldInView'))->toBe(200.0);
    expect($view->viewData('receivedInView'))->toBe(0.0);
    expect($view->viewData('quantityBalance'))->toBe(500.0);
});

test('clear filters resets every filter at once', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->set('typeFilter', 'sold')
        ->set('search', 'rahim')
        ->set('dateFrom', '2026-09-01')
        ->assertSee('Clear filters')
        ->call('clearFilters')
        ->assertSet('typeFilter', '')
        ->assertSet('search', '')
        ->assertSet('dateFrom', '')
        ->assertDontSee('Clear filters');
});

test('an entry\'s remarks are shown on its row, and rows without remarks show none', function () {
    $this->actingAs(User::factory()->create());
    EggSaleTransaction::factory()->create(['description' => 'Rahim Store', 'remarks' => 'Paid half in advance']);
    EggSaleTransaction::factory()->received()->create(['description' => 'Received', 'remarks' => null]);

    Volt::test('egg-sales.sales-manager')
        ->set('yearFilter', '')
        ->assertSee('Paid half in advance');

    Volt::test('egg-sales.sales-manager')
        ->set('yearFilter', '')
        ->call('startSold')
        ->set('quantity', '75')
        ->set('rate', '14')
        ->set('description', 'Local Market Vendor')
        ->set('remarks', 'Picked up by the vendor directly')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Picked up by the vendor directly');
});

test('creating, editing and deleting an entry each show a toast alert', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->call('startSold')
        ->set('quantity', '50')
        ->set('rate', '14')
        ->set('description', 'Rahim Store')
        ->call('save')
        ->assertDispatched('toast', message: 'Sale recorded.', type: 'success');

    Volt::test('egg-sales.sales-manager')
        ->call('startReceived')
        ->set('quantity', '500')
        ->call('save')
        ->assertDispatched('toast', message: 'Received entry recorded.', type: 'success');

    $entry = EggSaleTransaction::where('description', 'Rahim Store')->sole();

    Volt::test('egg-sales.sales-manager')
        ->call('startEdit', $entry->id)
        ->set('quantity', '75')
        ->call('save')
        ->assertDispatched('toast', message: 'Entry updated.', type: 'success');

    Volt::test('egg-sales.sales-manager')
        ->call('confirmDelete', $entry->id)
        ->call('delete')
        ->assertDispatched('toast', message: 'Entry deleted.', type: 'success');
});

test('a failed validation shows no success toast', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('egg-sales.sales-manager')
        ->call('startSold')
        ->set('quantity', '50')
        ->call('save')
        ->assertHasErrors(['description', 'rate'])
        ->assertNotDispatched('toast');
});
