<?php

namespace App\Livewire\Project\Database\Valkey;

use App\Models\StandaloneValkey;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandaloneValkey $database;

    protected function databaseLabel(): string
    {
        return 'Valkey';
    }

    protected function showPublicUrlPlaceholder(): bool
    {
        return true;
    }
}
