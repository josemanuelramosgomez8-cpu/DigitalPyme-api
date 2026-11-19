<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class ProjectStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Project $project,
        public string $previousStatus,
        public string $status,
    ) {}
}
