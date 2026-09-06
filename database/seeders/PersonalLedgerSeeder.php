<?php

namespace Database\Seeders;

use App\Models\PersonalContact;
use App\Models\PersonalPayment;
use App\Models\PersonalSale;
use App\Models\User;
use Illuminate\Database\Seeder;

class PersonalLedgerSeeder extends Seeder
{
    /**
     * Seeds a handful of realistic scenarios against the admin Super
     * Admin's own Personal Ledger — a mix of due states (partially paid,
     * fully settled, untouched, and paid off in several small parts over
     * time) so the "cash, often paid partially" behavior the client
     * described is actually visible without adding anything by hand.
     */
    public function run(): void
    {
        $owner = User::where('email', 'noorealamdev@gmail.com')->first();

        if (! $owner) {
            return;
        }

        // Wipe first rather than updateOrCreate — the same "no stale/
        // colliding rows across reseeds" discipline SimbaFashionSeeder
        // settled on. Cascade deletes handle the sales/payments.
        PersonalContact::where('user_id', $owner->id)->delete();

        // Partially paid — the client's own example: goods given once,
        // paid back in parts, balance still outstanding.
        $rahim = PersonalContact::create([
            'user_id' => $owner->id,
            'name' => 'Rahim Uddin',
            'factory_name' => 'Green Textile Ltd',
            'phone' => '01711-223344',
        ]);
        PersonalSale::create([
            'personal_contact_id' => $rahim->id,
            'sale_date' => now()->subDays(25)->toDateString(),
            'description' => '10 drums of oil',
            'amount' => 20000,
        ]);
        PersonalPayment::create([
            'personal_contact_id' => $rahim->id,
            'payment_date' => now()->subDays(10)->toDateString(),
            'amount' => 12000,
            'remarks' => 'Paid in cash at the factory',
        ]);

        // Fully settled — sold twice, paid off both times, balance zero.
        $karim = PersonalContact::create([
            'user_id' => $owner->id,
            'name' => 'Karim Sheikh',
            'factory_name' => 'Palmal Group',
            'phone' => '01822-334455',
        ]);
        PersonalSale::create([
            'personal_contact_id' => $karim->id,
            'sale_date' => now()->subDays(40)->toDateString(),
            'description' => '8 drums of oil',
            'amount' => 15000,
        ]);
        PersonalPayment::create([
            'personal_contact_id' => $karim->id,
            'payment_date' => now()->subDays(35)->toDateString(),
            'amount' => 15000,
        ]);
        PersonalSale::create([
            'personal_contact_id' => $karim->id,
            'sale_date' => now()->subDays(15)->toDateString(),
            'description' => '6 drums of oil',
            'amount' => 10000,
        ]);
        PersonalPayment::create([
            'personal_contact_id' => $karim->id,
            'payment_date' => now()->subDays(12)->toDateString(),
            'amount' => 10000,
        ]);

        // Untouched — sold recently, nothing paid yet.
        $jasim = PersonalContact::create([
            'user_id' => $owner->id,
            'name' => 'Jasim Molla',
            'factory_name' => 'Fakir Fashion',
            'phone' => '01933-445566',
        ]);
        PersonalSale::create([
            'personal_contact_id' => $jasim->id,
            'sale_date' => now()->subDays(5)->toDateString(),
            'description' => '15 drums of oil',
            'amount' => 30000,
            'remarks' => 'Promised payment by end of month',
        ]);

        // Multiple sales, paid off in several small parts over time — the
        // clearest demonstration of "they don't pay in one go."
        $selim = PersonalContact::create([
            'user_id' => $owner->id,
            'name' => 'Selim Reza',
            'factory_name' => 'Standard Group',
            'phone' => '01644-556677',
            'remarks' => 'Regular buyer — usually settles within 2 months',
        ]);
        PersonalSale::create([
            'personal_contact_id' => $selim->id,
            'sale_date' => now()->subDays(60)->toDateString(),
            'description' => '5 drums of oil',
            'amount' => 10000,
        ]);
        PersonalPayment::create([
            'personal_contact_id' => $selim->id,
            'payment_date' => now()->subDays(50)->toDateString(),
            'amount' => 5000,
        ]);
        PersonalSale::create([
            'personal_contact_id' => $selim->id,
            'sale_date' => now()->subDays(35)->toDateString(),
            'description' => '4 drums of oil',
            'amount' => 8000,
        ]);
        PersonalPayment::create([
            'personal_contact_id' => $selim->id,
            'payment_date' => now()->subDays(20)->toDateString(),
            'amount' => 5000,
        ]);
        PersonalSale::create([
            'personal_contact_id' => $selim->id,
            'sale_date' => now()->subDays(8)->toDateString(),
            'description' => '6 drums of oil',
            'amount' => 12000,
        ]);
        PersonalPayment::create([
            'personal_contact_id' => $selim->id,
            'payment_date' => now()->subDays(2)->toDateString(),
            'amount' => 10000,
            'remarks' => 'Partial payment, rest promised next visit',
        ]);
    }
}
