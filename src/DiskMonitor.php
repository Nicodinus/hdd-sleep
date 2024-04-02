<?php

namespace Nicodinus\HddSleep;

use Ramsey\Uuid\Uuid;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\File\filesystem;
use function Amp\Future\await;

class DiskMonitor
{
    /** @var string */
    private string $blockDevice;

    /** @var int|float|null */
    private int|float|null $lastUpdated;

    /** @var float */
    private float $updateInterval;

    /** @var int|float|null */
    private int|float|null $lastActivity;

    /** @var int|null */
    private ?int $lastStatCounter;

    /** @var string */
    private string $watcher;

    /** @var callable[] $callbacks */
    private $callbacks;

    //

    /**
     * @param string $blockDevice /sys/block/NAME
     * @param float $updateInterval ms
     */
    public function __construct(string $blockDevice, float $updateInterval = 1)
    {
        $this->blockDevice = $blockDevice;

        if ($updateInterval < 0.001) {
            throw new \InvalidArgumentException("Update interval cannot be less than 1 ms");
        }
        $this->updateInterval = $updateInterval;

        $this->lastUpdated = null;
        $this->lastActivity = null;
        $this->lastStatCounter = null;

        $this->callbacks = [];

        $this->watcher = EventLoop::repeat($this->updateInterval, $this->_monitorHandle(...));
        EventLoop::unreference($this->watcher);
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        EventLoop::cancel($this->watcher);
    }

    /**
     * @param callable $callback
     *
     * @return string
     */
    public function registerCallback(callable $callback): string
    {
        do {
            $uuid = Uuid::uuid4();
        } while (isset($this->callbacks[$uuid->toString()]));

        $this->callbacks[$uuid->toString()] = $callback;
        return $uuid->toString();
    }

    /**
     * @param string $uuid
     *
     * @return void
     */
    public function unregisterCallback(string $uuid): void
    {
        unset($this->callbacks[$uuid]);
    }

    /**
     * @return void
     */
    protected function _monitorHandle(): void
    {
        $statCounter = 0;
        // @see https://www.infradead.org/~mchehab/kernel_docs/admin-guide/iostats.html
        // @see https://www.kernel.org/doc/Documentation/block/stat.txt
        foreach (\explode(' ', filesystem()->read("/sys/block/{$this->blockDevice}/stat")) as $_v) {
            $_v = \trim($_v);
            if (\strlen($_v) < 1) {
                continue;
            }
            $statCounter += \intval($_v);
        }

        $hasActivity = false;

        $this->lastUpdated = \hrtime(true);
        if ($this->lastStatCounter !== null && $this->lastStatCounter != $statCounter) {
            $this->lastActivity = $this->lastUpdated;
            $hasActivity = true;

        }
        $this->lastStatCounter = $statCounter;

        if (!$hasActivity) {
            return;
        }

        $futures = [];
        foreach ($this->callbacks as $callback) {
            $futures[] = async($callback, $this);
        }

        await($futures);
    }

    /**
     * @return string
     */
    public function getBlockDevice(): string
    {
        return $this->blockDevice;
    }

    /**
     * @return int|float|null
     */
    public function getLastUpdated(): int|float|null
    {
        return $this->lastUpdated;
    }

    /**
     * @return float
     */
    public function getUpdateInterval(): float
    {
        return $this->updateInterval;
    }

    /**
     * @return float|int|null
     */
    public function getLastActivity(): float|int|null
    {
        return $this->lastActivity;
    }

}