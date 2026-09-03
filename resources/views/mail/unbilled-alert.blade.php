<x-mail::message>
# Unbilled Alert

The following {{ $groups->count() }} {{ Str::plural('group', $groups->count()) }} {{ $groups->count() === 1 ? 'has' : 'have' }} unbilled work that's been sitting for a while and may need invoicing:

<x-mail::table>
| Company | Category | Entries | Unbilled Total | Oldest Entry | Days |
| :--- | :--- | ---: | ---: | :--- | ---: |
@foreach ($groups as $group)
| {{ $group['company']->name }} | {{ $group['category']->name }} | {{ $group['count'] }} | {{ number_format($group['total'], 2) }} | {{ $group['oldestEntryDate']->format('M j, Y') }} | {{ $group['daysOutstanding'] }} |
@endforeach
</x-mail::table>

<x-mail::button :url="route('dashboard')">
Open Dashboard
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
