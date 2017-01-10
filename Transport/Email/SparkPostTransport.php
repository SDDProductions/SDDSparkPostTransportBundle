<?php

namespace SDD\Bundle\SparkPostTransportBundle\Transport\Email;

use Doctrine\ORM\EntityManager;
use Ds\Bundle\TransportBundle\Entity\WebHookData;
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
    public function validateInput(Request $request)
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


    private function getMessageFromData(Profile $profile, $data)
    {
        if ( !array_key_exists('rcpt_meta', $data))
            return null;
        if ( !array_key_exists('message_uid', $data['rcpt_meta']))
            return null;

        $message_uid = $data['rcpt_meta']['message_uid'];

        /** @var \Ds\Bundle\CommunicationBundle\Entity\Message $message */
        $message = $this->em
            ->getRepository('DsCommunicationBundle:Message')
            ->findOneBy([
                            'message_uid' => $message_uid,
                            'profile'     => $profile->getId(),
                        ]);

        return $message;
    }

    private function setMessageStatus(WebHookData $webHookData,$data, $status)
    {
        $message = $this->getMessageFromData($webHookData->getProfile() ,$data);

        if ($message === null)
            return false;

        // final message states
        if ($message->getDeliveryStatus() === Message::STATUS_FAILED
            || $message->getDeliveryStatus() === Message::STATUS_SENT
            || $message->getDeliveryStatus() === Message::STATUS_CANCELLED
        )
            return true;

        if($status !== Message::STATUS_UNKNOWN)
        {
            // Message:: STATUS_QUEUED
            // Message::STATUS_SENDING
            $message->setDeliveryStatus($status);
        }

        $this->em->persist($message);

        return true;
    }


    /**
     * @param WebHookData $webHookData
     *
     * @return bool
     */
    private function processSingle(WebHookData $webHookData, $event_class, $type , $data)
    {

        switch (sprintf('%s->%s', $event_class, $type))
        {
            case "message_event->injection" :
                $this->setMessageStatus($webHookData,$data, Message::STATUS_SENDING);

                return true;

            case "message_event->delivery" :
                $this->setMessageStatus($webHookData,$data, Message::STATUS_SENT);

                return true;
            case "track_event->open" :
                $this->setMessageStatus($webHookData,$data, Message::STATUS_SENT);

                return true;
            case "track_event->click" :
                $this->setMessageStatus($webHookData, $data,Message::STATUS_SENT);

                return true;
            case "message_event->delay" :
            case "relay_event->relay_tempfail" :
                $this->setMessageStatus($webHookData,$data, Message::STATUS_SENDING);

                return true;

            case "message_event->out_of_band" :
            case "message_event->bounce" :
            case "gen_event->generation_failure" :
            case "gen_event->generation_rejection" :
            case "relay_event->relay_rejection" :
            case "relay_event->relay_permfail" :
                $this->setMessageStatus($webHookData,$data, Message::STATUS_FAILED);

                return true;

            case "message_event->sms_status" :
            case "message_event->spam_complaint" :
            case "message_event->policy_rejection" :
            case "unsubscribe_event->list_unsubscribe" :
            case "unsubscribe_event->link_unsubscribe" :
            case "relay_event->relay_injection" :
            case "relay_event->relay_delivery" :
                return false;
        }


        return false;
    }


    /**
     * @param WebHookData $webHookData
     *
     * @return bool
     */
    public function process(WebHookData $webHookData)
    {
        $data = $webHookData->getData();

        foreach ($data as $event_class => $event_data)
        {
            $type = $event_data['type'];

            return $this->processSingle($webHookData, $event_class, $type, $event_data);
        }
    }
}
