<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Spatie\Url\Url;

function queue_application_deployment(Application $application, string $deployment_uuid, int | null $pull_request_id = 0, string $commit = 'HEAD', bool $force_rebuild = false, bool $is_webhook = false, bool $restart_only = false, ?string $git_type = null)
{
    $application_id = $application->id;
    $deployment_link = Url::fromString($application->link() . "/deployment/{$deployment_uuid}");
    $deployment_url = $deployment_link->getPath();
    $server_id = $application->destination->server->id;
    $server_name = $application->destination->server->name;
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application_id,
        'application_name' => $application->name,
        'server_id' => $server_id,
        'server_name' => $server_name,
        'deployment_uuid' => $deployment_uuid,
        'deployment_url' => $deployment_url,
        'pull_request_id' => $pull_request_id,
        'force_rebuild' => $force_rebuild,
        'is_webhook' => $is_webhook,
        'restart_only' => $restart_only,
        'commit' => $commit,
        'git_type' => $git_type
    ]);

    if (next_queuable($server_id, $application_id)) {
        dispatch(new ApplicationDeploymentJob(
            application_deployment_queue_id: $deployment->id,
        ));
    }
}

function queue_next_deployment(Application $application)
{
    $server_id = $application->destination->server_id;
    $next_found = ApplicationDeploymentQueue::where('server_id', $server_id)->where('status', 'queued')->get()->sortBy('created_at')->first();
    if ($next_found) {
        dispatch(new ApplicationDeploymentJob(
            application_deployment_queue_id: $next_found->id,
        ));
    }
}

function next_queuable(string $server_id, string $application_id): bool
{
    $deployments = ApplicationDeploymentQueue::where('server_id', $server_id)->whereIn('status', ['in_progress', 'queued'])->get()->sortByDesc('created_at');
    $same_application_deployments = $deployments->where('application_id', $application_id);
    $in_progress = $same_application_deployments->filter(function ($value, $key) {
        return $value->status === 'in_progress';
    });
    if ($in_progress->count() > 0) {
        return false;
    }
    $server = Server::find($server_id);
    $concurrent_builds = $server->settings->concurrent_builds;

    ray("serverId:{$server->id}", "concurrentBuilds:{$concurrent_builds}", "deployments:{$deployments->count()}", "sameApplicationDeployments:{$same_application_deployments->count()}");

    if ($deployments->count() > $concurrent_builds) {
        return false;
    }
    return true;
}
