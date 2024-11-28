<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://github.com/phpstreamserver/.github/blob/main/assets/phpss_core_light.svg">
    <img alt="PHPStreamServer logo" align="center" width="70%" src="https://raw.githubusercontent.com/phpstreamserver/.github/refs/heads/main/assets/phpss_core_dark.svg">
  </picture>
</p>

## PHPStreamServer - PHP Application Server
![PHP >=8.2](https://img.shields.io/badge/PHP->=8.2-777bb3.svg)
![Version](https://img.shields.io/github/v/tag/phpstreamserver/phpstreamserver?label=Version&filter=v*.*.*&sort=semver&color=374151)

**PHPStreamServer** is a high performance, event-loop based application server and process supervisor for PHP written in PHP.
As the core component of PHPStreamServer, this module is responsible for comprehensive worker management.

### Features
- Worker lifecycle management: Handles the creation, monitoring, and termination of worker processes.
- Automatic restarts: Automatically restarts workers in case of an exception or upon reaching specified limits.
- Time-to-live limits: Sets execution time limits for worker processes.
- Memory usage limits: Set memory usage limits to prevent memory leaks.
- Support for external programs: Use all of these to enable the supervision of external programs and processes.
- Resource sharing across workers: Preload any resources in a master process, and it will be shared among all workers reducing memory usage.

### Requirements and limitations
 - Unix based OS (no windows support);
 - php-posix and php-pcntl extensions;

### Install
```bash
$ composer require phpstreamserver/core
```

### Configure
Here is an example of a simple supervisor server configuration.

```php
// server.php

use PHPStreamServer\Core\Plugin\Supervisor\ExternalProcess;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerProcess;
use PHPStreamServer\Core\Server;

$server = new Server();

$server->addWorker(
    new WorkerProcess(
        name: 'Supervised Program',
        count: 1,
        onStart: function (WorkerProcess $worker): void {
            // custom long running process
        },
    ),
    new ExternalProcess(
        name: 'External supervised program',
        count: 1,
        command: 'sleep 600'
    ),
);

exit($server->run());
```

### Run
```bash
$ php server.php start
```
