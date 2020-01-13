<?php
// Autoload libraries
require_once  'vendor/autoload.php';

// Load Core class
include_once(__DIR__ . "/Core7.php"); //
$core = new Core7();

// Load DataStoreClient to optimize calls
use Google\Cloud\Datastore\DatastoreClient;
$datastore = new DatastoreClient(['transport'=>'grpc']);

// Run Dispatch
$core->dispatch();

// Apply performance parameter
//region performance ?__p parameter
if (isset($_GET['__p'])) {
    _print($core->__p->data['info']);

    if ($core->errors->lines)
        _print($core->errors->data);

    if ($core->logs->lines)
        _print($core->logs->data);
}
//endregion
