<?php

namespace Nicodinus\HddSleep;

use Amp\ByteStream;
use Amp\ByteStream\BufferException;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Amp\Serialization\JsonSerializer;
use Amp\Serialization\SerializationException;
use function Amp\File\filesystem;

class DiskCollector
{
    /**
     * @return \Generator<string>
     *
     * @throws BufferException
     * @throws ProcessException
     * @throws SerializationException
     */
    public static function fetch(): \Generator
    {
        $process = Process::start("lsblk -O --json --nodeps");

        //ByteStream\pipe($process->getStdout(), ByteStream\getStdout());
        ByteStream\pipe($process->getStderr(), ByteStream\getStderr());

        $stdout = ByteStream\buffer($process->getStdout());

        $code = $process->join();
        if ($code !== 0) {
            throw new \RuntimeException("lsblk failed");
        }

        $stdout = JsonSerializer::withAssociativeArrays()->unserialize($stdout);

        if (!isset($stdout['blockdevices'])) {
            throw new \RuntimeException("lsblk invalid response");
        }

        foreach ($stdout['blockdevices'] as $blockDevice) {
            yield $blockDevice;
        }
    }

    /**
     * @param string $blockDeviceType
     *
     * @return \Generator<string>
     *
     * @throws BufferException
     * @throws ProcessException
     * @throws SerializationException
     */
    public static function fetchOnly(string $blockDeviceType): \Generator
    {
        switch ($blockDeviceType)
        {
            case 'hdd':
                foreach (self::fetch() as $blockDevice) {
                    if ($blockDevice['rota'] !== true || $blockDevice['type'] !== 'disk' || $blockDevice['tran'] === null) {
                        continue;
                    }
                    yield $blockDevice;
                }
                break;
            case 'ssd':
                foreach (self::fetch() as $blockDevice) {
                    if ($blockDevice['rota'] !== false || $blockDevice['type'] !== 'disk' || $blockDevice['tran'] === null) {
                        continue;
                    }
                    yield $blockDevice;
                }
                break;
            case 'nvme':
                foreach (self::fetch() as $blockDevice) {
                    if ($blockDevice['tran'] !== 'nvme') {
                        continue;
                    }
                    yield $blockDevice;
                }
                break;
            case 'rom':
                foreach (self::fetch() as $blockDevice) {
                    if ($blockDevice['type'] !== 'rom' || $blockDevice['tran'] === null) {
                        continue;
                    }
                    yield $blockDevice;
                }
                break;
            default:
                throw new \InvalidArgumentException("Undefined block type {$blockDeviceType}");
        }
    }

    /**
     * @param string $devicePath
     *
     * @return array|false
     *
     * @throws BufferException
     * @throws ProcessException
     * @throws SerializationException
     */
    public static function checkEpc(string $devicePath): array|false
    {
        $process = Process::start(APP_PATH . "/bin/wdepc -f json -d {$devicePath} info");

        //ByteStream\pipe($process->getStdout(), ByteStream\getStdout());
        //ByteStream\pipe($process->getStderr(), ByteStream\getStderr());

        $stdout = ByteStream\buffer($process->getStdout());
        $stderr = ByteStream\buffer($process->getStderr());

        $code = $process->join();
        if ($code !== 0) {

            if (\str_contains($stderr, "EPC is not supported on this drive")) {
                return false;
            }

            throw new \RuntimeException("check epc error: {$stderr}");

        }

        return JsonSerializer::withAssociativeArrays()->unserialize($stdout);
    }

    /**
     * @param string $devicePath
     *
     * @return string
     *
     * @throws BufferException
     * @throws ProcessException
     * @throws SerializationException
     */
    public static function checkStatus(string $devicePath): string
    {
        $process = Process::start(APP_PATH . "/bin/wdepc -f json -d {$devicePath} check");

        //ByteStream\pipe($process->getStdout(), ByteStream\getStdout());
        ByteStream\pipe($process->getStderr(), ByteStream\getStderr());

        $stdout = ByteStream\buffer($process->getStdout());
        $stderr = ByteStream\buffer($process->getStderr());

        $code = $process->join();
        if ($code !== 0) {
            throw new \RuntimeException("check status error: {$stderr}");
        }

        return JsonSerializer::withAssociativeArrays()->unserialize($stdout)['mode'];
    }

    /**
     * @param string $devicePath
     *
     * @return bool
     *
     * @throws BufferException
     * @throws ProcessException
     * @throws SerializationException
     */
    public static function checkIsDeviceTestRunning(string $devicePath): bool
    {
        $process = Process::start("smartctl -j -c {$devicePath}");

        //ByteStream\pipe($process->getStdout(), ByteStream\getStdout());
        ByteStream\pipe($process->getStderr(), ByteStream\getStderr());

        $stdout = ByteStream\buffer($process->getStdout());
        $stderr = ByteStream\buffer($process->getStderr());

        $code = $process->join();
        if ($code !== 0) {
            throw new \RuntimeException("check status error: {$stderr}");
        }

        return isset(JsonSerializer::withAssociativeArrays()->unserialize($stdout)['ata_smart_data']['self_test']['status']['remaining_percent']);
    }

    /**
     * @param string $blockDevice
     *
     * @return array
     */
    public static function fetchStat(string $blockDevice): array
    {
        $result = [];
        // @see https://www.infradead.org/~mchehab/kernel_docs/admin-guide/iostats.html
        // @see https://www.kernel.org/doc/Documentation/block/stat.txt
        foreach (\explode(' ', filesystem()->read("/sys/block/{$blockDevice}/stat")) as $_v) {
            $_v = \trim($_v);
            if (\strlen($_v) < 1) {
                continue;
            }
            $result[] = $_v;
        }
        return $result;
    }
}