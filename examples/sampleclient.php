<?php

// load lib (run `composer install` first)
require __DIR__ . '/../vendor/autoload.php';

// create object and pass login credentials
$keycdn = new KeyCDN\KeyCDN('your_api_key');

// GET object zones
$result = $keycdn->get('zones.json');

// create zone
$result = $keycdn->post('zones.json', [
    'name' => 'testzone',
]);

// edit zone (the 1 specifies the zoneid, to be edited)
$result = $keycdn->put('zones/1.json', [
    'name' => 'newzonename',
]);

// delete zone (the 1 specifies the zoneid, to be deleted)
$result = $keycdn->delete('zones/1.json');
