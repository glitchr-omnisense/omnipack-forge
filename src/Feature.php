<?php

namespace Base\Forge;

/**
 * What the forge does beyond its core, each part switched on by the package it stands on.
 *
 * The core - software, releases and their builds, downloads, the versions and their
 * documentation, the publish API and forge:artifact:attach, WebGL play - needs none of them.
 * A feature is on when its package is installed (its class exists) and the application did not
 * turn it off (forge.features.<name>: false); off, its services, routes and entities are not
 * registered (ForgeExtension, ForgeRouteLoader, ForgeBundle's mapping pass).
 */
enum Feature: string
{
    /** omnipack/marketplace: licences sold, paid downloads, purchases, support hours, quotes, invoices, client projects. */
    case MARKETPLACE = 'marketplace';

    /** git/git-bundle: releases from tags, their archives, the Composer index, repositories read. */
    case GIT = 'git';

    /** omnisense/omnihub: the Unity registry and the hub clients' manifest and catalogue. */
    case HUB = 'hub';

    /**
     * The files of src/ that need more than the core, by their path under src/ (a folder ends
     * with "/"), with the features each needs - all of them. Everything else is the core.
     */
    public const PATHS = [
        'Command/HoursLowBalanceCommand.php' => [self::MARKETPLACE],
        'Command/LicenseExpireCommand.php' => [self::MARKETPLACE],
        'Command/LicenseKeypairCommand.php' => [self::MARKETPLACE],
        'Command/LicenseRotateCommand.php' => [self::MARKETPLACE],
        'Command/PipelineSyncCommand.php' => [self::MARKETPLACE],
        'Command/ReleaseSyncCommand.php' => [self::GIT],

        'Controller/Admin/ActionController.php' => [self::MARKETPLACE],
        'Controller/Admin/PipelineController.php' => [self::MARKETPLACE],
        'Controller/Admin/RefundController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/CardCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/HourCreditCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/HourInvoiceCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/HourPackCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/HourRefundCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/LicenseActivationCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/LicenseCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/LicenseOfferCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/LicenseSeatCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/PipelineCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/ProjectCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/ProjectReviewCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/QuoteCrudController.php' => [self::MARKETPLACE],
        'Controller/Admin/Crud/TimeEntryCrudController.php' => [self::MARKETPLACE],

        'Controller/Client/AccountController.php' => [self::MARKETPLACE],
        'Controller/Client/BoardController.php' => [self::MARKETPLACE],
        'Controller/Client/ComposerController.php' => [self::GIT],
        'Controller/Client/HubAccountController.php' => [self::HUB],
        'Controller/Client/HubController.php' => [self::HUB, self::GIT],
        'Controller/Client/HubLinkController.php' => [self::HUB, self::MARKETPLACE],
        'Controller/Client/HubPurchaseController.php' => [self::HUB, self::MARKETPLACE],
        'Controller/Client/HubReturnController.php' => [self::HUB, self::MARKETPLACE],
        'Controller/Client/InvoiceController.php' => [self::MARKETPLACE],
        'Controller/Client/LicenseApiController.php' => [self::MARKETPLACE],
        'Controller/Client/ReviewController.php' => [self::MARKETPLACE],
        'Controller/Client/ShopController.php' => [self::MARKETPLACE],
        'Controller/Client/UpmController.php' => [self::HUB, self::GIT],

        'Entity/Card.php' => [self::MARKETPLACE],
        'Entity/HourCredit.php' => [self::MARKETPLACE],
        'Entity/HourInvoice.php' => [self::MARKETPLACE],
        'Entity/HourRefund.php' => [self::MARKETPLACE],
        'Entity/HubPurchase.php' => [self::HUB, self::MARKETPLACE],
        'Entity/HubToken.php' => [self::HUB],
        'Entity/Job.php' => [self::MARKETPLACE],
        'Entity/PaymentCustomer.php' => [self::HUB, self::MARKETPLACE],
        'Entity/PaymentSetup.php' => [self::HUB, self::MARKETPLACE],
        'Entity/Pipeline.php' => [self::MARKETPLACE],
        'Entity/Product/' => [self::MARKETPLACE],
        'Entity/Project.php' => [self::MARKETPLACE],
        'Entity/ProjectReview.php' => [self::MARKETPLACE],
        'Entity/Quote.php' => [self::MARKETPLACE],
        'Entity/QuoteLine.php' => [self::MARKETPLACE],
        'Entity/Stage.php' => [self::MARKETPLACE],
        'Entity/TimeEntry.php' => [self::MARKETPLACE],

        'EventListener/HourInvoiceCancelListener.php' => [self::MARKETPLACE],
        'EventListener/HubPurchaseCharges.php' => [self::HUB, self::MARKETPLACE],
        'EventListener/LicenseOffersListener.php' => [self::MARKETPLACE],
        'EventListener/LicenseSeatListener.php' => [self::MARKETPLACE],
        'EventSubscriber/HubAccountSubscriber.php' => [self::HUB],
        'EventSubscriber/LicenseApiErrorSubscriber.php' => [self::MARKETPLACE],
        'EventSubscriber/OrderPaidSubscriber.php' => [self::MARKETPLACE],
        'EventSubscriber/TimeEntryLedgerSubscriber.php' => [self::MARKETPLACE],

        'Form/QuoteLineType.php' => [self::MARKETPLACE],
        'Form/ReviewAnswerType.php' => [self::MARKETPLACE],
        'Git/' => [self::GIT],
        'Invoice/' => [self::MARKETPLACE],
        'Pipeline/' => [self::MARKETPLACE],

        'Repository/HourCreditRepository.php' => [self::MARKETPLACE],
        'Repository/HourInvoiceRepository.php' => [self::MARKETPLACE],
        'Repository/PipelineRepository.php' => [self::MARKETPLACE],
        'Repository/ProjectRepository.php' => [self::MARKETPLACE],
        'Repository/ProjectReviewRepository.php' => [self::MARKETPLACE],
        'Repository/QuoteRepository.php' => [self::MARKETPLACE],
        'Repository/TimeEntryRepository.php' => [self::MARKETPLACE],

        'Security/Voter/GitRepositoryVoter.php' => [self::GIT],
        'Security/Voter/HourInvoiceVoter.php' => [self::MARKETPLACE],
        'Security/Voter/ProjectVoter.php' => [self::MARKETPLACE],

        'Service/Board.php' => [self::MARKETPLACE],
        'Service/ComposerIndex.php' => [self::GIT],
        'Service/DeviceLinks.php' => [self::HUB],
        'Service/HourInvoices.php' => [self::MARKETPLACE],
        'Service/HourLedger.php' => [self::MARKETPLACE],
        'Service/HourRefunds.php' => [self::MARKETPLACE],
        'Service/HubAccounts.php' => [self::HUB],
        'Service/HubCatalog.php' => [self::HUB],
        'Service/HubPayments.php' => [self::HUB, self::MARKETPLACE],
        'Service/HubPurchases.php' => [self::HUB, self::MARKETPLACE],
        'Service/LicenseActivations.php' => [self::MARKETPLACE],
        'Service/LicenseHolders.php' => [self::MARKETPLACE],
        'Service/LicenseIssuer.php' => [self::MARKETPLACE],
        'Service/LicenseKey.php' => [self::MARKETPLACE],
        'Service/LicenseKeyVault.php' => [self::MARKETPLACE],
        'Service/LicenseSeats.php' => [self::MARKETPLACE],
        'Service/LicenseToken.php' => [self::MARKETPLACE],
        'Service/PipelineGraph.php' => [self::MARKETPLACE],
        'Service/PipelineSync.php' => [self::MARKETPLACE],
        'Service/ProjectReviews.php' => [self::MARKETPLACE],
        'Service/QuoteStatusGuard.php' => [self::MARKETPLACE],
        'Service/QuoteToOrder.php' => [self::MARKETPLACE],
        'Service/ReleaseSync.php' => [self::GIT],
        'Service/UpmRegistry.php' => [self::HUB, self::GIT],
        'Twig/PipelineExtension.php' => [self::MARKETPLACE],
    ];

