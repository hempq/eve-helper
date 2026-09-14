<?php

namespace App\Providers;

use App\Services\Esi\EsiClient;
use App\Services\Esi\EsiClientInterface;
use App\Services\Market\FuzzworkPriceProvider;
use App\Services\Market\PriceProviderInterface;
use App\Services\Market\TradeFeeService;
use App\Services\Sde\SdeDownloader;
use App\Services\Sso\AccessTokenManager;
use App\Services\Sso\AccessTokenProvider;
use App\Services\Sso\EveSsoService;
use App\Services\Sso\JwtValidator;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JwtValidator::class, fn (Application $app) => new JwtValidator(
            cache: $app->make(Cache::class),
            jwksUrl: config('eve.sso.jwks_url'),
            clientId: (string) config('eve.sso.client_id'),
            issuers: config('eve.sso.issuers'),
        ));

        $this->app->singleton(EveSsoService::class, fn (Application $app) => new EveSsoService(
            jwtValidator: $app->make(JwtValidator::class),
            clientId: (string) config('eve.sso.client_id'),
            clientSecret: (string) config('eve.sso.client_secret'),
            authorizeUrl: config('eve.sso.authorize_url'),
            tokenUrl: config('eve.sso.token_url'),
            scopes: config('eve.sso.scopes'),
        ));

        $this->app->singleton(AccessTokenProvider::class, AccessTokenManager::class);

        $this->app->singleton(PriceProviderInterface::class, fn (Application $app) => new \App\Services\Market\CompositePriceProvider(
            market: new FuzzworkPriceProvider(
                cache: $app->make(Cache::class),
                baseUrl: config('eve.market.fuzzwork_url'),
                userAgent: config('eve.esi.user_agent'),
                cacheSeconds: (int) config('eve.market.price_cache_seconds'),
            ),
            contracts: $app->make(\App\Services\Market\ContractPriceService::class),
        ));

        $this->app->singleton(TradeFeeService::class, fn () => new TradeFeeService(
            accountingSkillId: (int) config('eve.market.accounting_skill_id'),
            brokerRelationsSkillId: (int) config('eve.market.broker_relations_skill_id'),
        ));

        $this->app->singleton(\App\Services\Universe\EveScoutService::class, fn (Application $app) => new \App\Services\Universe\EveScoutService(
            cache: $app->make(Cache::class),
            userAgent: config('eve.esi.user_agent'),
        ));

        $this->app->singleton(\App\Services\Market\ContractPriceService::class, fn () => new \App\Services\Market\ContractPriceService(
            userAgent: config('eve.esi.user_agent'),
            snapshotUrl: config('eve.market.contract_snapshot_url'),
        ));

        $this->app->singleton(\App\Services\Killmails\LossHistoryService::class, fn (Application $app) => new \App\Services\Killmails\LossHistoryService(
            cache: $app->make(Cache::class),
            esi: $app->make(EsiClientInterface::class),
            userAgent: config('eve.esi.user_agent'),
        ));

        $this->app->singleton(SdeDownloader::class, fn () => new SdeDownloader(
            baseUrl: config('eve.sde.base_url'),
            userAgent: config('eve.esi.user_agent'),
        ));

        $this->app->singleton(EsiClientInterface::class, fn (Application $app) => new EsiClient(
            tokens: $app->make(AccessTokenProvider::class),
            cache: $app->make(Cache::class),
            baseUrl: config('eve.esi.base_url'),
            compatibilityDate: config('eve.esi.compatibility_date'),
            userAgent: config('eve.esi.user_agent'),
            errorLimitThreshold: config('eve.esi.error_limit_threshold'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
