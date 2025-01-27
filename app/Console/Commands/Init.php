<?php

namespace App\Console\Commands;

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\CleanupHelperContainersJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class Init extends Command
{
    protected $signature = 'app:init {--cleanup}';
    protected $description = 'Cleanup instance related stuffs';

    public function handle()
    {
        $this->alive();
        $cleanup = $this->option('cleanup');
        if ($cleanup) {
            echo "Running cleanups...\n";
            $this->call('cleanup:stucked-resources');
            // Required for falsely deleted coolify db
            $this->restore_coolify_db_backup();

            // $this->cleanup_ssh();
        }
        $this->cleanup_in_progress_application_deployments();
        $this->cleanup_stucked_helper_containers();

        try {
            setup_dynamic_configuration();
        } catch (\Throwable $e) {
            echo "Could not setup dynamic configuration: {$e->getMessage()}\n";
        }

        $settings = InstanceSettings::get();
        if (!is_null(env('AUTOUPDATE', null))) {
            if (env('AUTOUPDATE') == true) {
                $settings->update(['is_auto_update_enabled' => true]);
            } else {
                $settings->update(['is_auto_update_enabled' => false]);
            }
        }
        $this->call('cleanup:queue');
    }
    private function restore_coolify_db_backup()
    {
        try {
            $database = StandalonePostgresql::withTrashed()->find(0);
            if ($database && $database->trashed()) {
                echo "Restoring coolify db backup\n";
                $database->restore();
                $scheduledBackup = ScheduledDatabaseBackup::find(0);
                if (!$scheduledBackup) {
                    ScheduledDatabaseBackup::create([
                        'id' => 0,
                        'enabled' => true,
                        'save_s3' => false,
                        'frequency' => '0 0 * * *',
                        'database_id' => $database->id,
                        'database_type' => 'App\Models\StandalonePostgresql',
                        'team_id' => 0,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            echo "Error in restoring coolify db backup: {$e->getMessage()}\n";
        }
    }
    private function cleanup_stucked_helper_containers()
    {
        $servers = Server::all();
        foreach ($servers as $server) {
            if ($server->isFunctional()) {
                CleanupHelperContainersJob::dispatch($server);
            }
        }
    }
    private function alive()
    {
        $id = config('app.id');
        $version = config('version');
        $settings = InstanceSettings::get();
        $do_not_track = data_get($settings, 'do_not_track');
        if ($do_not_track == true) {
            echo "Skipping alive as do_not_track is enabled\n";
            return;
        }
        try {
            Http::get("https://undead.coolify.io/v4/alive?appId=$id&version=$version");
            echo "I am alive!\n";
        } catch (\Throwable $e) {
            echo "Error in alive: {$e->getMessage()}\n";
        }
    }
    // private function cleanup_ssh()
    // {

    // TODO: it will cleanup id.root@host.docker.internal
    //     try {
    //         $files = Storage::allFiles('ssh/keys');
    //         foreach ($files as $file) {
    //             Storage::delete($file);
    //         }
    //         $files = Storage::allFiles('ssh/mux');
    //         foreach ($files as $file) {
    //             Storage::delete($file);
    //         }
    //     } catch (\Throwable $e) {
    //         echo "Error in cleaning ssh: {$e->getMessage()}\n";
    //     }
    // }
    private function cleanup_in_progress_application_deployments()
    {
        // Cleanup any failed deployments

        try {
            $halted_deployments = ApplicationDeploymentQueue::where('status', '==', ApplicationDeploymentStatus::IN_PROGRESS)->where('status', '==', ApplicationDeploymentStatus::QUEUED)->get();
            foreach ($halted_deployments as $deployment) {
                $deployment->status = ApplicationDeploymentStatus::FAILED->value;
                $deployment->save();
            }
        } catch (\Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }

}
