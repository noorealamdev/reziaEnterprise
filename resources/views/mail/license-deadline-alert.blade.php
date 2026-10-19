<x-mail::message>
# License Deadline Alert

The following {{ $licenses->count() }} {{ Str::plural('license', $licenses->count()) }} {{ $licenses->count() === 1 ? 'is' : 'are' }} expiring soon and may need renewal:

<x-mail::table>
| License | Expiry Date | Days Left |
| :--- | :--- | ---: |
@foreach ($licenses as $row)
| {{ $row['license']->title }} | {{ $row['license']->expiry_date->format('M j, Y') }} | {{ $row['daysRemaining'] }} |
@endforeach
</x-mail::table>

<x-mail::button :url="route('licenses.index')">
View Licenses
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
