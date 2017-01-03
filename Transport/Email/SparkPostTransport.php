<?php

namespace SDD\Bundle\SparkPostTransportBundle\Transport\Email;

use Ds\Bundle\TransportBundle\Transport\AbstractTransport;
use Ds\Bundle\TransportBundle\Model\Message;
use Ds\Bundle\TransportBundle\Entity\Profile;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;
use Oro\Bundle\EmailBundle\Model\EmailHolderInterface;
use Oro\Bundle\LocaleBundle\Model\FirstNameInterface;
use Oro\Bundle\LocaleBundle\Model\LastNameInterface;
use SparkPost\SparkPost;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;


/**
 * Class MailTransport
 */
class SparkPostTransport extends AbstractTransport
{

    private $profiles = [];

    public function __construct()
    {

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

            $promise = $sparky->transmissions->post(
                [
                    'recipients' => [
                        [
                            'address' => [
                                'name'  => sprintf("%s %s", $recipient->getFirstName(), $recipient->getLastName()),
                                'email' => $recipient->getEmail(),
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

            $body           = $response->getBody();
            $sendCount      = $body['results']['total_accepted_recipients'];
            $transmssion_id = $body['results']['id'];

            if ($sendCount > 0)
            {
                $message->setDeliveryStatus(Message::STATUS_SENDING);
                $message->setMessageUID($transmssion_id);
            }


        }
        catch (\Exception $e)
        {
            echo $e->getCode() . "\n";
            echo $e->getMessage() . "\n";
        }

        return $message;
    }
}
