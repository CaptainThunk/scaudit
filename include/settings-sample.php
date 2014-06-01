<?php

$keyID = 123456;
$vCode = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
$characterID = 789012345;

$static_dump = 'static_dump/sqlite-latest.sqlite';

// An array of ship typeIDs that we're interested in auditing.
$super_caps = [ 
    3514,    // Revenant
    3628,    // Nation
    22852,   // Hel
    23913,   // Nyx
    23917,   // Wyvern
    23919,   // Aeon
    671,     // Erebus
    3764,    // Leviathan
    11567,   // Avatar
    23773    // Ragnarok
];

$scap_items = [];
$scap_type;

$results['fail'] = [];
$results['pass'] = [];

?>
