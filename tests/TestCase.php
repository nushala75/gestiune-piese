<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = new User([
            'name' => 'Administrator test',
            'email' => 'nusescu@gmail.com',
        ]);
        $admin->id = 1;
        $admin->exists = true;
        $this->actingAs($admin);
    }
}
