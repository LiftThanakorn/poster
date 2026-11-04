<?php

namespace Tests\Unit;

use App\Rules\SafeRedirectUrl;
use PHPUnit\Framework\TestCase;

class SafeRedirectUrlTest extends TestCase
{
    public function test_accepts_valid_https_url(): void
    {
        $rule = new SafeRedirectUrl;
        $failed = false;

        $rule->validate('target_url', 'https://www.google.com/forms/sample', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_rejects_javascript_scheme(): void
    {
        $rule = new SafeRedirectUrl;
        $failed = false;

        $rule->validate('target_url', 'javascript:alert(1)', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    public function test_rejects_localhost_and_internal_domains(): void
    {
        $rule = new SafeRedirectUrl;
        $failed = false;

        $rule->validate('target_url', 'http://localhost/admin', function () use (&$failed) {
            $failed = true;
        });
        $this->assertTrue($failed);

        $failed = false;
        $rule->validate('target_url', 'http://server.local/api', function () use (&$failed) {
            $failed = true;
        });
        $this->assertTrue($failed);
    }

    public function test_rejects_private_ip_literals(): void
    {
        $rule = new SafeRedirectUrl;
        $failed = false;

        $rule->validate('target_url', 'http://127.0.0.1:8000', function () use (&$failed) {
            $failed = true;
        });
        $this->assertTrue($failed);

        $failed = false;
        $rule->validate('target_url', 'http://192.168.1.1', function () use (&$failed) {
            $failed = true;
        });
        $this->assertTrue($failed);
    }
}
