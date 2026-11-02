<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetLocalClientTestPassword extends Command
{
    protected $signature = 'client:password:local-test';

    protected $description = 'Set the shared test password for all local client portal accounts';

    public function handle(): int
    {
        if (config('app.env') !== 'local') {
            $this->error('This command is only available when APP_ENV=local.');

            return self::FAILURE;
        }

        $count = DB::transaction(function (): int {
            $users = User::query()
                ->where('is_client', true)
                ->whereHas('client')
                ->get();

            foreach ($users as $user) {
                $user->password = 'password';
                $user->save();
                $user->tokens()->delete();
            }

            return $users->count();
        });

        $this->info("Updated {$count} local client portal account(s).");
        $this->warn('All local client accounts now share a test-only password. Never use this password in production.');

        return self::SUCCESS;
    }
}
