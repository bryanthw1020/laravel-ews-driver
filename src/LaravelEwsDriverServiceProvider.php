<?php

namespace Bryanthw1020\LaravelEwsDriver;

use Bryanthw1020\LaravelEwsDriver\Transport\ExchangeTransport;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\MailServiceProvider;

class LaravelEwsDriverServiceProvider extends MailServiceProvider
{
    /**
     * Register the Swift Transport instance.
     *
     * @return void
     */
    public function register()
    {
        parent::register();

        $this->app->afterResolving(MailManager::class, function (MailManager $mail_manager) {
            $mail_manager->extend("exchange", function ($config) {
                $config = $this->app['config']->get('mail.mailers.exchange', []);
                $host = $config['host'];
                $username = $config['username'];
                $password = $config['password'];

                return new ExchangeTransport($host, $username, $password);
            });
        });
    }
}
