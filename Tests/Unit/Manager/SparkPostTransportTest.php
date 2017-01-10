<?php

namespace Ds\Bundle\CommunicationBundle\Tests\Unit\DependencyInjection;

use PHPUnit_Framework_TestCase;
use Ds\Bundle\CommunicationBundle\DependencyInjection\Configuration;
use SDD\Bundle\SparkPostTransportBundle\Transport\Email\SparkPostTransport;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class ConfigurationTest
 */
class SparkPostTransportTest extends PHPUnit_Framework_TestCase
{

    private function getRequest($content)
    {
        return new Request(array (), array (), array (), array (), array (), array (), $content);

    }

    /**
     * @dataProvider webhookProvider
     */
    public function testHandlePingMessage($body, $expected)
    {
        $request = $this->getRequest($body);

        $spp = new SparkPostTransport();

        $events = $spp->validateInput($request);

        $this->assertEquals($expected, $events);
    }


    public function webhookProvider()
    {
        return [
            [
                '[{msys:{}}]',
                null,
            ],
            [
                '[]',
                null,
            ],
            [
                '[junk !!! ',
                null,
            ],
            [
                null,
                null,
            ],
            [
                '[
                {"msys": {"message_event": { "type": "bounce" }}},
                {"msys": {"message_event": {"type": "delivery"}}}
                ]',
                [
                    json_encode([ "message_event" => [ "type" => "bounce" ] ]),
                    json_encode([ "message_event" => [ "type" => "delivery" ] ]),
                ],
            ],
        ];
    }




    public function webhookDataProvider()
    {
        return [
            [
                '{"track_event":{"accept_language":"en-US,en;q=0.5","rcpt_tags":[],"sending_ip":"52.38.191.214","message_id":"0002bd7e74588454dce4","ip_pool":"shared","user_agent":"Mozilla\/5.0 (Windows NT 10.0; WOW64; rv:45.0) Gecko\/20100101 Thunderbird\/45.6.0 Lightning\/4.7.6","delv_method":"esmtp","transmission_id":"48474501077245082","customer_id":"72258","rcpt_to":"samueldenisdortun@gmail.com","type":"open","ip_address":"192.222.162.174","timestamp":"1484031315","template_id":"template_48474501077245082","template_version":"0","event_id":"102519851696867968","rcpt_meta":{"message_uid":"a1374ece6b5523f55c82dcd599ab2c7c78378667"},"geo_ip":{"country":"CA","region":"QC","city":"Montr\u00e9al","latitude":45.5435,"longitude":-73.6339},"raw_rcpt_to":"samueldenisdortun@gmail.com"}}',
                null,
            ],
        ];
    }

}
