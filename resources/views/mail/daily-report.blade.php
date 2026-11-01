@php
    $profitOrLoss = fn (float $amount): string => ($amount < 0 ? 'Loss ' : ($amount > 0 ? 'Profit ' : '')).number_format(abs($amount), 2);
    $lastMonthLabel = $date->copy()->startOfMonth()->subMonthNoOverflow()->format('F Y');
    $thisMonth = $profitAndLoss['months']->first(fn (array $row) => $row['isCurrent']);
    $lastMonth = $profitAndLoss['months']->first(fn (array $row) => $row['month']->format('F Y') === $lastMonthLabel);
    $overview = $invoiceOverview->keyBy('statusValue');
@endphp
<x-mail::message>
# Daily Report — {{ $date->format('M j, Y') }}

@if ($profitAndLoss['months']->isNotEmpty())
<x-mail::panel>
@if ($lastMonth)
**{{ $lastMonthLabel }}: {{ $profitOrLoss($lastMonth['net']) }}**

@endif
**{{ $date->format('F') }} so far: {{ $profitOrLoss($thisMonth['net'] ?? 0) }}**

Since {{ $profitAndLoss['months']->last()['month']->format('F Y') }}: {{ $profitOrLoss($profitAndLoss['total']['net']) }}
</x-mail::panel>
@endif

@if ($billsNotSubmitted->isNotEmpty())
## Bills not submitted to the factory

A month's bills are due by the {{ \App\Support\BusinessSnapshot::BILL_DEADLINE_DAY }}th of the next month. A bill counts as submitted once its signed copy is uploaded.

@include('mail.partials.late-bills', ['bills' => $billsNotSubmitted])
@endif

@if ($billsDueSoon)
**{{ $billsDueSoon['month']->format('F') }} bills are due by {{ $billsDueSoon['deadline']->format('j F') }}.** {{ $billsDueSoon['count'] }} {{ Str::plural('service', $billsDueSoon['count']) }} still to bill: {{ number_format($billsDueSoon['amount'], 2) }}.
@endif

## Profit & Loss

@if ($profitAndLoss['months']->isEmpty())
Nothing recorded yet.
@else
<x-mail::table>
| | {{ $lastMonthLabel }} | {{ $date->format('F') }} so far |
| :--- | ---: | ---: |
| Income | {{ number_format($lastMonth['income'] ?? 0, 2) }} | {{ number_format($thisMonth['income'] ?? 0, 2) }} |
| VAT | {{ number_format($lastMonth['vat'] ?? 0, 2) }} | {{ number_format($thisMonth['vat'] ?? 0, 2) }} |
| Job costs | {{ number_format($lastMonth['jobCosts'] ?? 0, 2) }} | {{ number_format($thisMonth['jobCosts'] ?? 0, 2) }} |
| **Job profit** | **{{ number_format($lastMonth['jobProfit'] ?? 0, 2) }}** | **{{ number_format($thisMonth['jobProfit'] ?? 0, 2) }}** |
| Salaries | {{ number_format($lastMonth['salaries'] ?? 0, 2) }} | {{ number_format($thisMonth['salaries'] ?? 0, 2) }} |
| Expenses | {{ number_format($lastMonth['expenses'] ?? 0, 2) }} | {{ number_format($thisMonth['expenses'] ?? 0, 2) }} |
| Sajjat spending | {{ number_format($lastMonth['sajjat'] ?? 0, 2) }} | {{ number_format($thisMonth['sajjat'] ?? 0, 2) }} |
| **Result** | **{{ $profitOrLoss($lastMonth['net'] ?? 0) }}** | **{{ $profitOrLoss($thisMonth['net'] ?? 0) }}** |
</x-mail::table>

Job profit is income less VAT and job costs; the result then takes off salaries, expenses and Sajjat spending. Income counts all work, billed or not. VAT is only counted once a bill is generated, so recent months can drop a little.
@if ($profitAndLoss['uncountedBillCount'] > 0)
Not counted: {{ $profitAndLoss['uncountedBillCount'] }} past paper {{ Str::plural('bill', $profitAndLoss['uncountedBillCount']) }} ({{ number_format($profitAndLoss['uncountedBillAmount'], 2) }}) with no cost recorded, because their profit can't be worked out.
@endif
Canteen and Egg Sales are separate and not included.
@endif

## Invoice Overview

<x-mail::table>
| | Amount |
| :--- | ---: |
| Paid bills ({{ $overview['paid']['count'] }}) | {{ number_format($overview['paid']['amount'], 2) }} |
| Unpaid bills ({{ $overview['unpaid']['count'] }}) | {{ number_format($overview['unpaid']['amount'], 2) }} |
| **All bills ({{ $overview['paid']['count'] + $overview['unpaid']['count'] }})** | **{{ number_format($billedTotal, 2) }}** |
| Bills with a signed copy ({{ $overview['signed']['count'] }}) | {{ number_format($overview['signed']['amount'], 2) }} |
| Work not billed yet ({{ $overview['unbilled']['count'] }} {{ Str::plural('entry', $overview['unbilled']['count']) }}) | {{ number_format($overview['unbilled']['amount'], 2) }} |
</x-mail::table>

Amounts are full bill values. **Factories still owe {{ number_format($outstandingTotal, 2) }}**, which is the unpaid bills after VAT and part-payments.

@if ($readyToInvoice->isNotEmpty())
## Ready to Invoice

<x-mail::table>
| Company | Category | Total |
| :--- | :--- | ---: |
@foreach ($readyToInvoice as $group)
| {{ $group['company']->name }}{{ $group['isOverdue'] ? ' — since '.$group['oldestEntryDate']->format('M Y') : '' }}&nbsp;&nbsp; | {{ $group['category']->name }} | {{ number_format($group['total'], 2) }} |
@endforeach
</x-mail::table>
@else
Every entry has been invoiced — nothing waiting on billing.
@endif

## Companies — {{ $companiesMonthLabel }}

@if ($companiesRows->isEmpty())
No billing activity this month yet.
@else
@if ($companiesMonthLabel !== $date->format('F Y'))
No work is recorded for {{ $date->format('F') }} yet, so this shows last month.

@endif
<x-mail::table>
| Company | Work done&nbsp;&nbsp; | Profit |
| :--- | ---: | ---: |
@foreach ($companiesRows as $row)
| {{ $row['label'] }} | {{ number_format($row['billed'], 2) }}&nbsp;&nbsp; | {{ number_format($row['profitAllWork'], 2) }} |
@endforeach
| **Total** | **{{ number_format($companiesRows->sum('billed'), 2) }}**&nbsp;&nbsp; | **{{ number_format($companiesRows->sum('profitAllWork'), 2) }}** |
</x-mail::table>

Profit here is work done less job costs, before VAT, salaries and other spending.
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

## Today's Activity

@if ($todayActivity->isEmpty())
No work was recorded for today.
@else
{{ $todayEntryCount }} {{ Str::plural('entry', $todayEntryCount) }} recorded, totaling {{ number_format($todayTotal, 2) }}.

<x-mail::table>
| Company | Category | Item | Bill |
| :--- | :--- | :--- | ---: |
@foreach ($todayActivity as $entry)
| {{ $entry->company->name }} | {{ $entry->serviceCategory->name }} | {{ $entry->label }} | {{ number_format($entry->billAmount, 2) }} |
@endforeach
</x-mail::table>
@endif

All amounts are in Taka.

<x-mail::button :url="route('dashboard')">
Open Dashboard
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
