<x-mail::message>
# Agreement Deadline Alert

The following {{ $agreements->count() }} {{ Str::plural('agreement', $agreements->count()) }} {{ $agreements->count() === 1 ? 'is' : 'are' }} expiring soon and may need renewal:

<x-mail::table>
| Company | Agreement | End Date | Days Left |
| :--- | :--- | :--- | ---: |
@foreach ($agreements as $row)
| {{ $row['agreement']->company->name }} | {{ $row['agreement']->title }} | {{ $row['agreement']->end_date->format('M j, Y') }} | {{ $row['daysRemaining'] }} |
@endforeach
</x-mail::table>

<x-mail::button :url="route('company-agreements.index')">
View Agreements
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
