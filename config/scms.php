<?php

return [
    // Income account of admission fees taken at member registration (code in the chart of accounts).
    'admission_fee_account' => env('SCMS_ADMISSION_FEE_ACCOUNT', '4500'),

    // Fee suggested on the registration form (null = none).
    'admission_fee' => env('SCMS_ADMISSION_FEE'),
];
