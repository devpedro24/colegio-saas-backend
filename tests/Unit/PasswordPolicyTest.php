<?php

namespace Tests\Unit;

use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function test_generated_temporary_passwords_meet_the_same_policy(): void
    {
        foreach ([12, 14] as $length) {
            for ($i = 0; $i < 20; $i++) {
                $password = PasswordPolicy::temporary($length);
                $this->assertSame($length, strlen($password));
                $this->assertTrue(Validator::make(
                    ['password' => $password],
                    ['password' => PasswordPolicy::rule()],
                )->passes());
            }
        }
    }
}
