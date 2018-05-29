Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist contrib/yii2-oauth2-esia "*"
```

or add

```
"contrib/yii2-oauth2-esia": "*"
```

to the require section of your `composer.json` file.

Configure
---------

Add required certificates to your docker/config/certificate directory and 
fill corresponding data in .env

.env

```
ESIA_CERT=""
ESIA_KEY=""
ESIA_KEY_PASSWORD=""
ESIA_CLIENT=""
ESIA_PORTAL=
ESIA_SCOPES=""
```

frontend:

```
'components' => [
    'authClientCollection' => [
        'class' => \yii\authclient\Collection::class,
        'clients' => [
            'esia' => [
                'class' => \tina\esia\EsiaOAuth2::class,
                'clientId' => getenv('ESIA_CLIENT'),
                'returnUrl' => ['/cabinet/login/oauth'],
                'portalUrl' => getenv('ESIA_PORTAL'),
                'privateKeyPath' => getenv('ESIA_KEY'),
                'privateKeyPassword' => getenv('ESIA_KEY_PASSWORD'),
                'certPath' => getenv('ESIA_CERT'),
                'tmpPath' => sys_get_temp_dir(),
                'scopes' => explode(
                    ',',
                    str_replace([';', ' ', '|', '+'], ',', getenv('ESIA_SCOPES'))
                ),
            ],
        ],
    ],
]

```

@TODO: unit tests