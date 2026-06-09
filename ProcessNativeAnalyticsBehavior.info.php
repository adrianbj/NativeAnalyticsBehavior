<?php namespace ProcessWire;

$info = array(
    'title' => 'NativeAnalyticsBehavior Dashboard',
    'summary' => 'Dashboard for NativeAnalyticsBehavior (heatmaps).',
    'version' => 1,
    'author' => 'Adrian Jones',
    'permission' => 'nativeanalyticsbehavior-view',
    'permissions' => array('nativeanalyticsbehavior-view' => 'View NativeAnalyticsBehavior dashboard'),
    'icon' => 'fire',
    'requires' => array('NativeAnalyticsBehavior'),
    'page' => array(
        'name' => 'behavior-analytics',
        'parent' => 'setup',
        'title' => 'Behavior',
    ),
);
