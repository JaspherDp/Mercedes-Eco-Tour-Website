<?php
/**
 * Unified Search Configuration
 * Defines all search tab configurations and their form structures
 */

$SEARCH_TABS = [
    'hotels' => [
        'id' => 'hotels',
        'label' => 'Hotels/Resorts',
        'icon' => 'building-2',
        'active' => true,
        'fields' => [
            'destination' => [
                'type' => 'location',
                'label' => 'Destination',
                'placeholder' => 'Select a location',
                'required' => true,
                'icon' => 'map-pin'
            ],
            'dates' => [
                'type' => 'daterange',
                'label' => 'Check-in / Check-out',
                'required' => true,
                'icon' => 'calendar',
                'subfields' => [
                    'checkin' => ['label' => 'Check-in'],
                    'checkout' => ['label' => 'Check-out']
                ]
            ],
            'occupancy' => [
                'type' => 'counter',
                'label' => 'Pax & Room Selection',
                'required' => true,
                'icon' => 'users',
                'options' => [
                    'pax' => ['label' => 'Pax', 'default' => 1, 'min' => 1, 'max' => 30],
                    'rooms' => ['label' => 'Rooms', 'default' => 1, 'min' => 1, 'max' => 10]
                ]
            ]
        ],
        'result_page' => 'search_results.php',
        'result_type' => 'hotels'
    ],
    'tours' => [
        'id' => 'tours',
        'label' => 'Tour Packages',
        'icon' => 'ticket',
        'active' => false,
        'fields' => [
            'destination' => [
                'type' => 'location',
                'label' => 'Destination',
                'placeholder' => 'Select a location',
                'required' => true,
                'icon' => 'map-pin'
            ],
            'date' => [
                'type' => 'date',
                'label' => 'Date',
                'required' => true,
                'icon' => 'calendar'
            ],
            'travellers' => [
                'type' => 'counter',
                'label' => 'Pax',
                'required' => true,
                'icon' => 'users',
                'options' => [
                    'pax' => ['label' => 'Pax', 'default' => 1, 'min' => 1, 'max' => 50]
                ]
            ]
        ],
        'result_page' => 'search_results.php',
        'result_type' => 'tours'
    ],
    'guides' => [
        'id' => 'guides',
        'label' => 'Tour Guides',
        'icon' => 'user',
        'active' => false,
        'fields' => [
            'destination' => [
                'type' => 'location',
                'label' => 'Destination',
                'placeholder' => 'Select a location',
                'required' => true,
                'icon' => 'map-pin'
            ],
            'date' => [
                'type' => 'date',
                'label' => 'Date',
                'required' => true,
                'icon' => 'calendar'
            ],
            'travellers' => [
                'type' => 'counter',
                'label' => 'Pax',
                'required' => true,
                'icon' => 'users',
                'options' => [
                    'pax' => ['label' => 'Pax', 'default' => 1, 'min' => 1, 'max' => 50]
                ]
            ]
        ],
        'result_page' => 'search_results.php',
        'result_type' => 'guides'
    ],
    'boats' => [
        'id' => 'boats',
        'label' => 'Tour Boats',
        'icon' => 'boat',
        'active' => false,
        'fields' => [
            'destination' => [
                'type' => 'location',
                'label' => 'Destination',
                'placeholder' => 'Select a location',
                'required' => true,
                'icon' => 'map-pin'
            ],
            'date' => [
                'type' => 'date',
                'label' => 'Date',
                'required' => true,
                'icon' => 'calendar'
            ],
            'travellers' => [
                'type' => 'counter',
                'label' => 'Pax',
                'required' => true,
                'icon' => 'users',
                'options' => [
                    'pax' => ['label' => 'Pax', 'default' => 1, 'min' => 1, 'max' => 50]
                ]
            ]
        ],
        'result_page' => 'search_results.php',
        'result_type' => 'boats'
    ],
    'bundle' => [
        'id' => 'bundle',
        'label' => 'Tour Guide + Boat',
        'icon' => 'package',
        'active' => false,
        'fields' => [
            'destination' => [
                'type' => 'location',
                'label' => 'Destination',
                'placeholder' => 'Select a location',
                'required' => true,
                'icon' => 'map-pin'
            ],
            'date' => [
                'type' => 'date',
                'label' => 'Date',
                'required' => true,
                'icon' => 'calendar'
            ],
            'travellers' => [
                'type' => 'counter',
                'label' => 'Pax',
                'required' => true,
                'icon' => 'users',
                'options' => [
                    'pax' => ['label' => 'Pax', 'default' => 1, 'min' => 1, 'max' => 50]
                ]
            ]
        ],
        'result_page' => 'search_results.php',
        'result_type' => 'bundle'
    ]
];

// Popular categories for homepage carousel sections
$POPULAR_SECTIONS = [
    'tour_packages' => [
        'title' => 'Popular Packages',
        'type' => 'tours',
        'query' => "SELECT * FROM tour_packages LIMIT 10",
        'card_type' => 'tour'
    ],
    'tour_guides' => [
        'title' => 'Popular Tour Guides',
        'type' => 'guides',
        'query' => "SELECT * FROM tour_guides LIMIT 10",
        'card_type' => 'guide'
    ],
    'boats' => [
        'title' => 'Popular Boats',
        'type' => 'boats',
        'query' => "SELECT * FROM boats LIMIT 10",
        'card_type' => 'boat'
    ],
    'hotels' => [
        'title' => 'Popular Hotels',
        'type' => 'hotels',
        'query' => "SELECT h.* FROM hotel_resort h ORDER BY h.rating DESC LIMIT 10",
        'card_type' => 'hotel'
    ]
];
