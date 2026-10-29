<?php

namespace App\Support\Curriculum;

use DomainException;

final class CurriculumProposalBlocked extends DomainException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
