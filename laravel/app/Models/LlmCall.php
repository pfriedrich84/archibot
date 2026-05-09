<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'pipeline_run_id', 'paperless_document_id', 'provider', 'model', 'purpose',
    'input_tokens', 'output_tokens', 'duration_ms', 'status', 'error_type', 'error',
])]
class LlmCall extends Model {}
