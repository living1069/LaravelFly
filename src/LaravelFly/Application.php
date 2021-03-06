<?php

namespace LaravelFly;

use Illuminate\Events\EventServiceProvider;
//use LaravelFly\Routing\RoutingServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

class Application extends \Illuminate\Foundation\Application
{
    protected $bootedInRequest = false;

    protected $needBackUpAppAttributes = [
        'bindings', 'resolved', 'instances', 'aliases', 'extenders', 'tags', 'contextual',
        // 'buildStack ',
        'bootingCallbacks', 'bootedCallbacks', 'terminatingCallbacks', 'reboundCallbacks',
        'globalResolvingCallbacks', 'globalAfterResolvingCallbacks',
        'afterResolvingCallbacks', 'resolvingCallbacks',

    ];
    protected $__oldValues = [];

    protected $needBackupServiceAttributes = [];
    protected $restoreTool = [];

    protected $needBackupConfigs = [];

    protected $providerRepInRequest;

    public function setNeedBackupConfigs($need)
    {
        $this->needBackupConfigs = $need;
    }

    public function setNeedBackupServiceAttributes($need)
    {
        $this->needBackupServiceAttributes = $need;
    }

    public function prepareForProvidersInRequest($ps)
    {
        $this->makeManifestForProvidersInRequest($ps);
        $this->needBackUpAppAttributes = array_merge($this->needBackUpAppAttributes,
            ['serviceProviders', 'loadedProviders', 'deferredServices',]);
    }

    public function makeManifestForProvidersInRequest($providers)
    {
        $manifestPath = $this->getCachedServicesPathInRequest();
        $this->providerRepInRequest = new ProviderRepositoryInRequest($this, new Filesystem, $manifestPath);
        $this->providerRepInRequest->makeManifest($providers);
    }


    public function registerConfiguredProvidersInRequest()
    {
        $this->providerRepInRequest->load([]);
    }

    public function getCachedServicesPathInRequest()
    {
        return $this->bootstrapPath() . '/cache/laravelfly_services_in_request.json';
    }

    /**
     * replace \Illuminate\Foundation\Bootstrap\BootProviders::class,
     */
    public function bootProvidersInRequest()
    {
        if ($this->bootedInRequest) {
            return;
        }

        $this->fireAppCallbacks($this->bootingCallbacks);


        /**  array_walk
         * If when a provider booting, it reg some other providers,
         * then the new providers added to $this->serviceProviders
         * then array_walk will loop the new ones and boot them. // pingpong/modules 2.0 use this feature
         */
        array_walk($this->serviceProviders, function ($p) {
            $this->bootProvider($p);
        });

        $this->bootedInRequest = true;

        //todo it should be changed
        $this->fireAppCallbacks($this->bootedCallbacks);
    }

    public function addDeferredServices(array $services)
    {
        $this->deferredServices = array_merge($this->deferredServices, $services);
        //todo reduce numbers of deferredServices
//        var_dump(count(  $this->deferredServices)) ;
    }

    public function backUpOnWorker()
    {
        foreach ($this->needBackUpAppAttributes as $attri) {

            $this->__oldValues[$attri] = $this->$attri;

        }

        foreach ($this->needBackupServiceAttributes as $name => $attris) {
            $o = $this->instances[$name] ?? $this->make($name);
            $backuper = $this->backupToolMaker($attris);
            $backuper = $backuper->bindTo($o, get_class($o));
            $backuper();

            $this->restoreTool[$name] = $this->restoreToolMaker()->bindTo($o, get_class($o));
        }

    }

    // Accessing private PHP class members without reflection
    // http://ocramius.github.io/blog/accessing-private-php-class-members-without-reflection/
    protected function backupToolMaker($attriList)
    {
        return function () use ($attriList) {
            $this->__old = [];
            $this->__oldObj = [];

            if (isset($attriList['__obj__'])) {
                foreach ($attriList['__obj__'] as $obj => $attris) {
                    if (empty($attris)) {
                        continue;
                    }
                    if (!property_exists($this, $obj)){
                        echo "[WARN] check config\laravelfly.php, property '$obj' not exists for ", get_class($this);
                        continue;
                    }
                    $o = $this->$obj;
                    $info = ['obj' => $o, 'r_props' => [], 'values' => []];
                    $r = new \ReflectionObject($o);
                    foreach ($attris as $attr) {
                        $r_attr = $r->getProperty($attr);
                        $r_attr->setAccessible(true);
                        $info['r_props'][$attr] = $r_attr;
                        $info['values'][$attr] = $r_attr->getValue($o);
                    }
                    $this->__oldObj[] = $info;
//                    $backuper = $app->backupToolMaker($attris);
//                    $backuper = $backuper->bindTo($o, get_class($o));
//                    $backuper();

                }

                unset($attriList['__obj__']);
            }

            foreach ($attriList as $attri) {

                if (property_exists($this, $attri))
                    $this->__old[$attri] = $this->$attri;
                else {
                    echo "[WARN]check config\laravelfly.php,property '$attri' not exists for ", get_class($this);

                }
            }
        };
    }

    protected function restoreToolMaker()
    {
        return function () {
            foreach ($this->__old as $name => $v) {
                $this->$name = $v;
            }

            if ($this->__oldObj) {
//                return;
                foreach ($this->__oldObj as $info) {
//                    var_dump(app('view')->finder->views);
                    foreach ($info['r_props'] as $s_attr => $r_attr) {
                        $r_attr->setValue($info['obj'], $info['values'][$s_attr]);
                    }
//                    var_dump(app('view')->finder->views);
                }
            }
        };
    }


    public function restoreAfterRequest()
    {

        if ($this->needBackupConfigs) {
            $this->make('config')->set($this->needBackupConfigs);
        }

        // clear all, not just request
        Facade::clearResolvedInstances();

        foreach ($this->__oldValues as $attri => $v) {
//            echo "\n $attri\n";
//            if (is_array($this->$attri))
//                echo 'dif:', count($this->$attri) - count($this->__oldValues[$attri]);
            $this->$attri = $v;
        }


        foreach ($this->restoreTool as $tool) {
            $tool();
        }

        $this->bootedInRequest = false;
    }

    /**
     * Override
     */
    public function runningInConsole()
    {
        if (defined('HONEST_IN_CONSOLE')) {
            return HONEST_IN_CONSOLE;
        } else {
            return parent::runningInConsole();
        }
    }

}
