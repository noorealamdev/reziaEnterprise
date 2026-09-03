<x-mail::message>
# Daily Report — {{ $date->format('M j, Y') }}

**Today:** {{ $todayEntryCount }} {{ Str::plural('entry', $todayEntryCount) }} logged, totaling {{ number_format($todayTotal, 2) }}.

<x-mail::table>
| Metric | Amount |
| :--- | ---: |
| Unbilled ({{ $unbilledCount }} {{ Str::plural('entry', $unbilledCount) }}) | {{ number_format($unbilledTotal, 2) }} |
| Outstanding (due) | {{ number_format($outstandingTotal, 2) }} |
| Month to date | {{ number_format($monthToDateTotal, 2) }} |
</x-mail::table>

@if ($readyToInvoice->isNotEmpty())
## Ready to Invoice

<x-mail::table>
| Company | Category | Entries | Total |
| :--- | :--- | ---: | ---: |
@foreach ($readyToInvoice as $group)
| {{ $group['company']->name }} | {{ $group['category']->name }} | {{ $group['count'] }} | {{ number_format($group['total'], 2) }} |
@endforeach
</x-mail::table>
@else
Every entry has been invoiced — nothing waiting on billing.
@endif

<x-mail::button :url="route('dashboard')">
Open Dashboard
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
