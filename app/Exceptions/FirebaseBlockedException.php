<?php

namespace App\Exceptions;

use Exception;

class FirebaseBlockedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Firebase device registration is currently unavailable.');
    }
}
