<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Services\Integration\Parsers\CurlCookieParserService;
use Tests\TestCase;

class CurlCookieParserServiceTest extends TestCase
{
    public function test_parse_many_supports_multiple_curl_blocks(): void
    {
        $service = new CurlCookieParserService();

        $input = <<<'CURL'
        curl 'https://affiliate.shopee.vn/api/v3/payment/billing_list?page_num=1' \
          -H 'referer: https://affiliate.shopee.vn/payment/billing' \
          -H 'user-agent: test-ua' \
          -b 'SPC_EC=test-billing;'

        curl 'https://affiliate.shopee.vn/api/v3/gql?q=getPayoutList' \
          -H 'referer: https://affiliate.shopee.vn/payment/payout_record' \
          -H 'user-agent: test-ua' \
          -b 'SPC_EC=test-payout;'
        CURL;

        $parsed = $service->parseMany($input);

        $this->assertCount(2, $parsed);
        $this->assertSame('billing', $parsed[0]['endpoint_key']);
        $this->assertSame('payout_record', $parsed[1]['endpoint_key']);
        $this->assertSame('test-ua', $parsed[0]['user_agent']);
    }
}

