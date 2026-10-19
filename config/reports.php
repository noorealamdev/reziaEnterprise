<?php

return [

    /*
    |--------------------------------------------------------------------
    | Unbilled Alert Aging Days
    |--------------------------------------------------------------------
    |
    | A company/service-category group is flagged in the unbilled alert
    | email once its oldest unbilled job entry is older than this many
    | days — a proactive nudge that invoicing was forgotten, regardless
    | of how much money is involved.
    |
    */

    'unbilled_alert_aging_days' => (int) env('UNBILLED_ALERT_AGING_DAYS', 14),

    /*
    |--------------------------------------------------------------------
    | Unbilled Alert Resend Days
    |--------------------------------------------------------------------
    |
    | Once a group has been alerted on, it won't be alerted on again
    | until this many days have passed — avoids a daily email for as
    | long as something stays unbilled, while still reminding weekly.
    |
    */

    'unbilled_alert_resend_days' => (int) env('UNBILLED_ALERT_RESEND_DAYS', 7),

    /*
    |--------------------------------------------------------------------
    | Agreement Deadline Alert Days
    |--------------------------------------------------------------------
    |
    | A company agreement is flagged as "Expiring Soon" — and included in
    | the deadline alert email — once its end date is within this many
    | days, giving the office enough lead time to renew or renegotiate
    | before it lapses.
    |
    */

    'agreement_deadline_alert_days' => (int) env('AGREEMENT_DEADLINE_ALERT_DAYS', 30),

    /*
    |--------------------------------------------------------------------
    | Agreement Deadline Resend Days
    |--------------------------------------------------------------------
    |
    | Once an agreement's deadline has been alerted on, it won't be
    | alerted on again until this many days have passed — avoids a daily
    | email for the same agreement while it's still sitting unrenewed.
    |
    */

    'agreement_deadline_resend_days' => (int) env('AGREEMENT_DEADLINE_RESEND_DAYS', 7),

    /*
    |--------------------------------------------------------------------
    | License Deadline Alert Days
    |--------------------------------------------------------------------
    |
    | A company license is flagged as "Expiring Soon" — and included in
    | the deadline alert email — once its expiry date is within this many
    | days, giving the office enough lead time to renew it before it
    | lapses.
    |
    */

    'license_deadline_alert_days' => (int) env('LICENSE_DEADLINE_ALERT_DAYS', 30),

    /*
    |--------------------------------------------------------------------
    | License Deadline Resend Days
    |--------------------------------------------------------------------
    |
    | Once a license's deadline has been alerted on, it won't be alerted
    | on again until this many days have passed — avoids a daily email
    | for the same license while it's still sitting unrenewed.
    |
    */

    'license_deadline_resend_days' => (int) env('LICENSE_DEADLINE_RESEND_DAYS', 7),

];
