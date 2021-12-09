<?php

/**
 * @author Héctor López <hlopez@cloudframework.io>
 * @version 2020
 */


use Google\Cloud\Logging\Logger;
use Google\Cloud\Logging\PsrLogger;
use Google\Cloud\Storage\StorageClient;



if (!defined("_CLOUDFRAMEWORK_CORE_CLASSES_")) {
    define("_CLOUDFRAMEWORK_CORE_CLASSES_", TRUE);

    //region debug function
    /**
     * Echo in output a group of vars passed as args
     * @param mixed $args Element to print.
     */
    function __print($args)
    {
        if (key_exists('PWD', $_SERVER)) echo "\n";
        else echo "<pre>";
        for ($i = 0, $tr = count($args); $i < $tr; $i++) {
            if ($args[$i] === "exit")
                exit;
            if (key_exists('PWD', $_SERVER)) echo "\n[$i]: ";
            else echo "\n<li>[$i]: ";

            if (is_array($args[$i]))
                echo print_r($args[$i], TRUE);
            else if (is_object($args[$i]))
                echo var_dump($args[$i]);
            else if (is_bool($args[$i]))
                echo ($args[$i]) ? 'true' : 'false';
            else if (is_null($args[$i]))
                echo 'NULL';
            else
                echo $args[$i];
            if (key_exists('PWD', $_SERVER)) echo "\n";
            else echo "</li>";
        }
        if (key_exists('PWD', $_SERVER)) echo "\n";
        else echo "</pre>";
    }

    /**
     * @throws Exception
     */
    function __fatal_handler() {
        global $core;
        $errfile = "unknown file";
        $errstr  = "shutdown";
        $errno   = E_CORE_ERROR;
        $errline = 0;

        $error = error_get_last();

        // avoid E_USER_DEPRECATED errors
        if( $error !== NULL && $error["type"]!=E_USER_DEPRECATED) {
            $errno   = $error["type"];
            $errfile = $error["file"];
            $errline = $error["line"];
            $errstr  = $error["message"];

            //Catch gzuncompress() in Core7 file for cache
            if ($errno == E_WARNING && $errstr == 'gzuncompress(): data error' && strpos($errfile,'Core7.php')) {
                $core->logs->add('gzuncompress() failed. Potential wrong credentials in CoreCache->get() or wrong content in CoreSession->get()','gzumcompress','warning');
                return;
            }

            if($core)
                $core->errors->add(["ErrorCode"=>$errno, "ErrorMessage"=>$errstr, "File"=>$errfile, "Line"=>$errline],'fatal_error','error');

            if(!$core || ($core->is->development() && !$core->is->terminal()))
                _print( ["ErrorCode"=>$errno, "ErrorMessage"=>$errstr, "File"=>$errfile, "Line"=>$errline]);
        }
    }

    register_shutdown_function( "__fatal_handler" );

    /**
     * Print a group of mixed vars passed as arguments
     */
    function _print()
    {
        __print(func_get_args());
    }

    /**
     * _print() with an exit
     */
    function _printe()
    {
        __print(array_merge(func_get_args(), array('exit')));
    }
    //endregion

    /**
     * Core Class to build cloudframework applications
     * @package Core
     */
    final class Core7
    {
        // Version of the Core7 CloudFrameWork
        var $_version = 'v73.24091';
        /** @var CorePerformance $__p */
        var  $__p;
        /** @var CoreIs $is */
        var  $is;
        /** @var CoreSession $session */
        var  $session;
        /** @var CoreSystem $system */
        var  $system;
        /** @var CoreLog $logs */
        var  $logs;
        /** @var CoreLog $errors */
        var  $errors;
        /** @var CoreConfig $config */
        var $config;
        /** @var CoreSecurity $security */
        var $security;
        /** @var CoreCache $cache */
        var $cache;
        /** @var CoreRequest $request */
        var $request;
        /** @var CoreLocalization $localization */
        var $localization;
        /** @var CoreModel $model */
        var $model;
        /** @var CFILog $cfiLog */
        var $cfiLog;
        /** @var string $gc_project_id GCP Google Project assciated */
        var $gc_project_id;
        /**
         * @var array $loadedClasses control the classes loaded
         * @link Core::loadClass()
         */
        private $loadedClasses = [];
        /** @var StorageClient $gc_datastorage_client  */
        var $gc_datastorage_client = null;          // Google Cloud DataStorage Client

        /**
         * Core constructor.
         * @param string $root_path
         */
        function __construct($root_path = '')
        {
            //region SET $this->__p,$this->is,$this->system,$this->logs,$this->errors
            $this->__p = new CorePerformance();
            $this->is = new CoreIs();
            $this->system = new CoreSystem($root_path);
            $this->logs = new CoreLog($this->is->development() && $this->is->terminal());
            $this->errors = new CoreLog($this->is->development() && $this->is->terminal());
            //endregion

            //region SET $this->config and evaluate to read local_config.json and local_script.json
            $this->config = new CoreConfig($this, __DIR__ . '/config.json');
            if ($this->is->development() && is_file($this->system->root_path . '/local_config.json')) {
                $this->config->readConfigJSONFile($this->system->root_path . '/local_config.json');
                $this->logs->add('[development] loaded local_config.json');
            }
            if ($this->is->development() && $this->is->terminal() && is_file($this->system->root_path . '/local_script.json')) {
                $this->config->readConfigJSONFile($this->system->root_path . '/local_script.json');
                $this->logs->add('[script] loaded local_script.json');
            }
            //endregion
            //region SET $this->session and evaluate if we are runnning from a termina
            $this->session = new CoreSession($this);
            // To run scripts you can use Session data to store temporal Data. The session expire every 180 minutes by default
            if($this->is->development() && $this->is->terminal()) {
                $this->session->debug = true;
                $this->session->init('CloudFrameworkScripts');
            }
            //endregion
            $this->security = new CoreSecurity($this);
            $this->cache = new CoreCache($this);
            $this->request = new CoreRequest($this);
            $this->localization = new CoreLocalization($this);
            $this->model = new CoreModel($this);
            $this->cfiLog = new CFILog($this);
            //setup GCP basic vars
            if($this->config->get('core.gcp.project_id')) putenv('PROJECT_ID='.$this->config->get('core.gcp.project_id'));
            $this->gc_project_id = getenv('PROJECT_ID');
            $this->gc_project_service = ($this->config->get('core.gcp.project_service'))?$this->config->get('core.gcp.project_service'):'default';

            // DATASTORE_DATASET
            if($this->gc_project_id && !getenv('DATASTORE_DATASET'))  putenv('DATASTORE_DATASET='.$this->gc_project_id);

            //evaluate development DATASTORE fix
            if($DATASTORE_EMULATOR_HOST = getenv('DATASTORE_EMULATOR_HOST')) {
                if(strpos($DATASTORE_EMULATOR_HOST,'::1')===0) {
                    $DATASTORE_EMULATOR_HOST = str_replace('::1','localhost',$DATASTORE_EMULATOR_HOST);
                    putenv("DATASTORE_EMULATOR_HOST={$DATASTORE_EMULATOR_HOST}");
                }
            }

            // Local configuration
            $this->__p->add('Loaded $this->__p,$this->system,$this->logs,$this->errors,$this->is,$this->config,$this->session,$this->security,$this->cache,$this->request,$this->localization,$this->model,$this->cfiLog with __session[started=' . (($this->session->start) ? 'true' : 'false') . ']: ,', __METHOD__);

            // Config objects based in config
            $this->cache->setSpaceName($this->config->get('cacheSpacename'));

            // If the $this->system->app_path ends in / delete the char.
            $this->system->app_path = preg_replace('/\/$/','',$this->system->app_path);

            // region EVALUATE env variables:
            if($this->config->get("core.gcp.project_id")) putenv('PROJECT_ID='.$this->config->get("core.gcp.project_id"));
            if($this->config->get("core.gcp.credentials")) putenv('GOOGLE_APPLICATION_CREDENTIALS='.$this->config->get("core.gcp.credentials"));

            // Support of DataStorage to work with Buckets
            $this->initDataStorage();

        }

        /**
         * Router
         */
        function dispatch()
        {

            // core.dispatch.headers: Evaluate to add headers in the response.
            if($headers = $this->config->get('core.dispatch.headers')) {
                if(is_array($headers)) foreach ($headers as $key=>$value) {
                    header("$key: $value");
                }
            }

            // API end points. By default $this->config->get('core_api_url') is '/'
            if ($this->isApiPath()) {

                // Extract $apifile route
                $apifile = $this->system->url['parts'][$this->system->url['parts_base_index']];
                // If empty by default it will be index
                if(!$apifile) {
                    $apifile='index';
                }

                // if $apifile starts with '_' character or start with queue the look into the framework
                if ($apifile[0] == '_' || $apifile == 'queue') {
                    $pathfile = __DIR__ . "/api/{$apifile}.php";
                    if (!file_exists($pathfile)) $pathfile = '';
                }
                // else look into user api structure.
                else {

                    // $apifile is a directory and there are more than one parameter
                    // then $apifile will be the firts_parameter/sencond_parameter
                    if(isset($this->system->url['parts'][$this->system->url['parts_base_index']+1])
                        && is_dir($this->system->app_path . "/api/{$apifile}")
                        && !file_exists($this->system->app_path . "/api/{$apifile}.php")
                    ) {
                        $apifile = $this->system->url['parts'][$this->system->url['parts_base_index']].'/'.$this->system->url['parts'][$this->system->url['parts_base_index']+1];
                    }

                    // $pathfile is the path where the php file has to be created
                    $pathfile = $this->system->app_path . "/api/{$apifile}.php";

                    if (!file_exists($pathfile)) {
                        $pathfile = '';
                        if (strlen($this->config->get('core.api.extra_path')))
                            $pathfile = $this->config->get('core.api.extra_path') . "/{$apifile}.php";
                    }
                }


                // IF NOT EXIST
                include_once __DIR__ . '/class/RESTful.php';

                try {
                    // Include the external file $pathfile
                    if (strlen($pathfile)) {
                        // init data storage client wrapper if filepath starts with gs://
                        $this->__p->add('Loaded $pathfile', __METHOD__,'note');
                        @include_once $pathfile;
                        $this->__p->add('Loaded $pathfile', __METHOD__,'endnote');

                    }

                    // By default the ClassName will be called API.. if the include set $api_class var, we will use that class name
                    if(!isset($api_class)) $api_class = 'API';

                    if (class_exists($api_class)) {
                        /** @var RESTful $api */
                        $api = new $api_class($this,$this->system->url['parts_base_url']);
                        if (array_key_exists(0,$api->params) && $api->params[0] == '__codes') {
                            $__codes = $api->codeLib;
                            foreach ($__codes as $key => $value) {
                                $__codes[$key] = $api->codeLibError[$key] . ', ' . $value;
                            }
                            $api->addReturnData($__codes);
                        } else {
                            $api->main();
                        }

                        $api->send();

                        die('eee');


                    } else {
                        $api = new RESTful($this);
                        if(is_file($pathfile)) {
                            $api->setError("the code in '{$apifile}' does not include a {$api_class} class extended from RESTFul. Use: <?php class API extends RESTful { ... your code ... } ", 404);
                        } else {
                            $api->setError("the file for '{$apifile}' does not exist in api directory: ".$pathfile, 404);

                        }
                        $api->send();
                    }
                } catch (Exception $e) {
                    // ERROR CONTROL WHERE $api is not an object
                    if(!is_object($api)) {
                        $api = new RESTful($this);
                        if(is_file($pathfile)) {
                            $api->setError("the code in '{$apifile}' does not include a {$api_class} class extended from RESTFul. Use: <?php class API extends RESTful { ... your code ... } ", 404);
                        } else {
                            $api->setError("the file for '{$apifile}' does not exist in api directory: ".$pathfile, 404);
                        }
                    }
                    // If $api is an object then an exception has been captured
                    else {
                        $api->setError("the code in '{$apifile}' has produced an exception ", 503);

                    }

                    $this->errors->add(error_get_last());
                    $this->errors->add($e->getMessage());
                    $api->send();
                }
                $this->__p->add("API including RESTfull.php and {$apifile}.php: ", 'There are ERRORS');
                return false;
            } // Take a LOOK in the menu
            elseif ($this->config->inMenuPath()) {

                // Common logic
                if (!empty($this->config->get('commonLogic'))) {
                    try {
                        include_once $this->system->app_path . '/logic/' . $this->config->get('commonLogic');
                        if (class_exists('CommonLogic')) {
                            $commonLogic = new CommonLogic($this);
                            $commonLogic->main();
                            $this->__p->add("Executed CommonLogic->main()", "/logic/{$this->config->get('commonLogic')}");

                        } else {
                            die($this->config->get('commonLogic').' does not include CommonLogic class');
                        }
                    } catch (Exception $e) {
                        $this->errors->add(error_get_last());
                        $this->errors->add($e->getMessage());
                        _print($this->errors->data);
                    }
                }

                // Specific logic
                if (!empty($this->config->get('logic'))) {
                    try {
                        include_once $this->system->app_path . '/logic/' . $this->config->get('logic');
                        if (class_exists('Logic')) {
                            $logic = new Logic($this);
                            $logic->main();
                            $this->__p->add("Executed Logic->main()", "/logic/{$this->config->get('logic')}");

                        } else {
                            $logic = new CoreLogic($this);
                            $logic->addError("api {$this->config->get('logic')} does not include a Logic class extended from CoreLogic with method ->main()", 404);
                        }

                    } catch (Exception $e) {
                        $this->errors->add(error_get_last());
                        $this->errors->add($e->getMessage());
                    }
                } else {
                    $logic = new CoreLogic($this);
                }
                // Templates
                if (!empty($this->config->get('template'))) {
                    $logic->render($this->config->get('template'));
                }
                // No template assigned.
                else {
                    // If there is no logic and no template, then ERROR
                    if(empty($this->config->get('logic'))) {
                        $this->errors->add('No logic neither template assigned');
                        _print($this->errors->data);
                    }
                }
            }
        }

        /**
         * Assign the path to Root of the app
         * @param $dir
         */
        function setAppPath($dir)
        {
            if (is_dir($this->system->root_path . $dir)) {
                $this->system->app_path = $this->system->root_path . $dir;
                $this->system->app_url = $dir;
            } else {
                $this->errors->add($this->system->root_path . $dir . " doesn't exist. ".$this->system->root_path . $dir);
            }
        }

        /**
         * Is the current route part of the API?
         * @return bool
         */
        private function isApiPath() {
            if(!$this->config->get('core.api.urls')) return false;
            $paths = $this->config->get('core.api.urls');
            if(!is_array($paths)) $paths = explode(',',$this->config->get('core.api.urls'));

            foreach ($paths as $path) {
                if(strpos($this->system->url['url'], $path) === 0) {
                    $path = preg_replace('/\/$/','',$path);
                    $this->system->url['parts_base_index'] = count(explode('/',$path))-1;
                    $this->system->url['parts_base_url'] = $path;
                    return true;
                }
            }
            return false;
        }

        /**
         * Return an object of the Class $class. If this object has been previously called class
         * @param $class
         * @param null $params
         * @return mixed|null
         */
        function loadClass($class, $params = null)
        {

            $hash = hash('md5', $class . json_encode($params));
            if (key_exists($hash, $this->loadedClasses)) return $this->loadedClasses[$hash];

            if (is_file(__DIR__ . "/class/{$class}.php"))
                include_once(__DIR__ . "/class/{$class}.php");
            elseif (is_file($this->system->app_path . "/class/" . $class . ".php"))
                include_once($this->system->app_path . "/class/" . $class . ".php");
            else {
                $this->errors->add("Class $class not found");
                return null;
            }
            $this->loadedClasses[$hash] = new $class($this, $params);
            return $this->loadedClasses[$hash];

        }

        /**
         * Init gc_datastorage_client and registerStreamWrapper
         */
        protected function initDataStorage() {

            //avoid to create several times
            if(is_object($this->gc_datastorage_client)) return;

            // if $this->config->get('core.datastorage.on') but !$this->gc_project_id then error
            if(!$this->gc_project_id && $this->config->get('core.datastorage.on')) {
                echo('Missing PROJECT_ID ENVIRONMENT VARIABLE TO REGISTER STREAM WRAPPER'."\n");
                if($this->is->terminal()) {
                    echo('export PROJECT_ID={YOUR-PROJECT-ID}'."\n");
                    exit;
                } else if($this->is->development()) {
                    echo('export PROJECT_ID={YOUR-PROJECT-ID}'."\n");
                    exit;
                }else  {
                    echo('add in app.yaml'."\nenv_variables:\n   PROJECT_ID: \"{YOUR-PROJECT-ID}\"");
                    exit;
                }
                $this->logs->add('Missing PROJECT_ID ENVIRONMENT VARIABLE TO REGISTER STREAM WRAPPER');
                $this->logs->add('export PROJECT_ID={YOUR-PROJECT-ID}');
                return;
            }

            // only setup datastorage if gc_project_id
            if($this->gc_project_id) {
                $this->gc_datastorage_client = new StorageClient(['projectId' => $this->gc_project_id]);
                $this->gc_datastorage_client->registerStreamWrapper();
            }
        }

        /**
         * json_encode an $input with JSON_UNESCAPED_UNICODE options by default. if any error iadd it
         * @param $input
         * @param null $options
         * @return false|string
         */
        public function jsonEncode($input, $options=null)
        {
            if($options) $json = json_encode($input, JSON_UNESCAPED_UNICODE | $options);
            else $json = json_encode($input, JSON_UNESCAPED_UNICODE );

            if (function_exists('json_last_error') && $errno = json_last_error()) {
                $this->errors->add(['json_encode error',$errno],'jsonEncode');
            } elseif ($json === 'null' && $input !== null) {
                $this->errors->add('Null result with non-null input','jsonEncode');
            }
            return $json;
        }

        /**
         * json_decode a
         * @param $input
         * @return mixed
         */
        public function jsonDecode($input)
        {
            if (version_compare(PHP_VERSION, '5.4.0', '>=') && !(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) {
                /** In PHP >=5.4.0, json_decode() accepts an options parameter, that allows you
                 * to specify that large ints (like Steam Transaction IDs) should be treated as
                 * strings, rather than the PHP default behaviour of converting them to floats.
                 */
                $arr = json_decode($input, true, 512, JSON_BIGINT_AS_STRING);
            } else {
                /** Not all servers will support that, however, so for older versions we must
                 * manually detect large ints in the JSON string and quote them (thus converting
                 *them to strings) before decoding, hence the preg_replace() call.
                 */
                $max_int_length = strlen((string) PHP_INT_MAX) - 1;
                $json_without_bigints = preg_replace('/:\s*(-?\d{'.$max_int_length.',})/', ': "$1"', $input);
                $arr = json_decode($json_without_bigints,true);
            }

            if (function_exists('json_last_error') && $errno = json_last_error()) {
                $this->errors->add(['json_encode error',$errno],'jsonDecode');
            } elseif ($arr === null && $input !== 'null') {
                $this->errors->add('Null result with non-null input','jsonDecode');
            }
            return $arr;
        }
    }

    /**
     * Class to track performance
     * @package Core
     */
    class CorePerformance
    {
        var $data = [];
        var $deep = 0;
        var $spaces = "";
        var $lastnote = "";
        var $active = true; // turn to false to deactivate Performance lines to save Memory

        function __construct()
        {
            // Performance Vars
            $this->data['initMicrotime'] = microtime(true);
            $this->data['lastMicrotime'] = $this->data['initMicrotime'];
            $this->data['initMemory'] = memory_get_usage() / (1024 * 1024);
            $this->data['lastMemory'] = $this->data['initMemory'];
            $this->data['lastIndex'] = 1;
            $this->data['info'][] = 'File: ' . str_replace($_SERVER['DOCUMENT_ROOT'], '', __FILE__);
            $this->data['info'][] = 'Init Memory Usage: ' . number_format(round($this->data['initMemory'], 4), 4) . 'Mb';

        }

        function add($title, $file = '', $type = 'all')
        {
            if(!$this->active) return;
            // Hidding full path (security)
            $file = str_replace($_SERVER['DOCUMENT_ROOT'], '', $file);


            if ($type == 'note') {
                for($this->spaces  = "",$i=0;$i<($this->deep);$i++) $this->spaces.="  ";
                $this->deep++;
                $line = "{$this->spaces}[$type";
                $this->lastnote = 'note';
                $this->data['lastMicrotime_'.$this->deep] = microtime(true);
            }
            else $line = $this->data['lastIndex'] . ' [';

            if (strlen($file)) $file = " ($file)";

            $_time = microtime(TRUE) - $this->data['lastMicrotime'];
            if ($type == 'all' || $type == 'endnote' || $type == 'time' || (isset($_GET['data']) && $_GET['data'] == $this->data['lastIndex'])) {
                if(isset($this->data['lastMicrotime_'.$this->deep]))
                    $_time = microtime(TRUE) - $this->data['lastMicrotime_'.$this->deep];

                $line .=  (round($_time, 4)) . ' secs';
                $this->data['lastMicrotime'] = microtime(TRUE);
            }

            $_mem = memory_get_usage() / (1024 * 1024) - $this->data['lastMemory'];
            if ($type == 'all' || $type == 'endnote' || $type == 'memory' || (isset($_GET['data']) && $_GET['data'] == $this->data['lastIndex'])) {
                $line .= (($line == '[') ? '' : ', ') . number_format(round($_mem, 3), 3) . ' Mb';
                $this->data['lastMemory'] = memory_get_usage() / (1024 * 1024);
            }
            $line .= '] ' . $title;

            $line = (($type != 'note') ? '['
                    . (round(microtime(TRUE) - $this->data['initMicrotime'], 4)) . ' secs, '
                    . number_format(round(memory_get_usage() / (1024 * 1024), 3), 3) . ' Mb] / '
                    : '') . $line . $file;

            if ($type == 'endnote') {
                if($this->lastnote=='note') $line = "{$this->spaces}[$type] " . $line;
                $this->deep--;
                for($this->spaces = "",$i=0;$i<($this->deep);$i++) $this->spaces.="  ";
                if($this->lastnote=='endnote') $line = "{$this->spaces}[$type] " . $line;
                $this->lastnote = 'endnote';
            }

            $this->data['info'][] = $line;

            if ($title) {
                if (!isset($this->data['titles'][$title])) $this->data['titles'][$title] = ['mem' => '', 'time' => 0, 'lastIndex' => ''];
                $this->data['titles'][$title]['mem'] = $_mem;
                $this->data['titles'][$title]['time'] += $_time;
                $this->data['titles'][$title]['lastIndex'] = $this->data['lastIndex'];

            }

            if (isset($_GET['__p']) && $_GET['__p'] == $this->data['lastIndex']) {
                _printe($this->data);
                exit;
            }

            $this->data['lastIndex']++;

        }

        function getTotalTime($prec = 3)
        {
            return (round(microtime(TRUE) - $this->data['initMicrotime'], $prec));
        }

        function getTotalMemory($prec = 3)
        {
            return number_format(round(memory_get_usage() / (1024 * 1024), $prec), $prec);
        }

        function init($spacename, $key)
        {
            if(!$this->active) return;
            $this->data['init'][$spacename][$key]['mem'] = memory_get_usage();
            $this->data['init'][$spacename][$key]['time'] = microtime(TRUE);
            $this->data['init'][$spacename][$key]['ok'] = TRUE;
        }

        function end($spacename, $key, $ok = TRUE, $msg = FALSE)
        {
            if(!$this->active) return;
            // Verify indexes
            if(!isset($this->data['init'][$spacename][$key])) {
                $this->data['init'][$spacename][$key] = [];
            }

            $this->data['init'][$spacename][$key]['mem'] = round((memory_get_usage() - $this->data['init'][$spacename][$key]['mem']) / (1024 * 1024), 3) . ' Mb';
            $this->data['init'][$spacename][$key]['time'] = round(microtime(TRUE) - $this->data['init'][$spacename][$key]['time'], 3) . ' secs';
            $this->data['init'][$spacename][$key]['ok'] = $ok;
            if ($msg !== FALSE) $this->data['init'][$spacename][$key]['notes'] = $msg;
        }
    }

    /**
     * Class to manage session
     * @package Core
     */
    class CoreSession
    {
        /** @var bool $start says if the session has been started */
        var $start = false;
        /** @var string $id Id of the session */
        var $id = '';
        /** @var bool $debug if true the class will send to $core->logs the use of the methods*/
        var $debug = false;
        /** @var Core7 $core */
        var $core;

        /**
         * CoreSession constructor
         * @param Core7 $core Core7 class passed by reference
         * @param null $debug if true it will send to $core->logs the use of the methods
         */
        function __construct(Core7 &$core,$debug=null)
        {
            $this->core = $core;
            //region SET $this->>debug. Activate debug based on $debug or if I am in development (local environgmnet)
            if(null !== $debug) $this->debug = true === $debug;
            else if($this->core->is->development()) $this->debug = true;
            //endregion

            //region VERIFY the session is currently active
            if(session_status() == PHP_SESSION_ACTIVE) {
                $this->id = session_id();
                $this->start = true;
            }
            //endregion

        }

        /**
         * init the session
         * @param string $id optional paramater to assign a session_id
         */
        function init($id = '')
        {
            // If they pass a session id I will use it.
            if (!empty($id)) {
                $this->id = '';
                $this->start = false;

                if(session_status() == PHP_SESSION_ACTIVE) {
                    if(session_id() != $id )  {
                        session_abort();
                        session_id($id);
                    }
                } else {
                    session_id($id);
                }
            }

            // Session start
            if(session_status() != PHP_SESSION_ACTIVE) {
                session_start();
            }

            // Let's keep the session id
            $this->id = session_id();

            // Initiated.
            $this->start = true;

            if($this->debug)
                $this->core->logs->add("init(). id: {$this->id}",'CoreSession');

        }

        /**
         * get a variable from session
         * @param $var
         * @return mixed|null
         */
        function get($var)
        {
            if (!$this->start) $this->init();
            if (key_exists('CloudSessionVar_' . $var, $_SESSION)) {
                try {
                    $ret = unserialize(gzuncompress($_SESSION['CloudSessionVar_' . $var]));
                } catch (Exception $e) {
                    if($this->debug)
                        $this->core->logs->add("get('\$var=$var') Exception: ".$e->getMessage(),'CoreSession');
                    return null;
                }
                if($this->debug)
                    $this->core->logs->add("get('\$var=$var') found",'CoreSession');
                return $ret;
            }
            if($this->debug)
                $this->core->logs->add("get('\$var=$var') not-found",'CoreSession');

            return null;
        }

        /**
         * set a variable from session
         * @param $var
         * @param $value
         */
        function set($var, $value)
        {
            if (!$this->start) $this->init();
            $_SESSION['CloudSessionVar_' . $var] = gzcompress(serialize($value));
            if($this->debug)
                $this->core->logs->add("set('\$var=$var') ok",'CoreSession');
        }

        /**
         * delete a variable from session
         * @param $var
         */
        function delete($var)
        {
            if (!$this->start) $this->init();
            unset($_SESSION['CloudSessionVar_' . $var]);
            if($this->debug)
                $this->core->logs->add("delete('\$var=$var') ok",'CoreSession');
        }
    }

    /**
     * Class to interacto with with the System variables
     * @package Core
     */
    class CoreSystem
    {
        var $url, $app,$root_path, $app_path, $app_url,$script_path;
        var $config = [];
        var $ip, $user_agent, $os, $lang, $format, $time_zone;
        var $geo;

        function __construct($root_path = '')
        {

            // region  $server_var from $_SERVER
            $server_var['HTTPS'] = (array_key_exists('HTTPS',$_SERVER))?$_SERVER['HTTPS']:null;
            $server_var['DOCUMENT_ROOT'] = (array_key_exists('DOCUMENT_ROOT',$_SERVER))?$_SERVER['DOCUMENT_ROOT']:null;
            $server_var['HTTP_HOST'] = (array_key_exists('HTTP_HOST',$_SERVER))?$_SERVER['HTTP_HOST']:null;
            $server_var['REQUEST_URI'] = (array_key_exists('REQUEST_URI',$_SERVER))?$_SERVER['REQUEST_URI']:null;
            $server_var['SCRIPT_NAME'] = (array_key_exists('SCRIPT_NAME',$_SERVER))?$_SERVER['SCRIPT_NAME']:null;
            $server_var['HTTP_USER_AGENT'] = (array_key_exists('HTTP_USER_AGENT',$_SERVER))?$_SERVER['HTTP_USER_AGENT']:null;
            $server_var['HTTP_ACCEPT_LANGUAGE'] = (array_key_exists('HTTP_ACCEPT_LANGUAGE',$_SERVER))?$_SERVER['HTTP_ACCEPT_LANGUAGE']:null;
            $server_var['HTTP_X_APPENGINE_COUNTRY'] = (array_key_exists('HTTP_X_APPENGINE_COUNTRY',$_SERVER))?$_SERVER['HTTP_X_APPENGINE_COUNTRY']:null;
            $server_var['HTTP_X_APPENGINE_CITY'] = (array_key_exists('HTTP_X_APPENGINE_CITY',$_SERVER))?$_SERVER['HTTP_X_APPENGINE_CITY']:null;
            $server_var['HTTP_X_APPENGINE_REGION'] = (array_key_exists('HTTP_X_APPENGINE_REGION',$_SERVER))?$_SERVER['HTTP_X_APPENGINE_REGION']:null;
            $server_var['HTTP_X_APPENGINE_CITYLATLONG'] = (array_key_exists('HTTP_X_APPENGINE_CITYLATLONG',$_SERVER))?$_SERVER['HTTP_X_APPENGINE_CITYLATLONG']:null;
            // endregion

            if (!strlen($root_path)) $root_path = (strlen($_SERVER['DOCUMENT_ROOT'])) ? $_SERVER['DOCUMENT_ROOT'] : $_SERVER['PWD'];

            $this->url['https'] = $server_var['HTTPS'];
            $this->url['protocol'] = ($server_var['HTTPS'] == 'on') ? 'https' : 'http';
            $this->url['host'] = $server_var['HTTP_HOST'];
            $this->url['url_uri'] = $server_var['REQUEST_URI'];

            $this->url['url'] = $server_var['REQUEST_URI'];
            $this->url['params'] = '';
            if (strpos($server_var['REQUEST_URI'], '?') !== false)
                list($this->url['url'], $this->url['params']) = explode('?', $server_var['REQUEST_URI'], 2);

            $this->url['host_base_url'] = (($server_var['HTTPS'] == 'on') ? 'https' : 'http') . '://' . $server_var['HTTP_HOST'];
            $this->url['host_url'] = (($server_var['HTTPS'] == 'on') ? 'https' : 'http') . '://' . $server_var['HTTP_HOST'] . $this->url['url'];
            $this->url['host_url_uri'] = (($server_var['HTTPS'] == 'on') ? 'https' : 'http') . '://' . $server_var['HTTP_HOST'] . $server_var['REQUEST_URI'];
            $this->url['script_name'] = $server_var['SCRIPT_NAME'];
            $this->url['parts'] = explode('/', substr($this->url['url'], 1));
            $this->url['parts_base_index'] = 0;
            $this->url['parts_base_url'] = '/';

            // paths
            $this->root_path = $root_path;
            $this->app_path = $this->root_path;

            // Remote user:
            $this->ip = $this->getClientIP();
            $this->user_agent = $server_var['HTTP_USER_AGENT'];
            $this->os = $this->getOS();
            $this->lang = $server_var['HTTP_ACCEPT_LANGUAGE'];

            // About timeZone, Date & Number format
            if (isset($_SERVER['PWD']) && strlen($_SERVER['PWD'])) date_default_timezone_set('UTC'); // necessary for shell run
            $this->time_zone = array(date_default_timezone_get(), date('Y-m-d h:i:s'), date("P"), time());
            //date_default_timezone_set(($this->core->config->get('timeZone')) ? $this->core->config->get('timeZone') : 'Europe/Madrid');
            //$this->_timeZone = array(date_default_timezone_get(), date('Y-m-d h:i:s'), date("P"), time());
            $this->format['formatDate'] = "Y-m-d";
            $this->format['formatDateTime'] = "Y-m-d h:i:s";
            $this->format['formatDBDate'] = "Y-m-d";
            $this->format['formatDBDateTime'] = "Y-m-d h:i:s";
            $this->format['formatDecimalPoint'] = ",";
            $this->format['formatThousandSep'] = ".";

            // General conf
            // TODO default formats, currencies, timezones, etc..
            $this->config['setLanguageByPath'] = false;

            // GEO BASED ON GOOGLE APPENGINE VARS
            $this->geo['COUNTRY'] = $server_var['HTTP_X_APPENGINE_COUNTRY'];
            $this->geo['CITY'] = $server_var['HTTP_X_APPENGINE_CITY'];
            $this->geo['REGION'] = $server_var['HTTP_X_APPENGINE_REGION'];
            $this->geo['COORDINATES'] = $server_var['HTTP_X_APPENGINE_CITYLATLONG'];

            // Script path for terminal
            $this->script_path = $this->app_path.'/scripts';

        }

        function setTimeZone($timezone) {
            date_default_timezone_set($timezone);
            $this->time_zone = array(date_default_timezone_get(), date('Y-m-d h:i:s'), date("P"), time());
        }


        /**
         * Return the IP of the client:
         * https://cloud.google.com/appengine/docs/standard/php7/runtime#https_and_forwarding_proxies
         * @return mixed|string
         */
        function getClientIP() {

            $remote_address = (array_key_exists('HTTP_X_FORWARDED_FOR',$_SERVER))?$_SERVER['HTTP_X_FORWARDED_FOR']:'localhost';
            return  ($remote_address == '::1') ? 'localhost' : $remote_address;

            // Popular approaches we don't trust.
            // http://stackoverflow.com/questions/3003145/how-to-get-the-client-ip-address-in-php#comment50230065_3003233
            // http://stackoverflow.com/questions/15699101/get-the-client-ip-address-using-php
            /*
            if (getenv('HTTP_CLIENT_IP'))
                $ipaddress = getenv('HTTP_CLIENT_IP');
            else if (getenv('HTTP_X_FORWARDED_FOR'))
                $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
            else if (getenv('HTTP_X_FORWARDED'))
                $ipaddress = getenv('HTTP_X_FORWARDED');
            else if (getenv('HTTP_FORWARDED_FOR'))
                $ipaddress = getenv('HTTP_FORWARDED_FOR');
            else if (getenv('HTTP_FORWARDED'))
                $ipaddress = getenv('HTTP_FORWARDED');
            else if (getenv('REMOTE_ADDR'))
                $ipaddress = getenv('REMOTE_ADDR');
            else
                $ipaddress = 'UNKNOWN';
            return $ipaddress;
            */

        }

        public function getOS()
        {
            $os_platform = "Unknown OS Platform";
            $os_array = array(
                '/windows nt 6.2/i'     => 'Windows 8',
                '/windows nt 6.1/i'     => 'Windows 7',
                '/windows nt 6.0/i'     => 'Windows Vista',
                '/windows nt 5.2/i'     => 'Windows Server 2003/XP x64',
                '/windows nt 5.1/i'     => 'Windows XP',
                '/windows xp/i'         => 'Windows XP',
                '/windows nt 5.0/i'     => 'Windows 2000',
                '/windows me/i'         => 'Windows ME',
                '/win98/i'              => 'Windows 98',
                '/win95/i'              => 'Windows 95',
                '/win16/i'              => 'Windows 3.11',
                '/macintosh|mac os x/i' => 'Mac OS X',
                '/mac_powerpc/i'        => 'Mac OS 9',
                '/linux/i'              => 'Linux',
                '/ubuntu/i'             => 'Ubuntu',
                '/iphone/i'             => 'iPhone',
                '/ipod/i'               => 'iPod',
                '/ipad/i'               => 'iPad',
                '/android/i'            => 'Android',
                '/blackberry/i'         => 'BlackBerry',
                '/webos/i'              => 'Mobile'
            );
            foreach ($os_array as $regex => $value) {
                if (array_key_exists('HTTP_USER_AGENT',$_SERVER) && preg_match($regex, $_SERVER['HTTP_USER_AGENT'])) {
                    $os_platform = $value;
                }
            }
            return ($os_platform)?:$_SERVER['HTTP_USER_AGENT'];
        }

        /**
         * @param $url path for destination ($dest is empty) or for source ($dest if not empty)
         * @param string $dest Optional destination. If empty, destination will be $url
         */
        function urlRedirect($url, $dest = '')
        {
            if (!strlen($dest)) {
                if ($url != $this->url['url']) {
                    Header("Location: $url");
                    exit;
                }
            } else if ($url == $this->url['url'] && $url != $dest) {
                if (strlen($this->url['params'])) {
                    if (strpos($dest, '?') === false)
                        $dest .= "?" . $this->url['params'];
                    else
                        $dest .= "&" . $this->url['params'];
                }
                Header("Location: $dest");
                exit;
            }
        }

        /**
         * Generate a fingerprint from the Request
         * @return array
         */
        function getRequestFingerPrint()
        {
            // Return the fingerprint coming from a queue
            if (isset($_REQUEST['cloudframework_queued_fingerprint'])) {
                return (json_decode($_REQUEST['cloudframework_queued_fingerprint'], true));
            }

            $ret['user_agent'] = (isset($_SERVER['HTTP_USER_AGENT']))?$_SERVER['HTTP_USER_AGENT']:'unknown';
            $ret['host'] = (isset($_SERVER['HTTP_HOST']))?$_SERVER['HTTP_HOST']:null;
            $ret['software'] = (isset($_SERVER['GAE_RUNTIME']))?('GAE_RUN_TIME:'.$_SERVER['GAE_RUNTIME'].'/'.$_SERVER['GAE_VERSION']):((isset($_SERVER['SERVER_SOFTWARE']))?$_SERVER['SERVER_SOFTWARE']:'Unknown');


            $ret['hash'] = sha1(implode(",", $ret));
            $ret['ip'] = $this->ip;

            if(isset($_SERVER['HTTP_X_APPENGINE_CITY'])) $ret['getData']['city'] = $_SERVER['HTTP_X_APPENGINE_CITY'];
            if(isset($_SERVER['HTTP_X_APPENGINE_COUNTRY'])) $ret['getData']['country'] = $_SERVER['HTTP_X_APPENGINE_COUNTRY'];
            if(isset($_SERVER['HTTP_X_APPENGINE_REGION'])) $ret['getData']['region'] = $_SERVER['HTTP_X_APPENGINE_REGION'];
            if(isset($_SERVER['HTTP_X_APPENGINE_CITYLATLONG'])) $ret['getData']['latlong'] = $_SERVER['HTTP_X_APPENGINE_CITYLATLONG'];

            $ret['http_referer'] = (array_key_exists('HTTP_REFERER',$_SERVER))?$_SERVER['HTTP_REFERER']:'unknown';
            $ret['time'] = date('Ymdhis');
            $ret['uri'] = (isset($_SERVER['REQUEST_URI']))?$_SERVER['REQUEST_URI']:null;



            return ($ret);
        }

        function crypt($input, $rounds = 7)
        {
            $salt = "";
            $salt_chars = array_merge(range('A', 'Z'), range('a', 'z'), range(0, 9));
            for ($i = 0; $i < 22; $i++) {
                $salt .= $salt_chars[array_rand($salt_chars)];
            }
            return crypt($input, sprintf('$2a$%02d$', $rounds) . $salt);
        }

        // Compare Password
        function checkPassword($passw, $compare)
        {
            return (crypt($passw, $compare) == $compare);
        }

    }

    /**
     * Class to manage Logs & Errors
     * https://cloud.google.com/logging/docs/setup/php
     * $logger->info('This will show up as log level INFO');
     * $logger->warning('This will show up as log level WARNING');
     * $logger->error('This will show up as log level ERROR');
     * @package Core
     */
    class CoreLog
    {
        var $lines = 0;
        var $data = [];
        var $syslog_type = 'info';  //error, info, warning, notice, debug, critical, alert, emergency
        /** @var Logger|PsrLogger  */
        var $logger = null;
        var $is_terminal;
        var $active_lines = true;

        function __construct($is_terminal=false)
        {
            global $logger;
            $this->logger= &$logger;
            $this->is_terminal = $is_terminal;
        }
        /**
         * Reset the log and add an entry in the log.. if syslog_title is passed, also insert a LOG_DEBUG
         * @param $data
         * @param string $syslog_title
         */
        function set($data,$syslog_title=null, $syslog_type=null)
        {
            $this->lines = 0;
            $this->data = [];
            $this->add($data,$syslog_title, $syslog_type);
        }

        /**
         * Add an entry in the log.. if syslog_title is passed, also insert a LOG_DEBUG
         * @param $data
         * @param string $syslog_title
         * @param $syslog_type string|null values: error, info, warning, notice, debug, critical, alert, emergency
         */
        function add($data, $syslog_title=null, $syslog_type=null)
        {
            // Evaluate to write in syslog
            if(null !==  $syslog_title) {
                if(null==$syslog_type) $syslog_type = $this->syslog_type;
                $this->sendToSysLog($syslog_title.': '. json_encode($data,JSON_FORCE_OBJECT),$syslog_type);
                //syslog($syslog_type, $syslog_title.': '. json_encode($data,JSON_FORCE_OBJECT));
                // Change the data sent to say that the info has been sent to syslog
                if(is_string($data))
                    $data = '[syslog:'.$syslog_type.'] '.$syslog_title.': '.$data;
                else
                    $data = ['[syslog:'.$syslog_type.'] '.$syslog_title=>$data];
            }
            // Store in local var.
            if($this->active_lines) {
                $this->data[] = $data;
                $this->lines++;
            }
        }

        /**
         * return the current data stored in the log
         * @return array
         */
        function get() { return $this->data; }

        /**
         * store all the data inside a syslog
         *
         * @param $data
         * @param $syslog_type string|null values: error, info, warning, notice, debug, critical, alert, emergency
         */
        function sendToSysLog($data, $syslog_type=null) {
            if(!is_string($data)) $data = json_encode($data,JSON_FORCE_OBJECT);
            if(null==$syslog_type) $syslog_type = $this->syslog_type;
            // In development write the logs in a different way
            if(is_object($this->logger)) {
                switch ($syslog_type) {
                    case "error":
                        $this->logger->error($data);
                        break;
                    case "warning":
                        $this->logger->warning($data);
                        break;
                    case "notice":
                        $this->logger->notice($data);
                        break;
                    case "debug":
                        $this->logger->debug($data);
                        break;
                    case "critical":
                        $this->logger->critical($data);
                        break;
                    case "alert":
                        $this->logger->alert($data);
                        break;
                    case "emergency":
                        $this->logger->emergency($data);
                        break;
                    default:
                        $this->logger->info($data);
                        break;
                }
            } else {
                switch ($syslog_type) {
                    case "error":
                        syslog(LOG_ERR,$data);
                        break;
                    case "warning":
                        syslog(LOG_WARNING,$data);
                        break;
                    case "notice":
                        syslog(LOG_NOTICE,$data);
                        break;
                    case "debug":
                        syslog(LOG_DEBUG,$data);
                        break;
                    case "critical":
                        syslog(LOG_CRIT,$data);
                        break;
                    case "alert":
                        syslog(LOG_ALERT,$data);
                        break;
                    case "emergency":
                        syslog(LOG_EMERG,$data);
                        break;
                    default:
                        syslog(LOG_INFO,$data);
                        break;
                }
                if(!$this->is_terminal || !$this->active_lines)
                    file_put_contents("php://stderr", ' - [log:'.$syslog_type.']: '.$data."\n");
            }
        }

        /**
         * Reset the log
         */
        function reset()
        {
            $this->lines = 0;
            $this->data = [];
        }

    }

    /**
     * Class to answer is? questions
     * @package Core
     */
    class CoreIs
    {
        function development()
        {
            return (!(array_key_exists('GAE_SERVICE',$_SERVER)));
        }

        function production()
        {
            return (array_key_exists('GAE_SERVICE',$_SERVER) );
        }

        function localEnvironment()
        {
            return (!(array_key_exists('GAE_SERVICE',$_SERVER)));
        }

        function GCPEnvironment()
        {
            return (array_key_exists('GAE_SERVICE',$_SERVER) );
        }

        function script()
        {
            return (isset($_SERVER['PWD']) && !isset($_SERVER['GAE_SERVICE']));
        }

        function dirReadable($dir)
        {
            if (strlen($dir)) return (is_dir($dir));
        }

        function terminal()
        {
            return !isset($_SERVER['SERVER_PORT']);
        }

        function dirWritable($dir)
        {
            if (strlen($dir)) {
                if (!$this->dirReadble($dir)) return false;
                try {
                    if (@mkdir($dir . '/__tmp__')) {
                        rmdir($dir . '/__tmp__');
                        return (true);
                    }
                } catch (Exception $e) {
                    return false;
                }
            }
        }

        function validEmail($email)
        {
            return (filter_var($email, FILTER_VALIDATE_EMAIL));
        }

        function validURL($url)
        {
            return (filter_var($url, FILTER_VALIDATE_URL));
        }
    }

    /**
     * Class to manage Cache
     * https://cloud.google.com/appengine/docs/standard/php7/using-memorystore#setup_redis_db
     * @package Core
     */
    class CoreCache
    {
        var $cache = null;
        var $spacename = 'CloudFrameWork';
        var $type = 'memory'; // memory, redis, datastore, directory,
        var $dir = '';
        var $error = false;
        var $errorMsg = [];
        var $debug = false;
        var $lastHash = null;
        var $lastExpireTime = null;
        var $atom = null;
        var $errorSecurity = false; // It will change to true when you try to get a value using a wrong $cache_secret_key,$cache_secret_iv
        /** @var Core  */
        var $core=null;

        /**
         * CoreCache constructor. If $type==CacheInDirectory a writable $path is required
         * @param string $spacename
         * @param string $path if != null the it assumes the cache will be store in files
         */
        function __construct(Core7 &$core, $spacename = '',  $path=null, $debug = null)
        {
            $this->core = $core;

            // Initialize $this->spacename
            if (!strlen(trim($spacename))) $spacename = (isset($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : $_SERVER['PWD'];
            $this->setSpaceName($spacename);

            // Activate debug based on $debug or if I am in development (local environgmnet)
            if(null !== $debug)
                $this->debug = true === $debug;
            // If we are in localhost then activate
            else
                if($this->core->is->development()) {
                    if(!$path && $this->core->config->get('core.cache.cache_path')) $path = $this->core->config->get('core.cache.cache_path');
                    $this->debug = true;
                }

            // Activate CacheInDirectory
            if (null !== $path) {
                $this->activateCacheFile($path);
            }
        }

        /**
         * Activate cache in memory
         * @return bool
         */
        function activateMemory()
        {
            $this->cache = null;
            $this->type = 'memory';
            $this->init();
            return true;
        }

        /**
         * Activate cache in files.. It requires that the path will be writtable
         * @param string $path dir path to keep files.
         * @param string $spacename
         * @return bool
         */
        function activateCacheFile($path, $spacename = '')
        {
            // Avoid to activate the same Cache Path
            if($this->dir == $path && is_object($this->cache) ) return true;

            if (isset($_SESSION['Core_CacheFile_' . $path]) || is_dir($path) || @mkdir($path)) {
                $this->type = 'directory';
                $this->dir = $path;
                if (strlen($spacename)) $spacename = '_' . $spacename;
                $this->setSpaceName(basename($path) . $spacename);
                $this->cache = null;
                $this->init();

                // Save in session to improve the performance for buckets because is_dir has a high cost.
                if(isset($_SESSION))
                    $_SESSION['Core_CacheFile_' . $path] = true;
                return true;
            } else {
                $this->addError($path . ' does not exist and can not be created');
                return false;
            }

        }

        /**
         * Activate cache in Datastore.. It requires Core Class
         * @param Core7 $core Core class to facilitate errors
         * @param string $spacename spacename where to store objecs
         * @return bool
         */
        function activateDataStore( $spacename = '')
        {
            $this->type = 'datastore';
            if($spacename) $this->spacename = $spacename;
            $this->cache = null;
            $this->init();
            if($this->error) return false;
            else return true;
        }

        /**
         * Initialiated Cache Memory object.. If previously it has been called it just returns true.. if there is an error it returns false..
         * https://cloud.google.com/appengine/docs/standard/php7/php-differences
         * App Engine Memcache support is not provided. Instead, use Memorystore for Redis.
         * https://cloud.google.com/appengine/docs/standard/php7/using-memorystore
         * @return bool
         */
        function init()
        {
            if(null !== $this->cache) return(is_object($this->cache));

            if($this->debug)
                $this->core->logs->add("init(). type: {$this->type}",'CoreCache');

            if ($this->type == 'memory') {
                if (!getenv('REDIS_HOST') || !getenv('REDIS_PORT')) {
                    if($this->debug)
                        $this->core->logs->add("init(). Failed because REDIS_HOST and REDIS_PORT env_vars does not exist.",'CoreCache','warning');
                    $this->cache=-1;
                    return;
                } else {
                    $host = getenv('REDIS_HOST');
                    $port = getenv('REDIS_PORT');
                    try {
                        $this->cache = new Redis();
                        $this->cache->connect($host, $port);
                    } catch (Exception $e) {
                        $this->core->logs->add("init(). REDIS connection failed because: ". $e->getMessage(),'CoreCache','warning');
                        unset($this->cache);
                        $this->cache=-1;
                        return;
                    }

                }
            } elseif($this->type=='datastore') {
                $this->cache = new CoreCacheDataStore($this->core,$this->spacename);
                if($this->cache->error) $this->addError(['CoreCacheDataStore'=>$this->cache->errorMsg]);
            } elseif($this->type=='directory' && $this->dir) {
                $this->cache = new CoreCacheFile($this->dir);
            }

            return(is_object($this->cache));
        }


        /**
         * Set a $spacename to set/get $objects
         * @param $name
         */
        function setSpaceName($name)
        {
            if (strlen($name)) {
                $name = '_' . trim($name);
                $this->spacename = preg_replace('/[^A-z_-]/', '_', 'CloudFrameWork_' . $this->type . $name);
            }
        }

        /**
         * Return an object from Cache.
         * @param $key
         * @param int $expireTime The default value es -1. If you want to expire, you can use a value in seconds.
         * @param string $hash if != '' evaluate if the $hash match with hash stored in cache.. If not, delete the cache and return false;
         * @param string $cache_secret_key  optional secret key. If not empty it will encrypt the data in cache
         * @param string $cache_secret_iv  optional secret key. If not empty it will encrypt the data in cache
         * @return bool|mixed|null
         */
        function get($key, $expireTime = -1, $hash = '',$cache_secret_key='',$cache_secret_iv='')
        {
            if(!$this->init() || !strlen(trim($key))) return null;

            //region SET __p performance paramater
            if (!strlen($expireTime)) $expireTime = -1;
            $encrypted = ($cache_secret_key && $cache_secret_iv)?'/encrypted':'/no-encrypted';
            $encrypted.='/exp:'.$expireTime;
            $encrypted.='/hash:'.(($hash)?'/with-hash':'/no-hash');
            $this->core->__p->add("CoreCache.get [{$this->type}{$encrypted}]", $key, 'note');
            //endregion


            $info = $this->cache->get($this->spacename . '-' . $key);
            if (strlen($info) && $info !== null) {

                $info = unserialize($info);
                $this->lastExpireTime = microtime(true) - $info['_microtime_'];
                $this->lastHash = $info['_hash_'];

                // Expire Caché
                if ($expireTime >= 0 && microtime(true) - $info['_microtime_'] >= $expireTime) {
                    $this->delete( $key);
                    if($this->debug)
                        $this->core->logs->add("get('\$key=$key',$expireTime=\$expireTime) failed (because expiration)",'CoreCache');
                    $this->core->__p->add("CoreCache.get [{$this->type}{$encrypted}]", '', 'endnote');
                    return null;
                }
                // Hash Cache
                if ('' != $hash && $hash != $info['_hash_']) {
                    $this->delete( $key);
                    if($this->debug)
                        $this->core->logs->add("get('$key',$expireTime,'\$hash') failed (because hash does not match) token: ".$this->spacename . '-' . $key.' [hash='.$this->lastHash.',since='.round($this->lastExpireTime,2).' ms.]','CoreCache');

                    $this->core->__p->add("CoreCache.get [{$this->type}{$encrypted}]", '', 'endnote');
                    return null;
                }
                // Normal return

                // decrypt data if $cache_secret_key and $cache_secret_iv are not empty
                if($cache_secret_key && $cache_secret_iv) {
                    $this->errorSecurity = false;
                    $info['_data_'] = $this->core->security->decrypt($info['_data_'],$cache_secret_key,$cache_secret_iv);
                }

                // unserialize vars
                $ret = null;
                try {
                    if(isset($info['_data_']) && $info['_data_']) {
                        $ret = @unserialize(@gzuncompress($info['_data_']));
                        if($ret===false && $cache_secret_key && $cache_secret_iv) {
                            $this->delete( $key);
                            $this->errorSecurity = true;
                            if($this->debug)
                                $this->core->logs->add("get('$key',$expireTime,'$hash',\$cache_secret_key or \$cache_secret_iv). Wrong \$cache_secret_key or \$cache_secret_iv. Cache key has been deleted because security",'CoreCache');
                            $this->core->__p->add("CoreCache.get [{$this->type}{$encrypted}]", '', 'endnote');
                            return null;
                        }
                    }
                } catch (Exception $e) {
                    $ret = null;
                }

                if($this->debug)
                    $this->core->logs->add("get(\$key=$key,\$expireTime=$expireTime,\$hash,\$cache_secret_key, \$cache_secret_iv). successful returned in namespace ".$this->spacename . ' [time='.round($this->lastExpireTime,2).' ms.]','CoreCache');


                $this->core->__p->add("CoreCache.get [{$this->type}{$encrypted}]", '', 'endnote');
                return $ret;

            } else {
                if($this->debug) $this->core->logs->add("get(\$key=$key) failed (because it does not exist)",'CoreCache');
                $this->core->__p->add("CoreCache.get [{$this->type}{$encrypted}]", 'error', 'endnote');
                return null;
            }
        }

        /**
         * Set an object on cache based on $key
         * @param $key
         * @param mixed $object
         * @param string $hash Allow to set the info based in a hash to determine if it is valid when read it.
         * @param string $cache_secret_key  optional secret key. If not empty it will encrypt the data en cache
         * @param string $cache_secret_iv  optional secret key. If not empty it will encrypt the data en cache
         * @return bool
         */
        function set($key, $object, $hash=null, $cache_secret_key='',$cache_secret_iv='')
        {
            $encrypt = ($cache_secret_key && $cache_secret_iv)?'/encrypt':'/no-encrypt';
            if(!$this->init() || !strlen(trim($key))) return null;
            $this->core->__p->add("CoreCache.set [{$this->type}{$encrypt}]", $key, 'note');

            $info['_microtime_'] = microtime(true);
            $info['_hash_'] = $hash;
            $info['_data_'] = gzcompress(serialize($object));

            // encrypt data if $cache_secret_key and $cache_secret_iv are not empty
            if($cache_secret_key && $cache_secret_iv) $info['_data_'] = $this->core->security->encrypt($info['_data_'],$cache_secret_key,$cache_secret_iv);

            $this->cache->set($this->spacename . '-' . $key, serialize($info));
            // If exists a property error in the class checkit
            if(isset($this->cache->error) && $this->cache->error) {
                $this->error = true;
                $this->errorMsg = $this->cache->errorMsg;
            }

            if($this->debug)
                $this->core->logs->add("set(\$key={$key},..)".(($hash)?' with $hash,':'').(($cache_secret_key && $cache_secret_iv)?' with $cache_secret_key and $cache_secret_iv':''),'CoreCache');

            unset($info);
            $this->core->__p->add("CoreCache.set [{$this->type}{$encrypt}]", '', 'endnote');
            return ($this->error)?false:true;
        }

        /**
         * delete a $key from cache
         * @param $key
         * @return true|null
         */
        function delete($key)
        {
            if(!$this->init() || !strlen(trim($key))) return null;

            if (!strlen(trim($key))) return false;
            if($this->type=='memory')
                $this->cache->del($this->spacename . '-' . $key);
            else
                $this->cache->delete($this->spacename . '-' . $key);
            if($this->debug)
                $this->core->logs->add("delete(). token: ".$this->spacename . '-' . $key,'CoreCache');

            return true;
        }


        /**
         * Return a cache based in a hash previously assigned in set
         * @param $str
         * @param $hash
         * @return bool|mixed|null
         */
        public function getByHash($str, $hash) { return $this->get($str,-1, $hash); }


        /**
         * Return a cache based in the Expiration time = TimeToSave + $seconds
         * @param string $str
         * @param int $seconds
         * @return bool|mixed|null
         */
        public function getByExpireTime($str, $seconds) { return $this->get($str,$seconds); }

        /**
         * Set error in the class
         * @param $value
         */
        function addError($value)
        {
            $this->error = true;
            $this->errorMsg[] = $value;
        }

    }

    /**
     * Class to manate Cache in Files
     * @package Core
     */
    class CoreCacheFile
    {

        var $dir = '';

        function __construct($dir = '')
        {
            if (strlen($dir)) $this->dir = $dir . '/';
        }

        function set($path, $data)
        {
            $path = preg_replace('/[\/\.;]/','_',$path);
            return @file_put_contents($this->dir . $path, gzcompress(serialize($data)));
        }

        function delete($path)
        {
            $path = preg_replace('/[\/\.;]/','_',$path);
            if(is_file($this->dir . $path))
                return @unlink($this->dir . $path);
        }

        function get($path)
        {
            $ret = false;
            $path = preg_replace('/[\/\.;]/','_',$path);
            if (is_file($this->dir . $path))
                $ret = file_get_contents($this->dir . $path);
            if (false === $ret) return null;
            else return unserialize(gzuncompress($ret));
        }
    }

    /**
     * Class to manage Cache in DataStore
     * @package Core
     */
    class CoreCacheDataStore
    {

        /** @var DataStore  */
        var $ds = null;
        /** @var Core7 */
        var $core;
        var $error = false;
        var $errorMsg = [];
        var $spacename = 'CloudFramework';

        function __construct(Core7 &$core, $spacename)
        {
            $this->spacename = $spacename;
            $this->core = $core;
            $entity = 'CloudFrameworkCache';
            $model = json_decode('{
                                    "KeyName": ["keyname","index|minlength:4"],
                                    "Fingerprint": ["json","internal"],
                                    "DateUpdating": ["datetime","index|forcevalue:now"],
                                    "Serialize": ["string"]
                                  }',true);
            $this->ds = $core->loadClass('DataStore',[$entity,$this->spacename,$model]);
            if ($this->ds->error) return($this->addError($this->ds->errorMsg));

        }

        function set($path, $data)
        {
            $entity = ['KeyName'=>$path
                ,'Fingerprint'=>$this->core->system->getRequestFingerPrint()
                ,'DateUpdating'=>"now"
                ,'Serialize'=>utf8_encode(gzcompress(serialize($data)))];

            $ret = $this->ds->createEntities([$entity]);
            if($this->ds->error) {
                $this->errorMsg = ['DataStore'=>$this->ds->errorMsg];
                $this->error = true;
                return false;
            }
            return true;
        }


        function delete($path)
        {
            $this->ds->deleteByKeys([$path]);
            if($this->ds->error) $this->addError($this->ds->errorMsg);
            if($this->error) return false;
            else return true;
        }

        function get($path)
        {
            $data = $this->ds->fetchOneByKey($path);
            if($this->ds->error) return($this->addError($this->ds->errorMsg));
            if(!$data) return null;
            else return unserialize(gzuncompress(utf8_decode($data['Serialize'])));
        }

        function addError($value)
        {
            $this->error = true;
            $this->errorMsg[] = $value;
        }
    }

    /**
     * Class to manage User information
     * @package Core
     */
    class CoreUser
    {
        private $core;
        var $isAuth = null;
        var $namespace;
        var $data = [];

        function __construct(Core7 &$core, $namespace = 'Default')
        {
            $this->core = $core;
            $this->namespace = (is_string($namespace) && strlen($namespace)) ? $namespace : 'Default';

            // Check if the Auth comes from X-CloudFrameWork-AuthToken and there is a hacking.
            if (strlen($this->core->security->getHeader('X-CloudFrameWork-AuthToken'))) {
                list($sessionId, $hash) = explode("_", $this->core->security->getHeader('X-CloudFrameWork-AuthToken'), 2);

                // Checking security
                $_security = '';
                if (!strlen(trim($hash)) || $hash != $this->core->system->getRequestFingerPrint()[hash]) {
                    $_security = 'Wrong token.';
                    if (strlen($hash)) $_security .= ' Violating token integrity, ';
                } else {

                    $this->core->session->init($sessionId);
                    if (!$this->isAuth() || $this->getVar('token') != $this->core->security->getHeader('X-CloudFrameWork-AuthToken')) {
                        $_security = 'Violating session token integrity';
                        session_destroy();
                        $_SESSION = array();
                        session_regenerate_id();
                        $this->data = [];
                    }
                }
                // Informing security issue
                // This is top level risk
                if (strlen($_security)) {
                    // TODO: Report the log
                    //$this->sendLog('access', 'Hacking', 'X-CloudFrameWork-AuthToken', 'Ilegal token ' . $this->getHeader('X-CloudFrameWork-AuthToken')
                    //    , 'Error comparing with internal token: ' . $this->getAuthUserData('token') . ' for user: ' . $this->getAuthUserData('email'), $this->getConf('CloudServiceLogEmail'));
                    die($_security);
                }
            }
        }

        function init($namespace = '')
        {
            if (strlen($namespace)) $this->namespace = $namespace;
            $this->core->session->init();
            // LOGOUT with $_REQUEST paramter

            if (isset($_GET['_logout']) || isset($_POST['_logout'])) $this->core->session->delete("_User_" . $this->namespace);
            $this->data[$this->namespace] = $this->core->session->get("_User_" . $this->namespace);

            if (null === $this->data[$this->namespace]) $this->data[$this->namespace] = ['__auth' => false];
        }

        function setVar($var, $data = '')
        {
            if (null === $this->data[$this->namespace]) $this->init();
            if (is_array($var)) {
                foreach ($var as $key => $value)
                    $this->data[$this->namespace][$key] = $value;
            } else {
                $this->data[$this->namespace][$var] = $data;
            }
            $this->data[$this->namespace]['__auth'] = true;
            $this->core->session->set("_User_" . $this->namespace, $this->data[$this->namespace]);
        }

        function getVar($var = '')
        {
            if (null === $this->data[$this->namespace]) $this->init();
            if (!strlen($var))
                return $this->data[$this->namespace];
            else
                return (key_exists($var, $this->data[$this->namespace])) ? $this->data[$this->namespace][$var] : null;
        }

        function isAuth()
        {
            if (null === $this->isAuth) $this->init();
            return (true === $this->data[$this->namespace]['__auth']);
        }

        function setAuth($bool)
        {
            if ($bool) $this->setData('__auth', true);
            else {
                $this->data[$this->namespace] = ['__auth' => false];
                $this->core->session->set("_User_" . $this->namespace, $this->data[$this->namespace]);
            }
        }

        /*
        * Manage User Organizations
        */

        function addOrganization($orgId, $orgData, $group = '')
        {
            $_userOrganizations = $this->getVar("userOrganizations");
            if (empty($_userOrganizations))
                $_userOrganizations = array();


            // Default Organization
            if (count($_userOrganizations) == 0)
                $_userOrganizations['__org__'] = $orgId;

            $_userOrganizations['__orgs__'][$orgId] = $orgData;
            if (!strlen($group)) $group = '__OTHER__';
            $_userOrganizations['__groups__'][$group][$orgId] = true;

            $this->setVar("userOrganizations", $_userOrganizations);
        }

        function setOrganizationDefault($orgId)
        {
            if (strlen($orgId)) {
                $_userOrganizations = $this->getVar("userOrganizations");
                if (is_array($_userOrganizations) && isset($_userOrganizations['__orgs__'][$orgId])) {
                    $_userOrganizations['__org__'] = $orgId;
                    $this->setVar("userOrganizations", $_userOrganizations);
                }
            }
        }

        function getOrganizationDefault()
        {
            $_userOrganizations = $this->getVar("userOrganizations");
            if (is_array($_userOrganizations) && isset($_userOrganizations['__org__']))
                return $_userOrganizations['__org__'];
            else return '__orgNotNefined__';
        }

        function getOrganizations()
        {
            $_userOrganizations = $this->getVar("userOrganizations");

            if (empty($_userOrganizations)
                || (!isset($_userOrganizations['__orgs__']))
            )
                return array();

            return $_userOrganizations['__orgs__'];
        }

        function getOrganizationsGroups()
        {
            $_userOrganizations = $this->getVar("userOrganizations");

            if (empty($_userOrganizations)
                || (!isset($_userOrganizations['__groups__']))
            )
                return array();

            return $_userOrganizations['__groups__'];
        }

        function getOrganization($id = '')
        {
            if (!strlen($id)) $id = $this->getOrganizationDefault();
            $orgs = $this->getOrganizations();
            if (isset($orgs[$id])) return ($orgs[$id]);
            else return null;
        }

        function resetOrganizations()
        {
            $this->setVar("userOrganizations", array());
        }

        /*
         * Manage User Roles
         */

        function setRole($rolId, $rolName = '', $org = '')
        {
            if (!strlen($org)) $org = $this->getOrganizationDefault();
            if (!strlen($rolName)) $rolName = $rolId;

            $_userRoles = $this->getVar("UserRoles");
            if (empty($_userRoles))
                $_userRoles = array();

            $_userRoles[$org]['byId'][$rolId] = $rolName;
            $_userRoles[$org]['byName'][$rolName] = $rolId;
            $this->setVar("UserRoles", $_userRoles);
        }

        function hasRoleId($rolId, $org = '')
        {
            if (!strlen($org)) $org = $this->getOrganizationDefault();
            $_userRoles = $this->getVar("UserRoles");
            if (empty($_userRoles))
                $_userRoles = array();

            if (!is_array($rolId))
                $rolId = array($rolId);
            $ret = false;
            foreach ($rolId as $key => $value) {
                if (strlen($value) && !empty($_userRoles[$org]['byId'][$value]) && strlen($_userRoles[$org]['byId'][$value]))
                    $ret = true;
            }
            return ($ret);

        }

        function hasRoleName($roleName, $org = '')
        {
            if (!strlen($org))
                $org = $this->getOrganizationDefault();
            $_userRoles = $this->getVar("UserRoles");
            if (empty($_userRoles))
                $_userRoles = array();

            if (!is_array($roleName))
                $roleName = array($roleName);
            $ret = false;
            foreach ($roleName as $key => $value) {
                if (strlen($value) && !empty($_userRoles[$org]['byName'][$value]) && strlen($_userRoles[$org]['byName'][$value]))
                    $ret = true;
            }
            return ($ret);
        }

        function resetRoles()
        {
            $this->setVar("UserRoles", array());
        }

        function getRoles($org = '')
        {
            if (!strlen($org))
                $org = $this->getOrganizationDefault();

            $ret = $this->getVar("UserRoles");
            if ($org == '*') return $ret;
            else return (isset($ret[$org])) ? $ret[$org] : [];
        }


        /*
         * Manage User Privileges by App
         */

        function setPrivilege($appId, $privileges = array(), $org = '')
        {
            if (!strlen($org)) $org = $this->getOrganizationDefault();

            $_userPrivileges = $this->getVar("UserPrivileges");
            if (empty($_userPrivileges))
                $_userPrivileges = array();

            $_userPrivileges[$org][$appId] = $privileges;
            $this->setVar("UserPrivileges", $_userPrivileges);
        }

        function getPrivileges($appId = '', $privilege = '', $org = '')
        {
            if (!strlen($org)) $org = $this->getOrganizationDefault();
            $_userPrivileges = $this->getVar("UserPrivileges");

            if (empty($_userPrivileges)
                || (strlen($appId) && !isset($_userPrivileges[$org][$appId]))
                || (strlen($privilege) && !isset($_userPrivileges[$org][$appId][$privilege]))
            )
                return null;

            if (!strlen($appId)) return $_userPrivileges[$org];
            elseif (!strlen($privilege)) return $_userPrivileges[$org][$appId];
            else return $_userPrivileges[$org][$appId][$privilege];
        }

        function resetPrivileges()
        {
            $this->setVar("UserPrivileges", array());
        }


    }

    /**
     * Class to manage CloudFramework configuration.
     * @package Core
     */
    class CoreConfig
    {
        private $core;
        private $_configPaths = [];
        var $data = [];
        var $menu = [];
        var $cache = null;
        // takes the secret_id  from: $this->core->config->get('core.gcp.secrets.env_vars') if it is not set from local_config
        var $cache_secret_key = ''; // openssl rand -base64 32
        var $cache_secret_iv = ''; // openssl rand -base64 24
        protected $lang = 'en';


        function __construct(Core7 &$core, $path)
        {
            $this->core = $core;

            // Read first json file
            $this->readConfigJSONFile($path);

            // Set lang for the system
            if (strlen($this->get('core.localization.default_lang'))) $this->setLang($this->get('core.localization.default_lang'));

            // core.localization.param_name allow to change the lang by URL
            if (strlen($this->get('core.localization.param_name'))) {
                $field = $this->get('core.localization.param_name');
                if (!empty($_GET[$field])) $this->core->session->set('_CloudFrameWorkLang_', $_GET[$field]);
                $lang = $this->core->session->get('_CloudFrameWorkLang_');
                if (strlen($lang))
                    if (!$this->setLang($lang)) {
                        $this->core->session->delete('_CloudFrameWorkLang_');
                    }
            }

            // Cache secrets to encrypt data in Cache
            $this->cache_secret_key=$this->get('core.gcp.secrets.cache_encrypt_key');
            $this->cache_secret_iv=$this->get('core.gcp.secrets.cache_encrypt_iv');

            // Update $this->get('core.scripts.path')
            if($this->get('core.scripts.path')) $this->core->system->script_path = $this->get('core.scripts.path');

        }

        /**
         * Return an array of files readed for config.
         * @return array
         */
        function getConfigLoaded()
        {
            $ret = [];
            foreach ($this->_configPaths as $path => $foo) {
                $ret[] = str_replace($this->core->system->root_path, '', $path);
            }
            return $ret;
        }

        /**
         * Get the current lang
         * @return string
         */
        function getLang()
        {
            return ($this->lang);
        }

        /**
         * Assign the language
         * @param $lang
         * @return bool
         */
        function setLang($lang)
        {
            $lang = preg_replace('/[^a-z]/', '', strtolower($lang));
            // Control Lang
            if (strlen($lang = trim($lang)) < 2) {
                $this->core->logs->add('Warning config->setLang. Trying to pass an incorrect Lang: ' . $lang);
                return false;
            }
            if (strlen($this->get('core.localization.allowed_langs'))
                && !preg_match('/(^|,)' . $lang . '(,|$)/', preg_replace('/[^A-z,]/', '', $this->get('core.localization.allowed_langs')))
            ) {
                $this->core->logs->add('Warning in config->setLang. ' . $lang . ' is not included in {{core.localization.allowed_langs}}');
                return false;
            }

            $this->lang = $lang;
            return true;
        }

        /**
         * Get a config var value. $var is empty return the array with all values.
         * @param string $var  Config variable
         * @return mixed|null
         */
        public function get($var='')
        {
            if(strlen($var))
                return (key_exists($var, $this->data)) ? $this->data[$var] : null;
            else return $this->data;
        }

        /**
         * Set a config var
         * @param $var string
         * @param $data mixed
         */
        public function set($var, $data)
        {
            $this->data[$var] = $data;
        }

        /**
         * Set a config vars bases in an Array {"key":"value"}
         * @param $data Array
         */
        public function bulkSet(Array $data)
        {
            foreach ($data as $key=>$item) {
                $this->data[$key] = $item;
            }
        }
        /**
         * Add a menu line
         * @param $var
         */
        public function pushMenu($var)
        {
            if (!key_exists('menupath', $this->data)) {
                $this->menu[] = $var;
                if (!isset($var['path'])) {
                    $this->core->logs->add('Missing path in menu line');
                    $this->core->logs->add($var);
                } else {
                    // Trying to match the URLs
                    if (strpos($var['path'], "{*}"))
                        $_found = strpos($this->core->system->url['url'], str_replace("{*}", '', $var['path'])) === 0;
                    else
                        $_found = $this->core->system->url['url'] == $var['path'];

                    if ($_found) {
                        $this->set('menupath', $var['path']);
                        foreach ($var as $key => $value) {
                            $value = $this->convertTags($value);
                            $this->set($key, $value);
                        }
                    }
                }
            }
        }

        /**
         * Determine if the current URL is part of the menupath
         * @return bool
         */
        public function inMenuPath()
        {
            return key_exists('menupath', $this->data);
        }

        /**
         * Try to read a JSON file to process it as a corfig file
         * @param $path string
         * @return bool
         */
        public function readConfigJSONFile($path)
        {
            // Avoid recursive load JSON files
            if (isset($this->_configPaths[$path])) {
                $this->core->errors->add("Recursive config file: " . $path);
                return false;
            }
            $this->_configPaths[$path] = 1; // Control witch config paths are beeing loaded.
            try {
                $data = json_decode(@file_get_contents($path), true);
                if (!is_array($data)) {
                    $this->core->errors->add('error reading ' . $path);
                    if (json_last_error())
                        $this->core->errors->add("Wrong format of json: " . $path);
                    elseif (!empty(error_get_last()))
                        $this->core->errors->add(error_get_last());
                    return false;
                } else {
                    $this->processConfigData($data);
                    return true;
                }
            } catch (Exception $e) {
                $this->core->errors->add(error_get_last());
                $this->core->errors->add($e->getMessage());
                return false;
            }
        }

        /**
         * Process a config array
         * @param $data array
         */
        public function processConfigData(array $data)
        {
            // going through $data
            foreach ($data as $cond => $vars) {

                // Just a comment
                if ($cond == '--') continue;

                // Convert potentials Tags
                if (is_string($vars)) $vars = $this->convertTags($vars);
                $include = false;

                $tagcode = '';
                if (strpos($cond, ':') !== false) {
                    // Substitute tags for strings
                    $cond = $this->convertTags(trim($cond));
                    list($tagcode, $tagvalue) = explode(":", $cond, 2);
                    $tagcode = trim($tagcode);
                    $tagvalue = trim($tagvalue);

                    if ($this->isConditionalTag($tagcode))
                        $include = $this->getConditionalTagResult($tagcode, $tagvalue);
                    elseif ($this->isAssignationTag($tagcode)) {
                        $this->setAssignationTag($tagcode, $tagvalue, $vars);
                        continue;
                    } else {
                        $this->core->errors->add('unknown tag: |' . $tagcode . '|');
                        continue;
                    }

                } else {
                    $include = true;
                    $vars = [$cond => $vars];

                }

                // Include config vars.
                if ($include) {
                    if (is_array($vars)) {
                        foreach ($vars as $key => $value) {
                            if ($key == '--') continue; // comment
                            // Recursive call to analyze subelements
                            if (strpos($key, ':')) {
                                $this->processConfigData([$key => $value]);
                            } else {
                                // Assign conf var values converting {} tags
                                $this->set($key, $this->convertTags($value));
                            }
                        }
                    }
                }
            }

        }

        /**
         * Evalue if the tag is a condition
         * @param $tag
         * @return bool
         */
        private function isConditionalTag($tag)
        {
            $tags = ["uservar", "authvar", "confvar", "sessionvar", "servervar", "auth", "noauth", "development", "production"
                , "indomain", "domain", "interminal", "url", "noturl", "inurl", "notinurl", "beginurl", "notbeginurl"
                , "inmenupath", "notinmenupath", "isversion", "false", "true"];
            return in_array(strtolower($tag), $tags);
        }

        /**
         * Evalue conditional tags on config file
         * @param $tagcode string
         * @param $tagvalue string
         * @return bool
         */
        private function getConditionalTagResult($tagcode, $tagvalue)
        {
            $evaluateTags = [];
            while(strpos($tagvalue,'|')) {
                list($tagvalue,$tags) = explode('|',$tagvalue,2);
                $evaluateTags[] = [trim($tagcode),trim($tagvalue)];
                list($tagcode,$tagvalue) = explode(':',$tags,2);
            }
            $evaluateTags[] = [trim($tagcode),trim($tagvalue)];
            $ret = false;
            // Conditionals tags
            // -----------------
            foreach ($evaluateTags as $evaluateTag) {
                $tagcode = $evaluateTag[0];
                $tagvalue = $evaluateTag[1];
                switch (trim(strtolower($tagcode))) {
                    case "uservar":
                    case "authvar":
                        if (strpos($tagvalue, '=') !== false) {
                            list($authvar, $authvalue) = explode("=", $tagvalue);
                            if ($this->core->user->isAuth() && $this->core->user->getVar($authvar) == $authvalue)
                                $ret = true;
                        }
                        break;
                    case "confvar":
                        if (strpos($tagvalue, '=') !== false) {
                            list($confvar, $confvalue) = explode('=', $tagvalue,2);
                            if (strlen($confvar) && $this->get($confvar) == $confvalue)
                                $ret = true;
                        } elseif (strpos($tagvalue, '!=') !== false) {
                            list($confvar, $confvalue) = explode('!=', $tagvalue,2);
                            if (strlen($confvar) && $this->get($confvar) != $confvalue)
                                $ret = true;
                        }
                        break;
                    case "sessionvar":
                        if (strpos($tagvalue, '=') !== false) {
                            list($sessionvar, $sessionvalue) = explode("=", $tagvalue);
                            if (strlen($sessionvar) && $this->core->session->get($sessionvar) == $sessionvalue)
                                $ret = true;
                        }elseif (strpos($tagvalue, '!=') !== false) {
                            list($sessionvar, $sessionvalue) = explode("!=", $tagvalue);
                            if (strlen($sessionvar) && $this->core->session->get($sessionvar) != $sessionvalue)
                                $ret = true;
                        }
                        break;
                    case "servervar":
                        if (strpos($tagvalue, '=') !== false) {
                            list($servervar, $servervalue) = explode("=", $tagvalue);
                            if (strlen($servervar) && $_SERVER[$servervar] == $servervalue)
                                $ret = true;
                        }elseif (strpos($tagvalue, '!=') !== false) {
                            list($servervar, $servervalue) = explode("!=", $tagvalue);
                            if (strlen($servervar) && $_SERVER[$servervar] != $servervalue)
                                $ret = true;
                        }
                        break;
                    case "auth":
                    case "noauth":
                        if (trim(strtolower($tagcode)) == 'auth')
                            $ret = $this->core->user->isAuth();
                        else
                            $ret = !$this->core->user->isAuth();
                        break;
                    case "development":
                        $ret = $this->core->is->development();
                        break;
                    case "production":
                        $ret = $this->core->is->production();
                        break;
                    case "indomain":
                    case "domain":
                        $domains = explode(",", $tagvalue);
                        foreach ($domains as $ind => $inddomain) if (strlen(trim($inddomain))) {
                            if (trim(strtolower($tagcode)) == "domain") {
                                if (strtolower($_SERVER['HTTP_HOST']) == strtolower(trim($inddomain)))
                                    $ret = true;
                            } else {
                                if (isset($_SERVER['HTTP_HOST']) && stripos($_SERVER['HTTP_HOST'], trim($inddomain)) !== false)
                                    $ret = true;
                            }
                        }
                        break;
                    case "interminal":
                        $ret = $this->core->is->terminal();
                        break;
                    case "url":
                    case "noturl":
                        $urls = explode(",", $tagvalue);

                        // If noturl the condition is upsidedown
                        if (trim(strtolower($tagcode)) == "noturl") $ret = true;
                        foreach ($urls as $ind => $url) if (strlen(trim($url))) {
                            if (trim(strtolower($tagcode)) == "url") {
                                if (($this->core->system->url['url'] == trim($url)))
                                    $ret = true;
                            } else {
                                if (($this->core->system->url['url'] == trim($url)))
                                    $ret = false;
                            }
                        }
                        break;
                    case "inurl":
                    case "notinurl":
                        $urls = explode(",", $tagvalue);

                        // If notinurl the condition is upsidedown
                        if (trim(strtolower($tagcode)) == "notinurl") $ret = true;
                        foreach ($urls as $ind => $inurl) if (strlen(trim($inurl))) {
                            if (trim(strtolower($tagcode)) == "inurl") {
                                if ((strpos($this->core->system->url['url'], trim($inurl)) !== false))
                                    $ret = true;
                            } else {
                                if ((strpos($this->core->system->url['url'], trim($inurl)) !== false))
                                    $ret = false;
                            }
                        }
                        break;
                    case "beginurl":
                    case "notbeginurl":
                        $urls = explode(",", $tagvalue);
                        // If notinurl the condition is upsidedown
                        if (trim(strtolower($tagcode)) == "notbeginurl") $ret = true;
                        foreach ($urls as $ind => $inurl) if (strlen(trim($inurl))) {
                            if (trim(strtolower($tagcode)) == "beginurl") {
                                if ((strpos($this->core->system->url['url'], trim($inurl)) === 0))
                                    $ret = true;
                            } else {
                                if ((strpos($this->core->system->url['url'], trim($inurl)) === 0))
                                    $ret = false;
                            }
                        }
                        break;
                    case "inmenupath":
                        $ret = $this->inMenuPath();
                        break;
                    case "notinmenupath":
                        $ret = !$this->inMenuPath();
                        break;
                    case "isversion":
                        if (trim(strtolower($tagvalue)) == 'core')
                            $ret = true;
                        break;
                    case "false":
                    case "true":
                        $ret = trim(strtolower($tagcode))=='true';
                        break;

                    default:
                        $this->core->errors->add('unknown tag: |' . $tagcode . '|');
                        break;
                }
                // If I have found a true, break foreach
                if($ret) break;
            }
            return $ret;
        }

        /**
         * Evalue if the tag is a condition
         * @param $tag
         * @return bool
         */
        private function isAssignationTag($tag)
        {
            $tags = ["webapp", "set", "include", "redirect", "menu","coreversion","env_vars"];
            return in_array(strtolower($tag), $tags);
        }

        /**
         * Execute an assignation based on the tagcode
         * @param $tagcode string
         * @param $tagvalue string
         * @return bool
         */
        private function setAssignationTag($tagcode, $tagvalue, $vars)
        {
            // Asignation tags
            // -----------------
            switch (trim(strtolower($tagcode))) {
                case "webapp":
                    $this->set("webapp", $vars);
                    $this->core->setAppPath($vars);
                    break;
                case "set":
                    $this->set($tagvalue, $vars);
                    break;
                case "include":
                    // Recursive Call
                    $this->readConfigJSONFile($vars);
                    break;
                case "redirect":
                    // Array of redirections
                    if (!$this->core->is->terminal()) {
                        if (is_array($vars)) {
                            foreach ($vars as $ind => $urls)
                                if (!is_array($urls)) {
                                    $this->core->errors->add('Wrong redirect format. It has to be an array of redirect elements: [{orig:dest},{..}..]');
                                } else {
                                    foreach ($urls as $urlOrig => $urlDest) {
                                        if ($urlOrig == '*' || !strlen($urlOrig))
                                            $this->core->system->urlRedirect($urlDest);
                                        else
                                            $this->core->system->urlRedirect($urlOrig, $urlDest);
                                    }
                                }

                        } else {
                            $this->core->system->urlRedirect($vars);
                        }
                    }
                    break;

                case "menu":
                    if (is_array($vars)) {
                        $vars = $this->convertTags($vars);
                        foreach ($vars as $key => $value) {
                            if (!empty($value['path']))
                                $this->pushMenu($value);
                            else {
                                $this->core->logs->add('wrong menu format. Missing path element');
                                $this->core->logs->add($value);
                            }

                        }
                    } else {
                        $this->core->errors->add("menu: tag does not contain an array");
                    }
                    break;
                case "coreversion":
                    if($this->core->_version!= $vars) {
                        die("config var 'CoreVersion' is '{$vars}' and the current cloudframework version is {$this->core->_version}. Please update the framework. composer.phar update");
                    }
                    break;
                case "env_vars":
                    if(!is_array($vars))  die("config var 'env_vars:' has to be an array");
                    if(!isset( $this->data['env_vars']) || !is_array( $this->data['env_vars']))  $this->data['env_vars'] = [];
                    $this->data['env_vars'] = array_merge($this->data['env_vars'],$vars);
                    break;
                default:
                    $this->core->errors->add('unknown tag: |' . $tagcode . '|');
                    break;
            }
        }

        /**
         * Convert tags inside a string or object
         * @param $data mixed
         * @return mixed|string
         */
        public function convertTags($data)
        {
            $_array = is_array($data);

            // Convert into string if we received an array
            if ($_array) $data = json_encode($data);
            // Tags Conversions
            $data = str_replace('{{rootPath}}', $this->core->system->root_path, $data);
            $data = str_replace('{{appPath}}', $this->core->system->app_path, $data);
            $data = str_replace('{{lang}}', $this->lang, $data);
            while (strpos($data, '{{confVar:') !== false) {
                list($foo, $var) = explode("{{confVar:", $data, 2);
                list($var, $foo) = explode("}}", $var, 2);
                $data = str_replace('{{confVar:' . $var . '}}', $this->get(trim($var)), $data);
            }
            // Convert into array if we received an array
            if ($_array) $data = json_decode($data, true);
            return $data;
        }

        /**
         * Allow to read config env_vars taking it from GCP secrets.
         * The secret has to have a JSON structure like { "env_vars:" {"key1":"value1","key2":"value2"}}
         * the result is stored in $this->data['env_vars'].
         *
         * The result is cached.
         * The config variables needed are:
         *   - core.gcp.secrets.env_vars: name of the secret in GCP
         *   - core.gcp.secrets.cache_path: If the cache is not in memory specify the directory to store the secrets
         *   - core.gcp.secrets.cache_encrypt_key: To encryt the data in cache you can define a config-var key
         *   - core.gcp.secrets.cache_encrypt_iv: To encryt the data in cache you can define a config-var iv
         *   - @param string $cache_secret_key  optional secret key. If not empty it will encrypt the data en cache
         *   - @param string $cache_secret_iv  optional secret key. If not empty it will encrypt the data en cache         *
         * @param $gpc_secret_id string project id in gcp.secrets. if empty it will take $this->get('core.gcp.secrets.env_vars')
         * @param $gpc_secret_id string secret id in gcp.secrets. if empty it will take $this->get('core.gcp.secrets.env_vars')
         * @param $reload boolean false by default. if true, force to read it from gcp.secrets
         * @return boolean
         */
        function readEnvVarsFromGCPSecrets($gpc_project_id = '',$gpc_secret_id = '', $reload=false) {

            //region CHECK $gpc_secret_id
            if(!$gpc_project_id) $gpc_project_id = ($this->get('core.gcp.secrets.project_id'))?:$this->core->gc_project_id;
            if(!$gpc_secret_id) $gpc_secret_id = $this->get('core.gcp.secrets.env_vars');
            if(!$gpc_project_id) return $this->core->logs->add('Missing $gpc_project_id and core.gcp.secrets.project_id config var','error_readEnvVarsFromGCPSecrets');
            if(!$gpc_secret_id) return $this->core->logs->add('Missing $secret_id and core.gcp.secrets.env_vars config var','error_readEnvVarsFromGCPSecrets');
            //endregion

            //region CHECK local_config.json if this.core.is.development to avoid read from secret_id
            if($this->core->is->development()
                && is_file($this->core->system->root_path.'/local_config.json')
                && isset($this->data['env_vars'] )) {
                $this->core->logs->add('readEnvVarsFromGCPSecrets avoided because local_config.json->["env_vars"] exists');
                return true;
            }
            //endregion


            //region INIT $this->data['env_vars'] and CHECK $this->core->gc_project_id, $this->core->gc_project_service
            if(!isset($this->data['env_vars']) || !is_array($this->data['env_vars']))
                $this->data['env_vars'] = [];

            if(!$this->core->gc_project_id) {
                $this->core->logs->add('Missing $this->core->gc_project_id','error_readEnvVarsFromGCPSecrets');
                return false;
            }

            if(!$this->core->gc_project_service) {
                $this->core->logs->add('Missing $this->core->gc_project_service','error_readEnvVarsFromGCPSecrets');
                return false;
            }
            //endregion

            // read data from cache and encrypt data
            $key ="{$gpc_project_id}_{$gpc_secret_id}_core.gcp.secrets.env_vars";
            $env_vars = $this->getCache($key);

            // for local development force reload if local_env_vars.json does not exist
            if($this->core->is->development() && !is_file($this->core->system->root_path.'/local_env_vars.json')) {
                $reload=true;
            }

            //region READ env_vars data from cache or from GPC Secret service
            if($reload || !$env_vars || !isset($env_vars['env_vars:']) || !$env_vars['env_vars:']) {

                // performance init
                $this->core->__p->add('GoogleSecrets', $gpc_secret_id, 'note');

                // INSTANCE OF GoogleSecrets init
                /** @var GoogleSecrets $gs */
                $gs = $this->core->loadClass('GoogleSecrets');
                if($gs->error) {
                    $this->core->logs->add($gs->errorMsg,'error_readEnvVarsFromGCPSecrets');
                    return false;
                }

                // READ secret FROM gcp.secrets
                $secrets = $gs->getSecret($this->get('core.gcp.secrets.env_vars'));
                if($gs->error) {
                    $this->core->logs->add($gs->errorMsg,'error_readEnvVarsFromGCPSecrets');
                    return false;
                }
                // performane end
                $this->core->__p->add('GoogleSecrets', '', 'endnote');
                $this->core->logs->add('GCP Secrets service used: core.gcp.secrets.env_vars='.$this->get('core.gcp.secrets.env_vars'));


                if(!$secrets) {
                    $this->core->logs->add('the secret is empty','error_readEnvVarsFromGCPSecrets');
                    return false;
                }

                $env_vars = json_decode($secrets,true);
                if(!$env_vars || !isset($env_vars['env_vars:']) || !$env_vars['env_vars:']) {
                    $this->core->logs->add('the secret does not have a right json structure','error_readEnvVarsFromGCPSecrets');
                    return false;
                }


                // For development in localhost
                if($this->core->is->development() && isset($env_vars['env_vars:'])) {
                    $local_envars = [];

                    // If file env_vars.json exists, read it to update with secrets
                    if(is_file($this->core->system->root_path.'/local_env_vars.json')) {
                        $f_env_vars = file_get_contents($this->core->system->root_path.'/local_env_vars.json');
                        $f_env_vars = json_decode($f_env_vars,true);
                        if(is_array($f_env_vars)) $local_envars+=$f_env_vars;
                    }

                    $local_envars['gpc.secrets.loaded.id:'] = $this->get('core.gcp.secrets.env_vars');
                    $local_envars['gpc.secrets.loaded.date:'] = date('Y-m-d H:i:s');
                    $local_envars['gpc.secrets.loaded.env_vars:'] = $env_vars['env_vars:'];
                    foreach ($env_vars['env_vars:'] as $key_local=>$val) {
                        $local_envars['gpc.secrets.loaded.env_vars:'][$key_local]='******';
                    }
                    file_put_contents($this->core->system->root_path.'/local_env_vars.json',json_encode($local_envars,JSON_PRETTY_PRINT));
                    $this->core->logs->add('local_env_vars.json created');
                }

                $this->updateCache($key,$env_vars);
                $this->core->logs->add('Core7.CoreConfig data cached');

            }
            //endregion


            //region Merge this->data['env_vars'] with env_vars['env_vars:']
            if($env_vars && isset($env_vars['env_vars:']) && $env_vars['env_vars:']) {
                $this->data['env_vars'] = array_merge($this->data['env_vars'],$env_vars['env_vars:']);
            }
            //endregion

            return true;

        }

        /**
         * Return an environment var from getenv($var) or from a GCP Secret
         * $this->get('core.gcp.secrets.env_vars') in the $this->get('core.gcp.secrets.project_id')
         * @param $var
         * @param string $gcp_project_id optional GCP projectId where the secrets are stored. By default this value is got from $this->get('core.gcp.secrets.project_id')
         * @param string $gcp_secret_id  optional GCP sercretId where env_vars are stored in JSON format. By default this value is got from $this->get('core.gcp.secrets.env_vars')
         * @return mixed|null if the env var $var exist it returns the contenct and it can be any type
         */
        public function getEnvVar($var,$gcp_project_id='',$gcp_secret_id='') {

            //region RETURN getenv($var) if it exist
            if(getenv($var)) return(getenv($var));
            //endregion

            //region ELSE RETURN $this->data['env_vars'][$var] if exists reading it from $this->readEnvVarsFromGCPSecrets
            if($gcp_project_id) $this->set('core.gcp.secrets.project_id',$gcp_project_id);
            if($gcp_secret_id) $this->set('core.gcp.secrets.env_vars',$gcp_secret_id);
            if(!isset($this->data['env_vars']) && $this->get('core.gcp.secrets.env_vars')) $this->readEnvVarsFromGCPSecrets($gcp_project_id,$gcp_secret_id);

            // Return $this->data['env_vars'][$var] if it exists
            if(isset($this->data['env_vars'][$var])) return $this->data['env_vars'][$var];
            //endregion

            //region ELSE RETURN null
            return null;
            //endregion

        }


        /**
         * Reset Cache of the module
         * @param string $cache_secret_key optional param to use a specific secret_key. Default value is $this->cache_secret_key
         * @param string $cache_secret_iv optional param to use a specific secret_iv. Default value is $this->cache_secret_iv
         */
        public function readCache(string $cache_secret_key='', string $cache_secret_iv='') {

            if(!$cache_secret_key) $cache_secret_key = $this->cache_secret_key;
            if(!$cache_secret_iv) $cache_secret_iv = $this->cache_secret_iv;
            if($this->cache === null) {
                $this->cache = $this->core->cache->get('Core7.CoreConfig', -1,'',$cache_secret_key,$cache_secret_iv);
                if(!$this->cache) $this->cache=[];
            }
        }

        /**
         * Reset Cache of the module
         * @param string $cache_secret_key optional param to use a specific secret_key. Default value is $this->cache_secret_key
         * @param string $cache_secret_iv optional param to use a specific secret_iv. Default value is $this->cache_secret_iv
         */
        public function resetCache(string $cache_secret_key='',string $cache_secret_iv='') {
            $this->cache = [];
            if(!$cache_secret_key) $cache_secret_key = $this->cache_secret_key;
            if(!$cache_secret_iv) $cache_secret_iv = $this->cache_secret_iv;
            $this->core->cache->set('Core7.CoreConfig',$this->cache,null,$cache_secret_key,$cache_secret_iv);
        }

        /**
         * Update Cache of the module
         * @param $var
         * @param $data
         * @param string $cache_secret_key optional param to use a specific secret_key. Default value is $this->cache_secret_key
         * @param string $cache_secret_iv optional param to use a specific secret_iv. Default value is $this->cache_secret_iv
         */
        public function updateCache($var,$data,string $cache_secret_key='',string $cache_secret_iv='') {

            if(!$cache_secret_key) $cache_secret_key = $this->cache_secret_key;
            if(!$cache_secret_iv) $cache_secret_iv = $this->cache_secret_iv;
            $this->readCache($cache_secret_key,$cache_secret_iv);
            $this->cache[$var] = $data;
            $this->core->cache->set('Core7.CoreConfig',$this->cache,null,$cache_secret_key,$cache_secret_iv);
        }

        /**
         * Get var Cache of the module
         * @param string $key key to get from cache
         * @param string $cache_secret_key optional param to use a specific secret_key. Default value is $this->cache_secret_key
         * @param string $cache_secret_iv optional param to use a specific secret_iv. Default value is $this->cache_secret_iv
         */
        public function getCache(string $key,string $cache_secret_key='',string $cache_secret_iv='') {
            $this->readCache($cache_secret_key,$cache_secret_iv);
            if(isset($this->cache[$key])) return $this->cache[$key];
            else return null;
        }

    }

    /**
     * Class to manage the security access and dynamic getenv variables
     * @package Core
     */
    class CoreSecurity
    {
        private $core;
        /* @var $dsToken DataStore */
        private $dsToken = null;

        var $error = false;
        var $errorMsg = [];
        var $cache = null;
        var $cache_key = null;
        var $cache_iv = null;
        var $secret_vars = null;


        function __construct(Core7 &$core)
        {
            $this->core = $core;
            $this->cache_key = ($this->core->config->get('core.gcp.secrets.cache_encrypt_key'))?:'T8K1Ogtl5E9R9CDbWIdV6Vs4yBY4';
            $this->cache_iv = ($this->core->config->get('core.gcp.secrets.cache_encrypt_iv'))?:'iveTFs7++f9niowHcuafMzTeKLG4X';
        }

        /**
         * Assgin a $secret_value to the secret $secret_key
         * @param $secret_key
         * @param $secret_value
         */
        public function setSecretVar($secret_key,$secret_value) {
            $this->secret_vars['secrets'][$secret_key] =$secret_value;
        }

        /**
         * Return the secret value of $secret_key
         * @param $secret_key
         * @return mixed|null
         */
        public function getSecretVar($secret_key) {
            return $this->secret_vars['secrets'][$secret_key] ?? null;
        }

        /**
         * Return $this->secret_vars
         * @param $secret_key
         * @return mixed|null
         */
        public function getSecretVars() {
            return $this->secret_vars;
        }

        /**
         * Check if exists a Basic Authorizatino header
         * @return boolean tellong
         */
        function existBasicAuth()
        {
            return (isset($_SERVER['PHP_AUTH_USER']) && strlen($_SERVER['PHP_AUTH_USER'])
                || (isset($_SERVER['HTTP_AUTHORIZATION']) && strpos(strtolower($_SERVER['HTTP_AUTHORIZATION']), 'basic') === 0)
            );
        }

        /**
         * Return an array with [user,password] from PHP_AUTH_USER, PHP_AUTH_PW or if they don't exist HTTP_AUTHORIZATION header
         * @return
         */
        function getBasicAuth()
        {
            $username = null;
            $password = null;
            // mod_php
            if (isset($_SERVER['PHP_AUTH_USER'])) {
                $username = $_SERVER['PHP_AUTH_USER'];
                $password = $_SERVER['PHP_AUTH_PW'];
                // most other servers
            } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                if (strpos(strtolower($_SERVER['HTTP_AUTHORIZATION']), 'basic') === 0)
                    list($username, $password) = explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
            }
            return ([$username, $password]);
        }

        /**
         * Verify user,password with Basic Authorization Header from  $this->getBasicAuth()
         */
        function checkBasicAuth($user, $passw)
        {
            list($username, $password) = $this->getBasicAuth();
            return (!is_null($username) && $user == $username && $passw == $password);
        }

        function existBasicAuthConfig()
        {
            return is_array($this->core->config->get('authorizations'));
        }

        function checkBasicAuthWithConfig()
        {
            $ret = false;
            list($user, $passw) = $this->getBasicAuth();

            // Kee backwards compatibility:
            if($this->core->config->get('authorizations') && !$this->core->config->get('core.system.authorizations'))
                $this->core->config->set('core.system.authorizations',$this->core->config->get('authorizations'));


            if ($user === null) {
                $this->core->logs->add('checkBasicAuthWithConfig: No Authorization in headers ');
            } elseif (!is_array($auth = $this->core->config->get('core.system.authorizations'))) {
                $this->core->logs->add('checkBasicAuthWithConfig: no "core.system.authorizations" array in config. ');
            } elseif (!isset($auth[$user])) {
                $this->core->logs->add('checkBasicAuthWithConfig: key  does not match in "core.system.authorizations"');
            } elseif (!$this->core->system->checkPassword($passw, ((isset($auth[$user]['password']) ? $auth[$user]['password'] : '')))) {
                $this->core->logs->add('checkBasicAuthWithConfig: password does not match in "core.system.authorizations"');

                // User and password match!!!
            } else {
                $ret = true;
                // IPs Security
                if (isset($auth[$user]['ips']) && strlen($auth[$user]['ips'])) {
                    if (!($ret = $this->checkIPs($auth[$user]['ips']))) {
                        $this->core->logs->add('checkBasicAuthWithConfig: IP "' . $this->core->system->ip . '" not allowed');
                    }
                }
            }

            // Return the array of elements it passed
            if ($ret) {
                $auth[$user]['_BasicAuthUser_'] = $user;
                $ret = $auth[$user];
            }
            return $ret;
        }

        /*
         * API KEY
         */
        function existWebKey()
        {
            return (isset($_GET['web_key']) || isset($_POST['web_key']) || strlen($this->getHeader('X-WEB-KEY')));
        }

        /**
         * Read a Development SubKeys to encrypt locally the cache where the secrets will be stores
         * In production these subkeys are cached to increase performance and because the risk who someone have access to Cache server is low. It rotates every day.
         * @param string $erp_platform_id if empty it will try to get it from $this->core->config->get('core.erp.platform_id')
         * @param string $erp_user if empty it will try to get it from $this->core->config->get('core.erp.user_id.'.$erp_platform_id)
         * @return bool|void
         */
        public function readERPDeveloperEncryptedSubKeys($erp_platform_id='',$erp_user='')
        {
            //region CHECK $erp_platform_id value
            if(!$erp_platform_id || !is_string($erp_platform_id)) {
                $erp_platform_id = $this->core->config->get('core.erp.platform_id');
                if(!$erp_platform_id) return($this->addError('readERPDeveloperEncryptedSubKeys(..) missing function-var($erp_platform_id) or config-var(core.erp.platform_id)'));
            }
            //endregion

            //region CHECK $erp_user value
            if(!$erp_user || !is_string($erp_user)) {
                $erp_user = $this->core->config->get('core.erp.user_id.'.$erp_platform_id);
                if(!$this->core->config->get('core.erp.platform_id') || $this->core->config->get('core.erp.platform_id')!=$erp_platform_id) {
                    $erp_user = $this->core->security->getGoogleEmailAccount();
                    if($this->core->is->development() && $this->core->is->script()) echo '  - Read $this->core->security->getGoogleEmailAccount() in readERPDeveloperEncryptedSubKeys()';
                }
                if(!$erp_user) return($this->addError('readERPDeveloperEncryptedSubKeys(..) missing function-var($erp_user) or config-var(core.erp.user_id.'.$erp_platform_id.'.)'));
            }
            //endregion

            //region SET $url, $key_cache, $keys=[];
            $url = 'https://api.cloudframework.io/core/secrets/'.$erp_platform_id.'/my-daily-encryption-subkeys/'.$erp_user;
            $key_cache = $erp_platform_id.'/my-daily-encryption-subkeys/'.$erp_user;
            $keys = [];
            //endregion

            //region GET from cache $keys if we are in production servers
            if($this->core->is->production())
                $keys = $this->core->cache->get($key_cache,3600*24,date('Y-m-d'));
            else
                $keys = $this->core->session->get($key_cache);
            //endregion

            //region CALL $url and SET $keys if empty $keys
            if(!$keys) {
                //region SET $headers and call $url
                $headers = ['X-WEB-KEY'=>$erp_user];
                $keys = $this->core->request->get_json_decode($url,null,$headers);
                if($this->core->request->error) return($this->addError('Error in developer license for '.$erp_user.': '.$keys['message'].' '));
                //endregion

                //region SAVE from cache $keys if we are in production servers
                if($this->core->is->production())
                    $this->core->cache->set($key_cache,$keys,date('Y-m-d'));
                else
                    $this->core->session->set($key_cache,$keys);
                //endregion
            }
            //endregion

            //region ADD $keys to  $this->cache_key and $this->cache_iv
            if(!isset($keys['data']['key']) || !isset($keys['data']['iv'])) return($this->addError('Error in CloudFramework Service to retrieve subkeys. key or iv is missing'));
            $this->cache_key.=$keys['data']['key'];
            $this->cache_iv.=$keys['data']['iv'];
            //endregion

            return true;
        }

        /**
         * GET from CloudFramework ERP Secrets of user is running the script or the GCP appengine,cloudfuntion,computeengine
         * If the user is running in localhost it will prompted
         * If the GCP engine is running it will use the Token of the Instance
         * @param string $erp_platform_id
         * @param string $erp_user User to use in the autentication. If empty it will prompt in the terminal if you are an script
         */
        function readMyERPSecretsVars($erp_platform_id='',$erp_user='') {

            //region CHECK $erp_platform_id value
            if(!$erp_platform_id || !is_string($erp_platform_id)) {
                $erp_platform_id = $this->core->config->get('core.erp.platform_id');
                if(!$erp_platform_id) return($this->addError('readERPSecretVars(..) missing function-var($erp_platform_id) or config-var(core.erp.platform_id)'));
            }
            //endregion

            //region CHECK $erp_user value
            if(!$erp_user || !is_string($erp_user)) {
                $erp_user = $this->core->config->get('core.erp.user_id.'.$erp_platform_id);
                if(!$this->core->config->get('core.erp.platform_id') || $this->core->config->get('core.erp.platform_id')!=$erp_platform_id) {
                    $erp_user = $this->core->security->getGoogleUserEmailAccount();
                }
                if(!$erp_user) return($this->addError('readERPSecretVars(..) missing function-var($erp_platform_id) or config-var(core.erp.platform_id)'));
            }
            //endregion

            return($this->readERPSecretVars($erp_user,$erp_platform_id,$erp_user));
        }

        /**
         * Return a specific secret var form ERP
         * @param $var
         * @param string $erp_secret_id
         * @param string $erp_platform_id
         * @param string $erp_user
         */
        public function getERPSecretVar($var, string $erp_secret_id='', string $erp_platform_id='', string $erp_user='') {
            if($this->secret_vars ===null) if(!$this->readERPSecretVars($erp_secret_id,$erp_platform_id,$erp_user)) return;
        }

        /**
         * Return a secret var stored in CloudFramework Secret Manager through the ERP/BPA
         * $this->get('core.gcp.secrets.env_vars') in the $this->get('core.gcp.secrets.project_id')
         * @param string $var name of the var contained in the secret
         * @param string optional $cf_secret_id CF secretId defined in the ERP. Default value is $this->get('core.erp.secrets.secret_id')
         * @param string optional $cf_secret_token CF secretToken defined in the ERP. Default value is $this->get('core.erp.secrets.secret_token'
         * @param string optional $platform_id of the ERP. Default value is $this->get('core.erp.platform_id'
         * @param string optional $user_token User token when is required from the following command: gcloud auth print-access-token --account={{personal_user}}
         * @return mixed|null if the env var $var exist it returns the contenct and it can be any type
         */
        public function readERPSecretVars($erp_secret_id='',$erp_platform_id='',$erp_user='') {

            //region CHECK $erp_platform_id value
            if(!$erp_secret_id || !is_string($erp_secret_id)) {
                $erp_secret_id = $this->core->config->get('core.erp.secrets.secret_id');
                if(!$erp_secret_id) return($this->addError('readERPSecretVars(..) missing function-var($erp_secret_id) or config-var(core.erp.secrets.secret_id)'));
            }
            //endregion

            //region CHECK $erp_platform_id value
            if(!$erp_platform_id || !is_string($erp_platform_id)) {
                $erp_platform_id = $this->core->config->get('core.erp.platform_id');
                if(!$erp_platform_id) return($this->addError('readERPSecretVars(..) missing function-var($erp_platform_id) or config-var(core.erp.platform_id)'));
            }
            //endregion

            //region CHECK $erp_user value
            if(!$erp_user || !is_string($erp_user)) {
                $erp_user = $this->core->config->get('core.erp.user_id.'.$erp_platform_id);
                if(!$this->core->config->get('core.erp.platform_id') || $this->core->config->get('core.erp.platform_id')!=$erp_platform_id) {
                    $erp_user = $this->core->security->getGoogleEmailAccount();
                }
                if(!$erp_user) return($this->addError('readERPSecretVars(..) missing function-var($erp_platform_id) or config-var(core.erp.platform_id)'));
            }
            //endregion

            //region READ $user_secrets from cache and RETURN it if it exist
            $key = 'getMyERPSecrets_'.$this->core->gc_project_id.'_'.$erp_platform_id.'_'.$erp_secret_id;
            $user_secrets = $this->getCache($key,'ERP.secrets');

            if($erp_user && isset($user_secrets['id']) && $user_secrets['id']!=$erp_user) $user_secrets=[];
            if($user_secrets){
                $this->secret_vars = ['id'=>$user_secrets['id'],'secret-id'=>$user_secrets['secret-id'],'secrets'=>$user_secrets['secrets']];
                return true;
            }
            //endregion

            //region IF $user_secrets is empty feed it with basic structure
            $user_secrets = ['access_token'=>null,'id'=>'','platform'=>$erp_platform_id,'secrets'=>''];
            //endregion

            //region SET $token from User or GCP Engine Instance Token
            $token = $this->getGoogleIdentityToken($erp_user);
            if(!$token) return($this->addError('CoreSecurity.readERPSecretVars() has returned an error calling $this->getGoogleIdentityToken($erp_user)'));
            //endregion

            //region VERIFY $token and SET $user_secrets['id'] and $user_secrets['token']
            $token_info = $this->getGoogleTokenInfo($token);
            if(isset($token_info['error'])) return($this->addError($token_info));
            // If the token_info does not contains email attribute the we trust the email sent in the header.
            if(!isset($token_info['email']) && !isset($token_info['sub'])) return($this->addError('CoreSecurity.getMyERPSecrets() has not got a token with email or $token attributes'));

            $user_secrets['id'] = (isset($token_info['email']))?$token_info['email']:$token_info['sub'];
            $user_secrets['token'] = $token_info;
            //endregion

            //region CALL secret CF API and set $user_secrets['secrets']
            if($erp_secret_id!=$user_secrets['id']){
                $url = 'https://api.cloudframework.io/core/secrets/'.$erp_platform_id.'/secret-with-my-token/'.$user_secrets['id'].'/'.$erp_secret_id;
            }
            else{
                $url = 'https://api.cloudframework.io/core/secrets/'.$erp_platform_id.'/my-secrets/'.$user_secrets['id'];
            }

            $headers = ['X-WEB-KEY'=>$user_secrets['id'],'X-DS-TOKEN'=>$token];
            $secrets = $this->core->request->get_json_decode($url,null,$headers);

            if($this->core->request->error) {
                return($this->addError($this->core->request->errorMsg));
                $this->core->request->reset();
                $this->core->errors->reset();
            }
            $user_secrets['secret-id'] = ($erp_secret_id)?:$user_secrets['id'];
            $user_secrets['secrets'] = $secrets['data']['secrets'];
            //endregion

            //region UPDATE cache
            $this->updateCache($key,$user_secrets,'ERP.secrets');
            //endregion

            //region RETURN $user_secrets
            $this->secret_vars = ['id'=>$user_secrets['id'],'secret-id'=>$user_secrets['secret-id'],'secrets'=>$user_secrets['secrets']];
            return true;
            //endregion

        }

        /**
         * Reset the cache for the ERP Secrets
         */
        public function resetERPCache() {
            return $this->resetCache('ERP.secrets');
        }

        /**
         * Execute a user Prompt
         * @param $title
         * @param null $default
         * @return false|string|null
         */
        function prompt($title,$default=null) {
            // Check default value
            if($default) $title.="[{$default}] ";
            $ret = readline($title);
            if(!$ret) $ret=$default;
            return $ret;
        }

        /**
         * Get the Google user email that the terminal or the instance (appengine, computeengine, or user localhost..) is using.
         */
        function getGoogleEmailAccount() {
            if($this->core->is->development()) {
                $gcloud_auth_list = 'gcloud auth list 2>/dev/null';
                $auth_list = shell_exec($gcloud_auth_list);
                if(!$auth_list) return($this->addError("'{$gcloud_auth_list}' has produced an error. Install gcloud or check gcloud auth login"));
                $lines = explode("\n",$auth_list);
                array_shift($lines);
                if(!isset($lines[0]) || strpos($lines[0],'ACTIVE')===false) return($this->addError("'{$gcloud_auth_list}' has no active account. Execute gcloud auth login"));
                array_shift($lines);
                $user='';
                do {
                    if(strpos($lines[0],'*')===0) {
                        $user = preg_replace('/[\* ]/','',trim($lines[0]));
                    }
                    array_shift($lines);
                } while($lines && !$user);
                if(!$user) return($this->addError("getGoogleUserEmailAccount() has not found an active user with '{$gcloud_auth_list}'. Verify the programming of this method"));
                return $user;
            } else {
                try {
                    $metadata = new Google\Cloud\Core\Compute\Metadata();
                    $user = explode('/',$metadata->get('instance/service-accounts'))[0];
                    return $user;
                } catch (Exception $e) {
                    return($this->addError("getGoogleUserEmailAccount() has produced an error in metadata call: ".$e->getMessage()));
                }
            }
        }

        /**
         * Prompt the user to generate a token
         * @param string $user this should be passed when you are working in a script or development environment
         * @return array|voud
         * {
            "access_token": "ya29.****",
            "expires_in": 1799,
            "token_type": "Bearer"
            }
         */
        function getGoogleAccessToken(string $user='') {
            if($this->core->is->development()) {
                $gcloud_token_command = 'gcloud auth print-access-token';
                if($user) $gcloud_token_command.=' --account='.$user;
                $token = shell_exec($gcloud_token_command);
                if(!$token) return($this->addError('CoreSecurity.getGoogleUserAccessToken(..) The following command does not work: '.$gcloud_token_command));
                $token = ['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>0];
            } else {
                try {
                    $url = 'instance/service-accounts/default/token';
                    $metadata = new Google\Cloud\Core\Compute\Metadata();
                    $token =  json_decode($metadata->get($url),true);
                } catch (Exception $e) {
                    return($this->addError("CoreSecurity.getGoogleUserAccessToken(..) has produced an error calling {$url}: ".$e->getMessage()));
                }
            }
            return($token);
        }

        /**
         * Get an Identity token for the $user
         * https://cloud.google.com/compute/docs/instances/verifying-instance-identity#curl
         * https://cloud.google.com/compute/docs/instances/verifying-instance-identity#token_format
         * @param string $user this should be passed when you are working in a script or development environment
         * @param string $audience this is the audience added to the token for more security. Only valid for GAE service account
         * @return string|void example: eyJhbGciOiJSUzI1NiIsImtpZCI6I....
         */
        function getGoogleIdentityToken($user='',$audience='https//api.cloudframework.io') {
            if($this->core->is->development()) {
                $gcloud_token_command = 'gcloud auth print-identity-token';
                if($audience) {
                    if(strpos($user,'compute@developer.gserviceaccount.com'))
                        $gcloud_token_command.=' --audiences='.$audience;
                }
                if($user) {
                    $gcloud_token_command .= ' --account=' . $user;
                    if(strpos($user,'compute@developer.gserviceaccount.com')) {
                        $gcloud_token_command.=' --token-format=full';
                    }
                }
                $token = shell_exec($gcloud_token_command);
                if(!$token) return($this->addError('CoreSecurity.getGoogleIdentityToken(...) The following command does not work: '.$gcloud_token_command));
            } else {
                try {
                    $url='instance/service-accounts/default/identity?format=full&licenses=true';
                    if($audience) $url.='&audience='.$audience;
                    $metadata = new Google\Cloud\Core\Compute\Metadata();
                    $token = $metadata->get($url);
                    if(!$token) return($this->addError('CoreSecurity.getGoogleIdentityToken(...)  Error calling The following url does not work: '.$url));
                } catch (Exception $e) {
                    return($this->addError('CoreSecurity.getGoogleIdentityToken(...)  Error calling The following url does not work: '.$url));
                }
            }
            return($token);
        }

        /**
         * Return info about an Access Token or Identity Tokens
         * This function call getGoogleAccessTokenInfo($token) or getGoogleIdentityTokenInfo($token) depending on $token format
         * @param $token
         * @return array|void
         */
        function getGoogleTokenInfo($token) {

            // IF IT IS AN ACCESS TOKEN
            if(strpos($token,'ya29.')===0) {
                return($this->getGoogleAccessTokenInfo($token));
            }
            // ELSE THEN IT HAS TO BE AN IDENTITY TOKEN
            // https://developers.google.com/identity/gsi/web/guides/verify-google-id-token
            else {
                return($this->getGoogleIdentityTokenInfo($token));
            }
        }

        /**
         * Retrieve info about a Google Access Token
         * For example you can generate a localhost token using: gcloud auth print-access-token --account={{personal_email_user}}
         * For example you can instance token with:
         *         $metadata = new Google\Cloud\Core\Compute\Metadata();
         *         $ret = json_decode($metadata->get('instance/service-accounts/default/token'),true)['access_token'];
         * @param $token
         * @return mixed|string
         * appengine output {
            "issued_to": "anonymous",
            "audience": "anonymous",
            "scope": "https://www.googleapis.com/auth/trace.append https://www.googleapis.com/auth/monitoring.write https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/logging.write https://www.googleapis.com/auth/cloud_debugger https://www.googleapis.com/auth/cloud-platform https://www.googleapis.com/auth/devstorage.full_control https://www.googleapis.com/auth/appengine.apis",
            "expires_in": 1408,
            "email": "cloudframework-io@appspot.gserviceaccount.com",
            "verified_email": true,
            "access_type": "online"
            },
         * compute engine script {
            "aud": "32555940559.apps.googleusercontent.com",
            "azp": "107073846750350635781",
            "email": "173191905033-compute@developer.gserviceaccount.com",
            "email_verified": true,
            "exp": 1638615700,
            "google": {
                "compute_engine": {
                    "instance_creation_timestamp": 1608593543,
                    "instance_id": "5265569244953019153",
                    "instance_name": "bnext-spain-etl-machine",
                    "license_id": [
                    "5926592092274602096"
                    ],
                    "project_id": "bnext-cloud",
                    "project_number": 173191905033,
                    "zone": "europe-west1-b"
                }
            },
            "iat": 1638612100,
            "iss": "https://accounts.google.com",
            "sub": "107073846750350635781"
            }
         */
        function getGoogleAccessTokenInfo($token)
        {
            $token_info = $this->core->request->post_json_decode('https://www.googleapis.com/oauth2/v1/tokeninfo',['access_token'=>$token],['Content-Type'=>'application/x-www-form-urlencoded']);
            if($this->core->request->error) {
                $this->addError((isset($token_info['error']))?$token_info:$this->core->request->errorMsg);
                if(!isset($token_info['error_description'])) {
                    $token_info['error_description'] = 'unknown';
                } else {
                    $this->core->errors->reset();
                }
            }
            if(isset($token_info['error'])) $token_info['code'] = $this->core->request->getLastResponseCode();
            $this->core->request->reset();
            return $token_info;
        }

        /**
         * Retrieve info about a Google Identity Token
         * https://developers.google.com/identity/gsi/web/guides/verify-google-id-token
         * https://cloud.google.com/compute/docs/instances/verifying-instance-identity#token_format
         * @param $token
         * @return array|boolean|null if the token is valid returns an array otherwise false. If there is a service error it returns null and addError in the class
         * appengine output example{
            "aud": "https://api7.cloudframework.io",
            "azp": "105263613482625024225",
            "email": "cloudframework-io@appspot.gserviceaccount.com",
            "email_verified": true,
            "exp": 1638601791,
            "iat": 1638598191,
            "iss": "https://accounts.google.com",
            "sub": "105263613482625024225"
            },
         * computeengine script example{
            "aud": "32555940559.apps.googleusercontent.com",
            "azp": "107073846750350635781",
            "exp": 1638609030,
            "iat": 1638605430,
            "iss": "https://accounts.google.com",
            "sub": "107073846750350635781"
            },
         * user localhost example{
            "iss": "https://accounts.google.com",
            "azp": "32555940559.apps.googleusercontent.com",
            "aud": "32555940559.apps.googleusercontent.com",
            "sub": "116008531197812823243",
            "hd": "adrianmm.com",
            "email": "info@adrianmm.com",
            "email_verified": true,
            "at_hash": "LgKsYP8U6NcmstQR1LgO7w",
            "iat": 1638611898,
            "exp": 1638615498
            }
         */
        function getGoogleIdentityTokenInfo($token)
        {
            try {
                $client = new Google_Client();
                $token_info = $client->verifyIdToken($token);
                return $token_info;
            } catch (Exception $e) {
                $this->addError($e->getMessage());
                return null;
            }

        }

        /**
         * Read $_GET['web-key'] or HEADER X-WEB-KEY
         * @return mixed|string
         */
        function getWebKey()
        {
            if (isset($_GET['web_key'])) return $_GET['web_key'];
            else if (isset($_POST['web_key'])) return $_POST['web_key'];
            else if (strlen($this->getHeader('X-WEB-KEY'))) return $this->getHeader('X-WEB-KEY');
            else return '';
        }

        /**
         * @param null $keys
         * @return array|false|mixed
         */
        function checkWebKey($keys = null)
        {

            // If I don't have the credentials in keys I try to check if CLOUDFRAMEWORK-WEB-KEYS is defined.
            if (null === $keys) {
                $keys = $this->core->config->get('CLOUDFRAMEWORK-WEB-KEYS');
                if (!is_array($keys)) return false;
            }

            // Analyzing $keys
            if (!is_array($keys)) $keys = [[$keys, '*']];
            else if (!is_array($keys[0])) $keys = [$keys];
            $web_key = $this->getWebKey();

            if (strlen($web_key))
                foreach ($keys as $key) {
                    if ($key[0] == $web_key) {
                        if (!isset($key[1])) $key[1] = "*";
                        if ($key[1] == '*') return $key;
                        elseif (!strlen($_SERVER['HTTP_ORIGIN'])) return false;
                        else {
                            $allows = explode(',', $key[1]);
                            foreach ($allows as $host) {
                                if (preg_match('/^.*' . trim($host) . '.*$/', $_SERVER['HTTP_ORIGIN']) > 0) return $key;
                            }
                            return false;
                        }
                    }
                }
            return false;
        }

        function existServerKey()
        {
            return (strlen($this->getHeader('X-SERVER-KEY')) > 0);
        }

        function getServerKey()
        {
            return $this->getHeader('X-SERVER-KEY');
        }

        function checkServerKey($keys=null)
        {
            // If I don't have the credentials in keys I try to check if CLOUDFRAMEWORK-SERVER-KEYS is defined.
            if (null === $keys) {
                $keys = $this->core->config->get('CLOUDFRAMEWORK-SERVER-KEYS');
                if (!is_array($keys)) return false;
            }


            if (!is_array($keys)) $keys = [[$keys, '*']];
            else if (!is_array($keys[0])) $keys = [$keys];
            $web_key = $this->getServerKey();

            if (strlen($web_key))
                foreach ($keys as $key) {
                    if ($key[0] == $web_key) {

                        if (!isset($key[1])) $key[1] = "*";
                        if ($key[1] == '*') return $key;
                        else return $this->checkIPs($key[1]);
                    }
                }
            return false;
        }

        /**
         * @param array|string $allows string to compare with the current IP
         * @return bool
         */
        private function checkIPs($allows)
        {
            if (is_string($allows)) $allows = explode(',', $allows);
            foreach ($allows as $host) {
                $host = trim($host);
                if ($host == '*' || preg_match('/^.*' . $host . '.*$/', $this->core->system->ip) > 0) return true;
            }
            return false;
        }

        function getHeader($str)
        {
            $str = strtoupper($str);
            $str = str_replace('-', '_', $str);
            return ((isset($_SERVER['HTTP_' . $str])) ? $_SERVER['HTTP_' . $str] : '');
        }

        // Check checkCloudFrameWorkSecurity
        function checkCloudFrameWorkSecurity($maxSeconds = 0, $id = '', $secret = '')
        {
            if (!strlen($this->getHeader('X-CLOUDFRAMEWORK-SECURITY')))
                $this->core->logs->add('X-CLOUDFRAMEWORK-SECURITY missing.');
            else {
                list($_id, $_zone, $_time, $_token) = explode('__', $this->getHeader('X-CLOUDFRAMEWORK-SECURITY'), 4);
                if (!strlen($_id)
                    || !strlen($_zone)
                    || !strlen($_time)
                    || !strlen($_token)
                ) {
                    $this->core->logs->add('_wrong format in X-CLOUDFRAMEWORK-SECURITY.');
                } else {
                    $date = new DateTime(null, new DateTimeZone($_zone));
                    $secs = microtime(true) + $date->getOffset() - $_time;
                    if (!strlen($secret)) {
                        $secArr = $this->core->config->get('CLOUDFRAMEWORK-ID-' . $_id);
                        if (isset($secArr['secret'])) $secret = $secArr['secret'];
                    }


                    if (!strlen($secret)) {
                        $this->core->logs->add('conf-var CLOUDFRAMEWORK-ID-' . $_id . ' missing or it is not a righ CLOUDFRAMEWORK array.');
                    } elseif (!strlen($_time) || !strlen($_token)) {
                        $this->core->logs->add('wrong X-CLOUDFRAMEWORK-SECURITY format.');
                        // We allow an error of 2 min
                    } elseif (false && $secs < -120) {
                        $this->core->logs->add('Bad microtime format. Negative value got: ' . $secs . '. Check the clock of the client side.');
                    } elseif (strlen($id) && $id != $_id) {
                        $this->core->logs->add($_id . ' ID is not allowed');
                    } elseif ($this->getHeader('X-CLOUDFRAMEWORK-SECURITY') != $this->generateCloudFrameWorkSecurityString($_id, $_time, $secret)) {
                        $this->core->logs->add('X-CLOUDFRAMEWORK-SECURITY does not match.');
                    } elseif ($maxSeconds > 0 && $maxSeconds <= $secs) {
                        $this->core->logs->add('Security String has reached maxtime: ' . $maxSeconds . ' seconds');
                    } else {
                        $secArr['SECURITY-ID'] = $_id;
                        $secArr['SECURITY-EXPIRATION'] = ($maxSeconds) ? $maxSeconds - $secs : $maxSeconds;
                        return ($secArr);
                    }
                }
            }
            return false;
        }

        function getCloudFrameWorkSecurityInfo($maxSeconds = 0, $id = '', $secret = '')
        {
            $info = $this->checkCloudFrameWorkSecurity($maxSeconds, $id, $secret);
            if (false === $info) return [];
            else {
                return $this->core->config->get('CLOUDFRAMEWORK-ID-' . $info['SECURITY-ID']);
            }

        }

        // time, has to to be microtime().
        function generateCloudFrameWorkSecurityString($id = '', $time = '', $secret = '')
        {
            if (!strlen($id)) {
                $id = $this->core->config->get('CloudServiceId');
                if (!strlen($id)) {
                    $this->core->errors->add('generateCloudFrameWorkSecurityString has not received $id and CloudServiceId config var does not exist');
                    return false;
                }
            }

            if (!strlen($secret)) {
                $secret = $this->core->config->get('CloudServiceSecret');
                if (!strlen($secret)) {
                    $secArr = $this->core->config->get('CLOUDFRAMEWORK-ID-' . $id);
                    if (isset($secArr['secret'])) $secret = $secArr['secret'];
                    if (!strlen($secret)) {
                        $this->core->errors->add('generateCloudFrameWorkSecurityString has not received $secret and CloudServiceSecret and CLOUDFRAMEWORK-ID-XXX   config vars don\'t not exist');
                        return false;
                    }
                }
            }

            $ret = null;
            if (!strlen($secret)) {
                $secArr = $this->core->config->get('CLOUDFRAMEWORK-ID-' . $id);
                if (isset($secArr['secret'])) $secret = $secArr['secret'];
            }
            if (!strlen($secret)) {
                $this->core->logs->add('conf-var CLOUDFRAMEWORK-ID-' . $id . ' missing.');
            } else {
                if (!strlen($time)) $time = microtime(true);
                $date = new DateTime(null, new DateTimeZone('UTC'));
                $time += $date->getOffset();
                $ret = $id . '__UTC__' . $time;
                $ret .= '__' . hash_hmac('sha1', $ret, $secret);
            }
            return $ret;
        }

        private function createDSToken()
        {
            $dschema = ['token' => ['keyname', 'index']];
            $dschema['dateInsert'] = ['datetime', 'index'];
            $dschema['JSONZIP'] = ['string'];
            $dschema['fingerprint'] = ['string'];
            $dschema['prefix'] = ['string', 'index'];
            $dschema['secondsToExpire'] = ['integer'];
            $dschema['status'] = ['integer','index'];
            $dschema['ip'] = ['string','index'];
            $spacename = $this->core->config->get('DataStoreSpaceName');
            if (!strlen($spacename)) $spacename = "cloudframework";
            $this->dsToken = $this->core->loadClass('DataStore', ['CloudFrameWorkAuthTokens', $spacename, $dschema]);
            if ($this->dsToken->error) $this->core->errors->add(['setDSToken' => $this->dsToken->errorMsg]);
            return(!$this->dsToken->error);

        }

        /**
         * @param $token Id generated with setDSToken
         * @param string $prefix Prefix to separate tokens Between apps
         * @param int $time MAX TIME to expire the token
         * @param string $fingerprint_hash fingerprint_has to use.. if '' we will generate it using: $this->core->system->getRequestFingerPrint()['hash']
         * @param boolean $use_fingerprint_security Says it we are going to apply fingerprint security
         * @return array|mixed    The content contained in DS.JSONZIP
         */
        function getDSToken($token, $prefixStarts = '', $time = 0, $fingerprint_hash='',$use_fingerprint_security=true)
        {
            $this->core->__p->add('getDSToken', $prefixStarts, 'note');
            $ret = null;


            // Check if token starts with $prefix
            if (strlen($prefixStarts) && strpos($token, $prefixStarts) !== 0) {
                $this->core->errors->add(['getDSToken' => 'incorrect prefix token']);
                return $ret;
            }
            // Check if object has been created
            if (null === $this->dsToken) if(!$this->createDSToken()) return;


            $retToken = $this->dsToken->fetchByKeys($token);
            // Allow to rewrite the fingerprint it it is passed
            if (!$this->dsToken->error && !strlen($fingerprint_hash)) $fingerprint_hash = $this->core->system->getRequestFingerPrint()['hash'];

            if ($this->dsToken->error) {
                $this->core->errors->add(['getDSToken' => $this->dsToken->errorMsg]);
            } elseif (!count($retToken)) {
                $this->core->errors->add(['getDSToken' => 'Token not found.']);
            } elseif (!$retToken[0]['status']) {
                $this->core->errors->add(['getDSToken' => 'Token is no longer active.']);
            } elseif ($use_fingerprint_security && $fingerprint_hash != $retToken[0]['fingerprint']) {
                $this->core->errors->add(['getDSToken' => 'Token fingerprint does not match. Security violation.']);
            } elseif ($time > 0 && ((new DateTime())->getTimestamp()) - (new DateTime($retToken[0]['dateInsert']))->getTimestamp() >= $time) {
                $this->core->errors->add(['getDSToken' => 'Token expired']);
            } elseif (isset($retToken[0]['JSONZIP'])) {
                $ret = json_decode($this->uncompress($retToken[0]['JSONZIP']), true);
            }
            $this->core->__p->add('getDSToken', '', 'endnote');
            return $ret;
        }

        /**
         * Delete the entity with KeyName=$token
         * @param $token
         * @return array|bool|void If the deletion is right it return the array with the recored deleted
         */
        function deleteDSToken($token)
        {
            $ret = null;

            // Check if object has been created
            if (null === $this->dsToken) if(!$this->createDSToken()) return;

            // Return deleted records
            return($this->dsToken->deleteByKeys($token));
        }

        /**
         * Update the token data.
         * @param $token
         * @return array|bool|void If the deletion is right it return the array with the recored deleted
         */
        function updateDSToken($token,$data)
        {
            // Check if object has been created
            if (null === $this->dsToken) if(!$this->createDSToken()) return;

            $retToken = $this->dsToken->fetchByKeys($token);
            if(count($retToken)) {
                $retToken[0]['JSONZIP'] = $this->compress(json_encode($data));
                $ret = $this->dsToken->createEntities($retToken[0]);
                if(count($ret)) {
                    return(json_decode($this->uncompress($ret[0]['JSONZIP']), true));
                }
            }
            return [];

        }

        /**
         * Change the status = 0
         * @param $token
         * @return array|bool|void If the deletion is right it return the array with the recored deleted
         */
        function deactivateDSToken($token)
        {
            // Check if object has been created
            if (null === $this->dsToken) if(!$this->createDSToken()) return;

            $retToken = $this->dsToken->fetchByKeys($token);
            if(count($retToken)) {
                $retToken[0]['status'] = 0;
                $ret = $this->dsToken->createEntities($retToken[0]);
                if(count($ret)) {
                    return(true);
                }
            }
            return false;

        }

        function setDSToken($data, $prefix = '', $fingerprint_hash = '',$time_expiration=0)
        {
            $ret = null;
            if (!strlen(trim($prefix))) $prefix = 'default';

            // Check if object has been created
            if (null === $this->dsToken) if(!$this->createDSToken()) return;

            // If not error continue
            if (!$this->core->errors->lines) {
                if (!strlen($fingerprint_hash)) $fingerprint_hash = $this->core->system->getRequestFingerPrint()['hash'];
                $record['dateInsert'] = "now";
                $record['fingerprint'] = $fingerprint_hash;
                $record['JSONZIP'] = $this->compress(json_encode($data));
                $record['prefix'] = $prefix;
                $record['secondsToExpire'] = $time_expiration;
                $record['status'] = 1;
                $record['ip'] = $this->core->system->ip;
                $record['token'] = $this->core->config->get('DataStoreSpaceName') . '__' . $prefix . '__' . sha1(json_encode($record) . date('Ymdhis'));

                $retEntity = $this->dsToken->createEntities($record);
                if ($this->dsToken->error) {
                    $this->core->errors->add(['setDSToken' => $this->dsToken->errorMsg]);
                } else {
                    $ret = $retEntity[0]['KeyName'];
                }
            }
            return $ret;

        }

        function compress($data) {
            if(!is_string($data)) return $data;
            return base64_encode(gzcompress($data));
        }

        function uncompress($data) {
            return gzuncompress(base64_decode($data));
        }

        /**
         * method to encrypt a plain text string
         * initialization vector(IV) has to be the same when encrypting and decrypting
         * based on: https://gist.github.com/joashp/a1ae9cb30fa533f4ad94
         *
         * @param string $text: string to encrypt
         * @param string $secret_key  optional secret key. If empty it will take it from config-vars: core.security.encrypt_key
         * @param string $secret_iv  optional secret key. If empty it will take it from config-vars: core.security.encrypt_secret
         * @return string in base64
         */
        function encrypt($text,$secret_key='',$secret_iv='') {

            if(!$text) return $text;

            $encrypt_method = "AES-256-CBC";

            if(!$secret_key)
                $secret_key = ($this->core->config->get('core.security.encrypt_key'))?:'ybdqfG3MTPfzct3jR28qbix/yvbodtT0';

            if(!$secret_iv)
                $secret_iv = ($this->core->config->get('core.security.encrypt_secret'))?:'sadf&$sad_dkuYWER$T__6ttre';

            // hash
            $key = hash('sha256', $secret_key);

            // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
            $iv = substr(hash('sha256', $secret_iv), 0, 16);

            return openssl_encrypt($text, $encrypt_method, $key, 0, $iv);

        }

        /*
         * Decrypt Encrypted Text
         *
         * @param string $encrypted_text base64 string
         * @param string $secret_key  optional secret key. If empty it will take it from config-vars: core.security.encrypt_key
         * @param string $text  optional secret key. If empty it will take it from config-vars: core.security.encrypt_secret
         *
         */
        function decrypt($encrypted_text,$secret_key='',$secret_iv='') {

            if(!$encrypted_text) return $encrypted_text;

            $encrypt_method = "AES-256-CBC";

            if(!$secret_key)
                $secret_key = ($this->core->config->get('core.security.encrypt_key'))?:'ybdqfG3MTPfzct3jR28qbix/yvbodtT0';

            if(!$secret_iv)
                $secret_iv = ($this->core->config->get('core.security.encrypt_secret'))?:'sadf&$sad_dkuYWER$T__6ttre';

            // hash
            $key = hash('sha256', $secret_key);

            // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
            $iv = substr(hash('sha256', $secret_iv), 0, 16);

            return openssl_decrypt($encrypted_text, $encrypt_method, $key, 0, $iv);
        }

        /**
         * It says if the call is being doing by Cron Appengine Service.
         * @return boolean
         */
        function isCron() {
            return(!empty($this->getHeader('X-Appengine-Cron')));
        }

        /**
         * It generates a random unique string.
         * @return string with a length of 32 chars
         */
        public function generateRandomString($pref='')
        {
            if(!abs(intval(32)) ) $length=32;
            if(!$pref) $pref = rand();
            return(base64_encode(md5(uniqid($pref))));
        }

        /**
         * It generates a JSON WEB TOKEN based on a private key
         * based on https://github.com/firebase/php-jwt
         * to generate privateKey ../scripts/jwtRS256.sh
         * @return string|null with a length of 32 chars or null. if null check $this->error, $this->>errorMsg
         */
        public function jwt_encode($payload,$privateKey, $keyId = null, $head = null, $algorithm='SHA256')
        {
            if(!is_string($privateKey) || strlen($privateKey)< 10) return($this->addError('Wrong private key'));

            $header = array('typ' => 'JWT', 'alg' => $algorithm);
            if ($keyId !== null) {
                $header['kid'] = $keyId;
            }
            if ( isset($head) && is_array($head) ) {
                $header = array_merge($head, $header);
            }

            //region create $signing_input
            $segments = array();
            $segments[] = $this->urlsafeB64Encode($this->core->jsonEncode($header));
            $segments[] = $this->urlsafeB64Encode($this->core->jsonEncode($payload));
            $signing_input = implode('.', $segments);
            if($this->error) return;
            //endregion

            //region create $signature signing with the privateKey
            $signature = '';
            $success = openssl_sign($signing_input, $signature, $privateKey, $algorithm);
            if(!$success) {
                return($this->addError(['error'=>true,'errorMsg'=>'OpenSSL unable to sign data']));
            }
            //endregion

            //region retur the signature
            $segments[] = $this->urlsafeB64Encode($signature);
            if($this->error) return;
            return implode('.', $segments);
            //endregion
        }

        /**
         * It decode a JSON WEB TOKEN based on a public key
         * based on https://github.com/firebase/php-jwt
         * to generate publicKey ../scripts/jwtRS256.sh
         * @return string with a length of 32 chars
         */
        public function jwt_decode($jwt,$publicKey,$keyId=null,$algorithm='SHA256')
        {
            if(!is_string($publicKey) || strlen($publicKey)< 10) return($this->addError('Wrong public key'));

            $tks = explode('.', $jwt);
            if (count($tks) != 3) {
                return($this->addError('Wrong number of segments in $token'));
            }

            list($headb64, $bodyb64, $cryptob64) = $tks;

            if (null === ($header = $this->core->jsonDecode($this->urlsafeB64Decode($headb64)))) {
                return($this->addError('Invalid header encoding'));
            }
            if (null === $payload = $this->core->jsonDecode($this->urlsafeB64Decode($bodyb64))) {
                return($this->addError('Invalid claims encoding'));
            }
            if (false === ($sig = $this->urlsafeB64Decode($cryptob64))) {
                return($this->addError('Invalid signature encoding'));
            }
            if (!array_key_exists('alg',$header) || $header['alg']!='SHA256') {
                return($this->addError('Empty algorithm in header or value != SHA256'));
            }
            if (array_key_exists('kid',$header) && $header['kid']!=$keyId) {
                return($this->addError('KeyId present in header and does not match with $keyId'));
            }

            //region create $signature signing with the privateKey
            $success = openssl_verify("$headb64.$bodyb64",$sig, $publicKey, $algorithm);
            if($success!==1) {
                return($this->addError(['error'=>true,'errorMsg'=>'OpenSSL verification failed. '.openssl_error_string()]));
            }
            //endregion
            return($payload);
        }

        /**
         * Call https://api.clouframework.io/core/api-keys to verify an APIKey
         * More info in: https://www.notion.so/cloudframework/CloudFrameworkSecurity-APIKeys-CFS-APIKeys-13b47034a6f14f23b836c1f4238da548
         *
         * @param string $token  token of the entity of CloudFrameWorkAPIKeys
         * @param string $key   key of the APIKey to evaluate if it exists
         * @param string $spacename spacename of the data. Default cloudframework.
         * @param string $org organization of the entity inside of the spacename. Default common
         * @return bool[]|false[]|mixed|string[]|void
         */
        public function checkAPIKey($token,$key,$spacename='cloudframework',$org='common') {

            //Generate hash and evaluate return cached data
            $hash = md5($token.$key.$spacename.$org);
            if($data = $this->getCache($hash)) return $data;

            // Call CloudFrameWorkAPIKeys Service
            $url = 'https://api.cloudframework.io/core/api-keys/'.$spacename.'/'.$org;
            $ret = $this->core->request->get_json_decode($url,null,['X-WEB-KEY'=>$key,'X-DS-TOKEN'=>$token]);
            if($this->core->request->error) {
                $this->addError(['checkAPIKey'=>$this->core->request->errorMsg]);
                $this->core->request->reset();
                return;
            }
            $this->core->logs->add('CloudFrameworkSecurity APIKeys service used');

            //Update Cache
            $this->updateCache($hash,$ret['data']);

            // Return data
            return $ret['data'];
        }

        /**
         * Reset Cache of the module
         */
        public function readCache($security_group = 'default') {
            if(!isset($this->cache[$security_group]))
                $this->cache[$security_group] = ($this->core->cache->get('Core7.CoreSecurity.'.$security_group,3600*24,null,$this->cache_key,$this->cache_iv))?:[];
        }

        /**
         * Reset Cache of the module
         */
        public function resetCache($security_group = 'default') {
            $this->cache[$security_group] = [];
            $this->core->cache->set('Core7.CoreSecurity.'.$security_group,$this->cache[$security_group],null,$this->cache_key,$this->cache_iv);
            $this->core->logs->add('CoreSecurity.resetCache(\''.$security_group.'\') from '.$this->core->system->url['host_url_uri'],'CoreSecurity');
        }

        /**
         * Update Cache of the module
         */
        public function updateCache($var,$data,$security_group = 'default') {
            if(!$security_group) $security_group = 'default';
            $this->readCache($security_group);
            $this->cache[$security_group][$var] = $data;
            $this->core->cache->set('Core7.CoreSecurity.'.$security_group,$this->cache[$security_group],null,$this->cache_key,$this->cache_iv);

        }

        /**
         * Get var Cache of the module
         * @param string $var y empty it returns all the variable of the security group
         * @param string $security_group
         * @return mixed|null
         */
        public function getCache(string $var='',$security_group = 'default') {
            if(!$security_group) $security_group = 'default';
            $this->readCache($security_group);
            if($var) return $this->cache[$security_group][$var] ?? null;
            else return $this->cache[$security_group] ?? null;
        }


        public function urlsafeB64Encode($input) {
            return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
        }

        public function urlsafeB64Decode($input)
        {
            $remainder = strlen($input) % 4;
            if ($remainder) {
                $padlen = 4 - $remainder;
                $input .= str_repeat('=', $padlen);
            }
            return base64_decode(strtr($input, '-_', '+/'));
        }

        /**
         * Check if myip is allowed in the pattern described in $allowed_ips
         * Example: $allowed_ips="127.0.0.1,234.45.23.123,10.0.0.0/24"
         * @param string $allowed_ips  Here You describe the IPs or subnets separated by ','. '*' value means any IP
         * @param null $myip  Optional. By default it is $this->core->system->ip
         * @return boolean
         */
        public function checkAllowedIPs($allowed_ips,$myip=null) {
            if(!$myip) $myip=$this->core->system->ip;
            if($allowed_ips && $allowed_ips!='*' && $allowed_ips!=$myip) {
                if(strpos($allowed_ips,',')!==false) {
                    $allows = explode(',',$allowed_ips);
                    foreach ($allows as $allow) if(trim($allow)) {
                        if($allow=='*' || $allow==$myip) return true;
                        if(strpos($allow,'/')!==false) {
                            $myip_local = ($myip=='localhost')?'127.0.0.1':$myip;
                            list ($net, $mask) = explode("/", trim($allow),2);
                            $ip_net = ip2long ($net);
                            $ip_mask = ~((1 << (32 - $mask)) - 1);
                            $ip_ip = ip2long ($myip_local);
                            $ip_ip_net = $ip_ip & $ip_mask;

                            if(strval($ip_ip_net) == strval($ip_net)) return true;
                        }
                    }
                }
                return false;
            }
            return true;
        }

        /**
         * Add an error Message
         * @param $value
         */
        function addError($value)
        {
            $this->error = true;
            $this->errorMsg[] = $value;
        }



    }


    /**
     * Class to manage localizations
     * @package Core
     */
    class CoreLocalization
    {
        protected $core;
        var $data = [];
        var $wapploca = [];
        var $files_readed = [];
        private $init = false;
        var $error = false;
        var $errorMsg = [];
        var $cache = true;

        function __construct(Core7 &$core)
        {
            $this->core = $core;

        }

        function init() {

            // Maintain compatibilty with old variable
            if (!strlen($this->core->config->get('core.localization.cache_path')) && strlen($this->core->config->get('LocalizePath')))
                $this->core->config->set('core.localization.cache_path', $this->core->config->get('LocalizePath'));

            // Maintain compatibilty with old variable
            if (!strlen($this->core->config->get('core.localization.cache_path')) && strlen($this->core->config->get('localizeCachePath')))
                $this->core->config->set('core.localization.cache_path', $this->core->config->get('localizeCachePath'));


            // Read from Cache last Dics
            if($this->cache) {
                if (!isset($_GET['_reloadDics']) && !isset($_GET['_nocacheDics'])) {
                    $this->data = $this->core->cache->get('Core:Localization:Data');
                    if (!is_array($this->data)) $this->data = [];
                }


                if ($this->core->config->get('WAPPLOCA') && !isset($_GET['_nocacheDics'])) {
                    $this->wapploca = $this->core->cache->get('Core:Localization:WAPPLOCA', $this->core->config->get('wapploca_cache_expiration_time'));
                    if (!is_array($this->wapploca)) $this->wapploca = [];
                }
            }

            $this->init = true;
        }

        /**
         * Get a Localization code from a localization file
         * @param $locFile
         * @param $code
         * @param array $config
         * @return mixed|string
         */
        function get($locFile, $code, $config = [])
        {
            if(!$this->init) $this->init();
            $ret = null;

            // Check syntax of $locFile & $code
            if (!$this->checkLocFileAndCode($locFile, $code)) return 'Err in: [' . $locFile . "{{$code}}" . ']';
            $lang = $this->core->config->getLang();
            if (isset($config['lang']) && strlen($config['lang']) == 2) $lang = $config['lang'];
            // The $locFile does not exist
            if (!isset($_GET['_debugDics'])) {



                // Trying read from file
                if (!isset($this->data[$locFile][$lang]) && !isset($this->files_readed[$locFile][$lang])  ) {
                    $this->readFromFile($locFile, $lang);
                }

                // Trying read from WAPPLOCA
                $wapploca_readed = false;
                if ($this->core->config->get('WAPPLOCA') && !isset($this->data[$locFile][$lang])) {
                    if ($this->readFromWAPPLOCA($locFile, $code, $lang) && isset($this->data[$locFile][$lang][$code])) {
                        // Writting Local file.
                        $this->writeLocalization($locFile, $lang);
                        $this->files_readed[$locFile][$lang] = true;

                    }
                }

                // If this localization file exists but the $code does not exist because the cache
                if (isset($this->data[$locFile][$lang]) && !isset($this->data[$locFile][$lang][$code]) && !isset($this->files_readed[$locFile][$lang]) ) {
                    // $this->readFromFile($locFile, $lang);
                }
            }


            if (isset($_GET['_debugDics'])) {
                $ret = $lang . '_' . $locFile . ":({$code})";
            } else if (isset($this->data[$locFile][$lang][$code])) {
                $ret = $this->data[$locFile][$lang][$code];
            } else {
                $this->core->logs->add("Missing configuration for Localizations: {$locFile}-{$lang}-{$code}");
                $this->core->logs->add('WAPPLOCA: ' . ((empty($this->core->config->get('WAPPLOCA'))) ? 'empty' : '***'));
                $this->core->logs->add('core.localization.cache_path: ' . ((empty($this->core->config->get('core.localization.cache_path'))) ? 'empty' : '***'));
            }

            // Evaluate data conversion
            if(array_key_exists('data',$config) && $config['data']) {
                $ret = vsprintf($ret,$config['data']);
            }

            // Return
            return $ret;
        }

        /**
         * Set a Localization code
         * @param $locFile
         * @param $code
         * @param $content
         * @param array $config
         * @return mixed|string
         */
        function set($locFile, $code, $content,$config = [])
        {
            if(!$this->init) $this->init();

            // Check syntax of $locFile & $code
            if (!$this->checkLocFileAndCode($locFile, $code)) return 'Err in: [' . $locFile . "{{$code}}" . ']';
            if (isset($config['lang']) && strlen($config['lang']) == 2) $lang = $config['lang'];

            if (!isset($this->data[$locFile][$lang]) || !isset($this->data[$locFile][$lang][$code]) || $this->data[$locFile][$lang][$code]!=$content) {
                $this->data[$locFile][$lang][$code]=$content;
            }
        }


        /**
         * Read from a file the localizations and store the content into: $this->data[$locFile][$lang]
         * @param $locFile
         * @return bool
         */
        private function readFromFile($locFile, $lang = '')
        {
            if(!$this->init) $this->init();


            if (!strlen($this->core->config->get('core.localization.cache_path'))) {
                $this->core->config->set('core.localization.cache_path',$this->core->system->app_path.'/localize');
            }
            if (!strlen($lang)) $lang = $this->core->config->getLang();
            $ok = true;
            $this->core->__p->add('Localization->readFromFile: ', strtoupper($lang)."-{$locFile}", 'note');
            // First read from local directory if {{core.localization.cache_path}} is defined.
            $this->files_readed[$locFile][$lang] = true;
            if (strlen($this->core->config->get('core.localization.cache_path'))) {
                $filename = $this->core->config->get('core.localization.cache_path') . '/' . strtoupper($lang) . '_Core_' . $locFile . '.json';
                try {
                    $ret = @file_get_contents($filename);
                    if ($ret !== false) {
                        $this->data[$locFile][$lang] = json_decode($ret, true);

                        // Cache result
                        if($this->cache)
                            $this->core->cache->set('Core:Localization:Data', $this->data);

                        $this->core->__p->add('Success reading ' . strtoupper($lang) . '_Core_' . $locFile . '.json');

                    } else {
                        $this->core->__p->add('Error reading ' .  strtoupper($lang) . '_Core_' . $locFile . '.json');
                        $this->addError('CoreLoalization.readFromFile error. No file: '.$filename);
                        $this->addError(error_get_last());
                        $ok = false;
                    }
                } catch (Exception $e) {
                    $ok = false;
                    $this->core->logs->add('Error reading ' . $filename . ': ');
                    $this->core->logs->add($e->getMessage() . ' ' . error_get_last());
                }
            }
            $this->core->__p->add('Localization->readFromFile: ', '', 'endnote');
            return $ok;
        }

        public function writeLocalization($locFile, $lang = '')
        {
            if(!$this->init) $this->init();


            if (!strlen($this->core->config->get('core.localization.cache_path'))) return false;
            if (!isset($this->data[$locFile])) return false;
            if (!strlen($lang)) $lang = $this->core->config->getLang();
            $ok = true;
            $this->core->__p->add('Localization->writeLocalization: ', strtoupper($lang) . '_Core_' . $locFile . '.json', 'note');

            $filename = $this->core->config->get('core.localization.cache_path') . '/' . strtoupper($lang) . '_Core_' . $locFile . '.json';
            try {
                $ret = @file_put_contents($filename, json_encode($this->data[$locFile][$lang], JSON_PRETTY_PRINT));
                if ($ret === false) {
                    $ok = false;
                    $this->core->__p->add('Error writting ' . strtoupper($lang) . '_Core_' . $locFile . '.json');
                    $this->core->logs->add('Error writting ' . $filename);
                    $this->core->logs->add(error_get_last());
                } else {
                    $this->core->__p->add('Success Writting ' . strtoupper($lang) . '_Core_' . $locFile . '.json');

                    // Cache the result
                    if($this->cache)
                        $this->core->cache->set('Core:Localization:Data', $this->data);
                }
            } catch (Exception $e) {
                $ok = false;
                $this->core->logs->add('Error reading ' . $filename . ': ');
                $this->core->logs->add($e->getMessage() . ' ' . error_get_last());
            }
            $this->core->__p->add('Localization->writeLocalization: ', '', 'endnote');
            return $ok;
        }


        /**
         * Read from a file the localizations
         * @param $locFile
         * @param $code
         * @return bool
         */
        private function readFromWAPPLOCA($locFile, $code, $lang = '')
        {
            if(!$this->init) $this->init();

            if (empty($this->core->config->get('WAPPLOCA'))) return false;
            if (!strlen($lang)) $lang = $this->core->config->getLang();

            list($org, $app, $cat, $loc_code) = explode(';', $code, 4);
            if (!strlen($loc_code) || preg_match('/[^a-z0-9_-]/', $loc_code)) {
                $this->core->logs->add('Localization->readFromWAPPLOCA: has a wrong value for $code: ' . $code);
                return false;
            }
            $ok = true;
            $lang = strtoupper($lang);
            $key = "$org/$app/$cat?lang=" . $lang;
            // Read From CloudService the info and put the cats into $this->wapploca
            $url = $this->core->config->get('wapploca_api_url');
            if (!isset($this->wapploca[$key][$lang])) {
                $ret = $this->core->request->get($url . '/dics/' . $key);
                if (!$this->core->request->error) {
                    $ret = json_decode($ret, true);
                    if (!$ret['success']) {
                        $this->core->logs->add($ret);
                        $ok = false;
                    } else {
                        if (is_array($ret['data'])) {
                            $this->wapploca[$key] = $ret['data'];

                            // Cache the result
                            if($this->cache)
                                $this->core->cache->set('Core:Localization:WAPPLOCA', $this->wapploca);

                        } else {
                            $this->core->logs->add('WAPPLOCA return data is not an array');
                            $this->core->logs->add($ret);
                        }
                    }
                } else $ok = false;
            }


            // Return the code required
            if (isset($this->wapploca[$key][$lang][$code]))
                $this->data[$locFile][$lang][$code] = $this->wapploca[$key][$lang][$code];
            else
                $this->data[$locFile][$lang][$code] = $code;
            return $ok;
        }


        /**
         * Check the formats for locaLizationFile and codes
         * @param $locFile
         * @param $code
         * @return bool
         */
        private function checkLocFileAndCode(&$locFile, &$code)
        {
            if(!$this->init) $this->init();

            $locFile = preg_replace('/[^A-z0-9_\-]/', '', $locFile);
            if (!strlen($locFile)) {
                $this->core->errors->set('Localization has received a wrong spacename: ');
                return false;
            }
            $code = preg_replace('/[^a-z0-9_\-;\.]/', '', strtolower($code));
            if (!strlen($code)) {
                $this->core->errors->set('Localization has received a wrong code: ');
                return false;
            }
            return true;
        }

        function addError($value)
        {
            $this->error = true;
            $this->errorMsg[] = $value;
        }
    }

    /**
     * Class to manage HTTP requests
     * @package Core
     */
    class CoreRequest
    {
        protected $core;
        protected $http;
        public $responseHeaders;
        public $error = false;
        public $errorMsg = [];
        public $options = null;
        var $rawResult = '';
        var $automaticHeaders = true; // Add automatically the following headers if exist on config: X-CLOUDFRAMEWORK-SECURITY, X-SERVER-KEY, X-SERVER-KEY, X-DS-TOKEN,X-EXTRA-INFO
        var $sendSysLogs = true;
        var $default_options = array('ssl' => array('verify_peer' => false),'http'=>['protocol_version'=>'1.1','ignore_errors'=>'1','follow_location'=>true]);
        var $cookies = [];
        function __construct(Core7 &$core)
        {
            $this->core = $core;
            if (!$this->core->config->get("CloudServiceUrl"))
                $this->core->config->set("CloudServiceUrl", 'https://api7.cloudframework.io');

        }

        /**
         * @param string $path Path to complete URL. if it does no start with http.. $path will be aggregated to: $this->core->config->get("CloudServiceUrl")
         * @return string
         */
        function defaultServiceUrl($path = '')
        {
            if (strpos($path, 'http') === 0) return $path;
            else {
                if (!$this->core->config->get("CloudServiceUrl"))
                    $this->core->config->set("CloudServiceUrl", 'https://api7.cloudframework.io');

                $this->http = $this->core->config->get("CloudServiceUrl");

                if (strlen($path) && $path[0] != '/')
                    $path = '/' . $path;
                return ($this->http . $path);
            }
        }
        function getServiceUrl($path = '') {return $this->defaultServiceUrl($path);}


        /**
         * Call External Cloud Service Caching the result
         */
        function getCache($route, $data = null, $verb = 'GET', $extraheaders = null, $raw = false)
        {
            $_qHash = hash('md5', $route . json_encode($data) . $verb);
            $ret = $this->core->cache->get($_qHash);
            if (isset($_GET['refreshCache']) || $ret === false || $ret === null) {
                $ret = $this->get($route, $data, $extraheaders, $raw);
                // Only cache successful responses.
                if (is_array($this->responseHeaders) && isset($this->responseHeaders[0]) && strpos($this->responseHeaders[0], 'OK')) {
                    $this->core->cache->set($_qHash, $ret);
                }
            }
            return ($ret);
        }

        /**
         * CURL METHOD
         * @param $route
         * @param null $data
         * @param string $verb
         * @param null $extra_headers
         * @param false $raw
         * @return false|string
         * @throws Exception
         */
        function getCurl($route, $data = null, $verb = 'GET', $extra_headers = null, $raw = false)
        {

            $_time = microtime(TRUE);
            if($this->sendSysLogs)
                $this->core->logs->add("curl request {$verb} {$route} ".(($data === null) ? '{no params}' : '{with params}'),'CoreRequest');

            $this->core->__p->add('Request->getCurl: ', "$route " . (($data === null) ? '{no params}' : '{with params}'), 'note');
            $route = $this->getServiceUrl($route);
            $this->responseHeaders = null;
            $options['http']['header'] = ['Connection: close', 'Expect:', 'ACCEPT:']; // improve perfomance and avoid 100 HTTP Header


            // Automatic send header for X-CLOUDFRAMEWORK-SECURITY if it is defined in config
            if (strlen($this->core->config->get("CloudServiceId")) && strlen($this->core->config->get("CloudServiceSecret")))
                $options['http']['header'][] = 'X-CLOUDFRAMEWORK-SECURITY: ' . $this->generateCloudFrameWorkSecurityString($this->core->config->get("CloudServiceId"), microtime(true), $this->core->config->get("CloudServiceSecret"));

            // Extra Headers
            if ($extra_headers !== null && is_array($extra_headers)) {
                foreach ($extra_headers as $key => $value) {
                    $options['http']['header'][] .= $key . ': ' . $value;
                }
            }

            # Content-type for something different than get.
            if ($verb != 'GET') {
                if (stripos(json_encode($options['http']['header']), 'Content-type') === false) {
                    if ($raw) {
                        $options['http']['header'][] = 'Content-type: application/json';
                    } else {
                        $options['http']['header'][] = 'Content-type: application/x-www-form-urlencoded';
                    }
                }
            }
            // Build contents received in $data as an array
            if (is_array($data) ) {
                if ($verb == 'GET') {
                    // Add the parameter ? or & to add new variable
                    $route .= (strpos($route,'?'))?'&':'?';
                    //explore variable by variable
                    foreach ($data as $key => $value) {
                        if (is_array($value)) {
                            // This could be improved becuase the coding will produce 1738 format and 3986 format
                            $route .= http_build_query([$key => $value]) . '&';
                        } else {
                            $route .= $key . '=' . rawurlencode($value) . '&';
                        }
                    }
                } else {
                    if ($raw) {
                        if (stripos(json_encode($options['http']['header']), '/json') !== false) {
                            $build_data = json_encode($data);
                        } else
                            $build_data = $data;
                    } else {
                        $build_data = http_build_query($data);
                    }
                    $options['http']['content'] = $build_data;

                    // You have to calculate the Content-Length to run as script
                    // $options['http']['header'][] = sprintf('Content-Length: %d', strlen($build_data));
                }
            }

            $curl_options = [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,            // return headers
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => $options['http']['header'],
                CURLOPT_CUSTOMREQUEST => $verb

            ];
            // Appengine  workaround
            // $curl_options[CURLOPT_SSL_VERIFYPEER] = false;
            // $curl_options[CURLOPT_SSL_VERIFYHOST] = false;
            // Download https://pki.google.com/GIAG2.crt
            // openssl x509 -in GIAG2.crt -inform DER -out google.pem -outform PEM
            // $curl_options[CURLOPT_CAINFO] =__DIR__.'/google.pem';

            if (isset($options['http']['content'])) {
                $curl_options[CURLOPT_POSTFIELDS] = $options['http']['content'];
            }

            // Cache
            $ch = curl_init($route);
            curl_setopt_array($ch, $curl_options);
            $ret = curl_exec($ch);

            if (!curl_errno($ch)) {
                $header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $this->responseHeaders = explode("\n",substr($ret, 0, $header_len));
                $ret = substr($ret, $header_len);
                if($this->getLastResponseCode()>=400) {
                    $this->addError('Error code returned: ' . $this->getLastResponseCode());
                    $this->addError($this->responseHeaders);
                    $this->addError($ret);
                }
            } else {
                $this->addError(error_get_last());
                $this->addError([('Curl error ' . curl_errno($ch)) => curl_error($ch)]);
                $this->addError(['Curl url' => $route]);
                $ret = false;
            }
            curl_close($ch);

            if($this->sendSysLogs) {
                $_time = round(microtime(TRUE) -$_time,4);
                $this->core->logs->add("end curl request {$verb} {$route} ".(($data === null) ? '{no params}' : '{with params}')." {$_time} secs",(($this->error)?'debug':'info'),'CoreRequest');
            }

            $this->core->__p->add('Request->getCurl: ', '', 'endnote');
            return $ret;


        }


        /**
         * GET CALL expecting a json response
         * @param $route
         * @param null $data
         * @param null $extra_headers
         * @param false $send_in_json
         * @return bool[]|mixed|string[]
         */
        function get_json_decode($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            $this->rawResult = $this->get($route, $data, $extra_headers, $send_in_json);
            $ret = json_decode($this->rawResult, true);
            if (JSON_ERROR_NONE === json_last_error()) $this->rawResult = '';
            else {
                $ret = ['error'=>$this->rawResult];
            }
            return $ret;
        }

        /**
         * POST CALL expecting a json response
         * @param $route
         * @param null $data
         * @param null $extra_headers
         * @param false $send_in_json
         * @return bool[]|mixed|string[]
         */
        function post_json_decode($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            $this->rawResult = $this->post($route, $data, $extra_headers, $send_in_json);
            $ret = json_decode($this->rawResult, true);
            if (JSON_ERROR_NONE === json_last_error()) $this->rawResult = '';
            else {
                $ret = ['error'=>$this->rawResult];
            }
            return $ret;
        }

        /**
         * PUT CALL expecting a json response
         * @param $route
         * @param null $data
         * @param null $extra_headers
         * @param false $send_in_json
         * @return bool[]|mixed|string[]
         */
        function put_json_decode($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            $this->rawResult = $this->put($route, $data, $extra_headers, $send_in_json);
            $ret = json_decode($this->rawResult, true);
            if (JSON_ERROR_NONE === json_last_error()) $this->rawResult = '';
            else {
                $ret = ['error'=>$this->rawResult];
            }
            return $ret;
        }


        /**
         * PATCH
         * @param $route
         * @param null $data
         * @param null $extra_headers
         * @param false $send_in_json
         * @return bool[]|mixed|string[]
         */
        function patch_json_decode($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            $this->rawResult = $this->patch($route, $data, $extra_headers, $send_in_json);
            $ret = json_decode($this->rawResult, true);
            if (JSON_ERROR_NONE === json_last_error()) $this->rawResult = '';
            else {
                $ret = ['error'=>$this->rawResult];
            }
            return $ret;
        }

        /**
         * DELETE
         * @param $route
         * @param null $extra_headers
         * @param null $data
         * @param false $send_in_json
         * @return bool[]|mixed|string[]
         */
        function delete_json_decode($route, $extra_headers = null, $data = null, $send_in_json = false)
        {
            $this->rawResult = $this->delete($route, $extra_headers, $data, $send_in_json);
            $ret = json_decode($this->rawResult, true);
            if (JSON_ERROR_NONE === json_last_error()) $this->rawResult = '';
            else {
                $ret = ['error'=>$this->rawResult];
            }
            return $ret;
        }

        /**
         * GET
         * @param $route
         * @param null $data
         * @param null $extra_headers
         * @param false $send_in_json
         * @return bool|string
         */
        function get($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            return $this->call($route, $data, 'GET', $extra_headers, $send_in_json);
        }

        /**
         * POST
         * @param $route
         * @param null $data
         * @param null $extra_headers
         * @param false $send_in_json
         * @return bool|string
         */
        function post($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            return $this->call($route, $data, 'POST', $extra_headers, $send_in_json);
        }

        /**
         * PUT
         * @param $route
         * @param null $data
         * @param null $extra_headers
         * @param false $send_in_json
         * @return bool|string
         */
        function put($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            return $this->call($route, $data, 'PUT', $extra_headers, $send_in_json);
        }

        /**
         * @param $route
         * @param null $data
         * @param null $extra_headers
         * @param false $send_in_json
         * @return bool|string
         */
        function patch($route, $data = null, $extra_headers = null, $send_in_json = false)
        {
            return $this->call($route, $data, 'PATCH', $extra_headers, $send_in_json);
        }

        /**
         * DELETE
         * @param $route
         * @param null $extra_headers
         * @param null $data
         * @param false $send_in_json
         * @return bool|string
         */
        function delete($route, $extra_headers = null, $data = null, $send_in_json = false)
        {
            return $this->call($route, $data, 'DELETE', $extra_headers, $send_in_json);
        }

        /**
         * Allow to execute a GET,POST,PUT,PATCH,DELETE call with variables and files
         * All the calls will try to add the following headers if they exit in $this->core->config:
         *   - X-CLOUDFRAMEWORK-SECURITY
         *   - X-SERVER-KEY
         *   - X-DS-TOKEN
         *   - X-EXTRA-INFO
         *
         * Sending files:
         *     If you need to send files it can only be made with POST/PUT and $raw=false
         *     $data has to include $data['__files'] = array($file,$file,..) where
         *     $file = [
         *           'file_content'=>'{content to send}'  // if file_path is not sent this is mandatory. Content of file
         *          ,'file_path'=>'{path_to_content}'     // if file_content is not sent this is mandatory. Path of the file content
         *          ,'file_name'=>'{name_of_file}'        // Name of file
         *          ,'file_type'=>'{file type}'           // Content-Type of the file. Example: application/pdf
         *          ,'file_var'=>'{name_of_var}'          // Optional var name to be used sending the file. You can use {name_of_var}[] for multiple files in the same var. default = file
         *
         * Sending JSON data:
         *     Put $raw = true;
         *
         *
         * Extra info: https://stackoverflow.com/questions/4003989/upload-a-file-using-file-get-contents
         * For extra headers you can get further information in: https://www.php.net/manual/de/context.http.php
         * @param $route
         * @param null $data
         * @param string $verb
         * @param null $extra_headers
         * @param bool $raw
         * @return bool|false|string
         */
        function call($route, $data = null, $verb = 'GET', $extra_headers = null, $raw = false)
        {
            $_time = microtime(TRUE);
            $this->core->__p->add("Request->{$verb}: ", "$route " . (($data === null) ? '{no params}' : '{with params}'), 'note');

            $route = $this->getServiceUrl($route);
            $this->responseHeaders = null;

            if($this->sendSysLogs)
                $this->core->logs->add("request {$verb} {$route} ".(($data === null) ? '{no params}' : '{with params}'),'CoreRequest');
            //syslog(LOG_INFO,"request {$verb} {$route} ".(($data === null) ? '{no params}' : '{with params}'));

            // Performance for connections
            $options = $this->default_options;
            $options['http']['header'] = 'Connection: close' . "\r\n";
            if(!is_array($extra_headers) || !isset($extra_headers['Accept']))
                $options['http']['header'] = 'Accept: */*' . "\r\n";


            if($this->automaticHeaders) {
                // Automatic send header for X-CLOUDFRAMEWORK-SECURITY if it is defined in config
                if (strlen($this->core->config->get("CloudServiceId")) && strlen($this->core->config->get("CloudServiceSecret")))
                    $options['http']['header'] .= 'X-CLOUDFRAMEWORK-SECURITY: ' . $this->generateCloudFrameWorkSecurityString($this->core->config->get("CloudServiceId"), microtime(true), $this->core->config->get("CloudServiceSecret")) . "\r\n";

                // Add Server Key if we have it.
                if (strlen($this->core->config->get("CloudServerKey")))
                    $options['http']['header'] .= 'X-SERVER-KEY: ' . $this->core->config->get("CloudServerKey") . "\r\n";

                // Add Server Key if we have it.
                if (strlen($this->core->config->get("X-DS-TOKEN")))
                    $options['http']['header'] .= 'X-DS-TOKEN: ' . $this->core->config->get("X-DS-TOKEN") . "\r\n";

                if (strlen($this->core->config->get("X-EXTRA-INFO")))
                    $options['http']['header'] .= 'X-EXTRA-INFO: ' . $this->core->config->get("X-EXTRA-INFO") . "\r\n";
            }
            // Extra Headers
            if ($extra_headers !== null && is_array($extra_headers)) {
                foreach ($extra_headers as $key => $value) {
                    $options['http']['header'] .= $key . ': ' . $value . "\r\n";
                }
            }


            // Method
            $options['http']['method'] = $verb;

            // Content-type
            $MULTIPART_BOUNDARY= '--------------------------'.microtime(true);
            if ($verb != 'GET') {
                if (stripos($options['http']['header'], 'Content-type') === false) {
                    if ($raw) {
                        $options['http']['header'] .= 'Content-type: application/json' . "\r\n";
                    }elseif(($verb == 'POST' || $verb == 'PUT' || $verb == 'PATCH') && is_array($data) && key_exists('__files', $data) && $data['__files']){
                        $options['http']['header'] .= 'Content-Type: multipart/form-data; boundary='.$MULTIPART_BOUNDARY;
                    } else {
                        $options['http']['header'] .= 'Content-type: application/x-www-form-urlencoded' . "\r\n";
                    }
                }
            }


            // Build contents received in $data as an array
            if (is_array($data)) {
                if ($verb == 'GET') {
                    if (strpos($route, '?') === false) $route .= '?';
                    else $route .= '&';
                    foreach ($data as $key => $value) {
                        if (is_array($value)) {
                            // This could be improved becuase the coding will produce 1738 format and 3986 format
                            $route .= http_build_query([$key => $value]) . '&';
                        } else {
                            $route .= $key . '=' . rawurlencode($value) . '&';
                        }
                    }
                } else {
                    if ($raw) {
                        if (stripos($options['http']['header'], 'application/json') !== false) {
                            $build_data = json_encode($data);
                        } else
                            $build_data = $data;
                    } else {
                        if(($verb == 'POST' || $verb == 'PUT' || $verb == 'PATCH') && is_array($data) && key_exists('__files',$data) && $data['__files']) {
                            $build_data = '';
                            foreach ($data['__files'] as $file) {

                                //region SET $file_var
                                $file_var = (isset($file['file_var']) && $file['file_var'])?$file['file_var']:'file';
                                //endregion
                                //region SET $file_content
                                $file_content=null;
                                if(key_exists('file_content',$file) && !empty($file['file_content'])) {
                                    $file_content = $file['file_content'];
                                    if(!$file_content) return($this->addError('Error trying to send a file. file_content is empty.'));
                                } elseif(key_exists('file_path',$file) && !empty($file['file_path'])) {
                                    $file_content = @file_get_contents($file['file_path']);
                                    if(false === $file_content) return($this->addError('Error trying to send a file. The file_path {'.$file['file_path'].'} does not return content'));
                                } else {
                                    return($this->addError('Error trying to send a file. Missing file_content or file_path variables'));
                                }
                                //endregion
                                //region SET $file_name
                                $file_name = (isset($file['file_name']) && $file['file_name'])?$file['file_name']:null;
                                if(!$file_name) return($this->addError('Error trying to send a file. Missing file_name variable'));
                                //endregion
                                //region SET $file_type
                                $file_type = (isset($file['file_type']) && $file['file_type'])?$file['file_type']:null;
                                if(!$file_type) return($this->addError('Error trying to send a file. Missing file_name variable'));
                                //endregion

                                $build_data.=  '--'.$MULTIPART_BOUNDARY."\r\n".
                                    'Content-Disposition: form-data; name="'.$file_var.'"; filename="'.$file_name."\"\r\n".
                                    'Content-Type: '.$file_type."\r\n\r\n".
                                    $file_content."\r\n";
                            }
                            unset($data['__files']);
                            foreach ($data as $key=>$datum) {
                                $build_data.=  '--'.$MULTIPART_BOUNDARY."\r\n".
                                    'Content-Disposition: form-data; name="'.$key.'"'."\r\n\r\n".
                                    $datum."\r\n";
                            }
                            $build_data.=  '--'.$MULTIPART_BOUNDARY."--\r\n";

                        } else {
                            $build_data = http_build_query($data);
                        }
                    }
                    $options['http']['content'] = $build_data;

                    // You have to calculate the Content-Length to run as script
                    if($this->core->is->script())
                        $options['http']['header'] .= sprintf('Content-Length: %d', strlen($build_data)) . "\r\n";
                }
            }
            // Take data as a valid JSON
            elseif(is_string($data)) {
                if(is_array(json_decode($data,true))) $options['http']['content'] = $data;
            }

            // Save in the class the last options sent
            $this->options = ['route'=>$route,'options'=>$options];
            // Context creation
            $context = stream_context_create($options);

            try {
                $ret = @file_get_contents($route, false, $context);

                // Return response headers
                if(isset($http_response_header)) $this->responseHeaders = $http_response_header;
                else $this->responseHeaders = ['$http_response_header'=>'undefined'];

                // Process response Headers
                $this->processResponseHeaders();

                // If we have an error
                if ($ret === false) {
                    $this->addError(['route_error'=>$route,'reponse_headers'=>$this->responseHeaders,'system_error'=>error_get_last()]);
                } else {
                    $code = $this->getLastResponseCode();
                    if ($code === null) {
                        $this->addError('Return header not found');
                        $this->addError($this->responseHeaders);
                        $this->addError($ret);
                    } else {
                        if ($code >= 400) {
                            $this->addError('Error code returned: ' . $code);
                            $this->addError($this->responseHeaders);
                            $this->addError($ret);
                        }
                    }
                }
            } catch (Exception $e) {
                $this->addError(error_get_last());
                $this->addError($e->getMessage());
            }
            if($this->sendSysLogs) {
                $_time = round(microtime(TRUE) -$_time,4);
                $this->core->logs->add("end request {$verb} {$route} ".(($data === null) ? '{no params}' : '{with params}')." - {$code} [{$_time} secs]",'CoreRequest');
            }
            $this->core->__p->add("Request->{$verb}: ", '', 'endnote');


            //syslog(($this->error)?LOG_DEBUG:LOG_INFO,"end request {$verb} {$route} ".(($data === null) ? '{no params}' : '{with params}'));

            return ($ret);
        }

        private function processResponseHeaders() {
            if(is_array($this->responseHeaders)) foreach ($this->responseHeaders as $responseHeader) {
                if(strpos($responseHeader,'Set-Cookie: ')===0) {
                    list($var,$attributes) = explode(';',$responseHeader,2);
                    list($var,$value) = explode('=',$var);
                    $var  = str_replace('Set-Cookie: ','',$var);
                    $this->cookies[$var][] = ['value'=>$value,'path'=>$attributes];
                }
            }
        }


        /*
         * Returns the status number of the last call
         */
        function getLastResponseCode()
        {
            $code = null;
            if (isset($this->responseHeaders[0])) {
                $parts = explode(' ', $this->responseHeaders[0]);
                if(isset($parts[1])) $code=$parts[1];
            }
            return $code;

        }

        /**
         * Generate a CloudFrameWork SecurityS tring to be used with a API with CloudFramework Technology
         * @param $id
         * @param string $time time, has to to be microtime()
         * @param string $secret
         * @return string|null
         * @throws Exception
         */
        function generateCloudFrameWorkSecurityString($id, $time = '', $secret = '')
        {
            $ret = null;
            if (!strlen($secret)) {
                $secArr = $this->core->config->get('CLOUDFRAMEWORK-ID-' . $id);
                if (isset($secArr['secret'])) $secret = $secArr['secret'];
            }
            if (!strlen($secret)) {
                $this->core->logs->add('conf-var CLOUDFRAMEWORK-ID-' . $id . ' missing.');
            } else {
                if (!strlen($time)) $time = microtime(true);
                $date = new DateTime(null, new DateTimeZone('UTC'));
                $time += $date->getOffset();
                $ret = $id . '__UTC__' . $time;
                $ret .= '__' . hash_hmac('sha1', $ret, $secret);
            }
            return $ret;
        }

        /**
         * @param string $url
         * @param int $format
         * @desc Fetches all the headers
         * @return array
         */
        function getUrlHeaders($url)
        {
            if(!$this->core->is->validURL($url)) return($this->core->errors->add('invalid url: '.$url));
            if(!($headers = @get_headers($url))) {
                $this->core->errors->add(error_get_last()['message']);
            }
            return $headers;
        }

        /**
         * @param $url
         * @param $header key name of the header to get.. If not passed return all the array
         * @return string|array
         */
        function getUrlHeader($url, $header=null) {
            $ret = 'error';
            $response = $this->getUrlHeaders($url);
            $headers = [];
            foreach ($response as $i=>$item) {
                if($i==0) $headers['response'] = $item;
                else {
                    list($key,$value) = explode(':',strtolower($item),2);
                    $headers[$key] = $value;
                }
            }
            if($header) return($headers[strtolower($header)]);
            else return $headers;
        }

        function addError($value)
        {
            $this->error = true;
            $this->core->errors->add($value);
            $this->errorMsg[] = $value;
        }
        public function getResponseHeader($key) {

            if(is_array($this->responseHeaders))
                foreach ($this->responseHeaders as $responseHeader)
                    if(strpos($responseHeader,$key)!==false) {
                        list($header_key,$content) = explode(':',$responseHeader,2);
                        $content = trim($content);
                        return $content;
                    }
            return null;
        }

        function sendLog($type, $cat, $subcat, $title, $text = '', $email = '', $app = '', $interactive = false)
        {

            if (!strlen($app)) $app = $this->core->system->url['host'];

            $this->core->logs->add(['sending cloud service logs:' => [$this->getServiceUrl('queue/cf_logs/' . $app), $type, $cat, $subcat, $title]]);
            if (!$this->core->config->get('CloudServiceLog') && !$this->core->config->get('LogPath')) return false;
            $app = str_replace(' ', '_', $app);
            $params['id'] = $this->core->config->get('CloudServiceId');
            $params['cat'] = $cat;
            $params['subcat'] = $subcat;
            $params['title'] = $title;
            if (!is_string($text)) $text = json_encode($text);
            $params['text'] = $text . ((strlen($text)) ? "\n\n" : '');
            if ($this->core->errors->lines) $params['text'] .= "Errors: " . json_encode($this->core->errors->data, JSON_PRETTY_PRINT) . "\n\n";
            if (count($this->core->logs->lines)) $params['text'] .= "Logs: " . json_encode($this->core->logs->data, JSON_PRETTY_PRINT);

            // IP gathered from queue
            if (isset($_REQUEST['cloudframework_queued_ip']))
                $params['ip'] = $_REQUEST['cloudframework_queued_ip'];
            else
                $params['ip'] = $this->core->system->ip;

            // IP gathered from queue
            if (isset($_REQUEST['cloudframework_queued_fingerprint']))
                $params['fingerprint'] = $_REQUEST['cloudframework_queued_fingerprint'];
            else
                $params['fingerprint'] = json_encode($this->core->system->getRequestFingerPrint(), JSON_PRETTY_PRINT);

            // Tell the service to send email of the report.
            if (strlen($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
                $params['email'] = $email;
            if ($this->core->config->get('CloudServiceLog')) {
                $ret = $this->core->jsonDecode($this->get('queue/cf_logs/' . urlencode($app) . '/' . urlencode($type), $params, 'POST'), true);
                if (is_array($ret) && !$ret['success']) $this->addError($ret);
            } else {
                $ret = 'Sending to LogPath not yet implemented';
            }
            return $ret;
        }

        /**
         * Send Cors headers to allow AJAX calls
         * @param string $methods
         * @param string $origin
         * @param string $extra_headers
         */
        function sendCorsHeaders($methods = 'GET,POST,PUT', $origin = '',$extra_headers='')
        {

            if($extra_headers) $extra_headers = ','.$extra_headers;
            // Rules for Cross-Domain AJAX
            // https://developer.mozilla.org/en-US/docs/Web/HTTP/Access_control_CORS
            // $origin =((strlen($_SERVER['HTTP_ORIGIN']))?preg_replace('/\/$/', '', $_SERVER['HTTP_ORIGIN']):'*')
            if (!strlen($origin)) $origin = ((strlen($_SERVER['HTTP_ORIGIN'])) ? preg_replace('/\/$/', '', $_SERVER['HTTP_ORIGIN']) : '*');
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Methods: $methods");
            header("Access-Control-Allow-Headers: Content-Type,Authorization,X-CloudFrameWork-AuthToken,X-CLOUDFRAMEWORK-SECURITY,X-DS-TOKEN,X-REST-TOKEN,X-EXTRA-INFO,X-WEB-KEY,X-SERVER-KEY,X-REST-USERNAME,X-REST-PASSWORD,X-APP-KEY,cache-control,x-requested-with".$extra_headers);
            header("Access-Control-Allow-Credentials: true");
            header('Access-Control-Max-Age: 1000');

            // To avoid angular Cross-Reference
            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
                header("HTTP/1.1 200 OK");
                exit();
            }


        }


        /**
         * Returns a specific Header received in a API call
         * @param $str
         * @return mixed|string
         */
        function getHeader($str)
        {
            $str = strtoupper($str);
            $str = str_replace('-', '_', $str);
            return ((isset($_SERVER['HTTP_' . $str])) ? $_SERVER['HTTP_' . $str] : '');
        }

        /**
         * Return all headers received in a API call
         * @return array
         */
        function getHeaders()
        {
            $ret = array();
            foreach ($_SERVER as $key => $value) if (strpos($key, 'HTTP_') === 0) {
                $ret[str_replace('HTTP_', '', $key)] = $value;
            }
            return ($ret);
        }

        /**
         * Reset $this->errorMsg and $this->error vars
         */
        function reset() {
            $this->errorMsg = [];
            $this->error = false;
        }


    }


    /**
     * Class to manage Data Models
     * @package Core
     */
    Class CoreModel
    {
        var $error = false;
        var $errorMsg = null;
        var $errorCode = null;
        /** @var CloudSQL $db  */
        var $db = null;
        /** @var DataMongoDB $mongoDB  */
        var $mongoDB = null;
        var $cache = null;

        protected $core;
        var $models = null;

        function __construct(Core7 &$core)
        {
            $this->core = $core;
        }

        /**
         * Read models from specific Path
         * @param $path
         * @return bool
         */
        function readModels($path) {

            try {
                $data = json_decode(@file_get_contents($path), true);

                if (!is_array($data)) {
                    $this->addError('error reading ' . $path);
                    if (json_last_error())
                        $this->addError("Wrong format of json: " . $path);
                    elseif (!empty(error_get_last()))
                        $this->addError(error_get_last());
                    return false;
                } else {
                    $this->processModels($data);
                    return true;
                }
            } catch (Exception $e) {
                $this->addError(error_get_last());
                $this->addError($e->getMessage());
                return false;
            }

        }

        /**
         * Read models from CloudFramework
         * @param $models string Models separated by ,
         * @param $api_key string API Key of the licence
         * @return boolean|void
         */
        function readModelsFromCloudFramework($models,$api_key) {

            // To reset cache they have to call $this->resetCache();
            $ret_models = $this->getCache($models);

            if(!$ret_models || isset($ret_models['Unknown'])) {
                $ret_models =  $this->core->request->get_json_decode('https://api7.cloudframework.io/core/models/export',['models'=>$models],['X-WEB-KEY'=>$api_key]);
                if($this->core->request->error)  return($this->addError($this->core->request->errorMsg));
                $ret_models = $ret_models['data'];
                if(!isset($ret_models['Unknown']))
                    $this->updateCache($models,$ret_models);
            }

            if(!$ret_models || isset($ret_models['Unknown']) || !$this->processModels($ret_models)) return($this->addError($ret_models));
            return $ret_models;
        }

        /**
         * Process the model received in models
         * @param $models array
         */
        public function processModels($models) {
            // $models has to be an array
            if(!is_array($models) || !$models) return;

            if(array_key_exists('DataBaseTables',$models) && is_array($models['DataBaseTables']))
                foreach ($models['DataBaseTables'] as $model=>$dataBaseTable) {
                    $this->models['db:'.$model] = ['type'=>'db','data'=>$dataBaseTable];
                }
            if(array_key_exists('DataStoreEntities',$models) && is_array($models['DataStoreEntities']))
                foreach ($models['DataStoreEntities'] as $model=>$dsEntity) {
                    $this->models['ds:'.$model] = ['type'=>'ds','data'=>$dsEntity];
                }
            if(array_key_exists('BigqueryDataSets',$models) && is_array($models['BigqueryDataSets']))
                foreach ($models['BigqueryDataSets'] as $model=>$bqDataset) {
                    $this->models['bq:'.$model] = ['type'=>'bq','data'=>$bqDataset];
                }
            if(array_key_exists('MongoDBCollections',$models) && is_array($models['MongoDBCollections']))
                foreach ($models['MongoDBCollections'] as $model=>$mongoDBColletion) {
                    $this->models['mongodb:'.$model] = ['type'=>'mongodb','data'=>$mongoDBColletion];
                }
            if(array_key_exists('JSONTables',$models) && is_array($models['JSONTables']))
                foreach ($models['JSONTables'] as $model=>$dsEntity) {
                    $this->models['json:'.$model] = ['type'=>'json','data'=>$dsEntity];
                }

            if(array_key_exists('APIUrls',$models) && is_array($models['APIUrls']))
                foreach ($models['APIUrls'] as $model=>$apiUrl) {
                    $this->models['api:'.$model] = ['type'=>'api','data'=>$apiUrl];
                }
            return true;
        }

        /**
         * @param string $object  We expect a '(db|ds):model_name' or just 'model_name'
         * @param array $options  optional options
         *    string $namespace says what namespace to use for datastore objects
         *    string $cf_models_api_key is the API-KEY to use for CloudFrameworkDataModels and read the structure remotelly
         * @return DataStore|DataSQL|void
         */
        public function getModelObject(string $object,$options=[]) {


            //region $this->readModelsFromCloudFramework if $options['cf_models_api_key']
            if(isset($options['cf_models_api_key']) && $options['cf_models_api_key'] && !isset($this->models['db:'.$object]) && !isset($this->models['ds:'.$object]) && !isset($this->models[$object])) {
                if(!$this->readModelsFromCloudFramework($object,$options['cf_models_api_key'])) return;
            }
            //endregion

            // If the model does not include the '(ds|db):' we add it.
            if(!strpos($object,':')) {
                if(isset($this->models['db:'.$object])) $object = 'db:'.$object;
                elseif(isset($this->models['ds:'.$object])) $object = 'ds:'.$object;
                elseif(isset($this->models['bq:'.$object])) $object = 'bq:'.$object;
                else $object = 'api:'.$object;
            }

            // Let's find it and return
            if(!isset($this->models[$object])) return($this->addError("Model $object does not exist",404));
            if(!isset($this->models[$object]['data'])) return($this->addError($object. 'Does not have data',503));

            switch ($this->models[$object]['type']) {
                case "db":
                    list($type,$table) = explode(':',$object,2);

                    if(isset($this->models[$object]['data']['extends'])) {
                        $model_extended = 'db:'.$this->models[$object]['data']['extends'];
                        if(!isset($this->models[$model_extended])) return($this->addError("Model extended $model_extended from model: $object does not exist",404));

                        // Rewrite model if it is defined
                        if(isset($this->models[$object]['data']['model']) && $this->models[$object]['data']['model']) {
                            $this->models[$model_extended]['data']['model'] =  $this->models[$object]['data']['model'];
                        }

                        //Merge variables with the extended object.
                        if(isset($this->models[$object]['data']['interface']) && $this->models[$object]['data']['interface']) foreach ($this->models[$object]['data']['interface'] as $object_property=>$data) {
                            $this->models[$model_extended]['data']['interface'][$object_property] = $data;
                        }
                        $this->models[$object]['data'] = array_merge(['extended_from'=>$table],array_merge($this->models[$model_extended]['data'],array_merge($this->models[$object]['data'],$this->models[$model_extended]['data'])));

                    }
                    // rewrite name of the table
                    if(isset($this->models[$object]['data']['interface']['object'])) $table = $this->models[$object]['data']['interface']['object'];

                    // Object creation
                    if(!is_object($object_db = $this->core->loadClass('DataSQL',[$table,$this->models[$object]['data']]))) return;
                    return($object_db);
                    break;

                case "ds":

                    list($type,$entity) = explode(':',$object,2);
                    $namespace = (isset($options['namespace']))?$options['namespace']:$this->core->config->get('DataStoreSpaceName');
                    if(isset($this->models[$object]['data']['interface']['namespace']) && $this->models[$object]['data']['interface']['namespace']) $namespace=$this->models[$object]['data']['interface']['namespace'];
                    if(empty($namespace)) return($this->addError('Missing DataStoreSpaceName config var or $options["namespace"] paramater'));

                    //region EVALUATE extends the object from others
                    if(isset($this->models[$object]['data']['extends'])) {
                        // look for the model
                        $model_extended = 'ds:'.$this->models[$object]['data']['extends'];
                        if(!isset($this->models[$model_extended])) return($this->addError("Model extended $model_extended from model: $object does not exist",404));

                        // Rewrite model if it is defined
                        if(isset($this->models[$object]['data']['model'])) {
                            $this->models[$model_extended]['data']['model'] =  $this->models[$object]['data']['model'];
                        }

                        //Merge variables with the extended object.
                        if(isset($this->models[$object]['data']['interface'])) foreach ($this->models[$object]['data']['interface'] as $object_property=>$data) {
                            $this->models[$model_extended]['data']['interface'][$object_property] = $data;
                        }

                        $this->models[$object]['data'] = array_merge(['extended_from'=>$entity],array_merge($this->models[$model_extended]['data'],array_merge($this->models[$object]['data'],$this->models[$model_extended]['data'])));
                        $entity = $this->models[$object]['data']['extends'];
                    }
                    //endregion

                    //region REWRITE entity if $this->models[$object]['data']['entity']
                    if(isset($this->models[$object]['data']['entity'])) $entity = $this->models[$object]['data']['entity'];
                    //endregion

                    $project_id = $this->core->config->get('core.gcp.datastore.project_id') ?? $this->core->gc_project_id;
                    $project_id = $this->models[$object]['data']['interface']['project_id'] ?? $project_id;
                    $options = ['projectId'=>$project_id];
                    if(isset($this->models[$object]['data']['interface']['secret']) && $this->models[$object]['data']['interface']['secret']) $options['keyFile'] = $this->models[$object]['data']['interface']['secret'];
                    if(isset($this->models[$object]['data']['interface']['namespace']) && $this->models[$object]['data']['interface']['namespace']) $options['namespace'] = $this->models[$object]['data']['interface']['namespace'];
                    if(!is_object($object_ds = $this->core->loadClass('DataStore',[$entity,$namespace,$this->models[$object]['data'],$options]))) return;

                    return($object_ds);
                    break;

                case "bq":
                    list($type,$dataset) = explode(':',$object,2);

                    // rewrite name of the table
                    if(isset($this->models[$object]['data']['interface']['object'])) $dataset = $this->models[$object]['data']['interface']['object'];

                    // $options
                    $options = ['projectId'=>(isset($this->models[$object]['data']['interface']['project_id']) && $this->models[$object]['data']['interface']['project_id'])?$this->models[$object]['data']['interface']['project_id']: $this->core->gc_project_id];
                    if(isset($this->models[$object]['data']['interface']['secret']) && $this->models[$object]['data']['interface']['secret']) $options['keyFile'] = $this->models[$object]['data']['interface']['secret'];
                    // Object creation
                    if(!is_object($object_bq = $this->core->loadClass('DataBQ',[$dataset,$this->models[$object]['data'],$options]))) return;
                    return($object_bq);
                    break;
            }
            return null;
        }


        /**
         * Returns the array keys of the models
         * @return array
         */
        public function listmodels() {
            if(is_array($this->models)) return array_keys($this->models);
            else return [];
        }

        /**
         * Init a Mongo connection
         * @return bool
         */
        public function mongoInit($uri='') {

            if(null === $this->mongoDB) {
                $this->mongoDB = $this->core->loadClass('DataMongoDB',$uri);
                if(!$this->mongoDB->connect()) $this->addError($this->mongoDB->errorMsg);

            }
            return !$this->mongoDB->error;
        }

        /**
         * Init a DB connection
         * @return bool
         */
        public function dbInit() {

            if(null === $this->db) {
                $this->db = $this->core->loadClass('CloudSQL');
                if(!$this->db->connect()) $this->addError($this->db->getError());
            }
            return !$this->db->error();
        }

        /**
         * Excute the query and return the result if there is no errors
         * @param $SQL
         * @param $params
         * @param $types type format of the fields. Example: ['id':'int(10)']
         * @return array|void
         */
        public function dbQuery($title, $SQL, $params=[],$types=null) {

            if(!is_string($SQL)) return($this->addError('Wrong $SQL method parameter in: dbQuery($title, $SQL, $params=[]) '));
            // Verify we have the object created
            if(!$this->dbInit()) return($this->errorMsg);

            // Execute the query
            $ret = $this->db->getDataFromQuery($SQL,$params);
            if(!$this->db->_onlyCreateQuery)
                $this->core->logs->add($title.' -> '.$this->db->getQuery()." [{$this->db->_lastExecutionMicrotime} secs]",'dbQuery');
            if($this->db->error()) return($this->addError($this->db->getError()));
            else {

                // If there is no type defined return as is
                if(!$types || !is_array($types)) return $ret;
                // else cast it
                else {
                    $number_types = [];
                    foreach ($types as $field=>$type) {
                        if(is_array($type)) $type=$type[0];
                        if(strpos($type,'int')===0 || strpos($type,'bit')===0 ) $number_types[$field] = 'int';
                        else if(strpos($type,'number')===0 || strpos($type,'decimal')===0 || strpos($type,'float')===0) $number_types[$field] = 'float';
                    }

                    if($number_types)
                        foreach ($ret as $i=>$row) {
                            foreach ($row as $field=>$value) if(isset($number_types[$field]) && strlen($value)) {
                                if($number_types[$field]=='int') $value=intval($value);
                                elseif($number_types[$field]=='float') $value=floatval($value);
                                $ret[$i][$field] = $value;
                            }
                        }
                    return $ret;
                }
            }

        }

        /**
         * Update a record into the database
         * @param $title
         * @param $table
         * @param $data
         * @return bool|null|void
         */
        public function dbUpdate($title, $table, &$data) {

            $time = microtime(true);
            // Verify we have the object created
            if(!$this->dbInit()) return($this->errorMsg);

            // Execute the query
            $this->db->cfmode=false; // Deactivate Cloudframework mode.
            $this->db->cloudFrameWork('update',$data,$table);
            $time = round(microtime(true)-$time,4);
            $this->core->logs->add($title." [{$time} secs]",'dbUpdate');

            if($this->db->error()) return($this->addError($this->db->getError()));
            else return true;

        }

        public function dbCommand($title, $q,$params=[]) {

            $time = microtime(true);
            // Verify we have the object created
            if(!$this->dbInit()) return($this->errorMsg);

            // Execute the query
            $this->db->cfmode=false; // Deactivate Cloudframework mode.
            $this->db->command($q,$params);
            $time = round(microtime(true)-$time,4);
            $this->core->logs->add($title." [{$time} secs]",'dbCommand');
            if($this->db->error()) return($this->addError($this->db->getError()));
            else return true;

        }

        /**
         * Upsert a record into the database. If it exist rewrite it
         * @param $title
         * @param $table
         * @param $data
         * @return bool|null|void
         */
        public function dbUpsert($title, $table, &$data) {

            $time = microtime(true);
            // Verify we have the object created
            if(!$this->dbInit()) return($this->errorMsg);

            // Execute the query

            $this->db->cfmode=false; // Deactivate Cloudframework mode.
            if(!isset($data[0])) $data = [$data];
            foreach ($data as $record) {
                $this->db->cloudFrameWork('replace',$record,$table);
                $time = round(microtime(true)-$time,4);
                $this->core->logs->add($title." [{$time} secs]",'dbUpsert');
                if($this->db->error()) return($this->addError($this->db->getError()));
                $time = microtime(true);
            }

            return true;
        }

        /**
         * Insert
         * @param $title
         * @param $table
         * @param $data
         * @return bool|null|void
         */
        public function dbInsert($title, $table, &$data) {

            $time = microtime(true);
            // Verify we have the object created
            if(!$this->dbInit()) return($this->errorMsg);

            // Execute the query

            $this->db->cfmode=false; // Deactivate Cloudframework mode.
            if(!isset($data[0])) $data = [$data];
            foreach ($data as $record) {
                $this->db->cloudFrameWork('insert',$record,$table);
                $time = round(microtime(true)-$time,4);
                $this->core->logs->add($title." [{$time} secs]",'dbInsert');
                if($this->db->error()) return($this->addError($this->db->getError()));
                $time = microtime(true);
            }

            return $this->db->getInsertId();

        }

        /**
         * Delete a record into the database. If it exist rewrite it
         * @param $title
         * @param $table
         * @param $data
         * @return bool|null|void
         */
        public function dbDelete($title, $table, &$data) {

            $time = microtime(true);

            // Verify we have the object created
            if(!$this->dbInit()) return($this->errorMsg);

            // Execute the query
            $this->db->cfmode=false; // Deactivate Cloudframework mode.
            if(!isset($data[0])) $data = [$data];
            foreach ($data as $record) {
                $this->db->cloudFrameWork('delete',$record,$table);
                $time = round(microtime(true)-$time,4);
                $this->core->logs->add($title." [{$time} secs]",'dbDelete');
                if($this->db->error()) return($this->addError($this->db->getError()));
            }

            return true;
        }

        public function dbClose() {
            if(is_object($this->db)) $this->db->close();
        }

        /**
         * Reset Cache of the module
         */
        public function readCache() {
            if($this->cache === null)
                $this->cache = ($this->core->cache->get('Core7.CoreModel'))?:[];
        }

        /**
         * Reset Cache of the module
         */
        public function resetCache() {
            $this->cache = [];
            $this->core->cache->set('Core7.CoreModel',$this->cache);
        }

        /**
         * Update Cache of the module
         */
        public function updateCache($var,$data) {
            $this->readCache();
            $this->cache[$var] = $data;
            $this->core->cache->set('Core7.CoreModel',$this->cache);

        }

        /**
         * Get var Cache of the module
         */
        public function getCache($var) {
            $this->readCache();
            if(isset($this->cache[$var])) return $this->cache[$var];
            else return null;
        }

        private function addError($msg,$code=0) {
            $this->error = true;
            $this->errorCode = $code;
            $this->errorMsg[] = $msg;
        }



    }

    /**
     * Class to Manage Datastore Logs and Bitacora Entries
     * @package Core
     */
    class CFILog
    {
        /** @var Core7 $core */
        var $core;

        /** @var DataStore $dsLogs */
        var $dsLogs;

        /** @var DataStore $dsBitacora */
        var $dsBitacora;

        var $error = false;
        var $errorMsg = null;

        function __construct(Core7 &$core)
        {
            $this->core = $core;
        }

        /**
         * Add a LOG entry in CloudFrameWorkLogs
         * @param $app
         * @param $action string 'ok', 'error', 'check'..
         * @param $title
         * @param $method
         * @param $user
         * @param null|array $data
         * @param null|string $slack_url
         * @param null|array $rewrite_fingerprint if you want to rewrite the default fingerprint send it here
         */
        public function add($app, $action, $title, $method, $user, $data=null, $slack_url=null, $rewrite_fingerprint=null) {
            if(!$this->initDSLogs()) return;

            //region SET $rewrite_fingerprint
            $fingerprint = $this->core->system->getRequestFingerPrint();
            if(!isset($fingerprint['ip'])) $fingerprint['ip'] = $this->core->system->ip;
            if(!isset($fingerprint['http_referer'])) $fingerprint['http_referer'] = 'unknown';
            if($rewrite_fingerprint && is_array($rewrite_fingerprint)){
                if(!isset($rewrite_fingerprint['ip'])) $rewrite_fingerprint['ip'] = 'X.X.X.X';
                if(!isset($rewrite_fingerprint['http_referer'])) $rewrite_fingerprint['http_referer'] = 'unknown';
                $rewrite_fingerprint['local_fingerprint'] = $fingerprint;
            } else {
                $rewrite_fingerprint = $fingerprint;
            }
            //endregion

            //region SET $entity
            $entity = ["Host"=>(isset($_SERVER['HTTP_HOST']))?$_SERVER['HTTP_HOST']:'NO HTTP_HOST'];
            $entity["App"]=$app;
            $entity["Method"]=$method;
            $entity["DateInsertion"]="now";
            $entity["User"]=$user;
            $entity["Ip"]=$rewrite_fingerprint['ip'];
            $entity["Action"]=$action;
            $entity["Title"]=$title;
            $entity["JSON"]=[
                'url'=>(array_key_exists('REQUEST_URI',$_SERVER))?$_SERVER['REQUEST_URI']:''
                ,'http_referer'=> $rewrite_fingerprint['http_referer']
                ,'data'=>$data
                ,'fingerprint'=>$rewrite_fingerprint
            ];
            //endregion

            // If slack_url. Send the post to slack_url
            if($slack_url) {
                $data_slack = ['text'=>"[$user]{$method}:{$app}: {$action}. {$title}"];
                $ret = $this->core->request->post_json_decode($slack_url,$data_slack,['Content-type'=> 'application/json'],true);
                if($this->core->request->error) {
                    $entity["JSON"]['slack_url_error'] = $this->core->request->errorMsg;
                    $this->core->logs->add($this->core->request->errorMsg,'slack_error');
                } else {
                    $this->core->logs->add('slack_url');
                    $entity["JSON"]['slack_url_ok'] = $ret;
                }
            }

            //region $this->dsLogs->createEntities($entity)
            $this->dsLogs->createEntities($entity);
            if ($this->dsLogs->error) {
                $this->addError($this->dsLogs->errorMsg);
                return($this->core->logs->add(['data'=>$entity,'error'=>$this->dsLogs->errorMsg],'CFILog_add_error'));
            } else {
                $this->core->logs->add('CFILog_add_ok');
                return true;
            }
            //endregion
        }

        /**
         * Init $this->dsLog
         */
        private function initDSLogs() {

            if(is_object($this->dsLogs)) return true;
            $model = json_decode('{
                    "Host": ["string","index"],
                    "App": ["string","index"],
                    "Method": ["string","index"],
                    "DateInsertion": ["datetime","index|forcevalue:now"],
                    "Title": ["string","index"],
                    "User": ["string","index"],
                    "Ip": ["string","index"],
                    "Action": ["string","index"],
                    "JSON": ["json","allownull"]
                  }',true);
            $model_bitacora = json_decode('{
                    "Solution": ["string","index"],
                    "App": ["string","index"],
                    "AppId": ["string","index|allowNull"],
                    "DateInsertion": ["datetime","index|forcevalue:now"],
                    "User": ["string","index"],
                    "Action": ["string","index"],
                    "Title": ["string","index"],
                    "Ip": ["string","index"],
                    "JSON": ["json","allownull"]
                  }',true);
            $this->core->model->processModels(['DataStoreEntities'=>['CloudFrameWorkLogs'=>['model'=>$model],'CloudFrameWorkBitacora'=>['model'=>$model_bitacora]]]);
            if($this->core->model->error) return($this->addError($this->core->model->errorMsg));

            $this->dsLogs = $this->core->model->getModelObject('CloudFrameWorkLogs');
            $this->dsBitacora = $this->core->model->getModelObject('CloudFrameWorkBitacora');
            return true;
        }

        /**
         * Add a LOG entry in CloudFrameWorkLogs
         * @param $app
         * @param $action string 'ok', 'error', 'check'..
         * @param $title
         * @param $method
         * @param $user
         * @param $data
         * @param $platform
         * @param $api_key
         * @param true|false $rewrite_fingerprint  // send the fingerprint of the current call
         */
        public function sendToCFService($app, $action, $title, $method, $user, $data,$platform,$api_key,$rewrite_fingerprint=false) {
            //region SET $entity
            $entity = ["App"=>$app];
            $entity["Method"]=$method;
            $entity["User"]=$user;
            $entity["Action"]=$action;
            $entity["Title"]=$title;
            $entity["Data"]=$data;

            // Send the fingerprint from the call to rewrite in the recepcion  of the service
            if($rewrite_fingerprint)
                $entity["Fingerprint"]=$this->core->system->getRequestFingerPrint();
            //endregion

            $this->core->request->reset();
            $this->core->request->post_json_decode('https://api.cloudframework.io/core/logs/'.$platform,$entity,['X-WEB-KEY'=>$api_key]);
            if($this->core->request->error) return($this->addError($this->core->request->errorMsg));

            return true;
        }

        /**
         * Add a LOG entry in CloudFrameWorkBitacora
         * @param $user
         * @param $action string 'inserted'',updated', 'deleted', 'accessed'.. Try to add a past verb over a Solution/App
         * @param $solution
         * @param $app
         * @param $app_id string Id of the app to which receive and action
         * @param $title
         * @param $data null|mixed values to add in bitacora if it is necessary
         */
        public function bitacora($user, $action,$solution, $app, $app_id=null, $title=null, $data=null) {
            if(!$this->initDSLogs()) return;

            //region SET $rewrite_fingerprint
            $fingerprint = $this->core->system->getRequestFingerPrint();
            if(!isset($fingerprint['ip'])) $fingerprint['ip'] = $this->core->system->ip;
            //endregion
            if(!$title) {
                $title = "The user {$user} has {$action}";
                if($app_id) $title.=" [Id: {$app_id}]";
                $title .= " in {$solution}/{$app}";
            }

            //region SET $entity
            $entity = [];
            $entity["User"]=$user;
            $entity["Action"]=$action;
            $entity["Solution"]=$solution;
            $entity["App"]=$app;
            $entity["AppId"]=$app_id;
            $entity["DateInsertion"]="now";
            $entity["Ip"]=$fingerprint['ip'];
            $entity["Title"]=$title;
            $entity["JSON"]=[
                'url'=>(array_key_exists('REQUEST_URI',$_SERVER))?$_SERVER['REQUEST_URI']:''
                ,'http_referer'=> $fingerprint['http_referer']

            ];
            if($data) {
                $entity['JSON']['data'] = $data;
            }
            //endregion

            //region $this->dsBitacora->createEntities($entity)
            $this->dsBitacora->createEntities($entity);
            if ($this->dsBitacora->error) {
                $this->addError($this->dsBitacora->errorMsg);
                return($this->core->logs->add(['data'=>$entity,'error'=>$this->dsLogs->errorMsg],'CFILog_bitacora_error'));
            } else {
                $this->core->logs->add('CFILog_bitacora_ok');
                return true;
            }
            //endregion
        }

        /*
         *  Add an error in the class
         */
        private function addError($msg) {
            $this->error = true;
            $this->errorMsg[] = $msg;
        }

    }

    /**
     * Class to be extended for the creation of a logic application.
     *
     * The sintax is: `Class Logic extends CoreLogic() {..}`
     *
     *
     * Normally your file has to be stored in the `logic/` directory and extend this class.
     * @package Core
     */
    Class CoreLogic2020
    {
        /** @var Core7 $core pointer to the Core class. `$this->core->...` */
        protected $core;

        /** @var string $method indicates the HTTP method used to access the script: GET, POST etc.. Default value is GET */
        var $method = 'GET';

        /** @var array $formParams Contains the variables passed in a GET,POST,PUT call intro an URL  */
        var $formParams = array();

        /** @var array $params contains the substrings paths of an URL script/param0/param1/..  */
        var $params = array();

        /**
         * @var boolean $error Indicates if an error has been produced
         */
        public $error = false;

        /** @var array $errorMsg Keep the error messages  */
        public $errorMsg = [];



        /**
         * CoreLogic constructor.
         * @param Core7 $core
         */
        function __construct(Core7 &$core)
        {
            // Singleton of core
            $this->core = $core;

            // Params
            $this->method = (array_key_exists('REQUEST_METHOD',$_SERVER)) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($this->method == 'GET') {
                $this->formParams = &$_GET;
                if (isset($_GET['_raw_input_']) && strlen($_GET['_raw_input_'])) $this->formParams = (count($this->formParams)) ? array_merge($this->formParams, json_decode($_GET['_raw_input_'], true)) : json_decode($_GET['_raw_input_'], true);
            } else {
                if (count($_GET)) $this->formParams = (count($this->formParams)) ? array_merge($this->formParam, $_GET) : $_GET;
                if (count($_POST)) $this->formParams = (count($this->formParams)) ? array_merge($this->formParams, $_POST) : $_POST;
                // POST
                $raw = null;
                if(isset($_POST['_raw_input_']) && strlen($_POST['_raw_input_'])) $raw = json_decode($_POST['_raw_input_'],true);
                if (is_array($raw)) $this->formParams = (count($this->formParams)) ? array_merge($this->formParams, $raw) : $raw;
                // GET
                $raw = null;
                if(isset($_GET['_raw_input_']) && strlen($_GET['_raw_input_'])) $raw = json_decode($_GET['_raw_input_'],true);
                if (is_array($raw)) $this->formParams = (count($this->formParams)) ? array_merge($this->formParams, $raw) : $raw;
                // RAW DATA
                $input = file_get_contents("php://input");
                if (strlen($input)) {
                    $this->formParams['_raw_input_'] = $input;

                    if (is_object(json_decode($input))) {
                        $input_array = json_decode($input, true);
                    } elseif(strpos($input,"\n") === false && strpos($input,"=")) {
                        parse_str($input, $input_array);
                    }

                    if (is_array($input_array)) {
                        $this->formParams = array_merge($this->formParams, $input_array);
                        unset($input_array);

                    }
                }
                // Trimming fields
                foreach ($this->formParams as $i=>$data) if(is_string($data)) $this->formParams[$i] = trim ($data);
            }

            $this->params = &$this->core->system->url['parts'];

        }

        /**
         * Try to render a template
         * @param string $template Path to the template
         */
        function render($template)
        {
            if(strpos($template,'.htm.twig')) {
                $template = str_replace('.htm.twig','',$template);
                /* @var $rtwig RenderTwig */
                $rtwig = $this->core->loadClass('RenderTwig');
                if(!$rtwig->error) {
                    $path = $this->core->system->app_path;
                    if($path[strlen($path)-1] != '/') $path.='/';
                    $template_path = $path . 'templates/';
                    if($this->core->config->get('core.path.templates')) $template_path = $this->core->config->get('core.path.templates').'/';
                    $rtwig->addFileTemplate($template,$template_path . $template);
                    $rtwig->setTwig($template);
                    echo $rtwig->render();
                } else {
                    $this->addError($rtwig->errorMsg);
                }
            } else {
                try {
                    $template_path = $this->core->system->app_path . '/templates/';
                    if($this->core->config->get('core.path.templates')) $template_path = $this->core->config->get('core.path.templates').'/';
                    include  $template_path. $template;
                } catch (Exception $e) {
                    $this->addError(error_get_last());
                    $this->addError($e->getMessage());
                }
            }

        }

        /*
         * Return the Value from $this->formParams
         * @params $var string var for $this->formParams
         * @return mixed|null returns $this->formParams[$var]
         */
        public function getFormParams($var) {
            return (isset($this->formParams[$var]))?$this->formParams[$var]:null;
        }

        /*
         * Return the Value from $this->params
         * @params $index string var for $this->params
         * @return mixed|null returns $this->params[$index]
         */
        public function getParams($index) {
            return (isset($this->params[$index]))?$this->params[$index]:null;
        }

        /**
         * Add an error in the class
         * @param $value
         */
        function addError($value)
        {
            $this->error = true;
            $this->core->errors->add($value);
            $this->errorMsg[] = $value;
        }
    }

    class Scripts2020 extends CoreLogic2020
    {
        /** @var array $argv Keep the arguments passed to the logic if it runs as a script  */
        public $argv = null;
        var $tests;
        /** @var CoreCache */
        var $cache = null;
        var $cache_secret_key = '';
        var $cache_secret_iv = '';
        var $cache_data = null;
        var $vars = [];
        var $sendTerminal=[];
        var $time = null;

        /**
         * Scripts constructor.
         * @param Core7 $core
         * @param null $argv
         */
        function __construct(Core7 $core, $argv=null)
        {

            parent::__construct($core);
            $this->argv = $argv;

            // Adding $vars
            foreach ($this->argv as $item) {
                if(strpos($item,'--')===0 && strpos($item,'=')) {
                    list($var,$value) = explode('=',$item,2);
                    $this->vars[$var] = $value;
                }
            }
            //endregion

            $this->cache = &$this->core->cache;
            $this->time = microtime(true);

        }

        function hasOption($option) {
            return(in_array('--'.$option, $this->argv));
        }

        function getOptionVar($option) {
            return((isset($this->vars['--'.$option]))?$this->vars['--'.$option]:null);
        }

        function sendTerminal($info='') {
            if(is_string($info)) echo $info."\n";
            else print_r($info);
            if($info)
                $this->sendTerminal[] = "[ ".(round(microtime(true)-$this->time,4))." ms] ".((is_string($info))?$info:json_encode($info));
        }

        function readCache() {
            // Read cache into $this->cache_data if not cache default value [];
            $this->cache_data = ($this->cache->get('Core7_Scripts2020_cache',-1,'',$this->cache_secret_key,$this->cache_secret_iv))?:[];
        }

        function cleanCache() {
            // Read cache into $this->cache_data if not cache default value [];
            $this->cache_data = [];
            $this->cache->set('Core7_Scripts2020_cache',$this->cache_data,'',$this->cache_secret_key,$this->cache_secret_iv);
        }

        function getCacheVar($var) {
            if($this->cache_data === null) $this->readCache();
            return((isset($this->cache_data[$var]))?$this->cache_data[$var]:null);
        }

        function setCacheVar($var,$value) {
            if($this->cache_data === null) $this->readCache();
            $this->cache_data[$var] = $value;
            $this->cache->set('Core7_Scripts2020_cache',$this->cache_data,'',$this->cache_secret_key,$this->cache_secret_iv);
        }

        /**
         * Execute a user Prompt
         * @param $title
         * @param null $default
         * @param null $cache_var
         * @return false|string|null
         */
        function prompt($title,$default=null,$cache_var=null) {

            // Check Cache var
            if($cache_var) {
                $cache_content = $this->cache->get('Core7_Scripts2020_'.$cache_var,-1,'',$this->cache_secret_key,$this->cache_secret_iv);
                if($cache_content) $default = $cache_content;
            }

            // Check default value
            if($default) $title.="[{$default}] ";
            $ret = readline($title);
            if(!$ret) $ret=$default;

            // Set Cache var
            if($cache_var) {
                $this->cache->set('Core7_Scripts2020_'.$cache_var,$ret,'',$this->cache_secret_key,$this->cache_secret_iv);
            }
            return $ret;
        }

        /**
         * Execute a user Prompt user for a specific var
         *  $options['title'] = titlte to be shown
         *  $options['default'] = default value
         *  $options['values'] = [array of valid values]
         *  $options['cache_var'] = 'name to cache the result. If result is cached it rewrites defaul'
         *  $options['type'] = password | number | float
         *  $options['allowed_values'] = allowed values
         *
         * @param $options array array of options
         * @return false|string|null
         */
        function promptVar($options) {

            $title = (isset($options['title']) && $options['title'])?$options['title']:'missing title in prompt';
            $default = (isset($options['default']) && $options['default'])?$options['default']:null;
            $cache_var = (isset($options['cache_var']) && $options['cache_var'])?$options['cache_var']:null;
            $type = (isset($options['type']) && $options['type'])?$options['type']:null;
            $allowed_values = (isset($options['allowed_values']) && is_array($options['allowed_values']))?$options['allowed_values']:null;
            // Check rewrite $default
            if($cache_var && ($cache_content = $this->getCacheVar($options['cache_var']))) $default = $cache_content;

            // Check default value
            if($allowed_values) $title.= ' ['.implode(', ',$allowed_values).']';
            if($default) {
                if($type=='password') $title.=" (*******) :";
                else $title.=" ({$default}) :";
            } else {
                $title.=' :';
            }
            do {
                if($type=='password') {
                    system('stty -echo');
                    echo $title;
                    $ret = trim(fgets(STDIN));
                    echo "\n";
                    system('stty echo');
                    if(!$ret) $ret=$default;
                } else {
                    $ret = readline($title);
                    if(!$ret) $ret=$default;
                }
                $error = ($allowed_values && !in_array($ret,$allowed_values))?true:false;
            } while($error);


            // Set Cache var
            if($cache_var) $this->setCacheVar($cache_var,$ret);

            return $ret;
        }


        /**
         * Add an error in the script. This method exist to be compatible with RESTFull class
         * @param $code
         * @param $msg
         */
        function setErrorFromCodelib($code,$msg) {
            $this->sendTerminal('ERROR: '.$code);
            $this->sendTerminal([$code=>$msg]);
            $this->addError([$code=>$msg]);
        }

        /**
         * Execute a method if $method is defined.
         * @param string $method name of the method
         * @return bool
         */
        function useFunction($method) {
            if(method_exists($this,$method)) {
                $this->$method();
                return true;
            } else {
                return false;
            }
        }

        function getUserGoogleAccessToken()
        {
            $user = $this->getCacheVar('user_readUserGoogleCredentials');
            $user_new='';
            $token=[];
            if($user) $token = $this->getCacheVar($user.'_token_readUserGoogleCredentials');
            if (!$user || !$this->core->is->validEmail($user) || !$token || (microtime(true)-$token['time'] > 3500)) {
                do {
                    $user_new = $this->prompt('Give me your Google User Email: ', $user);
                } while (!$this->core->is->validEmail($user_new)) ;
                $this->setCacheVar('user_readUserGoogleCredentials',$user_new);
            }

            if(!$token || ($user_new && $user != $user_new)) {
                $user = $user_new;
                $token = $this->getCacheVar($user.'_token_readUserGoogleCredentials');
            }

            if(!$token || (microtime(true)-$token['time'] > 3500)) {
                $token=[];
                $gcloud_token_command = 'gcloud auth print-access-token --account='.$user;
                $this->sendTerminal('Gathering token for account: '.$user);
                $auth['token'] = shell_exec($gcloud_token_command);
                if(!$auth['token']) return($this->addError('The following command does not work: '.$gcloud_token_command));
                $token_info = $this->core->request->post_json_decode('https://www.googleapis.com/oauth2/v1/tokeninfo',['access_token'=>$auth['token']],['Content-Type'=>'application/x-www-form-urlencoded']);
                if($this->core->request->error)
                    return($this->addError($this->core->request->errorMsg));

                $token['time'] = microtime(true);
                $token['token'] = $auth['token'];
                $token['email'] = $user;
                $token['info'] = $token_info;
                $this->setCacheVar($user.'_token_readUserGoogleCredentials',$token);
            } else {
                $this->sendTerminal('Using Google Access Token for User: '.$user);
            }
            return($token);
        }
    }
}