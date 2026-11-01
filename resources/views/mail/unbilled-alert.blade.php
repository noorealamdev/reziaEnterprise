<x-mail::message>
# Bills not submitted to the factory

A month's bills are due by the {{ \App\Support\BusinessSnapshot::BILL_DEADLINE_DAY }}th of the next month. A bill counts as submitted once its signed copy is uploaded. These are late:

@include('mail.partials.late-bills', ['bills' => $bills])

All amounts are in Taka.

<x-mail::button :url="route('dashboard')">
Open Dashboard
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
