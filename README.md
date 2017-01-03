# SDDSparkPostTransport

This bundle is an integration between [DigitalState/Platform-Transport-Bundle](https://github.com/DigitalState/Platform-Transport-Bundle) and [SparkPost/php-sparkpost](https://github.com/SparkPost/php-sparkpost)


## Configuration
The ususal composer require !



### Transport and Profile configuration

#### Transport

You need to add a new Transport with the following data:
```json
{
    "api_key":"YOUR_SPARKPOST_API_KEY",
    "allowed_sender_domains":
    [
        "example.com",
        "other.example.com"
    ]
}
```

The `api_key` is self explanatory.
The `allowed_sender_domains` is the list of domains that are currently allowed and configured in SparkPost.


#### Profile

You need to add a new profile with the following data: 
```json
{
   "send_from":"CURRENT_USER"
}
```

The Field `send_from` is used to configured the emails will that is used to send emails through the _Transport_

Possible values :  
- `CURRENT_USER` The `from` address will be the email address of the current connected user (The user pressing the SEND Button)
- `ENTITY_OWNER` The `from` address will be the email address of the user that is the _Owner_ of the recipient


