<?php

namespace SDD\Bundle\SparkPostTransportBundle\Transport\Email;

use Doctrine\ORM\EntityManager;
use Ds\Bundle\TransportBundle\Entity\WebHookData;
use Ds\Bundle\TransportBundle\Model\AbstractMessageEvent;
use Ds\Bundle\TransportBundle\Model\MessageEvent;
use Ds\Bundle\TransportBundle\Model\UrlTrackingMessageEvent;
use Ds\Bundle\TransportBundle\Transport\AbstractTransport;
use Ds\Bundle\TransportBundle\Model\Message;
use Ds\Bundle\TransportBundle\Entity\Profile;
use Ds\Bundle\TransportBundle\Transport\WebHookHandler;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;
use Oro\Bundle\EmailBundle\Model\EmailHolderInterface;
use Oro\Bundle\LocaleBundle\Model\FirstNameInterface;
use Oro\Bundle\LocaleBundle\Model\LastNameInterface;
use SparkPost\SparkPost;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpFoundation\Request;


/**
 * Class MailTransport
 */
class SparkPostTransport extends AbstractTransport implements WebHookHandler
{

    private $profiles = [];

    /**
     * @var EntityManager
     */
    private $em;

    public function __construct(EntityManager $entityManager)
    {
        $this->em = $entityManager;
    }

    /**
     * @param Profile $profile
     *
     * @return \stdClass
     */
    private function getApi(Profile $profile)
    {
        if ( !array_key_exists($profile->getId(), $this->profiles))
        {
            $profileData = $profile->getData();

            if ( !array_key_exists('send_from', $profileData))
                throw new InvalidConfigurationException("The Transport SparkPost Profile must have a 'send_from' field.");

            $from = $profileData['send_from'];

            if ( !array_key_exists('fullName', $from)
                || !array_key_exists('email', $from)
            )
                throw new InvalidConfigurationException("The Transport SparkPost Profile 'send_from' must have a 'email' and 'fullName' field.");


            $transportData = $profile->getTransport()->getData();

            if ( !array_key_exists('api_key', $transportData))
                throw new InvalidConfigurationException("The Transport SparkPost Transport must have a 'api_key' field.");

            $httpClient = new GuzzleAdapter(new Client());
            $sparky     = new SparkPost($httpClient, [ 'key' => $transportData['api_key'] ]);

            $cfg               = new \stdClass();
            $cfg->sparky       = $sparky;
            $cfg->fromEmail    = $from['email'];
            $cfg->fromFullName = $from['fullName'];

            $cfg->profileData   = $profileData;
            $cfg->transportData = $transportData;

            $this->profiles[$profile->getId()] = $cfg;
        }

        return $this->profiles[$profile->getId()];
    }

    private static function generateUID($email)
    {
        return sha1(uniqid($email, true) . uniqid('', true));
    }

    /**
     * {@inheritdoc}
     */
    public function send(Message $message, Profile $profile = null)
    {
        $profile = $profile ? : $this->profile;

        $cfg = $this->getApi($profile);


        $message->setFrom(sprintf("%s <%s>", $cfg->fromFullName, $cfg->fromEmail));

        /** @var EmailHolderInterface|FirstNameInterface|LastNameInterface $recipient */
        $recipient = $message->getRecipient();

        try
        {
            /** @var SparkPost $sparky */
            $sparky = $cfg->sparky;

            $message->setMessageUID(self::generateUID($recipient->getEmail()));

            $promise = $sparky->transmissions->post(
                [
                    'recipients' => [
                        [
                            'address'  => [
                                'name'  => sprintf("%s %s", $recipient->getFirstName(), $recipient->getLastName()),
                                'email' => $recipient->getEmail(),
                            ],
                            'metadata' => [
                                'message_uid' => $message->getMessageUID(),
                            ],
                        ],
                    ],
                    'content'    => [
                        'from'    => [
                            'name'  => $cfg->fromFullName,
                            'email' => $cfg->fromEmail,
                        ],
                        'subject' => $message->getTitle(),
                        'html'    => $message->getContent(),
                        'text'    => $message->getContent(), // @todo
                    ],

                ]);


            $response = $promise->wait();

            $body      = $response->getBody();
            $sendCount = $body['results']['total_accepted_recipients'];


            if ($sendCount > 0)
            {
                $message->setDeliveryStatus(Message::STATUS_SENDING);
            }
            else
            {
                $message->setDeliveryStatus(Message::STATUS_FAILED);
            }


        }
        catch (\Exception $e)
        {
            $message->setDeliveryStatus(Message::STATUS_FAILED);

            echo $e->getCode() . "\n";
            echo $e->getMessage() . "\n";
        }

        return $message;
    }

