<?php

namespace App\Domain\Chat\Services;

/** Streams a file to a ClamAV daemon (clamd) with the INSTREAM command. */
class ClamAvScanner implements VirusScanner
{
    private const CHUNK = 65536;

    public function __construct(private readonly string $host, private readonly int $port, private readonly int $timeout = 10) {}

    public function scan(string $path): ScanResult
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new ScannerUnavailable('The file could not be read for scanning.');
        }
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errorCode, $error, $this->timeout);
        if ($socket === false) {
            fclose($file);
            throw new ScannerUnavailable("Could not reach the virus scanner ({$error}).");
        }
        stream_set_timeout($socket, $this->timeout);
        try {
            $this->write($socket, "zINSTREAM\0");
            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK);
                if ($chunk === false) {
                    throw new ScannerUnavailable('The file could not be read for scanning.');
                }
                if ($chunk !== '') {
                    $this->write($socket, pack('N', strlen($chunk)).$chunk);
                }
            }
            $this->write($socket, pack('N', 0));
            $response = stream_get_contents($socket, 4096);
            if ($response === false || $response === '' || stream_get_meta_data($socket)['timed_out']) {
                throw new ScannerUnavailable('The virus scanner did not respond in time.');
            }

            return self::parseResponse($response);
        } finally {
            fclose($file);
            fclose($socket);
        }
    }

    /** @throws ScannerUnavailable for errors and anything unexpected, so unknown replies never count as clean. */
    public static function parseResponse(string $response): ScanResult
    {
        $text = trim($response, "\0\r\n ");
        if (preg_match('/^stream:\s*OK$/', $text) === 1) {
            return new ScanResult(true);
        }
        if (preg_match('/^stream:\s*(.+?)\s+FOUND$/', $text, $found) === 1) {
            return new ScanResult(false, $found[1]);
        }

        throw new ScannerUnavailable('The virus scanner returned an unexpected response.');
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $count = @fwrite($socket, substr($data, $written));
            if ($count === false || $count === 0) {
                throw new ScannerUnavailable('The virus scanner closed the connection.');
            }
            $written += $count;
        }
    }
}
