<?php

namespace Bellows\Plugins;

use Bellows\PluginSdk\Contracts\Deployable;
use Bellows\PluginSdk\Contracts\HttpClient;
use Bellows\PluginSdk\Contracts\Installable;
use Bellows\PluginSdk\Data\AddApiCredentialsPrompt;
use Bellows\PluginSdk\Facades\Console;
use Bellows\PluginSdk\Facades\Deployment;
use Bellows\PluginSdk\Facades\Dns;
use Bellows\PluginSdk\Facades\Domain;
use Bellows\PluginSdk\Facades\Entity;
use Bellows\PluginSdk\Facades\Project;
use Bellows\PluginSdk\Plugin;
use Bellows\PluginSdk\PluginResults\CanBeDeployed;
use Bellows\PluginSdk\PluginResults\CanBeInstalled;
use Bellows\PluginSdk\PluginResults\DeploymentResult;
use Bellows\PluginSdk\PluginResults\InstallationResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Mailgun extends Plugin implements Deployable, Installable
{
    use CanBeDeployed, CanBeInstalled;

    protected const MAILER = 'mailgun';

    protected string $domain;

    protected string $endpoint;

    protected bool $verifyNewDomain = false;

    public function __construct(
        protected HttpClient $http,
    ) {
    }

    public function install(): ?InstallationResult
    {
        return InstallationResult::create();
    }

    public function deploy(): ?DeploymentResult
    {
        $region = Console::choice(
            'Which region is your Mailgun account in?',
            ['US', 'EU'],
        );

        $this->endpoint = $region === 'US' ? 'api.mailgun.net' : 'api.eu.mailgun.net';

        $this->http->createClient(
            "https://{$this->endpoint}/v4",
            fn (PendingRequest $request, array $credentials) => $request->asForm()->withBasicAuth('api', $credentials['token']),
            new AddApiCredentialsPrompt(
                url: 'https://app.mailgun.com/app/account/security/api_keys',
                helpText: 'Make sure you select your <comment>Private API key</comment>',
                credentials: ['token'],
                displayName: 'Mailgun',
            ),
            fn (PendingRequest $request) => $request->get('domains', ['limit' => 1]),
        );

        $response = $this->http->client()->get('domains')->json();

        $domainChoices = collect($response['items'])->map(fn ($domain) => array_merge(
            $domain,
            ['custom_key' => "{$domain['name']} ({$domain['type']})"]
        ));

        $domainResult = Entity::from($domainChoices)
            ->selectFromExisting(
                'Which domain do you want to use?',
                'custom_key',
                fn ($domain) => Str::contains($domain['name'], Project::domain()),
                'Create new domain',
            )
            ->createNew(
                'Create new domain?',
                $this->createDomain(...),
            )
            ->prompt();

        $this->domain = $domainResult['name'];

        return DeploymentResult::create()
            ->environmentVariables([
                'MAIL_MAILER'      => self::MAILER,
                'MAILGUN_DOMAIN'   => $this->domain,
                'MAILGUN_SECRET'   => $this->http->client()->getOptions()['auth'][1],
                'MAILGUN_ENDPOINT' => $this->endpoint,
            ])
            ->wrapUp(function () {
                if ($this->verifyNewDomain) {
                    $this->http->client()->put("domains/{$this->domain}/verify");
                }
            });
    }

    public function requiredComposerPackages(): array
    {
        return [
            'symfony/mailgun-mailer',
        ];
    }

    public function shouldDeploy(): bool
    {
        return !Deployment::site()->env()->hasAll(
            'MAILGUN_DOMAIN',
            'MAILGUN_SECRET',
            'MAILGUN_ENDPOINT',
        ) || Deployment::site()->env()->get('MAIL_MAILER') !== self::MAILER;
    }

    public function confirmDeploy(): bool
    {
        return Deployment::confirmChangeValueTo(
            Deployment::site()->env()->get('MAIL_MAILER'),
            self::MAILER,
            'Change mailer to Mailgun'
        );
    }

    protected function createDomain()
    {
        $domain = Console::ask('Domain name?', 'mail.' . Project::domain());

        $result = $this->http->client()->post('domains', ['name' => $domain]);

        if (Dns::available() && Arr::get($result, 'sending_dns_records')) {
            $this->updateDnsRecords($result);
        }

        return $result;
    }

    protected function updateDnsRecords($result)
    {
        Console::info('Updating DNS records...');

        collect($result['sending_dns_records'])->each(function ($record) {
            $args = [
                Domain::getSubdomain($record['name']),
                $record['value'],
                1800,
            ];

            match ($record['record_type']) {
                'TXT'   => Dns::addTXTRecord(...$args),
                'CNAME' => Dns::addCNAMERecord(...$args),
            };
        });

        $this->verifyNewDomain = true;
    }
}
