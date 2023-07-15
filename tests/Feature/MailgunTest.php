<?php

use Bellows\Plugins\Mailgun;
use Illuminate\Support\Facades\Http;

it('can create a new domain', function () {
    Http::fake([
        'domains' => Http::sequence([
            ['items' => []],
            ['name' => 'mail.bellowstest.com'],
        ]),
    ]);

    $result = $this->plugin(Mailgun::class)
        ->expectsQuestion('Which region is your Mailgun account in?', 'US')
        ->expectsQuestion('Domain name?', 'mail.bellowstest.com')
        ->deploy();

    expect($result->getEnvironmentVariables())->toBe([
        'MAIL_MAILER'      => 'mailgun',
        'MAILGUN_DOMAIN'   => 'mail.bellowstest.com',
        'MAILGUN_SECRET'   => 'personal-access-token',
        'MAILGUN_ENDPOINT' => 'api.mailgun.net',
    ]);

    $this->assertRequestWasSent('POST', 'domains', [
        'name' => 'mail.bellowstest.com',
    ]);
});

it('can choose an existing domain', function () {
    Http::fake([
        'domains' => Http::sequence([
            [
                'items' => [
                    [
                        'name' => 'mail.existingdomain.com',
                        'type' => 'sandbox',
                    ],
                ],
            ],
        ]),
    ]);

    $result = $this->plugin(Mailgun::class)
        ->expectsQuestion('Which region is your Mailgun account in?', 'US')
        ->expectsConfirmation('Create new domain?', 'no')
        ->expectsQuestion(
            'Which domain do you want to use?',
            'mail.existingdomain.com (sandbox)'
        )
        ->deploy();

    expect($result->getEnvironmentVariables())->toBe([
        'MAIL_MAILER'      => 'mailgun',
        'MAILGUN_DOMAIN'   => 'mail.existingdomain.com',
        'MAILGUN_SECRET'   => 'personal-access-token',
        'MAILGUN_ENDPOINT' => 'api.mailgun.net',
    ]);
});
