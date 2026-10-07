<?php

namespace App\Support\Payments;

/** Safe public failure: never attach provider bodies or transport exceptions. */
final class PesaPalException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('PesaPal request could not be completed safely.');
    }
}
