<?php

namespace App\Services\Chat;

readonly class ChatRagResult
{
    /**
     * @param  array<int, array{id:int,title:?string,distance:float|int}>  $sources
     */
    public function __construct(
        public string $answer,
        public array $sources = [],
    ) {}
}
