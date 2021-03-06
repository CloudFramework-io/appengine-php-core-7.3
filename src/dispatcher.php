<?php
$_root_path = (strlen($_SERVER['DOCUMENT_ROOT'])) ? $_SERVER['DOCUMENT_ROOT'] : $_SERVER['PWD'];
// Autoload libraries
require_once  $_root_path.'/vendor/autoload.php';

// Load Core class
include_once(__DIR__ . "/Core7.php"); //
$core = new Core7();

// Load DataStoreClient to optimize calls
use Google\Cloud\Datastore\DatastoreClient;
$datastore = null;
if((getenv('PROJECT_ID') || $core->config->get("core.gcp.datastore.project_id")) && $core->config->get('core.datastore.on')) {

    //2021-02-25: Fix to force rest transport instead of grpc because it crash for certain content.
    if(isset($_GET['_fix_datastore_transport'])) $core->config->set('core.datastore.transport','rest');

    // grpc or rest
    if($core->is->development()) {
        $transport = ($core->config->get('core.datastore.transport'))?:'rest';
        $datastore = new DatastoreClient(['transport'=>$transport,'projectId'=>($core->config->get("core.gcp.datastore.project_id"))?:getenv('PROJECT_ID')]);
    } else {
        $transport = ($core->config->get('core.datastore.transport'))?:'grpc';
        $datastore = new DatastoreClient(['transport'=>$transport,'projectId'=>($core->config->get("core.gcp.datastore.project_id"))?:getenv('PROJECT_ID')]);
    }
}

// https://cloud.google.com/logging/docs/setup/php
use Google\Cloud\Logging\LoggingClient;
$logger = null;
if(getenv('PROJECT_ID') && $core->is->production()) {
    $logger = LoggingClient::psrBatchLogger('app');
}

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
