<?php

use App\Models\CanteenEntry;
use App\Models\CanteenEntryItem;
use App\Models\RolePermission;
use App\Models\User;
use App\UserRole;
use Livewire\Volt\Volt;

/**
 * 1 Sep 2026 from the canteen's "Main Sheet". The sheet's own totals for this
 * row are purchases 13,280, profit 4,160, net profit 3,400 and cash 3,400.
 */
const CANTEEN_SHEET_FIRST_DAY = [
    'date' => '2026-09-01',
    'quantities' => ['bakery_1' => 300, 'bakery_2' => 200, 'porota' => 150, 'vaji' => 50, 'singara' => 150, 'khuder_vat' => 30, 'rice' => 90, 'cha' => 3, 'drinks_others' => 400],
    'riceProfit' => 710,
    'dailyCost' => 760,
];

function fillCanteenDay($form, array $day)
{
    $form->call('startCreate')->set('entry_date', $day['date']);

    foreach ($day['quantities'] as $item => $quantity) {
        $form->set("items.{$item}.quantity", (string) $quantity);
    }

    return $form->set('items.rice.profit_amount', (string) $day['riceProfit'])->set('daily_cost', (string) $day['dailyCost']);
}

test('a full day calculates exactly like the spreadsheet row', function () {
    $this->actingAs(User::factory()->create());

    fillCanteenDay(Volt::test('canteen.ledger-manager'), CANTEEN_SHEET_FIRST_DAY)->call('save')->assertHasNoErrors();

    $entry = CanteenEntry::sole();
    expect((float) $entry->total_purchases)->toBe(13280.0);
    expect((float) $entry->total_profit)->toBe(4160.0);
    expect($entry->net_profit)->toBe(3400.0);
    expect($entry->cash)->toBe(3400.0);

    $lines = $entry->items->keyBy('item');
    expect($lines)->toHaveCount(9);
    expect((float) $lines['bakery_1']->purchase_amount)->toBe(2400.0);
    expect((float) $lines['bakery_1']->profit_amount)->toBe(600.0);
    expect((float) $lines['khuder_vat']->purchase_amount)->toBe(1200.0);
    expect((float) $lines['khuder_vat']->profit_amount)->toBe(300.0);
    expect((float) $lines['rice']->purchase_amount)->toBe(4230.0);
    expect((float) $lines['rice']->profit_amount)->toBe(710.0);
    expect((float) $lines['cha']->purchase_amount)->toBe(1050.0);
    expect((float) $lines['cha']->profit_amount)->toBe(450.0);
    expect((float) $lines['drinks_others']->purchase_amount)->toBe(0.0);
    expect((float) $lines['drinks_others']->profit_amount)->toBe(1000.0);
});

test('company staff due comes off the cash', function () {
    $this->actingAs(User::factory()->create());

    fillCanteenDay(Volt::test('canteen.ledger-manager'), CANTEEN_SHEET_FIRST_DAY)
        ->set('company_staff_due', '500')
        ->call('save')
        ->assertHasNoErrors();

    expect(CanteenEntry::sole()->net_profit)->toBe(3400.0);
    expect(CanteenEntry::sole()->cash)->toBe(2900.0);
});

test('a one-day zero buy rate works and is not carried to the next day', function () {
    $this->actingAs(User::factory()->create());

    fillCanteenDay(Volt::test('canteen.ledger-manager'), [...CANTEEN_SHEET_FIRST_DAY, 'quantities' => ['bakery_2' => 250, 'rice' => 90]])
        ->set('items.bakery_2.purchase_rate', '0')
        ->call('save')
        ->assertHasNoErrors();

    $line = CanteenEntry::sole()->items->firstWhere('item', 'bakery_2');
    expect((float) $line->purchase_amount)->toBe(0.0);
    expect((float) $line->profit_amount)->toBe(500.0);

    Volt::test('canteen.ledger-manager')->call('startCreate')->assertSet('items.bakery_2.purchase_rate', 8.0);
});

