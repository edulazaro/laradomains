<?php

namespace EduLazaro\Laradomains\Dns {
    // The system driver calls dns_get_record() unqualified, so a function in its namespace stands
    // in for it while a test sets an answer.
    function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
    {
        return \EduLazaro\Laradomains\Tests\AcceptsMailTest::$system[$type] ?? \dns_get_record($hostname, $type);
    }
}

namespace EduLazaro\Laradomains\Tests {
    use EduLazaro\Laradomains\Facades\Domains;
    use Illuminate\Support\Facades\Http;

    class AcceptsMailTest extends TestCase
    {
        /** @var array<int, array|false> dns_get_record() answers by type, for the system driver. */
        public static array $system = [];

        protected function tearDown(): void
        {
            self::$system = [];
            parent::tearDown();
        }

        private function answer(array $records, int $status = 0): array
        {
            return ['Status' => $status, 'Answer' => $records];
        }

        /**
         * @param array<string, mixed> $byType Response per record type; missing types answer with no records.
         */
        private function fakeDns(array $byType): void
        {
            Http::fake(fn ($request) => $byType[$request['type']] ?? Http::response($this->answer([])));
        }

        public function test_a_normal_mx_accepts_mail(): void
        {
            $this->fakeDns(['MX' => Http::response($this->answer([['type' => 15, 'data' => '10 mx.example.com.']]))]);

            $this->assertTrue(Domains::dns()->acceptsMail('example.com'));
        }

        public function test_a_null_mx_refuses_mail(): void
        {
            $this->fakeDns(['MX' => Http::response($this->answer([['type' => 15, 'data' => '0 .']]))]);

            $this->assertFalse(Domains::dns()->acceptsMail('example.com'));
            Http::assertSentCount(1);
        }

        public function test_without_mx_an_address_receives_the_mail(): void
        {
            $this->fakeDns(['A' => Http::response($this->answer([['type' => 1, 'data' => '93.184.215.14']]))]);

            $this->assertTrue(Domains::dns()->acceptsMail('example.com'));
        }

        public function test_an_ipv6_address_is_enough_too(): void
        {
            $this->fakeDns(['AAAA' => Http::response($this->answer([['type' => 28, 'data' => '2606:2800::1']]))]);

            $this->assertTrue(Domains::dns()->acceptsMail('example.com'));
        }

        public function test_no_mx_and_no_address_refuses_mail(): void
        {
            $this->fakeDns([]);

            $this->assertFalse(Domains::dns()->acceptsMail('example.com'));
        }

        public function test_a_name_that_does_not_exist_refuses_mail(): void
        {
            Http::fake(fn () => Http::response($this->answer([], status: 3)));

            $this->assertFalse(Domains::dns()->acceptsMail('nobody-owns-this.example'));
        }

        public function test_an_mx_lookup_that_fails_is_unknown(): void
        {
            $this->fakeDns(['MX' => Http::response('', 500)]);

            $this->assertNull(Domains::dns()->acceptsMail('example.com'));
        }

        public function test_servfail_is_unknown(): void
        {
            $this->fakeDns(['MX' => Http::response($this->answer([], status: 2))]);

            $this->assertNull(Domains::dns()->acceptsMail('example.com'));
        }

        public function test_without_mx_a_failed_address_lookup_is_unknown(): void
        {
            $this->fakeDns(['A' => Http::response('', 500)]);

            $this->assertNull(Domains::dns()->acceptsMail('example.com'));
        }

        public function test_the_system_driver_tells_a_failure_from_no_records(): void
        {
            config(['laradomains.dns.driver' => 'system']);

            self::$system = [DNS_MX => false];
            $this->assertNull(Domains::dns()->acceptsMail('example.com'));

            self::$system = [DNS_MX => [], DNS_A => [['ip' => '93.184.215.14']], DNS_AAAA => []];
            $this->assertTrue(Domains::dns()->acceptsMail('example.com'));

            self::$system = [DNS_MX => [], DNS_A => [], DNS_AAAA => []];
            $this->assertFalse(Domains::dns()->acceptsMail('example.com'));

            self::$system = [DNS_MX => [['pri' => 10, 'target' => 'mx.example.com']]];
            $this->assertTrue(Domains::dns()->acceptsMail('example.com'));
        }
    }
}
