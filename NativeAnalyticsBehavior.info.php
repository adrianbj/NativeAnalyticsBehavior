<?php namespace ProcessWire;

$info = array(
    'title' => 'NativeAnalyticsBehavior',
    'summary' => 'Behavioral analytics companion for NativeAnalytics: heatmaps, insights and session recordings.',
    'version' => 1,
    'author' => 'Adrian Jones',
    'icon' => 'fire',
    'autoload' => true,
    'singular' => true,
    'requires' => array('ProcessWire>=3.0.173', 'PHP>=7.4', 'NativeAnalytics', 'LazyCron'),
);