test('a changed rate applies from then on and never rewrites a past day', function () {
    $this->actingAs(User::factory()->create());

    fillCanteenDay(Volt::test('canteen.ledger-manager'), [...CANTEEN_SHEET_FIRST_DAY, 'date' => '2026-09-01', 'quantities' => ['vaji' => 100, 'rice' => 90]])
        ->call('save')
        ->assertHasNoErrors();
    fillCanteenDay(Volt::test('canteen.ledger-manager'), [...CANTEEN_SHEET_FIRST_DAY, 'date' => '2026-09-02', 'quantities' => ['vaji' => 100, 'rice' => 90]])
        ->set('items.vaji.purchase_rate', '9')
        ->call('save')
        ->assertHasNoErrors();

    Volt::test('canteen.ledger-manager')->call('startCreate')->assertSet('items.vaji.purchase_rate', 9.0);

    $firstDay = CanteenEntry::whereDate('entry_date', '2026-09-01')->sole();
    expect((float) $firstDay->items->firstWhere('item', 'vaji')->purchase_amount)->toBe(800.0);

    Volt::test('canteen.ledger-manager')
        ->call('startEdit', $firstDay->id)
        ->assertSet('items.vaji.purchase_rate', '8')
        ->assertSet('items.vaji.quantity', '100')
        ->assertSet('items.rice.profit_amount', '710')
        ->call('save')
        ->assertHasNoErrors();
    expect((float) $firstDay->fresh()->items->firstWhere('item', 'vaji')->purchase_amount)->toBe(800.0);
});

