<x-mail::message>
# Daily Report — {{ $date->format('M j, Y') }}

**Today:** {{ $todayEntryCount }} {{ Str::plural('entry', $todayEntryCount) }} logged, totaling {{ number_format($todayTotal, 2) }}.

<x-mail::table>
| Metric | Amount |
| :--- | ---: |
| Unbilled ({{ $unbilledCount }} {{ Str::plural('entry', $unbilledCount) }}) | {{ number_format($unbilledTotal, 2) }} |
| Billed (all-time) | {{ number_format($billedTotal, 2) }} |
| Outstanding (due) | {{ number_format($outstandingTotal, 2) }} |
| Profit this month (paid only) | {{ number_format($profitThisMonth, 2) }} |
| Month to date (billed) | {{ number_format($monthToDateTotal, 2) }} |
</x-mail::table>

@if ($readyToInvoice->isNotEmpty())
## Ready to Invoice

<x-mail::table>
| Company | Category | Entries | Total |
| :--- | :--- | ---: | ---: |
@foreach ($readyToInvoice as $group)
| {{ $group['company']->name }}{{ $group['isOverdue'] ? ' — overdue' : '' }} | {{ $group['category']->name }} | {{ $group['count'] }} | {{ number_format($group['total'], 2) }} |
@endforeach
</x-mail::table>
@else
Every entry has been invoiced — nothing waiting on billing.
@endif

## Invoice Overview

<x-mail::table>
| Status | Count | Amount |
| :--- | ---: | ---: |
@foreach ($invoiceOverview as $row)
| {{ $row['label'] }} | {{ $row['count'] }} | {{ number_format($row['amount'], 2) }} |
@endforeach
</x-mail::table>

## Companies — {{ $companiesMonthLabel }}

@if ($companiesRows->isEmpty())
No billing activity this month yet.
@else
<x-mail::table>
| Company | Billed | Cost | Profit (All Work) | Profit (Paid) |
| :--- | ---: | ---: | ---: | ---: |
@foreach ($companiesRows as $row)
| {{ $row['label'] }} | {{ number_format($row['billed'], 2) }} | {{ number_format($row['cost'], 2) }} | {{ number_format($row['profitAllWork'], 2) }} | {{ number_format($row['profitPaid'], 2) }} |
@endforeach
</x-mail::table>
@endif

@if ($agreementDeadlines->isNotEmpty())
## Agreement Deadlines

<x-mail::table>
| Company | Agreement | Status |
| :--- | :--- | :--- |
@foreach ($agreementDeadlines as $row)
| {{ $row['agreement']->company->name }} | {{ $row['agreement']->title }} | {{ $row['isExpired'] ? 'Expired '.$row['daysRemaining'].' '.Str::plural('day', $row['daysRemaining']).' ago' : $row['daysRemaining'].' '.Str::plural('day', $row['daysRemaining']).' left' }} |
@endforeach
</x-mail::table>
@endif

@if ($todayActivity->isNotEmpty())
## Today's Activity

<x-mail::table>
| Company | Category | Item | Bill |
| :--- | :--- | :--- | ---: |
@foreach ($todayActivity as $entry)
| {{ $entry->company->name }} | {{ $entry->serviceCategory->name }} | {{ $entry->label }} | {{ number_format($entry->billAmount, 2) }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="route('dashboard')">
Open Dashboard
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