    /**
     * @param Request $request
     *
     * @return string[]
     */
    public function parseRequest(Request $request)
    {
        $data    = [];
        $content = $request->getContent();
        if ( !$content)
            return null;

        $events = json_decode($content, true);
        if ($events === null || empty($events))
            return null;

        /*
         [
          {
            "msys": {
              "<event-class>_event": {
                "type": "<event-type>"
                // ...tasty event fields...
              }
            }
          },
          // ...
        ]
         */

        foreach ($events as $event)
        {
            if ( !array_key_exists("msys", $event))
                continue;

            $msg = $event["msys"];
            if (empty($msg))
                continue;

            $data[] = $msg;
        }

        return $data;
    }


    private static function getMessageData(WebHookData $webHookData)
    {
        $body = $webHookData->getData();

        if (count($body) > 1)
            throw new \InvalidArgumentException('The WebHookData must contain a single event');

        if (empty($body))
            return null;

        reset($body);
        $event_class = key($body);
        $data        = $body[$event_class];

        $type = $data['type'];


        return [ $event_class, $type, $data ];
    }

    public function getMessageUID(WebHookData $webHookData)
    {
        list($event_class, $type, $data) = self::getMessageData($webHookData);

        if ( !array_key_exists('rcpt_meta', $data))
            return null;
        if ( !array_key_exists('message_uid', $data['rcpt_meta']))
            return null;

        return $data['rcpt_meta']['message_uid'];
    }


    private function getDate($data)
    {
        if ( !array_key_exists('timestamp', $data))
            return null;

        $timestamp = intval($data['timestamp']);

        $d = new \DateTime();

        if ($timestamp)
        {
            $d->setTimestamp($timestamp);
        }

        return $d;
    }


    /**
     * @param WebHookData $webHookData
     *
     * @return AbstractMessageEvent|null
     */
    public function createEvent(WebHookData $webHookData)
    {
        list($event_class, $type, $data) = self::getMessageData($webHookData);

        // @todo WIP !
        switch (sprintf('%s->%s', $event_class, $type))
        {
            case "message_event->injection" :
            case "message_event->delay" :
            case "relay_event->relay_tempfail" :
                return $this->extractEvent(Message::STATUS_SENDING, $data);

            case "message_event->delivery" :
                return $this->extractEvent(Message::STATUS_SENT, $data);

            case "track_event->open" :
                return $this->extractEvent(Message::STATUS_OPEN, $data);

            case "track_event->click" :
                return $this->extractUrlEvent('click', $data);

            case "message_event->out_of_band" :
            case "message_event->bounce" :
            case "gen_event->generation_failure" :
            case "gen_event->generation_rejection" :
            case "relay_event->relay_rejection" :
            case "relay_event->relay_permfail" :
                // @todo out_of_band does not have "rcpt_meta" will need to lookup "message_id"
                return $this->extractEvent(Message::STATUS_FAILED, $data);

            case "message_event->sms_status" :
            case "message_event->spam_complaint" :
            case "message_event->policy_rejection" :
            case "unsubscribe_event->list_unsubscribe" :
            case "unsubscribe_event->link_unsubscribe" :
            case "relay_event->relay_injection" :
            case "relay_event->relay_delivery" :
            default:
                return null;
        }

    }

    private function extractEvent($type, $data)
    {
        $event = new MessageEvent($type);
        $event->setOccurredAt($this->getDate($data));

        return $event;
    }

    private function extractUrlEvent($type, $data)
    {
        $event = new UrlTrackingMessageEvent($type);

        $event->setOccurredAt($this->getDate($data));

        if (array_key_exists('target_link_name', $data))
        {
            $event->setTargetLinkUrl($data['target_link_name']);
        }

        if (array_key_exists('target_link_url', $data))
        {
            $event->setTargetLinkUrl($data['target_link_url']);
        }

        return $event;
    }

}
