<?php

return [
    'admin_email' => env('ADMIN_EMAIL', 'admin@gmail.com'),
    'reasons' => [
        'wrong_name_at_signup'  => 'Wrong name entered during signup',
        'wrong_dob_at_signup'   => 'Wrong date of birth entered during signup',
        'both_wrong_at_signup'  => 'Both name and DOB entered incorrectly',
        'typo_mistake'          => 'Typo / spelling mistake',
        'legal_name_change'     => 'Legal name change (marriage, court, etc.)',
        'dob_data_entry_error'  => 'DOB data entry error',
        'other'                 => 'Other (specify in remarks)',
    ],

    'reason_fields' => [
        'wrong_name_at_signup' => ['full_name'],
        'wrong_dob_at_signup'  => ['date_of_birth'],
        'both_wrong_at_signup' => ['full_name', 'date_of_birth'],
    ],
];
