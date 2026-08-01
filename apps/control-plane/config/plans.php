<?php

declare(strict_types=1);

// Billing v1 plan matrix. `null` limits mean unlimited; `white_label`
// controls the widget "Powered by mkEngage" badge (organizations.white_label
// is synced from here on plan changes — see PlanService::apply()).
return [
    'free' => [
        'label' => 'Free',
        'price' => '$0',
        'max_channels' => 2,
        'max_chatbots' => 3,
        'max_agents' => 3,
        'white_label' => false,
    ],
    'pro' => [
        'label' => 'Pro',
        'price' => '$29 / month',
        'max_channels' => 6,
        'max_chatbots' => 5,
        'max_agents' => 10,
        'white_label' => true,
    ],
    'business' => [
        'label' => 'Business',
        'price' => '$99 / month',
        'max_channels' => null,
        'max_chatbots' => null,
        'max_agents' => null,
        'white_label' => true,
    ],
];
