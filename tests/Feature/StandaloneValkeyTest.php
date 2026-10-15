<?php

use App\Actions\Database\StartValkey;
use App\Livewire\Project\Database\Valkey\General as ValkeyGeneral;
use App\Livewire\Project\New\Select;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneValkey;
use App\Models\Team;
use App\Models\User;
use App\Services\DatabaseStartCommandExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));
    Process::fake();
    Queue::fake();
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->executor = new class
    {
        public array $commands = [];

        public function execute(array $commands, $database, Activity $activity): Activity
        {
            $this->commands = $commands;

            return $activity;
        }
    };
    app()->instance(DatabaseStartCommandExecutor::class, $this->executor);
});

/**
 * @return array<string, mixed>
 */
function startedValkeyService(object $executor, string $uuid): array
{
    foreach ($executor->commands as $command) {
        if (preg_match("#^echo '([^']+)' \| base64 -d \| tee \S+/docker-compose\.yml#", $command, $matches)) {
            return Yaml::parse(base64_decode($matches[1]))['services'][$uuid];
        }
    }

    throw new RuntimeException('No docker-compose.yml was written.');
}

function valkeyApiHeaders(User $user, int $teamId): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'valkey-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['root'],
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

it('creates a Valkey database with a generated password and a data volume', function () {
    $database = create_standalone_valkey($this->environment->id, $this->destination);

    expect($database->type())->toBe('standalone-valkey')
        ->and($database->name)->toBe('valkey-database-'.$database->uuid)
        ->and($database->fresh()->image)->toBe('valkey/valkey:9')
        ->and(strlen($database->valkey_password))->toBe(64)
        ->and($database->persistentStorages()->sole()->only(['name', 'mount_path']))
        ->toBe(['name' => 'valkey-data-'.$database->uuid, 'mount_path' => '/data']);
});

it('builds password-only connection urls', function () {
    $database = create_standalone_valkey($this->environment->id, $this->destination, ['valkey_password' => 'p@ss']);

    expect($database->internal_db_url)->toBe("redis://:p%40ss@{$database->uuid}:6379/0");

    $database->update(['enable_ssl' => true]);

    expect($database->fresh()->internal_db_url)->toBe("rediss://:p%40ss@{$database->uuid}:6380/0");
});

it('starts valkey-server with the quoted password and a valkey-cli healthcheck', function () {
    $database = create_standalone_valkey($this->environment->id, $this->destination, ['valkey_password' => "it's secret"]);

    StartValkey::run($database->fresh(), new Activity);

    $service = startedValkeyService($this->executor, $database->uuid);
    expect($service['image'])->toBe('valkey/valkey:9')
        ->and($service['command'])->toBe("valkey-server --requirepass 'it'\\''s secret' --appendonly yes")
        ->and($service['healthcheck']['test'])->toBe(['CMD', 'valkey-cli', '--pass', "it's secret", 'ping'])
        ->and($service['environment'])->toContain("REDIS_PASSWORD=it's secret")
        ->and($service['volumes'])->toContain("valkey-data-{$database->uuid}:/data");
});

it('mounts a custom valkey.conf and only adds the password when the conf has no requirepass', function (string $conf, string $expectedCommand) {
    $database = create_standalone_valkey($this->environment->id, $this->destination, [
        'valkey_password' => 'secret',
        'valkey_conf' => $conf,
    ]);

    StartValkey::run($database->fresh(), new Activity);

    $service = startedValkeyService($this->executor, $database->uuid);
    $configurationDir = database_configuration_dir()."/{$database->uuid}";
    expect($service['command'])->toBe($expectedCommand)
        ->and($service['healthcheck']['test'])->toBe(['CMD', 'valkey-cli', '--pass', 'secret', 'ping'])
        ->and($service['volumes'])->toContain([
            'type' => 'bind',
            'source' => "{$configurationDir}/valkey.conf",
            'target' => '/usr/local/etc/valkey/valkey.conf',
            'read_only' => true,
        ])
        ->and($this->executor->commands)->toContain("echo '".base64_encode($conf)."' | base64 -d | tee {$configurationDir}/valkey.conf > /dev/null")
        ->and($this->executor->commands)->toContain("chown 999:999 {$configurationDir}/valkey.conf");
})->with([
    'without requirepass' => ['maxmemory 256mb', "valkey-server /usr/local/etc/valkey/valkey.conf --requirepass 'secret'"],
    'with requirepass' => ["requirepass fromconf\nmaxmemory 256mb", 'valkey-server /usr/local/etc/valkey/valkey.conf'],
]);

