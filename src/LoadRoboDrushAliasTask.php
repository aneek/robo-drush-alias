<?php

declare(strict_types = 1);

namespace Aneek\Robo\DrushAlias;

use Aneek\Robo\DrushAlias\Task\DrushAliasTask;

/**
 * Trait LoadRoboDrushAliasTask
 *
 * This trait is responsible for loading Robo Drush aliases into the task.
 */
trait LoadRoboDrushAliasTask
{
    public function taskRoboDrushAlias(string $key, string $secret, string $uuid, iterable $config = [])
    {
        $config['key'] = $key;
        $config['secret'] = $secret;
        $config['application_uuid'] = $uuid;

        return $this->task(DrushAliasTask::class, $config);
    }
}