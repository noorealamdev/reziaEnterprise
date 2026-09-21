<x-mail::message>
# Sazzad Report — {{ $date->format('M j, Y') }}

## Balance available now

<x-mail::table>
| Wallet | Balance |
| :--- | ---: |
@foreach ($wallets as $key => $label)
| {{ $label }} | {{ number_format($balances[$key], 2) }} |
@endforeach
| **Total** | **{{ number_format($totalBalance, 2) }}** |
</x-mail::table>

@foreach ($wallets as $key => $label)
@if ($balances[$key] < 0)
**Overspent:** {{ $label }} is {{ number_format(abs($balances[$key]), 2) }} below zero — Sazzad has spent more than was topped up.

@endif
@endforeach
## Today

@if ($todayEntries->isEmpty())
No top-ups or expenses were recorded today.
@else
Topped up **{{ number_format($todayTopUps, 2) }}** · Spent **{{ number_format($todaySpent, 2) }}**

<x-mail::table>
| Type | Wallet | Details | Amount |
| :--- | :--- | :--- | ---: |
@foreach ($todayEntries as $entry)
| {{ $entry->type === 'top_up' ? 'Top-up' : 'Expense' }} | {{ $wallets[$entry->wallet] ?? $entry->wallet }} | {{ $entry->description }} | {{ $entry->type === 'top_up' ? '+' : '−' }}{{ number_format((float) $entry->amount, 2) }} |
@endforeach
</x-mail::table>
@endif

## {{ $date->format('F') }} so far

<x-mail::table>
| | Amount |
| :--- | ---: |
| Topped up | {{ number_format($monthTopUps, 2) }} |
| Spent | {{ number_format($monthSpent, 2) }} |
</x-mail::table>

<x-mail::button :url="route('sajjat.index')">
Open Sazzad
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
