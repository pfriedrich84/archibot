<?php

namespace Tests\Feature\Workers;

use App\Models\WorkerJob;
use App\Services\Workers\PythonWorkerCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PythonWorkerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_json_cli_contract_and_records_result(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'archibot-worker-');
        file_put_contents($script, <<<'PHP'
#!/usr/bin/env php
<?php
$input = $argv[array_search('--input', $argv, true) + 1];
$output = $argv[array_search('--output', $argv, true) + 1];
$data = json_decode(file_get_contents($input), true);
file_put_contents($output, json_encode(['ok' => true, 'type' => $data['type']]));
PHP);
        chmod($script, 0755);

        Config::set('archibot_workers.python_binary', $script);

        $workerJob = WorkerJob::factory()->create(['type' => WorkerJob::TYPE_POLL]);
        app(PythonWorkerCommand::class)->run($workerJob);

        $workerJob->refresh();
        $this->assertSame(WorkerJob::STATUS_SUCCEEDED, $workerJob->status);
        $this->assertSame(0, $workerJob->exit_code);
        $this->assertSame(['ok' => true, 'type' => WorkerJob::TYPE_POLL], $workerJob->result);
        $this->assertFileExists($workerJob->input_path);
        $this->assertFileExists($workerJob->output_path);

        @unlink($script);
    }
}
