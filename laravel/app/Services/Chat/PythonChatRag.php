<?php

namespace App\Services\Chat;

use App\Services\Settings\PythonRuntimeConfigExporter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class PythonChatRag
{
    private PythonRuntimeConfigExporter $configExporter;

    public function __construct(?PythonRuntimeConfigExporter $configExporter = null)
    {
        $this->configExporter = $configExporter ?? app(PythonRuntimeConfigExporter::class);
    }

    /**
     * @param  array<int, array{role:string,content:string,sources?:array}>  $history
     */
    public function ask(string $question, array $history): ChatRagResult
    {
        $this->configExporter->export();
        $directory = storage_path('app/chat-bridge');
        File::ensureDirectoryExists($directory);

        $token = Str::random(16);
        $inputPath = $directory."/chat-{$token}.in.json";
        $outputPath = $directory."/chat-{$token}.out.json";

        file_put_contents($inputPath, json_encode([
            'question' => $question,
            'history' => $history,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            $process = new Process([
                config('archibot.python_binary', 'python'),
                '-m',
                'app.cli',
                'chat-ask',
                '--input',
                $inputPath,
                '--output',
                $outputPath,
            ], base_path('..'), timeout: (int) config('archibot.chat_timeout', 120));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput() ?: 'Chat backend failed.'));
            }

            $payload = is_file($outputPath) ? json_decode((string) file_get_contents($outputPath), true) : null;

            if (! is_array($payload) || ! ($payload['ok'] ?? false)) {
                throw new RuntimeException((string) data_get($payload, 'error', 'Chat backend returned an invalid response.'));
            }

            return new ChatRagResult(
                answer: (string) ($payload['answer'] ?? ''),
                sources: is_array($payload['sources'] ?? null) ? $payload['sources'] : [],
            );
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }
}