it('uses a REDIS_PASSWORD variable instead of the stored password', function () {
    $database = create_standalone_valkey($this->environment->id, $this->destination, ['valkey_password' => 'stored']);
    EnvironmentVariable::create([
        'key' => 'REDIS_PASSWORD',
        'value' => 'from-variable',
        'resourceable_type' => $database->getMorphClass(),
        'resourceable_id' => $database->id,
    ]);

    StartValkey::run($database->fresh(), new Activity);

    $service = startedValkeyService($this->executor, $database->uuid);
    expect($service['command'])->toBe("valkey-server --requirepass 'from-variable' --appendonly yes")
        ->and($service['healthcheck']['test'][3])->toBe('from-variable')
        ->and($service['environment'])->toContain('REDIS_PASSWORD=from-variable')
        ->and($database->fresh()->internal_db_url)->toBe("redis://:from-variable@{$database->uuid}:6379/0")
        ->and($database->fresh()->valkey_password)->toBe('stored');
});

it('makes the password read-only once the database has started', function () {
    $database = create_standalone_valkey($this->environment->id, $this->destination, ['valkey_password' => 'old-password']);
    $database->forceFill(['started_at' => now()])->save();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(ValkeyGeneral::class, ['database' => $database->fresh()])
        ->assertSee('You can only change this in the database')
        ->assertDontSee('Verify the initial credentials');
});

it('shows the password to an admin of the database team while another team is selected', function () {
    $database = create_standalone_valkey($this->environment->id, $this->destination, ['valkey_password' => 'stored-password']);
    $admin = User::factory()->create();
    $this->team->members()->attach($admin->id, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($admin->id, ['role' => 'member']);

    $this->actingAs($admin);
    session(['currentTeam' => $otherTeam]);

    Livewire::test(ValkeyGeneral::class, ['database' => $database->fresh()])
        ->assertSet('isPasswordHiddenForMember', false)
        ->assertSet('valkeyPassword', 'stored-password');
});

it('hides the password from a member of the database team while an owned team is selected', function () {
    $database = create_standalone_valkey($this->environment->id, $this->destination, ['valkey_password' => 'stored-password']);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $ownedTeam = Team::factory()->create();
    $ownedTeam->members()->attach($member->id, ['role' => 'owner']);

    $this->actingAs($member);
    session(['currentTeam' => $ownedTeam]);

    Livewire::test(ValkeyGeneral::class, ['database' => $database->fresh()])
        ->assertSet('isPasswordHiddenForMember', true)
        ->assertSet('valkeyPassword', '');
});

it('lists Valkey as a new database option', function () {
    $databases = collect((new Select)->loadServices()['databases'])->keyBy('id');

    expect($databases['valkey']['name'])->toBe('Valkey')
        ->and($databases['valkey']['logo'])->toBe(resource_logo_url('valkey'));
});

it('creates and updates a Valkey database through the API', function () {
    $headers = valkeyApiHeaders($this->user, $this->team->id);

    $response = $this->withHeaders($headers)
        ->postJson('/api/v1/databases/valkey', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'valkey_password' => 'api-password',
            'valkey_conf' => base64_encode('maxmemory 128mb'),
        ])
        ->assertCreated();

    $database = StandaloneValkey::query()->where('uuid', $response->json('uuid'))->sole();
    expect($database->valkey_password)->toBe('api-password')
        ->and($database->valkey_conf)->toBe('maxmemory 128mb')
        ->and($response->json('internal_db_url'))->toBe("redis://:api-password@{$database->uuid}:6379/0");

    $this->withHeaders($headers)
        ->patchJson("/api/v1/databases/{$database->uuid}", ['valkey_conf' => base64_encode('maxmemory 512mb')])
        ->assertOk();

    expect($database->fresh()->valkey_conf)->toBe('maxmemory 512mb');

    $this->withHeaders($headers)
        ->getJson("/api/v1/databases/{$database->uuid}")
        ->assertOk()
        ->assertJsonPath('valkey_password', 'api-password');
});

it('rejects Valkey API fields that belong to other databases', function () {
    $this->withHeaders(valkeyApiHeaders($this->user, $this->team->id))
        ->postJson('/api/v1/databases/valkey', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'keydb_password' => 'not-allowed',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['keydb_password']);

    expect(StandaloneValkey::query()->count())->toBe(0);
});
