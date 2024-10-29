<?php

namespace Mak\Driver\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Connection as ConnectionBase;
use Mak\Driver\Connection;

class DriverServiceProvider extends ServiceProvider
{
    public function register()
    {
        ConnectionBase::resolverFor('api-driver', static function ($connection, $database, $prefix, $config) {
            return new Connection($connection, $database, $prefix, $config);
        });
    }

    public function boot() {}
}
