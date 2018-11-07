<?php
return [
    'extends' => 'bootprint3',
    'helpers' => [
        'factories' => [
            'VuFind\View\Helper\Truefit\Record' => 'VuFind\View\Helper\Root\RecordFactory',
            'VuFind\View\Helper\Truefit\RecordLink' => 'VuFind\View\Helper\Root\RecordLinkFactory'
        ],
        'aliases' => [
            'record' => 'VuFind\View\Helper\Truefit\Record',
            'recordLink' => 'VuFind\View\Helper\Truefit\RecordLink'
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
