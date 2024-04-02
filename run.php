<?php

define('STANDBY_TIMEOUT', 600);
define('UPDATE_INTERVAL', 10);
define('UPDATE_INTERVAL_THRESHOLD', 30);
define('APP_PATH', __DIR__);

//

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Process\Process;
use Monolog\Logger;
use Nicodinus\HddSleep\DiskCollector;
use Nicodinus\HddSleep\DiskMonitor;
use Revolt\EventLoop;
use function Amp\async;

require_once __DIR__ . "/vendor/autoload.php";

EventLoop::onSignal(SIGINT|SIGTERM, function () use (&$logger) {
    $logger->info("Exit...");
    exit(0);
});

$handler = new StreamHandler(ByteStream\getStdout());
$handler->setFormatter(new ConsoleFormatter("%channel%.%level_name%: %message% %context% %extra%\r\n", null, true, true));

$logger = new Logger('hdd-sleep', [], [], new DateTimeZone('Europe/Moscow'));
$logger->pushHandler($handler);

$watchers = [];

$forceStandbyModeCallable = static function (DiskMonitor $monitor, bool $isEpcAvailable) use (&$logger, &$watchers) {

    unset($watchers[$monitor->getBlockDevice()]);

    $currentStatus = DiskCollector::checkStatus("/dev/{$monitor->getBlockDevice()}");
    if ($currentStatus === "standby z") {
        $logger->debug("{$monitor->getBlockDevice()} already in standby mode");
        return;
    }

    $logger->info("{$monitor->getBlockDevice()} force standby mode ". ($isEpcAvailable ? "(wdepc)" : "(hdparm)"));

    async(static function ($dev) use (&$isEpcAvailable) {

        if (!$isEpcAvailable) {
            $process = Process::start("hdparm -Y {$dev}");
        } else {
            $process = Process::start(APP_PATH . "/bin/wdepc -d {$dev} set standby_z");
        }

        ByteStream\pipe($process->getStdout(), ByteStream\getStdout());
        ByteStream\pipe($process->getStderr(), ByteStream\getStderr());

        $process->join();

    }, "/dev/{$monitor->getBlockDevice()}")->await();

};

foreach (DiskCollector::fetchOnly('hdd') as $blockDeviceStruct) {

    $blockDevice = $blockDeviceStruct['name'];

    $isEpcAvailable = DiskCollector::checkEpc("/dev/{$blockDevice}") !== false;

    $monitor = new DiskMonitor($blockDevice, UPDATE_INTERVAL);
    $logger->info("monitor initialized for block device {$blockDevice}" . ($isEpcAvailable ? ", epc is available" : ""));

    $currentStatus = DiskCollector::checkStatus("/dev/{$blockDevice}");
    if ($currentStatus !== "standby z") {

        $logger->debug("set standby delay for {$monitor->getBlockDevice()}, current status is {$currentStatus}");
        $watchers[$monitor->getBlockDevice()] = EventLoop::delay(STANDBY_TIMEOUT, static function () use ($monitor, $isEpcAvailable, &$logger, &$watchers, &$forceStandbyModeCallable) {
            $forceStandbyModeCallable($monitor, $isEpcAvailable);
        });
    }

    $monitor->registerCallback(static function (DiskMonitor $monitor) use ($isEpcAvailable, &$logger, &$watchers, &$forceStandbyModeCallable) {

        if (isset($watchers[$monitor->getBlockDevice()])) {
            EventLoop::cancel($watchers[$monitor->getBlockDevice()]);
            unset($watchers[$monitor->getBlockDevice()]);
        }

        $watchers[$monitor->getBlockDevice()] = EventLoop::delay(UPDATE_INTERVAL_THRESHOLD, static function () use ($monitor, $isEpcAvailable, &$logger, &$watchers, &$forceStandbyModeCallable) {

            $logger->debug("{$monitor->getBlockDevice()} last update {$monitor->getLastUpdated()} last activity {$monitor->getLastActivity()}");
            $logger->debug("set standby delay for {$monitor->getBlockDevice()}");

            $watchers[$monitor->getBlockDevice()] = EventLoop::delay(STANDBY_TIMEOUT, static function () use ($monitor, $isEpcAvailable, &$logger, &$watchers, &$forceStandbyModeCallable) {
                $forceStandbyModeCallable($monitor, $isEpcAvailable);
            });

        });

    });
}

EventLoop::run();