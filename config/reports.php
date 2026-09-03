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

];
