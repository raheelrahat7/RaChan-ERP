<?php

namespace Tests\Feature\Chat;

use App\Domain\Chat\Services\ClamAvScanner;
use App\Domain\Chat\Services\ExecutableContentGuard;
use App\Domain\Chat\Services\ScannerUnavailable;
use App\Domain\Chat\Services\ScanResult;
use App\Domain\Chat\Services\VirusScanner;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VirusScanningTest extends TestCase
{
    use RefreshDatabase;

    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    /** @var resource|null */
    private $daemon = null;

    protected function tearDown(): void
    {
        if (is_resource($this->daemon)) {
            proc_terminate($this->daemon);
            proc_close($this->daemon);
        }
        parent::tearDown();
    }

    private function member(Organization $org): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $org->users()->attach($user, ['role' => OrganizationRole::Member->value]);

        return $user;
    }

    /** Starts a tiny clamd stand-in that speaks INSTREAM and reports EICAR as infected. */
    private function startFakeClamd(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $script = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:'.$argv[1]);
while ($client = @stream_socket_accept($server, 30)) {
    $command = '';
    while (strlen($command) < 10) { $piece = fread($client, 10 - strlen($command)); if ($piece === '' || $piece === false) { fclose($client); continue 2; } $command .= $piece; }
    $data = '';
    while (true) {
        $header = '';
        while (strlen($header) < 4) { $piece = fread($client, 4 - strlen($header)); if ($piece === '' || $piece === false) { fclose($client); continue 3; } $header .= $piece; }
        $length = unpack('N', $header)[1];
        if ($length === 0) { break; }
        $got = 0;
        while ($got < $length) { $piece = fread($client, $length - $got); if ($piece === '' || $piece === false) { fclose($client); continue 3; } $data .= $piece; $got += strlen($piece); }
    }
    fwrite($client, str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') ? "stream: Eicar-Test-Signature FOUND\0" : "stream: OK\0");
    fclose($client);
}
PHP;
        $this->daemon = proc_open([PHP_BINARY, '-r', $script, (string) $port], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 0.2);
            if ($socket) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }

        return $port;
    }

    private function temp(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $content);

        return $path;
    }

    public function test_clamd_responses_are_parsed_and_unknown_replies_never_count_as_clean(): void
    {
        $this->assertTrue(ClamAvScanner::parseResponse("stream: OK\0")->clean);
        $infected = ClamAvScanner::parseResponse("stream: Win.Test.EICAR_HDB-1 FOUND\0");
        $this->assertFalse($infected->clean);
        $this->assertSame('Win.Test.EICAR_HDB-1', $infected->signature);
        foreach (["INSTREAM size limit exceeded. ERROR\0", 'stream: something odd', '', 'stream: OK and more'] as $reply) {
            try {
                ClamAvScanner::parseResponse($reply);
                $this->fail("Reply [{$reply}] must not be accepted.");
            } catch (ScannerUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_scanner_streams_files_to_clamd_and_reports_infections(): void
    {
        $port = $this->startFakeClamd();
        $scanner = new ClamAvScanner('127.0.0.1', $port, 5);

        $clean = $this->temp(str_repeat('harmless text ', 20000));
        $this->assertTrue($scanner->scan($clean)->clean, 'a file larger than one chunk scans clean');
        $infected = $this->temp(str_repeat('a', 70000).self::EICAR.str_repeat('b', 70000));
        $result = $scanner->scan($infected);
        $this->assertFalse($result->clean);
        $this->assertSame('Eicar-Test-Signature', $result->signature);
        $empty = $this->temp('');
        $this->assertTrue($scanner->scan($empty)->clean);
        array_map('unlink', [$clean, $infected, $empty]);
    }

    public function test_an_unreachable_scanner_is_reported_not_treated_as_clean(): void
    {
        $this->expectException(ScannerUnavailable::class);
        (new ClamAvScanner('127.0.0.1', 1, 1))->scan($this->temp('x'));
    }

    public function test_program_files_are_recognised_by_content_not_name(): void
    {
        $guard = new ExecutableContentGuard;
        $this->assertSame('Windows program', $guard->reason($this->temp("MZ\x90\x00\x03")));
        $this->assertSame('Linux program', $guard->reason($this->temp("\x7FELF\x02\x01")));
        $this->assertSame('script', $guard->reason($this->temp("#!/bin/sh\necho hi")));
        $this->assertNull($guard->reason($this->temp('%PDF-1.7')));
        $this->assertNull($guard->reason($this->temp("\x89PNG\r\n\x1a\n")));
        $this->assertNull($guard->reason($this->temp('plain notes')));
        $this->assertNull($guard->reason('/no/such/file'));
    }

    public function test_uploads_are_refused_and_nothing_is_stored_when_the_scanner_is_down(): void
    {
        Storage::fake('local');
        config(['chat.virus_scan' => ['driver' => 'clamav', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $agent = $this->member($org);
        $room = $this->actingAs($owner)->postJson(route('chat.direct'), ['recipient_id' => $agent->id])->json('room_id');

        $this->postJson(route('chat.attach', $room), ['files' => [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')]])
            ->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->assertSame(0, DB::table('internal_chat_attachments')->count());
        $this->assertSame(0, DB::table('internal_chat_messages')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_infected_uploads_are_blocked_audited_and_leave_no_trace(): void
    {
        Storage::fake('local');
        $this->app->bind(VirusScanner::class, fn () => new class implements VirusScanner
        {
            public function scan(string $path): ScanResult
            {
                return str_contains((string) file_get_contents($path), 'EICAR') ? new ScanResult(false, 'Eicar-Test-Signature') : new ScanResult(true);
            }
        });
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $agent = $this->member($org);
        $room = $this->actingAs($owner)->postJson(route('chat.direct'), ['recipient_id' => $agent->id])->json('room_id');

        $this->postJson(route('chat.attach', $room), ['body' => 'see attached', 'files' => [
            UploadedFile::fake()->createWithContent('fine.txt', 'ok'),
            UploadedFile::fake()->createWithContent('bad.txt', self::EICAR),
        ]])->assertUnprocessable()->assertJsonPath('errors.files.0', fn ($message) => str_contains($message, 'bad.txt') && str_contains($message, 'virus'));
        $this->assertSame(0, DB::table('internal_chat_attachments')->count());
        $this->assertSame(0, DB::table('internal_chat_messages')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('audit_logs', ['event' => 'chat.attachment.blocked', 'organization_id' => $org->id]);

        $this->postJson(route('chat.attach', $room), ['files' => [UploadedFile::fake()->createWithContent('fine.txt', 'ok')]])->assertOk();
        $this->assertSame(1, DB::table('internal_chat_attachments')->count());
    }

    public function test_voice_notes_are_scanned_too(): void
    {
        Storage::fake('local');
        $this->app->bind(VirusScanner::class, fn () => new class implements VirusScanner
        {
            public function scan(string $path): ScanResult
            {
                return new ScanResult(false, 'Test.Signature');
            }
        });
        $org = Organization::factory()->create();
        $owner = $this->member($org);
        $agent = $this->member($org);
        $room = $this->actingAs($owner)->postJson(route('chat.direct'), ['recipient_id' => $agent->id])->json('room_id');

        $this->postJson(route('chat.attach', $room), ['voice' => true, 'duration' => 5, 'files' => [UploadedFile::fake()->create('note.ogg', 10, 'audio/ogg')]])->assertUnprocessable();
        $this->assertSame(0, DB::table('internal_chat_attachments')->count());
    }
}
