<?php

return [

    /*
    |--------------------------------------------------------------------
    | Letterhead / Invoice Issuer Details
    |--------------------------------------------------------------------
    |
    | The business details printed on every generated invoice — this is
    | Rezia Enterprise's own information (the seller), not a customer's.
    | Kept here rather than hardcoded in the invoice view so a future
    | deployment of this app for a different business only needs to
    | change these values (and the .env overrides below) to relabel the
    | whole invoicing system.
    |
    */

    'name' => env('COMPANY_NAME', 'Rezia Enterprise'),

    'tagline' => env('COMPANY_TAGLINE', 'Contractor/Construction/Supply Firm'),

    'phones' => env('COMPANY_PHONES', '01914-023738, 01785-458544'),

    'email' => env('COMPANY_EMAIL', 'mdrakib.pro@gmail.com'),

    'address' => env('COMPANY_ADDRESS', 'MM Market 2nd Floor Kadamtoli, South Para (Navana), Adamjee Nagar, Siddhirgonj, Narayangonj- 1431.'),

];