test('editing replaces the day\'s items and removing a quantity removes the item', function () {
    $this->actingAs(User::factory()->create());
    fillCanteenDay(Volt::test('canteen.ledger-manager'), CANTEEN_SHEET_FIRST_DAY)->call('save');
    $entry = CanteenEntry::sole();

    Volt::test('canteen.ledger-manager')
        ->call('startEdit', $entry->id)
        ->set('items.cha.quantity', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($entry->fresh()->items)->toHaveCount(8);
    expect((float) $entry->fresh()->total_purchases)->toBe(12230.0);
    expect((float) $entry->fresh()->total_profit)->toBe(3710.0);
});

test('deleting an entry removes its items too', function () {
    $this->actingAs(User::factory()->create());
    fillCanteenDay(Volt::test('canteen.ledger-manager'), CANTEEN_SHEET_FIRST_DAY)->call('save');

    Volt::test('canteen.ledger-manager')->call('confirmDelete', CanteenEntry::sole()->id)->call('delete');

    expect(CanteenEntryItem::count())->toBe(0);
});

test('a sold item with a missing figure is refused instead of recording zero', function (array $changes) {
    $this->actingAs(User::factory()->create());
    $form = fillCanteenDay(Volt::test('canteen.ledger-manager'), CANTEEN_SHEET_FIRST_DAY);

    foreach ($changes as $field => $value) {
        $form->set($field, $value);
    }

    $form->call('save')->assertHasErrors(['items']);
    expect(CanteenEntry::count())->toBe(0);
})->with([
    'no rice profit' => [['items.rice.profit_amount' => '']],
    'no buy rate' => [['items.porota.purchase_rate' => '']],
    'no profit rate' => [['items.cha.profit_rate' => '']],
]);

test('figures cannot be negative', function (string $field) {
    $this->actingAs(User::factory()->create());

    fillCanteenDay(Volt::test('canteen.ledger-manager'), CANTEEN_SHEET_FIRST_DAY)
        ->set($field, '-1')
        ->call('save')
        ->assertHasErrors([$field]);
})->with(['items.vaji.quantity', 'items.vaji.purchase_rate', 'items.vaji.profit_rate', 'items.rice.profit_amount', 'company_staff_due']);

test('the form previews the totals live before saving', function () {
    $this->actingAs(User::factory()->create());

    $form = fillCanteenDay(Volt::test('canteen.ledger-manager'), CANTEEN_SHEET_FIRST_DAY)->set('company_staff_due', '100');

    expect($form->viewData('formPurchases'))->toBe(13280.0);
    expect($form->viewData('formProfit'))->toBe(4160.0);
    expect($form->viewData('formNet'))->toBe(3400.0);
    expect($form->viewData('formCash'))->toBe(3300.0);
});

test('the table totals every item across all pages and follows the filters', function () {
    $this->actingAs(User::factory()->create());

    foreach (range(1, 35) as $offset) {
        fillCanteenDay(Volt::test('canteen.ledger-manager'), [
            ...CANTEEN_SHEET_FIRST_DAY,
            'date' => now()->setDate(2026, 8, 1)->addDays($offset)->toDateString(),
            'quantities' => ['vaji' => 10, 'rice' => 10],
            'riceProfit' => 70,
            'dailyCost' => 1,
        ])->call('save');
    }

    $all = Volt::test('canteen.ledger-manager')->assertSee('Grand Total');
    expect($all->viewData('entries'))->toHaveCount(31);
    expect((float) $all->viewData('itemTotals')['vaji']->quantity)->toBe(350.0);
    expect((float) $all->viewData('itemTotals')['rice']->profit_amount)->toBe(2450.0);

    $september = Volt::test('canteen.ledger-manager')->set('yearFilter', '2026')->set('monthFilter', '9');
    $september->assertDontSee('Grand Total');
    expect((float) $september->viewData('itemTotals')['vaji']->quantity)->toBe(50.0);
});

test('the page opens on the summary, and the full sheet shows every item', function () {
    $this->actingAs(User::factory()->create());
    fillCanteenDay(Volt::test('canteen.ledger-manager'), CANTEEN_SHEET_FIRST_DAY)->call('save');

    Volt::test('canteen.ledger-manager')
        ->assertSet('viewMode', 'summary')
        ->assertDontSeeHtml('colspan="3"')
        ->assertSee('Canteen — all-time')
        ->assertSeeInOrder(['01-Sep-26', '13280', '4160', '760', '3400', 'Grand Total', '13280', '4160', '760', '3400']);

    Volt::test('canteen.ledger-manager')
        ->set('yearFilter', '2026')
        ->set('monthFilter', '9')
        ->assertSee('Sep-26 canteen 30 days');

    Volt::test('canteen.ledger-manager')
        ->set('viewMode', 'sheet')
        ->assertSeeHtml('colspan="3"')
        ->assertSeeInOrder(['Grand Total', '300', '2,400', '600']);

    Volt::test('canteen.ledger-manager')
        ->set('viewMode', 'anything-else')
        ->assertDontSeeHtml('colspan="3"');
});

test('edit and delete are offered in both views, only to staff who can modify', function (string $view) {
    $this->actingAs(User::factory()->create());
    $entry = CanteenEntry::factory()->create();

    Volt::test('canteen.ledger-manager')
        ->set('viewMode', $view)
        ->assertSeeHtml("startEdit({$entry->id})")
        ->assertSeeHtml("confirmDelete({$entry->id})");

    $this->actingAs(User::factory()->canteenManager()->create());
    RolePermission::where('role', UserRole::CanteenManager->value)->where('permission', 'canteen.modify')->delete();

    Volt::test('canteen.ledger-manager')
        ->set('viewMode', $view)
        ->assertDontSeeHtml("startEdit({$entry->id})")
        ->assertDontSeeHtml("confirmDelete({$entry->id})");
})->with(['summary', 'sheet']);

test('rate boxes stay editable but the Tab key skips them', function () {
    $this->actingAs(User::factory()->create());

    $html = Volt::test('canteen.ledger-manager')->call('startCreate')->html();

    preg_match('/<input[^>]*id="item_vaji_purchase_rate"[^>]*>/', $html, $rate);
    preg_match('/<input[^>]*id="item_vaji_quantity"[^>]*>/', $html, $quantity);
    preg_match('/<input[^>]*id="item_rice_profit_amount"[^>]*>/', $html, $riceProfit);

    expect($rate[0])->toContain('tabindex="-1"')->not->toContain('disabled');
    expect($quantity[0])->not->toContain('tabindex');
    expect($riceProfit[0])->not->toContain('tabindex');
});

test('a chosen month reads oldest first like the sheet, otherwise newest first', function () {
    $this->actingAs(User::factory()->create());
    CanteenEntry::factory()->create(['entry_date' => '2026-09-01']);
    CanteenEntry::factory()->create(['entry_date' => '2026-09-02']);

    expect(Volt::test('canteen.ledger-manager')->viewData('entries')->first()->entry_date->toDateString())->toBe('2026-09-02');
    expect(Volt::test('canteen.ledger-manager')->set('yearFilter', '2026')->set('monthFilter', '9')->viewData('entries')->first()->entry_date->toDateString())->toBe('2026-09-01');
});
