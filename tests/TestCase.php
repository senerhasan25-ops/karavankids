<?php

namespace Tests;

use App\Models\ApiCredential;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Process-içi forStore cache testler arası sızmasın (#10).
        ApiCredential::forgetCache();
    }
}
