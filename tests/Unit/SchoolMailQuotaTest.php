<?php

namespace Tests\Unit;

use App\Services\SchoolMailDeferred;
use App\Services\SchoolMailQuota;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

final class SchoolMailQuotaTest extends TestCase
{
    public function test_personal_gmail_caps_an_oversized_daily_configuration_and_reserves_transactional_capacity(): void
    {
        config(['cache.stores.school_mail' => ['driver' => 'array'], 'mail.school_per_day' => 2000,
            'mail.school_per_minute' => 10]);
        $this->freezeTime();
        $quota = new SchoolMailQuota;
        $sender = 'School.Mail+test@googlemail.com';
        $start = now();
        for ($i = 0; $i < 450; $i++) {
            $quota->reserve($sender, true);
            $this->travel(1)->minute();
        }
        try {
            $quota->reserve('schoolmail@gmail.com', true);
            $this->fail('Bulk mail used the transactional reserve.');
        } catch (SchoolMailDeferred $delay) {
            $this->assertSame(86400 - 450 * 60, $delay->seconds);
        }
        for ($i = 0; $i < 50; $i++) {
            $quota->reserve($sender, false);
            $this->travel(1)->minute();
        }
        try {
            $quota->reserve($sender, false);
            $this->fail('Personal Gmail exceeded 500 attempts in 24 hours.');
        } catch (SchoolMailDeferred $delay) {
            $this->assertSame(86400 - 500 * 60, $delay->seconds);
        }
        $this->travelTo($start->copy()->addDay());
        $quota->reserve($sender, false);
    }

    public function test_gmail_daily_limit_pauses_the_account_including_aliases_but_not_another_sender(): void
    {
        config(['cache.stores.school_mail' => ['driver' => 'array']]);
        $quota = new SchoolMailQuota;
        $this->freezeTime();
        try {
            $quota->handleProviderLimit('First.Last+colegio@gmail.com', new TransportException('550 5.4.5 Daily user sending limit exceeded'));
            $this->fail('Expected quota pause');
        } catch (SchoolMailDeferred $wait) {
            $this->assertSame(86400, $wait->seconds);
        }
        $this->travel(1)->hour();
        $quota->reserve('otro@gmail.com', true);
        try {
            $quota->reserve('firstlast@googlemail.com', false);
            $this->fail('Expected shared sender pause');
        } catch (SchoolMailDeferred $wait) {
            $this->assertSame(23 * 3600, $wait->seconds);
        }
        $this->travel(23)->hours();
        $quota->reserve('firstlast@gmail.com', true);
        $quota->handleProviderLimit('firstlast@gmail.com', new TransportException('550 5.1.1 Recipient does not exist'));
        $quota->reserve('firstlast@gmail.com', true);
    }
}