    /** The class whose presence says the feature's package is installed. */
    public function requiredClass(): string
    {
        return match ($this) {
            self::MARKETPLACE => 'Base\\Marketplace\\MarketplaceBundle',
            self::GIT => 'Git\\GitBundle',
            self::HUB => 'Omnihub\\Upm\\Registry',
        };
    }

    /** The package that brings it, for the messages and the docs. */
    public function package(): string
    {
        return match ($this) {
            self::MARKETPLACE => 'omnipack/marketplace',
            self::GIT => 'git/git-bundle',
            self::HUB => 'omnisense/omnihub',
        };
    }

    public function isInstalled(): bool
    {
        return class_exists($this->requiredClass());
    }

    /**
     * The features on: installed, and not turned off by the configuration.
     *
     * @param array<string, bool|null> $configured forge.features: false turns one off, null (or absent) leaves it to its package
     *
     * @return list<self>
     */
    public static function enabled(array $configured = []): array
    {
        $enabled = [];
        foreach (self::cases() as $feature) {
            $wanted = $configured[$feature->value] ?? null;
            if (true === $wanted && !$feature->isInstalled()) {
                throw new \LogicException(sprintf('forge.features.%s is on but %s is not installed: composer require %s.', $feature->value, $feature->package(), $feature->package()));
            }
            if (false !== $wanted && $feature->isInstalled()) {
                $enabled[] = $feature;
            }
        }

        return $enabled;
    }

    /**
     * The features a file of src/ needs.
     *
     * @return list<self>
     */
    public static function requiredBy(string $path): array
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        foreach (self::PATHS as $prefix => $features) {
            if ($path === $prefix || (str_ends_with($prefix, '/') && str_starts_with($path, $prefix))) {
                return $features;
            }
        }

        return [];
    }

    /**
     * The files of src/ (paths under it) that need a feature not in $enabled.
     *
     * @param list<self> $enabled
     *
     * @return list<string>
     */
    public static function offPaths(array $enabled): array
    {
        $off = [];
        foreach (self::PATHS as $path => $features) {
            if (array_diff(array_map(fn (self $f) => $f->value, $features), array_map(fn (self $f) => $f->value, $enabled))) {
                $off[] = $path;
            }
        }

        return $off;
    }
}
