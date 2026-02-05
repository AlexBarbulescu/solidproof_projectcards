<?php

return [
    // v4 display card (2000x2000)
    'audit_v4' => [
        'title' => [
            'y' => 1130,
            'x' => 1000,
            'font' => 'Montserrat-ExtraBold.ttf',
            'size' => 140,
            'color' => '#ffffff',
            'align' => 'center',
            'valign' => 'middle',
        ],

        'date' => [
            'y' => 115,
            'x' => 1875,
            'font' => 'Montserrat-MediumItalic.ttf',
            'size' => 70,
            'color' => '#ffffff',
            'align' => 'right',
            'valign' => 'middle',
        ],

        'logo' => [
            'y' => -210, // from center
            'x' => 0, // from center
            'height' => 520,
            'width' => 900,
            'shape' => 'circle',
            'diameter' => 450,
            'upscale' => true,
            'trim' => true,
            'trim_alpha' => 126,
            'sharpen' => 14,
        ],

        'website' => [
            'y' => 1230,
            'x' => 1000,
            'size' => 44,
            'font' => 'Montserrat-Medium.ttf',
            'color' => '#ffffff',
            'align' => 'center',
            'valign' => 'middle',
        ],

        'badges' => [
            // Contract badge when we show 2-up (audit_standard)
            [
                'key' => 'contract',
                'position' => 'center',
                'x' => -250,
                'y' => 430,
                'height' => 170,
                'opacity' => 100,
            ],
            // Finalized badge (audit_standard)
            [
                'key' => 'final',
                'position' => 'center',
                'x' => 230,
                'y' => 430,
                'height' => 170,
                'opacity' => 100,
            ],
            // Single centered fallback when not audit_standard
            [
                'key' => 'contract_center',
                'position' => 'center',
                'x' => 0,
                'y' => 430,
                'height' => 170,
                'opacity' => 100,
            ],
        ],

        'copyright' => [
            'y' => 1910,
            'x' => 1000,
            'font' => 'Montserrat-Medium.ttf',
            'size' => 26,
            'color' => '#ffffff',
            'align' => 'center',
            'valign' => 'middle',
            'uppercase' => false,
        ],

        // Selection rules (config-only): pick background & badge asset based on project fields.
        // Intended inputs:
        // - project.audit_badge: e.g. https://.../audit_unknown.webp, audit_standard.webp, ...
        'background_select' => [
            'default' => 'certificates/Audit_blank_v4.png',
            'rules' => [
                [
                    'if' => [
                        'audit_badge_contains' => 'audit_unknown',
                    ],
                    'background' => 'certificates/Audit_blank_v4_unknown.png',
                ],
            ],
        ],

        'badge_select' => [
            'contract' => [
                'source' => 'project.audit_badge',
                'default' => null,
                'rules' => [
                    [
                        'if' => [
                            'audit_badge_contains' => 'audit_standard',
                        ],
                        'path' => 'img/badges/Contract_Audiated.png',
                    ],
                ],
            ],

            // Second badge: only for audit_standard
            'final' => [
                'source' => 'project.audit_badge',
                'default' => null,
                'rules' => [
                    [
                        'if' => [
                            'audit_badge_contains' => 'audit_standard',
                        ],
                        'path' => 'img/badges/Contract_Finalized.png',
                    ],
                ],
            ],

            // Fallback centered contract badge (anything except audit_standard)
            'contract_center' => [
                'source' => 'project.audit_badge',
                'default' => null,
                'rules' => [
                    [
                        'if' => [
                            'audit_badge_empty' => true,
                        ],
                        'path' => 'img/badges/Contract_Unknown.png',
                    ],
                    [
                        'if' => [
                            'audit_badge_contains' => 'audit_unknown',
                        ],
                        'path' => 'img/badges/Contract_Unknown.png',
                    ],
                    [
                        'if' => [
                            'audit_badge_contains' => 'audit_',
                            'audit_badge_not_contains' => 'audit_standard',
                        ],
                        'path' => 'img/badges/Contract_Audiated.png',
                    ],
                ],
            ],
        ],

        'background' => 'certificates/Audit_blank_v4.png',
    ],

    // v4 display card (2000x2000)
    'kyc_v4' => [
        'title' => [
            'y' => 1130,
            'x' => 1000,
            'font' => 'Montserrat-ExtraBold.ttf',
            'size' => 140,
            'color' => '#ffffff',
            'align' => 'center',
            'valign' => 'middle',
        ],

        'date' => [
            'y' => 115,
            'x' => 1875,
            'font' => 'Montserrat-MediumItalic.ttf',
            'size' => 70,
            'color' => '#ffffff',
            'align' => 'right',
            'valign' => 'middle',
        ],

        'logo' => [
            'y' => -210, // from center
            'x' => 0, // from center
            'height' => 520,
            'width' => 900,
            'shape' => 'circle',
            'diameter' => 450,
            'upscale' => true,
            'trim' => true,
            'trim_alpha' => 126,
            'sharpen' => 14,
        ],

        'website' => [
            'y' => 1230,
            'x' => 1000,
            'size' => 44,
            'font' => 'Montserrat-Medium.ttf',
            'color' => '#ffffff',
            'align' => 'center',
            'valign' => 'middle',
        ],

        'badges' => [
            // KYC tier badge (from projects.json: kyc_badge)
            [
                'key' => 'tier',
                'position' => 'center',
                'x' => -250,
                'y' => 430,
                'height' => 170,
                'opacity' => 100,
            ],
            // Partner/status badge when tier badge is present
            [
                'key' => 'status',
                'position' => 'center',
                'x' => 230,
                'y' => 430,
                'height' => 170,
                'opacity' => 100,
            ],
            // Partner/status badge when tier badge is missing (center it)
            [
                'key' => 'status_center',
                'position' => 'center',
                'x' => 0,
                'y' => 430,
                'height' => 170,
                'opacity' => 100,
            ],
        ],

        'copyright' => [
            'y' => 1910,
            'x' => 1000,
            'font' => 'Montserrat-Medium.ttf',
            'size' => 26,
            'color' => '#ffffff',
            'align' => 'center',
            'valign' => 'middle',
            'uppercase' => false,
        ],

        // Selection rules (config-only): pick background & badge assets based on project fields.
        // Intended inputs:
        // - project.kyc_badge: e.g. https://.../kyc_gold.webp, kyc_unknown.webp, ...
        // - partner haystack fields below (for GemPad / PinkSale)
        'background_select' => [
            'default' => 'certificates/KYC_blank_v4.png',
            'rules' => [
                [
                    'if' => [
                        'kyc_badge_contains' => 'kyc_gold',
                    ],
                    'background' => 'certificates/KYC_blank_v4_gold.png',
                ],
                [
                    'if' => [
                        'kyc_badge_contains' => 'kyc_unknown',
                    ],
                    'background' => 'certificates/KYC_blank_v4_unknown.png',
                ],
            ],
        ],

        'badge_select' => [
            'tier' => [
                'source' => 'project.kyc_badge',
                'default' => null,
                'rules' => [
                    [
                        'if' => [
                            'kyc_badge_contains' => 'kyc_gold',
                        ],
                        'path' => 'img/badges/KYC_Gold.png',
                    ],
                    [
                        'if' => [
                            'kyc_badge_contains' => 'kyc_silver',
                        ],
                        'path' => 'img/badges/KYC_Silver.png',
                    ],
                    [
                        'if' => [
                            'kyc_badge_contains' => 'kyc_bronze',
                        ],
                        'path' => 'img/badges/KYC_Bronze.png',
                    ],
                ],
            ],

            // Partner/status badge (right side when tier exists, centered when tier missing)
            'status' => [
                'source_fields' => [
                    'project.kyc_partner',
                    'project.kyc_platform',
                    'project.partner',
                    'project.website',
                    'project.url',
                    'project.name',
                    'project.slug',
                    'project.description',
                ],
                'default' => 'img/badges/KYC_Solidproof.png',
                'default_when_no_tier' => 'img/badges/KYC_Unknown.png',
                'rules' => [
                    [
                        'if' => [
                            'haystack_contains' => 'gempad',
                        ],
                        'path' => 'img/badges/KYC_Gempad.png',
                    ],
                    [
                        'if' => [
                            'haystack_contains' => 'pinksale',
                        ],
                        'path' => 'img/badges/KYC_Pinksale.png',
                    ],
                ],
            ],
        ],

        'background' => 'certificates/KYC_blank_v4.png',
    ],
];
