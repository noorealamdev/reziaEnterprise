<x-mail::table>
| Bill | Problem | Amount |
| :--- | :--- | ---: |
@foreach ($bills as $bill)
| {{ $bill['company']->name }} — {{ $bill['category']->name }}, {{ $bill['month']->format('M Y') }} | {{ $bill['invoice'] ? 'Generated, no signed copy' : 'Not generated' }}, {{ $bill['daysLate'] }} {{ Str::plural('day', $bill['daysLate']) }} late | {{ number_format($bill['amount'], 2) }} |
@endforeach
| **Total** | | **{{ number_format($bills->sum('amount'), 2) }}** |
</x-mail::table>
