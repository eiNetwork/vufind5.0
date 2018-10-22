<?php
return [
    'extends' => 'bootprint3',
    'helpers' => [
        'factories' => [
            'VuFind\View\Helper\Truefit\Record' => 'VuFind\View\Helper\Root\RecordFactory'
        ],
        'aliases' => [
            'record' => 'VuFind\View\Helper\Truefit\Record',
        ]
    ],
    'css' => [
        'vendor/font-awesome.min.css',
        'vendor/bootstrap-slider.min.css'
    ]/*,
    'js' => [
        'vendor/typeahead.js',
    ]*/
];
