<?php

namespace App\Actions\Database;

use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
use App\Models\StandaloneValkey;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class FlushDatabase
{
    use AsAction;

    public function handle(StandaloneRedis|StandaloneKeydb|StandaloneValkey|StandaloneDragonfly $database): void
    {
        $cli = match (true) {
            $database instanceof StandaloneKeydb => 'keydb-cli',
            $database instanceof StandaloneValkey => 'valkey-cli',
            default => 'redis-cli',
        };
        if ($database instanceof StandaloneDragonfly && $database->enable_ssl) {
            $cli .= ' --tls --cacert /etc/dragonfly/certs/coolify-ca.crt --cert /etc/dragonfly/certs/server.crt --key /etc/dragonfly/certs/server.key';
        }

        $output = instant_remote_process(
            ["docker exec {$database->uuid} sh -c 'REDISCLI_AUTH=\"\$REDIS_PASSWORD\" {$cli} FLUSHALL ASYNC'"],
            $database->destination->server,
        );

        if ($output !== 'OK') {
            throw new RuntimeException($output ?: 'Failed to flush the database.');
        }
    }
}
