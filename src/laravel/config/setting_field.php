<?php

use SteelAnts\LaravelBoilerplate\Types\SettingDataType;

return [
    'main' => [ // Slug
        'general' => [ // Section 1
            'default' => [
                'type'  => SettingDataType::INT,
                'rules' => 'required',
                'help'  => 'Help',
            ],
            'security_ack_days' => [
                'type'  => SettingDataType::INT,
                'rules' => 'nullable|integer|min:0|max:3650',
                'help'  => 'Security: days after which an acknowledged finding is open again (0 or empty: never).',
            ],
            'security_stale_days' => [
                'type'  => SettingDataType::INT,
                'rules' => 'nullable|integer|min:0|max:3650',
                'help'  => 'Security: days without a report after which the findings of a device are stale (default 14, 0: never).',
            ],
        ],
    ],
];
