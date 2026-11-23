<?php

declare(strict_types=1);
use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Collaborations\Providers\CollaborationsServiceProvider;
use App\Modules\Content\Providers\ContentServiceProvider;
use App\Modules\Creators\Providers\CreatorsServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Media\Providers\MediaServiceProvider;
use App\Modules\Messaging\Providers\MessagingServiceProvider;
use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Subscriptions\Providers\SubscriptionsServiceProvider;
use App\Modules\TrustSafety\Providers\TrustSafetyServiceProvider;
use App\Modules\Wallet\Providers\WalletServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    CreatorsServiceProvider::class,
    ContentServiceProvider::class,
    MediaServiceProvider::class,
    WalletServiceProvider::class,
    SubscriptionsServiceProvider::class,
    MessagingServiceProvider::class,
    TrustSafetyServiceProvider::class,
    NotificationsServiceProvider::class,
    AuditServiceProvider::class,
    CollaborationsServiceProvider::class,
    AdminPanelProvider::class,
];
