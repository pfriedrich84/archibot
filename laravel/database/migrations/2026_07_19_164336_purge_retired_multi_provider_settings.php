<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $retiredKeys = [
            'llm.provider_profiles',
            'llm.classification_provider',
            'llm.embedding_provider',
            'llm.ocr_provider',
            'llm.judge_provider',
            'AI_PROVIDER_PROFILES',
            'CLASSIFICATION_PROVIDER',
            'EMBEDDING_PROVIDER',
            'OCR_PROVIDER',
            'JUDGE_PROVIDER',
        ];

        foreach ($retiredKeys as $key) {
            AppSetting::deleteKey($key);
        }

        DB::table('app_settings')
            ->whereIn('key', $retiredKeys)
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
